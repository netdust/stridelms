<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Edition\EditionCompletion;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for post-course completion flow.
 *
 * Verifies: attendance met -> post-course tasks initialized -> tasks completed -> LD complete.
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter PostCourseCompletion
 */
class PostCourseCompletionTest extends IntegrationTestCase
{
    private EditionCompletion $editionCompletion;
    private EnrollmentCompletion $enrollmentCompletion;
    private RegistrationRepository $repo;
    private array $testRegistrationIds = [];
    private array $testAttendanceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->editionCompletion = ntdst_get(EditionCompletion::class);
        $this->enrollmentCompletion = ntdst_get(EnrollmentCompletion::class);
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        // Clean up registrations
        foreach ($this->testRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->testRegistrationIds = [];

        // Clean up attendance records
        $this->cleanupAttendanceRecords();
        $this->testAttendanceIds = [];

        parent::tearDown();
    }

    // =========================================================================
    // SCENARIO 1: Attendance met -> post-course tasks initialized
    // =========================================================================

    /**
     * @test
     */
    public function attendanceCompleteInitializesPostCourseTasks(): void
    {
        // 1. Create a course + edition with post_requires_evaluation
        $courseId = $this->createTestCourse();
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_status' => 'open',
                '_ntdst_course_id' => $courseId,
                '_ntdst_completion_mode' => 'attend_all',
                '_ntdst_post_requires_evaluation' => '1',
            ],
        ]);

        // 2. Create a session for the edition
        $sessionId = $this->createTestSession($editionId);

        // 3. Create a confirmed registration
        $regId = $this->createConfirmedRegistration(self::$testUserId, $editionId);

        // 4. Mark attendance (user present for the only session)
        $this->markAttendance($sessionId, self::$testUserId, $editionId);

        // 5. Track whether attendance_complete action fires
        $actionFired = false;
        $actionData = [];
        add_action('stride/completion/attendance_complete', function ($data) use (&$actionFired, &$actionData) {
            $actionFired = true;
            $actionData = $data;
        });

        // Track whether LD completion action fires (it should NOT)
        $ldCompleteFired = false;
        add_action('stride/completion/completed', function () use (&$ldCompleteFired) {
            $ldCompleteFired = true;
        });

        // 6. Process completion
        $result = $this->editionCompletion->processCompletion($editionId, self::$testUserId);

        $this->assertTrue($result, 'processCompletion should return true');

        // 7. Assert: attendance_complete action fired
        $this->assertTrue($actionFired, 'stride/completion/attendance_complete action should fire');
        $this->assertEquals($editionId, $actionData['edition_id']);
        $this->assertEquals(self::$testUserId, $actionData['user_id']);
        $this->assertEquals($regId, $actionData['registration_id']);

        // 8. Assert: LD completion was NOT triggered (deferred)
        $this->assertFalse($ldCompleteFired, 'LD completion should be deferred when post-course tasks exist');

        // 9. Assert: registration now has post_evaluation task
        $registration = $this->repo->find($regId);
        $this->assertNotNull($registration, 'Registration should exist');

        $tasks = $registration->completion_tasks;
        $this->assertIsArray($tasks, 'completion_tasks should be an array');
        $this->assertArrayHasKey('post_evaluation', $tasks, 'Should have post_evaluation task');
        $this->assertEquals('pending', $tasks['post_evaluation']['status']);
        $this->assertEquals('post_course', $tasks['post_evaluation']['phase']);
    }

    // =========================================================================
    // SCENARIO 2: All post-course tasks complete -> LD complete + status completed
    // =========================================================================

    /**
     * @test
     */
    public function allPostCourseTasksCompleteTriggersFinalCompletion(): void
    {
        // 1. Create a course + edition with post-course requirements
        $courseId = $this->createTestCourse();
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_status' => 'open',
                '_ntdst_course_id' => $courseId,
                '_ntdst_post_requires_evaluation' => '1',
            ],
        ]);

        // 2. Create a confirmed registration with post-course tasks already initialized
        $regId = $this->createConfirmedRegistration(self::$testUserId, $editionId);
        $this->repo->updateCompletionTasks($regId, [
            'post_evaluation' => ['status' => 'pending', 'phase' => 'post_course'],
        ]);

        // 3. Track task_completed action
        $taskCompletedFired = false;
        add_action('stride/enrollment/task_completed', function ($data) use (&$taskCompletedFired) {
            $taskCompletedFired = true;
        });

        // 4. Complete the post_evaluation task
        $result = $this->enrollmentCompletion->completeTask($regId, 'post_evaluation', ['answers' => ['q1' => 'good']]);
        $this->assertTrue($result, 'completeTask should return true');

        // 5. Assert: task_completed action fired
        $this->assertTrue($taskCompletedFired, 'stride/enrollment/task_completed action should fire');

        // 6. Assert: CompletionTaskHandler auto-completed the registration
        //    The handler listens to stride/enrollment/task_completed and when
        //    isFullyComplete() returns true with post-course tasks, it:
        //    - calls processCompletionFinal (LD completion)
        //    - updates status to 'completed'
        $registration = $this->repo->find($regId);
        $this->assertNotNull($registration, 'Registration should exist');
        $this->assertEquals(
            RegistrationStatus::Completed->value,
            $registration->status,
            'Registration status should be completed after all post-course tasks done'
        );
    }

    /**
     * @test
     */
    public function multiplePostCourseTasksAllMustComplete(): void
    {
        // 1. Create edition with multiple post-course requirements
        $courseId = $this->createTestCourse();
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_status' => 'open',
                '_ntdst_course_id' => $courseId,
                '_ntdst_post_requires_evaluation' => '1',
                '_ntdst_post_requires_documents' => '1',
            ],
        ]);

        // 2. Create registration with both post-course tasks
        $regId = $this->createConfirmedRegistration(self::$testUserId, $editionId);
        $this->repo->updateCompletionTasks($regId, [
            'post_evaluation' => ['status' => 'pending', 'phase' => 'post_course'],
            'post_documents' => ['status' => 'pending', 'phase' => 'post_course'],
        ]);

        // 3. Complete only post_evaluation -- status should NOT change yet
        $this->enrollmentCompletion->completeTask($regId, 'post_evaluation', ['answers' => ['q1' => 'ok']]);

        $registration = $this->repo->find($regId);
        $this->assertEquals(
            RegistrationStatus::Confirmed->value,
            $registration->status,
            'Status should remain confirmed when only some post-course tasks are done'
        );

        // 4. Complete post_documents -- now status should become completed
        $this->enrollmentCompletion->completeTask($regId, 'post_documents', ['files' => [123]]);

        $registration = $this->repo->find($regId);
        $this->assertEquals(
            RegistrationStatus::Completed->value,
            $registration->status,
            'Status should be completed after ALL post-course tasks done'
        );
    }

    // =========================================================================
    // SCENARIO 3: No post-course tasks -> immediate LD completion (regression)
    // =========================================================================

    /**
     * @test
     */
    public function noPostCourseTasksMarksLdCompleteImmediately(): void
    {
        // 1. Create a course + edition WITHOUT post-course requirements
        $courseId = $this->createTestCourse();
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_status' => 'open',
                '_ntdst_course_id' => $courseId,
                '_ntdst_completion_mode' => 'attend_all',
                // No post_requires_* meta -- no post-course tasks
            ],
        ]);

        // 2. Create a session and mark attendance
        $sessionId = $this->createTestSession($editionId);
        $this->createConfirmedRegistration(self::$testUserId, $editionId);
        $this->markAttendance($sessionId, self::$testUserId, $editionId);

        // 3. Track LD completion action (should fire)
        $ldCompleteFired = false;
        $ldCompleteData = [];
        add_action('stride/completion/completed', function ($data) use (&$ldCompleteFired, &$ldCompleteData) {
            $ldCompleteFired = true;
            $ldCompleteData = $data;
        });

        // Track attendance_complete action (should NOT fire)
        $attendanceCompleteFired = false;
        add_action('stride/completion/attendance_complete', function () use (&$attendanceCompleteFired) {
            $attendanceCompleteFired = true;
        });

        // 4. Process completion
        $result = $this->editionCompletion->processCompletion($editionId, self::$testUserId);
        $this->assertTrue($result, 'processCompletion should return true');

        // 5. Assert: LD completion WAS triggered
        $this->assertTrue($ldCompleteFired, 'stride/completion/completed should fire when no post-course tasks');
        $this->assertEquals($editionId, $ldCompleteData['edition_id']);
        $this->assertEquals(self::$testUserId, $ldCompleteData['user_id']);
        $this->assertEquals($courseId, $ldCompleteData['course_id']);

        // 6. Assert: attendance_complete was NOT fired (that path is for deferred)
        $this->assertFalse($attendanceCompleteFired, 'attendance_complete should not fire without post-course tasks');

        // 7. Assert: no completion tasks on registration
        $reg = $this->repo->findByUserAndEdition(self::$testUserId, $editionId);
        $this->assertTrue(
            empty($reg->completion_tasks),
            'Registration should have no completion tasks when edition has no post-course requirements'
        );
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Create a session CPT linked to an edition.
     */
    private function createTestSession(int $editionId, array $data = []): int
    {
        $defaults = [
            'post_title' => 'Test Session ' . wp_generate_password(4, false),
            'post_type' => 'vad_session',
            'post_status' => 'publish',
        ];

        $postData = array_merge($defaults, $data);
        $sessionId = wp_insert_post($postData);

        if (is_wp_error($sessionId)) {
            throw new \RuntimeException('Failed to create test session: ' . $sessionId->get_error_message());
        }

        self::$testPosts[] = $sessionId;

        // Set session meta
        $metaDefaults = [
            '_ntdst_edition_id' => $editionId,
            '_ntdst_date' => date('Y-m-d', strtotime('+7 days')),
            '_ntdst_start_time' => '09:00',
            '_ntdst_end_time' => '17:00',
            '_ntdst_type' => 'in_person',
        ];

        $meta = array_merge($metaDefaults, $data['meta'] ?? []);
        foreach ($meta as $key => $value) {
            update_post_meta($sessionId, $key, $value);
        }

        return $sessionId;
    }

    /**
     * Create a confirmed registration directly in the database.
     */
    private function createConfirmedRegistration(int $userId, int $editionId): int
    {
        $regId = $this->repo->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);

        if (is_wp_error($regId)) {
            throw new \RuntimeException('Failed to create registration: ' . $regId->get_error_message());
        }

        $this->testRegistrationIds[] = $regId;

        return $regId;
    }

    /**
     * Record attendance directly in the attendance table.
     */
    private function markAttendance(int $sessionId, int $userId, int $editionId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vad_attendance';

        $result = $wpdb->insert($table, [
            'edition_id' => $editionId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_by' => $userId,
        ]);

        if ($result === false) {
            throw new \RuntimeException('Failed to record attendance: ' . $wpdb->last_error);
        }

        $this->testAttendanceIds[] = (int) $wpdb->insert_id;
    }

    /**
     * Clean up attendance records created during tests.
     */
    private function cleanupAttendanceRecords(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'vad_attendance';

        foreach ($this->testAttendanceIds as $id) {
            $wpdb->delete($table, ['id' => $id]);
        }
    }
}
