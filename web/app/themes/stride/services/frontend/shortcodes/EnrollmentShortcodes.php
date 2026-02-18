<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * Enrollment-related shortcodes.
 *
 * - [stride_enrollment] - Enrollment form
 * - [stride_edition] - Single edition page
 * - [stride_session_selection] - Session selection for flexible editions
 *
 * @package stride\services\frontend\shortcodes
 */
final class EnrollmentShortcodes
{
    private ?DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService ?? $this->resolveService(DashboardService::class);
    }

    /**
     * Register shortcodes
     */
    public function register(): void
    {
        add_shortcode('stride_enrollment', [$this, 'renderEnrollmentForm']);
        add_shortcode('stride_edition', [$this, 'renderEdition']);
        add_shortcode('stride_session_selection', [$this, 'renderSessionSelection']);
    }

    /**
     * Resolve service from DI container
     */
    private function resolveService(string $class): ?object
    {
        if (function_exists('ntdst_get')) {
            try {
                return ntdst_get($class);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Get template path
     */
    private function getTemplatePath(string $template): string
    {
        return get_stylesheet_directory() . '/templates/' . $template;
    }

    /**
     * Render a template with data
     */
    private function renderTemplate(string $template, array $data = []): string
    {
        $templatePath = $this->getTemplatePath($template);

        if (!file_exists($templatePath)) {
            if (current_user_can('manage_options')) {
                return '<div class="uk-alert uk-alert-warning">Template not found: ' . esc_html($template) . '</div>';
            }
            return '';
        }

        // Extract data for template access
        extract($data, EXTR_SKIP);

        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Check if user is logged in and redirect/show message if not
     */
    private function requireLogin(): ?string
    {
        if (is_user_logged_in()) {
            return null;
        }

        return $this->renderTemplate('dashboard/login-required.php', [
            'login_url' => wp_login_url(get_permalink()),
            'register_url' => wp_registration_url(),
        ]);
    }

    // ========================================
    // SHORTCODE HANDLERS
    // ========================================

    /**
     * [stride_session_selection registration_id="123"] - Session selection UI
     */
    public function renderSessionSelection(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $atts = shortcode_atts([
            'registration_id' => '',
        ], $atts);

        // Get registration ID from URL if not in shortcode
        $registrationId = (int) ($atts['registration_id'] ?: ($_GET['registration_id'] ?? 0));

        if (!$registrationId) {
            return '<div class="uk-alert uk-alert-warning">' .
                esc_html__('Geen registratie opgegeven.', 'stride') .
                '</div>';
        }

        $userId = get_current_user_id();

        // Get services
        $sessionSelectionService = $this->resolveService(\ntdst\Stride\core\SessionSelectionService::class);
        $registrationRepo = $this->resolveService(\ntdst\Stride\core\RegistrationRepository::class);
        $editionService = $this->resolveService(\ntdst\Stride\core\EditionService::class);

        if (!$sessionSelectionService || !$registrationRepo) {
            return '<div class="uk-alert uk-alert-danger">' .
                esc_html__('Service niet beschikbaar.', 'stride') .
                '</div>';
        }

        // Get registration and verify ownership
        $registration = $registrationRepo->get($registrationId);
        if (!$registration) {
            return '<div class="uk-alert uk-alert-warning">' .
                esc_html__('Registratie niet gevonden.', 'stride') .
                '</div>';
        }

        if ((int) $registration['user_id'] !== $userId && !current_user_can('manage_options')) {
            return '<div class="uk-alert uk-alert-danger">' .
                esc_html__('Je hebt geen toegang tot deze registratie.', 'stride') .
                '</div>';
        }

        // Get selection status
        $status = $sessionSelectionService->getSelectionStatus($registrationId);

        // Get edition and course info
        $editionId = $registration['edition_id'];
        $edition = $editionService ? $editionService->getEdition($editionId) : null;
        $courseId = $edition['course_id'] ?? null;
        $courseTitle = $courseId ? get_the_title($courseId) : '';

        $data = [
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'status' => $status,
            'edition' => $edition,
            'course_title' => $courseTitle,
        ];

        return $this->renderTemplate('dashboard/session-selection.php', $data);
    }

    /**
     * [stride_edition id="123"] - Single edition page
     */
    public function renderEdition(array $atts = []): string
    {
        $atts = shortcode_atts(['id' => 0], $atts);
        $editionId = (int) ($atts['id'] ?: get_queried_object_id());

        if (!$editionId) {
            return '<div class="uk-alert uk-alert-warning">' . esc_html__('Geen editie opgegeven.', 'stride') . '</div>';
        }

        $editionService = $this->resolveService(\ntdst\Stride\core\EditionService::class);
        $sessionService = $this->resolveService(\ntdst\Stride\core\SessionService::class);

        if (!$editionService || !$sessionService) {
            return '<div class="uk-alert uk-alert-danger">' . esc_html__('Service niet beschikbaar.', 'stride') . '</div>';
        }

        $edition = $editionService->getEdition($editionId);
        if (!$edition) {
            return '<div class="uk-alert uk-alert-warning">' . esc_html__('Editie niet gevonden.', 'stride') . '</div>';
        }

        $courseId = $edition['course_id'];
        $course = get_post($courseId);
        $sessions = $sessionService->getSessionsForEdition($editionId);
        $userId = get_current_user_id();

        // Calculate total hours
        $totalHours = $sessionService->getTotalHours($editionId);

        // Get status for badge
        $status = $editionService->getStatus($editionId);

        $data = [
            'edition_id' => $editionId,
            'edition' => $edition,
            'course' => $course,
            'course_content' => $course ? apply_filters('the_content', get_post_field('post_content', $courseId)) : '',
            'sessions' => $sessions,
            'session_slots' => $editionService->getSessionSlots($editionId),
            'speakers' => $editionService->getSpeakers($editionId),
            'available_spots' => $editionService->getAvailableSpots($editionId),
            'capacity' => $editionService->getCapacity($editionId),
            'status' => $status,
            'total_hours' => $totalHours,
            'day_count' => $sessionService->getDayCount($editionId),
            'price' => $editionService->getPrice($editionId),
            'price_non_member' => $editionService->getPriceNonMember($editionId),
            'venue' => $editionService->getVenue($editionId),
            'start_date' => $editionService->getStartDate($editionId),
            'end_date' => $editionService->getEndDate($editionId),
            'selection_deadline' => $editionService->getSelectionDeadline($editionId),
            'requires_session_selection' => $editionService->requiresSessionSelection($editionId),
            'is_certificate_enabled' => $editionService->isCertificateEnabled($editionId),
            'is_invoice_enabled' => $editionService->isInvoiceEnabled($editionId),
            'is_multi_year' => $editionService->isMultiYearTraining($editionId),
            'action_button' => $this->dashboardService ? $this->dashboardService->getEditionActionButton($editionId, $userId) : null,
            'user_id' => $userId,
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('edition/single.php', $data);
    }

    /**
     * [stride_enrollment edition="123"] - Enrollment form
     */
    public function renderEnrollmentForm(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $atts = shortcode_atts(['edition' => 0], $atts);
        $editionId = (int) ($atts['edition'] ?: ($_GET['edition_id'] ?? 0));

        if (!$editionId) {
            return '<div class="uk-alert uk-alert-warning">' . esc_html__('Geen editie opgegeven.', 'stride') . '</div>';
        }

        $editionService = $this->resolveService(\ntdst\Stride\core\EditionService::class);
        $sessionService = $this->resolveService(\ntdst\Stride\core\SessionService::class);

        if (!$editionService || !$sessionService) {
            return '<div class="uk-alert uk-alert-danger">' . esc_html__('Service niet beschikbaar.', 'stride') . '</div>';
        }

        $edition = $editionService->getEdition($editionId);
        if (!$edition) {
            return '<div class="uk-alert uk-alert-warning">' . esc_html__('Editie niet gevonden.', 'stride') . '</div>';
        }

        // Check if enrollment is open
        if (!$editionService->isEnrollmentOpen($editionId)) {
            $status = $editionService->getStatus($editionId);
            $message = match ($status) {
                'full' => __('Deze editie is volzet.', 'stride'),
                'cancelled' => __('Deze editie is geannuleerd.', 'stride'),
                'completed' => __('Deze editie is afgelopen.', 'stride'),
                default => __('Inschrijving is niet mogelijk voor deze editie.', 'stride'),
            };
            return '<div class="uk-alert uk-alert-warning">' . esc_html($message) . '</div>';
        }

        $userId = get_current_user_id();
        $user = wp_get_current_user();
        $sessions = $sessionService->getSessionsForEdition($editionId);

        $data = [
            'edition_id' => $editionId,
            'edition' => $edition,
            'course' => get_post($edition['course_id']),
            'price' => $editionService->getPrice($editionId),
            'price_non_member' => $editionService->getPriceNonMember($editionId),
            'sessions' => $sessions,
            'session_slots' => $editionService->getSessionSlots($editionId),
            'requires_session_selection' => $editionService->requiresSessionSelection($editionId),
            'selection_deadline' => $editionService->getSelectionDeadline($editionId),
            'start_date' => $editionService->getStartDate($editionId),
            'end_date' => $editionService->getEndDate($editionId),
            'venue' => $editionService->getVenue($editionId),
            'user' => $user,
            'user_profile' => $this->dashboardService ? $this->dashboardService->getUserProfile($userId) : null,
            'nonce' => wp_create_nonce('stride_enrollment'),
            'ajax_url' => admin_url('admin-ajax.php'),
        ];

        return $this->renderTemplate('enrollment/form.php', $data);
    }
}
