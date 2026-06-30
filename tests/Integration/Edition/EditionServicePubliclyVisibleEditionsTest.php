<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Pins EditionService::getPubliclyVisibleEditions(int $courseId, ?int $userId) — the
 * public-visibility status POLICY + the upcoming/past PARTITION extracted out of the
 * editions-list template (templates/course/editions-list.php:75-113, Cluster 3 / Task
 * 3.6 / B6). The template becomes a pure renderer over the returned struct.
 *
 * The returned struct:
 *   array{
 *     upcoming: list<array{id,start_date,end_date,venue,status,is_enrolled,permalink,session_count,price_cents}>,
 *     past:     list<...same row shape...>,
 *   }
 *
 * THE POLICY (the denial guard this test exists to pin):
 *   - Active (Announcement/Open/Full/InProgress) → upcoming block.
 *   - Completed → past block.
 *   - Anything else (Draft/Postponed/Cancelled/Archived) → SUPPRESSED from the public
 *     set UNLESS the given user is enrolled in that edition (they must still see their
 *     own registration even after a cancellation).
 *   - A guest (userId null) gets ZERO enrolled-exception — only the public set.
 *
 * INV-7: visibility + partition are driven by EFFECTIVE status (getEffectiveStatuses),
 * not the raw stored status — an edition whose stored start is future but whose
 * effective status is Completed is treated as PAST.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EditionServicePubliclyVisibleEditionsTest
 */
final class EditionServicePubliclyVisibleEditionsTest extends IntegrationTestCase
{
    /** @var list<int> */
    private array $registrationIds = [];

