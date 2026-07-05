<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Handlers\CompletionTaskHandler;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Seats-3: enforce per-session seat capacity on BOTH selection-write paths.
 *
 * Two write paths exist for a user's session picks:
 *   1. API path      — SessionSelection::setSelections (already validated deadline,
 *      locked, belongs-to-edition; MUST also gate on hasAvailableSeats).
 *   2. Completion path — CompletionTaskHandler::handleCompleteTask, which BYPASSED
 *      SessionSelection entirely by writing selections direct to the repo. Routing
 *      it through SessionSelection closes the bypass and gives it the seat gate.
 *
 * The gate blocks only NEWLY-ADDED full sessions: a user who already holds a
 * seat on a full session (re-submitting) must not be blocked by their own seat.
 *
 * Seeding mirrors SessionSeatCapacityTest (create OTHER registrations that select
 * the session to fill it). Cleanup tracks regs + users + posts — do NOT leak.
 */
final class SessionSeatGateTest extends IntegrationTestCase
{
    private SessionSelection $selection;
    private SessionService $sessions;
    private RegistrationRepository $registrations;

    // Post ids tracked in inherited static self::$testPosts (deleted in
    // tearDownAfterClass). Do NOT redeclare $testPosts (parent is static;
    // a non-static redeclaration is a fatal PHPUnit swallows as exit 255).

    /** @var array<int> Registration ids to clean up. */
    private array $testRegIds = [];

    /** @var array<int> User ids to clean up. */
    private array $testUserIds = [];

    private ?int $previousUserId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selection     = ntdst_get(SessionSelection::class);
        $this->sessions      = ntdst_get(SessionService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
        $this->previousUserId = get_current_user_id();
    }

