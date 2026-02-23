<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Infrastructure\AbstractService;
use Stride\Modules\Trajectory\TrajectoryService;

/**
 * URL routing for enrollment forms.
 *
 * Handles clean URLs for trajectory and course enrollment:
 * - /trajecten/{slug}/inschrijving/ -> Trajectory enrollment
 * - /cursussen/{slug}/inschrijving/ -> Course/edition enrollment (future)
 */
final class EnrollmentRouterService extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Router',
            'description' => 'Handles clean URL routing for enrollment forms',
            'priority' => 15,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'enrollment_router';
    }

    protected function init(): void
    {
        $this->registerRewriteRules();
        $this->registerRoutes();
    }

    /**
     * Register rewrite rules to prevent 404.
     *
     * WordPress needs to know these URLs are valid before the router can handle them.
     */
    private function registerRewriteRules(): void
    {
        add_action('init', function (): void {
            // Trajectory enrollment: /trajecten/{slug}/inschrijving/
            add_rewrite_rule(
                '^trajecten/([^/]+)/inschrijving/?$',
                'index.php?ntdst_route=1',
                'top'
            );

            // Course/edition enrollment: /cursussen/{slug}/inschrijving/
            add_rewrite_rule(
                '^cursussen/([^/]+)/inschrijving/?$',
                'index.php?ntdst_route=1',
                'top'
            );
        });

        // Register query var
        add_filter('query_vars', fn(array $vars): array => array_merge($vars, ['ntdst_route']));
    }

    /**
     * Register routes with the NTDST router.
     */
    private function registerRoutes(): void
    {
        // Trajectory enrollment route
        ntdst_router()->get('trajecten/:slug/inschrijving', function (array $params) {
            return $this->handleTrajectoryEnrollment($params['slug']);
        });

        // Course/edition enrollment route (future-proofing)
        ntdst_router()->get('cursussen/:slug/inschrijving', function (array $params) {
            return $this->handleCourseEnrollment($params['slug']);
        });
    }

    /**
     * Handle trajectory enrollment route.
     *
     * @return \NTDST_Response|string|null
     */
    private function handleTrajectoryEnrollment(string $slug)
    {
        // Look up trajectory by slug
        $trajectory = get_page_by_path($slug, OBJECT, 'vad_trajectory');

        if (!$trajectory) {
            return $this->trigger404();
        }

        // Check login status
        if (!is_user_logged_in()) {
            $returnUrl = home_url('/trajecten/' . $slug . '/inschrijving/');
            wp_redirect(wp_login_url($returnUrl));
            exit;
        }

        // Check if enrollment is open
        $trajectoryService = ntdst_get(TrajectoryService::class);
        $enrollmentOpen = $trajectoryService->isEnrollmentOpen($trajectory->ID);

        return ntdst_response()
            ->with('item', $trajectory)
            ->with('type', 'trajectory')
            ->with('enrollment_open', $enrollmentOpen)
            ->template('enrollment/form');
    }

    /**
     * Handle course/edition enrollment route.
     *
     * @return \NTDST_Response|string|null
     */
    private function handleCourseEnrollment(string $slug)
    {
        // Look up edition by slug
        $edition = get_page_by_path($slug, OBJECT, 'vad_edition');

        if (!$edition) {
            return $this->trigger404();
        }

        // Check login status
        if (!is_user_logged_in()) {
            $returnUrl = home_url('/cursussen/' . $slug . '/inschrijving/');
            wp_redirect(wp_login_url($returnUrl));
            exit;
        }

        return ntdst_response()
            ->with('item', $edition)
            ->with('type', 'edition')
            ->with('enrollment_open', true) // TODO: Check edition status
            ->template('enrollment/form');
    }

    /**
     * Trigger a 404 response.
     *
     * Sets WordPress 404 status and returns the 404 template.
     */
    private function trigger404(): string
    {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();

        return get_404_template();
    }
}
