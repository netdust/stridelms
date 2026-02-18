<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * User dashboard shortcodes.
 *
 * - [stride_dashboard] - Main user dashboard
 * - [stride_my_courses] - User's enrolled courses
 * - [stride_my_profile] - User profile page
 * - [stride_my_calendar] - User's upcoming sessions calendar
 */
final class UserDashboardShortcodes
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
        add_shortcode('stride_dashboard', [$this, 'renderDashboard']);
        add_shortcode('stride_my_courses', [$this, 'renderMyCourses']);
        add_shortcode('stride_my_profile', [$this, 'renderMyProfile']);
        add_shortcode('stride_my_calendar', [$this, 'renderMyCalendar']);
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

    /**
     * [stride_dashboard] - Main dashboard home
     */
    public function renderDashboard(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $userId = get_current_user_id();
        $user = wp_get_current_user();

        $data = [
            'user' => $user,
            'user_id' => $userId,
            'first_name' => $user->first_name ?: $user->display_name,
            'upcoming_dates' => $this->dashboardService->getUpcomingDates($userId, 3),
            'recent_activity' => $this->dashboardService->getRecentActivity($userId, 5),
            'stats' => $this->dashboardService->getDashboardStats($userId),
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('dashboard/home.php', $data);
    }

    /**
     * [stride_my_courses] - User's enrolled courses
     */
    public function renderMyCourses(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $atts = shortcode_atts([
            'filter' => 'all', // all, online, in-person, completed
        ], $atts);

        $userId = get_current_user_id();

        // Get filter from URL if set
        $currentFilter = sanitize_text_field($_GET['filter'] ?? $atts['filter']);

        $filters = [];
        if ($currentFilter === 'online') {
            $filters['type'] = 'online';
        } elseif ($currentFilter === 'in-person') {
            $filters['type'] = 'in-person';
        } elseif ($currentFilter === 'completed') {
            $filters['status'] = 'completed';
        }

        $data = [
            'user_id' => $userId,
            'courses' => $this->dashboardService->getUserCourses($userId, $filters),
            'current_filter' => $currentFilter,
            'filters' => [
                'all' => __('Alle', 'stride'),
                'online' => __('Online', 'stride'),
                'in-person' => __('In-person', 'stride'),
                'completed' => __('Afgerond', 'stride'),
            ],
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('dashboard/courses.php', $data);
    }

    /**
     * [stride_my_profile] - User profile edit
     */
    public function renderMyProfile(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $userId = get_current_user_id();

        $data = [
            'user_id' => $userId,
            'profile' => $this->dashboardService->getUserProfile($userId),
            'change_password_url' => wp_lostpassword_url(),
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('dashboard/profile.php', $data);
    }

    /**
     * [stride_my_calendar] - User's upcoming dates/agenda
     */
    public function renderMyCalendar(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $atts = shortcode_atts([
            'limit' => 10,
        ], $atts);

        $userId = get_current_user_id();

        $data = [
            'user_id' => $userId,
            'upcoming_dates' => $this->dashboardService->getUpcomingDates($userId, (int) $atts['limit']),
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('dashboard/calendar.php', $data);
    }
}