    protected function tearDown(): void
    {
        global $wpdb;

        if ($this->previousUserId !== null) {
            wp_set_current_user($this->previousUserId);
        }

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
            'post_title'  => 'Seat-gate test edition',
            'post_status' => 'publish',
        ]);
        // Selection window open + no deadline so the completion path's
        // getTaskAvailability() leaves session_selection "available", and
        // SessionSelection's deadline check passes.
        update_post_meta((int) $id, '_ntdst_selection_open', 1);
        self::$testPosts[] = (int) $id;

        return (int) $id;
    }

    private function createSession(int $editionId, int $capacity): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => 'Seat-gate test session',
            'post_status' => 'publish',
        ]);
        update_post_meta((int) $id, '_ntdst_edition_id', $editionId);
        update_post_meta((int) $id, '_ntdst_capacity', $capacity);
        self::$testPosts[] = (int) $id;

        return (int) $id;
    }

    private function createUser(): int
    {
        $username = 'seatgate_' . wp_generate_password(8, false);
        $userId   = wp_create_user($username, 'testpass123', $username . '@test.local');
        self::assertIsInt($userId, 'user create should return an int id');
        $this->testUserIds[] = $userId;

        return $userId;
    }

    /**
     * @param array<int> $selectedSessionIds
     * @param array<string, mixed> $completionTasks
     */
    private function createRegistration(
        int $editionId,
        array $selectedSessionIds = [],
        string $status = 'confirmed',
        ?int $userId = null,
        array $completionTasks = [],
    ): int {
        $userId ??= $this->createUser();

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

        if ($completionTasks !== []) {
            $this->registrations->updateCompletionTasks($regId, $completionTasks);
        }

        return $regId;
    }

    /** Fill a session to capacity with OTHER users' active registrations. */
    private function fillSession(int $editionId, int $sessionId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->createRegistration($editionId, [$sessionId]);
        }
    }

    // === API path ===

    public function testApiPathRefusesFullSessionAndDoesNotWrite(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);

        // Fill the session with two OTHER users.
        $this->fillSession($editionId, $sessionId, 2);
        self::assertFalse($this->sessions->hasAvailableSeats($sessionId), 'precondition: session is full');

        // A fresh user tries to select the full session via the API path.
        $regId = $this->createRegistration($editionId);
        $result = $this->selection->setSelections($regId, [$sessionId]);

        self::assertInstanceOf(\WP_Error::class, $result, 'selecting a full session must be refused');
        self::assertSame('session_full', $result->get_error_code());

        // The selection must NOT have been written.
        self::assertSame(
            [],
            $this->registrations->getSelections($regId),
            'a refused selection must not be persisted',
        );
    }

    public function testApiPathAllowsSessionWithRoomAndWrites(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);

        $this->fillSession($editionId, $sessionId, 1); // 1 of 2 taken

        $regId = $this->createRegistration($editionId);
        $result = $this->selection->setSelections($regId, [$sessionId]);

        self::assertTrue($result, 'a session with room must be selectable');
        self::assertSame([$sessionId], $this->registrations->getSelections($regId));
    }

    // === Own-seat exemption ===

    public function testOwnSeatIsExemptWhenResubmitting(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);

        // Two users take the two seats — one of them is OUR user.
        $otherReg = $this->createRegistration($editionId, [$sessionId]);
        $userId   = $this->createUser();
        $regId    = $this->createRegistration($editionId, [$sessionId], 'confirmed', $userId);

        // Session is now full (2/2), and OUR user already holds one of those seats.
        self::assertFalse($this->sessions->hasAvailableSeats($sessionId), 'precondition: full');

        // Re-submitting with the SAME session (already held) must NOT be blocked.
        $result = $this->selection->setSelections($regId, [$sessionId]);

        self::assertTrue(
            $result,
            'a user re-submitting a session they already hold must not be blocked by their own seat',
        );
        self::assertSame([$sessionId], $this->registrations->getSelections($regId));

        unset($otherReg);
    }

    public function testNewlyAddedFullSessionBlockedEvenWhenOtherPicksHeld(): void
    {
        $editionId = $this->createEdition();
        $openSession = $this->createSession($editionId, 5);
        $fullSession = $this->createSession($editionId, 1);

        // Our user already holds the open session.
        $regId = $this->createRegistration($editionId, [$openSession]);

        // Someone else fills the second session.
        $this->createRegistration($editionId, [$fullSession]);
        self::assertFalse($this->sessions->hasAvailableSeats($fullSession), 'precondition: full');

        // Re-submit keeping the held open session but ADDING the full one.
        $result = $this->selection->setSelections($regId, [$openSession, $fullSession]);

        self::assertInstanceOf(\WP_Error::class, $result, 'a newly-added full session must be refused');
        self::assertSame('session_full', $result->get_error_code());

        // Nothing changed — the held open session is still the only selection.
        self::assertSame([$openSession], $this->registrations->getSelections($regId));
    }

    // === Capacity 0 (unlimited) ===

    public function testUnlimitedCapacityNeverBlocksOnApiPath(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 0);

        // Fill well past any plausible capacity.
        $this->fillSession($editionId, $sessionId, 3);

        $regId  = $this->createRegistration($editionId);
        $result = $this->selection->setSelections($regId, [$sessionId]);

        self::assertTrue($result, 'capacity 0 must never block a selection');
        self::assertSame([$sessionId], $this->registrations->getSelections($regId));
    }

    // === Cache invalidation ===

    public function testSuccessfulWriteInvalidatesSelectedCountCache(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 5);

        // Warm the cache at 0.
        self::assertSame(0, $this->sessions->getSelectedCount($sessionId));

        $regId = $this->createRegistration($editionId);
        $result = $this->selection->setSelections($regId, [$sessionId]);
        self::assertTrue($result);

        // Without the invalidate call, the 60s transient would still read 0.
        self::assertSame(
            1,
            $this->sessions->getSelectedCount($sessionId),
            'the selected-count cache must be invalidated on write so the count is fresh',
        );
    }

    // === Completion path (bypass closure) ===

    /** @return array<string, mixed> */
    private function sessionSelectionTasks(): array
    {
        return [
            'session_selection' => [
                'status' => 'pending',
                'phase'  => 'enrollment',
            ],
        ];
    }

    public function testCompletionPathRefusesFullSession(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);

        $this->fillSession($editionId, $sessionId, 2);
        self::assertFalse($this->sessions->hasAvailableSeats($sessionId), 'precondition: full');

        $userId = $this->createUser();
        $regId  = $this->createRegistration(
            $editionId,
            [],
            'confirmed',
            $userId,
            $this->sessionSelectionTasks(),
        );

        wp_set_current_user($userId);

        $handler = ntdst_get(CompletionTaskHandler::class);
        $result  = $handler->handleCompleteTask(null, [
            'registration_id' => $regId,
            'task_type'       => 'session_selection',
            'task_data'       => ['session_ids' => [$sessionId]],
        ]);

        self::assertInstanceOf(
            \WP_Error::class,
            $result,
            'completion path must refuse a full session (bypass closed)',
        );
        self::assertSame('session_full', $result->get_error_code());

        // The refused selection must not be written.
        self::assertSame([], $this->registrations->getSelections($regId));
    }

    public function testCompletionPathAllowsSessionWithRoomAndWrites(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 5);

        $userId = $this->createUser();
        $regId  = $this->createRegistration(
            $editionId,
            [],
            'confirmed',
            $userId,
            $this->sessionSelectionTasks(),
        );

        wp_set_current_user($userId);

        $handler = ntdst_get(CompletionTaskHandler::class);
        $result  = $handler->handleCompleteTask(null, [
            'registration_id' => $regId,
            'task_type'       => 'session_selection',
            'task_data'       => ['session_ids' => [$sessionId]],
        ]);

        self::assertIsArray($result, 'completion of an available session must succeed');
        self::assertTrue($result['completed'] ?? false);
        self::assertSame([$sessionId], $this->registrations->getSelections($regId));
    }

    public function testCompletionPathUnlimitedCapacityNeverBlocks(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 0);

        $this->fillSession($editionId, $sessionId, 3);

        $userId = $this->createUser();
        $regId  = $this->createRegistration(
            $editionId,
            [],
            'confirmed',
            $userId,
            $this->sessionSelectionTasks(),
        );

        wp_set_current_user($userId);

        $handler = ntdst_get(CompletionTaskHandler::class);
        $result  = $handler->handleCompleteTask(null, [
            'registration_id' => $regId,
            'task_type'       => 'session_selection',
            'task_data'       => ['session_ids' => [$sessionId]],
        ]);

        self::assertIsArray($result, 'capacity 0 must never block the completion path');
        self::assertSame([$sessionId], $this->registrations->getSelections($regId));
    }
}
