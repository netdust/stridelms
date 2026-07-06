<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Invoicing;

use IntegrationTestCase;
use ReflectionMethod;
use Stride\Domain\DiscountType;
use Stride\Handlers\EnrollmentFormHandler;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\User\ProfileTypeService;

/**
 * T9 (concern 1) — Trajectory auto-voucher parity with the edition path (T8).
 * RED-first contract test. MONEY BOUNDARY (Tier A).
 *
 * Plan: docs/plans/2026-07-05-profiletype-visibility-filter.md §4 M3, §6.3,
 * §8 flow D, §7 T9.
 *
 * The trajectory inline quote path — EnrollmentFormHandler::createTrajectoryQuote()
 * (Handlers/EnrollmentFormHandler.php:361) — currently validates + calculates a
 * MANUAL voucher (:392) but, like the edition path before T8, NEVER redeems
 * (used_count never moves; only QuoteService::applyVoucher() redeems). T9 brings
 * the trajectory path to parity with T8's edition path: after the quote is built,
 * resolve ProfileTypePolicy::autoVoucherCode($userId, $trajectoryId,
 * 'vad_trajectory') from the user's STORED type and, when no manual voucher was
 * supplied, apply it via QuoteService::applyVoucher($quoteId, $code) — which runs
 * the full VoucherService::validateVoucher (scope/date/usage) AND redeems
 * (used_count moves under SELECT ... FOR UPDATE).
 *
 * Seam under test: the REAL createTrajectoryQuote() private method (the exact
 * insertion point the plan pins) driven un-mocked → real createQuote → real
 * autoVoucherCode → real applyVoucher → real redeemVoucher → real used_count.
 * The auto-voucher code is NEVER passed in; it must be resolved server-side from
 * the enrolling user's stored profile type (the money + no-client-trust contract).
 *
 * This test asserts, mirroring AutoVoucherEditionTest:
 *   1. CORE (gap-closer): a voucher-granting-type user's trajectory quote gets the
 *      discount AND the voucher's used_count INCREMENTS (redemption happened).
 *   2. DENIAL (mandatory): a user of a type with NO voucher rule gets NO auto
 *      voucher — the granting type's used_count does NOT move (no cross-type theft).
 *   3. FLOW-D BOUNDARY: a resolved-but-exhausted code → the auto-application is
 *      skipped gracefully; the enrollment quote STILL builds, just without a
 *      discount; used_count unchanged.
 *   4. NO STACKING: when a MANUAL voucher was supplied, the auto path must NOT
 *      apply/redeem a second code — exactly one voucher, the manual one, wins.
 *
 * This test is IMMUTABLE to the implementer: green it without weakening; escalate
 * (do not edit) if it is wrong.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AutoVoucherTrajectory'
 */
final class AutoVoucherTrajectoryTest extends IntegrationTestCase
{
    private const GRANT_SLUG = 'vrijwilliger';   // type whose rule carries a voucher
    private const OTHER_SLUG = 'werknemer';      // type with NO voucher

    private QuoteService $quotes;
    private RegistrationRepository $registrations;
    private EnrollmentFormHandler $handler;

