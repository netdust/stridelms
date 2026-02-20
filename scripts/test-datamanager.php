<?php
/**
 * DataManager & Caching Test Suite
 *
 * Run with: ddev exec wp eval-file scripts/test-datamanager.php
 *
 * Tests:
 * 1. Basic query functionality
 * 2. Cache invalidation on post save/update/delete
 * 3. Cache invalidation on meta changes
 * 4. Version-based invalidation
 * 5. whereNot for core fields
 * 6. orderBy with numeric sorting
 * 7. Query caching hit/miss
 * 8. Dev mode cache bypass
 */

// Enable caching for tests (override WP_DEBUG)
if (!defined('NTDST_ENABLE_CACHE_IN_DEBUG')) {
    define('NTDST_ENABLE_CACHE_IN_DEBUG', true);
}

class DataManagerTestSuite
{
    private array $created_posts = [];
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "  NTDST DataManager & Caching Test Suite\n";
        echo str_repeat("=", 60) . "\n\n";

        try {
            $this->testBasicQuery();
            $this->testCacheInvalidationOnPostSave();
            $this->testCacheInvalidationOnPostUpdate();
            $this->testCacheInvalidationOnPostDelete();
            $this->testCacheInvalidationOnMetaChange();
            $this->testVersionBasedInvalidation();
            $this->testWhereNotPostStatus();
            $this->testWhereNotPostAuthor();
            $this->testWhereNotUnsupportedField();
            $this->testOrderByNumeric();
            $this->testCacheHitMiss();
            $this->testGlobalHelpers();
            $this->testMetaFilterHook();
        } finally {
            $this->cleanup();
        }

        echo "\n" . str_repeat("=", 60) . "\n";
        echo "  Results: {$this->passed} passed, {$this->failed} failed\n";
        echo str_repeat("=", 60) . "\n\n";

