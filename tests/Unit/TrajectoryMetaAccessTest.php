<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Domain\TrajectoryMode;
use Stride\Domain\OfferingStatus;
use Stride\Tests\TestCase;

/**
 * Unit tests for Trajectory meta access
 *
 * Tests that TrajectoryAdminController and related services correctly use
 * Data Manager for meta access after refactoring away from legacy prefixes.
 */
class TrajectoryMetaAccessTest extends TestCase
{
    /**
     * @test
     */
    public function testTrajectoryModeReadViaDataManager(): void
    {
        global $_test_posts;

        $trajectory = (object) [
            'ID' => 100,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Trajectory',
            'post_status' => 'publish',
        ];
        $_test_posts[100] = $trajectory;

        $this->setDataManagerMeta('vad_trajectory', 100, [
            'mode' => TrajectoryMode::Cohort->value,
        ]);

        $model = ntdst_data()->get('vad_trajectory');
        $mode = $model->getMeta(100, 'mode');

        $this->assertEquals(TrajectoryMode::Cohort->value, $mode);

        $modeEnum = TrajectoryMode::tryFrom($mode);
        $this->assertEquals(TrajectoryMode::Cohort, $modeEnum);
    }

    /**
     * @test
     */
    public function testOfferingStatusReadViaDataManager(): void
    {
        global $_test_posts;

        $trajectory = (object) [
            'ID' => 101,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Trajectory Status',
            'post_status' => 'publish',
        ];
        $_test_posts[101] = $trajectory;

        $this->setDataManagerMeta('vad_trajectory', 101, [
            'status' => OfferingStatus::Open->value,
        ]);

        $model = ntdst_data()->get('vad_trajectory');
        $status = $model->getMeta(101, 'status');

        $this->assertEquals(OfferingStatus::Open->value, $status);

        $statusEnum = OfferingStatus::tryFrom($status);
        $this->assertEquals(OfferingStatus::Open, $statusEnum);
    }

    /**
     * @test
     */
    public function testTrajectoryCoursesArrayReadViaDataManager(): void
    {
        global $_test_posts;

        $trajectory = (object) [
            'ID' => 102,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Trajectory Courses',
            'post_status' => 'publish',
        ];
        $_test_posts[102] = $trajectory;

        $courses = [
            ['type' => 'online', 'course_id' => 1, 'required' => true],
            ['type' => 'edition', 'course_id' => 2, 'edition_id' => 100, 'required' => true],
            ['type' => 'online', 'course_id' => 3, 'required' => false, 'group' => 'Electives'],
        ];

        $this->setDataManagerMeta('vad_trajectory', 102, [
            'courses' => $courses,
        ]);

        $model = ntdst_data()->get('vad_trajectory');
        $result = $model->getMeta(102, 'courses');

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertEquals('online', $result[0]['type']);
        $this->assertEquals('edition', $result[1]['type']);
        $this->assertTrue($result[0]['required']);
        $this->assertFalse($result[2]['required']);
    }

    /**
     * @test
     */
    public function testTrajectoryCapacityReadViaDataManager(): void
    {
        global $_test_posts;

        $trajectory = (object) [
            'ID' => 103,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Trajectory Capacity',
            'post_status' => 'publish',
        ];
        $_test_posts[103] = $trajectory;

        $this->setDataManagerMeta('vad_trajectory', 103, [
            'capacity' => 25,
        ]);

        $model = ntdst_data()->get('vad_trajectory');
        $capacity = $model->getMeta(103, 'capacity');

        $this->assertEquals(25, $capacity);
    }

    /**
     * @test
     */
    public function testTrajectoryDeadlineFieldsReadViaDataManager(): void
    {
        global $_test_posts;

        $trajectory = (object) [
            'ID' => 104,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Trajectory Deadlines',
            'post_status' => 'publish',
        ];
        $_test_posts[104] = $trajectory;

        $this->setDataManagerMeta('vad_trajectory', 104, [
            'enrollment_deadline' => '2024-06-30',
            'choice_available_date' => '2024-03-01',
            'choice_deadline' => '2024-05-15',
            'deadline_months' => 18,
        ]);

        $model = ntdst_data()->get('vad_trajectory');

        $this->assertEquals('2024-06-30', $model->getMeta(104, 'enrollment_deadline'));
        $this->assertEquals('2024-03-01', $model->getMeta(104, 'choice_available_date'));
        $this->assertEquals('2024-05-15', $model->getMeta(104, 'choice_deadline'));
        $this->assertEquals(18, $model->getMeta(104, 'deadline_months'));
    }

