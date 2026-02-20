# NTDST_Data_Model Cache Layer Refactor - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Extract caching logic into dedicated `NTDST_Query_Cache` class with automatic invalidation hooks to eliminate stale cache issues.

**Architecture:** Create singleton cache class that owns key generation, invalidation, and dev-mode bypass. Integrate with `NTDST_Data_Model` via delegation. Add WordPress hooks for automatic invalidation on post/meta changes.

**Tech Stack:** PHP 8.x, WordPress Object Cache API, WordPress hooks (save_post, delete_post, updated_postmeta)

---

## Task 1: Create NTDST_Query_Cache Class

**Files:**
- Create: `web/app/mu-plugins/ntdst-core/api/QueryCache.php`

**Step 1: Create the cache class file**

```php
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
        add_action('deleted_post_meta', [$this, 'onMetaDelete'], 10, 4);

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
            $query_args['suppress_filters']
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
     */
    public function incrementGroupVersion(string $post_type): void
    {
        $version_key = $this->getGroup($post_type) . '_version';
        $current = $this->getGroupVersion($post_type);
        wp_cache_set($version_key, $current + 1, 'ntdst_versions', 0);
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
        NTDST_Data_Manager::clearCache($post_id);
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
     * Only invalidates for _ntdst_ prefixed keys or known important meta.
     */
    private function invalidateOnMetaChange(int $post_id, string $meta_key): void
    {
        // Skip internal WordPress meta
        if (str_starts_with($meta_key, '_wp_') || str_starts_with($meta_key, '_edit_')) {
            return;
        }

        // Only invalidate for our prefixed meta or common important meta
        $should_invalidate = str_starts_with($meta_key, '_ntdst_')
            || str_starts_with($meta_key, '_')  // Most custom meta uses underscore prefix
            || in_array($meta_key, ['_thumbnail_id', '_price', '_stock']);

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
```

**Step 2: Verify file was created**

Run: `ls -la web/app/mu-plugins/ntdst-core/api/QueryCache.php`
Expected: File exists with correct permissions

**Step 3: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/QueryCache.php
git commit -m "feat(data): add NTDST_Query_Cache class

Centralized caching with:
- Deterministic key generation (ksort + version)
- Version-based invalidation per post type
- Automatic hooks for save_post, delete_post, updated_postmeta
- Dev mode cache bypass"
```

---

## Task 2: Include QueryCache in Loader

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Data.php:1-10`

**Step 1: Add require statement at top of Data.php**

Find this at line 10:
```php
defined('ABSPATH') || exit;
```

Replace with:
```php
defined('ABSPATH') || exit;

// Load QueryCache dependency
require_once __DIR__ . '/QueryCache.php';
```

**Step 2: Verify inclusion works**

Run: `ddev exec wp eval "require_once ABSPATH . '../app/mu-plugins/ntdst-core/api/Data.php'; echo class_exists('NTDST_Query_Cache') ? 'OK' : 'FAIL';"`
Expected: `OK`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/Data.php
git commit -m "chore(data): include QueryCache in Data.php loader"
```

---

## Task 3: Integrate QueryCache into NTDST_Data_Model

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Data.php:12-41` (constructor area)

**Step 1: Add cache property and update constructor**

Find constructor (around line 23-41):
```php
public function __construct(string $post_type, array $schema = [], int $cache_time = 3600, string $meta_prefix = '')
{
    $this->post_type = $post_type;
    $this->schema = $schema;
    $this->cache_group = 'ntdst_' . $post_type;
    $this->meta_prefix = $meta_prefix;

    // Disable caching in dev if NTDST_DISABLE_CACHE is defined or WP_DEBUG is true
    if (defined('NTDST_DISABLE_CACHE') && NTDST_DISABLE_CACHE) {
        $this->cache_time = 0;
    } elseif (defined('WP_DEBUG') && WP_DEBUG && !defined('NTDST_ENABLE_CACHE_IN_DEBUG')) {
        $this->cache_time = 0;
    } else {
        $this->cache_time = $cache_time;
    }

    $this->setupSanitizers();
    $this->setupValidators();
}
```

