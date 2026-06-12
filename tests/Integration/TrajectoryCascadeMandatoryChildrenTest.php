<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCascadeService;

/**
 * Shake-out BUG-5 (2026-06-12): cascadeOnSelection reconciled ALL children
 * against the elective selection, so submitting elective choices CANCELLED
 * the mandatory-course child registrations created at enrollment.
 *
 * Contract: the elective slate only governs elective children — children on
 * mandatory editions (required entries in the trajectory's courses config)
 * are never touched by a choices submission.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryCascadeMandatoryChildrenTest
 */
final class TrajectoryCascadeMandatoryChildrenTest extends IntegrationTestCase
{
    private int $trajectoryId;
    private int $mandatoryEditionId;
    private int $electiveEditionA;
    private int $electiveEditionB;
    private int $userId;
    private int $parentId;
    private int $mandatoryChildId;

    /** @var array<int> */
    private array $registrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $mandatoryCourse = $this->makeCourse('Mandatory Course');
        $electiveCourseA = $this->makeCourse('Elective Course A');
        $electiveCourseB = $this->makeCourse('Elective Course B');

        $this->mandatoryEditionId = $this->makeEdition($mandatoryCourse);
        $this->electiveEditionA = $this->makeEdition($electiveCourseA);
        $this->electiveEditionB = $this->makeEdition($electiveCourseB);

        $this->trajectoryId = wp_insert_post([
            'post_type' => 'vad_trajectory', 'post_status' => 'publish',
            'post_title' => 'Mandatory Children Trajectory ' . uniqid(),
        ]);
        self::$testPosts[] = $this->trajectoryId;
        update_post_meta($this->trajectoryId, '_ntdst_mode', 'cohort');
        update_post_meta($this->trajectoryId, '_ntdst_status', 'open');
        update_post_meta($this->trajectoryId, '_ntdst_courses', wp_json_encode([
            ['course_id' => $mandatoryCourse, 'required' => true, 'type' => 'edition', 'edition_id' => $this->mandatoryEditionId, 'order' => 1],
            ['course_id' => $electiveCourseA, 'required' => false, 'type' => 'edition', 'edition_id' => $this->electiveEditionA, 'group' => 'Keuze', 'min_choices' => 1],
            ['course_id' => $electiveCourseB, 'required' => false, 'type' => 'edition', 'edition_id' => $this->electiveEditionB, 'group' => 'Keuze', 'min_choices' => 1],
        ]));

        $this->userId = wp_create_user('mandchild_' . uniqid(), 'pass12345', 'mandchild_' . uniqid() . '@test.local');

        $repo = ntdst_get(RegistrationRepository::class);
        $this->parentId = $repo->create([
            'user_id' => $this->userId,
            'trajectory_id' => $this->trajectoryId,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
        ]);
        $this->registrationIds[] = $this->parentId;

        // Mandatory child as cascadeOnEnrollment creates it.
        $this->mandatoryChildId = $repo->create([
            'user_id' => $this->userId,
            'edition_id' => $this->mandatoryEditionId,
            'parent_registration_id' => $this->parentId,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
        ]);
        $this->registrationIds[] = $this->mandatoryChildId;
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}vad_registrations WHERE user_id = %d",
            $this->userId,
        ));
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($this->userId);

        parent::tearDown();
    }

    private function makeCourse(string $title): int
    {
        $id = wp_insert_post([
            'post_type' => 'sfwd-courses', 'post_status' => 'publish',
            'post_title' => $title . ' ' . uniqid(),
        ]);
        self::$testPosts[] = $id;

        return $id;
    }

    private function makeEdition(int $courseId): int
    {
        $id = wp_insert_post([
            'post_type' => 'vad_edition', 'post_status' => 'publish',
            'post_title' => 'Edition for ' . $courseId,
        ]);
        self::$testPosts[] = $id;
        update_post_meta($id, '_ntdst_course_id', (string) $courseId);
        update_post_meta($id, '_ntdst_status', 'open');
        update_post_meta($id, '_ntdst_capacity', '20');

        return $id;
    }

    public function testElectiveSelectionDoesNotCancelMandatoryChild(): void
    {
        $cascade = ntdst_get(TrajectoryCascadeService::class);

        $result = $cascade->cascadeOnSelection($this->parentId, [$this->electiveEditionA]);
        $this->assertTrue($result === true, 'cascade must succeed');

        $repo = ntdst_get(RegistrationRepository::class);
        $mandatory = $repo->find($this->mandatoryChildId);
        $this->assertSame(
            'confirmed',
            (string) $mandatory->status,
            'the mandatory child must survive an elective choices submission'
        );

        // The elective child was created.
        global $wpdb;
        $electiveChild = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$wpdb->prefix}vad_registrations
             WHERE parent_registration_id = %d AND edition_id = %d",
            $this->parentId,
            $this->electiveEditionA,
        ));
        $this->assertNotNull($electiveChild, 'elective child must be created');
        $this->assertSame('confirmed', (string) $electiveChild->status);
        if ($electiveChild) {
            $this->registrationIds[] = (int) $electiveChild->id;
        }
    }

    public function testSwitchingElectivesOnlyReconcilesElectiveChildren(): void
    {
        $cascade = ntdst_get(TrajectoryCascadeService::class);

        $cascade->cascadeOnSelection($this->parentId, [$this->electiveEditionA]);
        $cascade->cascadeOnSelection($this->parentId, [$this->electiveEditionB]);

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, status FROM {$wpdb->prefix}vad_registrations
             WHERE parent_registration_id = %d",
            $this->parentId,
        ), OBJECT_K);

        $byEdition = [];
        foreach ($rows as $row) {
            $byEdition[(int) $row->edition_id] = (string) $row->status;
        }

        $this->assertSame('confirmed', $byEdition[$this->mandatoryEditionId] ?? null, 'mandatory child untouched across switches');
        $this->assertSame('cancelled', $byEdition[$this->electiveEditionA] ?? null, 'deselected elective cancelled');
        $this->assertSame('confirmed', $byEdition[$this->electiveEditionB] ?? null, 'newly selected elective confirmed');
    }
}
