<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Invoicing;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Handlers\EnrollmentQuoteHandler;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\User\ProfileTypeService;

/**
 * T8 — Auto-voucher from STORED profile type, applied via applyVoucher
 * (validates + REDEEMS). RED-first contract test. MONEY BOUNDARY (Tier A).
 *
 * Plan: docs/plans/2026-07-05-profiletype-visibility-filter.md §4 M3, §6.3,
 * §8 flow D, §7 T8.
 *
 * Threat model M3: a voucher is auto-applied ONLY to the profile type the
 * enrollable's rules name, resolved SERVER-SIDE from the user's STORED type
 * (never client-sent), and STILL subject to full voucher business rules
 * (usage caps, dates, scope) AND redemption. This CLOSES the createQuote-
 * doesn't-redeem gap: the current `onRegistrationCreated` path builds the
 * quote via createQuote(voucherCode:...) which validates+calculates but does
 * NOT move used_count — only QuoteService::applyVoucher() redeems.
 *
 * Insertion point (edition path): EnrollmentQuoteHandler::onRegistrationCreated()
 * (Handlers/EnrollmentQuoteHandler.php). AFTER the quote is created, T8 must
 * resolve ProfileTypePolicy::autoVoucherCode($userId, $editionId, 'vad_edition')
 * and, if non-null, apply it via QuoteService::applyVoucher($quoteId, $code).
 *
 * This is the DENIAL-PATH contract for a MONEY boundary. It asserts:
 *   1. CORE (gap-closer): a voucher-granting-type user's quote gets the
 *      discount AND the voucher's used_count INCREMENTS (redemption happened,
 *      not merely calculated).
 *   2. DENIAL (mandatory): a user of a type with NO voucher (or a different
 *      code) gets NO auto-voucher — the granting type's used_count does NOT
 *      move. No cross-type voucher theft.
 *   3. FLOW-D BOUNDARY: a resolved-but-invalid code (over usage cap) → the
 *      auto-application is skipped gracefully; the enrollment + quote STILL
 *      succeed, just without the discount; used_count unchanged.
 *   4. STORED-TYPE ONLY (no client trust): the code is resolved from usermeta
 *      via the policy, never from request input. A user of a NON-granting type
 *      cannot inject the granting type's code through the enroll payload.
 *   5. NO DOUBLE-APPLY: when a MANUAL voucher was already supplied (pending-
 *      billing path), the auto-voucher must NOT stack a second redeemed code.
 *      Asserted precedence: a manual voucher takes precedence; the auto path
 *      only applies when no manual voucher was supplied. End state = exactly
 *      ONE redeemed voucher.
 *
 * The seam is exercised un-mocked end to end: real onRegistrationCreated →
 * real createQuote → real autoVoucherCode → real applyVoucher → real
 * redeemVoucher → real used_count.
 *
 * This test is IMMUTABLE to the implementer: green it without weakening;
 * escalate (do not edit) if it is wrong.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AutoVoucherEdition'
 */
final class AutoVoucherEditionTest extends IntegrationTestCase
{
    use CleansUpLeakedQuotesTrait;

    private const GRANT_SLUG = 'vrijwilliger';   // type whose rule carries a voucher
    private const OTHER_SLUG = 'werknemer';       // type with NO voucher

    private QuoteService $quotes;
    private RegistrationRepository $registrations;
    private EnrollmentQuoteHandler $handler;

