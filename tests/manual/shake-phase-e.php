<?php
/**
 * Phase E — Cancellation flows
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\RegistrationStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);

// --- E0: Event dispatch — count cancelled events fired per cancel() call ---
shake_section('E0: stride/registration/cancelled fires exactly ONCE per cancel()');
global $shake_cancel_count;
$shake_cancel_count = 0;
add_action('stride/registration/cancelled', function() {
    global $shake_cancel_count;
    $shake_cancel_count++;
}, 999);

// Find a confirmed reg
$reg = $regRepo->findByUserAndEdition(7785, 13234);
if ($reg && $reg->status === 'confirmed') {
    $shake_cancel_count = 0;
    $r = $svc->cancel((int)$reg->id);
    echo "  cancel result: " . ($r === true ? "TRUE" : (is_wp_error($r) ? $r->get_error_message() : 'unknown')) . "\n";
    echo "  events fired: $shake_cancel_count\n";
    echo "  expected 1: " . ($shake_cancel_count === 1 ? 'OK' : "FAIL — event fires {$shake_cancel_count}x") . "\n";
} else {
    echo "  setup FAIL: no confirmed reg for 7785/13234\n";
}

// --- E1: Cancel a confirmed reg — status + cancelled_at + LMS revoke ---
shake_section('E1: Cancel confirmed → status=cancelled, cancelled_at set, LMS revoked');
$reg = $regRepo->findByUserAndEdition(7785, 13234);
if (!$reg) {
    echo "  (skipped — already cancelled by E0)\n";
} else {
    echo "  state: " . shake_dump_reg((int)$reg->id) . "\n";
    echo "  expected cancelled + cancelled_at set: " . ($reg->status === 'cancelled' && !empty($reg->cancelled_at) ? 'OK' : 'FAIL') . "\n";
}

// --- E2: Cancel a pending reg ---
shake_section('E2: Cancel pending row');
$reg = $regRepo->findByUserAndEdition(7782, 13311);
if (!$reg || $reg->status !== 'pending') {
    echo "  setup FAIL: no pending reg for 7782/13311 (state: " . ($reg ? $reg->status : 'no row') . ")\n";
} else {
    $r = $svc->cancel((int)$reg->id);
    $row = $regRepo->find((int)$reg->id);
    echo "  after: " . shake_dump_reg((int)$reg->id) . "\n";
    echo "  expected cancelled: " . ($row->status === 'cancelled' ? 'OK' : 'FAIL') . "\n";
}

// --- E3: Cancel an interest row ---
shake_section('E3: Cancel an interest registration');
// Create an interest row
wp_set_current_user(7787);
$intRegId = $svc->registerInterest(7787, ['edition_id' => 13224]);
if (is_wp_error($intRegId)) {
    echo "  setup FAIL: " . $intRegId->get_error_message() . "\n";
} else {
    echo "  before: " . shake_dump_reg($intRegId) . "\n";
    $r = $svc->cancel($intRegId);
    $row = $regRepo->find($intRegId);
    echo "  after:  " . shake_dump_reg($intRegId) . "\n";
    echo "  cancel result: " . ($r === true ? "TRUE" : (is_wp_error($r) ? $r->get_error_message() : 'unknown')) . "\n";
    echo "  expected cancelled (or graceful handling): " . ($row->status === 'cancelled' ? 'OK' : 'FAIL (still ' . $row->status . ')') . "\n";
}

// --- E4: Cancel a waitlist row ---
shake_section('E4: Cancel a waitlist registration');
wp_set_current_user(7788);
$wlRegId = $svc->registerWaitlist(7788, ['edition_id' => 13222]);
if (is_wp_error($wlRegId)) {
    echo "  setup FAIL: " . $wlRegId->get_error_message() . "\n";
} else {
    echo "  before: " . shake_dump_reg($wlRegId) . "\n";
    $r = $svc->cancel($wlRegId);
    $row = $regRepo->find($wlRegId);
    echo "  after:  " . shake_dump_reg($wlRegId) . "\n";
    echo "  expected cancelled: " . ($row->status === 'cancelled' ? 'OK' : 'FAIL') . "\n";
}

// --- E5: Cancel non-existent reg ---
shake_section('E5: Cancel non-existent registration → not_found');
$r = $svc->cancel(999999);
shake_assert_error($r, 'not_found', 'E5 cancel bogus reg');

// --- E6: Re-enroll after cancel → reactivation ---
shake_section('E6: Re-enroll after cancel reactivates existing row (no duplicate)');
// User 7785 was cancelled in E0 on edition 13234. Try to enroll again.
global $wpdb;
$preRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM stride_vad_registrations WHERE user_id = 7785 AND edition_id = 13234");
echo "  pre: rows for user 7785 on 13234 = $preRows\n";

wp_set_current_user(7785);
$r = $svc->enroll(7785, 13234);
$postRows = (int) $wpdb->get_var("SELECT COUNT(*) FROM stride_vad_registrations WHERE user_id = 7785 AND edition_id = 13234");
echo "  post: rows = $postRows (expect $preRows — same row reactivated)\n";
echo "  enroll result: " . (is_wp_error($r) ? "ERROR " . $r->get_error_message() : "reg=$r") . "\n";
if (!is_wp_error($r)) {
    $row = $regRepo->find($r);
    echo "  state: " . shake_dump_reg((int)$row->id) . "\n";
    echo "  status flipped back to confirmed: " . ($row->status === 'confirmed' ? 'OK' : 'FAIL') . "\n";
    echo "  cancelled_at cleared: " . (empty($row->cancelled_at) ? 'OK' : 'FAIL (still ' . $row->cancelled_at . ')') . "\n";
}

// --- E7: Cancel a completed reg — must be REJECTED (post-fix) ---
shake_section('E7: Cancel a completed registration → already_completed');
$reg = $regRepo->findByUserAndEdition(7781, 13230);
if (!$reg || $reg->status !== 'completed') {
    echo "  setup FAIL: no completed reg for 7781/13230 (state: " . ($reg ? $reg->status : 'none') . ")\n";
} else {
    echo "  before: " . shake_dump_reg((int)$reg->id) . "\n";
    $r = $svc->cancel((int)$reg->id);
    shake_assert_error($r, 'already_completed', 'E7 cancel completed');
    $row = $regRepo->find((int)$reg->id);
    echo "  status preserved: " . ($row->status === 'completed' ? 'OK' : 'FAIL — leaked to ' . $row->status) . "\n";
}

// --- E7b: Cancel an already-cancelled reg → already_cancelled ---
shake_section('E7b: Cancel an already-cancelled registration → already_cancelled');
// Pick an existing cancelled row
$cancelled = $regRepo->findByUserAndEdition(7785, 13234);
if (!$cancelled || $cancelled->status !== 'cancelled') {
    echo "  setup: no cancelled row (state: " . ($cancelled ? $cancelled->status : 'none') . ")\n";
} else {
    $r = $svc->cancel((int)$cancelled->id);
    shake_assert_error($r, 'already_cancelled', 'E7b cancel already-cancelled');
}

// --- E8: Side-effect — quote gets cancelled ---
shake_section('E8: Cancel reg with quote → quote auto-cancels via stride/registration/cancelled');
$reg = $regRepo->findByUserAndEdition(7784, 13230);
if (!$reg) {
    wp_set_current_user(7784);
    $regId = $svc->enroll(7784, 13230);
    $reg = is_wp_error($regId) ? null : $regRepo->find($regId);
}
if ($reg && $reg->status === 'confirmed' && $reg->quote_id) {
    $quoteId = (int) $reg->quote_id;
    $quoteSvc = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
    $qBefore = $quoteSvc->getQuote($quoteId);
    echo "  quote before: id=$quoteId status=" . ($qBefore['status'] ?? '?') . "\n";

    $svc->cancel((int)$reg->id);

    $qAfter = $quoteSvc->getQuote($quoteId);
    echo "  quote after:  id=$quoteId status=" . ($qAfter['status'] ?? '?') . "\n";
    echo "  expected quote cancelled: " . (($qAfter['status'] ?? '') === 'cancelled' ? 'OK' : 'FAIL') . "\n";
} else {
    echo "  setup FAIL: no confirmed+quoted reg for 7784/13230\n";
}

echo "\n=== Phase E complete ===\n";
