<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Enrollment;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Tests\TestCase;

/**
 * Task 3.1 — getTaskAvailability() surfaces gate_deadline / post_gate_deadline
 * as a reason + overdue flag. Past deadline is FLAG-ONLY (D3): the task stays
 * 'available', it never locks or cancels.
 */
class GateDeadlineAvailabilityTest extends TestCase
{
    private EnrollmentCompletion $service;

    protected function setUp(): void
    {
        parent::setUp();
        $mockRepo = $this->createMock(\Stride\Modules\Enrollment\RegistrationRepository::class);
        $this->service = new EnrollmentCompletion(
            $mockRepo,
            new EditionRepository(),
            new TrajectoryRepository(),
        );
    }

    /** @test */
    public function testQuestionnaireWithFutureGateDeadlineIsAvailableWithReasonAndNotOverdue(): void
    {
        $future = date('Y-m-d', strtotime('+10 days'));
        $this->setDataManagerMeta('vad_edition', 100, [
            'gate_deadline' => $future,
        ]);

        $tasks = ['questionnaire' => ['status' => 'pending']];
        $availability = $this->service->getTaskAvailability($tasks, 100);

        $this->assertEquals('available', $availability['questionnaire']['state']);
        $this->assertStringContainsString('Voltooien voor', $availability['questionnaire']['reason']);
        $this->assertFalse($availability['questionnaire']['overdue']);
    }

    /** @test */
    public function testDocumentsWithPastGateDeadlineStaysAvailableButFlaggedOverdue(): void
    {
        $past = date('Y-m-d', strtotime('-5 days'));
        $this->setDataManagerMeta('vad_edition', 101, [
            'gate_deadline' => $past,
        ]);

        $tasks = ['documents' => ['status' => 'pending']];
        $availability = $this->service->getTaskAvailability($tasks, 101);

        // D3 regression guard: past deadline is flag-only — must NOT lock or cancel.
        $this->assertEquals('available', $availability['documents']['state']);
        $this->assertNotEquals('locked', $availability['documents']['state']);
        $this->assertNotEquals('cancelled', $availability['documents']['state']);
        $this->assertEquals('De deadline is verstreken.', $availability['documents']['reason']);
        $this->assertTrue($availability['documents']['overdue']);
    }

    /** @test */
    public function testQuestionnaireWithNoGateDeadlineIsAvailableWithEmptyReasonNotOverdue(): void
    {
        // No gate_deadline meta set for this edition id.
        $tasks = ['questionnaire' => ['status' => 'pending']];
        $availability = $this->service->getTaskAvailability($tasks, 999);

        $this->assertEquals('available', $availability['questionnaire']['state']);
        $this->assertEquals('', $availability['questionnaire']['reason']);
        $this->assertFalse($availability['questionnaire']['overdue']);
    }

    /** @test */
    public function testPostEvaluationAndPostDocumentsUsePostGateDeadlineIdentically(): void
    {
        $past = date('Y-m-d', strtotime('-2 days'));
        $this->setDataManagerMeta('vad_edition', 102, [
            'post_gate_deadline' => $past,
        ]);

        $tasks = [
            'post_evaluation' => ['status' => 'pending', 'phase' => 'post_course'],
            'post_documents' => ['status' => 'pending', 'phase' => 'post_course'],
        ];
        $availability = $this->service->getTaskAvailability($tasks, 102);

        foreach (['post_evaluation', 'post_documents'] as $type) {
            $this->assertEquals('available', $availability[$type]['state']);
            $this->assertEquals('De deadline is verstreken.', $availability[$type]['reason']);
            $this->assertTrue($availability[$type]['overdue']);
        }
    }

    /** @test */
    public function testPostEvaluationWithFuturePostGateDeadlineIsAvailableNotOverdue(): void
    {
        $future = date('Y-m-d', strtotime('+7 days'));
        $this->setDataManagerMeta('vad_edition', 103, [
            'post_gate_deadline' => $future,
        ]);

        $tasks = ['post_evaluation' => ['status' => 'pending', 'phase' => 'post_course']];
        $availability = $this->service->getTaskAvailability($tasks, 103);

        $this->assertEquals('available', $availability['post_evaluation']['state']);
        $this->assertStringContainsString('Voltooien voor', $availability['post_evaluation']['reason']);
        $this->assertFalse($availability['post_evaluation']['overdue']);
    }

    /** @test */
    public function testSessionSelectionPastDeadlineBehaviorUnchanged(): void
    {
        // Regression guard: session_selection's OWN past-deadline rule (locks) must
        // stay exactly as it was before this task — gate_deadline must not leak in
        // and must not change this unrelated task's behavior.
        $past = date('Y-m-d', strtotime('-3 days'));
        $this->setDataManagerMeta('vad_edition', 104, [
            'selection_open' => '1',
            'selection_deadline' => $past,
        ]);

        $tasks = [
            'approval' => ['status' => 'completed'],
            'session_selection' => ['status' => 'pending'],
        ];
        $availability = $this->service->getTaskAvailability($tasks, 104);

        $this->assertEquals('locked', $availability['session_selection']['state']);
        $this->assertStringContainsString(
            'verstreken',
            strtolower($availability['session_selection']['reason']),
        );
    }
}
