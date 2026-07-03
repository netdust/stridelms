<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\AttendanceStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Attendance\AttendanceRepository;

/**
 * Perf audit 4B.2 — batchGetAttendanceForEditions must return the SAME
 * attendance map as N per-edition batchGetAttendance() calls (parity), and
 * must do so in a BOUNDED number of queries (one existence probe + one SELECT),
 * not one probe + one SELECT PER edition.
 *
 * The denial path here is the perf regression itself: if the batched method
 * ever falls back to a per-edition loop, the query-count assertion goes RED
 * (proven by temporarily reverting the implementation to the naive loop during
 * development — the count assertion fired).
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BatchGetAttendanceForEditions"
 */
final class BatchGetAttendanceForEditionsTest extends IntegrationTestCase
{
    private AttendanceRepository $attendance;

    /** @var array<int> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->attendance = ntdst_get(AttendanceRepository::class);
    }

    protected function tearDown(): void
    {
        if (!empty($this->sessionIds)) {
            $this->attendance->deleteBySessions($this->sessionIds);
            $this->sessionIds = [];
        }
        parent::tearDown();
    }

    private function seedRow(int $editionId, int $sessionId, int $userId, AttendanceStatus $status): void
    {
        $this->sessionIds[] = $sessionId;
        $this->attendance->record($sessionId, $userId, $status, $editionId);
    }

    /** @test */
    public function it_returns_the_same_map_as_n_per_edition_calls(): void
    {
        // Three editions, distinct users/sessions, plus one empty edition.
        $edA = 71001;
        $edB = 71002;
        $edC = 71003;
        $edEmpty = 71004; // no rows — must still appear as an empty entry

        $this->seedRow($edA, 81001, 91001, AttendanceStatus::Present);
        $this->seedRow($edA, 81002, 91001, AttendanceStatus::Absent);
        $this->seedRow($edA, 81001, 91002, AttendanceStatus::Present);
        $this->seedRow($edB, 82001, 92001, AttendanceStatus::Excused);
        $this->seedRow($edC, 83001, 93001, AttendanceStatus::Present);

        $editionIds = [$edA, $edB, $edC, $edEmpty];

        // Reference: the pre-batch behaviour, one call per edition.
        $reference = [];
        foreach ($editionIds as $editionId) {
            $reference[$editionId] = BatchQueryHelper::batchGetAttendance($editionId);
        }

        $batched = BatchQueryHelper::batchGetAttendanceForEditions($editionIds);

        // Parity: every edition key present, identical nested user→session→status maps.
        $this->assertSame(array_keys($reference), array_keys($batched));
        foreach ($editionIds as $editionId) {
            $this->assertEquals(
                $reference[$editionId],
                $batched[$editionId],
                "attendance map for edition {$editionId} diverged from the per-edition call",
            );
        }

        // Spot-check the actual shape so parity isn't "both empty".
        $this->assertSame(AttendanceStatus::Present->value, $batched[$edA][91001][81001]);
        $this->assertSame(AttendanceStatus::Absent->value, $batched[$edA][91001][81002]);
        $this->assertSame(AttendanceStatus::Excused->value, $batched[$edB][92001][82001]);
        $this->assertSame([], $batched[$edEmpty]);
    }

    /** @test */
    public function it_uses_a_bounded_query_count_regardless_of_edition_count(): void
    {
        global $wpdb;

        // Seed across five editions so a per-edition loop would be 5×(probe+select).
        $editionIds = [];
        for ($i = 0; $i < 5; $i++) {
            $editionId = 72000 + $i;
            $editionIds[] = $editionId;
            $this->seedRow($editionId, 84000 + $i, 94000 + $i, AttendanceStatus::Present);
        }

        $before = $wpdb->num_queries;
        $result = BatchQueryHelper::batchGetAttendanceForEditions($editionIds);
        $queriesRun = $wpdb->num_queries - $before;

        // One existence probe + one IN(...) SELECT = 2. A per-edition loop would
        // run 2 × 5 = 10. Assert the batched path stays at or below the bound and
        // is strictly fewer than the naive loop.
        $this->assertLessThanOrEqual(
            2,
            $queriesRun,
            "expected <=2 queries (probe + IN select), got {$queriesRun} — batching regressed to a loop",
        );

        // Sanity: it actually returned all five editions.
        $this->assertSame($editionIds, array_keys($result));
    }
}
