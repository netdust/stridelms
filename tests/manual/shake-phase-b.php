<?php
/**
 * Phase B — Direct enrollment scenarios
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);

shake_reset_editions();
shake_clean_test_users();

// --- B1 ---
shake_section('B1: Self-enroll Open / no reqs → confirmed + LMS access + quote');
wp_set_current_user(7781);
$r = $svc->enroll(7781, 13230);
$id = shake_assert_success($r, 'B1 enroll');
if ($id) {
    $row = $regRepo->find($id);
    echo "  state: " . shake_dump_reg($id) . "\n";
    echo "  expect confirmed + quote_id set: " . ($row->status === 'confirmed' && $row->quote_id ? 'OK' : 'FAIL') . "\n";
}

// --- B2 ---
shake_section('B2: Self-enroll Open / has completion reqs → pending + completion_tasks set');
wp_set_current_user(7782);
$r = $svc->enroll(7782, 13311);
$id = shake_assert_success($r, 'B2 enroll');
if ($id) {
    $row = $regRepo->find($id);
    echo "  state: " . shake_dump_reg($id) . "\n";
    $tasks = is_array($row->completion_tasks) ? $row->completion_tasks : [];
    echo "  expect pending + tasks: " . ($row->status === 'pending' && !empty($tasks) ? 'OK tasks=' . json_encode(array_keys($tasks)) : 'FAIL') . "\n";
}

// --- B3 ---
shake_section('B3: Self-enroll Open / requiresApproval → pending');
wp_set_current_user(7783);
$r = $svc->enroll(7783, 13265);
$id = shake_assert_success($r, 'B3 enroll');
if ($id) {
    $row = $regRepo->find($id);
    echo "  state: " . shake_dump_reg($id) . "\n";
    echo "  expect pending: " . ($row->status === 'pending' ? 'OK' : 'FAIL') . "\n";
}

// --- B4 (already covered in shake-out-enrollment-v2; just sanity-check enrollment_path) ---
shake_section('B4: Colleague enrollment writes correct path + enrolled_by');
wp_set_current_user(7784);
$r = $svc->enroll(7785, 13234, ['enrollment_path' => 'colleague', 'enrolled_by' => 7784]);
$id = shake_assert_success($r, 'B4 colleague enroll (caller 7784 → user 7785)');
if ($id) {
    $row = $regRepo->find($id);
    echo "  state: " . shake_dump_reg($id) . "\n";
    echo "  expect path=colleague + enrolled_by=7784: " . ($row->enrollment_path === 'colleague' && (int)$row->enrolled_by === 7784 ? 'OK' : 'FAIL') . "\n";
}

// --- B5: capacity rejection ---
shake_section('B5: Enroll when edition is at capacity → edition_full');
global $wpdb;
$wpdb->query("DELETE FROM stride_vad_registrations WHERE edition_id = 13240 AND user_id BETWEEN 7100 AND 7199");
$cap = $editionRepo->getField(13240, 'capacity', 0);
$cap = (int) $cap;
echo "  edition 13240 capacity=$cap, filling with $cap dummy rows...\n";
for ($i = 0; $i < $cap; $i++) {
    $wpdb->insert("stride_vad_registrations", [
        'user_id' => 7100 + $i,
        'edition_id' => 13240,
        'status' => 'confirmed',
        'enrollment_path' => 'individual',
        'registered_at' => current_time('mysql'),
    ]);
}
$editionRepo->updateStatus(13240, OfferingStatus::Open);  // force back to Open so we can test the count check
$r = $svc->enroll(7786, 13240);
shake_assert_error($r, 'edition_full', 'B5 over-capacity');
$wpdb->query("DELETE FROM stride_vad_registrations WHERE edition_id = 13240 AND user_id BETWEEN 7100 AND 7199");

// --- B6: already-enrolled guard ---
shake_section('B6: Already-enrolled guard');
$r = $svc->enroll(7781, 13230);
shake_assert_error($r, 'already_enrolled', 'B6 dup enroll same edition');

// --- B-extra1: enroll on Full → edition_full (NOT redirected to waitlist) ---
shake_section('B-extra1: Enroll on Full edition → edition_full');
wp_set_current_user(7786);
$r = $svc->enroll(7786, 13222);
shake_assert_error($r, 'edition_full', 'enroll on Full');

// --- B-extra2: enroll on Announcement → enrollment_closed (NOT redirected to interest) ---
shake_section('B-extra2: Enroll on Announcement edition → enrollment_closed');
$r = $svc->enroll(7786, 13224);
shake_assert_error($r, 'enrollment_closed', 'enroll on Announcement');

// --- B-extra3: enroll on Draft (or any non-Open) ---
shake_section('B-extra3: Enroll on Draft edition → enrollment_closed');
$editionRepo->updateStatus(13234, OfferingStatus::Draft);
$r = $svc->enroll(7786, 13234);
shake_assert_error($r, 'enrollment_closed', 'enroll on Draft');
$editionRepo->updateStatus(13234, OfferingStatus::Open);  // restore

// --- B-extra4: enroll on invalid edition ---
shake_section('B-extra4: Enroll on non-existent edition → invalid_edition');
$r = $svc->enroll(7786, 999999);
shake_assert_error($r, 'invalid_edition', 'enroll on bogus id');

echo "\n=== Phase B complete ===\n";
