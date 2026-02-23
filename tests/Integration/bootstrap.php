<?php

/**
 * Integration Test Bootstrap
 *
 * Loads WordPress for integration testing.
 * Run these tests inside DDEV: ddev exec vendor/bin/phpunit --testsuite Integration
 */

declare(strict_types=1);

// Composer autoloader
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Define test environment
define('STRIDE_INTEGRATION_TESTING', true);

// Bedrock paths
$webRoot = dirname(__DIR__, 2) . '/web';

// Load WordPress
require_once $webRoot . '/wp/wp-load.php';

// Ensure we're in a test-safe state
if (!defined('DOING_PHPUNIT') && !defined('WP_TESTS_DOMAIN')) {
    define('DOING_PHPUNIT', true);
}

/**
 * Integration Test Case Base Class
 */
abstract class IntegrationTestCase extends \PHPUnit\Framework\TestCase
{
    protected static ?int $testUserId = null;
    protected static array $testPosts = [];

    /**
     * Create a test user for the suite
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create a test user for the entire test class
        $username = 'integration_test_' . time() . '_' . wp_generate_password(4, false);
        $email = $username . '@test.local';

        self::$testUserId = wp_create_user($username, 'testpass123', $email);

        if (is_wp_error(self::$testUserId)) {
            throw new \RuntimeException('Failed to create test user: ' . self::$testUserId->get_error_message());
        }
    }

    /**
     * Clean up test user and posts after suite
     */
    public static function tearDownAfterClass(): void
    {
        // Delete test posts
        foreach (self::$testPosts as $postId) {
            wp_delete_post($postId, true);
        }
        self::$testPosts = [];

        if (self::$testUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$testUserId);
            self::$testUserId = null;
        }

        parent::tearDownAfterClass();
    }

    /**
     * Set the current user for testing
     */
    protected function actingAs(int $userId): void
    {
        wp_set_current_user($userId);
    }

    /**
     * Assert user meta value
     */
    protected function assertUserMeta(int $userId, string $key, mixed $expected, string $message = ''): void
    {
        $actual = get_user_meta($userId, $key, true);
        $this->assertEquals($expected, $actual, $message ?: "User meta '{$key}' does not match expected value");
    }

    /**
     * Assert user meta does not exist or is empty
     */
    protected function assertUserMetaEmpty(int $userId, string $key, string $message = ''): void
    {
        $actual = get_user_meta($userId, $key, true);
        $this->assertEmpty($actual, $message ?: "User meta '{$key}' should be empty");
    }

    /**
     * Clean up user meta after each test
     */
    protected function cleanupUserMeta(int $userId, array $keys): void
    {
        foreach ($keys as $key) {
            delete_user_meta($userId, $key);
        }
    }

    /**
     * Create a test edition
     *
     * Uses _ntdst_ prefix as defined in EditionCPT.
     */
    protected function createTestEdition(array $data = []): int
    {
        $defaults = [
            'post_title' => 'Test Edition ' . wp_generate_password(4, false),
            'post_type' => 'vad_edition',
            'post_status' => 'publish',
        ];

        $postData = array_merge($defaults, $data);
        $postId = wp_insert_post($postData);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create test edition: ' . $postId->get_error_message());
        }

        self::$testPosts[] = $postId;

        // Set default meta with _ntdst_ prefix (as per EditionCPT)
        $metaDefaults = [
            '_ntdst_status' => 'open',
            '_ntdst_capacity' => 20,
            '_ntdst_price' => 10000, // 100.00 EUR in cents
            '_ntdst_member_price' => 8000, // 80.00 EUR in cents
            '_ntdst_course_id' => 0,
        ];

        $meta = array_merge($metaDefaults, $data['meta'] ?? []);
        foreach ($meta as $key => $value) {
            update_post_meta($postId, $key, $value);
        }

        return $postId;
    }

    /**
     * Create a test course (LearnDash)
     */
    protected function createTestCourse(array $data = []): int
    {
        $defaults = [
            'post_title' => 'Test Course ' . wp_generate_password(4, false),
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
        ];

        $postData = array_merge($defaults, $data);
        $postId = wp_insert_post($postData);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create test course: ' . $postId->get_error_message());
        }

        self::$testPosts[] = $postId;

        return $postId;
    }

    /**
     * Create a test voucher
     *
     * Uses _ntdst_ prefix as defined in VoucherCPT.
     */
    protected function createTestVoucher(array $data = []): int
    {
        $code = $data['code'] ?? 'TEST' . strtoupper(wp_generate_password(6, false, false));

        $defaults = [
            'post_title' => $code,
            'post_type' => 'vad_voucher',
            'post_status' => 'publish',
        ];

        $postData = array_merge($defaults, $data);
        $postId = wp_insert_post($postData);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create test voucher: ' . $postId->get_error_message());
        }

        self::$testPosts[] = $postId;

        // Set default meta with _ntdst_ prefix (as per VoucherCPT)
        $metaDefaults = [
            '_ntdst_code' => $code,
            '_ntdst_discount_type' => 'full',
            '_ntdst_discount_value' => 0,
            '_ntdst_usage_limit' => 1,
            '_ntdst_used_count' => 0,
            '_ntdst_edition_id' => 0,
            '_ntdst_status' => 'active',
            '_ntdst_redemptions' => [],
        ];

        $meta = array_merge($metaDefaults, $data['meta'] ?? []);
        foreach ($meta as $key => $value) {
            // Don't manually serialize - update_post_meta handles serialization automatically
            update_post_meta($postId, $key, $value);
        }

        return $postId;
    }

    /**
     * Create a test quote
     *
     * Uses no prefix as defined in QuoteCPT (meta_prefix => '').
     */
    protected function createTestQuote(int $userId, int $editionId, array $data = []): int
    {
        $quoteNumber = 'Q' . date('Y') . '-' . str_pad((string) rand(1, 9999), 4, '0', STR_PAD_LEFT);

        $defaults = [
            'post_title' => 'Test Quote ' . $quoteNumber,
            'post_type' => 'vad_quote',
            'post_status' => 'publish',
        ];

        $postData = array_merge($defaults, $data);
        $postId = wp_insert_post($postData);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create test quote: ' . $postId->get_error_message());
        }

        self::$testPosts[] = $postId;

        // Set default meta with no prefix (as per QuoteCPT)
        $metaDefaults = [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'registration_id' => 0,
            'quote_number' => $quoteNumber,
            'status' => 'draft',
            'subtotal' => 10000,
            'discount' => 0,
            'tax' => 2100,
            'total' => 12100,
            'billing' => [],
            'items' => [],
        ];

        $meta = array_merge($metaDefaults, $data['meta'] ?? []);
        foreach ($meta as $key => $value) {
            // Don't manually serialize - update_post_meta handles serialization automatically
            update_post_meta($postId, $key, $value);
        }

        return $postId;
    }

    /**
     * Delete a registration from the custom table
     */
    protected function deleteTestRegistration(int $registrationId): void
    {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $registrationId]);
    }
}