    /** @var array<int> registration/enrollment ids to hard-delete in tearDown */
    private array $createdRegistrationIds = [];
    /** @var array<int> user ids to delete in tearDown */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes        = ntdst_get(QuoteService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
        // Thin handler (no DI) — the real seam under test.
        $this->handler = new EnrollmentFormHandler();

        update_option('stride_profile_types', [
            ['slug' => self::GRANT_SLUG, 'label' => 'Vrijwilliger', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
            ['slug' => self::OTHER_SLUG, 'label' => 'Werknemer', 'description' => '', 'color' => '', 'icon' => '', 'order' => 2],
        ]);
        ntdst_get(ProfileTypeService::class)->resetCache();
    }

    protected function tearDown(): void
    {
        global $wpdb;

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

    // === 1. CORE — auto-apply + REDEEM on the trajectory path ===============

    /** @test */
    public function grantTypeUserGetsAutoVoucherAppliedAndRedemptionMovesUsedCountOnTrajectory(): void
    {
        $code         = $this->uniqueCode('TAUTO');
        $voucherId    = $this->createTenPercentVoucher($code);
        $userId       = $this->createUserOfType(self::GRANT_SLUG);
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $code);

        $enrollmentId = $this->fireTrajectoryQuote($userId, $trajectoryId);

        $quote = $this->quotes->getQuoteByRegistration($enrollmentId);
        self::assertIsArray($quote, 'a quote must be created for the trajectory enrollment');

        self::assertGreaterThan(
            0,
            (int) ($quote['discount'] ?? 0),
            'the auto-voucher discount must be applied to the trajectory quote',
        );
        self::assertSame(
            $code,
            (string) ($quote['voucher_code'] ?? ''),
            "the trajectory quote's voucher_code must be the auto-resolved code",
        );

        // The gap-closing assertion: redemption actually moved used_count.
        self::assertSame(
            1,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'REDEMPTION must move used_count on the trajectory path — calculate-only does not close the M3 gap',
        );
    }

    // === 2. DENIAL — wrong type → no voucher (money boundary) ===============

    /** @test */
    public function otherTypeUserGetsNoAutoVoucherAndUsedCountDoesNotMoveOnTrajectory(): void
    {
        $code         = $this->uniqueCode('TDENY');
        $voucherId    = $this->createTenPercentVoucher($code);
        // Rule grants the voucher to GRANT_SLUG only...
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $code);
        // ...but the enrolling user is a DIFFERENT type with no voucher rule.
        $userId       = $this->createUserOfType(self::OTHER_SLUG);

        $enrollmentId = $this->fireTrajectoryQuote($userId, $trajectoryId);

        $quote = $this->quotes->getQuoteByRegistration($enrollmentId);
        self::assertIsArray($quote, 'a quote is still created for the non-granting type');

        self::assertSame(
            0,
            (int) ($quote['discount'] ?? 0),
            'a non-granting type must NOT receive the auto-voucher discount on the trajectory path',
        );
        self::assertSame(
            '',
            (string) ($quote['voucher_code'] ?? ''),
            "a non-granting type's trajectory quote must carry no auto-voucher code",
        );
        self::assertSame(
            0,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            "used_count must NOT move for another type's voucher — no cross-type theft on the trajectory path",
        );
    }

    // === 3. FLOW-D BOUNDARY — resolved-but-exhausted → quote still builds ===

    /** @test */
    public function resolvedButExhaustedVoucherIsSkippedAndTrajectoryQuoteStillBuilds(): void
    {
        $code = $this->uniqueCode('TCAP');
        // usage_limit 1, already fully used → validateVoucher returns exhausted.
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

        $userId       = $this->createUserOfType(self::GRANT_SLUG);
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $code);

        // Must not fatal — the auto-application is skipped gracefully.
        $enrollmentId = $this->fireTrajectoryQuote($userId, $trajectoryId);

