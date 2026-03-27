<?php
/**
 * Object Cache Dropin for WordPress.
 */

global $_wp_using_ext_object_cache;
$_wp_using_ext_object_cache = true;

/**
 * Afterburner_Object_Cache is used as the $wp_object_cache global.
 */
class Afterburner_Object_Cache {

	/**
	 * The Afterburner\LocalRedisCache class that's provided by the Afterburn extension.
	 *
	 * @var Afterburner\LocalRedisCache
	 */
	private $afterburner_redis_cache;

	/**
	 * The internal / request cache.
	 *
	 * All items that are fetched from Afterburner are also cached in a local PHP array. This is because
	 * Afterburner only stores strings, and it would be prohibitively expensive to unserialize the data on
	 * every "get" call.
	 *
	 * This is public as some cache extensions and plugins will attempt to access `$GLOBALS['wp_object_cache']->cache`.
	 *
	 * @var array<string,mixed>
	 */
	public $cache = [];

	/**
	 * Cache of keys that have been looked up but not found in Redis.
	 *
	 * This prevents the issue where a not-found key cached as `false` in $this->cache
	 * would be indistinguishable from a key that legitimately has the value `false`.
	 * Uses keys as array keys for O(1) lookup performance.
	 *
	 * @var array<string,true>
	 */
	private $not_found_cache = [];

	/**
	 * The non-persistent groups.
	 *
	 * Non-persistent groups only store data in the local $this->cache array, and are not sent to Afterburner / Redis at all.
	 *
	 * @var array<string>
	 */
	private $non_persistent_groups = [];

	/**
	 * Stores the registered global groups.
	 *
	 * If an item is in a global group, the cache-key is not different on each site ($this->blog_prefix) is not included.
	 *
	 * @var array<string>
	 */
	private $global_groups = [];

	/**
	 * The blog prefix of the current site.
	 *
	 * @var string
	 */
	private $blog_prefix = '0:';

	/**
	 * Number of cache hits.
	 *
	 * @var int
	 */
	public $cache_hits = 0;

	/**
	 * Number of cache misses.
	 *
	 * @var int
	 */
	public $cache_misses = 0;

	/**
	 * Create the class with a redis server configuration.
	 *
	 * @psalm-aram array{ connection_string?: string, host?: string, port?: int, auth?: string, insecure?: bool, afterburner.lru_cache_max_items?: int, afterburner.redis_skip_server_check?: bool } $redis_server
	 * @param array $redis_server The redis server.
	 * @throws Exception If the configuration is not valid.
	 */
	public function __construct( array $redis_server ) {
		global $blog_id;
		if ( $blog_id ) {
			$this->blog_prefix = $blog_id . ':';
		}

		if ( ! empty( $redis_server['connection_string'] ) ) {
			$connection_string = $redis_server['connection_string'];
		} elseif ( ! empty( $redis_server['host'] ) ) {
			$connection_string = sprintf(
				'redis%s://%s%s:%d%s',
				isset( $redis_server['ssl'] ) ? 's' : '',
				! empty( $redis_server['auth'] ) ? ':' . $redis_server['auth'] . '@' : '',
				$redis_server['host'],
				$redis_server['port'],
				! empty( $redis_server['insecure'] ) ? '#insecure' : ''
			);
		} else {
			throw new Exception( 'No redis configuration found.' );
		}

		$max_items_lru_cache = $redis_server['afterburner.lru_cache_max_items'] ?? 3000;
		$redis_skip_server_check = $redis_server['afterburner.redis_skip_server_check'] ?? false;
		$this->afterburner_redis_cache = Afterburner\LocalRedisCache::init( $connection_string, $max_items_lru_cache, $redis_skip_server_check );
	}

