<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Enrollment;

use IntegrationTestCase;
use Stride\Handlers\EnrollmentFormHandler;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\User\ProfileTypeService;
use Stride\Tests\Integration\Modules\Invoicing\CleansUpLeakedQuotesTrait;

/**
 * Task 3 — Rewire EnrollmentFormHandler::processTrajectoryEnrollment onto the
 * event-driven quote path. RED-first CONTRACT test. MONEY BOUNDARY (Tier A).
 *
 * Plan: docs/plans/2026-07-07-trajectory-enroll-event-quote.md §4 (A1, A2-CRITICAL,
 * A4), §7 Task 3, §8 F1.
 *
 * On this branch Task 1 (event dispatch in TrajectorySelection::enroll) and Task 2
 * (TrajectoryQuoteHandler, subscribed + registered) are already merged. So enroll()
 * fires `stride/trajectory/registration/created` → the handler builds quote #1.
 * BUT processTrajectoryEnrollment STILL calls the inline createTrajectoryQuote()
 * (EnrollmentFormHandler.php:305-311) → quote #2. That is the DOUBLE-QUOTE the RED
 * test below catches. Task 3 removes the inline creator so the event is the sole
 * creator; then EXACTLY ONE quote exists.
 *
 * Seam under test: the REAL public entry `handleSubmitEnrollment($data, $params)`
 * with item_type=trajectory — driven un-mocked. This exercises the load-bearing
 * contracts Task 3 must satisfy, NOT a reflected private method:
 *   - the ORDERING (pending-billing transient written BEFORE enroll(), which fires
 *     the event synchronously and reads it);
 *   - the SERVER-SIDE current-user keying (the transient keys on get_current_user_id(),
 *     never a client-supplied user id) — the security contract from Cluster 1 review;
 *   - the priced-trajectory ROLLBACK branch (A2-CRITICAL) and the free-trajectory
 *     NON-rollback (A2/F1).
 *
 * The four contracts asserted (acceptance criteria 1-5):
 *   1. NO DOUBLE-QUOTE (A1)  — a priced web-form self-enroll produces EXACTLY ONE
 *      vad_quote for the registration (RED now: two; GREEN after inline removal).
 *   2. NAMESPACED, SERVER-KEYED TRANSIENT (Cluster-1 security) — the web form writes
 *      the pending-billing under the byte-identical key
 *      `stride_pending_billing_traj_{userId}_{trajectoryId}` keyed on the AUTHENTICATED
 *      current user (NOT a client-supplied user id), carrying the manual voucher_code
 *      so the event handler picks it up (proven by the discount landing on the quote).
 *   3. PRICED ROLLBACK (A2-CRITICAL) — a priced trajectory whose quote never lands
 *      (no quote_id after enroll) → the registration is CANCELLED + WP_Error returned;
 *      the enrollee never walks past billing.
 *   4. FREE NOT CANCELLED (A2/F1) — a FREE (price 0) trajectory self-enroll SUCCEEDS
 *      with no quote and the registration is NOT cancelled (free = legitimate no-quote).
 *   5. REGRESSION AMOUNT (A4) — the single web-form priced quote carries the same
 *      total as the trajectory price (behavior-preserving move, no ×100, one creator).
 *
 * This test is IMMUTABLE to the implementer: green it without weakening; escalate
 * (do not edit) if it is wrong. Adding edge cases is allowed; relaxing an assertion
 * or the one-quote count is not.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryWebFormQuote'
 */
final class TrajectoryWebFormQuoteTest extends IntegrationTestCase
{
    use CleansUpLeakedQuotesTrait;

    private const TYPE_SLUG = 'werknemer'; // a plain type with NO auto-voucher rule

    private QuoteService $quotes;
    private RegistrationRepository $registrations;
    private EnrollmentFormHandler $handler;

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
        $this->handler = new EnrollmentFormHandler();

