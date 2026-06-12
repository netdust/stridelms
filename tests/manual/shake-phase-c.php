<?php
/**
 * Phase C — Pending → Confirmed transitions
 *
 * Two paths exist:
 *   1. Admin confirms (EnrollmentService::confirmRegistration)
 *   2. User completes all enrollment-phase tasks → CompletionTaskHandler::onTaskCompleted
 *      auto-fires confirmRegistration when isFullyComplete returns true and no
 *      post-course tasks exist.
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);

shake_reset_editions();

// --- C1: Admin confirms a pending registration ---
shake_section('C1: Admin confirms pending → confirmed + LMS access granted');
// We already have a pending reg from B3 on edition 13265 for user 7783
$existing = $regRepo->findByUserAndEdition(7783, 13265);
if (!$existing || $existing->status !== 'pending') {
    // Create one if needed
    wp_set_current_user(7783);
    $regId = $svc->enroll(7783, 13265);
    echo "  (created reg=$regId)\n";
} else {
    $regId = (int) $existing->id;
    echo "  (using existing reg=$regId)\n";
}
echo "  before: " . shake_dump_reg($regId) . "\n";
$r = $svc->confirmRegistration($regId);
if (is_wp_error($r)) {
    echo "  FAIL: " . $r->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId);
    echo "  after: " . shake_dump_reg($regId) . "\n";
    echo "  expected status=confirmed: " . ($row->status === 'confirmed' ? 'OK' : 'FAIL') . "\n";
}

// --- C2: confirmRegistration on non-pending row → invalid_status ---
shake_section('C2: Confirm a non-pending row → invalid_status');
// $regId is now confirmed, try to confirm again
$r = $svc->confirmRegistration($regId);
shake_assert_error($r, 'invalid_status', 'C2 confirm already-confirmed');

// --- C3: confirmRegistration on non-existent reg → not_found ---
shake_section('C3: Confirm non-existent reg → not_found');
$r = $svc->confirmRegistration(999999);
shake_assert_error($r, 'not_found', 'C3 confirm bogus reg');

// --- C4: Auto-confirm via task completion ---
shake_section('C4: Complete all enrollment tasks → auto-confirm');
// Use shake6 on 13311 (Keuzecursus - has session_selection task)
wp_set_current_user(7786);
$regId = $svc->enroll(7786, 13311);
if (is_wp_error($regId)) {
    echo "  setup FAIL: " . $regId->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId);
    echo "  before: " . shake_dump_reg($regId) . "\n";
    $tasks = is_array($row->completion_tasks) ? array_keys($row->completion_tasks) : [];
    echo "  tasks: " . json_encode($tasks) . "\n";

    // Complete the session_selection task by finding actual sessions for the edition
    $sessionSvc = ntdst_get(\Stride\Modules\Edition\SessionService::class);
    $sessions = $sessionSvc->getSessionsForEdition(13311);
    $sessionIds = array_slice(array_map(fn($s) => (int)$s['id'], $sessions), 0, 4); // pick a few
    echo "  picking sessions: " . json_encode($sessionIds) . "\n";

    $r = $completion->completeTask($regId, 'session_selection', ['session_ids' => $sessionIds]);
    if (is_wp_error($r)) {
        echo "  task complete FAIL: " . $r->get_error_message() . "\n";
    } else {
        $row = $regRepo->find($regId);
        echo "  after: " . shake_dump_reg($regId) . "\n";
        echo "  expected auto-confirmed: " . ($row->status === 'confirmed' ? 'OK' : 'FAIL (still ' . $row->status . ')') . "\n";
    }
}

// --- C5: Pending row with approval task — completing other tasks does NOT auto-confirm ---
shake_section('C5: Approval task still pending → no auto-confirm');
// Use edition 13265 (requires approval). Enroll shake7
wp_set_current_user(7787);
$regId = $svc->enroll(7787, 13265);
if (is_wp_error($regId)) {
    echo "  setup FAIL: " . $regId->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId);
    $tasks = is_array($row->completion_tasks) ? array_keys($row->completion_tasks) : [];
    echo "  initial tasks: " . json_encode($tasks) . "\n";
    echo "  initial status: " . $row->status . "\n";
    // Try completing approval (this is admin-only, so should fail or be locked)
    if (in_array('approval', $tasks, true)) {
        $r = $completion->completeTask($regId, 'approval', []);
        if (is_wp_error($r)) {
            echo "  C5 PASS: completing 'approval' as user blocked: " . $r->get_error_message() . "\n";
        } else {
            echo "  unexpected: 'approval' completable by non-admin\n";
        }
    } else {
        echo "  (no approval task — edition doesn't require approval at task level)\n";
    }
}

echo "\n=== Phase C complete ===\n";
