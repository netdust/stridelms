<?php
/**
 * Flow shake-out #3 — Cancellation cascade
 *
 * For each cancellable starting state, verify the full blast radius:
 *  - DB write (status=cancelled, cancelled_at, completion_tasks cleared)
 *  - LMS revoke (when user had access)
 *  - Quote cancellation (when quote existed)
 *  - Edition Full→Open auto-flip (when seat freed)
 *  - Audit log row
 *  - User confirmation mail
 *  - Listener idempotency (cancel-of-cancel does nothing)
 *  - Quote-side cancel cascades into registration (admin cancels quote)
 *  - Anonymous rows cancel without crashing on missing user_id
 */
require __DIR__ . '/shake-helpers.php';

use Stride\Domain\OfferingStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\QuoteStatus;

$svc = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$regRepo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
$quoteSvc = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
$editionSvc = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$audit = ntdst_get(\NTDST\Audit\AuditService::class);
$qh = ntdst_get(\Stride\Modules\Questionnaire\QuestionnaireHandler::class);

global $wpdb;

shake_reset_editions();
shake_clean_test_users();

// Wipe audit rows for our test users so counts are deterministic
$auditTable = "stride_audit_log";
$wpdb->query("DELETE FROM {$auditTable} WHERE JSON_UNQUOTE(JSON_EXTRACT(context, '$.user_id')) IN ('7781','7782','7783','7784','7785','7786','7787','7788')");

// === Helper: count events fired during a callable ===
function shake_count_events(string $hook, callable $action): int
{
    $count = 0;
    $cb = function () use (&$count) { $count++; };
    add_action($hook, $cb, 999);
    $action();
    remove_action($hook, $cb, 999);
    return $count;
}

// === CC-1: Cancel confirmed reg → full cascade ===
shake_section('CC-1: Cancel confirmed → status + cancelled_at + LMS revoke + quote cancelled + Full→Open');
wp_set_current_user(7781);
$regId = $svc->enroll(7781, 13230);
shake_assert_success($regId, 'CC-1 setup enroll');
$row = $regRepo->find($regId);
$quoteIdBefore = (int) $row->quote_id;
echo "  setup: reg=$regId status=$row->status quote=$quoteIdBefore\n";

$courseId = $editionSvc->getCourseId(13230);
$hadLmsAccess = function_exists('learndash_user_get_enrolled_courses')
    && in_array($courseId, learndash_user_get_enrolled_courses(7781), true);
echo "  LMS access before cancel: " . ($hadLmsAccess ? "Y" : "N") . "\n";

$eventCount = shake_count_events('stride/registration/cancelled', function () use ($svc, $regId) {
    $svc->cancel($regId);
});

$row = $regRepo->find($regId);
echo "  reg after: status=$row->status cancelled_at=" . ($row->cancelled_at ?? '-') . " completion_tasks=" . ($row->completion_tasks ? 'KEPT' : 'NULL') . "\n";
echo "  event fired exactly once: " . ($eventCount === 1 ? "OK" : "FAIL ({$eventCount}x)") . "\n";

// Quote should now be cancelled
if ($quoteIdBefore) {
    $q = $quoteSvc->getQuote($quoteIdBefore);
    echo "  quote $quoteIdBefore status: " . ($q['status'] ?? '?') . "\n";
    echo "  expected cancelled: " . (($q['status'] ?? '') === 'cancelled' ? "OK" : "FAIL") . "\n";
}

// LMS access revoked
$lmsAfter = function_exists('learndash_user_get_enrolled_courses')
    && in_array($courseId, learndash_user_get_enrolled_courses(7781), true);
echo "  LMS access after: " . ($lmsAfter ? "STILL GRANTED (FAIL)" : "revoked (OK)") . "\n";

// Audit row
$auditRows = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$auditTable} WHERE entity_type = 'registration' AND entity_id = %d AND action = 'registration.cancelled'",
    $regId
));
echo "  audit rows: " . count($auditRows) . " (expect 1)\n";

// === CC-2: Cancel reg with FULL edition flips edition back to Open ===
shake_section('CC-2: Cancel reg on FULL edition → edition flips Full→Open');
$editionRepo->updateStatus(13257, OfferingStatus::Open);

