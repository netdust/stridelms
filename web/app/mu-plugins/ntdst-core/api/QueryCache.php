<?php

/**
 * NTDST Query Cache - Centralized caching for data queries
 *
 * Handles all caching concerns:
 * - Deterministic cache key generation
 * - Version-based invalidation per post type
 * - Automatic invalidation on post/meta changes
 * - Dev mode cache bypass
 */

defined('ABSPATH') || exit;

class NTDST_Query_Cache
{
    private static ?self $instance = null;
    private bool $hooks_registered = false;
    private array $meta_prefixes = ['_ntdst_'];

    /**
     * Get singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor - use instance()
     */
    private function __construct()
    {
        $this->registerInvalidationHooks();
    }

    /**
     * Register WordPress hooks for automatic cache invalidation
     */
    private function registerInvalidationHooks(): void
    {
        if ($this->hooks_registered) {
            return;
        }

        add_action('save_post', [$this, 'onPostSave'], 10, 2);
        add_action('delete_post', [$this, 'onPostDelete'], 10, 1);
        add_action('trashed_post', [$this, 'onPostTrash'], 10, 1);
        add_action('updated_postmeta', [$this, 'onMetaUpdate'], 10, 4);
        add_action('added_post_meta', [$this, 'onMetaAdd'], 10, 4);
        add_action('delete_post_meta', [$this, 'onMetaDelete'], 10, 4);

        $this->hooks_registered = true;
    }

    /**
     * Generate deterministic cache key from query args
     *
     * Uses ksort for consistent ordering and includes version for invalidation.
     */
    public function generateKey(array $query_args, string $post_type): string
    {
        // Remove volatile args that shouldn't affect caching
        unset(
            $query_args['cache_results'],
            $query_args['update_post_meta_cache'],
            $query_args['update_post_term_cache'],
            $query_args['suppress_filters'],
        );

        // Sort keys for deterministic ordering
        ksort($query_args);

        // Deep sort nested arrays (meta_query, tax_query)
        $query_args = $this->deepKsort($query_args);

        // Get current version for this post type
        $version = $this->getGroupVersion($post_type);

        $hash = md5(json_encode($query_args, JSON_THROW_ON_ERROR));

        return "ntdst_query_{$post_type}_v{$version}_{$hash}";
    }

