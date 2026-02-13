<?php
/**
 * Stride LMS - Session & Attendance Tests
 *
 * Tests session CRUD, attendance marking, and hours calculation.
 *
 * Run with: ddev exec wp eval-file scripts/test-session.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-session.php\n";
    exit(1);
}

use ntdst\Stride\core\SessionService;
use ntdst\Stride\core\EditionService;
use ntdst\Stride\core\RegistrationRepository;
use ntdst\Stride\FieldRegistry;

class StrideSessionTest
{
    private SessionService $sessionService;
    private EditionService $editionService;
    private RegistrationRepository $registrationRepo;

    private array $created = [
        'course_ids' => [],
        'edition_ids' => [],
        'session_ids' => [],
        'user_ids' => [],
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->sessionService = ntdst_get(SessionService::class);
        $this->editionService = ntdst_get(EditionService::class);
        $this->registrationRepo = ntdst_get(RegistrationRepository::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Session & Attendance Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->testSessionCrud();
            $this->testSessionQueries();
            $this->testAttendanceMarking();
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

    private function skip(string $message): void
    {
        echo "  [SKIP] {$message}\n";
        $this->passed++;
    }

    // ========================================
    // A. SESSION CRUD (8 tests)
    // ========================================

    private function testSessionCrud(): void
    {
        echo "A. Testing Session CRUD Operations...\n";

        // Create test course and edition
        $courseId = $this->createTestCourse('Session CRUD Test');
        $this->created['course_ids'][] = $courseId;

        $editionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $editionId;

        // A1. Create session with minimal data
        $sessionId = $this->sessionService->createSession([
            FieldRegistry::SESSION_EDITION_ID => $editionId,
            FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+30 days')),
        ]);
        $this->assert(
            !is_wp_error($sessionId) && $sessionId > 0,
            "A1. Create session with minimal data"
        );

        if (!is_wp_error($sessionId)) {
            $this->created['session_ids'][] = $sessionId;
        }

        // A2. Create session with full data
        $fullSessionId = $this->sessionService->createSession([
            FieldRegistry::SESSION_EDITION_ID => $editionId,
            FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+31 days')),
            FieldRegistry::SESSION_START_TIME => '09:00',
            FieldRegistry::SESSION_END_TIME => '17:00',
            FieldRegistry::SESSION_LOCATION => 'Room 101',
        ]);
        $this->assert(
            !is_wp_error($fullSessionId) && $fullSessionId > 0,
            "A2. Create session with full data"
        );

        if (!is_wp_error($fullSessionId)) {
            $this->created['session_ids'][] = $fullSessionId;
        }

        // A3. Get session
        $session = $this->sessionService->getSession($fullSessionId);
        $this->assert(
            $session !== null && isset($session['id']),
            "A3. Get session returns data"
        );

        // A4. Verify session fields
        $this->assert(
            $session['edition_id'] === $editionId &&
            $session['start_time'] === '09:00' &&
            $session['end_time'] === '17:00' &&
            $session['location'] === 'Room 101',
            "A4. Session fields stored correctly"
        );

        // A5. Update session
        $updateResult = $this->sessionService->updateSession($fullSessionId, [
            FieldRegistry::SESSION_LOCATION => 'Room 202',
            FieldRegistry::SESSION_START_TIME => '10:00',
        ]);
        $this->assert(!is_wp_error($updateResult), "A5. Update session succeeds");

        // A6. Verify update
        $updatedSession = $this->sessionService->getSession($fullSessionId);
        $this->assert(
            $updatedSession['location'] === 'Room 202' && $updatedSession['start_time'] === '10:00',
            "A6. Session update persisted"
        );

        // A7. Get non-existent session returns null
        $nullSession = $this->sessionService->getSession(999999);
        $this->assert($nullSession === null, "A7. Get non-existent session returns null");

        // A8. Validation rejects invalid time range
        $invalidResult = $this->sessionService->createSession([
            FieldRegistry::SESSION_EDITION_ID => $editionId,
            FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+32 days')),
            FieldRegistry::SESSION_START_TIME => '17:00',
            FieldRegistry::SESSION_END_TIME => '09:00', // End before start
        ]);
        $this->assert(
            is_wp_error($invalidResult) && $invalidResult->get_error_code() === 'invalid_time_range',
            "A8. Validation rejects end time before start time"
        );

        echo "\n";
    }

    // ========================================
    // B. SESSION QUERIES (6 tests)
    // ========================================

    private function testSessionQueries(): void
    {
        echo "B. Testing Session Queries...\n";

        $courseId = $this->createTestCourse('Session Query Test');
        $this->created['course_ids'][] = $courseId;

        $editionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $editionId;

        // Create 3 sessions
        for ($i = 0; $i < 3; $i++) {
            $sessionId = $this->sessionService->createSession([
                FieldRegistry::SESSION_EDITION_ID => $editionId,
                FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+' . (30 + $i) . ' days')),
                FieldRegistry::SESSION_START_TIME => '09:00',
                FieldRegistry::SESSION_END_TIME => '13:00',
            ]);
            if (!is_wp_error($sessionId)) {
                $this->created['session_ids'][] = $sessionId;
            }
        }

        // B1. Get sessions for edition
        $sessions = $this->sessionService->getSessionsForEdition($editionId);
        $this->assert(
            is_array($sessions) && count($sessions) === 3,
            "B1. Get sessions for edition returns 3 sessions"
        );

        // B2. Sessions are sorted by date
        $dates = array_column($sessions, 'date');
        $sortedDates = $dates;
        sort($sortedDates);
        $this->assert(
            $dates === $sortedDates,
            "B2. Sessions sorted by date ascending"
        );

        // B3. Get session count
        $count = $this->sessionService->getSessionCount($editionId);
        $this->assert($count === 3, "B3. Session count is 3");

        // B4. Get day count
        $dayCount = $this->sessionService->getDayCount($editionId);
        $this->assert($dayCount === 3, "B4. Day count is 3 (unique dates)");

        // B5. Sessions for non-existent edition
        $emptySessions = $this->sessionService->getSessionsForEdition(999999);
        $this->assert(
            is_array($emptySessions) && count($emptySessions) === 0,
            "B5. Sessions for non-existent edition returns empty array"
        );

        // B6. Create edition with multiple sessions on same day
        $sameDayEditionId = $this->createTestEdition($courseId, '+60 days');
        $this->created['edition_ids'][] = $sameDayEditionId;

        $sameDay = date('Y-m-d', strtotime('+60 days'));
        for ($i = 0; $i < 2; $i++) {
            $sessionId = $this->sessionService->createSession([
                FieldRegistry::SESSION_EDITION_ID => $sameDayEditionId,
                FieldRegistry::SESSION_DATE => $sameDay,
                FieldRegistry::SESSION_START_TIME => sprintf('%02d:00', 9 + ($i * 4)),
                FieldRegistry::SESSION_END_TIME => sprintf('%02d:00', 13 + ($i * 4)),
            ]);
            if (!is_wp_error($sessionId)) {
                $this->created['session_ids'][] = $sessionId;
            }
        }

        $sessionCount = $this->sessionService->getSessionCount($sameDayEditionId);
        $dayCount = $this->sessionService->getDayCount($sameDayEditionId);
        $this->assert(
            $sessionCount === 2 && $dayCount === 1,
            "B6. Multiple sessions on same day: sessions=2, days=1"
        );

        echo "\n";
    }

    // ========================================
    // C. ATTENDANCE MARKING (8 tests)
    // ========================================

    private function testAttendanceMarking(): void
    {
        echo "C. Testing Attendance Marking...\n";

        $courseId = $this->createTestCourse('Attendance Test');
        $this->created['course_ids'][] = $courseId;

        $editionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $editionId;

        $sessionId = $this->sessionService->createSession([
            FieldRegistry::SESSION_EDITION_ID => $editionId,
            FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::SESSION_START_TIME => '09:00',
            FieldRegistry::SESSION_END_TIME => '17:00',
        ]);
        $this->created['session_ids'][] = $sessionId;

        $userId = $this->createTestUser('attendance_test_' . time());
        $this->created['user_ids'][] = $userId;

        $userId2 = $this->createTestUser('attendance_test2_' . time());
        $this->created['user_ids'][] = $userId2;

        // C1. User not present initially
        $isPresent = $this->sessionService->isPresent($sessionId, $userId);
        $this->assert(!$isPresent, "C1. User not present initially");

        // C2. Get attendees returns empty array
        $attendees = $this->sessionService->getAttendees($sessionId);
        $this->assert(
            is_array($attendees) && count($attendees) === 0,
            "C2. Attendees list empty initially"
        );

        // C3. Mark user as present
        $result = $this->sessionService->markPresent($sessionId, $userId);
        $this->assert(!is_wp_error($result), "C3. Mark present succeeds");

        // C4. User now present
        $isPresent = $this->sessionService->isPresent($sessionId, $userId);
        $this->assert($isPresent, "C4. User is present after marking");

        // C5. Attendees list includes user
        $attendees = $this->sessionService->getAttendees($sessionId);
        $this->assert(
            in_array($userId, $attendees, true),
            "C5. Attendees list includes user"
        );

        // C6. Mark second user as present
        $this->sessionService->markPresent($sessionId, $userId2);
        $attendees = $this->sessionService->getAttendees($sessionId);
        $this->assert(
            count($attendees) === 2,
            "C6. Two users in attendees list"
        );

        // C7. Mark user as absent
        $result = $this->sessionService->markAbsent($sessionId, $userId);
        $this->assert(!is_wp_error($result), "C7. Mark absent succeeds");

        // C8. User no longer present
        $isPresent = $this->sessionService->isPresent($sessionId, $userId);
        $attendees = $this->sessionService->getAttendees($sessionId);
        $this->assert(
            !$isPresent && count($attendees) === 1 && !in_array($userId, $attendees, true),
            "C8. User removed from attendees after marking absent"
        );

        echo "\n";
    }

    // ========================================
    // D. HOURS CALCULATION (8 tests)
    // ========================================

    private function testHoursCalculation(): void
    {
        echo "D. Testing Hours Calculation...\n";

        $courseId = $this->createTestCourse('Hours Test');
        $this->created['course_ids'][] = $courseId;

        $editionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $editionId;

        // D1. Session duration calculation (8 hours)
        $sessionId1 = $this->sessionService->createSession([
            FieldRegistry::SESSION_EDITION_ID => $editionId,
            FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+30 days')),
            FieldRegistry::SESSION_START_TIME => '09:00',
            FieldRegistry::SESSION_END_TIME => '17:00',
        ]);
        $this->created['session_ids'][] = $sessionId1;

        $duration = $this->sessionService->getSessionDuration($sessionId1);
        $this->assert(
            abs($duration - 8.0) < 0.01,
            "D1. Session duration is 8 hours (09:00-17:00)"
        );

        // D2. Session duration - half day (4 hours)
        $sessionId2 = $this->sessionService->createSession([
            FieldRegistry::SESSION_EDITION_ID => $editionId,
            FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+31 days')),
            FieldRegistry::SESSION_START_TIME => '09:00',
            FieldRegistry::SESSION_END_TIME => '13:00',
        ]);
        $this->created['session_ids'][] = $sessionId2;

        $duration = $this->sessionService->getSessionDuration($sessionId2);
        $this->assert(
            abs($duration - 4.0) < 0.01,
            "D2. Session duration is 4 hours (09:00-13:00)"
        );

        // D3. Session with no times returns 0
        $sessionId3 = $this->sessionService->createSession([
            FieldRegistry::SESSION_EDITION_ID => $editionId,
            FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+32 days')),
        ]);
        $this->created['session_ids'][] = $sessionId3;

        $duration = $this->sessionService->getSessionDuration($sessionId3);
        $this->assert(
            $duration === 0.0,
            "D3. Session with no times returns 0 hours"
        );

        // D4. Total hours for edition (12 hours: 8 + 4 + 0)
        $totalHours = $this->sessionService->getTotalHours($editionId);
        $this->assert(
            abs($totalHours - 12.0) < 0.01,
            "D4. Total hours for edition is 12 (8+4+0)"
        );

        // D5. User with no attendance has 0 hours
        $userId = $this->createTestUser('hours_test_' . time());
        $this->created['user_ids'][] = $userId;

        $hoursAttended = $this->sessionService->getHoursAttended($userId, $editionId);
        $this->assert(
            $hoursAttended === 0.0,
            "D5. User with no attendance has 0 hours"
        );

        // D6. User attended one session (8 hours)
        $this->sessionService->markPresent($sessionId1, $userId);
        $hoursAttended = $this->sessionService->getHoursAttended($userId, $editionId);
        $this->assert(
            abs($hoursAttended - 8.0) < 0.01,
            "D6. User attended 8-hour session = 8 hours"
        );

        // D7. User attended two sessions (12 hours)
        $this->sessionService->markPresent($sessionId2, $userId);
        $hoursAttended = $this->sessionService->getHoursAttended($userId, $editionId);
        $this->assert(
            abs($hoursAttended - 12.0) < 0.01,
            "D7. User attended 8+4 hour sessions = 12 hours"
        );

        // D8. Hours attended updates after marking absent
        $this->sessionService->markAbsent($sessionId1, $userId);
        $hoursAttended = $this->sessionService->getHoursAttended($userId, $editionId);
        $this->assert(
            abs($hoursAttended - 4.0) < 0.01,
            "D8. After marking absent: 4 hours remaining"
        );

        echo "\n";
    }

    // ========================================
    // E. ATTENDANCE RATE (6 tests)
    // ========================================

    private function testAttendanceRate(): void
    {
        echo "E. Testing Attendance Rate...\n";

        $courseId = $this->createTestCourse('Rate Test');
        $this->created['course_ids'][] = $courseId;

        $editionId = $this->createTestEdition($courseId, '+30 days');
        $this->created['edition_ids'][] = $editionId;

        // Create 4 sessions
        $sessionIds = [];
        for ($i = 0; $i < 4; $i++) {
            $sessionId = $this->sessionService->createSession([
                FieldRegistry::SESSION_EDITION_ID => $editionId,
                FieldRegistry::SESSION_DATE => date('Y-m-d', strtotime('+' . (30 + $i) . ' days')),
                FieldRegistry::SESSION_START_TIME => '09:00',
                FieldRegistry::SESSION_END_TIME => '17:00',
            ]);
            $this->created['session_ids'][] = $sessionId;
            $sessionIds[] = $sessionId;
        }

        $userId = $this->createTestUser('rate_test_' . time());
        $this->created['user_ids'][] = $userId;

        // E1. No attendance = 0% rate
        $rate = $this->sessionService->getAttendanceRate($userId, $editionId);
        $this->assert(
            $rate === 0.0,
            "E1. No attendance = 0% rate"
        );

        // E2. Attended 1 of 4 = 25%
        $this->sessionService->markPresent($sessionIds[0], $userId);
        $rate = $this->sessionService->getAttendanceRate($userId, $editionId);
        $this->assert(
            abs($rate - 0.25) < 0.01,
            "E2. Attended 1/4 = 25% rate"
        );

        // E3. Attended 2 of 4 = 50%
        $this->sessionService->markPresent($sessionIds[1], $userId);
        $rate = $this->sessionService->getAttendanceRate($userId, $editionId);
        $this->assert(
            abs($rate - 0.50) < 0.01,
            "E3. Attended 2/4 = 50% rate"
        );

        // E4. Attended 3 of 4 = 75%
        $this->sessionService->markPresent($sessionIds[2], $userId);
        $rate = $this->sessionService->getAttendanceRate($userId, $editionId);
        $this->assert(
            abs($rate - 0.75) < 0.01,
            "E4. Attended 3/4 = 75% rate"
        );

        // E5. Attended 4 of 4 = 100%
        $this->sessionService->markPresent($sessionIds[3], $userId);
        $rate = $this->sessionService->getAttendanceRate($userId, $editionId);
        $this->assert(
            abs($rate - 1.0) < 0.01,
            "E5. Attended 4/4 = 100% rate"
        );

        // E6. Edition with no sessions = 0% rate
        $emptyEditionId = $this->createTestEdition($courseId, '+90 days');
        $this->created['edition_ids'][] = $emptyEditionId;

        $rate = $this->sessionService->getAttendanceRate($userId, $emptyEditionId);
        $this->assert(
            $rate === 0.0,
            "E6. Edition with no sessions = 0% rate"
        );

        echo "\n";
    }

    // ========================================
    // HELPERS
    // ========================================

    private function createTestCourse(string $title): int
    {
        return wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => $title . ' ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
    }

    private function createTestEdition(int $courseId, string $startOffset): int
    {
        $editionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            FieldRegistry::EDITION_START_DATE => date('Y-m-d', strtotime($startOffset)),
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
        ]);

        return is_wp_error($editionId) ? 0 : $editionId;
    }

    private function createTestUser(string $username): int
    {
        $email = $username . '@test.local';
        $userId = wp_create_user($username, 'testpass123', $email);

        if (!is_wp_error($userId)) {
            update_user_meta($userId, 'first_name', 'Test');
            update_user_meta($userId, 'last_name', 'User');
            update_user_meta($userId, '_stride_test_session', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        wp_set_current_user(1);

        // Delete sessions
        foreach ($this->created['session_ids'] as $sessionId) {
            if ($sessionId && !is_wp_error($sessionId)) {
                wp_delete_post($sessionId, true);
            }
        }
        echo "  - Deleted " . count($this->created['session_ids']) . " sessions\n";

        // Delete editions
        foreach ($this->created['edition_ids'] as $editionId) {
            if ($editionId && !is_wp_error($editionId)) {
                wp_delete_post($editionId, true);
            }
        }
        echo "  - Deleted " . count($this->created['edition_ids']) . " editions\n";

        // Delete courses
        foreach ($this->created['course_ids'] as $courseId) {
            if ($courseId && !is_wp_error($courseId)) {
                wp_delete_post($courseId, true);
            }
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
$test = new StrideSessionTest();
$test->run();
