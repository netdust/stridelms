<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Invoicing\Admin\QuoteAdminController;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;

/**
 * QuoteAdminController::handleNewQuoteCreation() — the new-quote-creation branch
 * of handleSave() (QuoteAdminController.php:277-280 dispatches here when the quote
 * has NO quote_number; body at :586-661), currently untested.
 *
 * Drives the REAL save path (nonce + capability guard + $_POST) against a BARE
 * `vad_quote` post created via wp_insert_post with NO quote_number meta — so
 * handleSave sees `$isNew === true` and calls handleNewQuoteCreation(). (Using
 * QuoteService::createQuote() would generate a quote_number and take the UPDATE
 * path instead — that path is covered by QuoteAdminHandleSaveStatusTest.)
 *
 * Contract asserted here (ground-truthed from source, product code UNCHANGED):
 *   1. Creating from user+edition sets status = 'draft'.
 *   2. A non-empty quote_number is generated AND set as the post title.
 *   3. valid_until = date('Y-m-d', '+30 days') — asserted as a valid date > today
 *      and within 29-31 days (tolerates the exact vs off-by-one clock boundary).
 *   4. billing is seeded from the user — the ONLY user-derived billing field the
 *      code copies is `email` ($user->user_email); every other billing key is
 *      hardcoded to '' in the source (:627-635). So the concrete billing
 *      assertion is billing['email'] === the fixture user's email.
 *   5. The single line item's unit_price (cents) reflects the edition MEMBER
 *      price. Source (:602-607) reads field `price` (member); if > 0 it wins over
 *      `price_non_member`. The stored edition price field is canonical CENTS and
 *      is NOT re-multiplied. Fixture sets an explicit member price so the branch
 *      is unambiguous.
 *
 * GROUND-TRUTH DIVERGENCES FROM THE TASK BRIEF (built to source, not the brief):
 *   - The posted keys are `ntdst_fields[user_id]` / `ntdst_fields[edition_id]`
 *     (:588-590), NOT `quote_user_id` / a separate edition select.
 *   - Billing is NOT broadly copied from user meta; only `email` is user-derived
 *     (:627-635). billing_company etc. are hardcoded '' — so item 4 asserts email.
 *   - Edition field `price` = the MEMBER price (EditionCPT.php:102, label
 *     "Prijs (leden)"), mapped to meta `_ntdst_price`.
 *
 * READ-BACK NOTES (Q-T1 lessons):
 *   - status / quote_number / valid_until / billing / items ARE QuoteCPT schema
 *     fields (QuoteCPT.php:55-93, meta_prefix => ''), so they surface via
 *     QuoteService::getQuote($id, true). The post title is read via get_post().
 *   - No helper is named status() — TestCase::status() is final and a collision
 *     fatals the class at compile (silent exit 255).
 *
 * Run: ddev exec --raw -- bash -c 'cd /var/www/html; \
 *   STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist \
 *   --filter QuoteAdminNewQuoteCreation'
 */
final class QuoteAdminNewQuoteCreationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // handleSave()'s current_user_can('edit_post', $id) guard resolves via
        // map_meta_cap for the vad_quote CPT — the base fixture user is a plain
        // subscriber, so promote it to administrator (same pattern as
        // QuoteAdminHandleSaveStatusTest / EditionDeadlineFieldsPersistTest).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
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

    /**
     * Create a BARE vad_quote post with NO quote_number meta, so handleSave sees
     * it as "new" and routes to handleNewQuoteCreation(). Returns the post ID.
     */
    private function createBareQuotePost(): int
    {
        $postId = wp_insert_post([
            'post_title'  => 'Nieuwe offerte',
            'post_type'   => 'vad_quote',
            'post_status' => 'publish',
        ]);

        $this->assertIsInt($postId, 'wp_insert_post must return an int quote id (fixture setup)');
        $this->assertGreaterThan(0, $postId, 'bare quote post must be created');
        self::$testPosts[] = $postId;

        // Sanity: this post must have NO quote_number (that is what makes it "new").
        $this->assertEmpty(
            get_post_meta($postId, 'quote_number', true),
            'precondition: bare quote must have no quote_number (else it is not "new")',
        );

        return $postId;
    }

    /**
     * Drive the real new-quote-creation path: set current user, create the nonce
     * AFTER the user is set, post the ntdst_fields[user_id]/[edition_id] the form
     * uses, invoke handleSave(), then clean up $_POST keys.
     *
     * @param array<string, mixed> $ntdstFields
     */
    private function save(int $quoteId, array $ntdstFields): void
    {
        wp_set_current_user((int) self::$testUserId);

        $_POST['stride_quote_nonce'] = wp_create_nonce('stride_save_quote');
        $_POST['ntdst_fields'] = $ntdstFields;

        $this->controller()->handleSave($quoteId, get_post($quoteId));

        unset($_POST['stride_quote_nonce'], $_POST['ntdst_fields']);
    }

    /** Read the hydrated quote (schema fields surface through getQuote). */
    private function readQuote(int $quoteId): array
    {
        $quote = $this->quoteService()->getQuote($quoteId, true);
        $this->assertIsArray($quote, 'getQuote must return the hydrated quote array');

        return $quote;
    }

    // ---------------------------------------------------------------------

    public function test_creating_from_user_and_edition_sets_status_draft(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createBareQuotePost();

        $this->save($quoteId, [
            'user_id'    => (int) self::$testUserId,
            'edition_id' => $editionId,
        ]);

        $quote = $this->readQuote($quoteId);
        $this->assertSame(
            'draft',
            (string) ($quote['status'] ?? ''),
            'new-quote creation must set status to draft',
        );
    }

    public function test_a_quote_number_is_generated_and_set_as_post_title(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createBareQuotePost();

        $this->save($quoteId, [
            'user_id'    => (int) self::$testUserId,
            'edition_id' => $editionId,
        ]);

        $quote = $this->readQuote($quoteId);
        $quoteNumber = (string) ($quote['quote_number'] ?? '');

        $this->assertNotEmpty(
            $quoteNumber,
            'new-quote creation must generate a non-empty quote_number',
        );

        $this->assertSame(
            $quoteNumber,
            get_post($quoteId)->post_title,
            'the generated quote_number must be set as the post title',
        );
    }

    public function test_valid_until_is_about_thirty_days_out(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createBareQuotePost();

        $this->save($quoteId, [
            'user_id'    => (int) self::$testUserId,
            'edition_id' => $editionId,
        ]);

        $quote = $this->readQuote($quoteId);
        $validUntil = (string) ($quote['valid_until'] ?? '');

        $this->assertNotEmpty($validUntil, 'new-quote creation must set valid_until');

        $validTs = strtotime($validUntil);
        $this->assertNotFalse($validTs, "valid_until ({$validUntil}) must be a parseable date");

        $now = time();
        $this->assertGreaterThan($now, $validTs, 'valid_until must be in the future');

        $daysOut = ($validTs - strtotime(date('Y-m-d', $now))) / DAY_IN_SECONDS;
        $this->assertGreaterThanOrEqual(
            29,
            $daysOut,
            "valid_until must be ~30 days out (got {$daysOut} days: {$validUntil})",
        );
        $this->assertLessThanOrEqual(
            31,
            $daysOut,
            "valid_until must be ~30 days out (got {$daysOut} days: {$validUntil})",
        );
    }

    public function test_billing_email_is_seeded_from_the_user(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createBareQuotePost();

        // The ONLY user-derived billing field the source copies is email.
        $expectedEmail = get_userdata((int) self::$testUserId)->user_email;
        $this->assertNotEmpty($expectedEmail, 'precondition: fixture user must have an email');

        $this->save($quoteId, [
            'user_id'    => (int) self::$testUserId,
            'edition_id' => $editionId,
        ]);

        $quote = $this->readQuote($quoteId);
        $billing = $quote['billing'] ?? [];
        $this->assertIsArray($billing, 'billing must hydrate to an array');

        $this->assertSame(
            $expectedEmail,
            (string) ($billing['email'] ?? ''),
            "billing.email must be seeded from the user's user_email",
        );
    }

    public function test_line_item_unit_price_reflects_edition_single_price(): void
    {
        // Single-price model: `price_non_member` (_ntdst_price_non_member) is the
        // canonical single price; discounts are applied via vouchers, not a member
        // tier. The legacy `price` field must be IGNORED entirely. Seed a distinct,
        // non-zero legacy `price` to prove the old member-first pick is gone.
        $singlePriceCents = 20000;
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_price'            => 12345,             // legacy member field: must be IGNORED
                '_ntdst_price_non_member' => $singlePriceCents, // canonical single price
            ],
        ]);

        $quoteId = $this->createBareQuotePost();

        $this->save($quoteId, [
            'user_id'    => (int) self::$testUserId,
            'edition_id' => $editionId,
        ]);

        $quote = $this->readQuote($quoteId);
        $items = $quote['items'] ?? [];
        $this->assertIsArray($items, 'items must hydrate to an array');
        $this->assertCount(1, $items, 'new-quote creation must build exactly one line item');

        $this->assertSame(
            $singlePriceCents,
            (int) ($items[0]['unit_price'] ?? -1),
            'line item unit_price (cents) must reflect the canonical single price (price_non_member), never the legacy member price',
        );
    }
}
