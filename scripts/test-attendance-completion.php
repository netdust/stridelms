<?php
/**
 * Stride V1 - Attendance + Completion Tests
 *
 * Tests attendance recording, queries, and completion logic.
 *
 * Run with: ddev exec wp eval-file scripts/test-attendance-completion.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-attendance-completion.php\n";
    exit(1);
}

use Stride\Domain\AttendanceStatus;
use Stride\Domain\CompletionMode;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;

echo "=== Stride V1 - Attendance + Completion Tests ===" . PHP_EOL . PHP_EOL;

$attendanceService = ntdst_get(AttendanceService::class);
$attendanceRepo = ntdst_get(AttendanceRepository::class);
$completionService = ntdst_get(CompletionService::class);
$editionService = ntdst_get(EditionService::class);
$editionRepo = ntdst_get(EditionRepository::class);
$sessionService = ntdst_get(SessionService::class);

$created = ['editions' => [], 'sessions' => [], 'users' => []];
$GLOBALS['passed'] = 0;
$GLOBALS['failed'] = 0;

function assert_test(bool $condition, string $message): void {
    if ($condition) {
        echo "  [PASS] {$message}" . PHP_EOL;
        $GLOBALS['passed']++;
    } else {
        echo "  [FAIL] {$message}" . PHP_EOL;
        $GLOBALS['failed']++;
    }
}

wp_set_current_user(1);

try {
    // === A. SETUP: Create test edition with sessions ===
    echo "A. Setup..." . PHP_EOL;

    // A1. Create edition using repository
    $editionPost = $editionRepo->create([
        'post_title' => 'Test Edition for Attendance',
        'post_status' => 'publish',
        'course_id' => 0, // No LD course needed for testing
        'start_date' => date('Y-m-d'),
        'end_date' => date('Y-m-d', strtotime('+2 days')),
        'capacity' => 20,
        'status' => 'open',
    ]);
    $editionId = is_wp_error($editionPost) ? 0 : $editionPost->ID;
    assert_test(!is_wp_error($editionPost) && $editionId > 0, 'A1. Create edition');
    $created['editions'][] = $editionId;

    // A2-A4. Create 3 sessions
    for ($i = 1; $i <= 3; $i++) {
        $sessionId = $sessionService->createSession([
            'edition_id' => $editionId,
            'date' => date('Y-m-d', strtotime("+{$i} days")),
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);
        assert_test(!is_wp_error($sessionId), "A{$i}a. Create session {$i}");
        $created['sessions'][] = $sessionId;
    }

    // A5. Create test user
    $userId = wp_create_user('att_test_' . time(), 'pass123', 'att@test.local');
    $created['users'][] = $userId;
    assert_test($userId > 0, 'A5. Create test user');

    echo PHP_EOL;

    // === B. ATTENDANCE MARKING ===
    echo "B. Attendance Marking..." . PHP_EOL;

    $session1 = $created['sessions'][0];
    $session2 = $created['sessions'][1];
    $session3 = $created['sessions'][2];

    // B1. Mark present
    $result = $attendanceService->markPresent($session1, $userId);
    assert_test(!is_wp_error($result), 'B1. Mark present');

    // B2. Check is present
    $isPresent = $attendanceService->isPresent($session1, $userId);
    assert_test($isPresent === true, 'B2. Is present');

    // B3. Get status
    $status = $attendanceService->getStatus($session1, $userId);
    assert_test($status === AttendanceStatus::Present, 'B3. Status is present');

    // B4. Mark absent
    $result = $attendanceService->markAbsent($session2, $userId);
    assert_test(!is_wp_error($result), 'B4. Mark absent');

    // B5. Check absent status
    $status = $attendanceService->getStatus($session2, $userId);
    assert_test($status === AttendanceStatus::Absent, 'B5. Status is absent');

    // B6. Mark excused
    $result = $attendanceService->markExcused($session3, $userId);
    assert_test(!is_wp_error($result), 'B6. Mark excused');

    // B7. Check excused status
    $status = $attendanceService->getStatus($session3, $userId);
    assert_test($status === AttendanceStatus::Excused, 'B7. Status is excused');

    // B8. Update status (mark session 2 as present)
    $result = $attendanceService->markPresent($session2, $userId);
    assert_test(!is_wp_error($result), 'B8. Update attendance');

    $status = $attendanceService->getStatus($session2, $userId);
    assert_test($status === AttendanceStatus::Present, 'B8a. Updated status is present');

    echo PHP_EOL;

    // === C. ATTENDANCE QUERIES ===
    echo "C. Attendance Queries..." . PHP_EOL;

    // C1. Count attended
    $attended = $attendanceService->countAttended($userId, $editionId);
    assert_test($attended === 2, 'C1. Count attended = 2');

    // C2. Get attendees for session
    $attendees = $attendanceService->getAttendees($session1);
    assert_test(in_array($userId, $attendees), 'C2. User in session attendees');

    // C3. Get user edition attendance
    $attendance = $attendanceService->getUserEditionAttendance($userId, $editionId);
    assert_test(count($attendance) === 3, 'C3. Has 3 attendance records');

    // C4. Get attendance rate (2/3 = 66.67%)
    $rate = $attendanceService->getAttendanceRate($userId, $editionId);
    assert_test($rate >= 66 && $rate <= 67, 'C4. Attendance rate ~67%');

    // C5. Get session attendance
    $sessionAttendance = $attendanceService->getSessionAttendance($session1);
    assert_test(count($sessionAttendance) === 1, 'C5. Session has 1 record');

    echo PHP_EOL;

    // === D. COMPLETION MODE: ATTEND_ALL ===
    echo "D. Completion Mode: Attend All..." . PHP_EOL;

    // D1. Set attend_all mode (default)
    $completionService->setCompletionMode($editionId, CompletionMode::AttendAll);
    $mode = $completionService->getCompletionMode($editionId);
    assert_test($mode === CompletionMode::AttendAll, 'D1. Mode is attend_all');

    // D2. Not complete (2/3 sessions)
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test(!$isComplete, 'D2. Not complete with 2/3 sessions');

    // D3. Mark session 3 as present
    $attendanceService->markPresent($session3, $userId);
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test($isComplete, 'D3. Complete with 3/3 sessions');

    // D4. Get progress
    $progress = $completionService->getProgress($editionId, $userId);
    assert_test($progress['is_complete'] === true, 'D4. Progress shows complete');
    assert_test($progress['attended'] === 3, 'D4a. Progress shows 3 attended');

    echo PHP_EOL;

    // === E. COMPLETION MODE: PERCENTAGE ===
    echo "E. Completion Mode: Percentage..." . PHP_EOL;

    // E1. Set percentage mode with 50% threshold
    $completionService->setCompletionMode($editionId, CompletionMode::Percentage);
    $completionService->setCompletionThreshold($editionId, 50);
    $mode = $completionService->getCompletionMode($editionId);
    assert_test($mode === CompletionMode::Percentage, 'E1. Mode is percentage');

    // E2. Mark session 3 as absent (now 2/3 = 67%)
    $attendanceService->markAbsent($session3, $userId);
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test($isComplete, 'E2. Complete with 67% (threshold 50%)');

    // E3. Set higher threshold (70%)
    $completionService->setCompletionThreshold($editionId, 70);
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test(!$isComplete, 'E3. Not complete with 67% (threshold 70%)');

    echo PHP_EOL;

    // === F. COMPLETION MODE: COUNT ===
    echo "F. Completion Mode: Count..." . PHP_EOL;

    // F1. Set count mode with minimum 2 sessions
    $completionService->setCompletionMode($editionId, CompletionMode::Count);
    $completionService->setCompletionThreshold($editionId, 2);
    $mode = $completionService->getCompletionMode($editionId);
    assert_test($mode === CompletionMode::Count, 'F1. Mode is count');

    // F2. Complete with 2 sessions (threshold 2)
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test($isComplete, 'F2. Complete with 2/3 (threshold 2)');

    // F3. Set higher threshold (3)
    $completionService->setCompletionThreshold($editionId, 3);
    $isComplete = $completionService->isComplete($editionId, $userId);
    assert_test(!$isComplete, 'F3. Not complete with 2/3 (threshold 3)');

    echo PHP_EOL;

    // === G. BULK OPERATIONS ===
    echo "G. Bulk Operations..." . PHP_EOL;

    // Create second user
    $userId2 = wp_create_user('att_test2_' . time(), 'pass123', 'att2@test.local');
    $created['users'][] = $userId2;

    // G1. Mark multiple users present
    $results = $attendanceService->markMultiplePresent($session1, [$userId, $userId2]);
    assert_test(count($results) === 2, 'G1. Marked 2 users');
    assert_test(!is_wp_error($results[$userId2]), 'G1a. Second user marked');

    // G2. Get attendees
    $attendees = $attendanceService->getAttendees($session1);
    assert_test(count($attendees) === 2, 'G2. Session has 2 attendees');

    echo PHP_EOL;

} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

// Cleanup
echo "Cleaning up..." . PHP_EOL;

// Delete attendance records
global $wpdb;
foreach ($created['sessions'] as $sessionId) {
    $wpdb->delete($wpdb->prefix . 'vad_attendance', ['session_id' => $sessionId]);
}

// Delete sessions and editions
foreach ($created['sessions'] as $id) {
    wp_delete_post($id, true);
}
foreach ($created['editions'] as $id) {
    wp_delete_post($id, true);
}

// Delete users
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ($created['users'] as $id) {
    wp_delete_user($id);
}

$passed = $GLOBALS['passed'];
$failed = $GLOBALS['failed'];

echo PHP_EOL . "=== Results ===" . PHP_EOL;
echo "Passed: {$passed}" . PHP_EOL;
echo "Failed: {$failed}" . PHP_EOL;
echo ($failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . PHP_EOL;
