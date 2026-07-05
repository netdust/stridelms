<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Domain\VoucherStatus;
use Stride\Modules\Invoicing\Admin\VoucherAdminController;
use Stride\Modules\Invoicing\VoucherRepository;
use Stride\Modules\Invoicing\VoucherService;

/**
 * Characterization + regression guard for VoucherAdminController::handleSave()
 * — the WHOLE voucher admin write surface (vouchers have NO AJAX handler; this
 * `save_post_vad_voucher` hook is the only write path). Driven through the REAL
 * admin save path (nonce + edit_post cap + $_POST['ntdst_fields']), read back
 * through the repository.
 *
 * Pins, against the source (VoucherAdminController.php lines cited inline):
 *  - V-1 DISCOUNT CENTS (the load-bearing off-by-100 guard, lines 494-504):
 *      fixed + 25.50 euros -> 2550 cents; percentage + 15 -> 15 as-is; and the
 *      SAME-SAVE type switch (line 496 reads the newly-posted type, not a stale
 *      one).
 *  - CODE uppercase+trim (line 459) AND post_title mirror (lines 464-468).
 *  - STATUS enum denial (line 476 VoucherStatus::tryFrom).
 *  - DISCOUNT_TYPE enum denial (line 489 DiscountType::tryFrom).
 *  - USAGE_LIMIT absint incl. 0 = unlimited (line 483).
 *  - SCOPE_MODE conditional matrix (lines 514-532): only/except/all persistence,
 *    the clearing transitions (only->all zeroes edition_id; except->all clears
 *    excluded), and the invalid-scope default to 'all' (line 516 in_array guard).
 *  - APPLY_MODE valid persists / invalid defaults to 'full' (line 536).
 *  - GUARD denials: bad nonce and empty ntdst_fields write nothing (lines 435, 451).
 *
 * READ-BACK: getField() delegates to the model's getMeta(), which resolves the
 * '_ntdst_' prefix internally — pass BARE field names. discount_value / edition_id
 * / usage_limit are `type => 'int'` (read back as int); excluded_edition_ids is
 * `type => 'json'` (read back as array). Never hardcode the meta prefix.
 *
 * Run (disposable-DB gate forwarded via --raw; a silent exit 255 = a swallowed
 * PHP fatal, not "no tests matched"):
 *   ddev exec --raw -- bash -c 'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist \
 *     --filter VoucherAdminHandleSave'
 */
final class VoucherAdminHandleSaveTest extends IntegrationTestCase
{
    private VoucherRepository $repo;
    private int $voucherId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = ntdst_get(VoucherRepository::class);

        // handleSave()'s current_user_can('edit_post', $id) guard resolves via
        // map_meta_cap for the vad_voucher CPT — the base fixture user is a plain
        // subscriber, so promote it to administrator (same pattern as every other
        // admin-save integration test, e.g. QuoteAdminHandleSaveStatusTest).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');