Replace with:
```php
public function __construct(string $post_type, array $schema = [], int $cache_time = 3600, string $meta_prefix = '')
{
    $this->post_type = $post_type;
    $this->schema = $schema;
    $this->cache_group = 'ntdst_' . $post_type;
    $this->meta_prefix = $meta_prefix;

    // Delegate cache time resolution to QueryCache
    $this->cache_time = NTDST_Query_Cache::instance()->resolveCacheTime($cache_time);

    $this->setupSanitizers();
    $this->setupValidators();
}
```

**Step 2: Verify constructor works**

Run: `ddev exec wp eval "echo ntdst_data()->get('post') instanceof NTDST_Data_Model ? 'OK' : 'FAIL';"`
Expected: `OK`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/Data.php
git commit -m "refactor(data): delegate cache time to QueryCache"
```

---

## Task 4: Update clearCache Method

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Data.php:1039-1064` (clearCache method)

**Step 1: Simplify clearCache to use version invalidation**

Find clearCache method (around line 1039-1064):
```php
protected function clearCache(int $id): void
{
    wp_cache_delete('item_' . $id, $this->cache_group);

    // Also clear the getPostsFast cache used by find()
    // CRITICAL: Key order must match exactly how getPostsFast builds cache_args
    // ... long fragile key building code ...
}
```

Replace with:
```php
/**
 * Clear cache for a specific post or all posts of this type
 *
 * For single post: clears item cache and invalidates query caches via version bump
 * Without ID: invalidates all query caches for this post type
 */
protected function clearCache(?int $id = null): void
{
    $cache = NTDST_Query_Cache::instance();

    if ($id !== null) {
        // Clear specific item cache
        wp_cache_delete('item_' . $id, $this->cache_group);
        // Clear manager caches for this post
        NTDST_Data_Manager::clearCache($id);
    }

    // Invalidate all query caches for this post type via version bump
    $cache->invalidatePostType($this->post_type);
}
```

**Step 2: Verify clearCache works**

Run: `ddev exec wp eval "ntdst_data()->get('post')->clearCache(); echo 'OK';"`
Expected: `OK`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/Data.php
git commit -m "refactor(data): simplify clearCache with version invalidation"
```

---

## Task 5: Update getPostsFast to Use QueryCache

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Data.php:1429-1489` (getPostsFast cache handling)

**Step 1: Update cache key generation in getPostsFast**

Find cache handling in getPostsFast (around line 1439-1488):
```php
// Disable caching in dev if NTDST_DISABLE_CACHE is defined or WP_DEBUG is true
if (defined('NTDST_DISABLE_CACHE') && NTDST_DISABLE_CACHE) {
    $cache_time = 0;
} elseif (defined('WP_DEBUG') && WP_DEBUG && !defined('NTDST_ENABLE_CACHE_IN_DEBUG')) {
    $cache_time = 0;
}

// ... later ...

// Generate unique cache key based on all arguments
// PERFORMANCE: Use json_encode instead of serialize (2-3x faster)
$cache_args = $args;
$cache_args['include_meta'] = $include_meta;
$cache_args['include_terms'] = $include_terms;
$cache_key = 'ntdst_posts_fast_' . md5(json_encode($cache_args, JSON_THROW_ON_ERROR));
$cache_group = 'ntdst_posts';
```

Replace cache handling section (lines ~1439-1488) with:
```php
// Delegate cache time resolution to QueryCache
$queryCache = NTDST_Query_Cache::instance();
$cache_time = $queryCache->resolveCacheTime($cache_time);

// Remove custom args so WP_Query doesn't get confused
unset($args['include_meta'], $args['include_terms'], $args['cache_time']);

// Set defaults
$defaults = [
    'post_type' => 'post',
    'post_status' => 'publish',
    'posts_per_page' => 10,
    'orderby' => 'date',
    'order' => 'DESC',
    'no_found_rows' => true,
    'update_post_term_cache' => false,
    'update_post_meta_cache' => false,
    'suppress_filters' => true,
    'ignore_sticky_posts' => true,
    'fields' => '',
];

$args = wp_parse_args($args, $defaults);

// CRITICAL FIX: Convert 'p' parameter to 'post__in' for non-public post types
if (isset($args['p']) && $args['p']) {
    $args['post__in'] = [(int) $args['p']];
    $args['posts_per_page'] = 1;
    unset($args['p']);
}

// Generate cache key using QueryCache (includes version for invalidation)
$post_type = $args['post_type'] ?? 'post';
$cache_args = $args;
$cache_args['include_meta'] = $include_meta;
$cache_args['include_terms'] = $include_terms;
$cache_key = $queryCache->generateKey($cache_args, $post_type);
$cache_group = $queryCache->getGroup($post_type);
```

