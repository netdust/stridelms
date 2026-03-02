<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Bridges;

use NetdustLTI\ToolProvider\Domain\GradePayload;
use NetdustLTI\ToolProvider\Services\GradePassbackService;
use NetdustLTI\ToolProvider\Services\CourseEnroller;
use NetdustLTI\ToolProvider\Services\CourseGradeSettingsService;

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
        private readonly CourseGradeSettingsService $gradeSettings,
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

        if (!$this->gradeSettings->shouldPostGrade($courseId, 'tincanny_complete')) {
            return;
        }

        if (!$this->enroller->hasLtiContext($userId, $courseId)) {
            return;
        }

        $gradeResult = $this->gradeService->postGrade(GradePayload::tincannyScore($userId, $courseId, $result));

        if (is_wp_error($gradeResult)) {
            ntdst_log('lti-grade')->warning('TinCanny grade failed', [
                'user_id' => $userId,
                'course_id' => $courseId,
                'module_id' => $moduleId,
                'error' => $gradeResult->get_error_message(),
            ]);
        }
    }

}