    /**
     * @test
     */
    public function testEditionMetaAccessForTrajectoryCourses(): void
    {
        $edition = $this->createEdition(['ID' => 200]);

        $this->setDataManagerMeta('vad_edition', 200, [
            'start_date' => '2024-09-01',
            'venue' => 'Utrecht Training Center',
            'course_id' => 50,
        ]);

        $model = ntdst_data()->get('vad_edition');

        $this->assertEquals('2024-09-01', $model->getMeta(200, 'start_date'));
        $this->assertEquals('Utrecht Training Center', $model->getMeta(200, 'venue'));
        $this->assertEquals(50, $model->getMeta(200, 'course_id'));
    }

    /**
     * @test
     */
    public function testLegacyStridePrefixNotUsed(): void
    {
        global $_test_posts, $_test_post_meta;

        $trajectory = (object) [
            'ID' => 105,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Legacy Prefix',
            'post_status' => 'publish',
        ];
        $_test_posts[105] = $trajectory;

        // Simulate old data with _stride_ prefix
        $_test_post_meta[105]['_stride_mode'] = [TrajectoryMode::Cohort->value];
        $_test_post_meta[105]['_stride_status'] = [OfferingStatus::Open->value];

        // Data Manager should NOT find this
        $model = ntdst_data()->get('vad_trajectory');

        $this->assertNull($model->getMeta(105, 'mode'));
        $this->assertNull($model->getMeta(105, 'status'));
    }

    /**
     * @test
     */
    public function testUpdateTrajectoryMetaViaDataManager(): void
    {
        global $_test_posts;

        $trajectory = (object) [
            'ID' => 106,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Update Meta',
            'post_status' => 'publish',
        ];
        $_test_posts[106] = $trajectory;

        $model = ntdst_data()->get('vad_trajectory');

        // Initial state - no meta
        $this->assertNull($model->getMeta(106, 'mode'));

        // Update via Data Manager
        $result = $model->updateMetaBatch(106, [
            'mode' => TrajectoryMode::SelfPaced->value,
            'status' => OfferingStatus::InProgress->value,
            'capacity' => 50,
        ]);

        $this->assertTrue($result);

        // Verify updates
        $this->assertEquals(TrajectoryMode::SelfPaced->value, $model->getMeta(106, 'mode'));
        $this->assertEquals(OfferingStatus::InProgress->value, $model->getMeta(106, 'status'));
        $this->assertEquals(50, $model->getMeta(106, 'capacity'));
    }

    /**
     * @test
     * @dataProvider trajectoryModeProvider
     */
    public function testAllTrajectoryModesCanBeStoredAndRetrieved(TrajectoryMode $mode): void
    {
        global $_test_posts;

        $id = 300 + ord($mode->value[0]);
        $trajectory = (object) [
            'ID' => $id,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Mode ' . $mode->value,
            'post_status' => 'publish',
        ];
        $_test_posts[$id] = $trajectory;

        $model = ntdst_data()->get('vad_trajectory');
        $model->updateMetaBatch($id, ['mode' => $mode->value]);

        $retrieved = $model->getMeta($id, 'mode');
        $this->assertEquals($mode->value, $retrieved);

        $parsed = TrajectoryMode::tryFrom($retrieved);
        $this->assertEquals($mode, $parsed);
    }

    public static function trajectoryModeProvider(): array
    {
        return [
            'cohort' => [TrajectoryMode::Cohort],
            'self_paced' => [TrajectoryMode::SelfPaced],
        ];
    }

    /**
     * @test
     * @dataProvider trajectoryStatusProvider
     */
    public function testAllOfferingStatusesCanBeStoredAndRetrieved(OfferingStatus $status): void
    {
        global $_test_posts;

        $id = 400 + ord($status->value[0]);
        $trajectory = (object) [
            'ID' => $id,
            'post_type' => 'vad_trajectory',
            'post_title' => 'Test Status ' . $status->value,
            'post_status' => 'publish',
        ];
        $_test_posts[$id] = $trajectory;

        $model = ntdst_data()->get('vad_trajectory');
        $model->updateMetaBatch($id, ['status' => $status->value]);

        $retrieved = $model->getMeta($id, 'status');
        $this->assertEquals($status->value, $retrieved);

        $parsed = OfferingStatus::tryFrom($retrieved);
        $this->assertEquals($status, $parsed);
    }

    public static function trajectoryStatusProvider(): array
    {
        return [
            'draft' => [OfferingStatus::Draft],
            'announcement' => [OfferingStatus::Announcement],
            'open' => [OfferingStatus::Open],
            'full' => [OfferingStatus::Full],
            'in_progress' => [OfferingStatus::InProgress],
            'postponed' => [OfferingStatus::Postponed],
            'cancelled' => [OfferingStatus::Cancelled],
            'completed' => [OfferingStatus::Completed],
            'archived' => [OfferingStatus::Archived],
        ];
    }
}
