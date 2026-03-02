<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Bridges;

use NetdustLTI\ToolProvider\Domain\GradePayload;
use NetdustLTI\ToolProvider\Services\GradePassbackService;
use NetdustLTI\ToolProvider\Services\CourseEnroller;
use NetdustLTI\ToolProvider\Services\CourseGradeSettingsService;

/**
 * Bridge between LearnDash events and LTI grade passback.
 *
 * Listens for LearnDash course completions and quiz completions,
 * and posts grades back to the originating LTI platform when appropriate.
 */
final class LearnDashBridge
{
    public function __construct(
        private readonly GradePassbackService $gradeService,
        private readonly CourseEnroller $enroller,
        private readonly CourseGradeSettingsService $gradeSettings,
    ) {
        add_action('learndash_course_completed', [$this, 'onCourseCompleted'], 10, 1);
        add_action('learndash_quiz_completed', [$this, 'onQuizCompleted'], 10, 2);
    }

    /**
     * Handle LearnDash course completion event.
     *
     * @param array $data Course completion data containing 'user' and 'course' objects
     */
    public function onCourseCompleted(array $data): void
    {
        $userId = $data['user']->ID;
        $courseId = $data['course']->ID;

        if (!$this->gradeSettings->shouldPostGrade($courseId, 'course_complete')) {
            return;
        }

        if (!$this->enroller->hasLtiContext($userId, $courseId)) {
            return;
        }

        $result = $this->gradeService->postGrade(GradePayload::completion($userId, $courseId));

        if (is_wp_error($result)) {
            ntdst_log('lti-grade')->warning('Course completion grade failed', [
                'user_id' => $userId,
                'course_id' => $courseId,
                'error' => $result->get_error_message(),
            ]);
        }
    }

    /**
     * Handle LearnDash quiz completion event.
     *
     * @param array    $data Quiz completion data containing score info and 'course' object
     * @param \WP_User $user The user who completed the quiz
     */
    public function onQuizCompleted(array $data, \WP_User $user): void
    {
        $courseId = $data['course']->ID ?? null;

        if (!$courseId) {
            return;
        }

        if (!$this->gradeSettings->shouldPostGrade($courseId, 'quiz_score')) {
            return;
        }

        if (!$this->enroller->hasLtiContext($user->ID, $courseId)) {
            return;
        }

        $score = $data['score'] ?? 0;
        $maxScore = $data['count'] ?? 100;

        $result = $this->gradeService->postGrade(GradePayload::quizScore($user->ID, $courseId, $score, $maxScore));

        if (is_wp_error($result)) {
            ntdst_log('lti-grade')->warning('Quiz grade failed', [
                'user_id' => $user->ID,
                'course_id' => $courseId,
                'error' => $result->get_error_message(),
            ]);
        }
    }

}
