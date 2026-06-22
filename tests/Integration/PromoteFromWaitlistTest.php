<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for EnrollmentService::promoteFromWaitlist (Task 2.2, Decision 1).
 *
 * Promote shares the SAME grant + event semantics as a normal confirm: one
 * grant path, one stride/registration/confirmed event. The capacity re-check
 * uses the race-safe FOR UPDATE path, so this needs the real DB.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PromoteFromWaitlist
 */
class PromoteFromWaitlistTest extends IntegrationTestCase
{
    private EnrollmentService $enrollmentService;
    private RegistrationRepository $registrations;
    private array $testRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->enrollmentService = ntdst_get(EnrollmentService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        foreach ($this->testRegistrationIds as $regId) {
            if ($regId) {
                $this->deleteTestRegistration($regId);
            }
        }
        $this->testRegistrationIds = [];

        parent::tearDown();
    }

    /**
     * @return array{0:int,1:int} [registrationId, confirmedEventCount-by-ref-not-used]
     */
    private function seedWaitlistRegistration(int $editionId): int
    {
        $regId = $this->registrations->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'waitlist',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId);
        $this->testRegistrationIds[] = $regId;

        return $regId;
    }

    /**
     * @test
     * Waitlist -> Confirmed on an edition with a free seat: grants access and
     * fires stride/registration/confirmed exactly once (same as a normal confirm).
     */
    public function promotesWaitlistRowOnEditionWithFreeSeat(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);
        $regId = $this->seedWaitlistRegistration($editionId);

        $confirmedEvents = 0;
        $listener = function (array $data) use (&$confirmedEvents, $regId): void {
            if ((int) ($data['registration_id'] ?? 0) === $regId) {
                $confirmedEvents++;
            }
        };
        add_action('stride/registration/confirmed', $listener);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        remove_action('stride/registration/confirmed', $listener);

        $this->assertTrue($result, 'promoteFromWaitlist should return true on success');
        $this->assertTrue(
            $this->enrollmentService->isEnrolled(self::$testUserId, $editionId),
            'Row should now be confirmed (enrolled)',
        );
        $this->assertSame(1, $confirmedEvents, 'stride/registration/confirmed must fire exactly once');
    }

    /**
     * @test
     * A full edition is skipped: per-row capacity re-check returns capacity_full
     * and the row stays on the waitlist (no grant, no event).
     */
    public function rejectsPromoteWhenEditionIsFull(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 1]]);

        // Fill the single seat with a confirmed registration.
        $confirmedId = $this->registrations->create([
            'user_id' => self::$testUserId + 1,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->testRegistrationIds[] = $confirmedId;

        $regId = $this->seedWaitlistRegistration($editionId);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('capacity_full', $result->get_error_code());

        // Untouched: still waitlist.
        $row = $this->registrations->find($regId);
        $this->assertSame('waitlist', $row->status);
    }

    /**
     * @test
     * A non-waitlist row (e.g. confirmed) is rejected with invalid_status.
     */
    public function rejectsPromoteOnNonWaitlistRow(): void
    {
        $editionId = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 5]]);
        $regId = $this->registrations->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->testRegistrationIds[] = $regId;

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('invalid_status', $result->get_error_code());
    }

    /**
     * @test
     * A terminal edition (cancelled) rejects promote with edition_closed (INV-7).
     */
    public function rejectsPromoteOnTerminalEdition(): void
    {
        $editionId = $this->createTestEdition([
            'meta' => ['_ntdst_status' => 'cancelled', '_ntdst_capacity' => 5],
        ]);
        $regId = $this->seedWaitlistRegistration($editionId);

        $result = $this->enrollmentService->promoteFromWaitlist($regId);

        $this->assertTrue(is_wp_error($result));
        $this->assertSame('edition_closed', $result->get_error_code());
    }
}
