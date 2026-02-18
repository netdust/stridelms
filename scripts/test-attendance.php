<?php
/**
 * Stride LMS - Attendance Service Tests
 *
 * Tests attendance marking, status changes, and hours calculations.
 *
 * Run with: ddev exec wp eval-file scripts/test-attendance.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-attendance.php\n";
    exit(1);
}

use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Edition\SessionService;
use Stride\Domain\AttendanceStatus;

class StrideAttendanceServiceTest
{
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
        $this->attendanceService = ntdst_get(AttendanceService::class);
        $this->sessionService = ntdst_get(SessionService::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Attendance Service Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->setupTestData();
            $this->testMarkPresent();
            $this->testMarkAbsent();
            $this->testMarkExcused();
            $this->testToggleAttendanceStatus();
            $this->testHoursCalculation();
            $this->testAttendanceRate();
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
            'post_title' => 'Attendance Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // Create an edition
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Attendance Test Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        $this->created['edition_ids'][] = $editionId;

        // Create sessions with different durations
        // Session 1: 2 hours (09:00 - 11:00)
        $session1Id = $this->createSession($editionId, '09:00', '11:00');
        $this->created['session_ids'][] = $session1Id;

        // Session 2: 3 hours (13:00 - 16:00)
        $session2Id = $this->createSession($editionId, '13:00', '16:00');
        $this->created['session_ids'][] = $session2Id;

        // Session 3: 2 hours (09:00 - 11:00)
        $session3Id = $this->createSession($editionId, '09:00', '11:00');
        $this->created['session_ids'][] = $session3Id;

        // Session 4: 1 hour (14:00 - 15:00) - for rate calculation
        $session4Id = $this->createSession($editionId, '14:00', '15:00');
        $this->created['session_ids'][] = $session4Id;

        echo "  - Created course, edition, and 4 sessions (2h, 3h, 2h, 1h = 8h total)\n\n";
    }

    private function createSession(int $editionId, string $startTime, string $endTime): int
    {
        $sessionId = wp_insert_post([
            'post_type' => 'vad_session',
            'post_title' => 'Session ' . time() . '-' . rand(1000, 9999),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);

        update_post_meta($sessionId, 'edition_id', $editionId);
        update_post_meta($sessionId, 'date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($sessionId, 'start_time', $startTime);
        update_post_meta($sessionId, 'end_time', $endTime);
        update_post_meta($sessionId, 'type', 'in_person');

        return $sessionId;
    }

    // ========================================
    // Test 4.1: Mark Present
    // ========================================

    private function testMarkPresent(): void
    {
        echo "4.1. Testing Mark Present...\n";

        $userId = $this->createTestUser('attendance_present_' . time());
        $this->created['user_ids'][] = $userId;
        $sessionId = $this->created['session_ids'][0];

        // Enroll user first (create registration)
        $this->createMockRegistration($userId, $this->created['edition_ids'][0]);

        // Mark present
        $result = $this->attendanceService->markPresent($sessionId, $userId);

        $this->assert(
            !is_wp_error($result),
            "markPresent() returns attendance ID"
        );

        // Verify status
        $status = $this->attendanceService->getStatus($sessionId, $userId);

        $this->assert(
            $status === AttendanceStatus::Present,
            "Attendance status is 'present'"
        );

        // Verify isPresent
        $isPresent = $this->attendanceService->isPresent($sessionId, $userId);

        $this->assert(
            $isPresent === true,
            "isPresent() returns true"
        );

        echo "\n";
    }

    // ========================================
    // Test 4.2: Mark Absent
    // ========================================

    private function testMarkAbsent(): void
    {
        echo "4.2. Testing Mark Absent...\n";

        $userId = $this->createTestUser('attendance_absent_' . time());
        $this->created['user_ids'][] = $userId;
        $sessionId = $this->created['session_ids'][1];

        $this->createMockRegistration($userId, $this->created['edition_ids'][0]);

        // Mark absent
        $result = $this->attendanceService->markAbsent($sessionId, $userId);

        $this->assert(
            !is_wp_error($result),
            "markAbsent() returns attendance ID"
        );

        // Verify status
        $status = $this->attendanceService->getStatus($sessionId, $userId);

        $this->assert(
            $status === AttendanceStatus::Absent,
            "Attendance status is 'absent'"
        );

        // Verify isPresent returns false
        $isPresent = $this->attendanceService->isPresent($sessionId, $userId);

        $this->assert(
            $isPresent === false,
            "isPresent() returns false for absent"
        );

        echo "\n";
    }

    // ========================================
    // Test 4.3: Mark Excused
    // ========================================

    private function testMarkExcused(): void
    {
        echo "4.3. Testing Mark Excused...\n";

        $userId = $this->createTestUser('attendance_excused_' . time());
        $this->created['user_ids'][] = $userId;
        $sessionId = $this->created['session_ids'][2];

        $this->createMockRegistration($userId, $this->created['edition_ids'][0]);

        // Mark excused
        $result = $this->attendanceService->markExcused($sessionId, $userId);

        $this->assert(
            !is_wp_error($result),
            "markExcused() returns attendance ID"
        );

        // Verify status
        $status = $this->attendanceService->getStatus($sessionId, $userId);

        $this->assert(
            $status === AttendanceStatus::Excused,
            "Attendance status is 'excused'"
        );

        echo "\n";
    }

    // ========================================
    // Test 4.4: Toggle Attendance Status (Upsert Behavior)
    // ========================================

    private function testToggleAttendanceStatus(): void
    {
        echo "4.4. Testing Toggle Attendance Status (Upsert Behavior)...\n";

        $userId = $this->createTestUser('attendance_toggle_' . time());
        $this->created['user_ids'][] = $userId;
        $sessionId = $this->created['session_ids'][3];
        $editionId = $this->created['edition_ids'][0];

        $this->createMockRegistration($userId, $editionId);

        // Mark present first
        $result1 = $this->attendanceService->markPresent($sessionId, $userId);
        $this->assert(!is_wp_error($result1), "First mark as present succeeds");

        // Change to absent
        $result2 = $this->attendanceService->markAbsent($sessionId, $userId);
        $this->assert(!is_wp_error($result2), "Second mark as absent succeeds");

        // Change back to present
        $result3 = $this->attendanceService->markPresent($sessionId, $userId);
        $this->assert(!is_wp_error($result3), "Third mark as present succeeds");

        // Verify final status is present
        $finalStatus = $this->attendanceService->getStatus($sessionId, $userId);

        $this->assert(
            $finalStatus === AttendanceStatus::Present,
            "Final status is 'present'"
        );

        // Verify only ONE record exists (upsert behavior)
        $attendance = $this->attendanceService->getUserEditionAttendance($userId, $editionId);
        $sessionRecords = array_filter($attendance, fn($a) => $a['session_id'] === $sessionId);

        $this->assert(
            count($sessionRecords) === 1,
            "Only ONE attendance record exists (upsert behavior, got: " . count($sessionRecords) . ")"
        );

        echo "\n";
    }

    // ========================================
    // Test 4.5: Hours Calculation
    // ========================================

    private function testHoursCalculation(): void
    {
        echo "4.5. Testing Hours Calculation...\n";

        $userId = $this->createTestUser('attendance_hours_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids'][0];

        $this->createMockRegistration($userId, $editionId);

        // Sessions: [0]=2h, [1]=3h, [2]=2h, [3]=1h = 8h total
        // Mark present for sessions 0 (2h) and 2 (2h) = 4h attended
        $this->attendanceService->markPresent($this->created['session_ids'][0], $userId);
        $this->attendanceService->markPresent($this->created['session_ids'][2], $userId);

        // Get hours attended
        $hoursAttended = $this->attendanceService->getHoursAttended($userId, $editionId);

        $this->assert(
            abs($hoursAttended - 4.0) < 0.01,
            "Hours attended is 4.0 (got: {$hoursAttended})"
        );

        echo "\n";
    }

    // ========================================
    // Test 4.6: Attendance Rate
    // ========================================

    private function testAttendanceRate(): void
    {
        echo "4.6. Testing Attendance Rate...\n";

        $userId = $this->createTestUser('attendance_rate_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids'][0];

        $this->createMockRegistration($userId, $editionId);

        // Edition has 4 sessions
        // Mark present for 3, absent for 1 = 75% rate
        $this->attendanceService->markPresent($this->created['session_ids'][0], $userId);
        $this->attendanceService->markPresent($this->created['session_ids'][1], $userId);
        $this->attendanceService->markPresent($this->created['session_ids'][2], $userId);
        $this->attendanceService->markAbsent($this->created['session_ids'][3], $userId);

        // Get attendance rate
        $rate = $this->attendanceService->getAttendanceRate($userId, $editionId);

        $this->assert(
            abs($rate - 75.0) < 0.01,
            "Attendance rate is 75% (got: {$rate}%)"
        );

        // Also test countAttended
        $attended = $this->attendanceService->countAttended($userId, $editionId);

        $this->assert(
            $attended === 3,
            "countAttended returns 3 (got: {$attended})"
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
            update_user_meta($userId, '_stride_test_attendance', true);
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

        // Delete attendance records
        global $wpdb;
        $attendanceTable = $wpdb->prefix . 'vad_attendance';
        foreach ($this->created['user_ids'] as $userId) {
            $wpdb->delete($attendanceTable, ['user_id' => $userId], ['%d']);
        }
        echo "  - Deleted attendance records\n";

        // Delete sessions
        foreach ($this->created['session_ids'] as $sessionId) {
            wp_delete_post($sessionId, true);
        }
        echo "  - Deleted " . count($this->created['session_ids']) . " sessions\n";

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
$test = new StrideAttendanceServiceTest();
$test->run();