    /** @var array<int> registration ids to hard-delete in tearDown */
    private array $createdRegistrationIds = [];
    /** @var array<int> user ids to delete in tearDown */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes        = ntdst_get(QuoteService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
        // Thin handler (no DI) — the real seam under test.
        $this->handler = new EnrollmentQuoteHandler();

        // Seed the two profile types this suite keys on so setUserType()/
        // getUserType() (which validate against getType()) accept both slugs.
        update_option('stride_profile_types', [
            ['slug' => self::GRANT_SLUG, 'label' => 'Vrijwilliger', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
            ['slug' => self::OTHER_SLUG, 'label' => 'Werknemer', 'description' => '', 'color' => '', 'icon' => '', 'order' => 2],
        ]);
        // ProfileTypeService memoises getTypes() and is a process-wide DI
        // singleton — a prior class may have warmed a DIFFERENT type set.
        // resetCache() discards the memo so the slugs above actually load;
        // without it the denial tests can fail open by suite ordering.
        ntdst_get(ProfileTypeService::class)->resetCache();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Delete the quotes the handler created for THIS suite's registrations
        // BEFORE the registration rows go away (shared with the trajectory
        // sibling via CleansUpLeakedQuotesTrait — see the trait for why the
        // registration-id-reuse leak flakes the money assertions).
        $this->deleteQuotesForRegistrations($this->createdRegistrationIds);

        foreach ($this->createdRegistrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->createdRegistrationIds = [];

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->createdUserIds as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUserIds = [];

        delete_option('stride_profile_types');
        ntdst_get(ProfileTypeService::class)->resetCache();
        wp_set_current_user(0);

        parent::tearDown();
    }

    // === 1. CORE — auto-apply + REDEEM (the gap-closer) =====================

    /** @test */
    public function grantTypeUserGetsAutoVoucherAppliedAndRedemptionMovesUsedCount(): void
    {
        $code      = $this->uniqueCode('AUTO');
        $voucherId = $this->createTenPercentVoucher($code);
        $userId    = $this->createUserOfType(self::GRANT_SLUG);
        $editionId = $this->createEditionGranting(self::GRANT_SLUG, $code);

        $regId = $this->seedRegistration($userId, $editionId);

        $this->fireRegistrationCreated($regId, $userId, $editionId);

        $quote = $this->quotes->getQuoteByRegistration($regId);
        self::assertIsArray($quote, 'a quote must be created for the enrollment');

        // The discount is applied on the quote...
        self::assertGreaterThan(
            0,
            (int) ($quote['discount'] ?? 0),
            'the auto-voucher discount must be applied to the quote',
        );
        self::assertSame(
            $code,
            (string) ($quote['voucher_code'] ?? ''),
            "the quote's voucher_code must be the auto-resolved code",
        );

        // ...AND the redemption actually moved used_count — this is the
        // gap-closing assertion. calculateDiscount alone would leave it at 0.
        self::assertSame(
            1,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'REDEMPTION must move used_count — a calculated-only discount does not close the M3 gap',
        );
    }

    // === 1b. BULK — each attendee redeems their OWN discount (money bug [8]) =

    /**
     * Regression for cluster-3 money bug [8]: an admin bulk-enrolls N colleagues
     * (enrolled_by = admin) each of the voucher-granting type. Each attendee's
     * quote must get the discount AND the voucher's used_count must move by N —
     * one redemption per attendee, keyed on the ATTENDEE, not the shared payer.
     *
     * Pre-fix: applyVoucher redeems against the quote's user_id (= the payer for
     * a colleague enroll). redeemVoucher's per-user "already redeemed" check then
     * keys on the admin: attendee 1 redeems, attendees 2..N get already_redeemed
     * → swallowed as invalid → those attendees silently enroll WITHOUT their
     * entitled discount and used_count stalls at 1.
     *
     * @test
     */
    public function bulkEnrolledColleaguesEachRedeemTheirOwnAutoVoucher(): void
    {
        $code      = $this->uniqueCode('BULK');
        $voucherId = $this->createTenPercentVoucher($code);
        $editionId = $this->createEditionGranting(self::GRANT_SLUG, $code);

        // One admin/payer enrolls two colleagues, both of the granting type.
        $adminId      = $this->createUserOfType(self::OTHER_SLUG); // payer, not attendee
        $colleagueOne = $this->createUserOfType(self::GRANT_SLUG);
        $colleagueTwo = $this->createUserOfType(self::GRANT_SLUG);

        $regOne = $this->seedRegistration($colleagueOne, $editionId, $adminId);
        $regTwo = $this->seedRegistration($colleagueTwo, $editionId, $adminId);

        // Fire both enrollments as the SAME payer (enrolled_by = admin).
        $this->fireRegistrationCreated($regOne, $colleagueOne, $editionId, ['enrolled_by' => $adminId]);
        $this->fireRegistrationCreated($regTwo, $colleagueTwo, $editionId, ['enrolled_by' => $adminId]);

        $quoteOne = $this->quotes->getQuoteByRegistration($regOne);
        $quoteTwo = $this->quotes->getQuoteByRegistration($regTwo);
        self::assertIsArray($quoteOne, 'colleague 1 must get a quote');
        self::assertIsArray($quoteTwo, 'colleague 2 must get a quote');

        self::assertGreaterThan(
            0,
            (int) ($quoteOne['discount'] ?? 0),
            'colleague 1 must receive the auto-voucher discount',
        );
        self::assertGreaterThan(
            0,
            (int) ($quoteTwo['discount'] ?? 0),
            'colleague 2 must ALSO receive the auto-voucher discount — not just the first attendee',
        );

        self::assertSame(
            2,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'each attendee counts as one redemption — used_count must move by 2, not stall at 1',
        );
    }

    // === 2. DENIAL — wrong type → no voucher (money boundary) ===============

    /** @test */
    public function otherTypeUserGetsNoAutoVoucherAndUsedCountDoesNotMove(): void
    {
        $code      = $this->uniqueCode('DENY');
        $voucherId = $this->createTenPercentVoucher($code);
        // Rule grants the voucher to GRANT_SLUG only.
        $editionId = $this->createEditionGranting(self::GRANT_SLUG, $code);
        // ...but the enrolling user is a DIFFERENT type with no voucher rule.
        $userId    = $this->createUserOfType(self::OTHER_SLUG);

        $regId = $this->seedRegistration($userId, $editionId);

        $this->fireRegistrationCreated($regId, $userId, $editionId);

        $quote = $this->quotes->getQuoteByRegistration($regId);
        self::assertIsArray($quote, 'a quote is still created for the non-granting type');

        self::assertSame(
            0,
            (int) ($quote['discount'] ?? 0),
            'a non-granting type must NOT receive the auto-voucher discount',
        );
        self::assertSame(
            '',
            (string) ($quote['voucher_code'] ?? ''),
            "a non-granting type's quote must carry no auto-voucher code",
        );
        self::assertSame(
            0,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            "used_count must NOT move for another type's voucher — no cross-type theft",
        );
    }

    // === 3. FLOW-D BOUNDARY — resolved-but-invalid → enroll still succeeds ==

    /** @test */
    public function resolvedButExhaustedVoucherIsSkippedAndEnrollmentStillSucceeds(): void
    {
        $code = $this->uniqueCode('CAP');
        // usage_limit 1, already fully used → validateVoucher returns 'exhausted'.
        $voucherId = $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_code'           => $code,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,
                '_ntdst_usage_limit'    => 1,
                '_ntdst_used_count'     => 1,
            ],
        ]);

