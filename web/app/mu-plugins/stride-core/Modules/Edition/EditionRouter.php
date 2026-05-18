<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

/**
 * Routes /vormingen/{slug}/ to either a vad_edition (native CPT rewrite)
 * or, when no edition matches, a pure-LD sfwd-courses post.
 *
 * The `vormingen` slug is the canonical transactional surface. For an
 * online course without any scheduled edition (pure LD), the course IS
 * its own enrollable instance — visitors reach the enrollment surface via
 * /vormingen/<course-slug>/ instead of an edition slug.
 *
 * Edition slugs resolve natively (no action needed — we let WP handle
 * it). When `parse_request` sets the query for a vormingen URL that
 * doesn't match an edition, we rewrite the query vars to load the
 * course instead. This keeps WP's 404 handling out of the way.
 */
final class EditionRouter
{
    public function register(): void
    {
        add_action('parse_request', [$this, 'maybeRouteCourse']);
    }

    /**
     * If the request matches /vormingen/<slug>/ and the slug doesn't belong
     * to an edition but DOES belong to a course, rewrite the query vars
     * so WP loads the course as the singular post.
     *
     * @param \WP $wp
     */
    public function maybeRouteCourse(\WP $wp): void
    {
        $path = trim((string) ($wp->request ?? ''), '/');
        if ($path === '' || !str_starts_with($path, 'vormingen/')) {
            return;
        }

        $slug = trim(substr($path, strlen('vormingen/')), '/');
        if ($slug === '' || str_contains($slug, '/')) {
            // Sub-paths like /vormingen/<slug>/inschrijving/ are owned by EnrollmentRouter
            return;
        }

        // Edition slug → let WP's native CPT routing handle it
        if (get_page_by_path($slug, OBJECT, 'vad_edition')) {
            return;
        }

        $course = get_page_by_path($slug, OBJECT, 'sfwd-courses');
        if (!$course) {
            return; // unknown slug → let WP 404
        }

        // Resolve based on how many publicly-listable editions the course has:
        //  - 0 editions → render the course as its own enrollable instance (pure-LD)
        //  - 1 edition  → 302 straight to that edition
        //  - 2+         → 302 back to /opleidingen/<course>/ so the user can pick
        $visibleEditions = $this->findVisibleEditionIds($course->ID);

        if (count($visibleEditions) === 1) {
            wp_safe_redirect(get_permalink($visibleEditions[0]), 302);
            exit;
        }

        if (count($visibleEditions) > 1) {
            wp_safe_redirect(get_permalink($course->ID), 302);
            exit;
        }

        // Pure-LD course: rewrite the query to load it as singular and render
        // the edition-shaped enrollment template.
        $wp->query_vars = [
            'sfwd-courses' => $slug,
            'name'         => $slug,
            'post_type'    => 'sfwd-courses',
        ];
        add_filter('template_include', [$this, 'forceEditionTemplate'], 1000);
    }

    /**
     * Edition IDs for this course that are visible on the public discovery
     * surface. Same set the catalog uses: only active statuses (announcement,
     * open, full, in_progress).
     *
     * Past terminal states (cancelled, completed, archived) and drafts are
     * excluded — sending visitors there is misleading.
     *
     * @return list<int>
     */
    private function findVisibleEditionIds(int $courseId): array
    {
        $ids = get_posts([
            'post_type'      => 'vad_edition',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_ntdst_course_id',
                    'value' => $courseId,
                ],
                [
                    'key'     => '_ntdst_status',
                    'value'   => ['announcement', 'open', 'full', 'in_progress'],
                    'compare' => 'IN',
                ],
            ],
        ]);

        return array_map('intval', $ids);
    }

    public function forceEditionTemplate(string $template): string
    {
        if (!is_singular('sfwd-courses')) {
            return $template;
        }
        $path = trim((string) ($_SERVER['REQUEST_URI'] ?? ''), '/');
        if (!str_starts_with($path, 'vormingen/')) {
            return $template;
        }
        $candidate = get_stylesheet_directory() . '/templates/course/single-course-enrollable.php';
        return file_exists($candidate) ? $candidate : $template;
    }
}