    /**
     * Recursively sort array keys
     */
    private function deepKsort(array $array): array
    {
        ksort($array);
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $array[$key] = $this->deepKsort($value);
            }
        }
        return $array;
    }

    /**
     * Get cache group for a post type
     */
    public function getGroup(string $post_type): string
    {
        return "ntdst_data_{$post_type}";
    }

    /**
     * Get current version for a cache group
     */
    public function getGroupVersion(string $post_type): int
    {
        $version_key = $this->getGroup($post_type) . '_version';
        $version = wp_cache_get($version_key, 'ntdst_versions');
        return $version !== false ? (int) $version : 0;
    }

    /**
     * Increment version to invalidate all cached queries for a post type
     *
     * Uses wp_cache_incr for atomic increment when supported.
     */
    public function incrementGroupVersion(string $post_type): void
    {
        $version_key = $this->getGroup($post_type) . '_version';

        // Try atomic increment first (supported by Redis, Memcached, etc.)
        $result = wp_cache_incr($version_key, 1, 'ntdst_versions');

        if ($result === false) {
            // Key doesn't exist yet or backend doesn't support incr - initialize it
            wp_cache_set($version_key, 1, 'ntdst_versions', 0);
        }
    }

    /**
     * Register a model meta prefix so direct post-meta updates invalidate cached queries.
     */
    public function registerMetaPrefix(string $prefix): void
    {
        if ($prefix === '' || in_array($prefix, $this->meta_prefixes, true)) {
            return;
        }

        $this->meta_prefixes[] = $prefix;
    }

    /**
     * Get registered model meta prefixes.
     */
    public function getMetaPrefixes(): array
    {
        return $this->meta_prefixes;
    }

    /**
     * Get cached value
     */
    public function get(string $key, string $group): mixed
    {
        return wp_cache_get($key, $group);
    }

    /**
     * Set cached value
     */
    public function set(string $key, string $group, mixed $value, int $ttl): bool
    {
        return wp_cache_set($key, $value, $group, $ttl);
    }

    /**
     * Resolve cache time based on environment
     *
     * Returns 0 (no cache) in dev mode unless explicitly overridden.
     */
    public function resolveCacheTime(int $requested): int
    {
        if (defined('NTDST_DISABLE_CACHE') && NTDST_DISABLE_CACHE) {
            return 0;
        }
        if (defined('WP_DEBUG') && WP_DEBUG && !defined('NTDST_ENABLE_CACHE_IN_DEBUG')) {
            return 0;
        }
        return $requested;
    }

    /**
     * Check if caching is enabled
     */
    public function isCachingEnabled(): bool
    {
        return $this->resolveCacheTime(1) > 0;
    }

    // -------------------------------------------------------------------------
    // Invalidation Hooks
    // -------------------------------------------------------------------------

    /**
     * Invalidate cache when a post is saved
     */
    public function onPostSave(int $post_id, \WP_Post $post): void
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $this->incrementGroupVersion($post->post_type);
    }

    /**
     * Invalidate cache when a post is deleted
     */
    public function onPostDelete(int $post_id): void
    {
        $post_type = get_post_type($post_id);
        if ($post_type) {
            $this->incrementGroupVersion($post_type);
        }

        // Also clear specific post caches
        if (class_exists('NTDST_Data_Manager')) {
            NTDST_Data_Manager::clearCache($post_id);
        }
    }

    /**
     * Invalidate cache when a post is trashed
     */
    public function onPostTrash(int $post_id): void
    {
        $post_type = get_post_type($post_id);
        if ($post_type) {
            $this->incrementGroupVersion($post_type);
        }
    }

    /**
     * Invalidate cache when post meta is updated
     */
    public function onMetaUpdate(int $meta_id, int $post_id, string $meta_key, mixed $meta_value): void
    {
        $this->invalidateOnMetaChange($post_id, $meta_key);
    }

    /**
     * Invalidate cache when post meta is added
     */
    public function onMetaAdd(int $meta_id, int $post_id, string $meta_key, mixed $meta_value): void
    {
        $this->invalidateOnMetaChange($post_id, $meta_key);
    }

    /**
     * Invalidate cache when post meta is deleted
     */
    public function onMetaDelete(array $meta_ids, int $post_id, string $meta_key, mixed $meta_value): void
    {
        $this->invalidateOnMetaChange($post_id, $meta_key);
    }

    /**
     * Common logic for meta change invalidation
     *
     * Only invalidates for registered model prefixes or explicitly important meta.
     * Filters out WordPress internal meta to avoid unnecessary invalidations.
     */
    private function invalidateOnMetaChange(int $post_id, string $meta_key): void
    {
        // Skip internal WordPress meta (common patterns)
        $wp_internal_prefixes = ['_wp_', '_edit_', '_oembed_', '_menu_item_', '_customize_'];
        foreach ($wp_internal_prefixes as $prefix) {
            if (str_starts_with($meta_key, $prefix)) {
                return;
            }
        }

        // Allowlist: meta keys that should trigger invalidation
        $should_invalidate = false;
        foreach ($this->meta_prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($meta_key, $prefix)) {
                $should_invalidate = true;
                break;
            }
        }

        $should_invalidate = $should_invalidate || in_array($meta_key, [
            '_thumbnail_id',   // Featured image
            '_price',          // WooCommerce price
            '_stock',          // WooCommerce stock
            '_stock_status',   // WooCommerce stock status
        ], true);

        // Allow filtering for custom post types to register their meta keys
        $should_invalidate = apply_filters(
            'ntdst_should_invalidate_meta',
            $should_invalidate,
            $meta_key,
            $post_id,
        );

        if (!$should_invalidate) {
            return;
        }

        $post_type = get_post_type($post_id);
        if ($post_type) {
            $this->incrementGroupVersion($post_type);
        }
    }

    /**
     * Manually invalidate cache for a post type
     *
     * Use this when you know queries need refreshing.
     */
    public function invalidatePostType(string $post_type): void
    {
        $this->incrementGroupVersion($post_type);
    }
}
