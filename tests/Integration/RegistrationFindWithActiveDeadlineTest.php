<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for RegistrationRepository::findWithActiveDeadline()
 * (Phase 2 Task 2.3 — the cron enumeration query, threat-model A2).
 *
 * Contract:
 *  - status IN ('confirmed', 'pending') only (cancelled/completed/interest/
 *    waitlist excluded — status-filter denial path).
 *  - edition has a non-empty _ntdst_gate_deadline OR _ntdst_post_gate_deadline
 *    (deadline-predicate denial path: both empty excludes the row).
 *  - Bounded: explicit LIMIT/OFFSET, clamped defensively (mitigation 5).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationFindWithActiveDeadline
 */
final class RegistrationFindWithActiveDeadlineTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    private function createReg(int $editionId, string $status): int
    {
        $regId = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'status' => $status,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($regId, 'Test fixture registration must be created');
        $this->createdRegistrationIds[] = $regId;

        return $regId;
    }

    /** @test */
    public function confirmedRegOnEditionWithGateDeadlineIsIncluded(): void
    {
        $edition = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => '2026-08-01']]);
        $regId = $this->createReg($edition, 'confirmed');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertContains($regId, $ids, 'Confirmed reg on an edition with gate_deadline must be included');
    }

    /** @test */
    public function confirmedRegOnEditionWithPostGateDeadlineOnlyIsIncluded(): void
    {
        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => '',
            '_ntdst_post_gate_deadline' => '2026-09-01',
        ]]);
        $regId = $this->createReg($edition, 'confirmed');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertContains($regId, $ids, 'Confirmed reg on an edition with only post_gate_deadline must be included');
    }

    /** @test */
    public function cancelledRegOnEditionWithDeadlineIsExcluded(): void
    {
        $edition = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => '2026-08-01']]);
        $regId = $this->createReg($edition, 'cancelled');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertNotContains($regId, $ids, 'Cancelled reg must be excluded by the status filter (denial path)');
    }

    /** @test */
    public function confirmedRegOnEditionWithNoDeadlineIsExcluded(): void
    {
        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => '',
            '_ntdst_post_gate_deadline' => '',
        ]]);
        $regId = $this->createReg($edition, 'confirmed');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertNotContains($regId, $ids, 'Confirmed reg on an edition with no deadline must be excluded (deadline predicate denial path)');
    }

    /** @test */
    public function pendingRegWithDeadlineIsIncluded(): void
    {
        $edition = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => '2026-08-01']]);
        $regId = $this->createReg($edition, 'pending');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertContains($regId, $ids, 'Pending reg with a deadline must be included — both confirmed+pending are in scope');
    }

    /** @test */
    public function limitAndOffsetAreRespected(): void
    {
        // 3 distinct editions (rather than 1 edition + 3 regs for the same
        // user) — RegistrationRepository::create() enforces one registration
        // per user+edition, so reusing an edition across create() calls for
        // the same test user hits the duplicate-registration guard.
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => '2026-08-01']]);
        $editionB = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => '2026-08-02']]);
        $editionC = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => '2026-08-03']]);
        $this->createReg($editionA, 'confirmed');
        $this->createReg($editionB, 'confirmed');
        $this->createReg($editionC, 'confirmed');

        $page1 = $this->repo->findWithActiveDeadline(2, 0);
        $page2 = $this->repo->findWithActiveDeadline(2, 2);

        $this->assertCount(2, $page1, 'First page of 2 must return exactly 2 rows');
        $this->assertGreaterThanOrEqual(1, count($page2), 'Second page (offset 2) must return at least the remaining row');
        $this->assertLessThanOrEqual(2, count($page2), 'Second page must never exceed the requested limit');
    }
}
