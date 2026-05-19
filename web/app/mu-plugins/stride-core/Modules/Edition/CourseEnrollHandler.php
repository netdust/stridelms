<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

use Stride\Integrations\LearnDash\LearnDashHelper;

/**
 * Handles ?enroll=1 on /opleidingen/<course-slug>/ for pure-LD online courses.
 *
 * The self-enroll CTA on single-sfwd-courses.php targets the same URL with
 * ?enroll=1. This handler intercepts before LD renders the page:
 *
 *   - guest          → login URL with redirect_to back to ?enroll=1
 *   - logged in
 *     - open mode    → grant LD access (idempotent) + redirect to first lesson
 *     - free mode    → ld_update_course_access() + redirect to first lesson
 *     - other modes  → strip the query arg, let LD render normally
 *
 * Only fires when:
 *   - we're on a singular sfwd-courses page
 *   - the course has 0 active editions (edition surface always wins otherwise)
 *
 * See tasks/url-structure-rework.md.
 */
final class CourseEnrollHandler implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name'        => 'Course Enroll Handler',
            'description' => 'Handles ?enroll=1 on /opleidingen/<slug>/ for pure-LD self-enroll',
            'priority'    => 10,
        ];
    }

    public function __construct(
        private readonly EditionRepository $editions,
    ) {
        add_action('template_redirect', [$this, 'maybeEnroll']);
    }

    public function maybeEnroll(): void
    {
        if (empty($_GET['enroll'])) {
            return;
        }

        if (!is_singular('sfwd-courses')) {
            return;
        }

        $course = get_queried_object();
        if (!$course instanceof \WP_Post || $course->post_type !== 'sfwd-courses') {
            return;
        }

        // Edition surface always wins. If the course has any active edition,
        // ignore ?enroll=1 — the user should enroll via the edition.
        if (!empty($this->editions->findActiveIdsByCourse($course->ID))) {
            wp_safe_redirect(get_permalink($course->ID), 302);
            exit;
        }

        if (!is_user_logged_in()) {
            $returnTo = add_query_arg('enroll', '1', get_permalink($course->ID));
            wp_safe_redirect(wp_login_url($returnTo), 302);
            exit;
        }

        $userId = get_current_user_id();
        $mode   = LearnDashHelper::getAccessMode($course->ID);

        // Free courses need an explicit grant; open mode is access-on-demand.
        if ($mode === LearnDashHelper::MODE_FREE && function_exists('ld_update_course_access')) {
            ld_update_course_access($userId, $course->ID);
        }

        wp_safe_redirect(LearnDashHelper::getFirstLessonUrl($course->ID), 302);
        exit;
    }
}
