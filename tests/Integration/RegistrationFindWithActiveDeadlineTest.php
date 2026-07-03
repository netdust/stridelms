<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for RegistrationRepository::findWithActiveDeadline()
 * (Phase 2 Task 2.3 — the cron enumeration query, threat-model A2;
 * scalability audit 2026-07-03 finding #7 — date floor + keyset paging).
 *
 * Contract:
 *  - status IN ('confirmed', 'pending') only (cancelled/completed/interest/
 *    waitlist excluded — status-filter denial path).
 *  - edition has a non-empty _ntdst_gate_deadline OR _ntdst_post_gate_deadline
 *    (deadline-predicate denial path: both empty excludes the row).
 *  - DATE FLOOR (finding #7): at least one of those deadlines must be >= today.
 *    A registration whose only deadline is in the past is NOT enumerated, so
 *    confirmed-incomplete rows on long-expired editions stop being re-scanned
 *    every cron tick forever.
 *  - Keyset paging (finding #7): findWithActiveDeadline($limit, $afterId)
 *    orders by r.id ASC and returns rows with r.id > $afterId — no OFFSET,
 *    so each matching row is visited exactly once across a paged run.
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

    /** Date-relative to the real "today" the query floors against. */
    private function futureDate(int $days): string
    {
        return (new \DateTimeImmutable(current_time('Y-m-d')))->modify("+{$days} days")->format('Y-m-d');
    }

    private function pastDate(int $days): string
    {
        return (new \DateTimeImmutable(current_time('Y-m-d')))->modify("-{$days} days")->format('Y-m-d');
    }

    /** @test */
    public function confirmedRegOnEditionWithGateDeadlineIsIncluded(): void
    {
        $edition = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->futureDate(30)]]);
        $regId = $this->createReg($edition, 'confirmed');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertContains($regId, $ids, 'Confirmed reg on an edition with a future gate_deadline must be included');
    }

    /** @test */
    public function confirmedRegOnEditionWithPostGateDeadlineOnlyIsIncluded(): void
    {
        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => '',
            '_ntdst_post_gate_deadline' => $this->futureDate(60),
        ]]);
        $regId = $this->createReg($edition, 'confirmed');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertContains($regId, $ids, 'Confirmed reg on an edition with only an in-range post_gate_deadline must be included');
    }

    /** @test */
    public function cancelledRegOnEditionWithDeadlineIsExcluded(): void
    {
        $edition = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->futureDate(30)]]);
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
        $edition = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->futureDate(30)]]);
        $regId = $this->createReg($edition, 'pending');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertContains($regId, $ids, 'Pending reg with a deadline must be included — both confirmed+pending are in scope');
    }

    // === Finding #7: date floor (the "re-scanned forever" bug) ===

    /** @test */
    public function confirmedRegWhosePastDeadlineExpiredIsExcluded(): void
    {
        // The bug: a confirmed-incomplete reg on an edition whose deadline
        // passed months ago is re-enumerated every cron tick forever, because
        // confirmed rows only leave via completion/cancellation. With the date
        // floor, a deadline strictly before today drops the row out.
        $edition = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->pastDate(90)]]);
        $regId = $this->createReg($edition, 'confirmed');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertNotContains($regId, $ids, 'A confirmed reg whose only deadline is in the past must NOT be enumerated (finding #7 date floor)');
    }

    /** @test */
    public function todayDeadlineIsStillIncluded(): void
    {
        // Boundary: the deadline-tomorrow / day-of mail must still be able to
        // fire, so a deadline landing exactly on today stays enumerable.
        $edition = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => current_time('Y-m-d')]]);
        $regId = $this->createReg($edition, 'confirmed');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertContains($regId, $ids, 'A deadline landing exactly on today must remain enumerable (day-of/day-before mail still fires)');
    }

    /** @test */
    public function pastEnrollDeadlineButFuturePostDeadlineIsIncluded(): void
    {
        // Mixed case: enroll-phase gate is long past, but the post-phase gate
        // is still in the future. The row must still match on the in-range
        // post deadline (the "at least one non-null AND in-range" invariant).
        $edition = $this->createTestEdition(['meta' => [
            '_ntdst_gate_deadline' => $this->pastDate(90),
            '_ntdst_post_gate_deadline' => $this->futureDate(30),
        ]]);
        $regId = $this->createReg($edition, 'confirmed');

        $rows = $this->repo->findWithActiveDeadline();
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertContains($regId, $ids, 'A row with a past enroll deadline but a future post deadline must still be enumerated on the in-range post deadline');
    }

    // === Finding #7: keyset pagination (no OFFSET drift) ===

    /** @test */
    public function keysetPaginationVisitsEachRowExactlyOnce(): void
    {
        // 3 distinct editions (create() enforces one registration per
        // user+edition, so the same test user cannot re-register an edition).
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->futureDate(31)]]);
        $editionB = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->futureDate(32)]]);
        $editionC = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->futureDate(33)]]);
        $idA = $this->createReg($editionA, 'confirmed');
        $idB = $this->createReg($editionB, 'confirmed');
        $idC = $this->createReg($editionC, 'confirmed');

        $mine = [$idA, $idB, $idC];

        // Walk the whole set the way the cron loop does: keyset by last-seen id.
        $seen = [];
        $afterId = 0;
        do {
            $page = $this->repo->findWithActiveDeadline(2, $afterId);
            foreach ($page as $row) {
                $seen[] = (int) $row->id;
            }
            if ($page) {
                $afterId = (int) end($page)->id;
            }
        } while (count($page) === 2);

        // Every fixture row visited exactly once (no drift, no dupes).
        $seenMine = array_values(array_intersect($seen, $mine));
        sort($seenMine);
        $expected = $mine;
        sort($expected);
        $this->assertSame($expected, $seenMine, 'Keyset paging must visit each matching row exactly once');
        $this->assertSame(count($seen), count(array_unique($seen)), 'No row may be returned twice across keyset pages');

        // Keyset result set equals a single unpaginated query.
        $single = $this->repo->findWithActiveDeadline(1000, 0);
        $singleIds = array_map(static fn($row) => (int) $row->id, $single);
        foreach ($mine as $id) {
            $this->assertContains($id, $singleIds, 'The unpaginated query must contain every matching fixture row');
        }
    }

    /** @test */
    public function afterIdExcludesRowsAtOrBelowTheCursor(): void
    {
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->futureDate(34)]]);
        $editionB = $this->createTestEdition(['meta' => ['_ntdst_gate_deadline' => $this->futureDate(35)]]);
        $idA = $this->createReg($editionA, 'confirmed');
        $idB = $this->createReg($editionB, 'confirmed');

        $lower = min($idA, $idB);
        $higher = max($idA, $idB);

        $rows = $this->repo->findWithActiveDeadline(1000, $lower);
        $ids = array_map(static fn($row) => (int) $row->id, $rows);

        $this->assertNotContains($lower, $ids, 'A row at the cursor id must be excluded (r.id > afterId, strict)');
        $this->assertContains($higher, $ids, 'A row above the cursor id must still be returned');
    }
}