	/**
	 * Add a group or groups to the non-persistent groups.
	 *
	 * @param string|array $groups The group or groups to add.
	 */
	public function add_non_persistent_groups( string|array $groups ): void {
		$groups = (array) $groups;
		$this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, $groups ) );
	}

	/**
	 * Add a group or groups to the global groups.
	 *
	 * @param string|array $groups The group or groups to add.
	 */
	public function add_global_groups( $groups ): void {
		$groups = (array) $groups;
		$this->global_groups = array_unique( array_merge( $this->non_persistent_groups, $groups ) );
	}

	/**
	 * Generate a cache key.
	 *
	 * @param string $key The cache key.
	 * @param string $group The cache group.
	 * @return string The cache key.
	 */
	private function key( string $key, string $group = 'default' ): string {
		// If it's a global group, don't include the blog ID.
		if ( ! empty( $group ) && isset( $this->global_groups[ $group ] ) ) {
			return empty( $group ) ? $key : "{$group}:{$key}";
		}

		// For non-global groups, include the blog ID.
		return sprintf(
			'ab:%s%s:%s',
			in_array( $group, $this->global_groups, true ) ? '' : $this->blog_prefix,
			$group,
			$key
		);
	}

	/**
	 * Serialize data.
	 *
	 * @param mixed $data The data to serialize.
	 * @return string The serialized data.
	 */
	protected function serialize( mixed $data ): string {
		// If this is an integer, store it as such. Otherwise, serialize it. This is because inc / decr need to be stored in redis as raw values.
		// @phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
		if ( is_numeric( $data ) && intval( $data ) == $data ) {
			return $data;
		}

		return serialize( $data );
	}

	/**
	 * Unserialize data.
	 *
	 * @param string $data The data to unserialize.
	 * @return mixed The unserialized data.
	 */
	protected function unserialize( string $data ): mixed {
		// Integers are stored directly as strings, so incr / decr work as expected.
		// @phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
		if ( is_numeric( $data ) && intval( $data ) == $data ) {
			// Technically this is lossy, as `wp_cache_set()` with `string("1")` will get converted to `int(1)` however this is also the case for other
			// object cache dropins.
			return intval( $data );
		}
		return unserialize( $data );
	}

	/**
	 * Add data to the cache.
	 *
	 * @param string $key The cache key.
	 * @param mixed $data The data to store.
	 * @param string $group The cache group.
	 * @param int $expire The expiration time in seconds.
	 * @return bool True if the data was added, false otherwise.
	 */
	public function add( string $key, mixed $data, string $group = 'default', int $expire = 0 ): bool {
		$cache_key = $this->key( $key, $group );

		// Only fail if the key actually exists in cache (not in not_found_cache).
		if ( isset( $this->cache[ $cache_key ] ) ) {
			return false;
		}

		$this->cache[ $cache_key ] = $data;
		unset( $this->not_found_cache[ $cache_key ] );

		// Skip Redis for non-persistent groups.
		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			return true;
		}

		try {
			$result = $this->afterburner_redis_cache->add( $cache_key, $this->serialize( $data ), $expire ?: null );
			if ( ! $result ) {
				unset( $this->cache[ $cache_key ] );
				return false;
			}
		} catch ( Exception $e ) {
			unset( $this->cache[ $cache_key ] );
			return false;
		}
		return true;
	}

	/**
	 * Get data from the cache.
	 *
	 * @param string $key The cache key.
	 * @param string $group The cache group.
	 * @param bool $force Whether to force a lookup on Afterburner rather than using the local cache.
	 * @param bool $found Whether the key was found, passed by reference.
	 * @return mixed The data, or false if not found.
	 */
	public function get( string $key, string $group = 'default', $force = false, &$found = null ): mixed {
		$cache_key = $this->key( $key, $group );

		// Check not_found_cache first (unless forcing a fresh lookup).
		if ( ! $force && isset( $this->not_found_cache[ $cache_key ] ) ) {
			$this->cache_hits++;
			$found = false;
			return false;
		}

		if ( ( ! $force || in_array( $group, $this->non_persistent_groups, true ) ) && isset( $this->cache[ $cache_key ] ) ) {
			$this->cache_hits++;
			$found = true;
			return $this->cache[ $cache_key ];
		}

		// Skip Redis for non-persistent groups.
		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			$this->cache_misses++;
			$found = false;
			return false;
		}

		try {
			$value = $this->afterburner_redis_cache->get( $cache_key );
			$unserialized = $this->unserialize( $value );
			$this->cache[ $cache_key ] = $unserialized;
			unset( $this->not_found_cache[ $cache_key ] );
			$this->cache_misses++;
			$found = true;
			return $unserialized;
		} catch ( Exception $e ) {
			$this->not_found_cache[ $cache_key ] = true;
			$this->cache_misses++;
			$found = false;
			return false;
		}
	}

	/**
	 * Set data in the cache.
	 *
	 * @param string $key The cache key.
	 * @param mixed $data The data to store.
	 * @param string $group The cache group.
	 * @param int $expire The expiration time in seconds.
	 * @return bool True if the data was set, false otherwise.
	 */
	public function set( string $key, mixed $data, string $group = 'default', int $expire = 0 ): bool {
		$cache_key = $this->key( $key, $group );

		$this->cache[ $cache_key ] = $data;
		unset( $this->not_found_cache[ $cache_key ] );

		// Skip Redis for non-persistent groups.
		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			return true;
		}

		try {
			$this->afterburner_redis_cache->set( $cache_key, $this->serialize( $data ), $expire ?: null );
			return true;
		} catch ( Exception $e ) {
			unset( $this->cache[ $cache_key ] );
			return false;
		}
	}

	/**
	 * Delete data from the cache.
	 *
	 * @param string $key The cache key.
	 * @param string $group The cache group.
	 * @return bool True if the data was deleted, false otherwise.
	 */
	public function delete( string $key, string $group = 'default' ): bool {
		$cache_key = $this->key( $key, $group );

		unset( $this->cache[ $cache_key ] );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			return true;
		}

		try {
			$this->afterburner_redis_cache->delete( $cache_key );
			$this->not_found_cache[ $cache_key ] = true;
		} catch ( Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Flush the cache.
	 *
	 * @return bool True if the cache was flushed, false otherwise.
	 */
	public function flush(): bool {
		$this->cache = [];
		$this->not_found_cache = [];

		try {
			$this->afterburner_redis_cache->flush();
		} catch ( Exception $e ) {
			return false;
		}

		return true;
	}

	/**
	 * Flush the runtime cache.
	 *
	 * @return bool True if the runtime cache was flushed, false otherwise.
	 */
	public function flush_runtime(): bool {
		$this->cache = [];
		$this->not_found_cache = [];
		try {
			$this->afterburner_redis_cache->flush_runtime();
		} catch ( Exception $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Increment a value in the cache.
	 *
	 * @param string $key The cache key.
	 * @param int $offset The amount to increment by.
	 * @param string $group The cache group.
	 * @return int|false The new value, or false if the operation failed.
	 */
	public function incr( string $key, int $offset = 1, string $group = 'default' ): int|false {
		$cache_key = $this->key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			return false;
		}

		try {
			$result = $this->afterburner_redis_cache->incr( $cache_key, $offset );
			if ( $result !== false ) {
				$this->cache[ $cache_key ] = $result;
			}
			return $result;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Decrement a value in the cache.
	 *
	 * @param string $key The cache key.
	 * @param int $offset The amount to decrement by.
	 * @param string $group The cache group.
	 * @return int|false The new value, or false if the operation failed.
	 */
	public function decr( string $key, int $offset = 1, string $group = 'default' ): int|false {
		$cache_key = $this->key( $key, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			return false;
		}

		try {
			$result = $this->afterburner_redis_cache->decr( $cache_key, $offset );
			if ( $result !== false ) {
				$this->cache[ $cache_key ] = $result;
			}
			return $result;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Add multiple items to the cache.
	 *
	 * @param array $data The data to store.
	 * @param string $group The cache group.
	 * @param int $expire The expiration time in seconds.
	 * @return array|false An array of results, or false if the operation failed.
	 */
	public function add_multiple( array $data, string $group = 'default', int $expire = 0 ): array|false {
		$all_results = [];
		$key_map = [];
		foreach ( $data as $key => $value ) {
			$key_map[ $this->key( $key, $group ) ] = $key;
		}

		$serialized_data = [];
		foreach ( $key_map as $cache_key => $key ) {
			$value = $data[ $key ];
			// Only fail if the key actually exists in cache (not in not_found_cache).
			if ( isset( $this->cache[ $cache_key ] ) ) {
				$all_results[ $key ] = false;
				continue;
			}
			$all_results[ $key ] = true;
			$this->cache[ $cache_key ] = $value;
			unset( $this->not_found_cache[ $cache_key ] );
			$serialized_data[ $cache_key ] = $this->serialize( $value );
		}

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			return $all_results;
		}

		try {
			if ( empty( $serialized_data ) ) {
				return $all_results;
			}

			$results = $this->afterburner_redis_cache->add_multiple( $serialized_data, $expire ?: null );
			foreach ( $results as $cache_key => $redis_result ) {
				$key = $key_map[ $cache_key ];
				if ( $redis_result->error ) {
					// Redis add failed, key exists in Redis - remove from local cache.
					unset( $this->cache[ $cache_key ] );
					$all_results[ $key ] = false;
				}
			}

			return $all_results;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Get multiple items from the cache.
	 *
	 * @param array $keys The cache keys.
	 * @param string $group The cache group.
	 * @param bool $force Whether to force a lookup on Afterburner rather than using the local cache.
	 * @return array|false An array of results, or false if the operation failed.
	 */
	public function get_multiple( array $keys, string $group = 'default', $force = false ): array|false {
		$key_map = [];
		foreach ( $keys as $key ) {
			$key_map[ $this->key( $key, $group ) ] = $key;
		}

		$need_to_get = [];
		$results = [];

		// Only fetch from local cache if force is set to false.
		if ( ! $force ) {
			foreach ( $key_map as $cache_key => $key ) {
				if ( isset( $this->cache[ $cache_key ] ) ) {
					$this->cache_hits++;
					$results[ $key ] = $this->cache[ $cache_key ];
				} elseif ( isset( $this->not_found_cache[ $cache_key ] ) ) {
					// Key was previously looked up and not found.
					$this->cache_hits++;
					$results[ $key ] = false;
				} else {
					$need_to_get[] = $cache_key;
				}
			}
		} else {
			$need_to_get = array_keys( $key_map );
		}

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			// Append all the non-set keys as false.
			foreach ( $need_to_get as $cache_key ) {
				$key = $key_map[ $cache_key ];
				$this->cache_misses++;
				$results[ $key ] = false;
			}
			return $results;
		}

		if ( ! empty( $need_to_get ) ) {
			try {
				$redis_results = $this->afterburner_redis_cache->get_multiple( $need_to_get );
				foreach ( $redis_results as $cache_key => $redis_value ) {
					$key = $key_map[ $cache_key ];
					$this->cache_misses++;
					if ( $redis_value->error ) {
						$this->not_found_cache[ $cache_key ] = true;
						$results[ $key ] = false;
					} else {
						$value = $this->unserialize( $redis_value->value );
						$this->cache[ $cache_key ] = $value;
						unset( $this->not_found_cache[ $cache_key ] );
						$results[ $key ] = $value;
					}
				}
			} catch ( Exception $e ) {
				return false;
			}
		}

		return $results;
	}

	/**
	 * Set multiple items in the cache.
	 *
	 * @param array $data The data to store.
	 * @param string $group The cache group.
	 * @param int $expire The expiration time in seconds.
	 * @return array|false An array of results, or false if the operation failed.
	 */
	public function set_multiple( array $data, string $group = 'default', int $expire = 0 ): array|false {
		$all_results = [];
		$key_map = [];
		foreach ( $data as $key => $value ) {
			$key_map[ $this->key( $key, $group ) ] = $key;
		}

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			foreach ( $data as $key => $value ) {
				$cache_key = $this->key( $key, $group );
				$this->cache[ $cache_key ] = $value;
				unset( $this->not_found_cache[ $cache_key ] );
			}
			return array_fill_keys( array_keys( $data ), true );
		}

		try {
			$serialized_data = [];
			foreach ( $data as $key => $value ) {
				$cache_key = $this->key( $key, $group );
				$serialized_data[ $cache_key ] = $this->serialize( $value );
			}

			$results = $this->afterburner_redis_cache->set_multiple( $serialized_data, $expire ?: null );

			if ( $results ) {
				foreach ( $results as $cache_key => $redis_result ) {
					$key = $key_map[ $cache_key ];
					if ( $redis_result->error ) {
						$all_results[ $key ] = false;
					} else {
						$this->cache[ $cache_key ] = $data[ $key ];
						unset( $this->not_found_cache[ $cache_key ] );
						$all_results[ $key ] = true;
					}
				}
			}
		} catch ( Exception $e ) {
			return false;
		}

		return $all_results;
	}

	/**
	 * Delete multiple items from the cache.
	 *
	 * @param array $keys The cache keys.
	 * @param string $group The cache group.
	 * @return array|false An array of results, or false if the operation failed.
	 */
	public function delete_multiple( array $keys, string $group = 'default' ): array|false {
		$all_results = [];
		$key_map = [];
		foreach ( $keys as $key ) {
			$cache_key = $this->key( $key, $group );
			$key_map[ $cache_key ] = $key;

			if ( isset( $this->cache[ $cache_key ] ) ) {
				$all_results[ $key ] = true;
				unset( $this->cache[ $cache_key ] );
			} else {
				$all_results[ $key ] = false;
			}
		}

		if ( in_array( $group, $this->non_persistent_groups, true ) ) {
			return $all_results;
		}

		try {
			$results = $this->afterburner_redis_cache->delete_multiple( array_keys( $key_map ) );
			foreach ( $results as $cache_key => $redis_result ) {
				$key = $key_map[ $cache_key ];
				if ( ! $redis_result->error ) {
					$this->not_found_cache[ $cache_key ] = true;
				}
				$all_results[ $key ] = ! $redis_result->error;
			}

			return $all_results;
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Switch blog prefix, which changes the cache that is accessed.
	 *
	 * @param int $blog_id Blog to switch to.
	 * @return void
	 */
	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = $blog_id . ':';
	}
}

/**
 * Initialize the object cache.
 *
 * @return bool True if the cache was initialized, false otherwise.
 */
function wp_cache_init(): bool {
	global $wp_object_cache;
	$redis_server = $GLOBALS['redis_server'] ?? [];

	// backwards compat for ini values.
	if ( ini_get( 'afterburner.redis_server_info' ) && empty( $redis_server['host'] ) ) {
		$redis_server['connection_string'] = ini_get( 'afterburner.redis_server_info' );
	}

	if ( ini_get( 'afterburner.redis_skip_server_check' ) && ! isset( $redis_server['afterburner.redis_skip_server_check'] ) ) {
		// Ini value can be "yes" / "no" which we need to interpret properly as boolean.
		$redis_server['afterburner.redis_skip_server_check'] = filter_var( ini_get( 'afterburner.redis_skip_server_check' ), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
	}

	if ( ini_get( 'afterburner.lru_cache_max_items' ) && ! isset( $redis_server['afterburner.lru_cache_max_items'] ) ) {
		$lru_cache_max_items = filter_var( ini_get( 'afterburner.lru_cache_max_items' ), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE );
		if ( null !== $lru_cache_max_items ) {
			$redis_server['afterburner.lru_cache_max_items'] = $lru_cache_max_items;
		}
	}

	try {

		$wp_object_cache = new Afterburner_Object_Cache( $redis_server );
	} catch ( Exception $e ) {
		// A failure to connect to Redis results in a fatal error.
		trigger_error( $e->getMessage(), E_USER_ERROR );
		return false;
	}

	return true;
}

/**
 * Add a group or groups to the non-persistent groups.
 *
 * @param string|array $groups The group or groups to add.
 */
function wp_cache_add_global_groups( string|array $groups ): void {
	global $wp_object_cache;
	$wp_object_cache->add_global_groups( $groups );
}

/**
 * Add a group or groups to the non-persistent groups.
 *
 * @param string|array $groups The group or groups to add.
 */
function wp_cache_add_non_persistent_groups( string|array $groups ): void {
	global $wp_object_cache;
	$wp_object_cache->add_non_persistent_groups( $groups );
}

/**
 * Add data to the cache.
 *
 * @param string $key The cache key.
 * @param mixed $data The data to store.
 * @param string $group The cache group.
 * @param int $expire The expiration time in seconds.
 * @return bool True if the data was added, false otherwise.
 */
function wp_cache_add( string $key, mixed $data, string $group = 'default', int $expire = 0 ): bool {
	global $wp_object_cache;
	return $wp_object_cache->add( $key, $data, $group, $expire );
}

/**
 * Get data from the cache.
 *
 * @param string $key The cache key.
 * @param string $group The cache group.
 * @param bool $force Whether to force a lookup on Afterburner rather than using the local cache.
 * @param bool $found Whether the key was found, passed by reference.
 * @return mixed The data, or false if not found.
 */
function wp_cache_get( string $key, string $group = 'default', $force = false, &$found = null ): mixed {
	global $wp_object_cache;
	return $wp_object_cache->get( $key, $group, $force, $found );
}

/**
 * Set data in the cache.
 *
 * @param string $key The cache key.
 * @param mixed $data The data to store.
 * @param string $group The cache group.
 * @param int $expire The expiration time in seconds.
 * @return bool True if the data was set, false otherwise.
 */
function wp_cache_set( string $key, mixed $data, string $group = 'default', int $expire = 0 ): bool {
	global $wp_object_cache;
	return $wp_object_cache->set( $key, $data, $group, $expire );
}

/**
 * Delete data from the cache.
 *
 * @param string $key The cache key.
 * @param string $group The cache group.
 * @return bool True if the data was deleted, false otherwise.
 */
function wp_cache_delete( string $key, string $group = 'default' ): bool {
	global $wp_object_cache;
	return $wp_object_cache->delete( $key, $group );
}

/**
 * Flush the cache.
 *
 * @return bool True if the cache was flushed, false otherwise.
 */
function wp_cache_flush(): bool {
	global $wp_object_cache;
	return $wp_object_cache->flush();
}

/**
 * Flush the runtime cache.
 *
 * @return bool True if the runtime cache was flushed, false otherwise.
 */
function wp_cache_flush_runtime(): bool {
	global $wp_object_cache;
	return $wp_object_cache->flush_runtime();
}

/**
 * Increment a value in the cache.
 *
 * @param string $key The cache key.
 * @param int $offset The amount to increment by.
 * @param string $group The cache group.
 * @return int|false The new value, or false if the operation failed.
 */
function wp_cache_incr( string $key, int $offset = 1, string $group = 'default' ): int|false {
	global $wp_object_cache;
	return $wp_object_cache->incr( $key, $offset, $group );
}

/**
 * Decrement a value in the cache.
 *
 * @param string $key The cache key.
 * @param int $offset The amount to decrement by.
 * @param string $group The cache group.
 * @return int|false The new value, or false if the operation failed.
 */
function wp_cache_decr( string $key, int $offset = 1, string $group = 'default' ): int|false {
	global $wp_object_cache;
	return $wp_object_cache->decr( $key, $offset, $group );
}

/**
 * Add multiple items to the cache.
 *
 * @param array $data The data to store.
 * @param string $group The cache group.
 * @param int $expire The expiration time in seconds.
 * @return array|false An array of results, or false if the operation failed.
 */
function wp_cache_add_multiple( array $data, string $group = 'default', int $expire = 0 ): array|false {
	global $wp_object_cache;
	return $wp_object_cache->add_multiple( $data, $group, $expire );
}

/**
 * Get multiple items from the cache.
 *
 * @param array $keys The cache keys.
 * @param string $group The cache group.
 * @param bool $force Whether to force a lookup on Afterburner rather than using the local cache.
 * @return array|false An array of results, or false if the operation failed.
 */
function wp_cache_get_multiple( array $keys, string $group = 'default', $force = false ): array|false {
	global $wp_object_cache;
	return $wp_object_cache->get_multiple( $keys, $group, $force );
}

/**
 * Set multiple items in the cache.
 *
 * @param array $data The data to store.
 * @param string $group The cache group.
 * @param int $expire The expiration time in seconds.
 * @return array|false An array of results, or false if the operation failed.
 */
function wp_cache_set_multiple( array $data, string $group = 'default', int $expire = 0 ): array|false {
	global $wp_object_cache;
	return $wp_object_cache->set_multiple( $data, $group, $expire );
}

/**
 * Delete multiple items from the cache.
 *
 * @param array $keys The cache keys.
 * @param string $group The cache group.
 * @return array|false An array of results, or false if the operation failed.
 */
function wp_cache_delete_multiple( array $keys, string $group = 'default' ): array|false {
	global $wp_object_cache;
	return $wp_object_cache->delete_multiple( $keys, $group );
}

/**
 * Switch blog prefix, which changes the cache that is accessed.
 *
 * @param int $blog_id Blog to switch to.
 * @return void
 */
function wp_cache_switch_to_blog( $blog_id ): void {
	global $wp_object_cache;
	$wp_object_cache->switch_to_blog( $blog_id );
}

/**
 * Check if the object cache supports a feature.
 *
 * @param string $feature The feature to check.
 * @return bool True if the feature is supported, false otherwise.
 */
function wp_cache_supports( string $feature ): bool {
	switch ( $feature ) {
		case 'add_multiple':
		case 'set_multiple':
		case 'get_multiple':
		case 'delete_multiple':
		case 'flush_runtime':
			return true;

		default:
			return false;
	}
}

/**
 * Closes the cache.
 *
 * This function has ceased to do anything since WordPress 2.5. The
 * functionality was removed along with the rest of the persistent cache. This
 * does not mean that plugins can't implement this function when they need to
 * make sure that the cache is cleaned up after WordPress no longer needs it.
 *
 * @return bool Always returns True
 */
function wp_cache_close() {
	return true;
}

