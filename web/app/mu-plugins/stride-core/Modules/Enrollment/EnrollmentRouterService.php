<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use NTDST_Response;
use Stride\Contracts\EditionQueryInterface;
use Stride\Domain\OfferingStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Trajectory\TrajectoryService;

/**
 * URL routing for enrollment forms.
 *
 * Handles clean URLs for trajectory and course enrollment:
 * - /trajecten/{slug}/inschrijving/ -> Trajectory enrollment
 * - /cursussen/{slug}/inschrijving/ -> Course/edition enrollment (future)
 *
 * Uses WordPress rewrite rules to ensure these URLs are handled
 * before WordPress tries to match them as CPT attachments.
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
        // Register rewrite rules and query vars
        add_action('init', [$this, 'addRewriteRules'], 20);
        add_filter('query_vars', [$this, 'addQueryVars']);

        // Handle the routing via template_redirect (before template_include)
        add_action('template_redirect', [$this, 'handleEnrollmentRoutes'], 5);
    }

    /**
     * Add custom rewrite rules for enrollment URLs.
     * These must run BEFORE the CPT rewrite rules to take precedence.
     */
    public function addRewriteRules(): void
    {
        // Trajectory enrollment: /trajecten/{slug}/inschrijving/
        add_rewrite_rule(
            '^trajecten/([^/]+)/inschrijving/?$',
            'index.php?stride_enrollment=trajectory&stride_enrollment_slug=$matches[1]',
            'top'
        );

        // Edition enrollment: /vormingen/{slug}/inschrijving/
        add_rewrite_rule(
            '^vormingen/([^/]+)/inschrijving/?$',
            'index.php?stride_enrollment=edition&stride_enrollment_slug=$matches[1]',
            'top'
        );

        // Edition completion: /vormingen/{slug}/voltooien/
        add_rewrite_rule(
            '^vormingen/([^/]+)/voltooien/?$',
            'index.php?stride_completion=edition&stride_enrollment_slug=$matches[1]',
            'top'
        );

        // Trajectory completion: /trajecten/{slug}/voltooien/
        add_rewrite_rule(
            '^trajecten/([^/]+)/voltooien/?$',
            'index.php?stride_completion=trajectory&stride_enrollment_slug=$matches[1]',
            'top'
        );
    }

    /**
     * Register custom query variables.
     */
    public function addQueryVars(array $vars): array
    {
        $vars[] = 'stride_enrollment';
        $vars[] = 'stride_enrollment_slug';
        $vars[] = 'stride_completion';
        return $vars;
    }

    /**
     * Handle enrollment routes via template_redirect.
     */
    public function handleEnrollmentRoutes(): void
    {
        // Handle completion routes first
        $completionType = get_query_var('stride_completion');
        $completionSlug = get_query_var('stride_enrollment_slug');

        if ($completionType && $completionSlug) {
            $this->handleCompletionRoute($completionType, $completionSlug);
            exit;
        }

        $enrollmentType = get_query_var('stride_enrollment');
        $slug = get_query_var('stride_enrollment_slug');

        if (!$enrollmentType || !$slug) {
            return;
        }

        if ($enrollmentType === 'trajectory') {
            $this->handleTrajectoryEnrollment($slug);
            exit;
        }

        if ($enrollmentType === 'edition') {
            $this->handleCourseEnrollment($slug);
            exit;
        }
    }

    /**
     * Handle trajectory enrollment route.
     */
    private function handleTrajectoryEnrollment(string $slug): void
    {
        // Look up trajectory by slug
        $trajectory = get_page_by_path($slug, OBJECT, 'vad_trajectory');

        if (!$trajectory) {
            $this->trigger404();
            return;
        }

        // Check login status
        if (!is_user_logged_in()) {
            $returnUrl = home_url('/trajecten/' . $slug . '/inschrijving/');
            wp_safe_redirect(wp_login_url($returnUrl));
            exit;
        }

        // Compute enrollment mode
        $trajectoryService = ntdst_get(TrajectoryService::class);
        $mode = $this->computeEnrollmentMode(
            $trajectoryService->getTrajectory($trajectory->ID)['status_enum'] ?? OfferingStatus::Draft,
            $trajectoryService->requiresApproval($trajectory->ID),
            $trajectoryService->isEnrollmentOpen($trajectory->ID),
        );

        // Block terminal states
        if ($mode === 'closed') {
            ntdst_response()
                ->with('item', $trajectory)
                ->with('type', 'trajectory')
                ->with('enrollment_open', false)
                ->with('enrollment_mode', $mode)
                ->render('enrollment/form');
            return;
        }

        // Render the enrollment form
        ntdst_response()
            ->with('item', $trajectory)
            ->with('type', 'trajectory')
            ->with('enrollment_open', true)
            ->with('enrollment_mode', $mode)
            ->render('enrollment/form');
    }

    /**
     * Handle course/edition enrollment route.
     */
    private function handleCourseEnrollment(string $slug): void
    {
        // Look up edition by slug (editions use numeric IDs as slugs)
        $edition = get_page_by_path($slug, OBJECT, 'vad_edition');

        // Also try by ID if slug lookup fails (editions often use ID-based URLs)
        if (!$edition && is_numeric($slug)) {
            $edition = get_post((int) $slug);
            if ($edition && $edition->post_type !== 'vad_edition') {
                $edition = null;
            }
        }

        if (!$edition) {
            $this->trigger404();
            return;
        }

        // Check login status
        if (!is_user_logged_in()) {
            $returnUrl = home_url('/vormingen/' . $slug . '/inschrijving/');
            wp_safe_redirect(wp_login_url($returnUrl));
            exit;
        }

        // Compute enrollment mode
        $editionService = ntdst_get(EditionService::class);
        $status = $editionService->getStatus($edition->ID);
        $mode = $this->computeEnrollmentMode(
            $status,
            $editionService->requiresApproval($edition->ID),
            $status->allowsEnrollment() && $editionService->hasAvailableSpots($edition->ID),
        );

        // Block terminal states
        if ($mode === 'closed') {
            ntdst_response()
                ->with('item', $edition)
                ->with('type', 'edition')
                ->with('enrollment_open', false)
                ->with('enrollment_mode', $mode)
                ->render('enrollment/form');
            return;
        }

        // Render the enrollment form
        ntdst_response()
            ->with('item', $edition)
            ->with('type', 'edition')
            ->with('enrollment_open', true)
            ->with('enrollment_mode', $mode)
            ->render('enrollment/form');
    }

    /**
     * Compute enrollment mode based on offering status and settings.
     *
     * @return string 'interest' | 'pending_approval' | 'enrollment' | 'closed'
     */
    private function computeEnrollmentMode(OfferingStatus $status, bool $requiresApproval, bool $enrollmentOpen): string
    {
        if ($status->allowsInterest()) {
            return 'interest';
        }

        if ($enrollmentOpen) {
            return $requiresApproval ? 'pending_approval' : 'enrollment';
        }

        return 'closed';
    }

    /**
     * Handle completion page route.
     */
    private function handleCompletionRoute(string $type, string $slug): void
    {
        if (!is_user_logged_in()) {
            $base = $type === 'trajectory' ? 'trajecten' : 'vormingen';
            wp_safe_redirect(wp_login_url(home_url("/{$base}/{$slug}/voltooien/")));
            exit;
        }

        $postType = $type === 'trajectory' ? 'vad_trajectory' : 'vad_edition';
        $post = get_page_by_path($slug, OBJECT, $postType);

        if (!$post && $postType === 'vad_edition' && is_numeric($slug)) {
            $post = get_post((int) $slug);
            if ($post && $post->post_type !== 'vad_edition') {
                $post = null;
            }
        }

        if (!$post) {
            $this->trigger404();
            return;
        }

        // Find user's pending registration
        $userId = get_current_user_id();
        $repo = ntdst_get(RegistrationRepository::class);

        if ($postType === 'vad_edition') {
            $registration = $repo->findByUserAndEdition($userId, $post->ID);
        } else {
            $regs = $repo->findByUser($userId);
            $registration = null;
            foreach ($regs as $r) {
                if ((int) ($r->trajectory_id ?? 0) === $post->ID && $r->status === 'pending') {
                    $registration = $r;
                    break;
                }
            }
        }

        if (!$registration || $registration->status !== 'pending' || empty($registration->completion_tasks)) {
            // No pending registration with tasks — redirect to the detail page
            wp_safe_redirect(get_permalink($post->ID));
            exit;
        }

        $completionService = ntdst_get(EnrollmentCompletionService::class);
        $taskSummary = $completionService->getTaskSummary((int) $registration->id);

        ntdst_response()
            ->with('post', $post)
            ->with('type', $type)
            ->with('registration', $registration)
            ->with('task_summary', $taskSummary)
            ->render('forms/completion');
    }

    /**
     * Trigger a 404 response and render the 404 template.
     */
    private function trigger404(): void
    {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();

        $template = get_404_template();
        if ($template) {
            include $template;
        }
        exit;
    }
}