        $userId    = $this->createUserOfType(self::GRANT_SLUG);
        $editionId = $this->createEditionGranting(self::GRANT_SLUG, $code);

        $regId = $this->seedRegistration($userId, $editionId);

        // Must not fatal — the auto-application is skipped gracefully.
        $this->fireRegistrationCreated($regId, $userId, $editionId);

        $quote = $this->quotes->getQuoteByRegistration($regId);
        self::assertIsArray($quote, 'the enrollment + quote must STILL succeed when the resolved voucher is invalid');
        self::assertSame(
            0,
            (int) ($quote['discount'] ?? 0),
            'an exhausted voucher yields no discount, but the quote survives',
        );
        self::assertSame(
            1,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'used_count must be unchanged when applyVoucher rejects the exhausted code',
        );
    }

    // === 4. STORED-TYPE ONLY — no client-supplied code injection ============

    /** @test */
    public function autoVoucherResolvesFromStoredTypeNotFromEnrollPayload(): void
    {
        $code      = $this->uniqueCode('STORED');
        $voucherId = $this->createTenPercentVoucher($code);
        // The edition grants the code ONLY to GRANT_SLUG.
        $editionId = $this->createEditionGranting(self::GRANT_SLUG, $code);
        // The enrolling user is the OTHER type (no voucher rule for them).
        $userId    = $this->createUserOfType(self::OTHER_SLUG);

        $regId = $this->seedRegistration($userId, $editionId);

        // Attempt to inject the granting type's code through a client-controlled
        // channel on the event payload. The AUTO path must key ONLY on the
        // user's STORED type — this injected code must be ignored.
        $this->fireRegistrationCreated($regId, $userId, $editionId, [
            'voucher_code'  => $code,
            'profile_type'  => self::GRANT_SLUG,
        ]);

        self::assertSame(
            0,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            "a client-injected code + claimed type must NOT auto-redeem another type's voucher",
        );
    }

    // === 5. NO DOUBLE-APPLY — manual voucher takes precedence ===============

    /** @test */
    public function manualVoucherTakesPrecedenceAndAutoDoesNotStackASecondRedemption(): void
    {
        $manualCode = $this->uniqueCode('MAN');
        $autoCode   = $this->uniqueCode('AUT');
        // The manual voucher must exist so createQuote's validate+calculate path
        // accepts it; we assert on its CODE staying on the quote, not its count.
        $this->createTenPercentVoucher($manualCode);
        $autoVoucherId = $this->createTenPercentVoucher($autoCode);

        $userId    = $this->createUserOfType(self::GRANT_SLUG);
        // The edition's rule grants the AUTO code to the user's type...
        $editionId = $this->createEditionGranting(self::GRANT_SLUG, $autoCode);

        // ...but a MANUAL voucher was already supplied via the pending-billing
        // path (the pre-existing separate channel). It must take precedence.
        $this->seedPendingBilling($userId, $editionId, $manualCode);

        $regId = $this->seedRegistration($userId, $editionId);

        $this->fireRegistrationCreated($regId, $userId, $editionId);

        // PRECEDENCE / NO-DOUBLE-APPLY boundary. This is a regression guard on
        // the money-doubling risk T8 introduces: a naive T8 that calls
        // applyVoucher($quoteId, $autoCode) unconditionally would (a) redeem the
        // AUTO voucher and (b) release-and-replace the manual code on the quote.
        // The asserted precedence: a manual voucher already on the quote WINS —
        // the auto path applies ONLY when no manual voucher was supplied. So the
        // AUTO voucher must never be redeemed here, and the quote must still
        // carry the MANUAL code. (Both hold pre-T8 because the auto path does not
        // exist yet; they MUST STILL hold post-T8 — that is the boundary.)
        $autoUsed = (int) get_post_meta($autoVoucherId, '_ntdst_used_count', true);
        self::assertSame(
            0,
            $autoUsed,
            'a manual voucher takes precedence: the AUTO voucher must NOT additionally redeem (no money-doubling)',
        );

        $quote = $this->quotes->getQuoteByRegistration($regId);
        self::assertIsArray($quote, 'the quote must exist');
        self::assertSame(
            $manualCode,
            (string) ($quote['voucher_code'] ?? ''),
            'the manually supplied voucher must remain on the quote — the auto path must not replace it',
        );
    }

    // === Fixtures ===========================================================

    private function uniqueCode(string $prefix): string
    {
        return $prefix . strtoupper(wp_generate_password(6, false, false));
    }

    /** 10%-off voucher, valid for all editions (edition_id 0 = "alle"). */
    private function createTenPercentVoucher(string $code): int
    {
        return $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_code'           => $code,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,
                '_ntdst_usage_limit'    => 5,
                '_ntdst_used_count'     => 0,
            ],
        ]);
    }

    private function createUserOfType(string $slug): int
    {
        $username = 'autov_' . wp_generate_password(6, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        self::assertIsInt($userId, 'fixture: failed to create user');
        $this->createdUserIds[] = $userId;
        update_user_meta($userId, '_stride_profile_type', [$slug]);
        return $userId;
    }

    /** Open, priced edition whose rule auto-grants $code to $slug. */
    private function createEditionGranting(string $slug, string $code): int
    {
        $editionId = $this->createTestEdition();
        // Price is read by EditionService::getPrice() via getField('price_non_member').
        // Write it through the model so a non-zero quote is created (a zero price
        // makes the handler skip the quote as a "free edition").
        ntdst_data()->get(EditionCPT::POST_TYPE)->update($editionId, [
            'status'            => 'open',
            'price_non_member'  => 10000, // 100.00 EUR in cents
            'profiletype_rules' => [
                $slug => ['block' => false, 'minimal' => false, 'voucher' => $code],
            ],
        ]);
        return $editionId;
    }

    private function seedRegistration(int $userId, int $editionId, ?int $enrolledBy = null): int
    {
        $fields = [
            'user_id'        => $userId,
            'edition_id'     => $editionId,
            'status'         => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ];
        if ($enrolledBy !== null) {
            $fields['enrolled_by'] = $enrolledBy;
        }
        $regId = $this->registrations->create($fields);
        self::assertIsInt($regId, 'fixture: could not seed registration');
        $this->createdRegistrationIds[] = $regId;
        return $regId;
    }

    /** Seed the pending-billing transient the handler reads for a manual voucher. */
    private function seedPendingBilling(int $userId, int $editionId, string $voucherCode): void
    {
        set_transient(
            'stride_pending_billing_' . $userId . '_' . $editionId,
            ['voucher_code' => $voucherCode],
            HOUR_IN_SECONDS,
        );
    }

    /**
     * Fire the real registration-created seam (edition path).
     *
     * @param array<string, mixed> $extra client-controlled payload keys to
     *        probe the no-client-trust contract (M3.4)
     */
    private function fireRegistrationCreated(int $regId, int $userId, int $editionId, array $extra = []): void
    {
        wp_set_current_user($userId);
        $this->handler->onRegistrationCreated(array_merge([
            'registration_id' => $regId,
            'user_id'         => $userId,
            'edition_id'      => $editionId,
            'status'          => 'confirmed',
        ], $extra));
    }
}
