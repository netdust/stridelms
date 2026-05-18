<?php
/**
 * Flow shake-out #2 — Enrollment-phase completion tasks
 *
 * Task types under test:
 *   - questionnaire (intake-stage field groups)
 *   - documents (file uploads)
 *   - approval (admin-only, locks until questionnaire+documents done)
 *   - session_selection (Keuzecursus-style — locks until approval done + selection window open)
 *
 * Coverage:
 *   - Task initialization on enroll
 *   - Each task's complete → state transition
 *   - Availability rules (which unlock what, in what order)
 *   - Locked-task guards (can user complete a locked task?)
 *   - Auto-confirm on full completion
 *   - Cancel mid-flow + re-enroll preserves tasks correctly
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$editionSvc = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$editionModel = ntdst_data()->get('vad_edition');

global $wpdb;

shake_reset_editions();
shake_clean_test_users();

// === Find test editions with each task type configured ===
shake_section('SETUP: Find editions with each task type configured');
$candidates = [13265, 13311, 13302, 13307, 13315];
foreach ($candidates as $id) {
    $reqs = $completion->getRequirements($id, 'vad_edition');
    $active = array_keys(array_filter($reqs));
    echo "  ed=$id status=" . $editionSvc->getStatus($id)->value . " requirements=" . json_encode($active) . "\n";
}

// === ET-1: Initialization ===
shake_section('ET-1: Initialization — completion_tasks JSON built from edition meta');
// 13311 has session_selection (Keuzecursus)
wp_set_current_user(7781);
$regId = $svc->enroll(7781, 13311);
if (is_wp_error($regId)) {
    echo "  setup FAIL: " . $regId->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId);
    $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
    echo "  reg=$regId status=$row->status tasks=" . json_encode(array_keys($tasks)) . "\n";
    echo "  task shape: " . json_encode($tasks) . "\n";
    foreach ($tasks as $name => $t) {
        $hasStatus = isset($t['status']);
        $hasPhase = isset($t['phase']);
        echo "  '$name' has status+phase: " . ($hasStatus && $hasPhase ? "OK" : "FAIL") . "\n";
    }
}

// === ET-2: Availability rules for each task ===
shake_section('ET-2: Availability rules — initial state');
// 13265 has questionnaire + documents + approval
wp_set_current_user(7782);
$regId2 = $svc->enroll(7782, 13265);
if (!is_wp_error($regId2)) {
    $row = $regRepo->find($regId2);
    $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
    $avail = $completion->getTaskAvailability($tasks, 13265);
    foreach ($avail as $task => $info) {
        echo "  $task: state={$info['state']} reason='{$info['reason']}'\n";
    }
    echo "\n  Expected: questionnaire+documents available, approval locked\n";
    $ok = ($avail['questionnaire']['state'] ?? '?') === 'available'
       && ($avail['documents']['state'] ?? '?') === 'available'
       && ($avail['approval']['state'] ?? '?') === 'locked';
    echo "  rules correct: " . ($ok ? "OK" : "FAIL") . "\n";
}

// === ET-3: Completing questionnaire works ===
shake_section('ET-3: Complete questionnaire task');
$r = $completion->completeTask($regId2, 'questionnaire', ['answers' => ['q1' => 'yes', 'q2' => 'no']]);
if (is_wp_error($r)) {
    echo "  FAIL: " . $r->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId2);
    $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
    $qStatus = $tasks['questionnaire']['status'] ?? '?';
    $hasCompletedAt = !empty($tasks['questionnaire']['completed_at']);
    $hasData = !empty($tasks['questionnaire']['data']['answers']);
    echo "  questionnaire status=$qStatus completed_at_set=" . ($hasCompletedAt ? "Y" : "N") . " answers_persisted=" . ($hasData ? "Y" : "N") . "\n";
    echo "  status not yet auto-confirmed (approval still pending): " . ($row->status === 'pending' ? "OK" : "FAIL — went to $row->status") . "\n";
}

// === ET-4: Approval still locked until documents done ===
shake_section('ET-4: Approval still locked after questionnaire only');
$row = $regRepo->find($regId2);
$avail = $completion->getTaskAvailability($row->completion_tasks ?? [], 13265);
echo "  approval state: " . ($avail['approval']['state'] ?? '?') . "\n";
echo "  expected locked: " . (($avail['approval']['state'] ?? '?') === 'locked' ? "OK" : "FAIL") . "\n";

// === ET-5: Try to complete approval as user — should be blocked ===
shake_section('ET-5: User cannot complete approval task (locked state)');
$r = $completion->completeTask($regId2, 'approval', []);
shake_assert_error($r, 'task_locked', 'ET-5 user complete approval');

// === ET-6: Complete documents task ===
shake_section('ET-6: Complete documents task');
$r = $completion->completeTask($regId2, 'documents', ['files' => ['mock-1.pdf']]);
if (is_wp_error($r)) {
    echo "  FAIL: " . $r->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId2);
    $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
    echo "  documents status=" . ($tasks['documents']['status'] ?? '?') . "\n";
    $avail = $completion->getTaskAvailability($tasks, 13265);
    echo "  approval now: state=" . ($avail['approval']['state'] ?? '?') . " reason='" . ($avail['approval']['reason'] ?? '') . "'\n";
    echo "  expected approval available: " . (($avail['approval']['state'] ?? '?') === 'available' ? "OK" : "FAIL") . "\n";
}

// === ET-7: Admin completes approval task — registration auto-confirms ===
shake_section('ET-7: Complete approval task → reg should auto-confirm');
// completeTask doesn't enforce admin (it's the handler that does). Inspect raw behavior.
$r = $completion->completeTask($regId2, 'approval', ['by' => 'admin']);
if (is_wp_error($r)) {
    echo "  FAIL: " . $r->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId2);
    echo "  reg status after final task: $row->status\n";
    echo "  expected confirmed: " . ($row->status === 'confirmed' ? "OK" : "FAIL — still $row->status") . "\n";
    echo "  quote auto-created on confirm: " . ($row->quote_id ? "OK (quote=$row->quote_id)" : "FAIL") . "\n";
}

// === ET-8: Re-completing approval is a no-op error ===
shake_section('ET-8: Re-completing already-completed task');
$r = $completion->completeTask($regId2, 'approval', []);
if (is_wp_error($r)) {
    echo "  result: WP_Error - " . $r->get_error_message() . "\n";
} else {
    echo "  result: TRUE (idempotent)\n";
}
// Per code: completed tasks return true early (line 357-358), only session_selection allows re-edit
echo "  expected: TRUE (early-return for completed)\n";

// === ET-9: session_selection LOCKED when selection_open=false ===
shake_section('ET-9: session_selection locked when selection_open=false');
$regId3 = $regId; // user 7781 on 13311 (Keuzecursus, session_selection only)
// Force selection_open OFF for this test
$editionModel->updateMetaBatch(13311, ['selection_open' => '']);
$row = $regRepo->find($regId3);
$avail = $completion->getTaskAvailability($row->completion_tasks ?? [], 13311);
echo "  selection_open=false: state=" . ($avail['session_selection']['state'] ?? '?') . " reason='" . ($avail['session_selection']['reason'] ?? '') . "'\n";
echo "  expected locked: " . (($avail['session_selection']['state'] ?? '?') === 'locked' ? "OK" : "FAIL") . "\n";

// Try to complete it while locked
$r = $completion->completeTask($regId3, 'session_selection', ['session_ids' => [1]]);
shake_assert_error($r, 'task_locked', 'ET-9b complete locked session_selection');

// === ET-10: Open the selection window then verify availability flips ===
shake_section('ET-10: selection_open=true → task becomes available');
$editionModel->updateMetaBatch(13311, ['selection_open' => '1']);
$row = $regRepo->find($regId3);
$avail = $completion->getTaskAvailability($row->completion_tasks ?? [], 13311);
echo "  session_selection state: " . ($avail['session_selection']['state'] ?? '?') . "\n";
echo "  expected available: " . (($avail['session_selection']['state'] ?? '?') === 'available' ? "OK" : "FAIL") . "\n";

// === ET-11: Complete session_selection ===
shake_section('ET-11: Complete session_selection');
// Find real session IDs for 13311
$sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
$sessions = $sessionService->getSessionsForEdition(13311);
$sessionIds = array_slice(array_map(fn($s) => (int)$s['id'], $sessions), 0, 2);
echo "  picking sessions: " . json_encode($sessionIds) . "\n";

$r = $completion->completeTask($regId3, 'session_selection', ['session_ids' => $sessionIds]);
if (is_wp_error($r)) {
    echo "  FAIL: " . $r->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId3);
    echo "  reg status after session_selection: $row->status\n";
    echo "  expected auto-confirmed (no other tasks): " . ($row->status === 'confirmed' ? "OK" : "FAIL") . "\n";
}

// === ET-12: Re-edit session_selection allowed before course starts ===
shake_section('ET-12: Re-edit session_selection while selection_open + course not started');
$startDate = $editionModel->getMeta(13311, 'start_date');
echo "  start_date=$startDate (now=" . date('Y-m-d') . ")\n";
$r = $completion->completeTask($regId3, 'session_selection', ['session_ids' => [$sessionIds[0]]]);
if (is_wp_error($r)) {
    echo "  re-edit blocked: " . $r->get_error_message() . "\n";
} else {
    echo "  re-edit allowed: OK\n";
}

// === ET-13: Cancel during pending tasks — what happens to tasks? ===
shake_section('ET-13: Cancel mid-flow → tasks behavior');
wp_set_current_user(7783);
$regId4 = $svc->enroll(7783, 13265);
if (is_wp_error($regId4)) {
    echo "  setup FAIL: " . $regId4->get_error_message() . "\n";
} else {
    $completion->completeTask($regId4, 'questionnaire', ['answers' => ['x' => 'y']]);
    $row = $regRepo->find($regId4);
    echo "  before cancel: status=$row->status tasks=" . json_encode(array_keys($row->completion_tasks ?? [])) . "\n";

    $r = $svc->cancel($regId4);
    $row = $regRepo->find($regId4);
    echo "  after cancel: status=$row->status\n";
    $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
    echo "  completion_tasks after cancel: " . (empty($tasks) ? "EMPTY" : json_encode(array_keys($tasks))) . "\n";
}

// === ET-14: Re-enroll after cancel — does it get fresh tasks? ===
shake_section('ET-14: Re-enroll after cancel → fresh task initialization');
$r = $svc->enroll(7783, 13265);
if (is_wp_error($r)) {
    echo "  FAIL: " . $r->get_error_message() . "\n";
} else {
    $row = $regRepo->find($r);
    echo "  reactivated reg=$r status=$row->status\n";
    $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
    echo "  fresh tasks: " . json_encode(array_keys($tasks)) . "\n";
    $allPending = !empty($tasks) && !array_filter($tasks, fn($t) => ($t['status'] ?? '') !== 'pending');
    echo "  all reset to pending: " . ($allPending ? "OK" : "FAIL — has carried-over completed tasks") . "\n";
}

// === ET-15: Unknown task type rejected ===
shake_section('ET-15: Unknown task type → invalid_task');
$r = $completion->completeTask($regId2, 'fake_task', []);
shake_assert_error($r, 'invalid_task', 'ET-15 bogus task type');

// === ET-16: Complete task on non-existent reg ===
shake_section('ET-16: Complete task on non-existent reg → not_found');
$r = $completion->completeTask(999999, 'questionnaire', []);
shake_assert_error($r, 'not_found', 'ET-16 bogus reg');

// === ET-17: Complete task that isn't in the registration's task set ===
shake_section('ET-17: Complete task not required for this registration → task_not_required');
// 13230 = Open, no requirements at all
wp_set_current_user(7784);
$ed230reg = $svc->enroll(7784, 13230);
if (!is_wp_error($ed230reg)) {
    $r = $completion->completeTask($ed230reg, 'questionnaire', []);
    shake_assert_error($r, 'task_not_required', 'ET-17 task not required');
}

// === ET-18: enrollment_data flows from tasks ===
shake_section('ET-18: enrollment_data captures answer payload');
$row = $regRepo->find($regId2);
$ed = is_array($row->enrollment_data) ? $row->enrollment_data : (json_decode($row->enrollment_data ?? '{}', true) ?: []);
echo "  enrollment_data keys: " . json_encode(array_keys($ed)) . "\n";
$tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
$hasAnswerInTask = !empty($tasks['questionnaire']['data']);
echo "  questionnaire data stored in completion_tasks.questionnaire.data: " . ($hasAnswerInTask ? "OK" : "FAIL") . "\n";
echo "  (answers stored in completion_tasks.<task>.data, NOT enrollment_data — by design)\n";

echo "\n=== Flow #2 (Enrollment tasks) complete ===\n";
