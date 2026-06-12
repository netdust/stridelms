<?php

declare(strict_types=1);

namespace Stride\Modules\Edition;

/**
 * Routes /edities/{slug}/ to a vad_edition via WP's native CPT rewrite.
 *
 * The `edities` slug is the canonical transactional surface for editions.
 * Pure-LD courses (online, no edition) live at /opleidingen/<slug>/ and
 * are owned by LearnDash; Stride decorates that page via the
 * single-sfwd-courses theme template. See tasks/url-structure-rework.md.
 *
 * Behaviour when /edities/<slug>/ matches a course slug (not an edition):
 *  - course has 1 active edition → 302 to that edition
 *  - course has 2+ active editions → 302 back to /opleidingen/<course>/ so
 *    the user can pick from the editions list there
 *  - course has 0 active editions → 302 to /opleidingen/<course>/ (info page)
 */
final class EditionRouter
{
    public function __construct(
        private readonly EditionRepository $editions,
    ) {}

    public function register(): void
    {
        add_action('parse_request', [$this, 'maybeRedirectCourseSlug']);
    }

    /**
     * If the request matches /edities/<slug>/ and the slug doesn't belong to
     * an edition but DOES belong to a course, redirect appropriately. Never
     * rewrites query vars (pure-LD courses are no longer rendered at /edities/).
     */
    public function maybeRedirectCourseSlug(\WP $wp): void
    {
        $path = trim((string) ($wp->request ?? ''), '/');
        if ($path === '' || !str_starts_with($path, 'edities/')) {
            return;
        }

        $slug = trim(substr($path, strlen('edities/')), '/');
        if ($slug === '' || str_contains($slug, '/')) {
            // Sub-paths like /edities/<slug>/inschrijving/ are owned by EnrollmentRouter
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

        $visibleEditions = $this->editions->findActiveIdsByCourse((int) $course->ID);

        // 1 active edition → straight to it
        if (count($visibleEditions) === 1) {
            wp_safe_redirect(get_permalink($visibleEditions[0]), 302);
            exit;
        }

        // Anything else (0 or many editions) → /opleidingen/<course>/, which
        // owns both info display and edition picker for klassikaal courses.
        wp_safe_redirect(get_permalink($course->ID), 302);
        exit;
    }
}
