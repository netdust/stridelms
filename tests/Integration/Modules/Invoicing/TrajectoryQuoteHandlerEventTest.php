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
 * Task 2 (plan docs/plans/2026-07-07-trajectory-enroll-event-quote.md §7) —
 * TrajectoryQuoteHandler on the `stride/trajectory/registration/created` event.
 * RED-first contract test. MONEY BOUNDARY + voucher redemption + guards (Tier A).
 *
 * This is the load-bearing money task: it moves trajectory quoting onto the same
 * event-driven path editions already use, so a Partner-API trajectory enroll (and
 * any future non-web-form caller of enroll()) produces a quote + auto-voucher.
 *
 * SEAM UNDER TEST: the REAL event. Each case seeds a trajectory + a registration
 * row, then fires `do_action('stride/trajectory/registration/created', [ids])`
 * un-mocked → the registered TrajectoryQuoteHandler → real QuoteService::createQuote
 * → real ProfileTypePolicy::autoVoucherCode → real applyVoucher → real
 * VoucherService::redeemVoucher → real used_count. The handler is wired via the
 * $handlers list in stride-core.php, so firing the event is the whole trigger — no
 * reflected private method, no handler instance constructed by the test.
 *
 * The auto-voucher code is NEVER placed on the event payload — the payload carries
 * only server-minted ids (registration_id/user_id/trajectory_id). The handler must
 * resolve the auto code server-side from the ATTENDEE's stored profile type
 * (money + no-client-trust contract, plan §5).
 *
 * RED behavior with the signature shell: the event reaches the handler but its body
 * is empty, so NO quote is created — every "a quote must exist" assertion fails
 * behaviorally (not "class not found"). The implementer fills the body to green.
 *
 * Cases asserted (mirrors AutoVoucherTrajectoryTest, driven by the EVENT):
 *   1. CORE       — grant-type user's event → quote with auto-voucher + used_count++.
 *   2. DENIAL     — no-rule type → no auto-voucher, granting voucher's used_count unmoved.
 *   3. EXHAUSTED  — resolved over-cap code → quote STILL builds, no discount, no fatal.
 *   4. NO-STACKING— manual voucher supplied → auto path adds no second redemption.
 *   5. IDEMPOTENCY— firing the event TWICE for one registration → exactly ONE quote.
 *   6. NO-THROW   — a handler-internal failure never escapes do_action (A3).
 *   7. SCOPE      — an edition-scoped voucher on a trajectory still applies (editionScoped:false).
 *
 * This test is IMMUTABLE to the implementer: green it without weakening; escalate
 * (do not edit) if it is wrong. The SHELL body is the implementer's to fill.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryQuoteHandlerEvent'
 */
final class TrajectoryQuoteHandlerEventTest extends IntegrationTestCase
{
    use CleansUpLeakedQuotesTrait;

    private const GRANT_SLUG = 'vrijwilliger';   // type whose rule carries a voucher
    private const OTHER_SLUG = 'werknemer';      // type with NO voucher

    private const EVENT = 'stride/trajectory/registration/created';

    private QuoteService $quotes;
    private RegistrationRepository $registrations;

    /** @var array<int> registration/enrollment ids to hard-delete in tearDown */
    private array $createdRegistrationIds = [];
    /** @var array<int> user ids to delete in tearDown */
    private array $createdUserIds = [];
    /** @var array<string> transient keys to clear in tearDown */
    private array $seededTransientKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotes        = ntdst_get(QuoteService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);

        update_option('stride_profile_types', [
            ['slug' => self::GRANT_SLUG, 'label' => 'Vrijwilliger', 'description' => '', 'color' => '', 'icon' => '', 'order' => 1],
            ['slug' => self::OTHER_SLUG, 'label' => 'Werknemer', 'description' => '', 'color' => '', 'icon' => '', 'order' => 2],
        ]);
        ntdst_get(ProfileTypeService::class)->resetCache();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        // Purge the quotes this suite's enrollments produced BEFORE the
        // registration rows go away — reused registration ids + leaked vad_quote
        // posts cross-contaminate later runs' money assertions
        // (gotcha_leaked_quotes_registration_id_reuse). See the trait.
        $this->deleteQuotesForRegistrations($this->createdRegistrationIds);

