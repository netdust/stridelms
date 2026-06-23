<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Tier-A backend contract for AdminTrajectoryService::getUserTrajectories() —
 * the spec's one true backend gap (Phase 1E / Task 3.5 / §11.4).
 *
 * The endpoint wires existing read compute (TrajectoryDashboardService::
 * getProgressData + the TrajectorySelection read methods) into the per-trajectory
 * progress shape the Dossier section binds. Load-bearing properties:
 *
 *  1. Enrolled user → per-trajectory completed_count / in_progress_count /
 *     total_required, required_courses with per-course state, and elective_groups
 *     ENRICHED with chosen-vs-required (countChosen / isChosen / chosen[]) —
 *     computed via getSelectedCourseIds / countChosenInGroup / isGroupChosen
 *     (INV-6b: TrajectorySelection read methods, NEVER validateSelections).
 *  2. The per-trajectory header carries {id, title, status, mode} (DRIFT #5 —
 *     getProgressData returns mode but NOT title/status).
 *  3. Anonymous request → denied (401/403) — M1, the permission_callback on the
 *     real route (this assertion crosses the un-mocked route→permission→callback
 *     seam via rest_do_request).
 *  4. A user in NO trajectory → empty `trajectories` list (not an error, not 404).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminUserTrajectories
 */
final class AdminUserTrajectoriesEndpointTest extends IntegrationTestCase
{
    private static ?int $coordinatorUserId = null;

    // The enrolled user + fixture entities.
    private static ?int $enrolledUserId = null;
    private static ?int $nonEnrolledUserId = null;

    private static ?int $trajectoryId = null;
    private static ?int $requiredCourse = null;
    private static ?int $requiredEdition = null;
    private static ?int $electiveCourseA = null;
    private static ?int $electiveEditionA = null;
    private static ?int $electiveCourseB = null;
    private static ?int $electiveEditionB = null;

    private static ?int $parentRegId = null;

    /** @var array<int> */
    private static array $regIds = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        // --- Coordinator (stride_view) for the authorised REST dispatch ---
        $coordName = 'aut_coord_' . uniqid();
        self::$coordinatorUserId = (int) wp_create_user($coordName, 'pass123', $coordName . '@test.local');
        get_user_by('ID', self::$coordinatorUserId)->set_role('stride_coordinator');

        self::$enrolledUserId = (int) wp_create_user('aut_enrolled_' . uniqid(), 'pass123', 'aut_enrolled_' . uniqid() . '@test.local');
        self::$nonEnrolledUserId = (int) wp_create_user('aut_none_' . uniqid(), 'pass123', 'aut_none_' . uniqid() . '@test.local');

        // --- Courses + editions ---
        self::$requiredCourse = self::makeCourse('Required Course');
        self::$requiredEdition = self::makeEdition(self::$requiredCourse);
        self::$electiveCourseA = self::makeCourse('Elective A');
        self::$electiveEditionA = self::makeEdition(self::$electiveCourseA);
        self::$electiveCourseB = self::makeCourse('Elective B');
        self::$electiveEditionB = self::makeEdition(self::$electiveCourseB);

        // --- Trajectory: 1 required course + 1 elective group (kies 1 uit 2) ---
        self::$trajectoryId = wp_insert_post([
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Case-view Trajectory ' . uniqid(),
        ]);
        self::$testPosts[] = self::$trajectoryId;
        update_post_meta(self::$trajectoryId, '_ntdst_mode', 'cohort');
        update_post_meta(self::$trajectoryId, '_ntdst_status', 'open');
        update_post_meta(self::$trajectoryId, '_ntdst_courses', wp_json_encode([
            ['course_id' => self::$requiredCourse, 'required' => true, 'type' => 'edition', 'edition_id' => self::$requiredEdition, 'order' => 1],
            ['course_id' => self::$electiveCourseA, 'required' => false, 'type' => 'edition', 'edition_id' => self::$electiveEditionA, 'group' => 'Keuze', 'min_choices' => 1],
            ['course_id' => self::$electiveCourseB, 'required' => false, 'type' => 'edition', 'edition_id' => self::$electiveEditionB, 'group' => 'Keuze', 'min_choices' => 1],
        ]));

        $repo = ntdst_get(RegistrationRepository::class);

        // Parent trajectory enrolment.
        self::$parentRegId = $repo->create([
            'user_id' => self::$enrolledUserId,
            'trajectory_id' => self::$trajectoryId,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
        ]);
        self::$regIds[] = self::$parentRegId;

        // Mandatory child on the required edition → required course is "bezig".
        self::$regIds[] = $repo->create([
            'user_id' => self::$enrolledUserId,
            'edition_id' => self::$requiredEdition,
            'parent_registration_id' => self::$parentRegId,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
        ]);

        // Elective child on edition A → the user CHOSE elective A (countChosen=1,
        // isChosen=true for the kies-1-uit-2 group).
        self::$regIds[] = $repo->create([
            'user_id' => self::$enrolledUserId,
            'edition_id' => self::$electiveEditionA,
            'parent_registration_id' => self::$parentRegId,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
        ]);

