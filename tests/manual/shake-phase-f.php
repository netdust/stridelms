<?php
/**
 * Phase F — Upgrade paths (interest → enrollment, waitlist row reuse)
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$qhandler = ntdst_get(\Stride\Modules\Questionnaire\QuestionnaireHandler::class);

global $wpdb;

// --- F1: Anonymous interest, user later registers with matching email, edition flips Open ---
shake_section('F1: Anonymous interest → user enrolls when edition opens → row promoted');
// Setup: anonymous interest on edition 13224 (Announcement)
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
$wpdb->query("DELETE FROM stride_vad_registrations WHERE edition_id = 13224 AND enrollment_data LIKE '%upgrade.test%'");

// User shake5 (7785) has email shake5@smoke.test. Submit anonymous interest with that email.
$user = get_userdata(7785);
$email = $user->user_email;
echo "  using user 7785 email=$email\n";

$r1 = $qhandler->handleSubmitInterest(null, [
    'edition_id' => 13224,
    'name' => 'Upgrade Test',
    'email' => $email,
]);
echo "  anonymous interest: " . (is_wp_error($r1) ? "FAIL" : "OK") . "\n";

$preRow = $regRepo->findByEmailAndEdition($email, 13224);
echo "  pre: interest row id=" . ($preRow->id ?? '?') . " user_id=" . ($preRow->user_id ?? 'NULL') . " status=" . ($preRow->status ?? '?') . "\n";

// Flip edition to Open
$editionRepo->updateStatus(13224, OfferingStatus::Open);
echo "  edition flipped to Open\n";

// User enrolls
wp_set_current_user(7785);
$enrollResult = $svc->enroll(7785, 13224);
if (is_wp_error($enrollResult)) {
    echo "  F1 enroll: FAIL " . $enrollResult->get_error_message() . "\n";
} else {
    $postRow = $regRepo->find($enrollResult);
    echo "  post: reg_id=" . $postRow->id . " user_id=" . $postRow->user_id . " status=" . $postRow->status . "\n";
    // Verify no duplicate
    $totalRows = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM stride_vad_registrations WHERE edition_id = %d AND (user_id = %d OR (user_id IS NULL AND JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.interest.email')) = %s))", 13224, 7785, $email));
    echo "  total rows for this user/email on this edition: $totalRows (expect 1)\n";
    echo "  upgrade path: " . ($totalRows == 1 && (int)$postRow->id === (int)$preRow->id ? "OK — row promoted in-place" : "FAIL — duplicate created or different row") . "\n";
}

// --- F2: Security check — anonymous interest exists for victim, attacker tries to upgrade ---
shake_section('F2: Security — non-self enroller cannot trigger upgradeFromInterest');
// Setup: anonymous interest for shake6 (7786) email
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
$wpdb->query("DELETE FROM stride_vad_registrations WHERE edition_id = 13224 AND enrollment_data LIKE '%shake6%'");
$victim = get_userdata(7786);
$qhandler->handleSubmitInterest(null, [
    'edition_id' => 13224,
    'name' => 'Victim Name',
    'email' => $victim->user_email,
]);
$preVictim = $regRepo->findByEmailAndEdition($victim->user_email, 13224);
echo "  victim interest row id=" . ($preVictim->id ?? '?') . "\n";

// Attacker (shake3, user 7783) tries to enroll AS THE VICTIM (admin-style call)
$editionRepo->updateStatus(13224, OfferingStatus::Open);
wp_set_current_user(7783);  // attacker context
$attackResult = $svc->enroll(7786, 13224);  // attacker enrolls victim
if (is_wp_error($attackResult)) {
    echo "  F2 attacker-enroll: " . $attackResult->get_error_message() . "\n";
} else {
    $newRow = $regRepo->find($attackResult);
    // Was the existing interest row used, or a new one created?
    $reused = (int)$newRow->id === (int)$preVictim->id;
    echo "  victim reg now: id=" . $newRow->id . " (was interest=" . $preVictim->id . ") reused=" . ($reused ? "Y" : "N") . "\n";
    if ($reused) {
        echo "  F2 result: row WAS reused — this means attacker context still triggered the upgrade. Inspect.\n";
    } else {
        // Check the original interest row's state
        $stillThere = $regRepo->find((int)$preVictim->id);
        echo "  original interest row id=" . $preVictim->id . " status=" . ($stillThere ? $stillThere->status : 'gone') . "\n";
        echo "  F2 PASS: new row created — old interest row untouched (security check held)\n";
    }
}

// --- F3: enrollment_data round-trip across stages ---
shake_section('F3: enrollment_data round-trip — interest → enrollment → preserves all stages');
// Find the F1 row that was upgraded
$f1Row = $regRepo->findByUserAndEdition(7785, 13224);
if ($f1Row) {
    $data = is_array($f1Row->enrollment_data) ? $f1Row->enrollment_data : (json_decode($f1Row->enrollment_data ?? '{}', true) ?: []);
    echo "  enrollment_data keys: " . json_encode(array_keys($data)) . "\n";
    echo "  has 'interest' key: " . (isset($data['interest']) ? "OK" : "FAIL — lost on upgrade") . "\n";
}

// --- F4: Waitlist row reuse when admin invites user to enroll later ---
shake_section('F4: Waitlist row reuse on later enroll (Full → Open)');
$editionRepo->updateStatus(13222, OfferingStatus::Full);
$wpdb->query("DELETE FROM stride_vad_registrations WHERE edition_id = 13222 AND user_id = 7788");

// User waitlists
wp_set_current_user(7788);
$wlId = $svc->registerWaitlist(7788, ['edition_id' => 13222]);
echo "  waitlist reg=" . (is_wp_error($wlId) ? $wlId->get_error_message() : $wlId) . "\n";

// Admin manually flips edition to Open (simulated)
$editionRepo->updateStatus(13222, OfferingStatus::Open);

// User enrolls
$enrollResult = $svc->enroll(7788, 13222);
$preCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM stride_vad_registrations WHERE edition_id = 13222 AND user_id = 7788");
echo "  enroll result: " . (is_wp_error($enrollResult) ? "ERROR " . $enrollResult->get_error_message() : "reg=$enrollResult") . "\n";
echo "  total rows for user 7788 on 13222: $preCount\n";
if (!is_wp_error($enrollResult)) {
    $row = $regRepo->find($enrollResult);
    echo "  state: " . shake_dump_reg((int)$row->id) . "\n";
    if ((int)$row->id === (int)$wlId) {
        echo "  F4: row REUSED — waitlist promoted to enrollment in-place\n";
    } else {
        echo "  F4: NEW row created — original waitlist still there. Check: " . shake_dump_reg($wlId) . "\n";
    }
}

echo "\n=== Phase F complete ===\n";
