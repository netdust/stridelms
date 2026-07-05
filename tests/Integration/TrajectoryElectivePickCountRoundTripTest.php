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
 * Regression guard (T-1): an elective group's "kies N" pick count must survive
 * the admin save → repository read round-trip.
 *
 * The bug: handleSave() STORES the group pick count under the JSON key
 * `pick_count` (TrajectoryAdminController::handleSave, elective_groups loop —
 * `$courseEntry['pick_count'] = $pickCount;`), but getElectiveGroups() READ the
 * count from `min_choices`, which was never written. With no mapping, the count
 * was lost on read and every group fell back to required=0, so a "kies 2 uit 4"
 * trajectory silently validated/displayed as "kies 1"
 * (TrajectorySelection::validateSelections uses max(1, required)).
 *
 * The first test drives the REAL controller save path so it proves the real
 * store key (`pick_count`). The second pins the legacy `min_choices` fallback so
 * any pre-fix data still reads back correctly.
 *
 * Run (disposable-DB gate forwarded via --raw; a silent exit 255 = a swallowed
 * PHP fatal, not "no tests matched"):
 *   ddev exec --raw -- bash -c 'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist \
 *     --filter TrajectoryElectivePickCountRoundTrip'
 */
final class TrajectoryElectivePickCountRoundTripTest extends IntegrationTestCase
{
    private TrajectoryRepository $repo;
    private int $trajectoryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = ntdst_get(TrajectoryRepository::class);

        // handleSave()'s current_user_can('edit_post', $id) guard resolves via
        // map_meta_cap for the vad_trajectory CPT — promote the fixture user to
        // administrator so the save is allowed (same pattern as the QuoteAdmin
        // handleSave integration tests).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');

        $this->trajectoryId = wp_insert_post([
            'post_type'   => 'vad_trajectory',
            'post_title'  => 'Elective Pick Count Test ' . uniqid(),
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

    /**
     * The REAL round-trip: post a "kies 2 uit 2" elective group through the
     * controller, then read it back through getElectiveGroups(). Before the fix
     * this reported required=0 (min_choices never stored), so a "kies 2"
     * trajectory validated as "kies 1".
     */
    public function testElectiveGroupPickCountSurvivesRealSaveRoundTrip(): void
    {
        $courseA = wp_insert_post([
            'post_type'   => 'sfwd-courses',
            'post_title'  => 'Elective A ' . uniqid(),
            'post_status' => 'publish',
        ]);
        $courseB = wp_insert_post([
            'post_type'   => 'sfwd-courses',
            'post_title'  => 'Elective B ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $courseA;
        self::$testPosts[] = $courseB;

        // Course item values are posted as the form encodes them — a JSON string
        // decoded by parseCourseItemValue(). Use plain online courses.
        $this->save([
            'elective_groups' => [
                [
                    'name'       => 'Keuzemodules',
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
        $group = $groups[0];

        $this->assertSame('Keuzemodules', $group['name'], 'group name must round-trip');
        $this->assertSame(
            2,
            $group['required'],
            'getElectiveGroups()[required] must reflect the stored pick_count=2 '
            . '("kies 2 uit N"), not fall back to 0',
        );
    }

    /**
     * Legacy-data fallback: a stored course entry that used the OLD `min_choices`
     * key (and no `pick_count`) must still read back required=3, so the read-side
     * fix does not orphan any pre-fix data.
     */
    public function testLegacyMinChoicesStillReadsBack(): void
    {
        $courseId = wp_insert_post([
            'post_type'   => 'sfwd-courses',
            'post_title'  => 'Legacy Elective ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $courseId;

        // Write the raw courses array exactly as a PRE-FIX version would have:
        // an elective entry keyed on min_choices, with NO pick_count.
        $this->repo->update($this->trajectoryId, [
            'courses' => [
                [
                    'type'        => 'online',
                    'course_id'   => $courseId,
                    'required'    => false,
                    'group'       => 'Legacy Group',
                    'min_choices' => 3,
                ],
            ],
        ]);

        $groups = $this->repo->getElectiveGroups($this->trajectoryId);

        $this->assertCount(1, $groups, 'the legacy elective group must read back');
        $this->assertSame('Legacy Group', $groups[0]['name']);
        $this->assertSame(
            3,
            $groups[0]['required'],
            'a legacy entry with only min_choices=3 must still read back required=3 '
            . '(fallback must not orphan pre-fix data)',
        );
    }
}