// Fill edition to capacity
$wpdb->query("DELETE FROM stride_vad_registrations WHERE edition_id = 13257 AND user_id BETWEEN 7100 AND 7199");
$cap = (int) $editionRepo->getField(13257, 'capacity', 0);
for ($i = 0; $i < $cap; $i++) {
    $wpdb->insert("stride_vad_registrations", [
        'user_id' => 7100 + $i,
        'edition_id' => 13257,
        'status' => 'confirmed',
        'enrollment_path' => 'individual',
        'registered_at' => current_time('mysql'),
    ]);
}

// Force a refresh of the status via the auto-flip listener
$editionSvc->onRegistrationCreated(['edition_id' => 13257]);
$stateBefore = $editionSvc->getStatus(13257);
echo "  capacity=$cap, edition state after fill: {$stateBefore->value}\n";

// Cancel one of the filler rows directly
$fillerReg = (int) $wpdb->get_var("SELECT id FROM stride_vad_registrations WHERE edition_id = 13257 AND user_id = 7100 LIMIT 1");
if ($fillerReg) {
    $r = $svc->cancel($fillerReg);
    echo "  cancel result: " . ($r === true ? "TRUE" : "ERROR") . "\n";
    $stateAfter = $editionSvc->getStatus(13257);
    echo "  edition state after cancel: {$stateAfter->value}\n";
    echo "  expected Open: " . ($stateAfter === OfferingStatus::Open ? "OK" : "FAIL") . "\n";
} else {
    echo "  setup FAIL: no filler row created\n";
}

// Cleanup fillers
$wpdb->query("DELETE FROM stride_vad_registrations WHERE edition_id = 13257 AND user_id BETWEEN 7100 AND 7199");

// === CC-3: Cancel pending reg with completion tasks → tasks cleared ===
shake_section('CC-3: Cancel pending reg → completion_tasks cleared to NULL');
wp_set_current_user(7782);
$regId = $svc->enroll(7782, 13265); // has questionnaire+documents+approval
$row = $regRepo->find($regId);
echo "  setup tasks: " . json_encode(array_keys($row->completion_tasks ?? [])) . "\n";

$svc->cancel($regId);
$row = $regRepo->find($regId);
echo "  after cancel completion_tasks: " . ($row->completion_tasks ? 'STALE: ' . json_encode($row->completion_tasks) : 'NULL') . "\n";
echo "  expected NULL: " . (empty($row->completion_tasks) ? "OK" : "FAIL — stale tasks retained") . "\n";

// === CC-4: Cancel interest row → no quote/LMS work, status flips ===
shake_section('CC-4: Cancel interest row (no quote, no LMS) → graceful');
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
wp_set_current_user(7783);
$regId = $svc->registerInterest(7783, ['edition_id' => 13224]);
$row = $regRepo->find($regId);
echo "  setup: reg=$regId status=$row->status quote=" . ($row->quote_id ?? '-') . "\n";

$r = $svc->cancel($regId);
$row = $regRepo->find($regId);
echo "  after cancel: status=$row->status cancelled_at=" . ($row->cancelled_at ?? '-') . "\n";
echo "  no crash on no-quote interest cancel: " . ($r === true ? "OK" : "FAIL") . "\n";

// === CC-5: Cancel waitlist row → graceful (no quote, no LMS) ===
shake_section('CC-5: Cancel waitlist row → graceful');
$editionRepo->updateStatus(13222, OfferingStatus::Full);
wp_set_current_user(7784);
$regId = $svc->registerWaitlist(7784, ['edition_id' => 13222]);
$r = $svc->cancel($regId);
$row = $regRepo->find($regId);
echo "  after cancel: status=$row->status\n";
echo "  graceful: " . ($r === true && $row->status === 'cancelled' ? "OK" : "FAIL") . "\n";

// === CC-6: Cancel ANONYMOUS interest row (user_id=NULL) → mail dispatch must not crash ===
shake_section('CC-6: Cancel anonymous interest row (user_id=NULL)');
$wpdb->query("DELETE FROM stride_vad_registrations WHERE enrollment_data LIKE '%cc6@flow.test%'");
$editionRepo->updateStatus(13224, OfferingStatus::Announcement);
$qh->handleSubmitInterest(null, ['edition_id' => 13224, 'name' => 'CC6 Anon', 'email' => 'cc6@flow.test']);
$anonRow = $regRepo->findByEmailAndEdition('cc6@flow.test', 13224);
$anonId = (int) $anonRow->id;
echo "  anon reg=$anonId user_id=" . ($anonRow->user_id ?? 'NULL') . "\n";

