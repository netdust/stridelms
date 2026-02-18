<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * Trajectory-related shortcodes.
 *
 * - [stride_my_trajectories] - User's trajectory list
 * - [stride_trajectory] - Single trajectory view
 * - [stride_trajectory_catalog] - Public trajectory catalog
 */
final class TrajectoryShortcodes
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
        add_shortcode('stride_my_trajectories', [$this, 'renderMyTrajectories']);
        add_shortcode('stride_trajectory', [$this, 'renderTrajectory']);
        add_shortcode('stride_trajectory_catalog', [$this, 'renderTrajectoryCatalog']);
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
     * [stride_my_trajectories] - User's trajectories
     */
    public function renderMyTrajectories(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $userId = get_current_user_id();

        $data = [
            'user_id' => $userId,
            'trajectories' => $this->dashboardService->getUserTrajectories($userId),
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('dashboard/trajectories.php', $data);
    }

    /**
     * [stride_trajectory id="123"] - Single trajectory journey view
     */
    public function renderTrajectory(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $atts = shortcode_atts([
            'id' => 0,
        ], $atts);

        // Get trajectory ID from attribute or URL
        $trajectoryId = (int) ($atts['id'] ?: ($_GET['trajectory'] ?? 0));

        if (!$trajectoryId) {
            return '<div class="uk-alert uk-alert-warning">' . __('Geen traject gespecificeerd.', 'stride') . '</div>';
        }

        $userId = get_current_user_id();
        $trajectory = $this->dashboardService->getTrajectory($trajectoryId, $userId);

        if (!$trajectory) {
            return '<div class="uk-alert uk-alert-warning">' . __('Traject niet gevonden of geen toegang.', 'stride') . '</div>';
        }

        $data = [
            'user_id' => $userId,
            'trajectory' => $trajectory,
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('dashboard/trajectory-single.php', $data);
    }

    /**
     * [stride_trajectory_catalog] - Public trajectory catalog
     */
    public function renderTrajectoryCatalog(array $atts = []): string
    {
        $atts = shortcode_atts([
            'limit' => 12,
            'show_filters' => 'true',
        ], $atts);

        // Get trajectory service
        $trajectoryService = stride_service(\ntdst\Stride\core\TrajectoryService::class);
        if (!$trajectoryService) {
            return '<p>' . esc_html__('Trajecten service niet beschikbaar.', 'stride') . '</p>';
        }

        // Get filter values
        $currentMode = sanitize_text_field($_GET['mode'] ?? '');
        $currentSearch = sanitize_text_field($_GET['search'] ?? '');

        // Get all active trajectories
        $allTrajectories = $trajectoryService->getActiveTrajectories();

        // Apply filters
        $trajectories = [];
        foreach ($allTrajectories as $trajectory) {
            // Mode filter
            if ($currentMode && ($trajectory['mode'] ?? 'self_paced') !== $currentMode) {
                continue;
            }

            // Search filter
            if ($currentSearch) {
                $searchLower = strtolower($currentSearch);
                $titleMatch = strpos(strtolower($trajectory['title']), $searchLower) !== false;
                $descMatch = strpos(strtolower($trajectory['description'] ?? ''), $searchLower) !== false;
                if (!$titleMatch && !$descMatch) {
                    continue;
                }
            }

            $trajectories[] = $trajectory;
        }

        $data = [
            'trajectories' => $trajectories,
            'total_trajectories' => count($trajectories),
            'current_mode' => $currentMode,
            'current_search' => $currentSearch,
            'show_filters' => $atts['show_filters'] === 'true',
        ];

        return $this->renderTemplate('trajectory/archive.php', $data);
    }
}
