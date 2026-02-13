<?php

namespace stride\services\frontend;

defined('ABSPATH') || exit;

/**
 * Dashboard Shortcodes Service
 *
 * Registers shortcodes for dashboard pages and routes them to templates.
 *
 * Available shortcodes:
 * - [stride_dashboard] - Main dashboard home
 * - [stride_my_courses] - User's enrolled courses
 * - [stride_my_trajectories] - User's trajectories
 * - [stride_my_quotes] - User's quotes
 * - [stride_my_profile] - User profile edit
 * - [stride_my_calendar] - User's upcoming dates
 * - [stride_trajectory] - Single trajectory view (requires id attribute)
 * - [stride_course_sidebar] - Course action sidebar (use on course pages)
 * - [stride_course_catalog] - Course listing/archive
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
     * Register all shortcodes
     */
    public function registerShortcodes(): void
    {
        add_shortcode('stride_dashboard', [$this, 'renderDashboard']);
        add_shortcode('stride_my_courses', [$this, 'renderMyCourses']);
        add_shortcode('stride_my_trajectories', [$this, 'renderMyTrajectories']);
        add_shortcode('stride_trajectory', [$this, 'renderTrajectory']);
        add_shortcode('stride_my_quotes', [$this, 'renderMyQuotes']);
        add_shortcode('stride_my_profile', [$this, 'renderMyProfile']);
        add_shortcode('stride_my_calendar', [$this, 'renderMyCalendar']);
        add_shortcode('stride_course_sidebar', [$this, 'renderCourseSidebar']);
        add_shortcode('stride_course_catalog', [$this, 'renderCourseCatalog']);
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
     * [stride_my_quotes] - User's quotes
     */
    public function renderMyQuotes(array $atts = []): string
    {
        $loginRequired = $this->requireLogin();
        if ($loginRequired !== null) {
            return $loginRequired;
        }

        $userId = get_current_user_id();

        $data = [
            'user_id' => $userId,
            'quotes' => $this->dashboardService->getUserQuotes($userId),
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('dashboard/quotes.php', $data);
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

    /**
     * [stride_course_sidebar] - Course action sidebar (for single course pages)
     */
    public function renderCourseSidebar(array $atts = []): string
    {
        $atts = shortcode_atts([
            'id' => 0,
        ], $atts);

        // Get course ID from attribute or current post
        $courseId = (int) ($atts['id'] ?: get_the_ID());

        if (!$courseId) {
            return '';
        }

        $userId = get_current_user_id();

        $data = [
            'course_id' => $courseId,
            'user_id' => $userId,
            'course_info' => $this->dashboardService->getCourseInfo($courseId),
            'action_button' => $this->dashboardService->getCourseActionButton($courseId, $userId),
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('partials/course-sidebar.php', $data);
    }

    /**
     * [stride_course_catalog] - Course listing/archive
     */
    public function renderCourseCatalog(array $atts = []): string
    {
        $atts = shortcode_atts([
            'category' => '',
            'type' => '', // online, in-person
            'limit' => 12,
            'show_filters' => 'true',
        ], $atts);

        // Get filter values from URL
        $currentCategory = sanitize_text_field($_GET['category'] ?? $atts['category']);
        $currentType = sanitize_text_field($_GET['type'] ?? $atts['type']);
        $currentSearch = sanitize_text_field($_GET['search'] ?? '');
        $currentPage = max(1, (int) ($_GET['paged'] ?? 1));

        // Build query args
        $queryArgs = [
            'post_type' => 'sfwd-courses',
            'posts_per_page' => (int) $atts['limit'],
            'paged' => $currentPage,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        ];

        // Add search
        if ($currentSearch) {
            $queryArgs['s'] = $currentSearch;
        }

        // Add category filter
        if ($currentCategory) {
            $queryArgs['tax_query'] = [
                [
                    'taxonomy' => 'ld_course_category',
                    'field' => 'slug',
                    'terms' => $currentCategory,
                ],
            ];
        }

        $query = new \WP_Query($queryArgs);
        $courses = [];

        // Get course service for type filtering
        $courseService = stride_service(\stride\services\core\CourseService::class);

        foreach ($query->posts as $post) {
            $courseId = $post->ID;

            // Type filter
            if ($currentType) {
                $isInPerson = $courseService->isInPerson($courseId);
                if ($currentType === 'online' && $isInPerson) {
                    continue;
                }
                if ($currentType === 'in-person' && !$isInPerson) {
                    continue;
                }
            }

            $courses[] = [
                'id' => $courseId,
                'title' => $post->post_title,
                'excerpt' => get_the_excerpt($post),
                'permalink' => get_permalink($courseId),
                'thumbnail' => get_the_post_thumbnail_url($courseId, 'stride_course_card'),
                'is_in_person' => $courseService->isInPerson($courseId),
                'is_online' => $courseService->isOnline($courseId),
                'next_date' => $courseService->getNextDate($courseId),
                'price' => $courseService->getCoursePrice($courseId),
                'is_full' => $courseService->isFull($courseId),
                'is_cancelled' => $courseService->isCancelled($courseId),
                'available_spots' => $courseService->getAvailableSpots($courseId),
            ];
        }

        // Get categories for filter
        $categories = get_terms([
            'taxonomy' => 'ld_course_category',
            'hide_empty' => true,
        ]);

        $data = [
            'courses' => $courses,
            'total_courses' => $query->found_posts,
            'total_pages' => $query->max_num_pages,
            'current_page' => $currentPage,
            'current_category' => $currentCategory,
            'current_type' => $currentType,
            'current_search' => $currentSearch,
            'categories' => is_array($categories) ? $categories : [],
            'show_filters' => $atts['show_filters'] === 'true',
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('course/archive.php', $data);
    }
}
