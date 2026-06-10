<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Task G1 (audit 2.2) — INV-7 equivalence + batch-read parity against the
 * real database.
 *
 * The batch catalog pre-pass MUST NOT fork the status decision:
 * `getEffectiveStatuses()` (batch) and `getEffectiveStatus()` (single) both
 * delegate to `getEffectiveStatusFromPrefetched()`. The denial path here is
 * divergence — any difference between the two paths for the same edition
 * fails the test.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CatalogBatchHydration"
 */
final class CatalogBatchHydrationTest extends IntegrationTestCase
{
    private EditionService $editions;
    private SessionRepository $sessions;
    private RegistrationRepository $registrations;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->editions = ntdst_get(EditionService::class);
        $this->sessions = ntdst_get(SessionRepository::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
        $this->registrations->clearCache();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];
        $this->registrations->clearCache();

        parent::tearDown();
    }

    /**
     * Create a classroom-format course (stride_format: klassikaal).
     */
    private function createClassroomCourse(): int
    {
        $courseId = $this->createTestCourse();
        $term = term_exists('klassikaal', 'stride_format') ?: wp_insert_term('klassikaal', 'stride_format');
        $termId = is_array($term) ? (int) $term['term_id'] : (int) $term;
        wp_set_object_terms($courseId, [$termId], 'stride_format');

        return $courseId;
    }

    /**
     * Create a published session row linked to an edition.
     */
    private function createSession(int $editionId): int
    {
        $sessionId = wp_insert_post([
            'post_title' => 'Test Session ' . wp_generate_password(4, false),
            'post_type' => 'vad_session',
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $sessionId;
        update_post_meta($sessionId, '_ntdst_edition_id', $editionId);
        update_post_meta($sessionId, '_ntdst_date', date('Y-m-d', strtotime('+10 days')));

        return $sessionId;
    }

    /**
     * @test
     *
     * The INV-7 contract: across the full state matrix (terminal status,
     * past end-date, classroom-no-sessions, open-with-sessions, online,
     * dateless), the batch path returns EXACTLY what the single path
     * returns. Divergence = a forked decision point = failure.
     */
    public function batchEffectiveStatusesMatchSinglePathAcrossStateMatrix(): void
    {
        $future = date('Y-m-d', strtotime('+30 days'));
        $past = date('Y-m-d', strtotime('-30 days'));

        $classroomCourse = $this->createClassroomCourse();
        $onlineCourse = $this->createTestCourse(); // no format terms → not classroom

        $matrix = [
            'terminal cancelled' => $this->createTestEdition(['meta' => [
                '_ntdst_status' => 'cancelled',
                '_ntdst_course_id' => $classroomCourse,
                '_ntdst_start_date' => $past,
                '_ntdst_end_date' => $past,
            ]]),
            'open past end-date' => $this->createTestEdition(['meta' => [
                '_ntdst_status' => 'open',
                '_ntdst_course_id' => $classroomCourse,
                '_ntdst_start_date' => $past,
                '_ntdst_end_date' => $past,
            ]]),
            'open classroom no sessions' => $this->createTestEdition(['meta' => [
                '_ntdst_status' => 'open',
                '_ntdst_course_id' => $classroomCourse,
                '_ntdst_start_date' => $future,
                '_ntdst_end_date' => $future,
            ]]),
            'open classroom with session' => $this->createTestEdition(['meta' => [
                '_ntdst_status' => 'open',
                '_ntdst_course_id' => $classroomCourse,
                '_ntdst_start_date' => $future,
                '_ntdst_end_date' => $future,
            ]]),
            'open online no sessions' => $this->createTestEdition(['meta' => [
                '_ntdst_status' => 'open',
                '_ntdst_course_id' => $onlineCourse,
                '_ntdst_start_date' => $future,
                '_ntdst_end_date' => $future,
            ]]),
            'open dateless no course' => $this->createTestEdition(['meta' => [
                '_ntdst_status' => 'open',
            ]]),
        ];

        $this->createSession($matrix['open classroom with session']);

        $batch = $this->editions->getEffectiveStatuses(array_values($matrix));

        foreach ($matrix as $label => $editionId) {
            $single = $this->editions->getEffectiveStatus($editionId);
            $this->assertSame(
                $single,
                $batch[$editionId] ?? null,
                "INV-7 divergence for '{$label}' (#{$editionId}): single path says {$single->value}",
            );
        }

        // The matrix must actually exercise the overrides (effective ≠ stored),
        // otherwise a naive stored-status read would pass the equivalence.
        $this->assertSame(OfferingStatus::Cancelled, $batch[$matrix['terminal cancelled']]);
        $this->assertSame(OfferingStatus::Completed, $batch[$matrix['open past end-date']]);
        $this->assertSame(OfferingStatus::Announcement, $batch[$matrix['open classroom no sessions']]);
        $this->assertSame(OfferingStatus::Open, $batch[$matrix['open classroom with session']]);
        $this->assertSame(OfferingStatus::Open, $batch[$matrix['open online no sessions']]);
    }

    /** @test */
    public function sessionCountBatchMatchesPerEditionCounts(): void
    {
        $withTwo = $this->createTestEdition();
        $withOne = $this->createTestEdition();
        $withNone = $this->createTestEdition();

        $this->createSession($withTwo);
        $this->createSession($withTwo);
        $this->createSession($withOne);

        // A draft session must not count as published.
        $draft = $this->createSession($withNone);
        wp_update_post(['ID' => $draft, 'post_status' => 'draft']);

        $batch = $this->sessions->countByEditions([$withTwo, $withOne, $withNone]);

        foreach ([$withTwo, $withOne, $withNone] as $editionId) {
            $this->assertSame(
                $this->sessions->countByEdition($editionId),
                $batch[$editionId],
                "countByEditions diverges from countByEdition for #{$editionId}",
            );
        }
        $this->assertSame(2, $batch[$withTwo]);
        $this->assertSame(1, $batch[$withOne]);
        $this->assertSame(0, $batch[$withNone]);
    }

    /**
     * @test
     *
     * Enrolled-state parity for a logged-in fixture: the batch set must
     * agree with isEnrolled() per edition — including the negative case
     * (a pending registration is NOT enrolled).
     */
    public function enrolledEditionIdsMatchIsEnrolledForLoggedInFixture(): void
    {
        $enrollment = ntdst_get(EnrollmentService::class);

        $confirmedEdition = $this->createTestEdition();
        $pendingEdition = $this->createTestEdition();
        $untouchedEdition = $this->createTestEdition();

        $regA = $this->registrations->create([
            'user_id' => self::$testUserId,
            'edition_id' => $confirmedEdition,
            'status' => 'confirmed',
        ]);
        $regB = $this->registrations->create([
            'user_id' => self::$testUserId,
            'edition_id' => $pendingEdition,
            'status' => 'pending',
        ]);
        $this->createdRegistrationIds = array_merge($this->createdRegistrationIds, [$regA, $regB]);
        $this->registrations->clearCache();

        $enrolledIds = $enrollment->getEnrolledEditionIds(self::$testUserId);

        foreach ([$confirmedEdition, $pendingEdition, $untouchedEdition] as $editionId) {
            $this->assertSame(
                $enrollment->isEnrolled(self::$testUserId, $editionId),
                in_array($editionId, $enrolledIds, true),
                "batch enrolled set diverges from isEnrolled() for #{$editionId}",
            );
        }
        $this->assertContains($confirmedEdition, $enrolledIds);
        $this->assertNotContains($pendingEdition, $enrolledIds);
        $this->assertSame([], $enrollment->getEnrolledEditionIds(0), 'anonymous visitor has no enrolled set');
    }
}
