<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Tests\TestCase;

class EnrollmentCompletionTest extends TestCase
{
    private EnrollmentCompletion $service;
    private \Stride\Modules\Enrollment\RegistrationRepository $mockRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockRepo = $this->createMock(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $this->service = new EnrollmentCompletion(
            $this->mockRepo,
            new EditionRepository(),
            new TrajectoryRepository(),
        );
    }

    /** @test */
    public function testGetRequirementsReturnsEnabledFlags(): void
    {
        $this->setDataManagerMeta('vad_edition', 100, [
            'requires_session_selection' => '1',
            'requires_questionnaire' => '1',
            'requires_documents' => '0',
            'requires_approval' => '0',
        ]);

        $reqs = $this->service->getRequirements(100, 'vad_edition');

        $this->assertTrue($reqs['session_selection']);
        $this->assertTrue($reqs['questionnaire']);
        $this->assertFalse($reqs['documents']);
        $this->assertFalse($reqs['approval']);
    }

    /** @test */
    public function testGetRequirementsAllFalseByDefault(): void
    {
        $reqs = $this->service->getRequirements(999, 'vad_edition');

        $this->assertFalse($reqs['session_selection']);
        $this->assertFalse($reqs['questionnaire']);
        $this->assertFalse($reqs['documents']);
        $this->assertFalse($reqs['approval']);
    }

    /** @test */
    public function testHasRequirementsReturnsTrueWhenAnyEnabled(): void
    {
        $this->setDataManagerMeta('vad_edition', 100, [
            'requires_questionnaire' => '1',
        ]);

        $this->assertTrue($this->service->hasRequirements(100, 'vad_edition'));
    }

    /** @test */
    public function testHasRequirementsReturnsFalseWhenNoneEnabled(): void
    {
        $this->assertFalse($this->service->hasRequirements(999, 'vad_edition'));
    }

    /** @test */
    public function testBuildInitialTasksCreatesCorrectStructure(): void
    {
        $this->setDataManagerMeta('vad_edition', 100, [
            'requires_session_selection' => '1',
            'requires_approval' => '1',
        ]);

        $tasks = $this->service->buildInitialTasks(100, 'vad_edition');

        $this->assertArrayHasKey('session_selection', $tasks);
        $this->assertArrayHasKey('approval', $tasks);
        $this->assertArrayNotHasKey('questionnaire', $tasks);
        $this->assertArrayNotHasKey('documents', $tasks);
        $this->assertEquals('pending', $tasks['session_selection']['status']);
        $this->assertEquals('pending', $tasks['approval']['status']);
        $this->assertEquals('enrollment', $tasks['session_selection']['phase']);
        $this->assertEquals('enrollment', $tasks['approval']['phase']);
    }

    /** @test */
    public function testBuildInitialTasksReturnsEmptyWhenNoRequirements(): void
    {
        $tasks = $this->service->buildInitialTasks(999, 'vad_edition');
        $this->assertEmpty($tasks);
    }

    /** @test */
    public function testAreUserTasksCompleteReturnsTrueWhenAllDone(): void
    {
        $tasks = [
            'session_selection' => ['status' => 'completed'],
            'questionnaire' => ['status' => 'completed'],
            'approval' => ['status' => 'pending'],
        ];

        $this->assertTrue($this->service->areUserTasksComplete($tasks));
    }

    /** @test */
    public function testAreUserTasksCompleteReturnsFalseWhenTaskPending(): void
    {
        $tasks = [
            'session_selection' => ['status' => 'completed'],
            'questionnaire' => ['status' => 'pending'],
        ];

        $this->assertFalse($this->service->areUserTasksComplete($tasks));
    }

    /** @test */
    public function testIsFullyCompleteRequiresAllTasksIncludingApproval(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed'],
            'approval' => ['status' => 'pending'],
        ];

        $this->assertFalse($this->service->isFullyComplete($tasks));