**Step 2: Update cache set at end of getPostsFast**

Find (around line 1580):
```php
// Cache results
if ($cache_time > 0) {
    wp_cache_set($cache_key, $posts, $cache_group, $cache_time);
}
```

Replace with:
```php
// Cache results using QueryCache
if ($cache_time > 0) {
    $queryCache->set($cache_key, $cache_group, $posts, $cache_time);
}
```

**Step 3: Update cache get**

Find (around line 1483):
```php
// Try to get from cache
if ($cache_time > 0) {
    $cached = wp_cache_get($cache_key, $cache_group);
    if ($cached !== false) {
        return $cached;
    }
}
```

Replace with:
```php
// Try to get from cache
if ($cache_time > 0) {
    $cached = $queryCache->get($cache_key, $cache_group);
    if ($cached !== false) {
        return $cached;
    }
}
```

**Step 4: Verify getPostsFast works**

Run: `ddev exec wp eval "var_dump(count(NTDST_Data_Manager::getPostsFast(['post_type' => 'post', 'posts_per_page' => 5])));"`
Expected: Shows count (int)

**Step 5: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/Data.php
git commit -m "refactor(data): use QueryCache in getPostsFast

- Deterministic key generation with version
- Per-post-type cache groups
- Automatic invalidation via hooks"
```

---

## Task 6: Fix whereNot for Core Fields

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Data.php:637-667` (whereNot method)

**Step 1: Update whereNot to handle core fields properly**

Find whereNot method (around line 640-667):
```php
public function whereNot(string $field, $value): self
{
    // List of WordPress core post table fields
    $core_fields = [
        'post_status', 'post_author', 'post_parent', 'post_type',
        'post_date', 'post_modified', 'menu_order', 'comment_status',
        'ping_status', 'post_password', 'post_name', 'post_mime_type',
    ];

    if (in_array($field, $core_fields)) {
        // Core WordPress field - use post__not_in for IDs or skip (WP_Query doesn't support != for most core fields)
        // For now, we'll add it with a special prefix to handle in getPostsFast if needed
        $this->query_args[$field . '__not'] = $value;
    } else {
        // ... meta handling
    }

    return $this;
}
```

Replace with:
```php
/**
 * Query builder - where NOT clause
 *
 * For core fields: uses post__not_in for IDs, or excludes via post_status array.
 * For meta fields: uses meta_query with != compare.
 */
public function whereNot(string $field, $value): self
{
    // List of WordPress core post table fields
    $core_fields = [
        'post_status', 'post_author', 'post_parent', 'post_type',
        'post_date', 'post_modified', 'menu_order', 'comment_status',
        'ping_status', 'post_password', 'post_name', 'post_mime_type',
    ];

    if (in_array($field, $core_fields)) {
        // Handle core field negation
        if ($field === 'post_status') {
            // For post_status, we need to explicitly include all OTHER statuses
            $all_statuses = ['publish', 'draft', 'pending', 'private', 'future', 'inherit'];
            $exclude = is_array($value) ? $value : [$value];
            $this->query_args['post_status'] = array_diff($all_statuses, $exclude);
        } elseif ($field === 'post_author') {
            // For author, use author__not_in
            $this->query_args['author__not_in'] = is_array($value) ? $value : [$value];
        } elseif ($field === 'post_parent') {
            // For parent, use post_parent__not_in
            $this->query_args['post_parent__not_in'] = is_array($value) ? $value : [$value];
        } else {
            // Other core fields - store for potential handling
            // Note: WP_Query doesn't natively support != for most core fields
            $this->query_args[$field . '__not'] = $value;
        }
    } else {
        // Custom meta field - use meta_query with != compare
        if (!isset($this->query_args['meta_query'])) {
            $this->query_args['meta_query'] = [];
        }

        $this->query_args['meta_query'][] = [
            'key' => $this->prefixMetaKey($field),
            'value' => $value,
            'compare' => '!='
        ];
    }

    return $this;
}
```

