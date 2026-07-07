<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Trajectory;

use IntegrationTestCase;
use Stride\Domain\OfferingStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectorySelection;

/**
 * Task 1 (plan 2026-07-07-trajectory-enroll-event-quote §7) — RED-first
 * contract test. MONEY-ADJACENT EVENT SURFACE (Tier A, gate 1a §4).
 *
 * Contract under test (acceptance criteria, plan §2/§7 Task 1):
 *   TrajectorySelection::enroll() must, AFTER the existing
 *   `stride/trajectory/enrolled` notification (TrajectorySelection.php:87),
 *   fire a NEW dedicated event `stride/trajectory/registration/created`
 *   carrying ONLY the server-minted ids: registration_id (the created
 *   registration), user_id, trajectory_id, and enrolled_by
 *   ($options['enrolled_by'] ?? null — null on every current path; null-safe
 *   scaffolding). The payload is ids-only — it must NEVER carry a
 *   client-supplied voucher code or price (those are resolved downstream by
 *   the TrajectoryQuoteHandler, Task 2).
 *
 * Denial-adjacent assertion (the threat-model mitigation, §4 / §5 no-client-
 * trust contract): the payload contains only server-minted values, never a
 * voucher/price/code key. This is the money-boundary guarantee — the whole
 * reason a downstream quote handler can trust the event.
 *
 * The existing `stride/trajectory/enrolled` event must STILL fire (no
 * regression — the two events are additive, §7 Task 1).
 *
 * Seam: the REAL TrajectorySelection::enroll() driven un-mocked through the
 * container (ntdst_get), on a real seeded OPEN trajectory, spying on the
 * action via add_action — the un-mocked event seam Task 1 wires.
 *
 * This test is IMMUTABLE to the implementer: green it without weakening or
 * editing; escalate (do not rewrite) if it is wrong.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist \
 *    --filter TrajectoryRegistrationCreatedEvent'
 */
final class TrajectoryRegistrationCreatedEventTest extends IntegrationTestCase
{
    private const NEW_EVENT      = 'stride/trajectory/registration/created';
    private const EXISTING_EVENT = 'stride/trajectory/enrolled';

    private TrajectorySelection $selection;

    /** @var array<int> registration ids to hard-delete in tearDown */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->selection = ntdst_get(TrajectorySelection::class);
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        remove_all_actions(self::NEW_EVENT);
        remove_all_actions(self::EXISTING_EVENT);

        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    /**
     * CORE — the new event fires exactly once with the correct server-minted
     * ids after a successful enroll.
     *
     * @test
     */
    public function enrollFiresRegistrationCreatedEventWithServerMintedIds(): void
    {
        $trajectoryId = $this->createOpenPricedTrajectory();

        $spy = $this->captureEvent(self::NEW_EVENT);

        $registrationId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->assertIsInt($registrationId, 'enroll() must succeed on an open trajectory');
        $this->createdRegistrationIds[] = $registrationId;

        $this->assertCount(
            1,
            $spy->payloads,
            self::NEW_EVENT . ' must fire exactly once per successful enroll()',
        );

        $payload = $spy->payloads[0];
        $this->assertSame(
            $registrationId,
            (int) ($payload['registration_id'] ?? 0),
            'payload registration_id must be the id enroll() actually created (server-minted)',
        );
        $this->assertSame(
            self::$testUserId,
            (int) ($payload['user_id'] ?? 0),
            'payload user_id must be the enrolling user (server-minted)',
        );
        $this->assertSame(
            $trajectoryId,
            (int) ($payload['trajectory_id'] ?? 0),
            'payload trajectory_id must be the trajectory enrolled into (server-minted)',
        );
    }

    /**
     * SCAFFOLDING — enrolled_by is present and null on the current (self-enroll)
     * paths. Null-safe scaffolding for a future payer!=attendee path (§4
     * deferral); the key must exist so downstream can read it null-safely.
     *
     * @test
     */
    public function payloadCarriesNullEnrolledByOnSelfEnrollPath(): void
    {
        $trajectoryId = $this->createOpenPricedTrajectory();

        $spy = $this->captureEvent(self::NEW_EVENT);

        $registrationId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->assertIsInt($registrationId);
        $this->createdRegistrationIds[] = $registrationId;

        $this->assertCount(1, $spy->payloads);
        $this->assertArrayHasKey(
            'enrolled_by',
            $spy->payloads[0],
            'payload must carry enrolled_by (null-safe scaffolding) so downstream reads it without notice',
        );
        $this->assertNull(
            $spy->payloads[0]['enrolled_by'],
            'enrolled_by is null on every current self-enroll path (no caller passes it)',
        );
    }