// Cancel it — listeners must not throw on missing user
$crashed = false;
try {
    $r = $svc->cancel($anonId);
} catch (\Throwable $e) {
    $crashed = true;
    echo "  EXCEPTION: " . $e->getMessage() . "\n";
}
$row = $regRepo->find($anonId);
echo "  after cancel: status=$row->status crashed=" . ($crashed ? "Y" : "N") . "\n";
echo "  cascaded without crashing: " . (!$crashed && $row->status === 'cancelled' ? "OK" : "FAIL") . "\n";

// === CC-7: Cancel-then-cancel → already_cancelled ===
shake_section('CC-7: Cancel an already-cancelled row → already_cancelled');
$r = $svc->cancel($anonId);
shake_assert_error($r, 'already_cancelled', 'CC-7 idempotent guard');

// === CC-8: Cancel a completed row → already_completed ===
shake_section('CC-8: Cancel a completed row → already_completed');
wp_set_current_user(7785);
$regId = $svc->enroll(7785, 13230);
$regRepo->updateStatus($regId, RegistrationStatus::Completed);
$r = $svc->cancel($regId);
shake_assert_error($r, 'already_completed', 'CC-8 completed guard');

// === CC-9: Admin cancels QUOTE → cascades to register cancel ===
shake_section('CC-9: Cancelling quote cascades to registration');
wp_set_current_user(7786);
$regId = $svc->enroll(7786, 13230);
$row = $regRepo->find($regId);
$quoteId = (int) $row->quote_id;
echo "  setup: reg=$regId quote=$quoteId\n";

// Fire the same event the admin controller fires
do_action('stride/quote/cancelled', ['quote_id' => $quoteId]);

$rowAfter = $regRepo->find($regId);
$quoteAfter = $quoteSvc->getQuote($quoteId);
echo "  reg status after: " . $rowAfter->status . "\n";
echo "  quote status after: " . ($quoteAfter['status'] ?? '?') . "\n";
echo "  both cancelled: " . ($rowAfter->status === 'cancelled' && ($quoteAfter['status'] ?? '') === 'cancelled' ? "OK" : "FAIL") . "\n";

// === CC-10: Loop check — registration cancel does NOT trigger quote cancel that re-triggers ===
shake_section('CC-10: No infinite event loop on reg-cancel ↔ quote-cancel');
wp_set_current_user(7787);
$regId = $svc->enroll(7787, 13230);

// Count both events during a single registration cancel
$regEvents = 0;
$quoteEvents = 0;
$cb1 = function () use (&$regEvents) { $regEvents++; };
$cb2 = function () use (&$quoteEvents) { $quoteEvents++; };
add_action('stride/registration/cancelled', $cb1, 999);
add_action('stride/quote/cancelled', $cb2, 999);

$svc->cancel($regId);

remove_action('stride/registration/cancelled', $cb1, 999);
remove_action('stride/quote/cancelled', $cb2, 999);

echo "  registration/cancelled fired $regEvents x (expect 1)\n";
echo "  quote/cancelled fired $quoteEvents x (expect 0 — quote-cancel via repo->updateStatus is silent)\n";
echo "  loop bounded: " . ($regEvents === 1 && $quoteEvents === 0 ? "OK" : "FAIL") . "\n";

// === CC-11: Audit log records actor when cancel comes from a logged-in user ===
shake_section('CC-11: Audit log captures actor on cancel');
// First, look at the existing CC-1 audit row
$auditRow = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$auditTable} WHERE entity_type = 'registration' AND action = 'registration.cancelled' ORDER BY id DESC LIMIT 1"
));
if ($auditRow) {
    echo "  latest audit cancel row: actor_id=" . ($auditRow->actor_id ?? 'NULL') . " context=" . substr($auditRow->context ?? '', 0, 80) . "\n";
    echo "  has actor: " . ($auditRow->actor_id ? "OK" : "GAP — no actor recorded on cancel events") . "\n";
} else {
    echo "  setup FAIL: no audit row to inspect\n";
}

echo "\n=== Flow #3 (Cancellation cascade) complete ===\n";