        // Record the selection on the parent (the selections column stores
        // EDITION ids; getSelectedCourseIds maps them back to course ids).
        $repo->setSelections(self::$parentRegId, [self::$electiveEditionA]);
        $repo->clearCache();
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([self::$enrolledUserId, self::$nonEnrolledUserId] as $uid) {
            if ($uid) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}vad_registrations WHERE user_id = %d", $uid));
                wp_delete_user($uid);
            }
        }
        if (self::$coordinatorUserId) {
            wp_delete_user(self::$coordinatorUserId);
        }
        parent::tearDownAfterClass();
    }

    private static function makeCourse(string $title): int
    {
        $id = (int) wp_insert_post([
            'post_type' => 'sfwd-courses', 'post_status' => 'publish',
            'post_title' => $title . ' ' . uniqid(),
        ]);
        self::$testPosts[] = $id;
        return $id;
    }

    private static function makeEdition(int $courseId): int
    {
        $id = (int) wp_insert_post([
            'post_type' => 'vad_edition', 'post_status' => 'publish',
            'post_title' => 'Edition for ' . $courseId . ' ' . uniqid(),
        ]);
        self::$testPosts[] = $id;
        update_post_meta($id, '_ntdst_course_id', (string) $courseId);
        update_post_meta($id, '_ntdst_status', 'open');
        update_post_meta($id, '_ntdst_capacity', '20');
        return $id;
    }

    private function dispatch(int $userId): \WP_REST_Response|\WP_Error
    {
        return rest_do_request(new \WP_REST_Request('GET', '/stride/v1/admin/users/' . $userId . '/trajectories'));
    }

    // =========================================================================
    // Assertion 1 + 2: enrolled user → progress + enriched electives + header
    // =========================================================================

    /** @test */
    public function enrolledUserReturnsProgressWithChosenVsRequiredElectives(): void
    {
        $this->actingAs(self::$coordinatorUserId);

        $response = $this->dispatch(self::$enrolledUserId);
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('trajectories', $data);
        $this->assertCount(1, $data['trajectories'], 'user enrolled in exactly one trajectory');

        $t = $data['trajectories'][0];

        // Header (DRIFT #5): {id, title, status, mode}.
        $this->assertArrayHasKey('trajectory', $t);
        $this->assertSame((int) self::$trajectoryId, (int) $t['trajectory']['id']);
        $this->assertNotEmpty($t['trajectory']['title']);
        $this->assertSame('open', $t['trajectory']['status']);
        $this->assertSame('cohort', $t['trajectory']['mode']);

        // Progress scalars. 1 required course + 1 group requiring 1 = total_required 2.
        $this->assertSame(2, (int) $t['total_required']);
        $this->assertSame(0, (int) $t['completed_count'], 'no LD completion → nothing afgerond');
        // Required course + chosen elective both enrolled (not LD-complete) → bezig.
        $this->assertSame(2, (int) $t['in_progress_count']);

        // Required courses carry per-course state.
        $this->assertArrayHasKey('required_courses', $t);
        $this->assertCount(1, $t['required_courses']);
        $this->assertSame('bezig', $t['required_courses'][0]['state']);
        $this->assertNotEmpty($t['required_courses'][0]['title']);

        // Elective group enriched with chosen-vs-required (INV-6b).
        $this->assertArrayHasKey('elective_groups', $t);
        $this->assertCount(1, $t['elective_groups']);
        $group = $t['elective_groups'][0];
        $this->assertSame('Keuze', $group['name']);
        $this->assertSame(1, (int) $group['required']);
        $this->assertSame(2, (int) $group['total']);
        $this->assertSame(1, (int) $group['countChosen'], 'user picked elective A');
        $this->assertTrue($group['isChosen'], 'kies 1 uit 2 satisfied');
        $this->assertCount(1, $group['chosen']);
        $this->assertNotEmpty($group['chosen'][0]['title']);
    }

    // =========================================================================
    // Assertion 3: anonymous request is denied (M1 — un-mocked route seam)
    // =========================================================================

    /** @test */
    public function anonymousRequestIsDenied(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatch(self::$enrolledUserId);
        // The real permission_callback (canViewAdmin) refuses an anon actor.
        $status = $response instanceof \WP_Error
            ? ($response->get_error_data()['status'] ?? 0)
            : $response->get_status();
        $this->assertContains((int) $status, [401, 403], 'anon must be denied by the route permission_callback');
    }

    // =========================================================================
    // Assertion 4: a user in NO trajectory → empty list (not error, not 404)
    // =========================================================================

    /** @test */
    public function nonEnrolledUserReturnsEmptyTrajectoryList(): void
    {
        $this->actingAs(self::$coordinatorUserId);

        $response = $this->dispatch(self::$nonEnrolledUserId);
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('trajectories', $data);
        $this->assertSame([], $data['trajectories'], 'non-enrolled user → empty list, not an error');
    }
}