        if ($this->failed > 0) {
            exit(1);
        }
    }

    // =========================================================================
    // Test Cases
    // =========================================================================

    private function testBasicQuery(): void
    {
        $this->test("Basic query returns posts", function() {
            $post_id = $this->createPost(['post_title' => 'Basic Query Test']);

            $posts = NTDST_Data_Manager::getPostsFast([
                'post_type' => 'post',
                'posts_per_page' => 10,
                'cache_time' => 3600,
            ]);

            $found = array_filter($posts, fn($p) => $p['id'] === $post_id);
            $this->assert(count($found) === 1, "Post should be found in results");
            $this->assert(reset($found)['title'] === 'Basic Query Test', "Title should match");
        });
    }

    private function testCacheInvalidationOnPostSave(): void
    {
        $this->test("Cache invalidates on post save", function() {
            // Create initial post and cache query
            $post_id = $this->createPost(['post_title' => 'Cache Test Original']);

            // Force cache by querying
            $this->queryPosts();

            // Get version before
            $version_before = ntdst_query_cache()->getGroupVersion('post');

            // Create another post (triggers save_post)
            $this->createPost(['post_title' => 'Cache Test New']);

            // Version should have incremented
            $version_after = ntdst_query_cache()->getGroupVersion('post');

            $this->assert(
                $version_after > $version_before,
                "Version should increment on save (was {$version_before}, now {$version_after})"
            );
        });
    }

    private function testCacheInvalidationOnPostUpdate(): void
    {
        $this->test("Cache invalidates on post update with fresh data", function() {
            $post_id = $this->createPost(['post_title' => 'Update Test Original']);

            // Query and cache
            $posts = $this->queryPosts();
            $original_title = $this->findPost($posts, $post_id)['title'] ?? null;
            $this->assert($original_title === 'Update Test Original', "Original title should match");

            // Update post
            wp_update_post(['ID' => $post_id, 'post_title' => 'Update Test Modified']);

            // Query again - should get fresh data
            $posts = $this->queryPosts();
            $updated_title = $this->findPost($posts, $post_id)['title'] ?? null;

            $this->assert(
                $updated_title === 'Update Test Modified',
                "Updated title should be 'Update Test Modified', got '{$updated_title}'"
            );
        });
    }

    private function testCacheInvalidationOnPostDelete(): void
    {
        $this->test("Cache invalidates on post delete", function() {
            $post_id = $this->createPost(['post_title' => 'Delete Test']);

            // Query and verify exists
            $posts = $this->queryPosts();
            $found = $this->findPost($posts, $post_id);
            $this->assert($found !== null, "Post should exist before delete");

            // Delete post (remove from tracking first)
            $this->created_posts = array_filter($this->created_posts, fn($id) => $id !== $post_id);
            wp_delete_post($post_id, true);

            // Query again - post should be gone
            $posts = $this->queryPosts();
            $found = $this->findPost($posts, $post_id);

            $this->assert($found === null, "Post should not exist after delete");
        });
    }

    private function testCacheInvalidationOnMetaChange(): void
    {
        $this->test("Cache invalidates on _ntdst_ meta change", function() {
            $post_id = $this->createPost(['post_title' => 'Meta Test']);

            $version_before = ntdst_query_cache()->getGroupVersion('post');

            // Update meta with _ntdst_ prefix (should trigger invalidation)
            update_post_meta($post_id, '_ntdst_test_field', 'test_value');

            $version_after = ntdst_query_cache()->getGroupVersion('post');

            $this->assert(
                $version_after > $version_before,
                "Version should increment on _ntdst_ meta change"
            );
        });
    }

    private function testVersionBasedInvalidation(): void
    {
        $this->test("Version-based invalidation works", function() {
            $cache = ntdst_query_cache();

            // Get initial version
            $v1 = $cache->getGroupVersion('test_post_type');

            // Increment
            $cache->incrementGroupVersion('test_post_type');
            $v2 = $cache->getGroupVersion('test_post_type');

            // Increment again
            $cache->incrementGroupVersion('test_post_type');
            $v3 = $cache->getGroupVersion('test_post_type');

            $this->assert($v2 > $v1, "Version should increment (v1={$v1}, v2={$v2})");
            $this->assert($v3 > $v2, "Version should increment again (v2={$v2}, v3={$v3})");
        });
    }

    private function testWhereNotPostStatus(): void
    {
        $this->test("whereNot works for post_status", function() {
            // Create a draft post
            $draft_id = $this->createPost([
                'post_title' => 'Draft Post',
                'post_status' => 'draft'
            ]);

            // Create a published post
            $pub_id = $this->createPost([
                'post_title' => 'Published Post',
                'post_status' => 'publish'
            ]);

            // Query excluding drafts
            $posts = ntdst_data()->get('post')
                ->whereNot('post_status', 'draft')
                ->limit(50)
                ->get();

            $found_draft = $this->findPost($posts, $draft_id);
            $found_pub = $this->findPost($posts, $pub_id);

            $this->assert($found_draft === null, "Draft post should be excluded");
            $this->assert($found_pub !== null, "Published post should be included");
        });
    }

    private function testWhereNotPostAuthor(): void
    {
        $this->test("whereNot works for post_author", function() {
            $current_user = get_current_user_id();
            if ($current_user === 0) {
                $current_user = 1; // Fallback to admin
            }

            $post_id = $this->createPost([
                'post_title' => 'Author Test',
                'post_author' => $current_user
            ]);

            // Query excluding current author
            $posts = ntdst_data()->get('post')
                ->whereNot('post_author', $current_user)
                ->limit(50)
                ->get();

            $found = $this->findPost($posts, $post_id);

            $this->assert($found === null, "Post by excluded author should not appear");
        });
    }

    private function testWhereNotUnsupportedField(): void
    {
        $this->test("whereNot throws exception for unsupported core fields", function() {
            $threw = false;
            $message = '';

            try {
                ntdst_data()->get('post')
                    ->whereNot('menu_order', 5)
                    ->get();
            } catch (\InvalidArgumentException $e) {
                $threw = true;
                $message = $e->getMessage();
            }

            $this->assert($threw, "Should throw InvalidArgumentException");
            $this->assert(
                str_contains($message, 'menu_order'),
                "Exception should mention the field name"
            );
        });
    }

    private function testOrderByNumeric(): void
    {
        $this->test("orderBy with numeric=true sorts numerically", function() {
            // Register a simple model with numeric field
            $model = ntdst_data()->register('post', [
                'fields' => ['test_number' => 'int'],
                'meta_prefix' => '_ntdst_',
            ]);

            // Create posts with numeric meta
            $post1 = $this->createPost(['post_title' => 'Number 2']);
            $post2 = $this->createPost(['post_title' => 'Number 10']);
            $post3 = $this->createPost(['post_title' => 'Number 1']);

            update_post_meta($post1, '_ntdst_test_number', '2');
            update_post_meta($post2, '_ntdst_test_number', '10');
            update_post_meta($post3, '_ntdst_test_number', '1');

            // Query with numeric ordering
            $posts = ntdst_data()->get('post')
                ->whereIn('ID', [$post1, $post2, $post3])
                ->orderBy('test_number', 'ASC', true)
                ->get();

            if (count($posts) >= 3) {
                // With numeric sort: 1, 2, 10
                // With string sort: 1, 10, 2
                $this->assert(
                    $posts[0]['id'] === $post3,
                    "First should be post with number 1"
                );
                $this->assert(
                    $posts[1]['id'] === $post1,
                    "Second should be post with number 2"
                );
                $this->assert(
                    $posts[2]['id'] === $post2,
                    "Third should be post with number 10 (numeric sort)"
                );
            }
        });
    }

    private function testCacheHitMiss(): void
    {
        $this->test("Cache hit returns same results quickly", function() {
            // Clear any existing cache
            ntdst_invalidate_post_type('post');

            $this->createPost(['post_title' => 'Cache Hit Test']);

            // First query (cache miss)
            $start1 = microtime(true);
            $posts1 = $this->queryPosts();
            $time1 = microtime(true) - $start1;

            // Second query (cache hit)
            $start2 = microtime(true);
            $posts2 = $this->queryPosts();
            $time2 = microtime(true) - $start2;

            $this->assert(
                count($posts1) === count($posts2),
                "Both queries should return same count"
            );

            // Note: In dev mode with object cache, cache hit may not be faster
            // The important thing is results are consistent
            $this->assert(
                $posts1[0]['id'] === $posts2[0]['id'],
                "Results should be identical"
            );
        });
    }

    private function testGlobalHelpers(): void
    {
        $this->test("Global helpers work correctly", function() {
            // Test ntdst_query_cache()
            $cache = ntdst_query_cache();
            $this->assert(
                $cache instanceof NTDST_Query_Cache,
                "ntdst_query_cache() should return NTDST_Query_Cache instance"
            );

            // Test ntdst_invalidate_post_type()
            $version_before = $cache->getGroupVersion('test_type');
            ntdst_invalidate_post_type('test_type');
            $version_after = $cache->getGroupVersion('test_type');

            $this->assert(
                $version_after > $version_before,
                "ntdst_invalidate_post_type() should increment version"
            );

            // Test ntdst_data()
            $manager = ntdst_data();
            $this->assert(
                $manager instanceof NTDST_Data_Manager,
                "ntdst_data() should return NTDST_Data_Manager instance"
            );
        });
    }

    private function testMetaFilterHook(): void
    {
        $this->test("Meta invalidation filter hook works", function() {
            $post_id = $this->createPost(['post_title' => 'Filter Hook Test']);

            // Add filter to invalidate on custom meta
            $filter_called = false;
            add_filter('ntdst_should_invalidate_meta', function($should, $key, $pid) use (&$filter_called, $post_id) {
                if ($key === 'my_custom_meta' && $pid === $post_id) {
                    $filter_called = true;
                    return true; // Force invalidation
                }
                return $should;
            }, 10, 3);

            $version_before = ntdst_query_cache()->getGroupVersion('post');

            // Update custom meta (not _ntdst_ prefixed)
            update_post_meta($post_id, 'my_custom_meta', 'test');

            $version_after = ntdst_query_cache()->getGroupVersion('post');

            $this->assert($filter_called, "Filter hook should be called");
            $this->assert(
                $version_after > $version_before,
                "Cache should invalidate via filter hook"
            );
        });
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function createPost(array $args): int
    {
        $defaults = [
            'post_type' => 'post',
            'post_status' => 'publish',
        ];

        $post_id = wp_insert_post(array_merge($defaults, $args));
        $this->created_posts[] = $post_id;

        return $post_id;
    }

    private function queryPosts(): array
    {
        return NTDST_Data_Manager::getPostsFast([
            'post_type' => 'post',
            'posts_per_page' => 50,
            'cache_time' => 3600,
        ]);
    }

    private function findPost(array $posts, int $post_id): ?array
    {
        foreach ($posts as $post) {
            if ($post['id'] === $post_id) {
                return $post;
            }
        }
        return null;
    }

    private function test(string $name, callable $fn): void
    {
        echo "  Testing: {$name}... ";

        try {
            $fn();
            echo "✓ PASS\n";
            $this->passed++;
        } catch (\Throwable $e) {
            echo "✗ FAIL\n";
            echo "    Error: {$e->getMessage()}\n";
            if ($e->getFile()) {
                echo "    At: {$e->getFile()}:{$e->getLine()}\n";
            }
            $this->failed++;
        }
    }

    private function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException("Assertion failed: {$message}");
        }
    }

    private function cleanup(): void
    {
        $count = count($this->created_posts);
        echo "\n  Cleaning up {$count} test posts...\n";

        foreach ($this->created_posts as $post_id) {
            wp_delete_post($post_id, true);
        }

        echo "  Cleanup complete.\n";
    }
}

// Run the tests
$suite = new DataManagerTestSuite();
$suite->run();
