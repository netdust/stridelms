<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Integration tests for cascadeOnSelection() — elective add/remove with
 * capacity + child-quote handling.
 *
 * Covers Stap 6 of plans/2026-05-20-trajectory-cascade-enrollment.md:
 *  - Selected electives produce child rows (with LD access for confirmed).
 *  - Re-selecting (same edition kept) is a no-op.
 *  - Removed elective → child cancelled (LD revoked, quote cancelled).
 *  - Edition full → WP_Error('edition_full'), other electives still created.
 *  - Free trajectory + paid edition → child quote auto-generated.
 *  - Paid trajectory + paid edition → no child quote (parent quote covers it).
 *  - Free trajectory + free edition → no quote.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter TrajectoryCascadeSelection
 */
final class TrajectoryCascadeSelectionTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;
    private TrajectoryCascadeService $cascade;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    /** @var array<int> */
    private array $createdQuoteIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->cascade = ntdst_get(TrajectoryCascadeService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        global $wpdb;
        foreach ($this->createdQuoteIds as $quoteId) {
            wp_delete_post($quoteId, true);
        }
        $this->createdQuoteIds = [];

        parent::tearDown();
    }

    /** @test */
    public function selectedElectivesProduceChildRegistrations(): void
    {
        $editionA = $this->createTestEdition();
        $editionB = $this->createTestEdition();
        $trajectoryId = $this->createBareTrajectory();
        $parentId = $this->createParentRegistration($trajectoryId);

        $result = $this->cascade->cascadeOnSelection($parentId, [$editionA, $editionB]);

        $this->assertTrue($result);
        $children = $this->repo->findByParent($parentId);
        $this->assertCount(2, $children);
        $editionIds = array_map(fn($c) => (int) $c->edition_id, $children);
        sort($editionIds);
        $expected = [$editionA, $editionB];
        sort($expected);
        $this->assertSame($expected, $editionIds);

        foreach ($children as $child) {
            $this->assertSame(RegistrationStatus::Confirmed->value, $child->status);
            $this->assertSame($parentId, (int) $child->parent_registration_id);
            $this->assertNull($child->trajectory_id);
            $this->assertSame(RegistrationRepository::PATH_TRAJECTORY, $child->enrollment_path);
        }
    }

    /** @test */
    public function reSelectingKeepsExistingChildrenWithoutDuplicates(): void
    {
        $editionA = $this->createTestEdition();
        $editionB = $this->createTestEdition();
        $trajectoryId = $this->createBareTrajectory();
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);
        $firstPass = $this->repo->findByParent($parentId);
        $this->assertCount(1, $firstPass);
        $firstChildId = (int) $firstPass[0]->id;

        $this->cascade->cascadeOnSelection($parentId, [$editionA, $editionB]);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(2, $children, 'kept edition + new edition = 2 active children');

        // Original child row is the same instance — not recreated.
        $ids = array_map(fn($c) => (int) $c->id, $children);
        $this->assertContains($firstChildId, $ids);
    }

    /** @test */
    public function removedElectiveCancelsChildAndRevokesLdAccess(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEditionForCourse($courseA);
        $editionB = $this->createTestEdition();
        $trajectoryId = $this->createBareTrajectory();
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$editionA, $editionB]);
        $this->assertTrue($this->userHasLdAccess(self::$testUserId, $courseA), 'LD access granted on initial selection');

        // Drop editionA from the selection.
        $this->cascade->cascadeOnSelection($parentId, [$editionB]);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(2, $children, 'cancelled rows stay around for audit');
        $cancelled = $this->childForEdition($children, $editionA);
        $kept = $this->childForEdition($children, $editionB);
        $this->assertSame(RegistrationStatus::Cancelled->value, $cancelled->status);
        $this->assertNotEmpty($cancelled->cancelled_at);
        $this->assertSame(RegistrationStatus::Confirmed->value, $kept->status);

        $this->assertFalse($this->userHasLdAccess(self::$testUserId, $courseA), 'LD access revoked when child cancelled');
    }

    /** @test */
    public function reAddingPreviouslyRemovedElectiveReactivatesTheChild(): void
    {
        $editionA = $this->createTestEdition();
        $trajectoryId = $this->createBareTrajectory();
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$editionA]);
        $firstChildId = (int) $this->repo->findByParent($parentId)[0]->id;

        $this->cascade->cascadeOnSelection($parentId, []);
        $this->cascade->cascadeOnSelection($parentId, [$editionA]);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children, 'cancelled child of same parent is reactivated, not duplicated');

        $child = $children[0];
        $this->assertSame($firstChildId, (int) $child->id);
        $this->assertSame(RegistrationStatus::Confirmed->value, $child->status);
        $this->assertNull($child->cancelled_at);
    }

    /** @test */
    public function fullEditionReturnsWpErrorButCreatesOtherElectives(): void
    {
        $fullEdition = $this->createTestEdition(['meta' => ['_ntdst_capacity' => 1]]);
        $this->seedConfirmedEnrollmentOnEdition($fullEdition);

        $openEdition = $this->createTestEdition();
        $trajectoryId = $this->createBareTrajectory();
        $parentId = $this->createParentRegistration($trajectoryId);

        $result = $this->cascade->cascadeOnSelection($parentId, [$fullEdition, $openEdition]);

        $this->assertTrue(is_wp_error($result), 'must return WP_Error for the full edition');
        $this->assertSame('edition_full', $result->get_error_code());
        $this->assertSame($fullEdition, (int) ($result->get_error_data()['edition_id'] ?? 0));

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children, 'only the non-full edition produced a child');
        $this->assertSame($openEdition, (int) $children[0]->edition_id);
    }

    /** @test */
    public function freeTrajectoryWithPaidEditionGeneratesChildQuote(): void
    {
        $edition = $this->createPaidEdition(50.00); // €50.00
        $trajectoryId = $this->createBareTrajectory(['price' => 0]);
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$edition]);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $child = $children[0];

        $this->assertNotEmpty($child->quote_id, 'free trajectory + paid edition must generate a child quote');
        $quoteId = (int) $child->quote_id;
        $this->createdQuoteIds[] = $quoteId;

        $quote = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class)->getQuote($quoteId);
        $this->assertFalse(is_wp_error($quote));
        $this->assertSame(5000, (int) $quote['subtotal'], 'subtotal in cents = €50.00');
        $this->assertGreaterThanOrEqual(5000, (int) $quote['total'], 'total includes any tax');
        $this->assertSame((int) $child->id, (int) $quote['registration_id']);
    }

    /** @test */
    public function paidTrajectoryWithPaidEditionDoesNotGenerateChildQuote(): void
    {
        $edition = $this->createPaidEdition(50.00);
        $trajectoryId = $this->createBareTrajectory(['price' => 100.00]); // €100
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$edition]);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $this->assertNull($children[0]->quote_id, 'paid trajectory means the parent quote bills the child too');
    }

    /** @test */
    public function freeTrajectoryWithFreeEditionDoesNotGenerateChildQuote(): void
    {
        $edition = $this->createPaidEdition(0.0); // explicit zero
        $trajectoryId = $this->createBareTrajectory(['price' => 0]);
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$edition]);

        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $this->assertNull($children[0]->quote_id);
    }

    /** @test */
    public function removingChildWithGeneratedQuoteCancelsTheQuote(): void
    {
        $edition = $this->createPaidEdition(50.00);
        $trajectoryId = $this->createBareTrajectory(['price' => 0]);
        $parentId = $this->createParentRegistration($trajectoryId);

        $this->cascade->cascadeOnSelection($parentId, [$edition]);
        $child = $this->repo->findByParent($parentId)[0];
        $quoteId = (int) $child->quote_id;
        $this->assertGreaterThan(0, $quoteId);
        $this->createdQuoteIds[] = $quoteId;

        $this->cascade->cascadeOnSelection($parentId, []);

        $quote = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class)->getQuote($quoteId, true);
        $this->assertFalse(is_wp_error($quote));
        $this->assertSame('cancelled', (string) $quote['status']);
    }

    // === Helpers ===

    /**
     * @param array<string, mixed> $meta Trajectory meta to set after insert.
     */
    private function createBareTrajectory(array $meta = []): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Cascade selection trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createBareTrajectory failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        if (!empty($meta)) {
            $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
            $model->update($trajectoryId, $meta);
        }

        return $trajectoryId;
    }

    private function createParentRegistration(int $trajectoryId): int
    {
        $id = $this->repo->create([
            'user_id' => self::$testUserId,
            'trajectory_id' => $trajectoryId,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ]);
        if (is_wp_error($id)) {
            $this->fail('createParentRegistration failed: ' . $id->get_error_message());
        }
        $this->createdRegistrationIds[] = $id;
        return $id;
    }

    private function createTestEditionForCourse(int $courseId): int
    {
        return $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseId]]);
    }

    /**
     * Edition priced at the given euro amount (e.g., 50.00 → €50).
     *
     * `_ntdst_price` and `_ntdst_price_non_member` are stored as euro floats —
     * EditionService::getPrice() runs them through Money::eur() which expects
     * euros, not cents. Setting both ensures membership state doesn't change
     * the test outcome.
     */
    private function createPaidEdition(float $euros): int
    {
        // $euros is expressed in EUROS for readable call sites; the stored price
        // fields are canonical CENTS (EditionService::getPrice reads them as
        // cents). Convert so a €50.00 edition stores 5000.
        $cents = (int) round($euros * 100);

        return $this->createTestEdition(['meta' => [
            '_ntdst_price' => $cents,
            '_ntdst_price_non_member' => $cents,
        ]]);
    }

    private function seedConfirmedEnrollmentOnEdition(int $editionId): void
    {
        $username = 'cap_filler_' . wp_generate_password(8, false);
        $userId = wp_create_user($username, 'testpass123', $username . '@test.local');
        if (is_wp_error($userId)) {
            $this->fail('seedConfirmedEnrollmentOnEdition: ' . $userId->get_error_message());
        }

        $regId = $this->repo->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Confirmed->value,
        ]);
        if (is_wp_error($regId)) {
            $this->fail('seedConfirmedEnrollmentOnEdition failed: ' . $regId->get_error_message());
        }
        $this->createdRegistrationIds[] = $regId;
    }

    /**
     * @param array<object> $children
     */
    private function childForEdition(array $children, int $editionId): object
    {
        foreach ($children as $child) {
            if ((int) $child->edition_id === $editionId) {
                return $child;
            }
        }
        $this->fail("No child found for edition {$editionId}");
    }

    private function userHasLdAccess(int $userId, int $courseId): bool
    {
        if (!function_exists('sfwd_lms_has_access')) {
            return false;
        }
        return (bool) sfwd_lms_has_access($courseId, $userId);
    }
}
