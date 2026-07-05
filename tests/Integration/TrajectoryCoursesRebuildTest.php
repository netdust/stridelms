<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\Admin\TrajectoryAdminController;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;

/**
 * Behavioral coverage for the COURSES-REBUILD in TrajectoryAdminController::handleSave().
 *
 * handleSave() rebuilds the trajectory's `courses` array from two posted form
 * arrays (TrajectoryAdminController::handleSave, ~1160-1199):
 *   - $fields['courses_required'][] — each encoded item value (JSON or legacy int)
 *     parsed via parseCourseItemValue(), marked required=true.
 *   - $fields['elective_groups'][]  — each group carries a name, a pick_count
 *     (absint, default 1) and courses[]; each elective entry gets required=false,
 *     group=<name>, pick_count=<pickCount>.
 * The rebuilt array is stored under $updateData['courses'] and read back through
 * the repository (getCourses / getRequiredCourses / getElectiveGroups).
 *
 * These pin the full save→store→read round-trip of that block: required/elective
 * split, per-course type/course_id/edition_id, grouping-by-name, the pick_count
 * fix (TrajectoryRepository:348), the supported legacy-integer format, and the
 * parseCourseItemValue guard against a malformed item value.
 *
 * Run (disposable-DB gate forwarded via --raw; a silent exit 255 = a swallowed
 * PHP fatal, not "no tests matched"):
 *   ddev exec --raw -- bash -c 'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist \
 *     --filter TrajectoryCoursesRebuild'
 */
final class TrajectoryCoursesRebuildTest extends IntegrationTestCase
{
    private TrajectoryRepository $repo;
    private int $trajectoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = ntdst_get(TrajectoryRepository::class);

        // handleSave()'s current_user_can('edit_post', $id) guard resolves via
        // map_meta_cap for the vad_trajectory CPT — promote the fixture user to
        // administrator so the save is allowed.
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');

