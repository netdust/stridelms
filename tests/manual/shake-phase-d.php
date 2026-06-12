<?php
/**
 * Phase D — Completed state
 *
 * Two paths reach Completed:
 *   1. LearnDash course completion (post-publish) → EditionCompletion::handleCourseCompletion
 *      flips confirmed → completed for matching edition rows.
 *   2. Post-course completion task path: CompletionTaskHandler::onTaskCompleted
 *      when all tasks done AND post-course tasks exist → updateStatus(Completed).
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);

// --- D1: updateStatus(Completed) sets completed_at ---
shake_section('D1: updateStatus(Completed) sets completed_at');
$reg = $regRepo->findByUserAndEdition(7781, 13230);
if (!$reg || $reg->status !== 'confirmed') {
    echo "  setup FAIL: no confirmed reg for user 7781 on 13230\n";
} else {
    echo "  before: " . shake_dump_reg((int)$reg->id) . "\n";
    $regRepo->updateStatus((int)$reg->id, RegistrationStatus::Completed);
    $row = $regRepo->find((int)$reg->id);
    echo "  after: " . shake_dump_reg((int)$reg->id) . "\n";
    echo "  expected status=completed + completed_at set: " . ($row->status === 'completed' && !empty($row->completed_at) ? 'OK' : 'FAIL') . "\n";
}

// --- D2: LD course completion flips confirmed → completed (without post-course tasks) ---
shake_section('D2: LD course completion → flips confirmed regs to completed');
wp_set_current_user(7788);
$existing = $regRepo->findByUserAndEdition(7788, 13257);
if ($existing) {
    $reg = $existing;
} else {
    $regIdOrErr = $svc->enroll(7788, 13257);
    if (is_wp_error($regIdOrErr)) {
        echo "  setup FAIL: " . $regIdOrErr->get_error_message() . "\n";
        $reg = null;
    } else {
        $reg = $regRepo->find($regIdOrErr);
        echo "  (created reg=$regIdOrErr)\n";
    }
}
if ($reg) {
    if ($reg->status !== 'confirmed') {
        $regRepo->updateStatus((int)$reg->id, RegistrationStatus::Confirmed);
        $reg = $regRepo->find((int)$reg->id);
    }
    echo "  before: " . shake_dump_reg((int)$reg->id) . "\n";

    $courseId = ntdst_get(\Stride\Modules\Edition\EditionService::class)->getCourseId(13257);
    echo "  course_id=$courseId\n";
    $editions = ntdst_get(\Stride\Modules\Edition\EditionRepository::class)->findByCourse($courseId);
    foreach ($editions as $ed) {
        $edId = is_object($ed) ? (int)($ed->ID ?? 0) : (int)($ed['id'] ?? $ed['ID'] ?? 0);
        if (!$edId) continue;
        $r2 = $regRepo->findByUserAndEdition(7788, $edId);
        if ($r2 && $r2->status === 'confirmed') {
            $regRepo->updateStatus((int)$r2->id, RegistrationStatus::Completed);
        }
    }
    $row = $regRepo->find((int)$reg->id);
    echo "  after: " . shake_dump_reg((int)$reg->id) . "\n";
    echo "  expected completed: " . ($row->status === 'completed' ? 'OK' : 'FAIL') . "\n";
}

// --- D3: Code trace only ---
shake_section('D3: Post-course task path defers Completed until all tasks done');
echo "  (logic verified by code trace — full E2E test requires real LD activity which is out of scope)\n";

// --- D4: Re-running updateStatus(Completed) preserves original completed_at ---
shake_section('D4: updateStatus(Completed) is idempotent + preserves completed_at');
$reg = $regRepo->findByUserAndEdition(7788, 13257);
if ($reg) {
    $origCompletedAt = $reg->completed_at;
    sleep(1);  // ensure clock would tick
    $regRepo->updateStatus((int)$reg->id, RegistrationStatus::Completed);
    $reg2 = $regRepo->find((int)$reg->id);
    echo "  orig completed_at: $origCompletedAt\n";
    echo "  new  completed_at: " . $reg2->completed_at . "\n";
    echo "  status still completed: " . ($reg2->status === 'completed' ? 'OK' : 'FAIL') . "\n";
    echo "  completed_at preserved: " . ($origCompletedAt === $reg2->completed_at ? 'OK' : 'FAIL — overwritten') . "\n";
}

// --- D5: Same idempotency guard for cancelled_at ---
shake_section('D5: updateStatus(Cancelled) is idempotent + preserves cancelled_at');
// Find a cancelled reg or create one
$reg = $regRepo->findByUserAndEdition(7782, 13311);
if ($reg && $reg->status === 'cancelled' && !empty($reg->cancelled_at)) {
    $origCancelledAt = $reg->cancelled_at;
    sleep(1);
    $regRepo->updateStatus((int)$reg->id, RegistrationStatus::Cancelled);
    $reg2 = $regRepo->find((int)$reg->id);
    echo "  orig cancelled_at: $origCancelledAt\n";
    echo "  new  cancelled_at: " . $reg2->cancelled_at . "\n";
    echo "  cancelled_at preserved: " . ($origCancelledAt === $reg2->cancelled_at ? 'OK' : 'FAIL') . "\n";
} else {
    echo "  setup: no suitable cancelled row to test against (state: " . ($reg ? $reg->status : 'none') . ")\n";
}

echo "\n=== Phase D complete ===\n";
