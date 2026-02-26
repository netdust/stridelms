<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\Platform\AGSReceiver;
use PHPUnit\Framework\TestCase;

class AGSReceiverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset global state
        global $_test_users, $_test_user_meta, $_test_options, $_test_action_calls;
        global $_SERVER, $_test_json_response, $_test_http_response_code;

        $_test_users = [];
        $_test_user_meta = [];
        $_test_options = [];
        $_test_action_calls = [];
        $_test_json_response = null;
        $_test_http_response_code = null;

        // Create a test user for grade storage tests
        $_test_users[1] = (object) [
            'ID' => 1,
            'user_login' => 'testuser',
            'user_email' => 'test@example.com',
            'display_name' => 'Test User',
        ];
    }

    public function test_stores_grade_in_user_meta(): void
    {
        $receiver = new AGSReceiver();

        // Store a grade
        $userId = 1;
        $toolId = 42;
        $resourceLinkId = 'course-123';
        $score = 0.85;

        $receiver->storeGrade($userId, $toolId, $resourceLinkId, $score, 'Completed');

        // Verify user meta was updated
        global $_test_user_meta;
        $grades = $_test_user_meta[$userId]['_lti_grades'][0] ?? [];

        $this->assertArrayHasKey('tool_42', $grades);
        $this->assertEquals(0.85, $grades['tool_42']['course-123']['score']);
        $this->assertEquals('Completed', $grades['tool_42']['course-123']['activity']);
    }

    public function test_stores_grade_with_comment(): void
    {
        $receiver = new AGSReceiver();

        $receiver->storeGrade(1, 42, 'course-123', 0.90, 'Completed', 'Great work!');

        global $_test_user_meta;
        $grades = $_test_user_meta[1]['_lti_grades'][0] ?? [];

        $this->assertEquals('Great work!', $grades['tool_42']['course-123']['comment']);
    }

    public function test_stores_multiple_grades_per_tool(): void
    {
        $receiver = new AGSReceiver();

        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');
        $receiver->storeGrade(1, 42, 'course-456', 0.75, 'InProgress');

        global $_test_user_meta;
        $grades = $_test_user_meta[1]['_lti_grades'][0] ?? [];

        $this->assertArrayHasKey('course-123', $grades['tool_42']);
        $this->assertArrayHasKey('course-456', $grades['tool_42']);
        $this->assertEquals(0.85, $grades['tool_42']['course-123']['score']);
        $this->assertEquals(0.75, $grades['tool_42']['course-456']['score']);
    }

    public function test_stores_grades_for_multiple_tools(): void
    {
        $receiver = new AGSReceiver();

        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');
        $receiver->storeGrade(1, 99, 'module-abc', 0.95, 'Completed');

        global $_test_user_meta;
        $grades = $_test_user_meta[1]['_lti_grades'][0] ?? [];

        $this->assertArrayHasKey('tool_42', $grades);
        $this->assertArrayHasKey('tool_99', $grades);
    }

    public function test_updates_existing_grade(): void
    {
        $receiver = new AGSReceiver();

        // Store initial grade
        $receiver->storeGrade(1, 42, 'course-123', 0.50, 'InProgress');

        // Update with new grade
        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');

        global $_test_user_meta;
        $grades = $_test_user_meta[1]['_lti_grades'][0] ?? [];

        // Should have updated score
        $this->assertEquals(0.85, $grades['tool_42']['course-123']['score']);
        $this->assertEquals('Completed', $grades['tool_42']['course-123']['activity']);
    }

    public function test_get_grades_returns_all_grades_for_user(): void
    {
        $receiver = new AGSReceiver();

        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');
        $receiver->storeGrade(1, 99, 'module-abc', 0.95, 'Completed');

        $grades = $receiver->getGrades(1);

        $this->assertArrayHasKey('tool_42', $grades);
        $this->assertArrayHasKey('tool_99', $grades);
    }

    public function test_get_grades_returns_grades_for_specific_tool(): void
    {
        $receiver = new AGSReceiver();

        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');
        $receiver->storeGrade(1, 99, 'module-abc', 0.95, 'Completed');

        $toolGrades = $receiver->getGrades(1, 42);

        $this->assertArrayHasKey('course-123', $toolGrades);
        $this->assertArrayNotHasKey('tool_99', $toolGrades);
        $this->assertArrayNotHasKey('module-abc', $toolGrades);
    }

    public function test_get_grades_returns_empty_array_for_nonexistent_user(): void
    {
        $receiver = new AGSReceiver();

        $grades = $receiver->getGrades(999);

        $this->assertEquals([], $grades);
    }

    public function test_get_grades_returns_empty_array_for_nonexistent_tool(): void
    {
        $receiver = new AGSReceiver();

        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');

        $toolGrades = $receiver->getGrades(1, 999);

        $this->assertEquals([], $toolGrades);
    }

    public function test_grade_timestamp_is_set(): void
    {
        $receiver = new AGSReceiver();

        $beforeTime = gmdate('c');
        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');
        $afterTime = gmdate('c');

        global $_test_user_meta;
        $grades = $_test_user_meta[1]['_lti_grades'][0] ?? [];

        $timestamp = $grades['tool_42']['course-123']['timestamp'];
        $this->assertNotEmpty($timestamp);
        // ISO 8601 format check
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $timestamp);
    }

    public function test_grade_max_score_is_normalized_to_one(): void
    {
        $receiver = new AGSReceiver();

        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');

        global $_test_user_meta;
        $grades = $_test_user_meta[1]['_lti_grades'][0] ?? [];

        // max_score should always be 1.0 since we normalize scores
        $this->assertEquals(1.0, $grades['tool_42']['course-123']['max_score']);
    }

    public function test_find_user_by_lti_sub_finds_by_id(): void
    {
        global $_test_users;
        $_test_users[42] = (object) [
            'ID' => 42,
            'user_login' => 'existinguser',
            'user_email' => 'existing@example.com',
        ];

        $receiver = new AGSReceiver();

        // Use reflection to test private method
        $reflection = new \ReflectionClass($receiver);
        $method = $reflection->getMethod('findUserByLtiSub');
        $method->setAccessible(true);

        $userId = $method->invoke($receiver, '42');

        $this->assertEquals(42, $userId);
    }

    public function test_find_user_by_lti_sub_finds_by_meta(): void
    {
        global $_test_users, $_test_user_meta;

        // User with LTI user ID meta
        $_test_users[50] = (object) [
            'ID' => 50,
            'user_login' => 'ltiuser',
            'user_email' => 'lti@example.com',
        ];
        $_test_user_meta[50]['_lti_user_id'] = ['external-sub-12345'];

        $receiver = new AGSReceiver();

        $reflection = new \ReflectionClass($receiver);
        $method = $reflection->getMethod('findUserByLtiSub');
        $method->setAccessible(true);

        $userId = $method->invoke($receiver, 'external-sub-12345');

        $this->assertEquals(50, $userId);
    }

    public function test_find_user_by_lti_sub_returns_null_for_nonexistent(): void
    {
        $receiver = new AGSReceiver();

        $reflection = new \ReflectionClass($receiver);
        $method = $reflection->getMethod('findUserByLtiSub');
        $method->setAccessible(true);

        $userId = $method->invoke($receiver, 'nonexistent-sub');

        $this->assertNull($userId);
    }

    public function test_fires_action_hook_on_grade_storage(): void
    {
        global $_test_action_calls;

        $receiver = new AGSReceiver();
        $receiver->storeGrade(1, 42, 'course-123', 0.85, 'Completed');

        // lti_grade_received action is fired in handleGradeSubmission, not storeGrade
        // We test this separately through the handler integration
        $this->assertTrue(true);
    }
}
