<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Invoicing;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\User\ProfileTypeService;

/**
 * Plan 2026-07-07 §7 Task 6, §8 F3 — TRAJECTORY redeem/release SYMMETRY.
 * MONEY BOUNDARY (Tier A).
 *
 * VoucherReleaseIdentityTest proves the redeem/release identity contract on the
 * EDITION path (bulk enroll: payer != attendee). This test proves the SAME money
 * contract holds on the TRAJECTORY path, driven through the real event seam.
 *
 * The contract (§8 F3): "Cancel a trajectory quote carrying an attendee
 * auto-voucher → used_count reverses against the attendee. mid-flow: release keys
 * on voucher_redeemed_user_id not payer."
 *
 * On the trajectory path (Handlers/TrajectoryQuoteHandler.php:143-147) the
 * auto-voucher is redeemed via applyVoucher($quoteId, $autoCode,
 * redeemAsUserId: $userId, editionScoped: false) — so voucher_redeemed_user_id is
 * durably persisted on the quote as the ATTENDEE (the enrolling user). When that
 * quote is CANCELLED, QuoteService::cancel() (QuoteService.php:591-593) reads
 * voucher_redeemed_user_id from the quote meta and calls
 * releaseVoucher($voucherCode, $redeemUserId, $quoteId) keyed on THAT stored id —
 * so redeem and release use the same attendee id and the release actually reverses
 * the redemption (used_count 1 -> 0), rather than keying on a wrong id and finding
 * no matching redemption row (which would roll back and strand used_count at 1).
 *
 * This test asserts, mirroring VoucherReleaseIdentityTest for trajectories:
 *   1. Firing stride/trajectory/registration/created for a voucher-granting-type
 *      attendee on a priced trajectory → a quote is created, the auto-voucher is
 *      applied, and used_count == 1 (redeemed against the attendee).
 *   2. The quote durably records voucher_redeemed_user_id == the ATTENDEE (the
 *      identity the release will key on — the money contract).
 *   3. CANCELLING that quote reverses used_count back to 0.
 *   4. Identity proof: the attendee is FREE to redeem the same code again on a
 *      fresh quote — removeRedemption removed the attendee's row, which is only
 *      possible if release keyed on the SAME attendee id the redeem used (not the
 *      payer / not a no-op against the wrong id).
 *
 * Reuses CleansUpLeakedQuotesTrait for the registration-id-reuse teardown leak.
 *
 * MONEY boundary — run it 3x for determinism (redeemVoucher SELECT ... FOR UPDATE).
 * IMMUTABLE contract: green it without weakening; if release does NOT reverse
 * used_count, that is a real bug — report it, do not weaken the assertion.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryVoucherReleaseIdentity'
 */
final class TrajectoryVoucherReleaseIdentityTest extends IntegrationTestCase
{
    use CleansUpLeakedQuotesTrait;

    private const GRANT_SLUG = 'vrijwilliger';   // type whose rule carries a voucher

    private const EVENT = 'stride/trajectory/registration/created';

    private QuoteService $quotes;
    private RegistrationRepository $registrations;

    /** @var array<int> registration/enrollment ids to hard-delete in tearDown */
    private array $createdRegistrationIds = [];
    /** @var array<int> user ids to delete in tearDown */
    private array $createdUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes        = ntdst_get(QuoteService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);

