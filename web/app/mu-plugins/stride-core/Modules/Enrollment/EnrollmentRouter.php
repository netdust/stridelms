<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

/**
 * URL routing for enrollment and completion forms.
 *
 * Plain class — created by EnrollmentService during init.
 * Uses ntdst_router() for clean URL pattern matching:
 * - /trajecten/{slug}/inschrijving/  → Trajectory enrollment
 * - /edities/{slug}/inschrijving/  → Edition enrollment
 * - /trajecten/{slug}/voltooien/     → Trajectory completion
 * - /edities/{slug}/voltooien/     → Edition completion
 */
final class EnrollmentRouter
{
    public function __construct(
        private readonly EnrollmentService $enrollmentService,
        private readonly RegistrationRepository $registrations,
        private readonly EnrollmentCompletion $completion,
    ) {}

    /**
     * Register all enrollment/completion routes via ntdst_router().
     */
    public function register(): void
    {
        ntdst_router()->get('trajecten/:slug/inschrijving', function (array $params) {
            return $this->handleTrajectoryEnrollment($params['slug']);
        });

        ntdst_router()->get('edities/:slug/inschrijving', function (array $params) {
            return $this->handleCourseEnrollment($params['slug']);
        });

        ntdst_router()->get('edities/:slug/voltooien', function (array $params) {
            return $this->handleCompletionRoute('edition', $params['slug']);
        });

        ntdst_router()->get('trajecten/:slug/voltooien', function (array $params) {
            return $this->handleCompletionRoute('trajectory', $params['slug']);
        });
    }

    // === Route Handlers ===

    private function handleTrajectoryEnrollment(string $slug): ?string
    {
        $trajectory = get_page_by_path($slug, OBJECT, 'vad_trajectory');

        if (!$trajectory) {
            return $this->notFoundTemplate();
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/trajecten/' . $slug . '/inschrijving/')));
            exit;
        }

        $args = ntdst_get(EnrollmentFormResolver::class)
            ->resolveTemplateArgs($trajectory, 'trajectory');

        return $this->renderResolved($args, get_permalink($trajectory->ID));
    }

    private function handleCourseEnrollment(string $slug): ?string
    {
        $edition = get_page_by_path($slug, OBJECT, 'vad_edition');

        if (!$edition && is_numeric($slug)) {
            $edition = get_post((int) $slug);
            if ($edition && $edition->post_type !== 'vad_edition') {
                $edition = null;
            }
        }

        if (!$edition) {
            return $this->notFoundTemplate();
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/edities/' . $slug . '/inschrijving/')));
            exit;
        }

        $args = ntdst_get(EnrollmentFormResolver::class)
            ->resolveTemplateArgs($edition, 'edition');

        if ($args['state'] === 'direct') {
            $this->handleDirectEnrollment($edition, $args['enrollment_mode']);
            return null;
        }

        return $this->renderResolved($args, get_permalink($edition->ID));
    }

    /**
     * Render the enrollment form wrapper based on the resolver's state.
     *
     * @param array<string, mixed> $args
     */
    private function renderResolved(array $args, string $closedRedirect): ?string
    {
        if ($args['state'] === 'closed') {
            wp_safe_redirect($closedRedirect);
            exit;
        }

        $response = ntdst_response()
            ->with('item', $args['item'])
            ->with('type', $args['item_type'])
            ->with('enrollment_open', $args['enrollment_open'])
            ->with('enrollment_mode', $args['enrollment_mode'])
            ->with('is_online', $args['is_online'])
            ->with('form_type', $args['form_type']);

        if ($args['already_enrolled']) {
            $response->with('already_enrolled', true);
        }

        $response->render('enrollment/form');

        return null;
    }

    private function handleCompletionRoute(string $type, string $slug): ?string
    {
        if (!is_user_logged_in()) {
            $base = $type === 'trajectory' ? 'trajecten' : 'edities';
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
            return $this->notFoundTemplate();
        }

        $userId = get_current_user_id();
        $repo = $this->registrations;

        if ($postType === 'vad_edition') {
            $registration = $repo->findByUserAndEdition($userId, $post->ID);
        } else {
            $regs = $repo->findByUser($userId);
            $registration = null;
            foreach ($regs as $r) {
                if ((int) ($r->trajectory_id ?? 0) === $post->ID && in_array($r->status, ['pending', 'confirmed'], true)) {
                    $registration = $r;
                    break;
                }
            }
        }

        // Allow both pending (enrollment tasks) and confirmed (post-course tasks)
        $allowedStatuses = ['pending', 'confirmed'];
        if (!$registration || !in_array($registration->status, $allowedStatuses, true) || empty($registration->completion_tasks)) {
            wp_safe_redirect(get_permalink($post->ID));
            exit;
        }

        // Check that at least one task is incomplete, or session_selection allows re-edit
        $tasks = is_string($registration->completion_tasks)
            ? json_decode($registration->completion_tasks, true) ?: []
            : (array) $registration->completion_tasks;
        $hasIncomplete = false;
        $hasSessionSelection = isset($tasks['session_selection']);
        foreach ($tasks as $task) {
            if (($task['status'] ?? 'pending') !== 'completed') {
                $hasIncomplete = true;
                break;
            }
        }
        if (!$hasIncomplete && !$hasSessionSelection) {
            wp_safe_redirect(get_permalink($post->ID));
            exit;
        }

        // Determine phase for template
        $phase = 'enrollment';
        foreach ($tasks as $task) {
            if (($task['phase'] ?? 'enrollment') === 'post_course' && ($task['status'] ?? 'pending') !== 'completed') {
                $phase = 'post_course';
                break;
            }
        }

        $completionService = $this->completion;
        $taskSummary = $completionService->getTaskSummary((int) $registration->id);

        ntdst_response()
            ->with('post', $post)
            ->with('type', $type)
            ->with('registration', $registration)
            ->with('task_summary', $taskSummary)
            ->with('phase', $phase)
            ->render('forms/completion');

        return null;
    }

    // === Helpers ===

    private function handleDirectEnrollment(\WP_Post $edition, string $mode): void
    {
        if ($mode === 'closed') {
            wp_safe_redirect(get_permalink($edition->ID));
            exit;
        }

        $userId = get_current_user_id();
        $enrollmentService = $this->enrollmentService;

        // Interest mode: register interest, not full enrollment
        $options = $mode === 'interest' ? ['status_override' => 'interest'] : [];

        $result = $enrollmentService->enroll($userId, $edition->ID, $options);

        if (is_wp_error($result)) {
            ntdst_log('enrollment')->warning('Direct enrollment failed; redirecting', [
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
                'edition_id' => $edition->ID,
                'user_id' => $userId,
                'mode' => $mode,
            ]);
            wp_safe_redirect(get_permalink($edition->ID));
            exit;
        }

        // Success — redirect to edition page with confirmation
        wp_safe_redirect(add_query_arg('enrolled', '1', get_permalink($edition->ID)));
        exit;
    }

    /**
     * Restore 404 state (the router cleared it on route match) and hand the
     * 404 template back to template_include so WP renders it the normal way.
     * Returning the template path keeps theme hooks and the_post() machinery
     * intact, instead of include-and-exit'ing past them.
     */
    private function notFoundTemplate(): string
    {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();

        return get_404_template();
    }
}
