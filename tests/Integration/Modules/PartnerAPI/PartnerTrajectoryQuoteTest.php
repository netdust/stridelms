<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\PartnerAPI;

use IntegrationTestCase;
use Stride\Domain\DiscountType;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\User\ProfileTypeService;
use Stride\Tests\Integration\Modules\Invoicing\CleansUpLeakedQuotesTrait;

/**
 * Task 4 — Partner-API trajectory enroll now PRODUCES A QUOTE (the gap-closer proof).
 * MONEY BOUNDARY (Tier A). Plan: docs/plans/2026-07-07-trajectory-enroll-event-quote.md
 * §7 Task 4, §8 F2.
 *
 * THE GAP THIS CLOSES (plan §1): before this feature a Partner-API trajectory enroll
 * (`PartnerAPIController::createEnrollment` with a `trajectory_id`) created a
 * registration with NO quote and NO auto-voucher — the inline createTrajectoryQuote
 * was web-form-only, so the Partner path routed through TrajectorySelection::enroll()
 * and stopped at the registration row. `getQuoteByRegistration($regId)` returned null.
 *
 * NOW: Partner enroll → `TrajectorySelection::enroll()` (PartnerAPIController.php:685)
 * → dispatches `stride/trajectory/registration/created` → the registered
 * TrajectoryQuoteHandler builds the quote + attendee-keyed auto-voucher. This test
 * drives the FULL, UN-MOCKED Partner REST chain (`rest_do_request` → checkPermission
 * → createEnrollment → enroll → event → handler → real createQuote → real
 * applyVoucher → real used_count) — the event MUST actually fire and create the quote;
 * nothing in the enroll→quote chain is stubbed. That is the whole point of the proof.
 *
 * This test is expected to PASS on this branch (the feature is built): it is the
 * gap-closer proof, not a RED-first-then-green cycle. If it FAILS, the Partner path
 * does not thread something into the event and that is a real gap to report, not to
 * paper over.
 *
 * Asserts (plan §8 F2):
 *   1. GAP-CLOSER: a Partner trajectory enroll of a company user into a PRICED
 *      trajectory → a quote EXISTS for the registration. Returned null before.
 *   2. ATTENDEE-KEYED AUTO-VOUCHER: when the attendee's stored profile type grants a
 *      voucher, it is applied AND used_count moves against the ATTENDEE (the enrolled
 *      $user->ID), not the partner/payer. (Mirrors AutoVoucherTrajectoryTest +
 *      VoucherReleaseIdentityTest's attendee-keyed redemption.)
 *   3. BOUNDARY (F2): an attendee whose type has NO voucher rule → quote created, no
 *      discount, the granting voucher's used_count unchanged.
 *
 * This is a MONEY boundary — run it 3x for determinism (redeemVoucher SELECT FOR
 * UPDATE). Immutable contract: green it without weakening; escalate if it is wrong.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PartnerTrajectoryQuote'
 */
final class PartnerTrajectoryQuoteTest extends IntegrationTestCase
{
    use CleansUpLeakedQuotesTrait;

    private const GRANT_SLUG = 'vrijwilliger';   // type whose rule carries a voucher
    private const OTHER_SLUG = 'werknemer';      // type with NO voucher rule

    private const COMPANY_ID = 8801;

    private QuoteService $quotes;

    private static ?int $partnerUserId = null;

    /** @var array<int> registration ids to hard-delete in tearDown */
    private array $createdRegistrationIds = [];
    /** @var array<int> attendee user ids to delete in tearDown */
    private array $createdUserIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Purge ORPHAN vad_quote posts before this suite runs. The full integration
        // suite hard-deletes vad_registrations rows in tearDown, so the table's
        // AUTO_INCREMENT id gets REUSED across test classes. A quote another class'
        // enroll handler created and left behind — keyed on a low, reused
        // registration_id — then collides with THIS suite's freshly-minted
        // registration id: TrajectoryQuoteHandler's idempotency guard
        // (getQuoteByRegistration early-return, TrajectoryQuoteHandler.php:56) sees
        // the stale quote, skips, and the Partner enroll produces NO new quote →
        // getQuoteByRegistration returns null → the gap-closer assertion flakes
        // (~1-in-11 full-suite runs). Deleting quotes whose registration_id points
        // at no live registration closes that collision window deterministically.
        // Production is unaffected — real registration ids are monotonic and never
        // reused, so the collision cannot occur there
        // (gotcha_leaked_quotes_registration_id_reuse).
        global $wpdb;
        $regTable = $wpdb->prefix . 'vad_registrations';
        $orphanQuoteIds = $wpdb->get_col(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'vad_quote'
              WHERE pm.meta_key = 'registration_id'
                AND CAST(pm.meta_value AS UNSIGNED) NOT IN (SELECT id FROM {$regTable})",
        );
        foreach ($orphanQuoteIds as $orphanId) {
            wp_delete_post((int) $orphanId, true);
        }

