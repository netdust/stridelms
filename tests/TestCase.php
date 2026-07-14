<?php

namespace Stride\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base Test Case for Stride Tests
 *
 * Provides common functionality for all tests including:
 * - Global state reset between tests
 * - Test data factories
 * - Assertion helpers for WordPress hooks
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Set up before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetGlobalState();
    }

    /**
     * Tear down after each test
     */
    protected function tearDown(): void
    {
        $this->resetGlobalState();
        parent::tearDown();
    }

    /**
     * Reset all global test state
     */
    protected function resetGlobalState(): void
    {
        global $_test_user_meta, $_test_post_meta, $_test_actions, $_test_filters;
        global $_test_action_calls, $_test_options, $_test_transients, $_test_container;
        global $_test_users, $_test_posts, $_test_data_manager_meta;
        global $_test_abilities, $_test_ability_categories, $_test_log_entries;
        global $_test_new_user_notification_calls;
        global $_test_rest_routes, $_test_rest_route_calls;
        global $_test_did_action_counts, $_test_doing_it_wrong_calls;
        global $_test_current_user_id;

        // Back to the stub default (wordpress-stubs.php seeds user 1): a test
        // that logs in as another user via $_test_current_user_id must not
        // leak that session into later test classes (order-dependent flakes:
        // get_current_user_id() disagreeing with an emptied $_test_users map).
        $_test_current_user_id = 1;

        $_test_user_meta = [];
        $_test_post_meta = [];
        $_test_actions = [];
        $_test_filters = [];
        $_test_action_calls = [];
        $_test_options = [];
        $_test_transients = [];
        $_test_container = [];
        $_test_users = [];
        $_test_posts = [];
        $_test_data_manager_meta = [];
        $_test_abilities = [];
        $_test_ability_categories = [];
        $_test_log_entries = [];
        $_test_new_user_notification_calls = 0;
        $_test_rest_routes = [];
        $_test_rest_route_calls = [];
        $_test_did_action_counts = [];
        $_test_doing_it_wrong_calls = [];
    }

    /**
     * Create a test user
     *
     * @param array $data User data overrides
     * @return object User object
     */
    protected function createUser(array $data = []): object
    {
        global $_test_users;

        static $nextId = 1;

        $defaults = [
            'ID' => $nextId++,
            'user_login' => 'testuser' . $nextId,
            'user_email' => "testuser{$nextId}@example.com",
            'first_name' => 'Test',
            'last_name' => 'User',
            'display_name' => 'Test User',
        ];

        $userData = array_merge($defaults, $data);
        $user = (object) $userData;

        $_test_users[$user->ID] = $user;

        return $user;
    }

    /**
     * Create a test course (LearnDash)
     *
     * @param array $data Course data overrides
     * @return object Post object
     */
    protected function createCourse(array $data = []): object
    {
        global $_test_posts;

        static $nextId = 1000;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'sfwd-courses',
            'post_title' => 'Test Course',
            'post_status' => 'publish',
        ];

        $courseData = array_merge($defaults, $data);
        $course = (object) $courseData;

        $_test_posts[$course->ID] = $course;

        return $course;
    }

    /**
     * Create a test group (LearnDash trajectory)
     *
     * @param array $data Group data overrides
     * @return object Post object
     */
    protected function createGroup(array $data = []): object
    {
        global $_test_posts;

        static $nextId = 2000;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'groups',
            'post_title' => 'Test Trajectory',
            'post_status' => 'publish',
        ];

        $groupData = array_merge($defaults, $data);
        $group = (object) $groupData;

        $_test_posts[$group->ID] = $group;

        return $group;
    }

    /**
     * Create a test quote
     *
     * @param array $data Quote data overrides
     * @return object Post object
     */
    protected function createQuote(array $data = []): object
    {
        global $_test_posts;

        static $nextId = 3000;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'vad_quote',
            'post_title' => 'Quote-' . date('Ymd') . '-001',
            'post_status' => 'publish',
        ];

        $quoteData = array_merge($defaults, $data);
        $quote = (object) $quoteData;

        $_test_posts[$quote->ID] = $quote;

        return $quote;
    }

    /**
     * Assert that a WordPress action was fired
     *
     * @param string $hook Hook name
     * @param int|null $times Expected number of calls (null = at least once)
     */
    protected function assertActionFired(string $hook, ?int $times = null): void
    {
        global $_test_action_calls;

        $calls = $_test_action_calls[$hook] ?? [];
        $count = count($calls);

        if ($times === null) {
            $this->assertGreaterThan(0, $count, "Action '{$hook}' was not fired.");
        } else {
            $this->assertEquals($times, $count, "Action '{$hook}' was fired {$count} times, expected {$times}.");
        }
    }

    /**
     * Assert that a WordPress action was not fired
     *
     * @param string $hook Hook name
     */
    protected function assertActionNotFired(string $hook): void
    {
        global $_test_action_calls;

        $calls = $_test_action_calls[$hook] ?? [];
        $this->assertCount(0, $calls, "Action '{$hook}' should not have been fired.");
    }

    /**
     * Get arguments from action calls
     *
     * @param string $hook Hook name
     * @param int $callIndex Which call to get (0-indexed)
     * @return array|null
     */
    protected function getActionArgs(string $hook, int $callIndex = 0): ?array
    {
        global $_test_action_calls;

        return $_test_action_calls[$hook][$callIndex] ?? null;
    }

    /**
     * Assert user meta equals expected value
     *
     * @param int $userId
     * @param string $key
     * @param mixed $expected
     */
    protected function assertUserMeta(int $userId, string $key, $expected): void
    {
        $actual = get_user_meta($userId, $key, true);
        $this->assertEquals($expected, $actual, "User meta '{$key}' for user {$userId} does not match expected value.");
    }

    /**
     * Assert post meta equals expected value
     *
     * @param int $postId
     * @param string $key
     * @param mixed $expected
     */
    protected function assertPostMeta(int $postId, string $key, $expected): void
    {
        $actual = get_post_meta($postId, $key, true);
        $this->assertEquals($expected, $actual, "Post meta '{$key}' for post {$postId} does not match expected value.");
    }

    /**
     * Register a service in the test container
     *
     * @param string $key
     * @param object $service
     */
    protected function registerService(string $key, object $service): void
    {
        global $_test_container;
        $_test_container[$key] = $service;
    }

    /**
     * Set Data Manager meta for a post type
     *
     * @param string $postType
     * @param int $postId
     * @param array $meta
     */
    protected function setDataManagerMeta(string $postType, int $postId, array $meta): void
    {
        global $_test_data_manager_meta;
        if (!isset($_test_data_manager_meta[$postType])) {
            $_test_data_manager_meta[$postType] = [];
        }
        if (!isset($_test_data_manager_meta[$postType][$postId])) {
            $_test_data_manager_meta[$postType][$postId] = [];
        }
        $_test_data_manager_meta[$postType][$postId] = array_merge(
            $_test_data_manager_meta[$postType][$postId],
            $meta,
        );
    }

    /**
     * Get Data Manager meta for a post type
     *
     * @param string $postType
     * @param int $postId
     * @param string $key
     * @return mixed
     */
    protected function getDataManagerMeta(string $postType, int $postId, string $key): mixed
    {
        global $_test_data_manager_meta;
        return $_test_data_manager_meta[$postType][$postId][$key] ?? null;
    }

    /**
     * Create a test edition
     *
     * @param array $data Edition data overrides
     * @return object Post object
     */
    protected function createEdition(array $data = []): object
    {
        global $_test_posts;

        static $nextId = 4000;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'vad_edition',
            'post_title' => 'Test Edition',
            'post_status' => 'publish',
        ];

        $editionData = array_merge($defaults, $data);
        $edition = (object) $editionData;

        $_test_posts[$edition->ID] = $edition;

        return $edition;
    }

    /**
     * Create a test session
     *
     * @param array $data Session data overrides
     * @return object Post object
     */
    protected function createSession(array $data = []): object
    {
        global $_test_posts;

        static $nextId = 5000;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'vad_session',
            'post_title' => 'Test Session',
            'post_status' => 'publish',
        ];

        $sessionData = array_merge($defaults, $data);
        $session = (object) $sessionData;

        $_test_posts[$session->ID] = $session;

        return $session;
    }

    /**
     * Create a test voucher
     *
     * @param array $data Voucher data overrides
     * @return object Post object
     */
    protected function createVoucher(array $data = []): object
    {
        global $_test_posts;

        static $nextId = 6000;

        $defaults = [
            'ID' => $nextId++,
            'post_type' => 'vad_voucher',
            'post_title' => 'Test Voucher',
            'post_status' => 'publish',
        ];

        $voucherData = array_merge($defaults, $data);
        $voucher = (object) $voucherData;

        $_test_posts[$voucher->ID] = $voucher;

        return $voucher;
    }
}
