<?php
/**
 * Acceptance fixture — Enroll + Voucher → Session Selection flow.
 *
 * Backend-seeds the enroll+voucher half of the flow through the REAL services
 * (not mocks), so the Playwright spec can browser-drive the session-selection
 * UI + edges. Asserts the voucher actually discounted the quote in the DB.
 *
 * Run:  ddev exec wp eval-file tests/frontend/enrollment/fixtures/seed-session-selection-flow.php
 * Reset: pass STRIDE_RESET=1 to delete the registration first (idempotent re-seed).
 *
 * Emits a single JSON line on stdout the spec reads:
 *   {"registration_id":N,"edition_id":24927,"course_slug":"...","slot":"...","slot_session_ids":[...],"quote_id":N,"voucher_applied":true}
 */

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Domain\Money;

$USER_ID  = (function(){ $u=get_user_by('email','student1@seed.test')?:get_user_by('email','seed_student1@seed.test'); return $u?$u->ID:0; })(); // resolve dynamically — seed IDs drift
$EDITION  = 24927;                // edition with slot "Verdieping (kies 1)"
$VOUCHER  = 'KORTING50';          // % voucher
$reset    = getenv('STRIDE_RESET') === '1';

global $wpdb;
$regTable = $wpdb->prefix . 'vad_registrations';

// --- idempotency: optionally clear a prior run ---------------------------------
if ($reset) {
    $wpdb->delete($regTable, ['user_id' => $USER_ID, 'edition_id' => $EDITION]);
}

// --- reuse existing reg if present, else enroll through the real service --------
$existing = $wpdb->get_row($wpdb->prepare(
    "SELECT id, quote_id FROM $regTable WHERE user_id=%d AND edition_id=%d",
    $USER_ID, $EDITION
), ARRAY_A);

if ($existing) {
    $registrationId = (int) $existing['id'];
    $quoteId        = (int) $existing['quote_id'];
} else {
    /** @var EnrollmentService $enroll */
    $enroll = ntdst_get(EnrollmentService::class);
    $result = $enroll->processEnrollment([
        'user_id'         => $USER_ID,
        'edition_id'      => $EDITION,
        'enrollment_type' => 'self',
        'first_name'      => 'Seed', 'last_name' => 'Student',
        'email'           => 'student1@seed.test',
        'voucher_code'    => $VOUCHER,
        'selected_sessions' => [],   // left empty ON PURPOSE — the browser picks the slot
        'terms_accepted'  => true,
    ]);
    if (is_wp_error($result)) {
        fwrite(STDERR, 'ENROLL FAILED: ' . $result->get_error_message() . "\n");
        exit(1);
    }
    $registrationId = (int) $result['registration_id'];
    $quoteId        = (int) ($result['quote_id'] ?? 0);

    // processEnrollment (called directly, not via the AJAX handler) does not run
    // the handler's quote-creation step. Replicate it here the way
    // EnrollmentFormHandler::createQuote does, so the voucher half is real.
    if ($quoteId === 0) {
        /** @var EditionService $editions */
        $editions = ntdst_get(EditionService::class);
        /** @var VoucherService $vouchers */
        $vouchers = ntdst_get(VoucherService::class);
        /** @var QuoteService $quotes */
        $quotes = ntdst_get(QuoteService::class);

        $price = $editions->getPrice($EDITION); // Money
        $items = [[
            'title'      => get_the_title($EDITION),
            'quantity'   => 1,
            'unit_price' => $price, // Money, per QuoteCalculator::calculateTotals
        ]];

        $discount = null;
        $voucher  = $vouchers->validateVoucher($VOUCHER, $EDITION);
        if (!is_wp_error($voucher)) {
            $discount = $vouchers->calculateDiscount($voucher, $price, $EDITION);
        }

        $created = $quotes->createQuote(
            $USER_ID, $registrationId, $EDITION, $items, [], $VOUCHER, $discount
        );
        if (!is_wp_error($created)) {
            $quoteId = (int) $created;
        }
    }
}

// --- DATA CHECK: did the voucher actually discount the quote? -------------------
// Read through QuoteService (Data API), NOT raw _ntdst_* meta — the Data API
// stores bare keys (subtotal/discount/total), the prefix is an internal detail.
$voucherApplied = false;
$discountInfo   = 'no-quote';
if ($quoteId > 0) {
    $q = ntdst_get(QuoteService::class)->getQuoteByRegistration($registrationId);
    if ($q) {
        $subtotal = (int) ($q['subtotal'] ?? 0);
        $discount = (int) ($q['discount'] ?? 0);
        $total    = (int) ($q['total'] ?? 0);
        $voucherApplied = $discount > 0 && ($q['voucher_code'] ?? '') !== '';
        $discountInfo = "subtotal={$subtotal} discount={$discount} total={$total} voucher={$q['voucher_code']}";
    }
}

// --- gather slot session ids the browser will choose among ---------------------
$slotConfig = (function () use ($EDITION) {
    /** @var SessionSelection $s */
    $s = ntdst_get(SessionSelection::class);
    return $s->getSlotConfig($EDITION);
})();
$slotName = $slotConfig[0]['slot'] ?? '';

$sessions = new WP_Query([
    'post_type' => 'vad_session', 'posts_per_page' => -1, 'fields' => 'ids',
    'post_status' => 'any',
    'meta_key' => '_ntdst_edition_id', 'meta_value' => $EDITION,
]);
$slotSessionIds = [];
foreach ($sessions->posts as $sid) {
    if (get_post_meta($sid, '_ntdst_slot', true) === $slotName) {
        $slotSessionIds[] = (int) $sid;
    }
}

$course      = get_post((int) get_post_meta($EDITION, '_ntdst_course_id', true));
$editionPost = get_post($EDITION);

fwrite(STDERR, "FIXTURE OK reg=$registrationId quote=$quoteId voucher_applied="
    . ($voucherApplied ? 'YES' : 'NO') . " ($discountInfo)\n");

echo wp_json_encode([
    'user_id'         => $USER_ID,
    'registration_id' => $registrationId,
    'edition_id'      => $EDITION,
    // Completion page is routed by EDITION slug: /edities/{slug}/voltooien/
    'edition_slug'    => $editionPost ? $editionPost->post_name : '',
    'course_slug'     => $course ? $course->post_name : '',
    'slot'            => $slotName,
    'slot_session_ids'=> $slotSessionIds,
    'quote_id'        => $quoteId,
    'voucher_applied' => $voucherApplied,
]) . "\n";
