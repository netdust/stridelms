<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Enrollment\EnrollmentCompletionService;
use Stride\Tests\TestCase;

class EnrollmentCompletionServiceTest extends TestCase
{
    private EnrollmentCompletionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EnrollmentCompletionService();
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
}
