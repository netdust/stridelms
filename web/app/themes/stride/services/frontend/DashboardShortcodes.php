<?php

namespace stride\services\frontend;

defined('ABSPATH') || exit;

use stride\services\frontend\shortcodes\CourseShortcodes;
use stride\services\frontend\shortcodes\TrajectoryShortcodes;
use stride\services\frontend\shortcodes\QuoteShortcodes;
use stride\services\frontend\shortcodes\EnrollmentShortcodes;
use stride\services\frontend\shortcodes\UserDashboardShortcodes;

/**
 * Dashboard Shortcodes Service
 *
 * Orchestrates registration of all dashboard shortcodes.
 * Each domain has its own shortcode class.
 *
 * Available shortcodes:
 * - [stride_dashboard] - Main dashboard home
 * - [stride_my_courses] - User's enrolled courses
 * - [stride_my_trajectories] - User's trajectories
 * - [stride_my_quotes] - User's quotes
 * - [stride_my_profile] - User profile edit
 * - [stride_my_calendar] - User's upcoming dates
 * - [stride_trajectory] - Single trajectory view (requires id attribute)
 * - [stride_trajectory_catalog] - Public trajectory catalog
 * - [stride_course_sidebar] - Course action sidebar (use on course pages)
 * - [stride_course_catalog] - Course listing/archive
 * - [stride_edition] - Single edition page
 * - [stride_enrollment] - Enrollment form
 * - [stride_session_selection] - Session selection UI
 * - [stride_quote_update] - Quote update form
 *
 * @package stride\services\frontend
 */
class DashboardShortcodes implements \NTDST_Service_Meta
{
    private ?DashboardService $dashboardService;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Dashboard Shortcodes',
            'description' => 'Registers dashboard shortcodes and template routing',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 20,
        ];
    }

    /**
     * Constructor
     */
    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService ?? $this->resolveService(DashboardService::class);

        add_action('init', [$this, 'registerShortcodes']);
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
     * Register all shortcodes via sub-classes.
     */
    public function registerShortcodes(): void
    {
        // User dashboard shortcodes
        (new UserDashboardShortcodes($this->dashboardService))->register();

        // Course shortcodes
        (new CourseShortcodes($this->dashboardService))->register();

        // Trajectory shortcodes
        (new TrajectoryShortcodes($this->dashboardService))->register();

        // Quote shortcodes
        (new QuoteShortcodes($this->dashboardService))->register();

        // Enrollment shortcodes
        (new EnrollmentShortcodes($this->dashboardService))->register();
    }
}
