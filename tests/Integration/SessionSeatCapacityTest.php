<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Seats-2: session seat capacity (count + gate on SessionService).
 *
 * Mirrors the edition seat pattern (EditionService::hasAvailableSpots /
 * getRegisteredCount) but the count is a JSON_CONTAINS match against the
 * `selections` column of vad_registrations — sessions are NOT a column, a
 * user's picked sessions live as a JSON array of session ids in `selections`.
 *
 * selections storage format (verified): RegistrationRepository::setSelections
 * writes wp_json_encode(array<int>), so a selection of session 123 is stored
 * as the JSON document `[123]` — integers, not strings. The JSON_CONTAINS
 * needle must therefore be the JSON integer literal `123`, not `"123"`.
 */
final class SessionSeatCapacityTest extends IntegrationTestCase
{
    private SessionService $service;
    private RegistrationRepository $registrations;

    // Post ids (editions + sessions) are tracked in the inherited static
    // self::$testPosts — IntegrationTestCase::tearDownAfterClass() deletes them.
    // (Do NOT redeclare $testPosts here: the parent declares it `static`, and a
    // non-static redeclaration is a fatal PHPUnit swallows as silent exit 255.)

    /** @var array<int> Registration ids to clean up. */
    private array $testRegIds = [];

    /** @var array<int> User ids to clean up. */
    private array $testUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service       = ntdst_get(SessionService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->testRegIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->testRegIds = [];

        foreach (self::$testPosts as $id) {
            wp_delete_post($id, true);
        }
        self::$testPosts = [];

        if ($this->testUserIds !== []) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ($this->testUserIds as $uid) {
                wp_delete_user($uid);
            }
            $this->testUserIds = [];
        }

        parent::tearDown();
    }

    private function createEdition(): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_edition',
            'post_title'  => 'Seat-cap test edition',
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = (int) $id;

        return (int) $id;
    }

    private function createSession(int $editionId, int $capacity): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => 'Seat-cap test session',
            'post_status' => 'publish',
        ]);
        // Stride uses the `_ntdst_` meta prefix — matches what
        // SessionRepository::getField('capacity'/'edition_id') reads.
        update_post_meta($id, '_ntdst_edition_id', $editionId);
        update_post_meta($id, '_ntdst_capacity', $capacity);
        self::$testPosts[] = (int) $id;

        return (int) $id;
    }

    /**
     * Create a registration in the given edition, then record the session ids
     * it selected via the real repository write path.
     *
     * @param array<int> $selectedSessionIds
     */
    private function createRegistrationSelecting(
        int $editionId,
        array $selectedSessionIds,
        string $status = 'confirmed',
    ): int {
        $username = 'seatcap_' . wp_generate_password(8, false);
        $userId   = wp_create_user($username, 'testpass123', $username . '@test.local');
        self::assertIsInt($userId, 'user create should return an int id');
        $this->testUserIds[] = $userId;

        $regId = $this->registrations->create([
            'user_id'    => $userId,
            'edition_id' => $editionId,
            'status'     => $status,
        ]);

        self::assertIsInt($regId, 'registration create should return an int id');
        $this->testRegIds[] = $regId;

        if ($selectedSessionIds !== []) {
            $this->registrations->setSelections($regId, $selectedSessionIds);
        }

        return $regId;
    }

    public function testZeroSelectionsLeavesSeatsAvailable(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);

        self::assertSame(0, $this->service->getSelectedCount($sessionId));
        self::assertTrue($this->service->hasAvailableSeats($sessionId));
    }

    public function testOneSelectionCountsOneAndLeavesSeatsAvailable(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);

        $this->createRegistrationSelecting($editionId, [$sessionId]);

        self::assertSame(1, $this->service->getSelectedCount($sessionId));
        self::assertTrue($this->service->hasAvailableSeats($sessionId));
    }

    public function testFullCapacityReportsNoSeatsAvailable(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);

        $this->createRegistrationSelecting($editionId, [$sessionId]);
        $this->createRegistrationSelecting($editionId, [$sessionId]);

        self::assertSame(2, $this->service->getSelectedCount($sessionId));
        self::assertFalse($this->service->hasAvailableSeats($sessionId));
    }

    public function testZeroCapacityMeansUnlimited(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 0);

        // Fill well past any plausible capacity.
        $this->createRegistrationSelecting($editionId, [$sessionId]);
        $this->createRegistrationSelecting($editionId, [$sessionId]);
        $this->createRegistrationSelecting($editionId, [$sessionId]);

        self::assertSame(3, $this->service->getSelectedCount($sessionId));
        self::assertTrue(
            $this->service->hasAvailableSeats($sessionId),
            'capacity 0 must be treated as unlimited regardless of count',
        );
    }

    public function testCancelledRegistrationsDoNotCount(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);

        // One active selection + one cancelled selection.
        $this->createRegistrationSelecting($editionId, [$sessionId], 'confirmed');
        $this->createRegistrationSelecting($editionId, [$sessionId], RegistrationStatus::Cancelled->value);

        self::assertSame(
            1,
            $this->service->getSelectedCount($sessionId),
            'only active-status registrations count toward seats',
        );
        self::assertTrue($this->service->hasAvailableSeats($sessionId));
    }

    public function testCountIsScopedToTheSessionsEdition(): void
    {
        $editionA = $this->createEdition();
        $editionB = $this->createEdition();

        // Session lives in edition A, capacity 2.
        $sessionId = $this->createSession($editionA, 2);

        // A registration in edition A selecting the session — counts.
        $this->createRegistrationSelecting($editionA, [$sessionId]);

        // A registration in edition B whose selections array contains the SAME
        // id value must NOT count toward this session (wrong edition scope).
        $this->createRegistrationSelecting($editionB, [$sessionId]);

        self::assertSame(
            1,
            $this->service->getSelectedCount($sessionId),
            'getSelectedCount must scope to the session edition, not just the id in the JSON array',
        );
        self::assertTrue($this->service->hasAvailableSeats($sessionId));
    }
}
