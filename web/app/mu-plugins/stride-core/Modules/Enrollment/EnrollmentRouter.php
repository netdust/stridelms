<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Trajectory\TrajectoryService;

/**
 * URL routing for enrollment and completion forms.
 *
 * Plain class — created by EnrollmentService during init.
 * Uses ntdst_router() for clean URL pattern matching:
 * - /trajecten/{slug}/inschrijving/  → Trajectory enrollment
 * - /vormingen/{slug}/inschrijving/  → Edition enrollment
 * - /trajecten/{slug}/voltooien/     → Trajectory completion
 * - /vormingen/{slug}/voltooien/     → Edition completion
 */
final class EnrollmentRouter
{
    /**
     * Register all enrollment/completion routes via ntdst_router().
     */
    public function register(): void
    {
        ntdst_router()->get('trajecten/:slug/inschrijving', function (array $params) {
            $this->handleTrajectoryEnrollment($params['slug']);
        });

        ntdst_router()->get('vormingen/:slug/inschrijving', function (array $params) {
            $this->handleCourseEnrollment($params['slug']);
        });

        ntdst_router()->get('vormingen/:slug/voltooien', function (array $params) {
            $this->handleCompletionRoute('edition', $params['slug']);
        });

        ntdst_router()->get('trajecten/:slug/voltooien', function (array $params) {
            $this->handleCompletionRoute('trajectory', $params['slug']);
        });
    }

    // === Route Handlers ===

    private function handleTrajectoryEnrollment(string $slug): void
    {
        $trajectory = get_page_by_path($slug, OBJECT, 'vad_trajectory');

        if (!$trajectory) {
            $this->trigger404();
            return;
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/trajecten/' . $slug . '/inschrijving/')));
            exit;
        }

        $trajectoryService = ntdst_get(TrajectoryService::class);
        $mode = $this->computeEnrollmentMode(
            $trajectoryService->getTrajectory($trajectory->ID)['status_enum'] ?? OfferingStatus::Draft,
            $trajectoryService->requiresApproval($trajectory->ID),
            $trajectoryService->isEnrollmentOpen($trajectory->ID),
        );

        ntdst_response()
            ->with('item', $trajectory)
            ->with('type', 'trajectory')
            ->with('enrollment_open', $mode !== 'closed')
            ->with('enrollment_mode', $mode)
            ->render('enrollment/form');
    }

    private function handleCourseEnrollment(string $slug): void
    {
        $edition = get_page_by_path($slug, OBJECT, 'vad_edition');

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

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/vormingen/' . $slug . '/inschrijving/')));
            exit;
        }

        $editionService = ntdst_get(EditionService::class);
        $status = $editionService->getStatus($edition->ID);
        $mode = $this->computeEnrollmentMode(
            $status,
            $editionService->requiresApproval($edition->ID),
            $status->allowsEnrollment() && $editionService->hasAvailableSpots($edition->ID),
        );

        $isOnline = $editionService->isOnline($edition->ID);

        ntdst_response()
            ->with('item', $edition)
            ->with('type', 'edition')
            ->with('enrollment_open', $mode !== 'closed')
            ->with('enrollment_mode', $mode)
            ->with('is_online', $isOnline)
            ->render('enrollment/form');
    }

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
            wp_safe_redirect(get_permalink($post->ID));
            exit;
        }

        $completionService = ntdst_get(EnrollmentCompletion::class);
        $taskSummary = $completionService->getTaskSummary((int) $registration->id);

        ntdst_response()
            ->with('post', $post)
            ->with('type', $type)
            ->with('registration', $registration)
            ->with('task_summary', $taskSummary)
            ->render('forms/completion');
    }

    // === Helpers ===

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
