<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Handlers;

use Stride\Handlers\CompletionTaskHandler;
use Stride\Tests\TestCase;

/**
 * The owner-or-enroller gate (form-identity rule 4, plan 2026-07-14):
 * the participant always acts on their own registration; the `enrolled_by`
 * actor may act on the NON-personal completion tasks (session selection,
 * documents) for the colleague they enrolled — but the questionnaire
 * (intake) task stays strictly personal, and strangers are always denied.
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

        // The intake questionnaire is strictly personal (rule 4) — the
        // enroller can neither submit nor tick it for their colleague.
        $this->assertFalse($this->gate($reg, 3, 'questionnaire'));
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
