<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Invoicing\Admin\QuoteAdminController;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use lucatume\WPBrowser\WordPress\WPDieException;

/**
 * QuoteAdminController — voucher/discount WIRING SEAM + ajaxGetUserData.
 *
 * Two behaviors, both currently untested:
 *
 * A) The voucher/discount WIRING SEAM (handleVoucherActions, source ~517-537,
 *    called from handleSave at 395). These tests assert the EFFECT on the quote
 *    (the discount actually lands / clears, tax/total re-derive) after driving
 *    the REAL save path — nonce + capability guard + $_POST — NOT merely that a
 *    method exists. The three POST keys route to three distinct effects:
 *      - stride_apply_voucher=<code>   -> QuoteService::applyVoucher   (voucher discount lands)
 *      - stride_apply_discount=<euros> -> applyManualDiscount          (manual discount lands, EUROS in / CENTS stored)
 *      - stride_remove_voucher=1       -> removeDiscount               (discount clears to 0, totals re-derive)
 *
 * B) ajaxGetUserData (source 663-694): nonce `stride_quote_admin` (param `nonce`),
 *    cap `edit_posts`, posted param `user_id`; returns billing/company/address via
 *    wp_send_json_success. Driven through the real AJAX path; the wp_send_json_*
 *    die is captured via a WPDieException-throwing wp_die handler + output buffer
 *    (see captureAjax()). Covers the happy path AND a denial (missing cap).
 *
 * UNIT NOTE: money is stored as CENTS in Stride. The fixture quote (createTestQuote)
 * starts subtotal=10000c (€100), discount=0, status=draft. discount/subtotal/tax/
 * total are QuoteCPT schema fields → read via QuoteService::getQuote($id, true)
 * (skipCache). A Fixed voucher's discount_value is CENTS; applyManualDiscount takes
 * EUROS and stores round($euros*100) cents.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec --raw -- bash -c \
 *   'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit \
 *    -c phpunit-integration.xml.dist --filter QuoteAdminVoucherWiring'
 */
final class QuoteAdminVoucherWiringTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // handleSave()'s current_user_can('edit_post', $id) guard + ajaxGetUserData's
        // current_user_can('edit_posts') both need an admin; the base fixture user is
        // a plain subscriber. Promote it (same pattern as QuoteAdminHandleSaveStatusTest).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    protected function tearDown(): void
    {
        // Defensive: clear any wiring-seam POST keys a test may have left set.
        foreach (['stride_apply_voucher', 'stride_apply_discount', 'stride_remove_voucher',
                  'stride_quote_nonce', 'nonce', 'user_id'] as $k) {
            unset($_POST[$k], $_REQUEST[$k]);
        }
        parent::tearDown();
    }

    private function controller(): QuoteAdminController
    {
        return new QuoteAdminController(
            ntdst_get(QuoteService::class),
            ntdst_get(QuoteRepository::class),
            ntdst_get(VoucherService::class),
            ntdst_get(EditionRepository::class),
        );
    }

    private function quoteService(): QuoteService
    {
        return ntdst_get(QuoteService::class);
    }

    /** Create a REAL draft quote (subtotal 10000c, discount 0). Returns the post id. */
    private function createQuoteFixture(): int
    {
        $editionId = $this->createTestEdition();

        return $this->createTestQuote((int) self::$testUserId, $editionId);
    }

    /**
     * Drive the real save path exactly like QuoteAdminHandleSaveStatusTest::save():
     * set current user, create the nonce AFTER (nonces are user-context-dependent),
     * merge $post into $_POST, invoke handleSave(), then clean up.
     *
     * @param array<string, mixed> $post
     */
    private function save(int $quoteId, array $post): void
    {
        wp_set_current_user((int) self::$testUserId);

        $_POST['stride_quote_nonce'] = wp_create_nonce('stride_save_quote');
        foreach ($post as $key => $value) {
            $_POST[$key] = $value;
        }

        $this->controller()->handleSave($quoteId, get_post($quoteId));

        unset($_POST['stride_quote_nonce']);
        foreach (array_keys($post) as $key) {
            unset($_POST[$key]);
        }
    }

    /** Read a schema field (discount/subtotal/tax/total) as int cents, skipCache. */
    private function field(int $quoteId, string $key): int
    {
        $quote = $this->quoteService()->getQuote($quoteId, true);

        return is_wp_error($quote) ? -1 : (int) ($quote[$key] ?? -1);
    }

    /**
     * Drive an AJAX handler that ends in wp_send_json_*: capture the echoed JSON.
     *
     * wp_send_json() echoes the payload then, when wp_doing_ajax() is true, calls
     * wp_die('', '', ['response'=>null]). We force the ajax path via the
     * `wp_doing_ajax` filter and swap the ajax die handler for one that throws
     * WPDieException, so the process is NOT killed. The echoed JSON is buffered.
     *
     * @return array{payload: array<string,mixed>|null, died: bool}
     */
    private function captureAjax(callable $invoke): array
    {
        $forceAjax = static fn (): bool => true;
        $thrower = static function (): callable {
            return static function (): void {
                throw new WPDieException('');
            };
        };

        add_filter('wp_doing_ajax', $forceAjax);
        add_filter('wp_die_ajax_handler', $thrower);

        $died = false;
        ob_start();
        try {
            $invoke();
        } catch (WPDieException $e) {
            $died = true;
        } finally {
            $json = ob_get_clean();
            remove_filter('wp_doing_ajax', $forceAjax);
            remove_filter('wp_die_ajax_handler', $thrower);
        }

        $payload = $json === '' ? null : json_decode($json, true);

        return ['payload' => is_array($payload) ? $payload : null, 'died' => $died];
    }

    // =====================================================================
    // A) VOUCHER / DISCOUNT WIRING SEAM — assert the EFFECT on the quote
    // =====================================================================

    /**
     * stride_apply_voucher=<code> routes to QuoteService::applyVoucher and the
     * voucher discount actually lands on the quote (un-mocked seam: real
     * VoucherService creates + validates + redeems, real repository persists).
     */
    public function test_apply_voucher_post_key_lands_the_voucher_discount(): void
    {
        $quoteId = $this->createQuoteFixture(); // subtotal 10000c, discount 0

        // Fixed voucher: discount_value is CENTS. €25 off, well under the €100
        // subtotal so it is NOT clamped — the persisted discount must equal 2500.
        $code = 'WIRE' . strtoupper(wp_generate_password(6, false));
        $voucherId = ntdst_get(VoucherService::class)->createVoucher([
            'code'           => $code,
            'discount_type'  => DiscountType::Fixed->value,
            'discount_value' => 2500,
            'usage_limit'    => 1,
        ]);
        $this->assertIsInt($voucherId, 'precondition: voucher fixture must be created');
        self::$testPosts[] = $voucherId;

        $this->assertSame(0, $this->field($quoteId, 'discount'), 'precondition: quote starts at 0 discount');

        $this->save($quoteId, ['stride_apply_voucher' => $code]);

        $this->assertSame(
            2500,
            $this->field($quoteId, 'discount'),
            'stride_apply_voucher must route to applyVoucher and persist the voucher discount (2500c) on the quote',
        );
        // Tax/total re-derive off the discounted base: (10000-2500)*21% = 1575, total 9075.
        $this->assertSame(1575, $this->field($quoteId, 'tax'), 'tax must re-derive off the discounted subtotal');
        $this->assertSame(9075, $this->field($quoteId, 'total'), 'total must re-derive off the discounted subtotal');
    }

    /**
     * stride_apply_discount=<euros> routes to applyManualDiscount, which converts
     * EUROS -> CENTS (round($euros*100)) and persists it.
     */
    public function test_apply_discount_post_key_lands_a_manual_discount_in_cents(): void
    {
        $quoteId = $this->createQuoteFixture(); // subtotal 10000c

        // 30 EUROS in -> 3000 CENTS stored.
        $this->save($quoteId, ['stride_apply_discount' => '30']);

        $this->assertSame(
            3000,
            $this->field($quoteId, 'discount'),
            'stride_apply_discount must route to applyManualDiscount and store €30 as 3000 cents',
        );
        // (10000-3000)*21% = 1470, total 8470.
        $this->assertSame(1470, $this->field($quoteId, 'tax'), 'tax must re-derive after manual discount');
        $this->assertSame(8470, $this->field($quoteId, 'total'), 'total must re-derive after manual discount');
    }

    /**
     * stride_remove_voucher=1 on a quote that HAS a discount routes to
     * removeDiscount: discount returns to 0 and tax/total re-derive off the
     * full subtotal.
     */
    public function test_remove_voucher_post_key_clears_the_discount(): void
    {
        $quoteId = $this->createQuoteFixture();

        // First land a manual discount so there is something to remove.
        $this->save($quoteId, ['stride_apply_discount' => '30']);
        $this->assertSame(3000, $this->field($quoteId, 'discount'), 'precondition: a discount must be present');

        $this->save($quoteId, ['stride_remove_voucher' => '1']);

        $this->assertSame(
            0,
            $this->field($quoteId, 'discount'),
            'stride_remove_voucher must route to removeDiscount and clear the discount to 0',
        );
        // Back to the full-subtotal derivation: 10000*21% = 2100, total 12100.
        $this->assertSame(2100, $this->field($quoteId, 'tax'), 'tax must re-derive off the full subtotal after removal');
        $this->assertSame(12100, $this->field($quoteId, 'total'), 'total must re-derive off the full subtotal after removal');
    }

    // =====================================================================
    // B) ajaxGetUserData — guard + response shape
    // =====================================================================

    /**
     * Happy path: with cap edit_posts + a valid stride_quote_admin nonce + a real
     * user id, ajaxGetUserData returns that user's billing fields.
     */
    public function test_ajax_get_user_data_returns_billing_fields_for_authorized_request(): void
    {
        // A target user with known billing meta.
        $targetId = wp_create_user('vad_target_' . wp_generate_password(6, false), 'pass123',
            'vad_target_' . wp_generate_password(6, false) . '@test.local');
        $this->assertIsInt($targetId, 'precondition: target user must be created');
        update_user_meta($targetId, 'billing_company', 'Acme NV');
        update_user_meta($targetId, 'billing_address_1', 'Kerkstraat 1');
        update_user_meta($targetId, 'billing_city', 'Gent');
        update_user_meta($targetId, 'organisation', 'Acme Dept');

        // Current user is admin (setUp) → has edit_posts. Nonce AFTER user is set.
        // check_ajax_referer reads the nonce from $_REQUEST['nonce'], so set that too.
        wp_set_current_user((int) self::$testUserId);
        $nonce = wp_create_nonce('stride_quote_admin');
        $_POST['nonce'] = $_REQUEST['nonce'] = $nonce;
        $_POST['user_id'] = $_REQUEST['user_id'] = $targetId;

        $result = $this->captureAjax(fn () => $this->controller()->ajaxGetUserData());

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($targetId);

        $this->assertTrue($result['died'], 'wp_send_json must terminate via the (captured) die');
        $this->assertIsArray($result['payload'], 'a JSON payload must have been emitted');
        $this->assertTrue($result['payload']['success'] ?? false, 'authorized request must be a success response');
        $data = $result['payload']['data'] ?? [];
        $this->assertSame('Acme NV', $data['company'] ?? null, 'must return the target user billing_company');
        $this->assertSame('Kerkstraat 1', $data['address'] ?? null, 'must return the target user billing_address_1');
        $this->assertSame('Gent', $data['city'] ?? null, 'must return the target user billing_city');
        $this->assertSame('Acme Dept', $data['organisation'] ?? null, 'must return the target user organisation');
    }

    /**
     * DENIAL: a user lacking edit_posts (subscriber) hits the cap guard and gets
     * the Unauthorized error path — NOT the user data. (Nonce is valid so we
     * isolate the CAPABILITY denial specifically.)
     */
    public function test_ajax_get_user_data_denies_user_without_edit_posts(): void
    {
        $targetId = wp_create_user('vad_target2_' . wp_generate_password(6, false), 'pass123',
            'vad_target2_' . wp_generate_password(6, false) . '@test.local');
        update_user_meta($targetId, 'billing_company', 'Secret NV');

        // A subscriber has no edit_posts capability.
        $subId = wp_create_user('vad_sub_' . wp_generate_password(6, false), 'pass123',
            'vad_sub_' . wp_generate_password(6, false) . '@test.local');
        wp_set_current_user($subId);
        wp_get_current_user()->set_role('subscriber');

        // Valid nonce for THIS user so the request fails on the cap guard, not the nonce.
        // (nonce read from $_REQUEST['nonce'] by check_ajax_referer.)
        $nonce = wp_create_nonce('stride_quote_admin');
        $_POST['nonce'] = $_REQUEST['nonce'] = $nonce;
        $_POST['user_id'] = $_REQUEST['user_id'] = $targetId;

        $result = $this->captureAjax(fn () => $this->controller()->ajaxGetUserData());

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($targetId);
        wp_delete_user($subId);

        $this->assertTrue($result['died'], 'denial must still terminate via the (captured) die');
        $this->assertIsArray($result['payload'], 'a JSON payload must have been emitted');
        $this->assertFalse($result['payload']['success'] ?? true, 'a user without edit_posts must get an error response');
        // The denial must NOT leak the target user billing data.
        $data = $result['payload']['data'] ?? [];
        $this->assertArrayNotHasKey('company', $data, 'the denial path must NOT return the target user billing data');
    }
}
