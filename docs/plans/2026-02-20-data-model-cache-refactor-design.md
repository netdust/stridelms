# NTDST_Data_Model Cache Layer Refactor

**Date:** 2026-02-20
**Status:** Approved
**Author:** Claude + ntdst

## Problem Statement

The `NTDST_Data_Model` class has stability issues:

1. **Stale cache results** - Cache invalidation is fragile; `clearCache()` generates keys differently than `get()`, causing old data to persist
2. **Unclear metadata handling** - Field ordering, meta vs core field detection, and numeric sorting behave unpredictably
3. **No automatic invalidation** - Cache only clears when explicitly called, missing post updates/deletes

## Solution: Extract Cache Layer

Create a dedicated `NTDST_Query_Cache` class that owns all caching concerns while keeping the query builder API unchanged.

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                   NTDST_Data_Model                      │
│  (Query builder - unchanged API)                        │
│                                                         │
│  ->where()->orderBy()->limit()->get()                   │
└─────────────────────┬───────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│                  NTDST_Query_Cache                      │
│  (New - handles all caching concerns)                   │
│                                                         │
│  - Deterministic key generation                         │
│  - Cache groups by post type                            │
│  - Automatic invalidation hooks                         │
│  - Dev mode bypass                                      │
└─────────────────────────────────────────────────────────┘
                      │
                      ▼
┌─────────────────────────────────────────────────────────┐
│                   WordPress Cache                       │
│  (wp_cache_get / wp_cache_set / wp_cache_delete)        │
└─────────────────────────────────────────────────────────┘
```

## Design Details

### 1. Cache Key Generation

Single method used for both get and clear operations:

```php
public function generateKey(array $query_args, string $post_type): string
{
    // Remove volatile args
    unset($query_args['cache_results']);
    unset($query_args['update_post_meta_cache']);
    unset($query_args['update_post_term_cache']);

    // Sort keys for deterministic ordering
    ksort($query_args);

    // Include version for invalidation
    $group = $this->getGroup($post_type);
    $version = (int) wp_cache_get("{$group}_version", 'ntdst_versions');

    $hash = md5(serialize($query_args));
    return "ntdst_query_{$post_type}_v{$version}_{$hash}";
}

public function getGroup(string $post_type): string
{
    return "ntdst_data_{$post_type}";
}
```

### 2. Automatic Cache Invalidation

Hooks into WordPress lifecycle events:

```php
public function __construct()
{
    add_action('save_post', [$this, 'invalidatePostType'], 10, 2);
    add_action('delete_post', [$this, 'invalidatePostType'], 10, 2);
    add_action('trashed_post', [$this, 'invalidateOnTrash']);
    add_action('updated_postmeta', [$this, 'invalidateOnMetaChange'], 10, 4);
}

public function invalidatePostType($post_id, $post): void
{
    if (wp_is_post_revision($post_id)) {
        return;
    }

    $post_type = is_object($post) ? $post->post_type : get_post_type($post_id);
    $this->incrementGroupVersion($this->getGroup($post_type));
}

public function invalidateOnMetaChange($meta_id, $post_id, $meta_key, $meta_value): void
{
    if (strpos($meta_key, '_ntdst_') !== 0) {
        return;
    }

    $post_type = get_post_type($post_id);
    if ($post_type) {
        $this->incrementGroupVersion($this->getGroup($post_type));
    }
}
```

### 3. Version-Based Invalidation

Instead of tracking and deleting individual cache keys, increment a version number:

```php
private function incrementGroupVersion(string $group): void
{
    $version_key = "{$group}_version";
    $version = (int) wp_cache_get($version_key, 'ntdst_versions') + 1;
    wp_cache_set($version_key, $version, 'ntdst_versions', 0);
}
```

Benefits:
- No need to track individual cache keys
- Incrementing version instantly invalidates all queries for that post type
- Old cache entries naturally expire via TTL
- Simpler than maintaining a key registry

### 4. Integration with NTDST_Data_Model

```php
class NTDST_Data_Model
{
    private NTDST_Query_Cache $cache;

    public function __construct(string $post_type, int $cache_time = 3600)
    {
        $this->cache = NTDST_Query_Cache::instance();
        $this->post_type = $post_type;
        $this->cache_time = $this->cache->resolveCacheTime($cache_time);
    }

    public function get(): array
    {
        $key = $this->cache->generateKey($this->query_args, $this->post_type);
        $group = $this->cache->getGroup($this->post_type);

        $cached = $this->cache->get($key, $group);
        if ($cached !== false) {
            return $cached;
        }

        $results = $this->executeQuery();
        $this->cache->set($key, $group, $results, $this->cache_time);
        return $results;
    }

    public function clearCache(): self
    {
        $this->cache->invalidatePostType(0, (object)['post_type' => $this->post_type]);
        return $this;
    }
}
```

## Additional Fixes

These issues will be fixed as part of this refactor:

### Fix `whereNot` for Core Fields

Currently `whereNot('post_status', 'trash')` just adds `__not` suffix which doesn't work. Fix: properly handle core field negation in query args.

### Add Numeric Meta Ordering

Support `meta_value_num` for numeric sorting:

```php
public function orderBy(string $field, string $dir = 'DESC', bool $numeric = false): self
{
    if (!in_array($field, $this->coreOrderBy, true)) {
        $this->query_args['meta_key'] = $this->prefixMetaKey($field);
        $this->query_args['orderby'] = $numeric ? 'meta_value_num' : 'meta_value';
    }
    // ...
}
```

### Dev Mode Cache Bypass

Consolidate in cache class:

```php
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
```

## Migration Strategy

1. Add `NTDST_Query_Cache` class to `ntdst-core/api/`
2. Update `NTDST_Data_Model` to delegate caching to new class
3. Existing code works unchanged - API is identical
4. Cache invalidation now automatic via WordPress hooks

## Files Changed

- `web/app/mu-plugins/ntdst-core/api/QueryCache.php` (new)
- `web/app/mu-plugins/ntdst-core/api/Data.php` (modified)

## Success Criteria

- [ ] No stale cache results after post updates
- [ ] Consistent query results across page loads
- [ ] Existing `NTDST_Data::get()` calls work unchanged
- [ ] Dev mode properly bypasses cache
- [ ] Numeric meta ordering works correctly
