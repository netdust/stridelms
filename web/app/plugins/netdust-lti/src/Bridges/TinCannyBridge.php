<?php
declare(strict_types=1);

namespace NetdustLTI\Bridges;

use NetdustLTI\Services\GradePassbackService;
use NetdustLTI\Services\CourseEnroller;

/**
 * Bridge between TinCanny xAPI events and LTI grade passback.
 *
 * Listens for TinCanny module result events and posts grades back
 * to the originating LTI platform when appropriate.
 */
final class TinCannyBridge
{
    public function __construct(
        private readonly GradePassbackService $gradeService,
        private readonly CourseEnroller $enroller,
    ) {
        add_action('tincanny_module_result_processed', [$this, 'onModuleResult'], 10, 3);
    }

    /**
     * Handle TinCanny module result event.
     *
     * This action is fired by TinCanny when an xAPI/SCORM module
     * result is processed (completion or score).
     *
     * @param int   $moduleId The TinCanny module ID
     * @param int   $userId   WordPress user ID
     * @param float $result   Result percentage (0-100)
     */
    public function onModuleResult(int $moduleId, int $userId, float $result): void
    {
        // Get course from TinCanny's last known course
        $courseId = (int) get_user_meta($userId, 'tincan_last_known_ld_course', true);

        if (!$courseId) {
            return;
        }

        if (!$this->shouldPostGrade($courseId, 'tincanny_complete')) {
            return;
        }

        if (!$this->enroller->hasLtiContext($userId, $courseId)) {
            return;
        }

        $gradeResult = $this->gradeService->postTinCannyScore($userId, $courseId, $result);

        if (is_wp_error($gradeResult)) {
            ntdst_log('lti-grade')->warning('TinCanny grade failed', [
                'user_id' => $userId,
                'course_id' => $courseId,
                'module_id' => $moduleId,
                'error' => $gradeResult->get_error_message(),
            ]);
        }
    }

    /**
     * Check if grade should be posted for this course and trigger type.
     *
     * @param int    $courseId LearnDash course ID
     * @param string $trigger  The trigger type (e.g., 'tincanny_complete')
     * @return bool Whether to post grade
     */
    private function shouldPostGrade(int $courseId, string $trigger): bool
    {
        $settings = get_post_meta($courseId, '_netdust_lti_grade_settings', true) ?: [];
        return !empty($settings[$trigger]);
    }
}
