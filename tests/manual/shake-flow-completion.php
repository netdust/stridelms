<?php
/**
 * Flow shake-out #4 — Post-course completion + certificate
 *
 * Two paths reach `completed`:
 *   A. Attendance-driven (in-person):
 *        attendance marked → isComplete() threshold met → processCompletion()
 *        → either initialise post-course tasks (defer) OR mark LD complete + fire stride/completion/completed
 *   B. LD-direct (e-learning):
 *        learndash_course_completed fires → onLearnDashCourseCompleted listener flips Stride reg to completed
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\CompletionMode;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
$editionSvc = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$editionCompletion = ntdst_get(\Stride\Modules\Edition\EditionCompletion::class);
$sessionSvc = ntdst_get(\Stride\Modules\Edition\SessionService::class);
$editionModel = ntdst_data()->get('vad_edition');

global $wpdb;

shake_reset_editions();
shake_clean_test_users();

// ===========================
// Path A: Attendance-driven
// ===========================

// === CP-1: isComplete() returns false when no attendance ===
shake_section('CP-1: isComplete returns false with no attendance');
wp_set_current_user(7781);
$regId = $svc->enroll(7781, 13230);
shake_assert_success($regId, 'CP-1 setup enroll');
$isComplete = $editionCompletion->isComplete(13230, 7781);
echo "  isComplete(13230, 7781) without any attendance: " . ($isComplete ? 'TRUE' : 'false') . "\n";
echo "  expected false: " . (!$isComplete ? 'OK' : 'FAIL') . "\n";

// === CP-2: getProgress shape ===
shake_section('CP-2: getProgress returns expected keys');
$progress = $editionCompletion->getProgress(13230, 7781);
$expectedKeys = ['total_sessions', 'attended', 'required', 'remaining', 'percentage', 'is_complete', 'mode', 'threshold'];
$missing = array_diff($expectedKeys, array_keys($progress));
echo "  keys: " . json_encode(array_keys($progress)) . "\n";
echo "  missing: " . (empty($missing) ? 'none' : json_encode($missing)) . "\n";
echo "  shape correct: " . (empty($missing) ? 'OK' : 'FAIL') . "\n";

// === CP-3: processCompletion rejects when not complete ===
shake_section('CP-3: processCompletion → not_complete when threshold not met');
$r = $editionCompletion->processCompletion(13230, 7781);
shake_assert_error($r, 'not_complete', 'CP-3 not-complete guard');

// === CP-4: Attendance fires the Stride event, but LD enforces lesson completion ===
shake_section('CP-4: Attendance triggers stride/completion/completed event');
$sessions = $sessionSvc->getSessionsForEdition(13230);
$attendanceSvc = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);

$completedFired = 0;
$cb = function() use (&$completedFired) { $completedFired++; };
add_action('stride/completion/completed', $cb, 999);
foreach ($sessions as $s) {
    $attendanceSvc->markPresent((int) $s['id'], 7781);
}
remove_action('stride/completion/completed', $cb, 999);

$row = $regRepo->find($regId);
echo "  stride/completion/completed fired $completedFired times\n";
echo "  reg status after full attendance: $row->status\n";

// Check whether LD considers course complete. If course has lessons, LD will block.
$courseId = $editionSvc->getCourseId(13230);
$lms = ntdst_get(\Stride\Contracts\LMSAdapterInterface::class);
$ldComplete = $lms->isComplete(7781, $courseId);
$ldLessons = function_exists('learndash_get_course_lessons_list')
    ? count(learndash_get_course_lessons_list($courseId)) : 0;
echo "  LD course $courseId has $ldLessons lessons; LD isComplete: " . ($ldComplete ? "T" : "F") . "\n";

if ($ldLessons === 0) {
    echo "  expected: completed (content-free course): " . ($row->status === 'completed' ? "OK" : "FAIL") . "\n";
} else {
    echo "  expected: confirmed (LD has $ldLessons unfinished lessons): " . ($row->status === 'confirmed' ? "OK" : "FAIL — flipped to $row->status despite open LD lessons") . "\n";
}
echo "  event always fires regardless: " . ($completedFired === 1 ? "OK" : "FAIL") . "\n";

// === CP-5: Direct updateStatus(Completed) sets completed_at correctly ===
shake_section('CP-5: Direct updateStatus(Completed) — happy path');
$regRepo->updateStatus($regId, RegistrationStatus::Completed);
$row = $regRepo->find($regId);
echo "  after manual flip: reg status=$row->status completed_at=" . ($row->completed_at ?? '-') . "\n";
echo "  expected completed + completed_at set: " . ($row->status === 'completed' && !empty($row->completed_at) ? "OK" : "FAIL") . "\n";

// === CP-6: D4 regression — re-running updateStatus(Completed) preserves completed_at ===
shake_section('CP-6: D4 regression — completed_at preserved on idempotent re-call');
$orig = $row->completed_at;
sleep(1);
$regRepo->updateStatus($regId, RegistrationStatus::Completed);
$row2 = $regRepo->find($regId);
echo "  orig: $orig\n  now : $row2->completed_at\n";
echo "  preserved: " . ($orig === $row2->completed_at ? "OK" : "FAIL") . "\n";

// === CP-7: Cancel a completed row → already_completed (E7 regression) ===
shake_section('CP-7: E7 regression — cancel completed → already_completed');
$r = $svc->cancel($regId);
shake_assert_error($r, 'already_completed', 'CP-7 cancel completed guard');

// ===========================
// Post-course tasks path
// ===========================

// === CP-8: Edition with post-course tasks → attendance completion defers LD completion ===
shake_section('CP-8: post-course tasks configured → completion deferred');
// Configure a test edition to have post-course tasks
$editionRepo->updateStatus(13234, OfferingStatus::Open);
$editionModel->updateMetaBatch(13234, [
    'post_requires_evaluation' => true,
    'post_requires_documents' => false,
    'post_requires_approval' => false,
]);

// Reset attendance for this user on this edition
$wpdb->query("DELETE FROM stride_vad_attendance WHERE user_id = 7782 AND edition_id = 13234");

wp_set_current_user(7782);
$regId2 = $svc->enroll(7782, 13234);
if (is_wp_error($regId2)) {
    echo "  setup FAIL: " . $regId2->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId2);
    echo "  reg=$regId2 status=$row->status\n";

    // Mark attendance fully
    $sessions = $sessionSvc->getSessionsForEdition(13234);
    if (empty($sessions)) {
        echo "  (no sessions to attend; skipping post-course-defer test)\n";
    } else {
        $attendanceSvc = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
        $deferred = 0;
        $completed = 0;
        $cb1 = function() use (&$deferred) { $deferred++; };
        $cb2 = function() use (&$completed) { $completed++; };
        add_action('stride/completion/attendance_complete', $cb1, 999);
        add_action('stride/completion/completed', $cb2, 999);

        foreach ($sessions as $s) {
            $attendanceSvc->markPresent((int) $s['id'], 7782);
        }

        remove_action('stride/completion/attendance_complete', $cb1, 999);
        remove_action('stride/completion/completed', $cb2, 999);

        echo "  attendance_complete fired: $deferred times\n";
        echo "  completed fired: $completed times\n";

        $row = $regRepo->find($regId2);
        $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
        echo "  reg status: $row->status\n";
        echo "  post-course tasks present: " . json_encode(array_keys(array_filter($tasks, fn($t) => ($t['phase'] ?? '') === 'post_course'))) . "\n";

        $ok = ($deferred === 1 && $completed === 0 && $row->status === 'confirmed');
        echo "  expected: deferred=1, completed=0, status=confirmed: " . ($ok ? "OK" : "FAIL") . "\n";
    }
}

// === CP-9: Completing post-course tasks → triggers final completion ===
shake_section('CP-9: Complete post_evaluation → status=completed');
if (isset($regId2) && !is_wp_error($regId2)) {
    $r = $completion->completeTask($regId2, 'post_evaluation', ['rating' => 5]);
    if (is_wp_error($r)) {
        echo "  FAIL: " . $r->get_error_message() . "\n";
    } else {
        $row = $regRepo->find($regId2);
        echo "  reg status after final task: $row->status\n";
        echo "  expected completed: " . ($row->status === 'completed' ? "OK" : "FAIL — still $row->status") . "\n";
        echo "  completed_at set: " . (!empty($row->completed_at) ? "OK" : "FAIL") . "\n";
    }
}

// ===========================
// Path B: LD-direct
// ===========================

// === CP-10: LD course-completed listener flips confirmed → completed ===
shake_section('CP-10: onLearnDashCourseCompleted listener — array vs WP_Post bug check');
// This was BUG-CP-1 candidate: listener does $edition->ID but getEditionsForCourse returns arrays
$editionRepo->updateStatus(13257, OfferingStatus::Open);
$editionModel->updateMetaBatch(13257, [
    'post_requires_evaluation' => false,
    'post_requires_documents' => false,
    'post_requires_approval' => false,
]);
wp_set_current_user(7783);
$regId3 = $svc->enroll(7783, 13257);
if (is_wp_error($regId3)) {
    echo "  setup FAIL: " . $regId3->get_error_message() . "\n";
} else {
    $row = $regRepo->find($regId3);
    echo "  reg=$regId3 status=$row->status\n";

    // Simulate the LD event
    $user = get_userdata(7783);
    $courseId = $editionSvc->getCourseId(13257);
    $course = get_post($courseId);

    if (!$user || !$course) {
        echo "  setup FAIL: missing user or course\n";
    } else {
        $caught = null;
        try {
            $editionCompletion->onLearnDashCourseCompleted([
                'user' => $user,
                'course' => $course,
                'progress' => [],
                'course_completed' => time(),
            ]);
        } catch (\Throwable $e) {
            $caught = $e;
        }

        if ($caught) {
            echo "  EXCEPTION: " . $caught->getMessage() . "\n";
            echo "  CP-10 FAIL — listener crashed on array vs object access\n";
        } else {
            $row = $regRepo->find($regId3);
            echo "  reg status after LD-completion event: $row->status\n";
            echo "  expected completed: " . ($row->status === 'completed' ? "OK" : "FAIL — still $row->status") . "\n";
        }
    }
}

// === CP-11: getCertificateLink — empty for non-complete ===
shake_section('CP-11: getCertificateLink returns empty for non-complete user');
$link = \Stride\Integrations\LearnDash\LearnDashHelper::getCertificateLink($courseId, 7787);
echo "  link for non-enrolled user 7787: '" . substr($link, 0, 60) . "'\n";
echo "  empty: " . (empty($link) ? "OK" : "FAIL — leaked '$link'") . "\n";

// === CP-12: Re-fire LD complete on already-completed row ===
shake_section('CP-12: Re-firing LD complete on already-completed row');
if (isset($regId3) && !is_wp_error($regId3)) {
    $origCompletedAt = $regRepo->find($regId3)->completed_at;
    sleep(1);
    $editionCompletion->onLearnDashCourseCompleted([
        'user' => $user,
        'course' => $course,
        'progress' => [],
        'course_completed' => time(),
    ]);
    $newCompletedAt = $regRepo->find($regId3)->completed_at;
    echo "  orig: $origCompletedAt\n  new : $newCompletedAt\n";
    // After my D4 fix, updateStatus is idempotent on timestamp
    // BUT the listener only flips confirmed→completed, so re-firing on already-completed should no-op entirely
    echo "  no-op on re-fire (timestamp preserved): " . ($origCompletedAt === $newCompletedAt ? "OK" : "FAIL — overwritten") . "\n";
}

echo "\n=== Flow #4 (Completion + certificate) complete ===\n";