        // The partner user: has `partner` role + `_stride_company_id`. Reused across
        // this suite's tests (matching PartnerAPIIntegrationTest's static partner).
        $username = 'ptqt_partner_' . time() . '_' . wp_generate_password(4, false);
        $id = wp_create_user($username, 'testpass123', $username . '@partner.test');
        if (is_wp_error($id)) {
            throw new \RuntimeException('Failed to create partner user: ' . $id->get_error_message());
        }
        get_user_by('ID', $id)->add_role('partner');
        update_user_meta($id, '_stride_company_id', self::COMPANY_ID);
        self::$partnerUserId = $id;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$partnerUserId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user(self::$partnerUserId);
            self::$partnerUserId = null;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes = ntdst_get(QuoteService::class);

        update_option('stride_profile_types', [
            ['slug' => self::GRANT_SLUG, 'label' => 'Vrijwilliger', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
            ['slug' => self::OTHER_SLUG, 'label' => 'Werknemer', 'description' => '', 'color' => '', 'icon' => '', 'order' => 2],
        ]);
        ntdst_get(ProfileTypeService::class)->resetCache();

        // Ensure the Partner REST routes are mounted for rest_do_request.
        do_action('rest_api_init');
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Purge quotes keyed on our registration ids BEFORE the rows go away —
        // otherwise leaked vad_quote posts collide on reused registration ids and
        // flake the money assertions (gotcha_leaked_quotes_registration_id_reuse).
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

    // === 1 + 2. GAP-CLOSER + ATTENDEE-KEYED AUTO-VOUCHER =====================

    /**
     * The core gap-closer (F2): a Partner-API trajectory enroll of a company user
     * into a PRICED trajectory produces a quote for the registration, and the
     * attendee's granting profile type earns an auto-voucher redeemed against the
     * ATTENDEE — not the partner/payer. Before this feature getQuoteByRegistration
     * returned null (no quote was ever built on the Partner path).
     *
     * @test
     */
    public function partnerTrajectoryEnrollProducesQuoteAndRedeemsAutoVoucherAgainstAttendee(): void
    {
        $code         = $this->uniqueCode('PTAUTO');
        $voucherId    = $this->createTenPercentVoucher($code);
        $attendeeId   = $this->createCompanyUserOfType(self::GRANT_SLUG);
        $trajectoryId = $this->createOpenPricedTrajectoryGranting(self::GRANT_SLUG, $code);

        $registrationId = $this->partnerEnrollTrajectory($attendeeId, $trajectoryId);

        // GAP-CLOSER: a quote now EXISTS for the Partner-created registration.
        $quote = $this->quotes->getQuoteByRegistration($registrationId);
        self::assertIsArray(
            $quote,
            'THE GAP: a Partner-API trajectory enroll must now produce a quote for the '
            . 'registration — getQuoteByRegistration returned null before this feature',
        );

        // The auto-voucher discount reached the trajectory quote.
        self::assertGreaterThan(
            0,
            (int) ($quote['discount'] ?? 0),
            'the attendee auto-voucher discount must be applied to the Partner trajectory quote',
        );
        self::assertSame(
            $code,
            (string) ($quote['voucher_code'] ?? ''),
            "the quote's voucher_code must be the auto-resolved code",
        );

        // ATTENDEE-KEYED redemption: used_count moved (redemption happened) AND it is
        // keyed on the ATTENDEE, not the partner/payer. redeemAsUserId = $user->ID in
        // the handler; the redemption row is persisted against the attendee.
        self::assertSame(
            1,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'redemption must move used_count on the Partner trajectory path (calculate-only would not)',
        );
        self::assertSame(
            $attendeeId,
            (int) ($quote['voucher_redeemed_user_id'] ?? 0),
            'the voucher must be redeemed against the ATTENDEE ($user->ID), not the partner/payer',
        );
    }

    // === 3. BOUNDARY — attendee type has NO voucher rule ====================

    /**
     * F2 boundary: the enrolled attendee's type carries no voucher rule. The quote is
     * still created (the gap is closed for every priced trajectory), but there is no
     * discount and the granting voucher's used_count is untouched — no cross-type
     * theft of a code the attendee's type was never granted.
     *
     * @test
     */
    public function partnerTrajectoryEnrollOfNonGrantingTypeCreatesQuoteWithNoDiscount(): void
    {
        $code         = $this->uniqueCode('PTBND');
        $voucherId    = $this->createTenPercentVoucher($code);
        // The rule grants the voucher to GRANT_SLUG only...
        $trajectoryId = $this->createOpenPricedTrajectoryGranting(self::GRANT_SLUG, $code);
        // ...but the enrolled attendee is a DIFFERENT type with no voucher rule.
        $attendeeId   = $this->createCompanyUserOfType(self::OTHER_SLUG);

        $registrationId = $this->partnerEnrollTrajectory($attendeeId, $trajectoryId);

        // The quote is STILL created — the gap is closed regardless of voucher grant.
        $quote = $this->quotes->getQuoteByRegistration($registrationId);
        self::assertIsArray(
            $quote,
            'a priced Partner trajectory enroll must produce a quote even when the '
            . "attendee's type carries no voucher rule",
        );

        self::assertSame(
            0,
            (int) ($quote['discount'] ?? 0),
            'a non-granting attendee type must NOT receive any auto-voucher discount',
        );
        self::assertSame(
            '',
            (string) ($quote['voucher_code'] ?? ''),
            "a non-granting attendee's quote must carry no auto-voucher code",
        );
        self::assertSame(
            0,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            "used_count must NOT move for a code the attendee's type was never granted — "
            . 'no cross-type theft on the Partner trajectory path',
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

    /**
     * A company member of the partner's company with a stored profile type. The
     * profile type is what the handler resolves the auto-voucher from (server-side,
     * never request input), so it must be persisted before enroll.
     */
    private function createCompanyUserOfType(string $slug): int
    {
        $username = 'ptqt_member_' . wp_generate_password(6, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@company.test');
        self::assertIsInt($userId, 'fixture: failed to create company user');
        $this->createdUserIds[] = $userId;

        // Affiliate with the partner's company so createEnrollment's company-scope
        // check (PartnerAPIController:663-673) passes.
        update_user_meta($userId, '_stride_company_id', self::COMPANY_ID);
        // Stored profile type — the auto-voucher resolves from this.
        update_user_meta($userId, '_stride_profile_type', [$slug]);

        return $userId;
    }

    /**
     * An OPEN, PRICED trajectory whose rule auto-grants $code to $slug. Status must be
     * `open` (OfferingStatus::Open->allowsEnrollment()) because this test drives the
     * FULL enroll() — which guards on isEnrollmentOpen — unlike AutoVoucherTrajectoryTest
     * which fires the event directly and can use a bare `published`. Capacity 0 =
     * unlimited so hasCapacity passes.
     */
    private function createOpenPricedTrajectoryGranting(string $slug, string $code): int
    {
        $trajectoryId = wp_insert_post([
            'post_title'  => 'Partner Trajectory ' . wp_generate_password(4, false),
            'post_type'   => TrajectoryCPT::POST_TYPE,
            'post_status' => 'publish',
        ]);
        self::assertIsInt($trajectoryId, 'fixture: failed to create trajectory');
        self::$testPosts[] = $trajectoryId;

        ntdst_data()->get(TrajectoryCPT::POST_TYPE)->update($trajectoryId, [
            'status'            => 'open',        // must allowEnrollment for enroll()
            'capacity'          => 0,             // unlimited
            'price'             => 10000,         // 100.00 EUR in cents
            'price_non_member'  => 10000,
            'profiletype_rules' => [
                $slug => ['block' => false, 'minimal' => false, 'voucher' => $code],
            ],
        ]);

        // Guard the round-trips enroll() + the policy depend on, so a fixture drift
        // fails loud here rather than silently masking the money assertion.
        $trajectory = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class)->getTrajectory($trajectoryId);
        self::assertIsArray($trajectory, 'fixture: trajectory must be readable');
        self::assertTrue(
            $trajectory['status_enum']->allowsEnrollment(),
            'fixture: trajectory must be OPEN so enroll() passes the isEnrollmentOpen guard',
        );
        self::assertSame(10000, (int) $trajectory['price'], 'fixture: trajectory must be priced');

        $rules = ntdst_get(TrajectoryRepository::class)->getProfiletypeRules($trajectoryId);
        self::assertArrayHasKey($slug, $rules, 'fixture: profiletype_rules must round-trip on the trajectory');

        return $trajectoryId;
    }

    /**
     * Drive the REAL Partner enroll seam UN-MOCKED via the REST layer: a POST to
     * /stride/v1/partner/enrollments with a trajectory_id, as the partner user. This
     * runs checkPermission → createEnrollment → TrajectorySelection::enroll() → the
     * `stride/trajectory/registration/created` event → the registered
     * TrajectoryQuoteHandler. Returns the registration id from the 201 response.
     */
    private function partnerEnrollTrajectory(int $attendeeId, int $trajectoryId): int
    {
        $this->actingAs(self::$partnerUserId);

        $attendee = get_userdata($attendeeId);
        self::assertNotFalse($attendee, 'fixture: attendee user must exist');

        $request = new \WP_REST_Request('POST', '/stride/v1/partner/enrollments');
        $request->set_body_params([
            'user_email'    => $attendee->user_email,
            'trajectory_id' => $trajectoryId,
        ]);

        $response = rest_do_request($request);

        self::assertSame(
            201,
            $response->get_status(),
            'the Partner trajectory enroll must succeed (201): '
            . var_export($response->get_data(), true),
        );

        $data = $response->get_data();
        self::assertArrayHasKey('id', $data, 'the enroll response must carry the registration id');

        $registrationId = (int) $data['id'];
        $this->createdRegistrationIds[] = $registrationId;

        return $registrationId;
    }
}