        update_option('stride_profile_types', [
            ['slug' => self::GRANT_SLUG, 'label' => 'Vrijwilliger', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
        ]);
        ntdst_get(ProfileTypeService::class)->resetCache();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Purge the quotes this suite's enrollments produced BEFORE the
        // registration rows go away — otherwise leaked vad_quote posts keyed on
        // reused registration ids flake the sibling money assertions. See the trait.
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

    // === §8 F3 — CANCEL reverses the ATTENDEE's trajectory redemption ========

    /**
     * A voucher-granting-type attendee self-enrolls in a priced trajectory via the
     * real event → the auto-voucher redeems against the ATTENDEE
     * (voucher_redeemed_user_id = attendee). Cancelling that quote must reverse
     * used_count to 0 AND free the attendee to redeem again — proving the release
     * keyed on voucher_redeemed_user_id (the attendee), symmetric with the redeem.
     *
     * If release did NOT reverse used_count, removeRedemption found no matching
     * row (wrong id) → rollback → used_count stuck at 1 → a real money bug.
     *
     * @test
     */
    public function cancellingATrajectoryQuoteReversesTheAttendeeVoucherRedemption(): void
    {
        $code         = $this->uniqueCode('TREL');
        $voucherId    = $this->createTenPercentVoucher($code);
        $attendeeId   = $this->createUserOfType(self::GRANT_SLUG);
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $code);

        $enrollmentId = $this->fireTrajectoryQuote($attendeeId, $trajectoryId);

        // 1. The event built a quote and the auto-voucher redeemed against the attendee.
        $quote = $this->quotes->getQuoteByRegistration($enrollmentId);
        self::assertIsArray($quote, 'the trajectory event must create a quote for the enrollment');
        self::assertSame(
            $code,
            (string) ($quote['voucher_code'] ?? ''),
            "the trajectory quote's voucher_code must be the auto-resolved code",
        );
        self::assertSame(
            1,
            $this->usedCount($voucherId),
            'the auto-voucher must be REDEEMED against the attendee on the trajectory path (used_count 1)',
        );

        // 2. The money-identity contract: the quote durably records the ATTENDEE as
        //    the id the voucher was redeemed against — this is the id cancel() reads.
        self::assertSame(
            $attendeeId,
            (int) ($quote['voucher_redeemed_user_id'] ?? 0),
            'voucher_redeemed_user_id must be the ATTENDEE (the enrolling user) — the id release keys on, not the payer',
        );

        $quoteId = (int) ($quote['id'] ?? 0);
        self::assertGreaterThan(0, $quoteId, 'the hydrated trajectory quote must expose its post id');

        // 3. Cancel the quote → the redemption keyed on the ATTENDEE must reverse.
        $cancelled = $this->quotes->cancel($quoteId);
        self::assertTrue($cancelled === true, 'cancel must succeed: ' . $this->err($cancelled));

        self::assertSame(
            0,
            $this->usedCount($voucherId),
            'cancelling the trajectory quote must reverse used_count to 0 — the release must key on '
            . 'voucher_redeemed_user_id (the attendee), so redeem and release are the same id',
        );

        // 4. Identity proof: the attendee is no longer capped — they can redeem the
        //    same code again on a fresh enrollment. This is only possible if release
        //    removed the ATTENDEE's redemption row (same id as the redeem), not a
        //    no-op against a wrong id (redeemVoucher blocks a second redemption per
        //    user, so a stranded row would refuse re-redemption → used_count stays 0).
        //
        //    Drive a SECOND, DISTINCT trajectory granting the same code — the same
        //    attendee cannot re-enroll the SAME trajectory (RegistrationRepository
        //    dedupes on (user, trajectory) and reactivates the cancelled row), so a
        //    second trajectory is the correct fresh-enrollment vehicle. If the release
        //    had keyed on the wrong id, the attendee's stale redemption row would make
        //    this second redemption fail and used_count would stay 0.
        $secondTrajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $code);
        $secondEnrollmentId = $this->fireTrajectoryQuote($attendeeId, $secondTrajectoryId);
        $secondQuote = $this->quotes->getQuoteByRegistration($secondEnrollmentId);
        self::assertIsArray($secondQuote, 'a fresh trajectory quote must be created for the second enrollment');
        self::assertSame(
            $code,
            (string) ($secondQuote['voucher_code'] ?? ''),
            'the attendee must be free to redeem the same code again after cancel released their redemption '
            . '— release and redeem keyed on the same attendee id',
        );
        self::assertSame(
            1,
            $this->usedCount($voucherId),
            'the re-redemption after release moves used_count back to 1 — proving the release actually '
            . 'reversed the original attendee redemption (not a no-op against the wrong id)',
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
        $username = 'trajrel_' . wp_generate_password(6, false);
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
            'post_title'  => 'Traj Release Identity ' . wp_generate_password(4, false),
            'post_type'   => TrajectoryCPT::POST_TYPE,
            'post_status' => 'publish',
        ]);
        self::assertIsInt($trajectoryId, 'fixture: failed to create trajectory');
        self::$testPosts[] = $trajectoryId;

        // Price is CENTS (non-zero so a real quote is created). profiletype_rules is
        // read back through TrajectoryRepository::getProfiletypeRules() by the policy.
        ntdst_data()->get(TrajectoryCPT::POST_TYPE)->update($trajectoryId, [
            'status'            => 'published',
            'price'             => 10000,       // 100.00 EUR in cents
            'price_non_member'  => 10000,
            'profiletype_rules' => [
                $slug => ['block' => false, 'minimal' => false, 'voucher' => $code],
            ],
        ]);

        // Guard the round-trip the policy depends on so a fixture drift fails loud
        // here rather than silently masking the money assertion.
        $rules = ntdst_get(TrajectoryRepository::class)->getProfiletypeRules($trajectoryId);
        self::assertArrayHasKey($slug, $rules, 'fixture: profiletype_rules must round-trip on the trajectory');

        return $trajectoryId;
    }

    /**
     * Drive the REAL trajectory quote seam un-mocked via the event. Mints a fresh
     * enrollment id per call (the handler keys the quote's registration_id on it, so
     * getQuoteByRegistration($enrollmentId) finds the quote). The auto-voucher code
     * is NEVER passed on the payload — it must be resolved server-side from the
     * user's stored type, and redeemed against the enrolling user (the attendee).
     */
    private function fireTrajectoryQuote(int $userId, int $trajectoryId): int
    {
        wp_set_current_user($userId);

        $enrollmentId = $this->registrations->create([
            'user_id'         => $userId,
            'trajectory_id'   => $trajectoryId,
            'status'          => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        self::assertIsInt($enrollmentId, 'fixture: could not seed trajectory enrollment row');
        $this->createdRegistrationIds[] = $enrollmentId;

        // Fire the REAL event un-mocked — the registered TrajectoryQuoteHandler is
        // the seam under test. Payload carries only server-minted ids.
        do_action(self::EVENT, [
            'registration_id' => $enrollmentId,
            'user_id'         => $userId,
            'trajectory_id'   => $trajectoryId,
        ]);

        return $enrollmentId;
    }

    private function usedCount(int $voucherId): int
    {
        return (int) get_post_meta($voucherId, '_ntdst_used_count', true);
    }

    private function err(mixed $result): string
    {
        return is_wp_error($result) ? $result->get_error_code() . ': ' . $result->get_error_message() : var_export($result, true);
    }
}
