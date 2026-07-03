<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\User\UserDashboardService;

/**
 * Integration test for Task 3.2 (Phase 3, gate-deadlines-reminders): the
 * 'overdue' flag computed by EnrollmentCompletion::getTaskAvailability()
 * (Task 3.1) must reach the dashboard payload the render surfaces consume —
 * proving the flag actually threads through UserDashboardService's real
 * aggregation chain, not just the isolated unit under test.
 *
 * Real chain: RegistrationRepository::create() (real DB row) ->
 * UserDashboardService::getEnrollmentData() -> buildEditionRegistrations()
 * -> buildTaskSummaryFromData() -> EnrollmentCompletion::getTaskAvailability()
 * (real getField() read of the edition's gate_deadline meta) -> verbatim
 * 'availability' passthrough into the payload. No mocks anywhere on this
 * chain.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CompletionOverdueSurfaces
 */
final class CompletionOverdueSurfacesTest extends IntegrationTestCase
{
    private UserDashboardService $dashboard;
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dashboard = ntdst_get(UserDashboardService::class);
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->actingAs(self::$testUserId);

        // The container service persists across tests in-process; reset the
        // per-user memo through the real invalidation point so each test
        // starts cold (see UserDashboardMemoizationTest for this pattern).
        $this->repo->clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];
        $this->repo->clearCache();

        parent::tearDown();
    }

    /**
     * Real-chain assertion: an edition with a PAST gate_deadline + a pending
     * registration carrying an open 'questionnaire' task -> the dashboard
     * payload's availability entry for that task carries overdue=true.
     *
     * @test
     */
    public function dashboardPayloadCarriesOverdueTrueForPastGateDeadline(): void
    {
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_requires_questionnaire' => '1',
                '_ntdst_gate_deadline' => '2020-01-01',
            ],
        ]);

        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Pending->value,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId, 'registration must be created');
        $this->createdRegistrationIds[] = $regId;

        $this->repo->updateCompletionTasks($regId, [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);
        $this->repo->clearCache();

        $data = $this->dashboard->getEnrollmentData(self::$testUserId);

        $registration = null;
        foreach ($data['active_editions'] as $reg) {
            if ($reg['edition_id'] === $editionId) {
                $registration = $reg;
                break;
            }
        }

        $this->assertNotNull($registration, 'the seeded registration must appear in active_editions');
        $availability = $registration['task_summary']['availability'] ?? [];

        $this->assertTrue(
            $availability['questionnaire']['overdue'] ?? null,
            'dashboard payload must carry overdue=true for an open gate task past its gate_deadline',
        );
        $this->assertSame(
            'available',
            $availability['questionnaire']['state'] ?? null,
            'D3: overdue must NOT lock the task — state stays available',
        );
    }

    /**
     * Negative case: same edition/task shape but a FUTURE gate_deadline ->
     * overdue must be false. Proves the flag is deadline-derived, not a
     * blanket true for every open gate task.
     *
     * @test
     */
    public function dashboardPayloadCarriesOverdueFalseForFutureGateDeadline(): void
    {
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_requires_questionnaire' => '1',
                '_ntdst_gate_deadline' => '2099-01-01',
            ],
        ]);

        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Pending->value,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId, 'registration must be created');
        $this->createdRegistrationIds[] = $regId;

        $this->repo->updateCompletionTasks($regId, [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);
        $this->repo->clearCache();

        $data = $this->dashboard->getEnrollmentData(self::$testUserId);

        $registration = null;
        foreach ($data['active_editions'] as $reg) {
            if ($reg['edition_id'] === $editionId) {
                $registration = $reg;
                break;
            }
        }

        $this->assertNotNull($registration, 'the seeded registration must appear in active_editions');
        $availability = $registration['task_summary']['availability'] ?? [];

        $this->assertFalse(
            $availability['questionnaire']['overdue'] ?? null,
            'a future gate_deadline must not mark the task overdue',
        );
    }
}
