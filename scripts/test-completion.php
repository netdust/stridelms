<?php
/**
 * Stride LMS - Completion Service Tests
 *
 * Tests completion modes, progress tracking, and completion processing.
 *
 * Run with: ddev exec wp eval-file scripts/test-completion.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-completion.php\n";
    exit(1);
}

use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Edition\SessionService;
use Stride\Domain\CompletionMode;

class StrideCompletionServiceTest
{
    private CompletionService $completionService;
    private AttendanceService $attendanceService;
    private SessionService $sessionService;

    private array $created = [
        'user_ids' => [],
        'edition_ids' => [],
        'session_ids' => [],
        'course_ids' => [],
        'registration_ids' => [],
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->completionService = ntdst_get(CompletionService::class);
        $this->attendanceService = ntdst_get(AttendanceService::class);
        $this->sessionService = ntdst_get(SessionService::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Completion Service Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->setupTestData();
            $this->testCompletionModeAttendAll();
            $this->testCompletionModeAttendAllIncomplete();
            $this->testCompletionModePercentage();
            $this->testCompletionModeCount();
            $this->testProgressTracking();
        } catch (Exception $e) {
            echo "\n[FATAL] " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        } finally {
            $this->cleanup();
        }

        echo "\n=== Test Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo ($this->failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . "\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            echo "  [PASS] {$message}\n";
            $this->passed++;
        } else {
            echo "  [FAIL] {$message}\n";
            $this->failed++;
        }
    }

    private function setupTestData(): void
    {
        echo "0. Setting up test data...\n";

        // Create a LearnDash course
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Completion Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // Create edition for "attend all" tests
        $editionAttendAll = $this->createEdition($courseId, 'Attend All Edition');
        update_post_meta($editionAttendAll, '_vad_completion_mode', CompletionMode::AttendAll->value);
        $this->created['edition_ids']['attend_all'] = $editionAttendAll;

        // Create 4 sessions for attend_all edition
        for ($i = 0; $i < 4; $i++) {
            $sessionId = $this->createSession($editionAttendAll);
            $this->created['session_ids']['attend_all'][] = $sessionId;
        }

        // Create edition for "percentage" tests (75% threshold)
        $editionPercentage = $this->createEdition($courseId, 'Percentage Edition');
        update_post_meta($editionPercentage, '_vad_completion_mode', CompletionMode::Percentage->value);
        update_post_meta($editionPercentage, '_vad_completion_threshold', 75);
        $this->created['edition_ids']['percentage'] = $editionPercentage;

        // Create 4 sessions for percentage edition
        for ($i = 0; $i < 4; $i++) {
            $sessionId = $this->createSession($editionPercentage);
            $this->created['session_ids']['percentage'][] = $sessionId;
        }

        // Create edition for "count" tests (minimum 3 sessions)
        $editionCount = $this->createEdition($courseId, 'Count Edition');
        update_post_meta($editionCount, '_vad_completion_mode', CompletionMode::Count->value);
        update_post_meta($editionCount, '_vad_completion_threshold', 3);
        $this->created['edition_ids']['count'] = $editionCount;

        // Create 5 sessions for count edition
        for ($i = 0; $i < 5; $i++) {
            $sessionId = $this->createSession($editionCount);
            $this->created['session_ids']['count'][] = $sessionId;
        }

        echo "  - Created course and 3 editions with different completion modes\n";
        echo "    - Attend All: 4 sessions\n";
        echo "    - Percentage (75%): 4 sessions\n";
        echo "    - Count (min 3): 5 sessions\n\n";
    }

    private function createEdition(int $courseId, string $title): int
    {
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => $title . ' ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);

        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');

        return $editionId;
    }

    private function createSession(int $editionId): int
    {
        $sessionId = wp_insert_post([
            'post_type' => 'vad_session',
            'post_title' => 'Session ' . time() . '-' . rand(1000, 9999),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);

        update_post_meta($sessionId, 'edition_id', $editionId);
        update_post_meta($sessionId, 'date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($sessionId, 'start_time', '09:00');
        update_post_meta($sessionId, 'end_time', '11:00');
        update_post_meta($sessionId, 'type', 'in_person');

        return $sessionId;
    }

    // ========================================
    // Test 5.1: Completion Mode - Attend All (Complete)
    // ========================================

    private function testCompletionModeAttendAll(): void
    {
        echo "5.1. Testing Completion Mode - Attend All (Complete)...\n";

        $userId = $this->createTestUser('completion_all_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['attend_all'];

        $this->createMockRegistration($userId, $editionId);

        // Mark present for ALL 4 sessions
        foreach ($this->created['session_ids']['attend_all'] as $sessionId) {
            $this->attendanceService->markPresent($sessionId, $userId);
        }

        // Check completion
        $isComplete = $this->completionService->isComplete($editionId, $userId);

        $this->assert(
            $isComplete === true,
            "isComplete() returns true when all sessions attended"
        );

        // Verify mode
        $mode = $this->completionService->getCompletionMode($editionId);

        $this->assert(
            $mode === CompletionMode::AttendAll,
            "Completion mode is 'attend_all'"
        );

        echo "\n";
    }

    // ========================================
    // Test 5.2: Completion Mode - Attend All (Incomplete)
    // ========================================

    private function testCompletionModeAttendAllIncomplete(): void
    {
        echo "5.2. Testing Completion Mode - Attend All (Incomplete)...\n";

        $userId = $this->createTestUser('completion_incomplete_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['attend_all'];

        $this->createMockRegistration($userId, $editionId);

        // Mark present for only 3 of 4 sessions
        $sessions = $this->created['session_ids']['attend_all'];
        $this->attendanceService->markPresent($sessions[0], $userId);
        $this->attendanceService->markPresent($sessions[1], $userId);
        $this->attendanceService->markPresent($sessions[2], $userId);
        // Skip session 4

        // Check completion
        $isComplete = $this->completionService->isComplete($editionId, $userId);

        $this->assert(
            $isComplete === false,
            "isComplete() returns false when not all sessions attended (3/4)"
        );

        echo "\n";
    }

    // ========================================
    // Test 5.3: Completion Mode - Percentage
    // ========================================

    private function testCompletionModePercentage(): void
    {
        echo "5.3. Testing Completion Mode - Percentage (75% threshold)...\n";

        $userId = $this->createTestUser('completion_pct_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['percentage'];

        $this->createMockRegistration($userId, $editionId);

        // 4 sessions, 75% threshold = need 3 sessions
        $sessions = $this->created['session_ids']['percentage'];

        // Test with exactly 75% (3/4)
        $this->attendanceService->markPresent($sessions[0], $userId);
        $this->attendanceService->markPresent($sessions[1], $userId);
        $this->attendanceService->markPresent($sessions[2], $userId);

        $isComplete = $this->completionService->isComplete($editionId, $userId);

        $this->assert(
            $isComplete === true,
            "isComplete() returns true at 75% (3/4 sessions)"
        );

        // Verify threshold
        $threshold = $this->completionService->getCompletionThreshold($editionId);

        $this->assert(
            $threshold === 75,
            "Completion threshold is 75 (got: {$threshold})"
        );

        echo "\n";
    }

    // ========================================
    // Test 5.4: Completion Mode - Count
    // ========================================

    private function testCompletionModeCount(): void
    {
        echo "5.4. Testing Completion Mode - Count (min 3 sessions)...\n";

        $userId = $this->createTestUser('completion_count_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['count'];

        $this->createMockRegistration($userId, $editionId);

        // 5 sessions, minimum 3 required
        $sessions = $this->created['session_ids']['count'];

        // Attend exactly 3 sessions
        $this->attendanceService->markPresent($sessions[0], $userId);
        $this->attendanceService->markPresent($sessions[1], $userId);
        $this->attendanceService->markPresent($sessions[2], $userId);

        $isComplete = $this->completionService->isComplete($editionId, $userId);

        $this->assert(
            $isComplete === true,
            "isComplete() returns true at minimum count (3/5 sessions)"
        );

        // Verify mode
        $mode = $this->completionService->getCompletionMode($editionId);

        $this->assert(
            $mode === CompletionMode::Count,
            "Completion mode is 'count'"
        );

        echo "\n";
    }

    // ========================================
    // Test 5.5: Progress Tracking
    // ========================================

    private function testProgressTracking(): void
    {
        echo "5.5. Testing Progress Tracking...\n";

        $userId = $this->createTestUser('completion_progress_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['attend_all'];

        $this->createMockRegistration($userId, $editionId);

        // Attend 2 of 4 sessions
        $sessions = $this->created['session_ids']['attend_all'];
        $this->attendanceService->markPresent($sessions[0], $userId);
        $this->attendanceService->markPresent($sessions[1], $userId);

        // Get progress
        $progress = $this->completionService->getProgress($editionId, $userId);

        $this->assert(
            isset($progress['total_sessions']) && $progress['total_sessions'] === 4,
            "Progress shows total_sessions = 4 (got: " . ($progress['total_sessions'] ?? 'null') . ")"
        );

        $this->assert(
            isset($progress['attended']) && $progress['attended'] === 2,
            "Progress shows attended = 2 (got: " . ($progress['attended'] ?? 'null') . ")"
        );

        $this->assert(
            isset($progress['required']) && $progress['required'] === 4,
            "Progress shows required = 4 (attend_all mode, got: " . ($progress['required'] ?? 'null') . ")"
        );

        $this->assert(
            isset($progress['remaining']) && $progress['remaining'] === 2,
            "Progress shows remaining = 2 (got: " . ($progress['remaining'] ?? 'null') . ")"
        );

        $this->assert(
            isset($progress['percentage']) && abs($progress['percentage'] - 50.0) < 0.1,
            "Progress shows percentage = 50% (got: " . ($progress['percentage'] ?? 'null') . ")"
        );

        $this->assert(
            isset($progress['is_complete']) && $progress['is_complete'] === false,
            "Progress shows is_complete = false"
        );

        $this->assert(
            isset($progress['mode']) && $progress['mode'] === CompletionMode::AttendAll->value,
            "Progress shows mode = 'attend_all'"
        );

        echo "\n";
    }

    // ========================================
    // HELPERS
    // ========================================

    private function createTestUser(string $username): int
    {
        $email = $username . '@test.local';
        $userId = wp_create_user($username, 'testpass123', $email);

        if (!is_wp_error($userId)) {
            update_user_meta($userId, 'first_name', 'Test');
            update_user_meta($userId, 'last_name', 'User');
            update_user_meta($userId, '_stride_test_completion', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function createMockRegistration(int $userId, int $editionId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';

        $wpdb->insert($table, [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
        ]);

        $regId = (int) $wpdb->insert_id;
        $this->created['registration_ids'][] = $regId;

        return $regId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        global $wpdb;

        // Delete attendance records
        $attendanceTable = $wpdb->prefix . 'vad_attendance';
        foreach ($this->created['user_ids'] as $userId) {
            $wpdb->delete($attendanceTable, ['user_id' => $userId], ['%d']);
        }
        echo "  - Deleted attendance records\n";

        // Delete sessions
        $allSessions = [];
        foreach ($this->created['session_ids'] as $sessions) {
            $allSessions = array_merge($allSessions, $sessions);
        }
        foreach ($allSessions as $sessionId) {
            wp_delete_post($sessionId, true);
        }
        echo "  - Deleted " . count($allSessions) . " sessions\n";

        // Delete registrations
        $regTable = $wpdb->prefix . 'vad_registrations';
        foreach ($this->created['registration_ids'] as $regId) {
            $wpdb->delete($regTable, ['id' => $regId], ['%d']);
        }
        echo "  - Deleted " . count($this->created['registration_ids']) . " registrations\n";

        // Delete editions
        foreach ($this->created['edition_ids'] as $editionId) {
            wp_delete_post($editionId, true);
        }
        echo "  - Deleted " . count($this->created['edition_ids']) . " editions\n";

        // Delete courses
        foreach ($this->created['course_ids'] as $courseId) {
            wp_delete_post($courseId, true);
        }
        echo "  - Deleted " . count($this->created['course_ids']) . " courses\n";

        // Delete users
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->created['user_ids'] as $userId) {
            if ($userId) {
                wp_delete_user($userId);
            }
        }
        echo "  - Deleted " . count($this->created['user_ids']) . " users\n";

        echo "  Cleanup complete.\n";
    }
}

// Run the test
$test = new StrideCompletionServiceTest();
$test->run();
