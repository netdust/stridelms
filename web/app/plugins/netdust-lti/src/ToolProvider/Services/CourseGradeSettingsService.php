<?php
declare(strict_types=1);

namespace NetdustLTI\ToolProvider\Services;

/**
 * Centralized service for LTI grade settings on LearnDash courses.
 *
 * Provides a single point of access for grade passback settings,
 * used by CourseSettingsMetabox (admin), LearnDashBridge, and TinCannyBridge.
 */
final class CourseGradeSettingsService
{
    private const META_KEY = '_netdust_lti_grade_settings';

    /**
     * Get grade settings for a course.
     *
     * @param int $courseId LearnDash course ID
     * @return array Settings array with trigger keys (course_complete, quiz_score, tincanny_complete)
     */
    public function getSettings(int $courseId): array
    {
        $settings = get_post_meta($courseId, self::META_KEY, true);
        return is_array($settings) ? $settings : [];
    }

    /**
     * Save grade settings for a course.
     *
     * @param int $courseId LearnDash course ID
     * @param array $settings Settings array
     * @return bool True on success
     */
    public function saveSettings(int $courseId, array $settings): bool
    {
        return (bool) update_post_meta($courseId, self::META_KEY, $settings);
    }

    /**
     * Check if a specific grade trigger is enabled for a course.
     *
     * @param int $courseId LearnDash course ID
     * @param string $trigger Trigger type: 'course_complete', 'quiz_score', or 'tincanny_complete'
     * @return bool Whether the trigger is enabled
     */
    public function shouldPostGrade(int $courseId, string $trigger): bool
    {
        $settings = $this->getSettings($courseId);
        return !empty($settings[$trigger]);
    }

    /**
     * Enable a grade trigger for a course.
     *
     * @param int $courseId LearnDash course ID
     * @param string $trigger Trigger type
     * @return bool True on success
     */
    public function enableTrigger(int $courseId, string $trigger): bool
    {
        $settings = $this->getSettings($courseId);
        $settings[$trigger] = 1;
        return $this->saveSettings($courseId, $settings);
    }

    /**
     * Disable a grade trigger for a course.
     *
     * @param int $courseId LearnDash course ID
     * @param string $trigger Trigger type
     * @return bool True on success
     */
    public function disableTrigger(int $courseId, string $trigger): bool
    {
        $settings = $this->getSettings($courseId);
        unset($settings[$trigger]);
        return $this->saveSettings($courseId, $settings);
    }

    /**
     * Get the meta key used for settings storage.
     * Useful for metabox rendering.
     *
     * @return string Meta key
     */
    public static function getMetaKey(): string
    {
        return self::META_KEY;
    }
}