    /**
     * DENIAL-ADJACENT (money boundary, §4/§5 no-client-trust contract) — the
     * payload is ids-only. It must NEVER carry a client-supplied voucher code
     * or price; those are resolved server-side downstream, not carried on the
     * event. A voucher/price key on this payload would be a money-integrity
     * regression (a client could then influence the discount via the event).
     *
     * @test
     */
    public function payloadNeverCarriesClientVoucherOrPrice(): void
    {
        $trajectoryId = $this->createOpenPricedTrajectory();

        $spy = $this->captureEvent(self::NEW_EVENT);

        // A voucher code IS supplied in $options — the payload must still NOT
        // echo it. The event is ids-only; a client-supplied code has no place
        // on it (proves the no-client-trust contract, not just its absence).
        $registrationId = $this->selection->enroll(
            self::$testUserId,
            $trajectoryId,
            ['voucher_code' => 'CLIENTHACK50', 'price' => 1],
        );
        $this->assertIsInt($registrationId);
        $this->createdRegistrationIds[] = $registrationId;

        $this->assertCount(1, $spy->payloads);
        $payload = $spy->payloads[0];

        foreach (['voucher_code', 'voucher', 'code', 'price', 'discount', 'amount', 'total'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                $payload,
                "payload must be ids-only — it must NEVER carry '{$forbidden}' (money resolved downstream, no client trust)",
            );
        }

        // Belt-and-braces: the client-supplied string must not appear anywhere
        // in the payload values, whatever key shape were used.
        $this->assertNotContains(
            'CLIENTHACK50',
            array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $payload),
            'a client-supplied voucher code must never travel on the event payload',
        );
    }

    /**
     * NO REGRESSION — the existing `stride/trajectory/enrolled` notification
     * must still fire; the new event is additive, not a replacement (§7 Task 1).
     *
     * @test
     */
    public function existingEnrolledEventStillFires(): void
    {
        $trajectoryId = $this->createOpenPricedTrajectory();

        $existingSpy = $this->captureEvent(self::EXISTING_EVENT);
        $newSpy      = $this->captureEvent(self::NEW_EVENT);

        $registrationId = $this->selection->enroll(self::$testUserId, $trajectoryId);
        $this->assertIsInt($registrationId);
        $this->createdRegistrationIds[] = $registrationId;

        $this->assertCount(
            1,
            $existingSpy->payloads,
            'the existing stride/trajectory/enrolled notification must still fire (no regression)',
        );
        $this->assertCount(
            1,
            $newSpy->payloads,
            'the new stride/trajectory/registration/created event must also fire (additive)',
        );
    }

    // === Helpers ============================================================

    /**
     * Register a spy on $event and return a collector whose ->payloads array
     * fills as the event fires.
     *
     * Returns an object (shared by reference between test and closure), not a
     * plain array — a returned array would be a value-copy taken at
     * registration time, so appends inside the closure would never reach the
     * test's copy.
     */
    private function captureEvent(string $event): object
    {
        $collector = new class {
            /** @var array<int, array<string, mixed>> */
            public array $payloads = [];
        };

        add_action(
            $event,
            static function ($payload) use ($collector): void {
                $collector->payloads[] = is_array($payload) ? $payload : ['__non_array' => $payload];
            },
            10,
            1,
        );

        return $collector;
    }

    /**
     * An OPEN, PRICED trajectory with no elective groups — a successful enroll
     * reaches the dispatch at TrajectorySelection.php:87. Priced so it mirrors
     * the real money path (a free trajectory is a separate flow, §8 F1).
     */
    private function createOpenPricedTrajectory(): int
    {
        $trajectoryId = wp_insert_post([
            'post_type'   => TrajectoryCPT::POST_TYPE,
            'post_title'  => 'Event trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createOpenPricedTrajectory failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        ntdst_data()->get(TrajectoryCPT::POST_TYPE)->update($trajectoryId, [
            'mode'     => TrajectoryMode::Cohort->value,
            'status'   => OfferingStatus::Open->value,
            'capacity' => 0, // unlimited
            'price'    => 10000, // 100.00 EUR in cents
            'courses'  => [],
        ]);

        return $trajectoryId;
    }
}
