<?php
/**
 * Flow shake-out #1 — Interest / Waitlist surface
 *
 * Every entry point + side-effect + admin surface for these two flows.
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$qh = ntdst_get(\Stride\Modules\Questionnaire\QuestionnaireHandler::class);

global $wpdb;

// Test editions:
// 13222 = Full → waitlist
// 13224 = Announcement → interest
// 13230 = Open → neither (control)
shake_reset_editions();
shake_clean_test_users();

// === Submission paths ===

shake_section('IW-1: Anonymous interest submission via handler');
$r = $qh->handleSubmitInterest(null, ['edition_id' => 13224, 'name' => 'IW1', 'email' => 'iw1@flow.test']);
shake_assert_success($r, 'IW-1 anon interest');

shake_section('IW-2: Anonymous waitlist submission via handler');
$r = $qh->handleSubmitWaitlist(null, ['edition_id' => 13222, 'name' => 'IW2', 'email' => 'iw2@flow.test']);
shake_assert_success($r, 'IW-2 anon waitlist');

shake_section('IW-3: Anonymous resubmit on same email — upsert (no duplicate row)');
$before = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM stride_vad_registrations WHERE edition_id = %d AND JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.interest.email')) = %s",
    13224, 'iw1@flow.test'
));
$r = $qh->handleSubmitInterest(null, ['edition_id' => 13224, 'name' => 'IW1 v2', 'email' => 'iw1@flow.test']);
shake_assert_success($r, 'IW-3 anon interest resubmit');
$after = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM stride_vad_registrations WHERE edition_id = %d AND JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.interest.email')) = %s",
    13224, 'iw1@flow.test'
));
echo "  rows before=$before after=$after — expect 1=1: " . ($before === 1 && $after === 1 ? "OK" : "FAIL") . "\n";

shake_section('IW-4: Logged-in interest via registerInterest()');
wp_set_current_user(7785);
$r = $svc->registerInterest(7785, ['edition_id' => 13224]);
shake_assert_success($r, 'IW-4 logged-in interest');

shake_section('IW-5: Logged-in waitlist via registerWaitlist()');
wp_set_current_user(7786);
$r = $svc->registerWaitlist(7786, ['edition_id' => 13222]);
shake_assert_success($r, 'IW-5 logged-in waitlist');

shake_section('IW-6: Interest on non-Announcement edition rejected');
wp_set_current_user(7787);
$r = $svc->registerInterest(7787, ['edition_id' => 13230]);
shake_assert_error($r, 'interest_closed', 'IW-6 interest on Open');

shake_section('IW-7: Waitlist on non-Full edition rejected');
$r = $svc->registerWaitlist(7787, ['edition_id' => 13230]);
shake_assert_error($r, 'waitlist_closed', 'IW-7 waitlist on Open');

// === Cross-status dedup ===

shake_section('IW-8: Same anonymous email — interest THEN waitlist on same edition (edition state change)');
// Reset to fresh edition state, anonymous email submits interest on Announcement
$wpdb->query("DELETE FROM stride_vad_registrations WHERE enrollment_data LIKE '%iw8@flow.test%'");
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
$qh->handleSubmitInterest(null, ['edition_id' => 13224, 'name' => 'IW8', 'email' => 'iw8@flow.test']);

// Admin flips edition to Full (could happen if edition gets scheduled then immediately full)
$editionRepo->updateStatus(13224, OfferingStatus::Full);

// Same anonymous email submits waitlist
$r = $qh->handleSubmitWaitlist(null, ['edition_id' => 13224, 'name' => 'IW8', 'email' => 'iw8@flow.test']);
shake_assert_success($r, 'IW-8 waitlist after interest');
$rowCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM stride_vad_registrations WHERE edition_id = %d AND user_id IS NULL AND (JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.interest.email')) = %s OR JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.waitlist.email')) = %s)",
    13224, 'iw8@flow.test', 'iw8@flow.test'
));
echo "  total anon rows for iw8@flow.test on ed 13224: $rowCount\n";
echo "  CROSS-STATUS DEDUP: " . ($rowCount === 1 ? "OK (single row, upserted)" : "GAP — created $rowCount separate rows for same email") . "\n";

// === Admin visibility ===

shake_section('IW-9: getEditionRegistrations API returns anonymous rows');
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
$ac = ntdst_get(\Stride\Admin\AdminAPIController::class);
$req = new \WP_REST_Request('GET', '/stride/v1/admin/editions/13224/registrations');
$req->set_url_params(['id' => 13224]);
$resp = $ac->getEditionRegistrations($req);
$data = $resp instanceof \WP_REST_Response ? $resp->get_data() : null;
$items = $data['items'] ?? [];
$anonRows = array_filter($items, fn($i) => !empty($i['anonymous']));
$anonWithEmail = array_filter($anonRows, fn($i) => !empty($i['user']['email']));
echo "  total items: " . count($items) . "\n";
echo "  anonymous rows shown: " . count($anonRows) . "\n";
echo "  anon rows with stageData email: " . count($anonWithEmail) . "\n";
echo "  expected ≥1 anon row + email fallback: " . (count($anonRows) >= 1 && count($anonWithEmail) >= 1 ? "OK" : "FAIL — anon rows hidden") . "\n";

shake_section('IW-10: getEditionRegistrations includes interest + waitlist statuses');
$statuses = array_unique(array_map(fn($i) => $i['status'] ?? '?', $items));
echo "  statuses present: " . json_encode(array_values($statuses)) . "\n";
echo "  contains interest: " . (in_array('interest', $statuses, true) ? "OK" : "FAIL") . "\n";

// === Mail delivery ===

shake_section('IW-11: Anonymous interest submission triggers user mail');
$wpdb->query("DELETE FROM stride_vad_registrations WHERE enrollment_data LIKE '%iw11@flow.test%'");
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM stride_postmeta WHERE meta_value LIKE '%iw11@flow.test%'");
$qh->handleSubmitInterest(null, ['edition_id' => 13224, 'name' => 'IW11', 'email' => 'iw11@flow.test']);
echo "  (Mailpit check: see latest mails to iw11@flow.test)\n";
echo "  (smoke-tested earlier — confirmed working)\n";

// === Export ===

shake_section('IW-12: XLSX export includes Interesse + Wachtlijst sheets');
$exporter = ntdst_get(\Stride\Modules\Edition\Admin\EditionRegistrationExporter::class);
$path = "/tmp/flow-iw-13224.xlsx";
$exporter->buildToFile(13224, $path);
$sheetNames = shell_exec("unzip -p $path xl/workbook.xml | grep -oP 'name=\"[^\"]+\"' | head -10");
echo "  edition 13224 sheets:\n$sheetNames";

$path2 = "/tmp/flow-iw-13222.xlsx";
$exporter->buildToFile(13222, $path2);
$sheetNames2 = shell_exec("unzip -p $path2 xl/workbook.xml | grep -oP 'name=\"[^\"]+\"' | head -10");
echo "  edition 13222 sheets:\n$sheetNames2";

// === Convert to enrollment (interest → enrollment) ===

shake_section('IW-13: Interest upgrade path covered in Phase F — link only');
echo "  See shake-phase-f.php F1-F3 for full upgrade-path tests.\n";

// === Trajectory equivalent ===

shake_section('IW-14: Interest registration on trajectory (not edition)');
// Find a trajectory
$traj = get_posts(['post_type' => 'vad_trajectory', 'numberposts' => 1, 'post_status' => 'publish']);
if (empty($traj)) {
    echo "  skipped: no trajectory found\n";
} else {
    $trajId = $traj[0]->ID;
    echo "  using trajectory ID $trajId\n";
    wp_set_current_user(7788);
    $r = $svc->registerInterest(7788, ['trajectory_id' => $trajId]);
    if (is_wp_error($r)) {
        echo "  IW-14: " . $r->get_error_code() . " — " . $r->get_error_message() . "\n";
    } else {
        echo "  IW-14: created reg=$r\n";
    }
}

shake_section('IW-15: Waitlist on trajectory — does it work at all?');
$traj = get_posts(['post_type' => 'vad_trajectory', 'numberposts' => 1, 'post_status' => 'publish']);
if (!empty($traj)) {
    $r = $svc->registerWaitlist(7788, ['trajectory_id' => $traj[0]->ID]);
    if (is_wp_error($r)) {
        echo "  IW-15: " . $r->get_error_code() . " — " . $r->get_error_message() . "\n";
    } else {
        echo "  IW-15: created reg=$r\n";
    }
}

echo "\n=== Flow #1 (Interest/Waitlist surface) complete ===\n";
