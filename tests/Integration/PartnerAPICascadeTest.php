<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\OfferingStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;
use Stride\Modules\Trajectory\TrajectoryCPT;
use Stride\Modules\Trajectory\TrajectorySelection;

/**
 * Integration tests for Stap 13: Partner API behaviour after cascade ships.
 *
 *  - Listing endpoint hides cascade children from the flat list (only the
 *    parent trajectory row + a nested `child_registrations` array).
 *  - Detail endpoint includes `child_registrations` for trajectory parents.
 *  - `include_children=true` repo filter lets admin tools opt back in.
 *  - `findByCompany` default excludes children entirely (paginate math stays right).
 *
 * Capacity 409 handling on POST /enrollments is already covered by the
 * existing partner integration suite for direct edition enrollment, and
 * trajectory enrollment via `TrajectorySelection::enroll` doesn't capacity-
 * check at the trajectory level (mandatory editions are pre-assigned).
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter PartnerAPICascade
 */
final class PartnerAPICascadeTest extends IntegrationTestCase
{
    private static ?int $partnerUserId = null;
    private static ?int $companyUserId = null;
    private static int $companyId = 7777;

    private RegistrationRepository $repo;
    private TrajectorySelection $selection;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $partnerUsername = 'partner_cascade_' . time() . '_' . wp_generate_password(4, false);
        self::$partnerUserId = wp_create_user($partnerUsername, 'testpass123', $partnerUsername . '@partner.test');
        if (is_wp_error(self::$partnerUserId)) {
            throw new \RuntimeException('Failed to create partner user: ' . self::$partnerUserId->get_error_message());
        }
        get_user_by('ID', self::$partnerUserId)->add_role('partner');
        update_user_meta(self::$partnerUserId, '_stride_company_id', self::$companyId);