        foreach ($this->createdRegistrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->createdRegistrationIds = [];

        foreach ($this->seededTransientKeys as $key) {
            delete_transient($key);
        }
        $this->seededTransientKeys = [];

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

    // === 1. CORE — event → quote with auto-voucher applied + REDEEMED ========

    /** @test */
    public function grantTypeUserEventProducesQuoteWithAutoVoucherAndRedemptionMovesUsedCount(): void
    {
        $code         = $this->uniqueCode('EAUTO');
        $voucherId    = $this->createTenPercentVoucher($code);
        $userId       = $this->createUserOfType(self::GRANT_SLUG);
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $code);

        $registrationId = $this->fireEvent($userId, $trajectoryId);

        $quote = $this->quotes->getQuoteByRegistration($registrationId);
        self::assertIsArray($quote, 'the event must create a quote for the trajectory registration');

        self::assertGreaterThan(
            0,
            (int) ($quote['discount'] ?? 0),
            'the auto-voucher discount must be applied to the trajectory quote built by the event handler',
        );
        self::assertSame(
            $code,
            (string) ($quote['voucher_code'] ?? ''),
            "the trajectory quote's voucher_code must be the auto-resolved code",
        );
        self::assertSame(
            1,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'REDEMPTION must move used_count — the event handler must applyVoucher (redeem), not calculate-only',
        );
    }

    // === 2. DENIAL — no-rule type → no auto-voucher, no cross-type theft =====

    /** @test */
    public function otherTypeUserEventGetsNoAutoVoucherAndGrantingVoucherUsedCountDoesNotMove(): void
    {
        $code         = $this->uniqueCode('EDENY');
        $voucherId    = $this->createTenPercentVoucher($code);
        // Rule grants the voucher to GRANT_SLUG only...
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $code);
        // ...but the enrolling user is a DIFFERENT type with no voucher rule.
        $userId       = $this->createUserOfType(self::OTHER_SLUG);

        $registrationId = $this->fireEvent($userId, $trajectoryId);

        $quote = $this->quotes->getQuoteByRegistration($registrationId);
        self::assertIsArray($quote, 'a quote is still created for the non-granting type');

