<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Handlers;

use Stride\Handlers\CompletionTaskHandler;
use Stride\Tests\TestCase;

/**
 * The owner-or-enroller gate (form-identity rule 4, plan 2026-07-14):
 * the participant always acts on their own registration; the `enrolled_by`
 * actor may act on the DELEGABLE completion tasks only (session selection,
 * documents — an allow-list, so future task types are enroller-denied by
 * default). The questionnaire (intake) and the evaluation stay strictly
 * personal, and strangers are always denied.
 */
final class CompletionTaskActorGateTest extends TestCase
{
    private function gate(object $reg, int $userId, ?string $taskType = null): bool
    {
        $handler = (new \ReflectionClass(CompletionTaskHandler::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(CompletionTaskHandler::class, 'actorMayActOn');
        $method->setAccessible(true);

        return $method->invoke($handler, $reg, $userId, $taskType);
    }

    private function reg(int $userId, ?int $enrolledBy = null): object
    {
        return (object) ['id' => 1, 'user_id' => $userId, 'enrolled_by' => $enrolledBy];
    }

    public function test_the_participant_always_acts_on_their_own_registration(): void
    {
        $this->assertTrue($this->gate($this->reg(7), 7, 'session_selection'));
        $this->assertTrue($this->gate($this->reg(7), 7, 'questionnaire'));
        $this->assertTrue($this->gate($this->reg(7), 7, 'documents'));
        $this->assertTrue($this->gate($this->reg(7), 7));
    }

    public function test_the_enroller_acts_on_non_personal_tasks_only(): void
    {
        $reg = $this->reg(7, enrolledBy: 3);

        $this->assertTrue($this->gate($reg, 3, 'session_selection'));
        $this->assertTrue($this->gate($reg, 3, 'documents'));
        $this->assertTrue($this->gate($reg, 3, 'post_documents'));
        $this->assertTrue($this->gate($reg, 3), 'proof download: enroller allowed');

        // The intake questionnaire AND the evaluation are strictly personal
        // (rule 4) — the enroller can neither submit nor tick them for their
        // colleague.
        $this->assertFalse($this->gate($reg, 3, 'questionnaire'));
        $this->assertFalse($this->gate($reg, 3, 'post_evaluation'));

        // Admin-review tasks are not the enroller's either (they are also
        // stride_manage-gated at the endpoint), and the allow-list denies
        // unknown/future task types by default instead of failing open.
        $this->assertFalse($this->gate($reg, 3, 'approval'));
        $this->assertFalse($this->gate($reg, 3, 'post_approval'));
        $this->assertFalse($this->gate($reg, 3, 'some_future_task'));
    }

    public function test_admin_review_tasks_require_stride_manage_at_the_endpoint(): void
    {
        // Completing 'approval' as the last enrollment task AUTO-CONFIRMS the
        // registration (post_approval finalizes it) — the participant must
        // not be able to approve their own enrollment through the public
        // stride_complete_task action. Its card says "afgehandeld door een
        // beheerder"; the admin surfaces call EnrollmentCompletion directly.
        global $current_user_caps, $_test_current_user_id;
        $current_user_caps = ['stride_manage' => false];
        $_test_current_user_id = 7;

        try {
            $handler = (new \ReflectionClass(CompletionTaskHandler::class))->newInstanceWithoutConstructor();

            foreach (['approval', 'post_approval'] as $adminTask) {
                $result = $handler->handleCompleteTask(null, [
                    'registration_id' => 1,
                    'task_type' => $adminTask,
                ]);

                $this->assertInstanceOf(\WP_Error::class, $result, $adminTask);
                $this->assertSame('forbidden', $result->get_error_code(), $adminTask);
            }
        } finally {
            $current_user_caps = null;
        }
    }

    public function test_strangers_are_always_denied(): void
    {
        $reg = $this->reg(7, enrolledBy: 3);

        $this->assertFalse($this->gate($reg, 99, 'session_selection'));
        $this->assertFalse($this->gate($reg, 99, 'documents'));
        $this->assertFalse($this->gate($reg, 99));
        // enrolled_by NULL: nobody but the participant.
        $this->assertFalse($this->gate($this->reg(7), 3, 'session_selection'));
    }
}