        $memberUsername = 'member_cascade_' . time() . '_' . wp_generate_password(4, false);
        self::$companyUserId = wp_create_user($memberUsername, 'testpass123', $memberUsername . '@company.test');
        if (is_wp_error(self::$companyUserId)) {
            throw new \RuntimeException('Failed to create company user: ' . self::$companyUserId->get_error_message());
        }
        update_user_meta(self::$companyUserId, '_stride_company_id', self::$companyId);
    }

    public static function tearDownAfterClass(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        if (self::$partnerUserId) {
            wp_delete_user(self::$partnerUserId);
        }
        if (self::$companyUserId) {
            wp_delete_user(self::$companyUserId);
        }
        self::$partnerUserId = null;
        self::$companyUserId = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->selection = ntdst_get(TrajectorySelection::class);
        $this->actingAs(self::$partnerUserId);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        delete_user_meta(self::$companyUserId, TrajectoryCascadeService::TRAJECTORY_COURSES_META_KEY);

        parent::tearDown();
    }

    /** @test */
    public function findByCompanyExcludesCascadeChildrenByDefault(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$companyUserId, $trajectoryId, ['company_id' => self::$companyId]);
        $this->assertIsInt($parentId);
        $this->createdRegistrationIds[] = $parentId;

        // Verify a child was created by the cascade.
        $this->assertCount(1, $this->repo->findByParent($parentId));

        // findByCompany (default) returns only the parent, not the child.
        $result = $this->repo->findByCompany(self::$companyId);
        $rowIds = array_map(fn($r) => (int) $r->id, $result['data']);
        $this->assertContains($parentId, $rowIds);

        $childIds = array_map(fn($r) => (int) $r->id, $this->repo->findByParent($parentId));
        foreach ($childIds as $childId) {
            $this->assertNotContains(
                $childId,
                $rowIds,
                'cascade children must be excluded from findByCompany'
            );
        }
    }

    /** @test */
    public function findByCompanyIncludesChildrenWhenAskedExplicitly(): void
    {
        $course = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $course]]);
        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'edition', 'course_id' => $course, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);

        $parentId = $this->selection->enroll(self::$companyUserId, $trajectoryId, ['company_id' => self::$companyId]);
        $this->createdRegistrationIds[] = $parentId;

        // For children to be findable by company_id we propagate the parent's
        // company_id into the child row — confirm cascade did that.
        $children = $this->repo->findByParent($parentId);
        $this->assertCount(1, $children);
        $this->assertSame(self::$companyId, (int) $children[0]->company_id);

        $withChildren = $this->repo->findByCompany(self::$companyId, ['include_children' => true]);
        $rowIds = array_map(fn($r) => (int) $r->id, $withChildren['data']);
        $this->assertContains((int) $children[0]->id, $rowIds, 'include_children=true must surface the cascade child');
    }

    /** @test */
    public function getEnrollmentsListsTrajectoryParentWithNestedChildren(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->selection->enroll(self::$companyUserId, $trajectoryId, ['company_id' => self::$companyId]);
        $this->createdRegistrationIds[] = $parentId;

        $request = new \WP_REST_Request('GET', '/stride/v1/partner/enrollments');
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $body = $response->get_data();
        $parentRow = $this->findRowById($body['data'], $parentId);
        $this->assertNotNull($parentRow, 'trajectory parent appears in flat data list');
        $this->assertArrayHasKey('child_registrations', $parentRow);
        $this->assertCount(1, $parentRow['child_registrations']);
        $this->assertSame($editionA, (int) $parentRow['child_registrations'][0]['edition_id']);
        $this->assertSame($parentId, (int) $parentRow['child_registrations'][0]['parent_registration_id']);

        // The child itself must NOT also appear as a top-level row.
        $childId = (int) $parentRow['child_registrations'][0]['id'];
        $this->assertNull($this->findRowById($body['data'], $childId), 'child must not appear as a standalone enrollment');
    }

    /** @test */
    public function getEnrollmentDetailIncludesNestedChildrenForTrajectoryParent(): void
    {
        $courseA = $this->createTestCourse();
        $editionA = $this->createTestEdition(['meta' => ['_ntdst_course_id' => $courseA]]);
        $trajectoryId = $this->createOpenTrajectory([
            ['type' => 'edition', 'course_id' => $courseA, 'edition_id' => $editionA, 'required' => true, 'order' => 1],
        ]);
        $parentId = $this->selection->enroll(self::$companyUserId, $trajectoryId, ['company_id' => self::$companyId]);
        $this->createdRegistrationIds[] = $parentId;

        $request = new \WP_REST_Request('GET', '/stride/v1/partner/enrollments/' . $parentId);
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $body = $response->get_data();
        $this->assertArrayHasKey('child_registrations', $body);
        $this->assertCount(1, $body['child_registrations']);

        $child = $body['child_registrations'][0];
        $this->assertSame($editionA, (int) $child['edition_id']);
        $this->assertSame($parentId, (int) $child['parent_registration_id']);
        $this->assertSame(self::$companyUserId, (int) $child['user_id']);
        $this->assertNotEmpty($child['edition_title']);
    }

    /** @test */
    public function getEnrollmentDetailOmitsChildrenForDirectEditionEnrollment(): void
    {
        $edition = $this->createTestEdition();
        $regId = $this->repo->create([
            'user_id' => self::$companyUserId,
            'edition_id' => $edition,
            'company_id' => self::$companyId,
        ]);
        $this->assertIsInt($regId);
        $this->createdRegistrationIds[] = $regId;

        $request = new \WP_REST_Request('GET', '/stride/v1/partner/enrollments/' . $regId);
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status());

        $body = $response->get_data();
        $this->assertArrayNotHasKey('child_registrations', $body, 'direct enrollment does not surface child_registrations');
    }

    // === Helpers ===

    /**
     * @param array<array<string, mixed>> $courses
     */
    private function createOpenTrajectory(array $courses): int
    {
        $trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_title' => 'Partner cascade trajectory ' . wp_generate_password(6, false),
            'post_status' => 'publish',
        ]);
        if (is_wp_error($trajectoryId)) {
            $this->fail('createOpenTrajectory failed: ' . $trajectoryId->get_error_message());
        }
        self::$testPosts[] = $trajectoryId;

        $model = ntdst_data()->get(TrajectoryCPT::POST_TYPE);
        $model->update($trajectoryId, [
            'mode' => TrajectoryMode::Cohort->value,
            'status' => OfferingStatus::Open->value,
            'capacity' => 0,
            'courses' => $courses,
        ]);

        return $trajectoryId;
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    private function findRowById(array $rows, int $id): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }
}