        $this->trajectoryId = wp_insert_post([
            'post_type'   => 'vad_trajectory',
            'post_title'  => 'Courses Rebuild Test ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $this->trajectoryId;
    }

    private function controller(): TrajectoryAdminController
    {
        return new TrajectoryAdminController(
            ntdst_get(TrajectoryService::class),
            ntdst_get(TrajectoryRepository::class),
            ntdst_get(RegistrationRepository::class),
            ntdst_get(EditionRepository::class),
        );
    }

    /**
     * Drive the real admin save path. Creates the nonce AS the current user
     * (nonces are user-context-dependent), merges the posted fields into $_POST,
     * invokes handleSave(), then cleans up $_POST.
     *
     * @param array<string, mixed> $fields Value for $_POST['ntdst_fields']
     */
    private function save(array $fields): void
    {
        wp_set_current_user((int) self::$testUserId);

        $_POST[TrajectoryAdminController::NONCE_FIELD] =
            wp_create_nonce(TrajectoryAdminController::NONCE_SAVE);
        $_POST['ntdst_fields'] = $fields;

        $this->controller()->handleSave($this->trajectoryId, get_post($this->trajectoryId));

        unset($_POST[TrajectoryAdminController::NONCE_FIELD], $_POST['ntdst_fields']);
    }

    /** Create a published sfwd-courses post and register it for teardown. */
    private function makeCourse(string $label): int
    {
        $id = wp_insert_post([
            'post_type'   => 'sfwd-courses',
            'post_title'  => $label . ' ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $id;

        return $id;
    }

    /** Create a published vad_edition post and register it for teardown. */
    private function makeEdition(string $label): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_edition',
            'post_title'  => $label . ' ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $id;

        return $id;
    }

    /**
     * Find the read-back config array for a course_id inside a set of resolved
     * WP_Post objects. resolveCoursePosts attaches the raw config as
     * $post->trajectory_config; that is where type/edition_id/required live.
     *
     * @param array<\WP_Post> $posts
     * @return array<string, mixed>|null
     */
    private function configFor(array $posts, int $courseId): ?array
    {
        foreach ($posts as $post) {
            if ((int) $post->ID === $courseId) {
                return $post->trajectory_config ?? [];
            }
        }

        return null;
    }

    /**
     * Test 1: required courses — one online, one edition-backed — round-trip
     * through getRequiredCourses() with required=true and correct
     * type/course_id/edition_id in trajectory_config.
     */
    public function testRequiredCoursesRoundTripWithTypeAndIds(): void
    {
        $onlineCourse  = $this->makeCourse('Online Required');
        $editionCourse = $this->makeCourse('Edition-Backed Required');
        $editionId     = $this->makeEdition('Edition For Required');

        $this->save([
            'courses_required' => [
                json_encode(['type' => 'online', 'course_id' => $onlineCourse]),
                json_encode([
                    'type'       => 'edition',
                    'course_id'  => $editionCourse,
                    'edition_id' => $editionId,
                ]),
            ],
        ]);

        $required = $this->repo->getRequiredCourses($this->trajectoryId);
        $this->assertCount(2, $required, 'both required courses must round-trip');

        $onlineCfg = $this->configFor($required, $onlineCourse);
        $this->assertNotNull($onlineCfg, 'the online required course must be present');
        $this->assertTrue($onlineCfg['required'], 'online course must be marked required=true');
        $this->assertSame('online', $onlineCfg['type'], 'online course type must round-trip');
        $this->assertSame($onlineCourse, (int) $onlineCfg['course_id'], 'online course_id must round-trip');

        $editionCfg = $this->configFor($required, $editionCourse);
        $this->assertNotNull($editionCfg, 'the edition-backed required course must be present');
        $this->assertTrue($editionCfg['required'], 'edition course must be marked required=true');
        $this->assertSame('edition', $editionCfg['type'], 'edition course type must round-trip');
        $this->assertSame($editionCourse, (int) $editionCfg['course_id'], 'edition course_id must round-trip');
        $this->assertSame(
            $editionId,
            (int) $editionCfg['edition_id'],
            'edition-backed course must carry the posted edition_id through the rebuild',
        );
    }

    /**
     * Test 2: one elective group "GroupA" with pick_count=2 and two courses ⇒
     * getElectiveGroups() returns one group named GroupA, required===2 (the
     * TrajectoryRepository:348 pick_count fix), with both courses.
     */
    public function testSingleElectiveGroupRoundTripsWithPickCount(): void
    {
        $courseA = $this->makeCourse('GroupA Course 1');
        $courseB = $this->makeCourse('GroupA Course 2');

        $this->save([
            'elective_groups' => [
                [
                    'name'       => 'GroupA',
                    'pick_count' => 2,
                    'courses'    => [
                        json_encode(['type' => 'online', 'course_id' => $courseA]),
                        json_encode(['type' => 'online', 'course_id' => $courseB]),
                    ],
                ],
            ],
        ]);

        $groups = $this->repo->getElectiveGroups($this->trajectoryId);

        $this->assertCount(1, $groups, 'exactly one elective group must round-trip');
        $this->assertSame('GroupA', $groups[0]['name'], 'group name must round-trip');
        $this->assertSame(
            2,
            $groups[0]['required'],
            'getElectiveGroups()[required] must reflect pick_count=2, not fall back to 0/1',
        );
        $this->assertCount(2, $groups[0]['courses'], 'both elective courses must be in the group');

        $ids = array_map(static fn($p) => (int) $p->ID, $groups[0]['courses']);
        $this->assertContains($courseA, $ids, 'courseA must be a member of GroupA');
        $this->assertContains($courseB, $ids, 'courseB must be a member of GroupA');
    }

    /**
     * Test 3: TWO elective groups with different pick_counts ⇒ both round-trip
     * with the right names, counts, and membership (proves grouping by name).
     */
    public function testTwoElectiveGroupsRoundTripWithDistinctCountsAndMembership(): void
    {
        $alphaCourse = $this->makeCourse('Alpha Course');
        $betaCourse1 = $this->makeCourse('Beta Course 1');
        $betaCourse2 = $this->makeCourse('Beta Course 2');

        $this->save([
            'elective_groups' => [
                [
                    'name'       => 'Alpha',
                    'pick_count' => 1,
                    'courses'    => [
                        json_encode(['type' => 'online', 'course_id' => $alphaCourse]),
                    ],
                ],
                [
                    'name'       => 'Beta',
                    'pick_count' => 2,
                    'courses'    => [
                        json_encode(['type' => 'online', 'course_id' => $betaCourse1]),
                        json_encode(['type' => 'online', 'course_id' => $betaCourse2]),
                    ],
                ],
            ],
        ]);

        $groups = $this->repo->getElectiveGroups($this->trajectoryId);
        $this->assertCount(2, $groups, 'both elective groups must round-trip');

        $byName = [];
        foreach ($groups as $group) {
            $byName[$group['name']] = $group;
        }

        $this->assertArrayHasKey('Alpha', $byName, 'the Alpha group must round-trip by name');
        $this->assertArrayHasKey('Beta', $byName, 'the Beta group must round-trip by name');

        $this->assertSame(1, $byName['Alpha']['required'], 'Alpha pick_count=1 must round-trip');
        $this->assertSame(2, $byName['Beta']['required'], 'Beta pick_count=2 must round-trip');

        $alphaIds = array_map(static fn($p) => (int) $p->ID, $byName['Alpha']['courses']);
        $betaIds  = array_map(static fn($p) => (int) $p->ID, $byName['Beta']['courses']);

        $this->assertSame([$alphaCourse], $alphaIds, 'Alpha must contain exactly its one course');
        $this->assertContains($betaCourse1, $betaIds, 'Beta must contain betaCourse1');
        $this->assertContains($betaCourse2, $betaIds, 'Beta must contain betaCourse2');
        $this->assertCount(2, $betaIds, 'Beta must contain exactly its two courses');
    }

    /**
     * Test 4: legacy integer format. parseCourseItemValue() explicitly supports a
     * bare numeric value (is_numeric → {type:online, course_id:N}); assert a
     * required course posted as a plain integer still lands as a course entry.
     */
    public function testLegacyIntegerCourseValueParses(): void
    {
        $courseId = $this->makeCourse('Legacy Integer Course');

        $this->save([
            'courses_required' => [
                (string) $courseId, // legacy bare-integer form, as select values arrive
            ],
        ]);

        $required = $this->repo->getRequiredCourses($this->trajectoryId);
        $this->assertCount(1, $required, 'the legacy-integer required course must round-trip');

        $cfg = $this->configFor($required, $courseId);
        $this->assertNotNull($cfg, 'legacy-integer course must be present');
        $this->assertSame('online', $cfg['type'], 'legacy integer must be treated as an online course');
        $this->assertSame($courseId, (int) $cfg['course_id'], 'legacy integer must become the course_id');
        $this->assertTrue($cfg['required'], 'legacy-integer required course must be marked required=true');
    }

    /**
     * Test 5: required vs elective split is exclusive — a course posted as
     * required is NOT in any elective group, and an elective course is NOT in the
     * required set.
     */
    public function testRequiredAndElectiveSplitIsExclusive(): void
    {
        $requiredCourse = $this->makeCourse('Split Required');
        $electiveCourse = $this->makeCourse('Split Elective');

        $this->save([
            'courses_required' => [
                json_encode(['type' => 'online', 'course_id' => $requiredCourse]),
            ],
            'elective_groups' => [
                [
                    'name'       => 'Keuze',
                    'pick_count' => 1,
                    'courses'    => [
                        json_encode(['type' => 'online', 'course_id' => $electiveCourse]),
                    ],
                ],
            ],
        ]);

        $requiredIds = array_map(
            static fn($p) => (int) $p->ID,
            $this->repo->getRequiredCourses($this->trajectoryId),
        );

        $electiveIds = [];
        foreach ($this->repo->getElectiveGroups($this->trajectoryId) as $group) {
            foreach ($group['courses'] as $post) {
                $electiveIds[] = (int) $post->ID;
            }
        }

        $this->assertSame([$requiredCourse], $requiredIds, 'only the required course is in the required set');
        $this->assertSame([$electiveCourse], $electiveIds, 'only the elective course is in the elective set');
        $this->assertNotContains($requiredCourse, $electiveIds, 'a required course must not leak into an elective group');
        $this->assertNotContains($electiveCourse, $requiredIds, 'an elective course must not leak into the required set');
    }

    /**
     * Test 6: parseCourseItemValue guards a malformed item value. A non-numeric,
     * non-JSON string (and a JSON object with course_id<=0) returns null and is
     * skipped by the rebuild loop — no fatal, no bogus entry. A valid sibling
     * course posted alongside still lands, proving the skip is surgical.
     */
    public function testMalformedCourseItemValueIsSkippedNotFatal(): void
    {
        $validCourse = $this->makeCourse('Valid Alongside Malformed');

        $this->save([
            'courses_required' => [
                'not-json-not-a-number',                       // non-numeric, non-JSON → null
                json_encode(['type' => 'online', 'course_id' => 0]), // JSON but course_id<=0 → null
                json_encode(['type' => 'online', 'course_id' => $validCourse]),
            ],
        ]);

        // Assert on the raw stored courses so a bogus entry (e.g. course_id=0)
        // that resolveCoursePosts would silently drop still can't hide.
        $stored = $this->repo->getCourses($this->trajectoryId);
        $this->assertCount(
            1,
            $stored,
            'only the one valid course must be stored — malformed items produce no entry',
        );
        $this->assertSame(
            $validCourse,
            (int) $stored[0]['course_id'],
            'the surviving stored entry must be the valid course',
        );

        $required = $this->repo->getRequiredCourses($this->trajectoryId);
        $this->assertCount(1, $required, 'only the valid required course resolves');
        $this->assertSame($validCourse, (int) $required[0]->ID, 'the resolved course is the valid one');
    }
}