        update_option('stride_profile_types', [
            ['slug' => self::TYPE_SLUG, 'label' => 'Werknemer', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
        ]);
        ntdst_get(ProfileTypeService::class)->resetCache();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Purge quotes BEFORE the registration rows go away (reused-id leak, see trait).
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

    // === 1. NO DOUBLE-QUOTE (A1) — the headline RED assertion ================

    /**
     * A priced web-form trajectory self-enroll must produce EXACTLY ONE quote.
     *
     * RED now: the event handler builds quote #1 and the still-present inline
     * createTrajectoryQuote() builds quote #2 → count == 2. GREEN once Task 3
     * removes the inline creator → count == 1.
     *
     * @test
     */
    public function pricedWebFormTrajectoryEnrollProducesExactlyOneQuote(): void
    {
        $userId       = $this->createUserOfType(self::TYPE_SLUG);
        $trajectoryId = $this->createPricedTrajectory(10000); // 100.00 EUR in cents

        $result = $this->submitTrajectoryEnrollment($userId, $trajectoryId);
        self::assertIsArray($result, 'a priced web-form trajectory enroll must succeed: ' . $this->err($result));

        $regId = (int) $result['enrollment_id'];
        $this->createdRegistrationIds[] = $regId;

        self::assertSame(
            1,
            $this->countQuotesForRegistration($regId),
            'a priced web-form trajectory self-enroll must produce EXACTLY ONE quote — '
            . 'the event is the sole creator; the inline createTrajectoryQuote() double-quotes (RED: got two)',
        );
    }

    // === 5. REGRESSION AMOUNT (A4) — one quote, correct total ================

    /**
     * The single quote's SUBTOTAL equals the trajectory price in cents
     * (behavior-preserving, no ×100). Subtotal is the pre-VAT line-item sum — the
     * amount Task 3 must preserve; `total` folds in VAT which this task does not
     * touch, so asserting subtotal isolates the behavior under test. Reads through
     * getQuoteByRegistration (the surviving single quote).
     *
     * @test
     */
    public function theSingleWebFormTrajectoryQuoteCarriesTheTrajectoryPriceAsSubtotal(): void
    {
        $userId       = $this->createUserOfType(self::TYPE_SLUG);
        $priceCents   = 12500; // 125.00 EUR in cents
        $trajectoryId = $this->createPricedTrajectory($priceCents);

        $result = $this->submitTrajectoryEnrollment($userId, $trajectoryId);
        self::assertIsArray($result, 'enroll must succeed: ' . $this->err($result));

        $regId = (int) $result['enrollment_id'];
        $this->createdRegistrationIds[] = $regId;

        $quote = $this->quotes->getQuoteByRegistration($regId);
        self::assertIsArray($quote, 'the web-form trajectory enroll must leave a quote');
        self::assertSame(
            $priceCents,
            (int) ($quote['subtotal'] ?? 0),
            'the web-form trajectory quote subtotal must equal the trajectory price in cents (no ×100, behavior-preserving)',
        );
    }

    // === 2. NAMESPACED, SERVER-KEYED TRANSIENT (Cluster-1 security) =========

    /**
     * The web form MUST write the pending-billing transient under the byte-identical
     * key `stride_pending_billing_traj_{userId}_{trajectoryId}`, keyed on the
     * SERVER-SIDE authenticated current user — NEVER a client-supplied user id — and
     * a manual voucher_code supplied on the form MUST travel on that transient so the
     * event handler applies it.
     *
     * We prove all three at once by the OBSERVABLE effect the handler produces ONLY
     * when it read the correctly-keyed transient carrying the voucher: the discount
     * lands on the quote. The params carry a hostile client-supplied `user_id`
     * (a DIFFERENT user); if the web form keyed the transient on that instead of
     * get_current_user_id(), the handler (which reads
     * stride_pending_billing_traj_{currentUser}_{traj}) would find nothing and the
     * discount would be absent.
     *
     * @test
     */
    public function webFormWritesNamespacedTransientKeyedOnCurrentUserCarryingTheVoucher(): void
    {
        $userId       = $this->createUserOfType(self::TYPE_SLUG);
        $attackerId   = $this->createUserOfType(self::TYPE_SLUG); // a client-supplied "other" id
        $priceCents   = 10000;
        $trajectoryId = $this->createPricedTrajectory($priceCents);

        $code = $this->uniqueCode('WFVOU');
        $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_code'           => $code,
                '_ntdst_discount_type'  => \Stride\Domain\DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,
                '_ntdst_usage_limit'    => 5,
                '_ntdst_used_count'     => 0,
            ],
        ]);

        // Manual voucher supplied on the form; hostile client user_id in params.
        $result = $this->submitTrajectoryEnrollment($userId, $trajectoryId, [
            'voucher_code' => $code,
            'user_id'      => $attackerId, // must be IGNORED — server keys on current user
        ]);
        self::assertIsArray($result, 'enroll must succeed: ' . $this->err($result));

        $regId = (int) $result['enrollment_id'];
        $this->createdRegistrationIds[] = $regId;

        $quote = $this->quotes->getQuoteByRegistration($regId);
        self::assertIsArray($quote, 'the web-form trajectory enroll must leave a quote');

        // The discount only lands if the handler read a transient keyed on the
        // CURRENT user (not the client-supplied $attackerId) carrying voucher_code.
        self::assertGreaterThan(
            0,
            (int) ($quote['discount'] ?? 0),
            'the manual voucher must travel on the pending-billing transient keyed on the '
            . 'authenticated current user (stride_pending_billing_traj_{currentUser}_{traj}); '
            . 'a client-supplied user_id must NOT be used as the key',
        );
        self::assertSame(
            $code,
            (string) ($quote['voucher_code'] ?? ''),
            'the voucher carried on the current-user transient must be applied to the quote',
        );

        // Belt-and-braces: no transient was written under the attacker's id.
        self::assertFalse(
            get_transient('stride_pending_billing_traj_' . $attackerId . '_' . $trajectoryId),
            'no pending-billing transient may be keyed on a client-supplied user id',
        );
    }

    // === 3. PRICED ROLLBACK (A2-CRITICAL) ===================================

    /**
     * When a PRICED trajectory's quote never lands (no quote_id after enroll), the
     * registration must be CANCELLED and a WP_Error returned — never walk past
     * billing.
     *
     * The "no quote" condition is forced surgically WITHOUT touching the trajectory
     * post (so the trajectory stays PRICED for the caller's `$isPriced` re-read): a
     * `wp_insert_post_empty_content` filter aborts insertion of the `vad_quote` post
     * only, making QuoteService::createQuote return a WP_Error → the event handler
     * logs and returns with no quote_id. The trajectory price signal is intact, so
     * the caller's rollback branch (Task 3: priced && no quote_id) must fire.
     *
     * @test
     */
    public function pricedTrajectoryWithNoResultingQuoteIsRolledBack(): void
    {
        $userId       = $this->createUserOfType(self::TYPE_SLUG);
        $trajectoryId = $this->createPricedTrajectory(10000);

        // Abort ONLY the vad_quote insert; leave the trajectory untouched.
        $blockQuote = static function (bool $maybeEmpty, array $postarr): bool {
            return ($postarr['post_type'] ?? '') === 'vad_quote' ? true : $maybeEmpty;
        };
        add_filter('wp_insert_post_empty_content', $blockQuote, 10, 2);

        try {
            $result = $this->submitTrajectoryEnrollment($userId, $trajectoryId);
        } finally {
            remove_filter('wp_insert_post_empty_content', $blockQuote, 10);
        }

        self::assertInstanceOf(
            \WP_Error::class,
            $result,
            'a priced trajectory whose quote never lands must return a WP_Error, never a success',
        );

        // The registration must have been cancelled — never left confirmed without a quote.
        $regId = $this->latestRegistrationFor($userId, $trajectoryId);
        if ($regId !== null) {
            $this->createdRegistrationIds[] = $regId;
            $reg = $this->registrations->find($regId);
            self::assertNotNull($reg, 'the registration row should still exist (cancelled, not deleted)');
            self::assertSame(
                'cancelled',
                (string) $reg->status,
                'a priced trajectory with no resulting quote must be CANCELLED (A2-CRITICAL: never walk past billing)',
            );
        }
    }

    // === 4. FREE NOT CANCELLED (A2/F1) ======================================

    /**
     * A FREE (price 0) trajectory self-enroll must SUCCEED with no quote and the
     * registration must NOT be cancelled — free is a legitimate no-quote outcome,
     * not a billing failure.
     *
     * @test
     */
    public function freeTrajectoryEnrollSucceedsWithNoQuoteAndIsNotCancelled(): void
    {
        $userId       = $this->createUserOfType(self::TYPE_SLUG);
        $trajectoryId = $this->createPricedTrajectory(0); // FREE

        $result = $this->submitTrajectoryEnrollment($userId, $trajectoryId);
        self::assertIsArray(
            $result,
            'a FREE trajectory enroll must SUCCEED (no quote is legitimate, not a failure): ' . $this->err($result),
        );

        $regId = (int) $result['enrollment_id'];
        $this->createdRegistrationIds[] = $regId;

        self::assertSame(
            0,
            $this->countQuotesForRegistration($regId),
            'a free trajectory produces NO quote',
        );

        $reg = $this->registrations->find($regId);
        self::assertNotNull($reg, 'the free-trajectory registration must exist');
        self::assertNotSame(
            'cancelled',
            (string) $reg->status,
            'a FREE trajectory with no quote must NOT be cancelled (A2/F1: free is not a billing failure)',
        );
    }

    // === Fixtures ===========================================================

    private function uniqueCode(string $prefix): string
    {
        return $prefix . strtoupper(wp_generate_password(6, false, false));
    }

    private function err(mixed $result): string
    {
        return $result instanceof \WP_Error ? $result->get_error_message() : 'not a WP_Error';
    }

    private function createUserOfType(string $slug): int
    {
        $username = 'twfq_' . wp_generate_password(6, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        self::assertIsInt($userId, 'fixture: failed to create user');
        $this->createdUserIds[] = $userId;
        update_user_meta($userId, '_stride_profile_type', [$slug]);
        return $userId;
    }

    /** Open, published trajectory at the given price in CENTS (0 = free). */
    private function createPricedTrajectory(int $priceCents): int
    {
        $trajectoryId = wp_insert_post([
            'post_title'  => 'Web-Form Trajectory ' . wp_generate_password(4, false),
            'post_type'   => TrajectoryCPT::POST_TYPE,
            'post_status' => 'publish',
        ]);
        self::assertIsInt($trajectoryId, 'fixture: failed to create trajectory');
        self::$testPosts[] = $trajectoryId;

        // status 'open' — OfferingStatus::Open is the only case allowsEnrollment()
        // accepts, so isEnrollmentOpen() (the web-form gate) passes.
        ntdst_data()->get(TrajectoryCPT::POST_TYPE)->update($trajectoryId, [
            'status'           => 'open',
            'price'            => $priceCents,
            'price_non_member' => $priceCents,
        ]);

        return $trajectoryId;
    }

    /**
     * Drive the REAL public web-form seam un-mocked: handleSubmitEnrollment with
     * item_type=trajectory. Sets the current user (the server-side identity the
     * transient must key on) and posts the minimal valid billing payload.
     *
     * @param array<string, mixed> $extra extra params (e.g. voucher_code, hostile user_id)
     * @return array<string, mixed>|\WP_Error the handler's response
     */
    private function submitTrajectoryEnrollment(int $userId, int $trajectoryId, array $extra = []): array|\WP_Error
    {
        wp_set_current_user($userId);

        $params = array_merge([
            'item_type'      => 'trajectory',
            'trajectory_id'  => $trajectoryId,
            'first_name'     => 'Test',
            'last_name'      => 'Enrollee',
            'email'          => 'enrollee_' . wp_generate_password(4, false) . '@test.local',
            'terms_accepted' => true,
        ], $extra);

        return $this->handler->handleSubmitEnrollment(null, $params);
    }

    /** Count the vad_quote posts linked to a registration via registration_id meta. */
    private function countQuotesForRegistration(int $registrationId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'vad_quote'
               AND pm.meta_key = 'registration_id'
               AND pm.meta_value = %s",
            (string) $registrationId,
        ));
    }

    /** Most-recent registration id for a user+trajectory (rollback path where the response has no id). */
    private function latestRegistrationFor(int $userId, int $trajectoryId): ?int
    {
        global $wpdb;

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}vad_registrations
             WHERE user_id = %d AND trajectory_id = %d
             ORDER BY id DESC LIMIT 1",
            $userId,
            $trajectoryId,
        ));

        return $id !== null ? (int) $id : null;
    }
}