    protected function tearDown(): void
    {
        foreach ($this->registrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->registrationIds = [];

        parent::tearDown();
    }

    private function service(): EditionService
    {
        return ntdst_get(EditionService::class);
    }

    /**
     * Create a published edition tied to $courseId with the given stored status.
     */
    private function editionFor(int $courseId, string $status, string $start, string $end = ''): int
    {
        return $this->createTestEdition([
            'meta' => [
                '_ntdst_status'     => $status,
                '_ntdst_course_id'  => $courseId,
                '_ntdst_start_date' => $start,
                '_ntdst_end_date'   => $end ?: $start,
            ],
        ]);
    }

    private function enroll(int $userId, int $editionId): void
    {
        $repo   = ntdst_get(RegistrationRepository::class);
        $result = $repo->create([
            'user_id'         => $userId,
            'edition_id'      => $editionId,
            'status'          => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        if (is_wp_error($result)) {
            $this->fail('Failed to create registration: ' . $result->get_error_message());
        }
        $this->registrationIds[] = (int) $result;
    }

    /** @param list<array<string,mixed>> $rows */
    private function ids(array $rows): array
    {
        return array_map(static fn(array $r): int => (int) $r['id'], $rows);
    }

    // =========================================================================
    // VISIBILITY DENIAL — the core contract
    // =========================================================================

    public function test_draft_cancelled_postponed_editions_excluded_when_user_not_enrolled(): void
    {
        $course   = $this->createTestCourse();
        $future   = date('Y-m-d', strtotime('+10 days'));
        $open     = $this->editionFor($course, 'open', $future);
        $draft    = $this->editionFor($course, 'draft', $future);
        $cancelled = $this->editionFor($course, 'cancelled', $future);
        $postponed = $this->editionFor($course, 'postponed', $future);

        // No user → public set only.
        $result = $this->service()->getPubliclyVisibleEditions($course, null);
        $visibleIds = array_merge($this->ids($result['upcoming']), $this->ids($result['past']));

        $this->assertContains($open, $visibleIds, 'an Open edition is publicly visible');
        $this->assertNotContains($draft, $visibleIds, 'a Draft edition is suppressed from the public set');
        $this->assertNotContains($cancelled, $visibleIds, 'a Cancelled edition is suppressed from the public set');
        $this->assertNotContains($postponed, $visibleIds, 'a Postponed edition is suppressed from the public set');
    }

    public function test_same_cancelled_edition_included_when_user_is_enrolled(): void
    {
        $course    = $this->createTestCourse();
        $future    = date('Y-m-d', strtotime('+10 days'));
        $cancelled = $this->editionFor($course, 'cancelled', $future);

        $userId = self::$testUserId;
        $this->enroll($userId, $cancelled);

        $result = $this->service()->getPubliclyVisibleEditions($course, $userId);
        $visibleIds = array_merge($this->ids($result['upcoming']), $this->ids($result['past']));

        $this->assertContains(
            $cancelled,
            $visibleIds,
            'an enrolled user still sees their own cancelled edition (the enrolled-exception)',
        );

        // and the row is flagged enrolled
        $row = null;
        foreach (array_merge($result['upcoming'], $result['past']) as $r) {
            if ((int) $r['id'] === $cancelled) {
                $row = $r;
            }
        }
        $this->assertNotNull($row, 'the cancelled edition row is present for the enrolled user');
        $this->assertTrue($row['is_enrolled'], 'the row is flagged is_enrolled=true');
    }

    public function test_guest_does_not_leak_a_non_public_edition_even_when_some_user_is_enrolled(): void
    {
        $course    = $this->createTestCourse();
        $future    = date('Y-m-d', strtotime('+10 days'));
        $cancelled = $this->editionFor($course, 'cancelled', $future);

        // Some OTHER user is enrolled in the cancelled edition — must not leak to a guest.
        $this->enroll(self::$testUserId, $cancelled);

        // Guest (userId null): the enrolled-exception must NOT fire.
        $result = $this->service()->getPubliclyVisibleEditions($course, null);
        $visibleIds = array_merge($this->ids($result['upcoming']), $this->ids($result['past']));

        $this->assertNotContains(
            $cancelled,
            $visibleIds,
            'a guest sees only the public set — no enrolled-exception leak for a cancelled edition',
        );
    }

    // =========================================================================
    // PARTITION — upcoming vs past, effective-status driven
    // =========================================================================

    public function test_upcoming_active_edition_lands_in_upcoming(): void
    {
        $course = $this->createTestCourse();
        $open   = $this->editionFor($course, 'open', date('Y-m-d', strtotime('+10 days')));

        $result = $this->service()->getPubliclyVisibleEditions($course, null);

        $this->assertContains($open, $this->ids($result['upcoming']), 'a future Open edition is upcoming');
        $this->assertNotContains($open, $this->ids($result['past']), 'a future Open edition is not in past');
    }

    public function test_past_dated_active_edition_lands_in_past(): void
    {
        $course = $this->createTestCourse();
        // An Open edition whose start_date is in the past partitions by date into past.
        $pastStart = date('Y-m-d', strtotime('-10 days'));
        $edition   = $this->editionFor($course, 'open', $pastStart);

        $result = $this->service()->getPubliclyVisibleEditions($course, null);
        $allIds = array_merge($this->ids($result['upcoming']), $this->ids($result['past']));

        // It must be publicly visible (Open is active) and in the past block (start < today).
        $this->assertContains($edition, $allIds, 'the edition is publicly visible');
        $this->assertContains($edition, $this->ids($result['past']), 'a past-dated edition partitions to past');
    }

    public function test_partition_uses_effective_status_completed_treated_as_past(): void
    {
        $course = $this->createTestCourse();

        // Stored start is FUTURE, but stored status is Completed → effective status Completed
        // → must land in PAST despite the future start date (the INV-7 contract).
        $future  = date('Y-m-d', strtotime('+20 days'));
        $edition = $this->editionFor($course, 'completed', $future);

        // Sanity: the effective status is indeed Completed (drives the assertion).
        $effective = $this->service()->getEffectiveStatuses([$edition]);
        $this->assertSame(
            OfferingStatus::Completed,
            $effective[$edition],
            'precondition: the edition resolves to effective status Completed',
        );

        $result = $this->service()->getPubliclyVisibleEditions($course, null);

        $this->assertContains(
            $edition,
            $this->ids($result['past']),
            'a Completed edition lands in past even with a future stored start (effective-status partition)',
        );
        $this->assertNotContains(
            $edition,
            $this->ids($result['upcoming']),
            'a Completed edition is never in upcoming',
        );
    }
}
