<?php

declare(strict_types=1);

namespace stridence\services\frontend\hooks;

use NTDST_Theme;

/**
 * LearnDash integration hooks: focus mode customisation, SCORM detection,
 * permalink slug override.
 */
final class LearnDashHooks
{
    public function bind(NTDST_Theme $theme): void
    {
        $theme
            ->filter('body_class', [$this, 'addScormBodyClass'])
            ->filter('learndash_focus_header_element', [$this, 'focusHeaderBackButton'], 10, 4)
            ->filter('learndash_focus_header_user_dropdown_items', [$this, 'focusHeaderUserMenu'], 10, 3)
            ->filter('option_learndash_settings_permalinks', [$this, 'overrideCoursePermalink'])
            ->on('wp_enqueue_scripts', [$this, 'dequeueTinCannyOutsideLDContext'], 999);
    }

    public function dequeueTinCannyOutsideLDContext(): void
    {
        if (is_singular(['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'sfwd-assignment', 'sfwd-certificates'])) {
            return;
        }
        wp_dequeue_script('tc_runtime');
        wp_dequeue_script('tc_vendors');
        wp_dequeue_script('wp-h5p-xapi');
        wp_dequeue_style('wp-h5p-xapi');
        wp_dequeue_style('datatables-styles');
        wp_dequeue_style('uotc-group-quiz-report');
        wp_dequeue_style('snc-style');
    }

    public function addScormBodyClass(array $classes): array
    {
        if (!is_singular('sfwd-lessons') && !is_singular('sfwd-topic')) {
            return $classes;
        }

        global $post;
        if (!$post) {
            return $classes;
        }

        $hasScorm = has_shortcode($post->post_content, 'vc_snc')
            || str_contains($post->post_content, '[vc_snc');

        if (!$hasScorm) {
            return $classes;
        }

        $classes[] = 'has-scorm-content';

        if (function_exists('learndash_get_course_id')) {
            $course_id = (int) learndash_get_course_id($post->ID);
            if ($course_id) {
                $lessons = learndash_get_course_lessons_list($course_id);
                if (is_array($lessons) && count($lessons) <= 1) {
                    $classes[] = 'single-lesson-course';
                }
            }
        }

        return $classes;
    }

    public function focusHeaderBackButton(string $header_element, array $header, int $course_id, int $user_id): string
    {
        // Only customise when no custom logo is set (header_element is empty)
        if (!empty($header_element)) {
            return $header_element;
        }

        // When this lesson is being consumed through an edition's online
        // session the user is enrolled in, return to the edition rather than
        // the bare course — the lesson is "part of" that edition. Falls back
        // to the course for pure e-learning / non-enrolled learners.
        [$back_url, $back_title] = $this->resolveBackTarget($course_id, $user_id);

        return sprintf(
            '<a href="%s" class="ld-brand-back-link" title="%s">%s<span class="ld-brand-back-text">%s</span></a>',
            esc_url($back_url),
            esc_attr(sprintf(__('Terug naar %s', 'stridence'), $back_title)),
            stridence_icon('chevron-left', 'ld-brand-back-icon'),
            esc_html__('Terug', 'stridence'),
        );
    }

    /**
     * Resolve the "back" target for the current lesson: the enrolled edition
     * the lesson is consumed through, else the course.
     *
     * @return array{0: string, 1: string} [url, title]
     */
    private function resolveBackTarget(int $course_id, int $user_id): array
    {
        $edition_id = $this->enrolledEditionForCurrentLesson($user_id);

        if ($edition_id) {
            return [get_permalink($edition_id), get_the_title($edition_id)];
        }

        return [get_permalink($course_id), get_the_title($course_id)];
    }

    /**
     * The enrolled edition the CURRENT (global) lesson is reached through, or
     * 0 when none. Guards every dependency so a non-lesson context, missing
     * service, or logged-out user resolves to 0 (course fallback).
     */
    private function enrolledEditionForCurrentLesson(int $user_id): int
    {
        if ($user_id <= 0) {
            return 0;
        }

        $lesson = get_post();
        if (!$lesson || !in_array($lesson->post_type, ['sfwd-lessons', 'sfwd-topic'], true)) {
            return 0;
        }

        if (!function_exists('ntdst_get')
            || !class_exists(\Stride\Modules\User\UserDashboardService::class)) {
            return 0;
        }

        return (int) ntdst_get(\Stride\Modules\User\UserDashboardService::class)
            ->findEnrolledEditionForLesson($user_id, (int) $lesson->ID);
    }

    public function focusHeaderUserMenu(array $menu_items, int $course_id, int $user_id): array
    {
        $dashboard_url = home_url('/mijn-account/');

        return [
            'dashboard' => [
                'url'   => $dashboard_url,
                'label' => __('Mijn dashboard', 'stridence'),
            ],
            'profile' => [
                'url'   => $dashboard_url . '?tab=profiel',
                'label' => __('Profiel', 'stridence'),
            ],
            'course-home' => [
                'url'   => get_permalink($course_id),
                'label' => __('Cursus overzicht', 'stridence'),
            ],
            'logout' => [
                'url'   => wp_logout_url(get_permalink($course_id)),
                'label' => __('Uitloggen', 'stridence'),
            ],
        ];
    }

    /**
     * Override LearnDash course permalink slug to 'opleidingen'.
     * Filters the option value so the database stays untouched.
     */
    public function overrideCoursePermalink(mixed $value): mixed
    {
        if (is_array($value)) {
            $value['courses'] = 'opleidingen';
        }
        return $value;
    }
}
