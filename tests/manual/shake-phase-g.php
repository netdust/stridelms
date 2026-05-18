<?php
/**
 * Phase G — Data column edge cases
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$qhandler = ntdst_get(\Stride\Modules\Questionnaire\QuestionnaireHandler::class);

global $wpdb;

// --- G1: Anonymous interest (user_id=NULL) survives all repo read paths ---
shake_section('G1: NULL user_id survives all repo read paths');
$wpdb->query("DELETE FROM stride_vad_registrations WHERE enrollment_data LIKE '%g1.test%'");
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
$r = $qhandler->handleSubmitInterest(null, [
    'edition_id' => 13224,
    'name' => 'G1 Anon',
    'email' => 'g1.test@anon.test',
]);
$row = $regRepo->findByEmailAndEdition('g1.test@anon.test', 13224);
$id = (int) ($row->id ?? 0);
echo "  created anon row id=$id user_id=" . ($row->user_id ?? 'NULL') . "\n";

// find()
$f = $regRepo->find($id);
echo "  find($id): " . ($f ? "OK status=" . $f->status : "FAIL") . "\n";

// findByEdition (all statuses)
$rows = $regRepo->findByEdition(13224);
$found = false;
foreach ($rows as $r) {
    if ((int)$r->id === $id) { $found = true; break; }
}
echo "  findByEdition(13224) contains anon row: " . ($found ? "OK" : "FAIL") . "\n";

// findByUser with no user_id makes no sense — skip
// findByCompany shouldn't error
$companyResult = $regRepo->findByCompany(1);
echo "  findByCompany(1) returned: " . (is_array($companyResult) ? "OK (array)" : "FAIL") . "\n";

// --- G2: completion_tasks merge on partial update ---
shake_section('G2: completion_tasks JSON merge — marking one task does not wipe others');
// Find a pending reg with completion tasks
$reg = $regRepo->findByUserAndEdition(7783, 13265);
if (!$reg) {
    wp_set_current_user(7783);
    $regId = $svc->enroll(7783, 13265);
    $reg = $regRepo->find($regId);
}
if ($reg && is_array($reg->completion_tasks)) {
    $taskKeys = array_keys($reg->completion_tasks);
    echo "  pre tasks: " . json_encode($taskKeys) . "\n";

    $completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
    // Mark the first non-approval task complete (questionnaire if present, else first)
    $taskToComplete = in_array('questionnaire', $taskKeys, true) ? 'questionnaire' : null;
    if ($taskToComplete) {
        $r = $completion->completeTask((int)$reg->id, $taskToComplete, ['answers' => ['q1' => 'test']]);
        if (is_wp_error($r)) {
            echo "  complete '$taskToComplete' WP_Error: " . $r->get_error_message() . "\n";
        }
        // Re-fetch
        $reg2 = $regRepo->find((int)$reg->id);
        $tasks2 = is_array($reg2->completion_tasks) ? $reg2->completion_tasks : [];
        $postKeys = array_keys($tasks2);
        echo "  post tasks: " . json_encode($postKeys) . "\n";
        echo "  same keys preserved: " . ($postKeys === $taskKeys ? "OK" : "FAIL") . "\n";
        echo "  task marked complete: " . (($tasks2[$taskToComplete]['status'] ?? '?') === 'completed' ? "OK" : "FAIL") . "\n";
    } else {
        echo "  (no questionnaire task to test with — skipping)\n";
    }
} else {
    echo "  setup FAIL: no pending reg with tasks\n";
}

// --- G3: selections JSON store and retrieve ---
shake_section('G3: selections JSON column round-trips correctly');
$reg = $regRepo->findByUserAndEdition(7782, 13311);
if (!$reg) {
    wp_set_current_user(7782);
    $regId = $svc->enroll(7782, 13311);
    $reg = $regRepo->find($regId);
}
if ($reg) {
    $selections = [13312, 13313, 13314];
    $ok = $regRepo->setSelections((int)$reg->id, $selections);
    echo "  setSelections result: " . ($ok ? "OK" : "FAIL") . "\n";
    $reg2 = $regRepo->find((int)$reg->id);
    $retrieved = is_array($reg2->selections) ? $reg2->selections : (json_decode($reg2->selections ?? '[]', true) ?: []);
    echo "  stored: " . json_encode($selections) . "\n";
    echo "  retrieved: " . json_encode($retrieved) . "\n";
    echo "  round-trip: " . ($retrieved == $selections ? "OK" : "FAIL") . "\n";
} else {
    echo "  setup FAIL: no reg for 7782/13311\n";
}

// --- G4: company_id propagation ---
shake_section('G4: company_id propagated from user meta on enroll');
// Set a company_id on user 7784
update_user_meta(7784, '_stride_company_id', 99);
$wpdb->query("DELETE FROM stride_vad_registrations WHERE user_id = 7784 AND edition_id = 13257");
wp_set_current_user(7784);
$editionRepo->updateStatus(13257, OfferingStatus::Open);
$r = $svc->enroll(7784, 13257);
if (is_wp_error($r)) {
    echo "  FAIL setup: " . $r->get_error_message() . "\n";
} else {
    $row = $regRepo->find($r);
    echo "  company_id on row: " . ($row->company_id ?? 'NULL') . "\n";
    echo "  expected 99: " . ((int)$row->company_id === 99 ? "OK" : "FAIL") . "\n";
}
// Cleanup
delete_user_meta(7784, '_stride_company_id');

// --- G5: enrollment_data preserves all stages through cancel + re-enroll ---
shake_section('G5: enrollment_data preserved through cancel → re-enroll cycle');
$reg = $regRepo->findByUserAndEdition(7785, 13224);
if (!$reg) {
    echo "  setup FAIL: no row for 7785/13224 (expected from F1)\n";
} else {
    $beforeData = is_array($reg->enrollment_data) ? $reg->enrollment_data : (json_decode($reg->enrollment_data ?? '{}', true) ?: []);
    echo "  before cancel: enrollment_data keys = " . json_encode(array_keys($beforeData)) . "\n";

    // Cancel
    $svc->cancel((int)$reg->id);
    $reg = $regRepo->find((int)$reg->id);
    $afterCancelData = is_array($reg->enrollment_data) ? $reg->enrollment_data : (json_decode($reg->enrollment_data ?? '{}', true) ?: []);
    echo "  after cancel: enrollment_data keys = " . json_encode(array_keys($afterCancelData)) . "\n";

    // Re-enroll
    wp_set_current_user(7785);
    $r = $svc->enroll(7785, 13224);
    $reg = $regRepo->find((int)$reg->id);
    $afterReenrollData = is_array($reg->enrollment_data) ? $reg->enrollment_data : (json_decode($reg->enrollment_data ?? '{}', true) ?: []);
    echo "  after re-enroll: enrollment_data keys = " . json_encode(array_keys($afterReenrollData)) . "\n";
    echo "  data preserved: " . ($afterReenrollData == $beforeData ? "OK" : "FAIL — data changed") . "\n";
}

// --- G6: JSON_EXTRACT lookup on enrollment_data.{stage}.email works for both interest and waitlist ---
shake_section('G6: findByEmailAndEditionForStage works for both stages');
$wpdb->query("DELETE FROM stride_vad_registrations WHERE enrollment_data LIKE '%g6.test%'");

$editionRepo->updateStatus(13222, OfferingStatus::Full);
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
$qhandler->handleSubmitWaitlist(null, ['edition_id' => 13222, 'name' => 'G6 W', 'email' => 'g6.test@waitlist.test']);
$qhandler->handleSubmitInterest(null, ['edition_id' => 13224, 'name' => 'G6 I', 'email' => 'g6.test@interest.test']);

$wlRow = $regRepo->findByEmailAndEditionForStage('g6.test@waitlist.test', 13222, RegistrationStatus::Waitlist);
$intRow = $regRepo->findByEmailAndEditionForStage('g6.test@interest.test', 13224, RegistrationStatus::Interest);
echo "  waitlist row found: " . ($wlRow ? "OK id=" . $wlRow->id : "FAIL") . "\n";
echo "  interest row found: " . ($intRow ? "OK id=" . $intRow->id : "FAIL") . "\n";

// And cross-check: looking for interest on the waitlist email should NOT match
$wrongStage = $regRepo->findByEmailAndEditionForStage('g6.test@waitlist.test', 13222, RegistrationStatus::Interest);
echo "  wrong-stage lookup correctly returns null: " . ($wrongStage === null ? "OK" : "FAIL — found something") . "\n";

echo "\n=== Phase G complete ===\n";
