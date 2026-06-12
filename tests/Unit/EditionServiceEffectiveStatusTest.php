<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Membership\MembershipService;
use Stride\Tests\TestCase;

/**
 * Task G1 (audit 2.2) — INV-7 decision-engine contract.
 *
 * `getEffectiveStatusFromPrefetched()` is THE single decision point for
 * display status (terminal wins; past dates → Completed; classroom with
 * zero published sessions → Announcement). `getEffectiveStatus()` must
 * DELEGATE to it — equivalence between the two paths is asserted against
 * the real database in tests/Integration/CatalogBatchHydrationTest.php.
 *
 * The denial path here: every matrix row where effective ≠ stored proves
 * a forked/naive "read the stored status" implementation FAILS this test.
 */
class EditionServiceEffectiveStatusTest extends TestCase
{
    private EditionService $service;
    private EditionRepository $repository;
    private SessionRepository $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->createMock(EditionRepository::class);
        $this->sessions = $this->createMock(SessionRepository::class);
        $membership = $this->createMock(MembershipService::class);

        $this->service = $this->getMockBuilder(EditionService::class)
            ->setConstructorArgs([$this->repository, $this->sessions, $membership])
            ->onlyMethods(['init'])
            ->getMock();
    }

    /**
     * The full state matrix from the task contract: terminal stored status,
     * past end-date, classroom-no-sessions, and plain open.
     *
     * @return array<string, array{0: OfferingStatus, 1: ?string, 2: ?string, 3: bool, 4: int, 5: OfferingStatus}>
     */
    public static function statusMatrix(): array
    {
        $future = date('Y-m-d', strtotime('+30 days'));
        $past = date('Y-m-d', strtotime('-30 days'));

        return [
            // Terminal stored statuses always win — even with past dates / no sessions.
            'cancelled stays cancelled' => [OfferingStatus::Cancelled, $past, $past, true, 0, OfferingStatus::Cancelled],
            'archived stays archived' => [OfferingStatus::Archived, null, null, true, 0, OfferingStatus::Archived],
            'completed stays completed' => [OfferingStatus::Completed, $future, $future, false, 3, OfferingStatus::Completed],

            // Past end-date → Completed regardless of stored intent (effective ≠ stored).
            'open with past end-date reads completed' => [OfferingStatus::Open, $past, $past, true, 3, OfferingStatus::Completed],
            'full with past end-date reads completed' => [OfferingStatus::Full, $past, $past, false, 0, OfferingStatus::Completed],
            'past start-date fallback when end missing' => [OfferingStatus::Open, null, $past, false, 0, OfferingStatus::Completed],

            // CR-G2 mutant kill: end_date has PRECEDENCE over start_date.
            // An in-progress multi-week edition (start past, END FUTURE) must
            // stay Open — the survived mutant ($startDate ?: $endDate) would
            // flip it to Completed and vanish it from the catalog.
            'in-progress multi-week edition stays open' => [OfferingStatus::Open, $future, $past, true, 3, OfferingStatus::Open],
            // Rule priority: past dates (rule 2) win over classroom-no-sessions
            // Announcement (rule 3) — a past sessionless edition is Completed.
            'past classroom without sessions reads completed' => [OfferingStatus::Open, $past, $past, true, 0, OfferingStatus::Completed],
            // Boundary pin: end_date === today is NOT past (strict <, not <=) —
            // the stored status stands on the last course day.
            'edition ending today is not yet completed' => [OfferingStatus::Open, date('Y-m-d'), $past, true, 2, OfferingStatus::Open],

            // Classroom with zero published sessions → Announcement (effective ≠ stored).
            'open classroom without sessions reads announcement' => [OfferingStatus::Open, $future, $future, true, 0, OfferingStatus::Announcement],
            'in_progress classroom without sessions reads announcement' => [OfferingStatus::InProgress, $future, $future, true, 0, OfferingStatus::Announcement],

            // No override applies → stored intent.
            'open classroom with sessions stays open' => [OfferingStatus::Open, $future, $future, true, 2, OfferingStatus::Open],
            'open online without sessions stays open' => [OfferingStatus::Open, $future, $future, false, 0, OfferingStatus::Open],
            'open without any dates stays open' => [OfferingStatus::Open, null, null, false, 1, OfferingStatus::Open],
            'announcement stays announcement' => [OfferingStatus::Announcement, $future, $future, true, 1, OfferingStatus::Announcement],
        ];
    }

    /**
     * @test
     * @dataProvider statusMatrix
     */
    public function decisionEngineResolvesTheMatrix(
        OfferingStatus $stored,
        ?string $endDate,
        ?string $startDate,
        bool $isClassroom,
        int $publishedSessionCount,
        OfferingStatus $expected,
    ): void {
        self::assertSame(
            $expected,
            $this->service->getEffectiveStatusFromPrefetched(
                $stored,
                $endDate,
                $startDate,
                $isClassroom,
                $publishedSessionCount,
            ),
        );
    }

    /** @test */
    public function batchVariantReturnsEmptyMapForEmptyInput(): void
    {
        self::assertSame([], $this->service->getEffectiveStatuses([]));
    }
}
