<?php
/**
 * Stride V1 - Session Selection Tests
 *
 * Tests session CRUD, selection, and deadline enforcement.
 *
 * Run with: ddev exec wp eval-file scripts/test-session-selection.php
 */

if (!defined('ABSPATH')) {
    echo "Run via WP-CLI: ddev exec wp eval-file scripts/test-session-selection.php\n";
    exit(1);
}

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Edition\SessionSelectionService;
use Stride\Modules\Enrollment\RegistrationRepository;

echo "=== Stride V1 - Session Selection Tests ===" . PHP_EOL . PHP_EOL;

$editionRepo = ntdst_get(EditionRepository::class);
$sessionService = ntdst_get(SessionService::class);
$selectionService = ntdst_get(SessionSelectionService::class);
$registrationRepo = ntdst_get(RegistrationRepository::class);

$created = ['editions' => [], 'sessions' => [], 'users' => [], 'registrations' => []];
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
    // === A. SESSION CRUD ===
    echo "A. Session CRUD..." . PHP_EOL;

    // Create test edition
    $editionId = $editionRepo->create([
        'post_title' => 'Session Test Edition',
        'course_id' => 1,
        'start_date' => date('Y-m-d', strtotime('+30 days')),
        'capacity' => 20,
        'status' => 'open',
    ])->ID;
    $created['editions'][] = $editionId;

    // A1. Create session
    $sessionId = $sessionService->createSession([
        'edition_id' => $editionId,
        'slot' => 'dag1_vm',
        'date' => date('Y-m-d', strtotime('+30 days')),
        'start_time' => '09:00',
        'end_time' => '12:00',
        'type' => 'in_person',
    ]);
    assert_test(!is_wp_error($sessionId), 'A1. Create session');
    $created['sessions'][] = $sessionId;

    // A2. Get session
    $session = $sessionService->getSession($sessionId);
    assert_test($session !== null && $session['slot'] === 'dag1_vm', 'A2. Get session');

    // A3. Session duration
    $duration = $sessionService->getSessionDuration($sessionId);
    assert_test(abs($duration - 3.0) < 0.01, 'A3. Duration is 3 hours');

    // A4. Create second session
    $sessionId2 = $sessionService->createSession([
        'edition_id' => $editionId,
        'slot' => 'dag1_nm',
        'date' => date('Y-m-d', strtotime('+30 days')),
        'start_time' => '13:00',
        'end_time' => '17:00',
        'type' => 'in_person',
    ]);
    $created['sessions'][] = $sessionId2;

    // A5. Get sessions for edition
    $sessions = $sessionService->getSessionsForEdition($editionId);
    assert_test(count($sessions) === 2, 'A5. Edition has 2 sessions');

    // A6. Total hours
    $totalHours = $sessionService->getTotalHours($editionId);
    assert_test(abs($totalHours - 7.0) < 0.01, 'A6. Total hours is 7 (3+4)');

    echo PHP_EOL;

    // === B. SESSION SELECTION ===
    echo "B. Session Selection..." . PHP_EOL;

    // Create test user
    $userId = wp_create_user('session_test_' . time(), 'pass123', 'session@test.local');
    $created['users'][] = $userId;

    // Create registration
    $regId = $registrationRepo->create([
        'user_id' => $userId,
        'edition_id' => $editionId,
        'status' => 'confirmed',
    ]);
    $created['registrations'][] = $regId;

    // B1. Register for session
    $result = $selectionService->registerForSession($regId, $sessionId, $userId);
    assert_test($result === true, 'B1. Register for session succeeds');

    // B2. Check is registered
    $isRegistered = $selectionService->isRegisteredForSession($userId, $sessionId);
    assert_test($isRegistered, 'B2. User is registered for session');

    // B3. Get user selections
    $selections = $selectionService->getUserSelections($regId);
    assert_test(count($selections) === 1, 'B3. User has 1 selection');

    // B4. Double registration fails
    $result2 = $selectionService->registerForSession($regId, $sessionId, $userId);
    assert_test(is_wp_error($result2) && $result2->get_error_code() === 'already_registered', 'B4. Double registration rejected');

    // B5. Session registration count
    $count = $selectionService->getSessionRegistrationCount($sessionId);
    assert_test($count === 1, 'B5. Session has 1 registration');

    // B6. Cancel session registration
    $cancelResult = $selectionService->cancelSessionRegistration($userId, $sessionId);
    assert_test($cancelResult === true, 'B6. Cancel session succeeds');

    // B7. User no longer registered
    $isRegistered = $selectionService->isRegisteredForSession($userId, $sessionId);
    assert_test(!$isRegistered, 'B7. User no longer registered after cancel');

    echo PHP_EOL;

    // === C. DEADLINE ENFORCEMENT ===
    echo "C. Deadline Enforcement..." . PHP_EOL;

    // C1. No deadline = not locked
    $isLocked = $selectionService->isSelectionLocked($editionId);
    assert_test(!$isLocked, 'C1. No deadline = not locked');

    // C2. Set future deadline
    $editionRepo->update($editionId, [
        'selection_deadline' => date('Y-m-d', strtotime('+14 days')),
    ]);
    $days = $selectionService->getDaysUntilDeadline($editionId);
    assert_test($days >= 13 && $days <= 14, 'C2. Days until deadline correct');

    // C3. Selection still open
    $isLocked = $selectionService->isSelectionLocked($editionId);
    assert_test(!$isLocked, 'C3. Future deadline = not locked');

    // C4. Set past deadline
    $editionRepo->update($editionId, [
        'selection_deadline' => date('Y-m-d', strtotime('-1 day')),
    ]);
    $isLocked = $selectionService->isSelectionLocked($editionId);
    assert_test($isLocked, 'C4. Past deadline = locked');

    // C5. Registration blocked after deadline
    $result = $selectionService->registerForSession($regId, $sessionId2, $userId);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'deadline_passed', 'C5. Registration blocked after deadline');

    echo PHP_EOL;

    // === D. CAPACITY ===
    echo "D. Capacity..." . PHP_EOL;

    // Reset deadline and create capacity-limited session
    $editionRepo->update($editionId, ['selection_deadline' => '']);

    $limitedSessionId = $sessionService->createSession([
        'edition_id' => $editionId,
        'slot' => 'limited',
        'date' => date('Y-m-d', strtotime('+31 days')),
        'start_time' => '09:00',
        'end_time' => '12:00',
        'type' => 'in_person',
        'capacity' => 1,
    ]);
    $created['sessions'][] = $limitedSessionId;

    // D1. Has capacity initially
    $hasCapacity = $selectionService->hasCapacity($limitedSessionId);
    assert_test($hasCapacity, 'D1. Session has capacity initially');

    // D2. Register fills capacity
    $selectionService->registerForSession($regId, $limitedSessionId, $userId);
    $hasCapacity = $selectionService->hasCapacity($limitedSessionId);
    assert_test(!$hasCapacity, 'D2. Session full after registration');

    // D3. Second user blocked
    $userId2 = wp_create_user('session_test2_' . time(), 'pass123', 'session2@test.local');
    $created['users'][] = $userId2;
    $regId2 = $registrationRepo->create([
        'user_id' => $userId2,
        'edition_id' => $editionId,
        'status' => 'confirmed',
    ]);
    $created['registrations'][] = $regId2;

    $result = $selectionService->registerForSession($regId2, $limitedSessionId, $userId2);
    assert_test(is_wp_error($result) && $result->get_error_code() === 'no_capacity', 'D3. Full session rejects registration');

    echo PHP_EOL;

} catch (Exception $e) {
    echo "[FATAL] " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
}

// Cleanup
echo "Cleaning up..." . PHP_EOL;

foreach ($created['sessions'] as $id) {
    wp_delete_post($id, true);
}
foreach ($created['editions'] as $id) {
    wp_delete_post($id, true);
}
foreach ($created['registrations'] as $id) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
    $wpdb->delete($wpdb->prefix . 'vad_session_registrations', ['registration_id' => $id]);
}
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