        $tasks['approval']['status'] = 'completed';
        $this->assertTrue($this->service->isFullyComplete($tasks));
    }

    /** @test */
    public function testMarkTaskCompleteUpdatesStatus(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'pending'],
        ];

        $updated = $this->service->markTaskComplete($tasks, 'questionnaire', ['answers' => ['big' => '123']]);

        $this->assertEquals('completed', $updated['questionnaire']['status']);
        $this->assertNotNull($updated['questionnaire']['completed_at']);
        $this->assertEquals(['answers' => ['big' => '123']], $updated['questionnaire']['data']);
    }

    /** @test */
    public function testMarkTaskCompleteWithoutData(): void
    {
        $tasks = [
            'session_selection' => ['status' => 'pending'],
        ];

        $updated = $this->service->markTaskComplete($tasks, 'session_selection');

        $this->assertEquals('completed', $updated['session_selection']['status']);
        $this->assertArrayNotHasKey('data', $updated['session_selection']);
    }

    /** @test */
    public function testAvailabilityQuestionnaireAlwaysAvailable(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ];

        $availability = $this->service->getTaskAvailability($tasks, 0);

        $this->assertEquals('available', $availability['questionnaire']['state']);
    }

    /** @test */
    public function testAvailabilityApprovalLockedUntilImmediateTasksDone(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'pending'],
            'documents' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ];

        $availability = $this->service->getTaskAvailability($tasks, 0);

        $this->assertEquals('locked', $availability['approval']['state']);
    }

    /** @test */
    public function testAvailabilityApprovalAvailableWhenImmediateTasksDone(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed'],
            'documents' => ['status' => 'completed'],
            'approval' => ['status' => 'pending'],
        ];

        $availability = $this->service->getTaskAvailability($tasks, 0);

        $this->assertEquals('available', $availability['approval']['state']);
    }

    /** @test */
    public function testAvailabilitySessionSelectionLockedWithoutApproval(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed'],
            'approval' => ['status' => 'pending'],
            'session_selection' => ['status' => 'pending'],
        ];

        // Even with selection_open, approval blocks it
        $this->setDataManagerMeta('vad_edition', 100, [
            'selection_open' => '1',
        ]);

        $availability = $this->service->getTaskAvailability($tasks, 100);

        $this->assertEquals('locked', $availability['session_selection']['state']);
        $this->assertStringContainsString('goedkeuring', strtolower($availability['session_selection']['reason']));
    }

    /** @test */
    public function testAvailabilitySessionSelectionLockedWhenNotOpen(): void
    {
        $tasks = [
            'session_selection' => ['status' => 'pending'],
        ];

        // No approval, but selection_open is false
        $this->setDataManagerMeta('vad_edition', 100, [
            'selection_open' => '0',
        ]);

        $availability = $this->service->getTaskAvailability($tasks, 100);

        $this->assertEquals('locked', $availability['session_selection']['state']);
        $this->assertStringContainsString('niet geopend', strtolower($availability['session_selection']['reason']));
    }

    /** @test */
    public function testAvailabilitySessionSelectionAvailableWhenOpenAndApproved(): void
    {
        $tasks = [
            'approval' => ['status' => 'completed'],
            'session_selection' => ['status' => 'pending'],
        ];

        $this->setDataManagerMeta('vad_edition', 100, [
            'selection_open' => '1',
        ]);

        $availability = $this->service->getTaskAvailability($tasks, 100);

        $this->assertEquals('available', $availability['session_selection']['state']);
    }

    /** @test */
    public function testAvailabilityCompletedTasksAlwaysCompleted(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed'],
            'approval' => ['status' => 'completed'],
            'session_selection' => ['status' => 'completed'],
        ];

        $availability = $this->service->getTaskAvailability($tasks, 0);

        $this->assertEquals('completed', $availability['questionnaire']['state']);
        $this->assertEquals('completed', $availability['approval']['state']);
        $this->assertEquals('completed', $availability['session_selection']['state']);
    }

    // === Phase support tests ===

    /** @test */
    public function testBuildInitialTasksIncludesPhaseField(): void
    {
        $this->setDataManagerMeta('vad_edition', 100, [
            'requires_questionnaire' => '1',
            'requires_documents' => '1',
        ]);

        $tasks = $this->service->buildInitialTasks(100, 'vad_edition');

        $this->assertArrayHasKey('questionnaire', $tasks);
        $this->assertArrayHasKey('documents', $tasks);
        $this->assertEquals('enrollment', $tasks['questionnaire']['phase']);
        $this->assertEquals('enrollment', $tasks['documents']['phase']);
    }

    /** @test */
    public function testBuildPostCourseTasksReturnsPhaseField(): void
    {
        $this->setDataManagerMeta('vad_edition', 100, [
            'post_requires_evaluation' => '1',
            'post_requires_documents' => '1',
        ]);

        $tasks = $this->service->buildPostCourseTasks(100, 'vad_edition');

        $this->assertArrayHasKey('post_evaluation', $tasks);
        $this->assertArrayHasKey('post_documents', $tasks);
        $this->assertArrayNotHasKey('post_approval', $tasks);
        $this->assertEquals('post_course', $tasks['post_evaluation']['phase']);
        $this->assertEquals('post_course', $tasks['post_documents']['phase']);
        $this->assertEquals('pending', $tasks['post_evaluation']['status']);
        $this->assertEquals('pending', $tasks['post_documents']['status']);
    }

    /** @test */
    public function testPostCourseTasksAvailableByDefault(): void
    {
        $tasks = [
            'post_evaluation' => ['status' => 'pending', 'phase' => 'post_course'],
            'post_documents' => ['status' => 'pending', 'phase' => 'post_course'],
        ];

        $availability = $this->service->getTaskAvailability($tasks, 0);

        $this->assertEquals('available', $availability['post_evaluation']['state']);
        $this->assertEquals('available', $availability['post_documents']['state']);
    }

    /** @test */
    public function testPostApprovalLockedUntilOtherPostTasksDone(): void
    {
        $tasks = [
            'post_evaluation' => ['status' => 'pending', 'phase' => 'post_course'],
            'post_documents' => ['status' => 'pending', 'phase' => 'post_course'],
            'post_approval' => ['status' => 'pending', 'phase' => 'post_course'],
        ];

        $availability = $this->service->getTaskAvailability($tasks, 0);

        $this->assertEquals('locked', $availability['post_approval']['state']);
    }

    /** @test */
    public function testPostApprovalAvailableWhenOtherPostTasksDone(): void
    {
        $tasks = [
            'post_evaluation' => ['status' => 'completed', 'phase' => 'post_course'],
            'post_documents' => ['status' => 'completed', 'phase' => 'post_course'],
            'post_approval' => ['status' => 'pending', 'phase' => 'post_course'],
        ];

        $availability = $this->service->getTaskAvailability($tasks, 0);

        $this->assertEquals('available', $availability['post_approval']['state']);
    }

    /** @test */
    public function testIsEnrollmentPhaseComplete(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed', 'phase' => 'enrollment'],
            'documents' => ['status' => 'completed', 'phase' => 'enrollment'],
            'post_evaluation' => ['status' => 'pending', 'phase' => 'post_course'],
        ];

        $this->assertTrue($this->service->isEnrollmentPhaseComplete($tasks));

        // Now with a pending enrollment task
        $tasks['questionnaire']['status'] = 'pending';
        $this->assertFalse($this->service->isEnrollmentPhaseComplete($tasks));
    }

    /** @test */
    public function testIsPostCoursePhaseComplete(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'pending', 'phase' => 'enrollment'],
            'post_evaluation' => ['status' => 'completed', 'phase' => 'post_course'],
            'post_documents' => ['status' => 'completed', 'phase' => 'post_course'],
        ];

        $this->assertTrue($this->service->isPostCoursePhaseComplete($tasks));

        // Now with a pending post-course task
        $tasks['post_evaluation']['status'] = 'pending';
        $this->assertFalse($this->service->isPostCoursePhaseComplete($tasks));
    }

    /** @test */
    public function testGetTasksForPhase(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed', 'phase' => 'enrollment'],
            'approval' => ['status' => 'pending', 'phase' => 'enrollment'],
            'post_evaluation' => ['status' => 'pending', 'phase' => 'post_course'],
            'post_documents' => ['status' => 'pending', 'phase' => 'post_course'],
        ];

        $enrollmentTasks = $this->service->getTasksForPhase($tasks, 'enrollment');
        $postCourseTasks = $this->service->getTasksForPhase($tasks, 'post_course');

        $this->assertCount(2, $enrollmentTasks);
        $this->assertArrayHasKey('questionnaire', $enrollmentTasks);
        $this->assertArrayHasKey('approval', $enrollmentTasks);

        $this->assertCount(2, $postCourseTasks);
        $this->assertArrayHasKey('post_evaluation', $postCourseTasks);
        $this->assertArrayHasKey('post_documents', $postCourseTasks);
    }

    /** @test */
    public function testHasPostCourseRequirements(): void
    {
        // No post-course requirements
        $this->assertFalse($this->service->hasPostCourseRequirements(999, 'vad_edition'));

        // With post-course requirements
        $this->setDataManagerMeta('vad_edition', 200, [
            'post_requires_evaluation' => '1',
        ]);

        $this->assertTrue($this->service->hasPostCourseRequirements(200, 'vad_edition'));
    }

    // === pendingReason() — dossier hint (waiting on user vs waiting on admin) ===

    /** @test */
    public function testPendingReasonWaitsOnAdminWhenUserTasksDone(): void
    {
        // Wout Claes' real case (#158512): user tasks done, only approval pending.
        $tasks = [
            'questionnaire' => ['status' => 'completed'],
            'documents' => ['status' => 'completed'],
            'approval' => ['status' => 'pending'],
        ];

        $reason = $this->service->pendingReason($tasks);

        $this->assertEquals('admin', $reason['actor']);
        $this->assertStringContainsString('goedkeuring', strtolower($reason['label']));
    }

    /** @test */
    public function testPendingReasonWaitsOnUserWhenUserTaskOpen(): void
    {
        $tasks = [
            'questionnaire' => ['status' => 'completed'],
            'documents' => ['status' => 'pending'],
            'approval' => ['status' => 'pending'],
        ];

        $reason = $this->service->pendingReason($tasks);

        $this->assertEquals('user', $reason['actor']);
        // First open user task is `documents` → label carries its Dutch label.
        $this->assertStringContainsString('Documenten uploaden', $reason['label']);
    }

    /** @test */
    public function testPendingReasonNeutralAdminWhenNoTasks(): void
    {
        $reason = $this->service->pendingReason([]);

        $this->assertEquals('admin', $reason['actor']);
        $this->assertStringContainsString('goedkeuring', strtolower($reason['label']));
    }

    /** @test */
    public function testCompleteTaskAcceptsPostCourseTypes(): void
    {
        $registration = (object) [
            'id' => 1,
            'edition_id' => 100,
            'completion_tasks' => [
                'post_evaluation' => ['status' => 'pending', 'phase' => 'post_course'],
            ],
        ];

        $mockRepo = $this->createMock(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $mockRepo->method('find')->willReturn($registration);

        $updatedTasks = [];
        $mockRepo->method('updateCompletionTasks')->willReturnCallback(
            function (int $id, array $tasks) use (&$updatedTasks) {
                $updatedTasks = $tasks;
                return true;
            },
        );

        $service = new EnrollmentCompletion(
            $mockRepo,
            new EditionRepository(),
            new TrajectoryRepository(),
        );

        $result = $service->completeTask(1, 'post_evaluation');

        $this->assertTrue($result);
        $this->assertEquals('completed', $updatedTasks['post_evaluation']['status']);
    }
}