        $quote = $this->quotes->getQuoteByRegistration($enrollmentId);
        self::assertIsArray($quote, 'the trajectory quote must STILL build when the resolved voucher is invalid');
        self::assertSame(
            0,
            (int) ($quote['discount'] ?? 0),
            'an exhausted voucher yields no discount, but the trajectory quote survives',
        );
        self::assertSame(
            1,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'used_count must be unchanged when applyVoucher rejects the exhausted code',
        );
    }

    // === 4. NO STACKING — a manual voucher was supplied, auto must not add ==

    /** @test */
    public function manualVoucherSuppliedMeansAutoDoesNotStackASecondRedemption(): void
    {
        $manualCode = $this->uniqueCode('TMAN');
        $autoCode   = $this->uniqueCode('TAUT');
        // Manual voucher must exist so createTrajectoryQuote's validate+calculate
        // path accepts it; we assert on the AUTO voucher NOT being redeemed.
        $this->createTenPercentVoucher($manualCode);
        $autoVoucherId = $this->createTenPercentVoucher($autoCode);

        $userId       = $this->createUserOfType(self::GRANT_SLUG);
        // The trajectory's rule grants the AUTO code to the user's type...
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $autoCode);

        // ...but a MANUAL voucher is supplied on the same enroll. No stacking:
        // when a manual voucher is present, the auto path must NOT apply.
        $enrollmentId = $this->fireTrajectoryQuote($userId, $trajectoryId, $manualCode);

        $autoUsed = (int) get_post_meta($autoVoucherId, '_ntdst_used_count', true);
        self::assertSame(
            0,
            $autoUsed,
            'a manual voucher present means the AUTO voucher must NOT additionally redeem (no stacking / no money-doubling)',
        );

        $quote = $this->quotes->getQuoteByRegistration($enrollmentId);
        self::assertIsArray($quote, 'the trajectory quote must exist');
        self::assertSame(
            $manualCode,
            (string) ($quote['voucher_code'] ?? ''),
            'the manually supplied voucher must remain on the trajectory quote — the auto path must not replace it',
        );
    }

    // === Fixtures ===========================================================

    private function uniqueCode(string $prefix): string
    {
        return $prefix . strtoupper(wp_generate_password(6, false, false));
    }

    /** 10%-off voucher, valid for all editions/trajectories (edition_id 0 = "alle"). */
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
        $username = 'autovt_' . wp_generate_password(6, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        self::assertIsInt($userId, 'fixture: failed to create user');
        $this->createdUserIds[] = $userId;
        update_user_meta($userId, '_stride_profile_type', [$slug]);
        return $userId;
    }

    /** Open, priced trajectory whose rule auto-grants $code to $slug. */
    private function createTrajectoryGranting(string $slug, string $code): int
    {
        $trajectoryId = wp_insert_post([
            'post_title'  => 'Auto-Voucher Trajectory ' . wp_generate_password(4, false),
            'post_type'   => TrajectoryCPT::POST_TYPE,
            'post_status' => 'publish',
        ]);
        self::assertIsInt($trajectoryId, 'fixture: failed to create trajectory');
        self::$testPosts[] = $trajectoryId;

        // Write the meta the trajectory quote/policy path reads. Price is CENTS
        // (a non-zero price so a real quote is created). profiletype_rules is read
        // back through TrajectoryRepository::getProfiletypeRules() by the policy.
        ntdst_data()->get(TrajectoryCPT::POST_TYPE)->update($trajectoryId, [
            'status'            => 'published',
            'price'             => 10000,       // 100.00 EUR in cents
            'price_non_member'  => 10000,
            'profiletype_rules' => [
                $slug => ['block' => false, 'minimal' => false, 'voucher' => $code],
            ],
        ]);

        // Guard the round-trip the policy depends on so a fixture drift fails
        // loud here rather than silently masking the money assertion.
        $rules = ntdst_get(TrajectoryRepository::class)->getProfiletypeRules($trajectoryId);
        self::assertArrayHasKey($slug, $rules, 'fixture: profiletype_rules must round-trip on the trajectory');

        return $trajectoryId;
    }

    /**
     * Drive the REAL trajectory quote seam un-mocked: the private
     * createTrajectoryQuote() method the plan pins as T9's insertion point.
     * A fresh enrollment id is minted per call (createTrajectoryQuote uses it as
     * the quote's registration_id, so getQuoteByRegistration($enrollmentId) finds
     * the quote). The auto-voucher code is NEVER passed here — it must be resolved
     * server-side from the user's stored type.
     */
    private function fireTrajectoryQuote(int $userId, int $trajectoryId, string $manualVoucher = ''): int
    {
        wp_set_current_user($userId);

        // Mint a registration row to stand in as the enrollment id (the trajectory
        // path passes enrollment_id where the edition path passes registration_id).
        $enrollmentId = $this->registrations->create([
            'user_id'         => $userId,
            'trajectory_id'   => $trajectoryId,
            'status'          => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        self::assertIsInt($enrollmentId, 'fixture: could not seed trajectory enrollment row');
        $this->createdRegistrationIds[] = $enrollmentId;

        $method = new ReflectionMethod(EnrollmentFormHandler::class, 'createTrajectoryQuote');
        $method->setAccessible(true);
        $quoteId = $method->invoke(
            $this->handler,
            $userId,
            $enrollmentId,
            $trajectoryId,
            [],             // billing data
            $manualVoucher, // manual voucher code (empty ⇒ auto path may apply)
        );

        self::assertNotInstanceOf(\WP_Error::class, $quoteId, 'createTrajectoryQuote must not error');

        return $enrollmentId;
    }
}
