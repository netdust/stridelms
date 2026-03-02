<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Domain;

/**
 * Immutable value object for LTI grade submissions.
 */
final readonly class GradePayload
{
    public function __construct(
        public int $userId,
        public int $courseId,
        public float $score,
        public float $maxScore,
        public string $activityProgress,
        public string $gradingProgress,
        public ?string $comment = null,
    ) {}

    public static function completion(int $userId, int $courseId): self
    {
        return new self(
            userId: $userId,
            courseId: $courseId,
            score: 1.0,
            maxScore: 1.0,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
        );
    }

    public static function quizScore(int $userId, int $courseId, float $score, float $maxScore): self
    {
        return new self(
            userId: $userId,
            courseId: $courseId,
            score: $score,
            maxScore: $maxScore,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
        );
    }

    public static function tincannyScore(int $userId, int $courseId, float $percentage): self
    {
        return new self(
            userId: $userId,
            courseId: $courseId,
            score: $percentage,
            maxScore: 100.0,
            activityProgress: 'Completed',
            gradingProgress: 'FullyGraded',
        );
    }
}