        self::assertSame(
            0,
            (int) ($quote['discount'] ?? 0),
            'a non-granting type must NOT receive the auto-voucher discount',
        );
        self::assertSame(
            '',
            (string) ($quote['voucher_code'] ?? ''),
            "a non-granting type's trajectory quote must carry no auto-voucher code",
        );
        self::assertSame(
            0,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            "used_count must NOT move for another type's voucher — no cross-type theft on the event path",
        );
    }

    // === 3. EXHAUSTED (A5) — resolved-but-over-cap → quote still builds ======

    /** @test */
    public function resolvedButExhaustedAutoVoucherIsSkippedAndQuoteStillBuildsWithoutFatal(): void
    {
        $code = $this->uniqueCode('ECAP');
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

        // Must not fatal — the auto-application is skipped gracefully (A5).
        $registrationId = $this->fireEvent($userId, $trajectoryId);

        $quote = $this->quotes->getQuoteByRegistration($registrationId);
        self::assertIsArray($quote, 'the trajectory quote must STILL build when the resolved auto code is exhausted');
        self::assertSame(
            0,
            (int) ($quote['discount'] ?? 0),
            'an exhausted voucher yields no discount, but the quote survives (A5)',
        );
        self::assertSame(
            1,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'used_count must be unchanged when applyVoucher rejects the exhausted code',
        );
    }

    // === 4. NO-STACKING — manual voucher present → auto must not add a second =

    /** @test */
    public function manualVoucherOnPendingBillingMeansAutoDoesNotStackASecondRedemption(): void
    {
        $manualCode = $this->uniqueCode('EMAN');
        $autoCode   = $this->uniqueCode('EAUT');
        $this->createTenPercentVoucher($manualCode);
        $autoVoucherId = $this->createTenPercentVoucher($autoCode);

        $userId       = $this->createUserOfType(self::GRANT_SLUG);
        // The trajectory's rule grants the AUTO code to the user's type...
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $autoCode);

        // ...but a MANUAL voucher travels on the NAMESPACED pending-billing
        // transient the handler reads (plan §4 finding #3: _traj_ key). No
        // stacking: manual present ⇒ the auto path must NOT apply.
        $this->seedPendingBilling($userId, $trajectoryId, ['voucher_code' => $manualCode]);

        $registrationId = $this->fireEvent($userId, $trajectoryId);

        self::assertSame(
            0,
            (int) get_post_meta($autoVoucherId, '_ntdst_used_count', true),
            'a manual voucher present means the AUTO voucher must NOT additionally redeem (no stacking / no money-doubling)',
        );

        $quote = $this->quotes->getQuoteByRegistration($registrationId);
        self::assertIsArray($quote, 'the trajectory quote must exist');
        self::assertSame(
            $manualCode,
            (string) ($quote['voucher_code'] ?? ''),
            'the manually supplied voucher must win on the quote — the auto path must not replace it',
        );
    }

    // === 5. IDEMPOTENCY (A1) — event fired twice → exactly ONE quote =========

    /** @test */
    public function firingTheEventTwiceForOneRegistrationCreatesExactlyOneQuote(): void
    {
        $userId       = $this->createUserOfType(self::OTHER_SLUG);
        $trajectoryId = $this->createTrajectoryGranting(self::OTHER_SLUG, '');

        // Seed ONE registration row, fire the event against it TWICE.
        $registrationId = $this->seedRegistration($userId, $trajectoryId);
        $this->dispatch($userId, $trajectoryId, $registrationId);
        $this->dispatch($userId, $trajectoryId, $registrationId);

        $quote = $this->quotes->getQuoteByRegistration($registrationId);
        self::assertIsArray($quote, 'the first event must create a quote');

        // Exactly one vad_quote post keys on this registration id.
        global $wpdb;
        $quoteCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = 'registration_id' AND meta_value = %s",
            (string) $registrationId,
        ));
        self::assertSame(
            1,
            $quoteCount,
            'firing the event twice must create exactly ONE quote — the idempotency guard (getQuoteByRegistration) must early-return the second time (A1)',
        );
    }

    // === 6. NO-THROW (A3) — a handler-internal failure never escapes do_action

    /**
     * The event fires inside the non-transactional enroll(); WordPress do_action
     * does NOT catch exceptions, so a throwing handler would orphan the committed
     * registration + cascade rows and 500 the caller. The handler body must be
     * wrapped (catch \Throwable, log, return) so it NEVER escapes.
     *
     * We drive an adversarial payload — a trajectory_id that resolves to nothing
     * (the trajectory post is deleted after the registration is seeded) — a path
     * that could throw inside createQuote/getTrajectory. The contract: do_action
     * returns normally, no exception propagates to the caller.
     *
     * @test
     */
    public function handlerInternalFailureNeverEscapesDoAction(): void
    {
        $userId       = $this->createUserOfType(self::GRANT_SLUG);
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, '');
        $registrationId = $this->seedRegistration($userId, $trajectoryId);

        // Make the trajectory unresolvable AFTER the registration exists, so the
        // handler hits a not-found / broken read path when the event fires.
        wp_delete_post($trajectoryId, true);

        $threw = null;
        try {
            $this->dispatch($userId, $trajectoryId, $registrationId);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        self::assertNull(
            $threw,
            'a handler-internal failure must NOT escape do_action (A3) — the body must catch \Throwable and log, never propagate: '
                . ($threw ? $threw::class . ': ' . $threw->getMessage() : ''),
        );
    }

    /**
     * The BITING no-throw test (test-effectiveness audit 2026-07-07): the sibling
     * above drives an unresolvable trajectory, which hits the handler's graceful
     * not-found early-return (:69) BEFORE any throwing line — so it would stay green
     * even if the entire try/catch(\Throwable) were deleted. That is a blind guard.
     *
     * This case forces a genuine throw PAST the early returns: a VALID, priced,
     * resolvable trajectory (so getTrajectory returns non-null and the body proceeds
     * to createQuote), with a `save_post_vad_quote` hook that throws when createQuote
     * inserts the quote post. The throw originates deep inside createQuote — exactly
     * the class of failure the A3 wrap exists to contain. Contract: do_action returns
     * normally, no exception propagates to the (non-transactional) enroll() caller.
     * Delete the try/catch(\Throwable) and this test goes RED (the throw escapes).
     *
     * @test
     */
    public function aGenuineThrowInsideCreateQuoteIsCaughtAndNeverEscapesDoAction(): void
    {
        $userId       = $this->createUserOfType(self::GRANT_SLUG);
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, ''); // priced, resolvable
        $registrationId = $this->seedRegistration($userId, $trajectoryId);

        // Force a throw from DEEP inside createQuote's wp_insert_post — past every
        // early return. Fires only for vad_quote inserts so it can't disturb fixtures.
        $thrower = static function (int $postId, \WP_Post $post): void {
            if ($post->post_type === 'vad_quote') {
                throw new \RuntimeException('injected failure inside createQuote');
            }
        };
        add_action('save_post_vad_quote', $thrower, 10, 2);

        $threw = null;
        try {
            $this->dispatch($userId, $trajectoryId, $registrationId);
        } catch (\Throwable $e) {
            $threw = $e;
        } finally {
            remove_action('save_post_vad_quote', $thrower, 10);
        }

        self::assertNull(
            $threw,
            'a genuine throw inside createQuote must be caught by the handler\'s '
                . 'try/catch(\Throwable) and NEVER escape do_action (A3) — else it '
                . 'orphans the committed registration and 500s the enroll() caller. '
                . 'Got: ' . ($threw ? $threw::class . ': ' . $threw->getMessage() : ''),
        );
    }

    // === 7. SCOPE — edition-scoped voucher on a trajectory still applies ======

    /**
     * Parity with AutoVoucherTrajectoryTest::trajectoryAutoVoucherAppliesEvenWhenVoucherIsEditionScoped.
     * The trajectory quote stores trajectoryId in its edition_id field. applyVoucher
     * must be called editionScoped:false so an edition-scoped voucher granted to a
     * trajectory is NOT rejected by comparing its allowed edition against trajectoryId.
     *
     * @test
     */
    public function editionScopedAutoVoucherStillAppliesToTrajectory(): void
    {
        $code = $this->uniqueCode('ESCOPE');

        $scopedEditionId = $this->createTestEdition();
        $voucherId = $this->createTestVoucher([
            'code' => $code,
            'meta' => [
                '_ntdst_code'           => $code,
                '_ntdst_discount_type'  => DiscountType::Percentage->value,
                '_ntdst_discount_value' => 10,
                '_ntdst_usage_limit'    => 5,
                '_ntdst_used_count'     => 0,
                '_ntdst_scope_mode'     => 'only',
                '_ntdst_edition_id'     => $scopedEditionId,
            ],
        ]);

        $userId       = $this->createUserOfType(self::GRANT_SLUG);
        $trajectoryId = $this->createTrajectoryGranting(self::GRANT_SLUG, $code);

        $registrationId = $this->fireEvent($userId, $trajectoryId);

        $quote = $this->quotes->getQuoteByRegistration($registrationId);
        self::assertIsArray($quote, 'a quote must be created for the trajectory registration');

        self::assertGreaterThan(
            0,
            (int) ($quote['discount'] ?? 0),
            'an edition-scoped voucher granted to a trajectory must still apply — the handler must applyVoucher(editionScoped:false)',
        );
        self::assertSame(
            1,
            (int) get_post_meta($voucherId, '_ntdst_used_count', true),
            'redemption must move used_count once edition scope is correctly not-applied',
        );
    }

    // === Fixtures ============================================================

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
        $username = 'trajevt_' . wp_generate_password(6, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        self::assertIsInt($userId, 'fixture: failed to create user');
        $this->createdUserIds[] = $userId;
        update_user_meta($userId, '_stride_profile_type', [$slug]);
        return $userId;
    }

    /** Open, priced trajectory whose rule auto-grants $code to $slug (empty $code = no rule voucher). */
    private function createTrajectoryGranting(string $slug, string $code): int
    {
        $trajectoryId = wp_insert_post([
            'post_title'  => 'Event Trajectory ' . wp_generate_password(4, false),
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

        // Guard the round-trip the policy depends on so fixture drift fails loud here.
        $rules = ntdst_get(TrajectoryRepository::class)->getProfiletypeRules($trajectoryId);
        self::assertArrayHasKey($slug, $rules, 'fixture: profiletype_rules must round-trip on the trajectory');

        return $trajectoryId;
    }

    /**
     * Seed a confirmed trajectory registration row (the enrollment id the event
     * payload + getQuoteByRegistration key on).
     */
    private function seedRegistration(int $userId, int $trajectoryId): int
    {
        $registrationId = $this->registrations->create([
            'user_id'         => $userId,
            'trajectory_id'   => $trajectoryId,
            'status'          => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        self::assertIsInt($registrationId, 'fixture: could not seed trajectory registration row');
        $this->createdRegistrationIds[] = $registrationId;
        return $registrationId;
    }

    /**
     * Seed the NAMESPACED pending-billing transient the handler reads
     * (plan §4 finding #3 — trajectory key is `_traj_`, NOT the edition shape).
     *
     * @param array<string, mixed> $payload
     */
    private function seedPendingBilling(int $userId, int $trajectoryId, array $payload): void
    {
        $key = 'stride_pending_billing_traj_' . $userId . '_' . $trajectoryId;
        set_transient($key, $payload, HOUR_IN_SECONDS);
        $this->seededTransientKeys[] = $key;
    }

    /**
     * Fire the REAL event un-mocked after seeding a fresh registration row.
     * Returns the registration id so getQuoteByRegistration() can find the quote.
     * The auto code is NEVER on the payload — it must be resolved server-side.
     */
    private function fireEvent(int $userId, int $trajectoryId): int
    {
        $registrationId = $this->seedRegistration($userId, $trajectoryId);
        $this->dispatch($userId, $trajectoryId, $registrationId);
        return $registrationId;
    }

    /** Dispatch the event with the exact server-minted payload TrajectorySelection::enroll() emits. */
    private function dispatch(int $userId, int $trajectoryId, int $registrationId): void
    {
        wp_set_current_user($userId);
        do_action(self::EVENT, [
            'registration_id' => $registrationId,
            'user_id'         => $userId,
            'trajectory_id'   => $trajectoryId,
            'enrolled_by'     => null,
        ]);
    }
}