        // A clean voucher post with baseline _ntdst_ meta, tracked in
        // self::$testPosts (base class cleans it up in tearDownAfterClass).
        $this->voucherId = $this->createTestVoucher();
    }

    private function controller(): VoucherAdminController
    {
        return new VoucherAdminController(
            ntdst_get(VoucherService::class),
            ntdst_get(VoucherRepository::class),
        );
    }

    /**
     * Drive the real admin save path. Creates the nonce AS the current user
     * (nonces are user-context-dependent), sets $_POST['ntdst_fields'], invokes
     * handleSave(), then cleans up $_POST.
     *
     * @param array<string, mixed> $fields Value for $_POST['ntdst_fields']
     * @param bool                 $withNonce Set false to simulate a bad/missing nonce.
     */
    private function save(array $fields, bool $withNonce = true): void
    {
        wp_set_current_user((int) self::$testUserId);

        if ($withNonce) {
            $_POST[VoucherAdminController::NONCE_FIELD] =
                wp_create_nonce(VoucherAdminController::NONCE_SAVE);
        }
        $_POST['ntdst_fields'] = $fields;

        // Re-fetch the post each time — the code save path mutates post_title.
        $this->controller()->handleSave($this->voucherId, get_post($this->voucherId));

        unset($_POST[VoucherAdminController::NONCE_FIELD], $_POST['ntdst_fields']);
    }

    /**
     * Read a stored voucher field back through the repository. NOT named status()
     * — that collides with PHPUnit\Framework\TestCase::status() (a `final` method),
     * which fatals at class-compile time (silent exit 255). Vouchers HAVE a status
     * field, hence the deliberately-different name.
     */
    private function field(string $name, mixed $default = null): mixed
    {
        return $this->repo->getField($this->voucherId, $name, $default);
    }

    // ---------------------------------------------------------------
    // V-1. DISCOUNT VALUE — euros->cents for fixed, as-is for percentage
    //      (the load-bearing off-by-100 guard, lines 494-504)
    // ---------------------------------------------------------------

    public function testFixedDiscountValueIsStoredAsCents(): void
    {
        $this->save([
            'discount_type'  => DiscountType::Fixed->value,
            'discount_value' => '25.50',
        ]);

        $this->assertSame(
            2550,
            (int) $this->field('discount_value'),
            'fixed + 25.50 euros must persist as 2550 cents ((int) round(x*100), line 500)',
        );
    }

    public function testPercentageDiscountValueIsStoredAsIs(): void
    {
        $this->save([
            'discount_type'  => DiscountType::Percentage->value,
            'discount_value' => '15',
        ]);

        $this->assertSame(
            15,
            (int) $this->field('discount_value'),
            'percentage + 15 must persist as 15 (NOT 1500) — value stored as-is (line 502)',
        );
    }

    /**
     * The type-switch-in-same-save contract: line 496 reads the newly-posted
     * discount_type (from $updateData, set at line 490) rather than a stale one.
     * Posting percentage + 20 in one save must store 20 (NOT 2000); posting
     * fixed + 20 in one save must store 2000 — same numeric input, opposite
     * treatment, proving the type is read from THIS save.
     */
    public function testDiscountTypeSwitchInSameSaveDrivesConversion(): void
    {
        // Same value (20), percentage first: stored as-is.
        $this->save([
            'discount_type'  => DiscountType::Percentage->value,
            'discount_value' => '20',
        ]);
        $this->assertSame(
            20,
            (int) $this->field('discount_value'),
            'percentage + 20 in one save must store 20 (line 496 picks up percentage, not fixed)',
        );

        // Now switch to fixed in one save with the same 20 -> x100.
        $this->save([
            'discount_type'  => DiscountType::Fixed->value,
            'discount_value' => '20',
        ]);
        $this->assertSame(
            2000,
            (int) $this->field('discount_value'),
            'fixed + 20 in one save must store 2000 (line 496 picks up the newly-posted fixed type)',
        );
    }

    // ---------------------------------------------------------------
    // CODE — uppercase+trim, and post_title mirror
    // ---------------------------------------------------------------

    public function testCodeIsUppercasedAndMirroredToPostTitle(): void
    {
        $this->save(['code' => '  summer25 ']);

        $this->assertSame(
            'SUMMER25',
            $this->field('code'),
            "code=' summer25 ' must persist uppercased+trimmed as 'SUMMER25' (line 459)",
        );
        $this->assertSame(
            'SUMMER25',
            get_post($this->voucherId)->post_title,
            'post_title must mirror the uppercased code (lines 464-468) — end state after the '
            . 'handler removes/re-adds its own save hook around wp_update_post',
        );
    }

    // ---------------------------------------------------------------
    // STATUS — valid persists, invalid rejected (enum denial, line 476)
    // ---------------------------------------------------------------

    public function testValidStatusPersists(): void
    {
        $this->save(['status' => VoucherStatus::Disabled->value]);

        $this->assertSame(
            VoucherStatus::Disabled->value,
            $this->field('status'),
            'a valid status (disabled) must persist (VoucherStatus::tryFrom guard, line 476)',
        );
    }

    public function testInvalidStatusIsRejected(): void
    {
        // Establish a known-good baseline so we prove the garbage is REJECTED,
        // not merely absent (a fresh voucher would read the schema default).
        $this->save(['status' => VoucherStatus::Disabled->value]);
        $this->assertSame(VoucherStatus::Disabled->value, $this->field('status'), 'baseline status');

        $this->save(['status' => 'definitely_not_a_status']);

        $this->assertSame(
            VoucherStatus::Disabled->value,
            $this->field('status'),
            'an invalid status must be rejected (tryFrom fails, line 476) — prior value untouched, '
            . 'garbage never stored',
        );
    }

    // ---------------------------------------------------------------
    // DISCOUNT_TYPE — valid persists, invalid rejected (enum denial, line 489)
    // ---------------------------------------------------------------

    public function testInvalidDiscountTypeIsRejected(): void
    {
        // Baseline: a valid, known discount_type.
        $this->save(['discount_type' => DiscountType::Percentage->value]);
        $this->assertSame(
            DiscountType::Percentage->value,
            $this->field('discount_type'),
            'baseline discount_type',
        );

        $this->save(['discount_type' => 'definitely_not_a_type']);

        $this->assertSame(
            DiscountType::Percentage->value,
            $this->field('discount_type'),
            'an invalid discount_type must be rejected (tryFrom fails, line 489) — prior value untouched',
        );
    }

    // ---------------------------------------------------------------
    // USAGE_LIMIT — absint incl. 0 = unlimited (line 483)
    // ---------------------------------------------------------------

    public function testUsageLimitZeroPersistsAsUnlimited(): void
    {
        // Start from a positive limit so we prove 0 is a deliberate, stored value.
        $this->save(['usage_limit' => '5']);
        $this->assertSame(5, (int) $this->field('usage_limit'), 'precondition: usage_limit=5');

        $this->save(['usage_limit' => '0']);

        $this->assertSame(
            0,
            (int) $this->field('usage_limit'),
            'usage_limit=0 must persist as 0 (= unlimited per domain), via absint (line 483)',
        );
    }

    // ---------------------------------------------------------------
    // SCOPE_MODE matrix (lines 514-532) — the richest untested surface
    // ---------------------------------------------------------------

    public function testScopeOnlyStoresEditionIdAndClearsExcluded(): void
    {
        $editionId = $this->createTestEdition();

        $this->save([
            'scope_mode' => 'only',
            'edition_id' => (string) $editionId,
            // Posting excluded ids too proves they are IGNORED in 'only' mode.
            'excluded_edition_ids' => ['1', '2'],
        ]);

        $this->assertSame('only', $this->field('scope_mode'), "scope_mode='only' must persist");
        $this->assertSame(
            $editionId,
            (int) $this->field('edition_id'),
            "scope='only' must store the posted edition_id (line 522-525)",
        );
        $this->assertSame(
            [],
            (array) $this->field('excluded_edition_ids', []),
            "scope='only' must leave excluded_edition_ids EMPTY (line 528-532 only fills it for 'except')",
        );
    }

    public function testScopeExceptStoresExcludedAndZeroesEditionId(): void
    {
        $this->save([
            'scope_mode' => 'except',
            'excluded_edition_ids' => ['1', '2'],
            // Posting edition_id too proves it is IGNORED in 'except' mode.
            'edition_id' => '999',
        ]);

        $this->assertSame('except', $this->field('scope_mode'), "scope_mode='except' must persist");
        $this->assertSame(
            [1, 2],
            array_map('intval', (array) $this->field('excluded_edition_ids', [])),
            "scope='except' must store the posted excluded ids (line 529-532)",
        );
        $this->assertSame(
            0,
            (int) $this->field('edition_id'),
            "scope='except' must zero edition_id (line 522: only 'only' mode keeps it)",
        );
    }

    /**
     * The clearing transition: only -> all must zero the now-irrelevant
     * edition_id (line 522 evaluates false for 'all', so edition_id := 0).
     */
    public function testScopeAllClearsPreviouslySetEditionId(): void
    {
        $editionId = $this->createTestEdition();

        $this->save(['scope_mode' => 'only', 'edition_id' => (string) $editionId]);
        $this->assertSame($editionId, (int) $this->field('edition_id'), 'precondition: edition_id set under only');

        // Switch to 'all' — edition_id is now irrelevant and must be cleared.
        $this->save(['scope_mode' => 'all']);

        $this->assertSame('all', $this->field('scope_mode'), "scope_mode='all' must persist");
        $this->assertSame(
            0,
            (int) $this->field('edition_id'),
            "switching scope only->all must reset edition_id to 0 (line 522 clearing)",
        );
    }

    /**
     * The clearing transition for the other branch: except -> all must clear the
     * now-irrelevant excluded_edition_ids (line 528 keeps them EMPTY for 'all').
     */
    public function testScopeAllClearsPreviouslySetExcludedIds(): void
    {
        $this->save(['scope_mode' => 'except', 'excluded_edition_ids' => ['3', '4']]);
        $this->assertSame(
            [3, 4],
            array_map('intval', (array) $this->field('excluded_edition_ids', [])),
            'precondition: excluded ids set under except',
        );

        $this->save(['scope_mode' => 'all']);

        $this->assertSame('all', $this->field('scope_mode'), "scope_mode='all' must persist");
        $this->assertSame(
            [],
            (array) $this->field('excluded_edition_ids', []),
            "switching scope except->all must clear excluded_edition_ids (line 528 keeps it empty for 'all')",
        );
    }

    /**
     * An invalid scope_mode value defaults to 'all' (line 516 in_array guard) —
     * which, as a side effect, also zeroes edition_id and excluded ids.
     */
    public function testInvalidScopeModeDefaultsToAll(): void
    {
        $this->save(['scope_mode' => 'not_a_scope']);

        $this->assertSame(
            'all',
            $this->field('scope_mode'),
            "an invalid scope_mode must default to 'all' (line 516 in_array guard)",
        );
    }

    // ---------------------------------------------------------------
    // APPLY_MODE — valid persists, invalid defaults to 'full' (line 536)
    // ---------------------------------------------------------------

    public function testValidApplyModePersists(): void
    {
        $this->save(['apply_mode' => 'single_session']);

        $this->assertSame(
            'single_session',
            $this->field('apply_mode'),
            "a valid apply_mode ('single_session') must persist (line 536)",
        );
    }

    public function testInvalidApplyModeDefaultsToFull(): void
    {
        // Establish 'single_session' first so we prove the invalid value RESETS to
        // 'full', not merely that an unset field defaults to full.
        $this->save(['apply_mode' => 'single_session']);
        $this->assertSame('single_session', $this->field('apply_mode'), 'precondition: single_session');

        $this->save(['apply_mode' => 'not_a_mode']);

        $this->assertSame(
            'full',
            $this->field('apply_mode'),
            "an invalid apply_mode must default to 'full' (line 536 in_array guard)",
        );
    }

    // ---------------------------------------------------------------
    // GUARD denials — bad nonce / empty fields write NOTHING
    // ---------------------------------------------------------------

    public function testBadNonceWritesNothing(): void
    {
        // Baseline: a known status.
        $this->save(['status' => VoucherStatus::Disabled->value]);
        $this->assertSame(VoucherStatus::Disabled->value, $this->field('status'), 'baseline status');

        // Post a status change WITHOUT a valid nonce — the guard (line 435) must
        // return early and write nothing.
        $this->save(['status' => VoucherStatus::Expired->value], withNonce: false);

        $this->assertSame(
            VoucherStatus::Disabled->value,
            $this->field('status'),
            'a bad/missing nonce must short-circuit handleSave (line 435) — no write',
        );
    }

    public function testEmptyFieldsWriteNothing(): void
    {
        // Baseline: a known status.
        $this->save(['status' => VoucherStatus::Disabled->value]);
        $this->assertSame(VoucherStatus::Disabled->value, $this->field('status'), 'baseline status');

        // An empty ntdst_fields must return early (line 451) — nothing written,
        // and in particular scope_mode/apply_mode/edition_id are NOT reset.
        $this->save([]);

        $this->assertSame(
            VoucherStatus::Disabled->value,
            $this->field('status'),
            'empty ntdst_fields must short-circuit handleSave (line 451) — no write',
        );
    }
}
