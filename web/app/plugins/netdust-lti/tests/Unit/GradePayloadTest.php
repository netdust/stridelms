<?php
declare(strict_types=1);

namespace NetdustLTI\Tests\Unit;

use NetdustLTI\ToolProvider\Domain\GradePayload;
use PHPUnit\Framework\TestCase;

class GradePayloadTest extends TestCase
{
    public function testCompletionFactory(): void
    {
        $payload = GradePayload::completion(42, 100);

        $this->assertSame(42, $payload->userId);
        $this->assertSame(100, $payload->courseId);
        $this->assertSame(1.0, $payload->score);
        $this->assertSame(1.0, $payload->maxScore);
        $this->assertSame('Completed', $payload->activityProgress);
        $this->assertSame('FullyGraded', $payload->gradingProgress);
        $this->assertNull($payload->comment);
    }

    public function testQuizScoreFactory(): void
    {
        $payload = GradePayload::quizScore(42, 100, 8, 10);

        $this->assertSame(42, $payload->userId);
        $this->assertSame(100, $payload->courseId);
        $this->assertSame(8.0, $payload->score);
        $this->assertSame(10.0, $payload->maxScore);
        $this->assertSame('Completed', $payload->activityProgress);
        $this->assertSame('FullyGraded', $payload->gradingProgress);
    }

    public function testTincannyScoreFactory(): void
    {
        $payload = GradePayload::tincannyScore(42, 100, 85.5);

        $this->assertSame(42, $payload->userId);
        $this->assertSame(100, $payload->courseId);
        $this->assertSame(85.5, $payload->score);
        $this->assertSame(100.0, $payload->maxScore);
        $this->assertSame('Completed', $payload->activityProgress);
        $this->assertSame('FullyGraded', $payload->gradingProgress);
    }

    public function testCustomComment(): void
    {
        $payload = new GradePayload(
            userId: 42,
            courseId: 100,
            score: 1,
            maxScore: 1,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
            comment: 'Excellent work',
        );

        $this->assertSame('Excellent work', $payload->comment);
    }
}