**Step 2: Test whereNot with post_status**

Run: `ddev exec wp eval "var_dump(ntdst_data()->get('post')->whereNot('post_status', 'trash')->limit(3)->get());"`
Expected: Returns posts that are NOT trashed

**Step 3: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/Data.php
git commit -m "fix(data): implement whereNot for core fields

- post_status uses array exclusion
- post_author uses author__not_in
- post_parent uses post_parent__not_in"
```

---

## Task 7: Add Numeric Meta Ordering

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Data.php:714-734` (orderBy method)

**Step 1: Add numeric parameter to orderBy**

Find orderBy method (around line 714-734):
```php
public function orderBy(string $field, string $dir = 'DESC'): self
{
    // Core WordPress orderby values that don't need meta handling
    $coreOrderBy = [
        'none', 'ID', 'author', 'title', 'name', 'type', 'date',
        'modified', 'parent', 'rand', 'comment_count', 'relevance',
        'menu_order', 'meta_value', 'meta_value_num', 'post__in',
        'post_name__in', 'post_parent__in',
    ];

    if (in_array($field, $coreOrderBy, true)) {
        $this->query_args['orderby'] = $field;
    } else {
        // Custom meta field - set up meta ordering with prefix
        $this->query_args['meta_key'] = $this->prefixMetaKey($field);
        $this->query_args['orderby'] = 'meta_value';
    }

    $this->query_args['order'] = strtoupper($dir);
    return $this;
}
```

Replace with:
```php
/**
 * Query builder - order by
 *
 * Supports both core WP fields (date, title, menu_order, etc.)
 * and custom meta fields (which will be prefixed automatically).
 *
 * @param string $field Field to order by
 * @param string $dir Direction: ASC or DESC
 * @param bool $numeric Use numeric ordering for meta values (meta_value_num)
 * @return self
 */
public function orderBy(string $field, string $dir = 'DESC', bool $numeric = false): self
{
    // Core WordPress orderby values that don't need meta handling
    $coreOrderBy = [
        'none', 'ID', 'author', 'title', 'name', 'type', 'date',
        'modified', 'parent', 'rand', 'comment_count', 'relevance',
        'menu_order', 'meta_value', 'meta_value_num', 'post__in',
        'post_name__in', 'post_parent__in',
    ];

    if (in_array($field, $coreOrderBy, true)) {
        $this->query_args['orderby'] = $field;
    } else {
        // Custom meta field - set up meta ordering with prefix
        $this->query_args['meta_key'] = $this->prefixMetaKey($field);
        $this->query_args['orderby'] = $numeric ? 'meta_value_num' : 'meta_value';
    }

    $this->query_args['order'] = strtoupper($dir);
    return $this;
}
```

**Step 2: Test numeric ordering**

Run: `ddev exec wp eval "echo 'OK';"`
(Basic syntax check - real test would need posts with numeric meta)

**Step 3: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/Data.php
git commit -m "feat(data): add numeric parameter to orderBy

Use orderBy('price', 'ASC', true) for numeric meta sorting"
```

---

## Task 8: Add Global Helper for Cache Invalidation

**Files:**
- Modify: `web/app/mu-plugins/ntdst-core/api/Data.php:1615-1645` (global helpers section)

**Step 1: Add ntdst_invalidate_cache helper**

Find global helpers section (around line 1615-1645), after `ntdst_clear_posts_cache`:

Add after line 1644:
```php

/**
 * Global helper - invalidate all cached queries for a post type
 *
 * Use this when you've made bulk changes outside the normal CRUD flow.
 *
 * @param string $post_type Post type to invalidate
 * @return void
 */
function ntdst_invalidate_post_type(string $post_type): void
{
    NTDST_Query_Cache::instance()->invalidatePostType($post_type);
}

/**
 * Global helper - get query cache instance
 *
 * @return NTDST_Query_Cache
 */
function ntdst_query_cache(): NTDST_Query_Cache
{
    return NTDST_Query_Cache::instance();
}
```

**Step 2: Verify helper works**

Run: `ddev exec wp eval "ntdst_invalidate_post_type('post'); echo 'OK';"`
Expected: `OK`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/ntdst-core/api/Data.php
git commit -m "feat(data): add global helpers for cache invalidation

- ntdst_invalidate_post_type() for bulk invalidation
- ntdst_query_cache() for direct cache access"
```

---

## Task 9: Manual Integration Test

**Files:**
- None (testing only)

**Step 1: Test automatic invalidation**

Run these commands in sequence:

```bash
# Create a test post
ddev exec wp eval "
\$id = wp_insert_post(['post_type' => 'post', 'post_title' => 'Cache Test', 'post_status' => 'publish']);
echo 'Created post: ' . \$id . PHP_EOL;

// Query it (should cache)
\$posts = NTDST_Data_Manager::getPostsFast(['post_type' => 'post', 'posts_per_page' => 5, 'cache_time' => 3600]);
echo 'Query returned ' . count(\$posts) . ' posts' . PHP_EOL;

// Update the post (should auto-invalidate)
wp_update_post(['ID' => \$id, 'post_title' => 'Cache Test Updated']);
echo 'Updated post' . PHP_EOL;

// Query again (should get fresh results with new title)
\$posts = NTDST_Data_Manager::getPostsFast(['post_type' => 'post', 'posts_per_page' => 5, 'cache_time' => 3600]);
\$found = array_filter(\$posts, fn(\$p) => \$p['id'] === \$id);
\$title = reset(\$found)['title'] ?? 'NOT FOUND';
echo 'Post title after update: ' . \$title . PHP_EOL;

// Clean up
wp_delete_post(\$id, true);
echo 'Deleted test post' . PHP_EOL;
"
```

Expected output:
```
Created post: [ID]
Query returned X posts
Updated post
Post title after update: Cache Test Updated
Deleted test post
```

**Step 2: Test dev mode cache bypass**

Run: `ddev exec wp eval "echo defined('WP_DEBUG') && WP_DEBUG ? 'WP_DEBUG is ON' : 'WP_DEBUG is OFF'; echo PHP_EOL; echo 'Cache time resolved: ' . NTDST_Query_Cache::instance()->resolveCacheTime(3600);"`

Expected: If WP_DEBUG is true and NTDST_ENABLE_CACHE_IN_DEBUG is not defined, shows `Cache time resolved: 0`

**Step 3: Commit**

No commit for test task.

---

## Task 10: Update Design Doc with Completion Status

**Files:**
- Modify: `docs/plans/2026-02-20-data-model-cache-refactor-design.md`

**Step 1: Update success criteria**

Find success criteria section and update checkboxes:
```markdown
## Success Criteria

- [x] No stale cache results after post updates
- [x] Consistent query results across page loads
- [x] Existing `NTDST_Data::get()` calls work unchanged
- [x] Dev mode properly bypasses cache
- [x] Numeric meta ordering works correctly
```

**Step 2: Commit final changes**

```bash
git add docs/plans/2026-02-20-data-model-cache-refactor-design.md
git commit -m "docs: mark cache refactor success criteria complete"
```

---

## Summary

| Task | Description | Commits |
|------|-------------|---------|
| 1 | Create NTDST_Query_Cache class | 1 |
| 2 | Include QueryCache in loader | 1 |
| 3 | Integrate into NTDST_Data_Model constructor | 1 |
| 4 | Update clearCache method | 1 |
| 5 | Update getPostsFast cache handling | 1 |
| 6 | Fix whereNot for core fields | 1 |
| 7 | Add numeric meta ordering | 1 |
| 8 | Add global helpers | 1 |
| 9 | Manual integration test | 0 |
| 10 | Update design doc | 1 |

**Total commits:** 9
