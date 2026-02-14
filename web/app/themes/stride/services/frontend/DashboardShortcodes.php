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
        add_shortcode('stride_trajectory_catalog', [$this, 'renderTrajectoryCatalog']);
        add_shortcode('stride_session_selection', [$this, 'renderSessionSelection']);
        add_shortcode('stride_edition', [$this, 'renderEdition']);
        add_shortcode('stride_enrollment', [$this, 'renderEnrollmentForm']);

        // AJAX handlers
        add_action('wp_ajax_stride_save_session_selection', [$this, 'ajaxSaveSessionSelection']);
        add_action('wp_ajax_stride_validate_voucher', [$this, 'ajaxValidateVoucher']);
        add_action('wp_ajax_stride_submit_enrollment', [$this, 'ajaxSubmitEnrollment']);
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
            'tag' => '',
            'limit' => 12,
            'show_filters' => 'true',
        ], $atts);

        // Get filter values from URL
        $currentCategory = sanitize_text_field($_GET['category'] ?? $atts['category']);
        $currentTag = sanitize_text_field($_GET['tag'] ?? $atts['tag']);
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

        // Add taxonomy filters
        $taxQuery = [];
        if ($currentCategory) {
            $taxQuery[] = [
                'taxonomy' => 'ld_course_category',
                'field' => 'slug',
                'terms' => $currentCategory,
            ];
        }
        if ($currentTag) {
            $taxQuery[] = [
                'taxonomy' => 'ld_course_tag',
                'field' => 'slug',
                'terms' => $currentTag,
            ];
        }
        if (!empty($taxQuery)) {
            $queryArgs['tax_query'] = $taxQuery;
        }

        $query = new \WP_Query($queryArgs);
        $items = [];

        // Get services for course data
        $courseService = stride_service(\ntdst\Stride\core\CourseService::class);
        $editionService = stride_service(\ntdst\Stride\core\EditionService::class);

        foreach ($query->posts as $post) {
            $courseId = $post->ID;
            $isInPerson = $courseService->isInPerson($courseId);
            $isOnline = $courseService->isOnline($courseId);
            $thumbnail = get_the_post_thumbnail_url($courseId, 'stride_course_card');

            if ($isOnline) {
                // E-learning: show as single card linking to course page
                $items[] = [
                    'id' => $courseId,
                    'title' => $post->post_title,
                    'excerpt' => get_the_excerpt($post),
                    'permalink' => get_permalink($courseId),
                    'thumbnail' => $thumbnail,
                    'is_in_person' => false,
                    'is_online' => true,
                    'next_date' => null,
                    'price' => null,
                    'is_full' => false,
                    'is_cancelled' => false,
                    'available_spots' => null,
                    'edition_id' => null,
                    'venue' => null,
                ];
            } else {
                // In-person: show each edition as a separate card linking to edition page
                $upcomingEditions = $editionService ? $editionService->getUpcomingEditionsForCourse($courseId) : [];

                if (empty($upcomingEditions)) {
                    // No upcoming editions - still show course but indicate no dates
                    $items[] = [
                        'id' => $courseId,
                        'title' => $post->post_title,
                        'excerpt' => get_the_excerpt($post),
                        'permalink' => get_permalink($courseId),
                        'thumbnail' => $thumbnail,
                        'is_in_person' => true,
                        'is_online' => false,
                        'next_date' => null,
                        'price' => null,
                        'is_full' => false,
                        'is_cancelled' => false,
                        'available_spots' => null,
                        'edition_id' => null,
                        'venue' => null,
                        'no_editions' => true,
                    ];
                } else {
                    // Add each edition as a separate item
                    foreach ($upcomingEditions as $edition) {
                        $editionId = $edition['id'];
                        $startDateStr = $editionService->getStartDate($editionId);

                        $items[] = [
                            'id' => $courseId,
                            'edition_id' => $editionId,
                            'title' => $post->post_title,
                            'excerpt' => get_the_excerpt($post),
                            'permalink' => get_permalink($editionId), // Link to edition!
                            'thumbnail' => $thumbnail,
                            'is_in_person' => true,
                            'is_online' => false,
                            'next_date' => $startDateStr ? strtotime($startDateStr) : null,
                            'price' => $editionService->getPrice($editionId),
                            'is_full' => $editionService->isFull($editionId),
                            'is_cancelled' => $editionService->isCancelled($editionId),
                            'available_spots' => $editionService->getAvailableSpots($editionId),
                            'venue' => $editionService->getVenue($editionId),
                        ];
                    }
                }
            }
        }

        // Sort by date (editions with dates first, then by date ascending)
        usort($items, function ($a, $b) {
            // Online courses go to the end
            if ($a['is_online'] !== $b['is_online']) {
                return $a['is_online'] ? 1 : -1;
            }
            // Items without dates go to the end
            if ($a['next_date'] === null && $b['next_date'] === null) {
                return 0;
            }
            if ($a['next_date'] === null) {
                return 1;
            }
            if ($b['next_date'] === null) {
                return -1;
            }
            // Sort by date ascending (soonest first)
            return $a['next_date'] <=> $b['next_date'];
        });

        // Get categories and tags for filters
        $categories = get_terms([
            'taxonomy' => 'ld_course_category',
            'hide_empty' => true,
        ]);
        $tags = get_terms([
            'taxonomy' => 'ld_course_tag',
            'hide_empty' => true,
        ]);

        $data = [
            'courses' => $items,
            'total_courses' => count($items),
            'total_pages' => $query->max_num_pages,
            'current_page' => $currentPage,
            'current_category' => $currentCategory,
            'current_tag' => $currentTag,
            'current_search' => $currentSearch,
            'categories' => is_array($categories) ? $categories : [],
            'tags' => is_array($tags) ? $tags : [],
            'show_filters' => $atts['show_filters'] === 'true',
            'dashboard_service' => $this->dashboardService,
        ];

        return $this->renderTemplate('course/archive.php', $data);
    }

    /**
     * Render public trajectory catalog
     *
     * Shortcode: [stride_trajectory_catalog]
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
     * AJAX: Save session selection
     */
    public function ajaxSaveSessionSelection(): void
    {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_session_selection')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $sessionsJson = $_POST['sessions'] ?? '[]';
        $sessionIds = json_decode($sessionsJson, true) ?: [];

        if (!$registrationId) {
            wp_send_json_error(['message' => __('Geen registratie opgegeven.', 'stride')]);
        }

        // Get service
        $sessionSelectionService = $this->resolveService(\ntdst\Stride\core\SessionSelectionService::class);
        if (!$sessionSelectionService) {
            wp_send_json_error(['message' => __('Service niet beschikbaar.', 'stride')]);
        }

        // Save selections
        $result = $sessionSelectionService->selectSessions($registrationId, array_map('intval', $sessionIds));

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Je sessiekeuze is opgeslagen.', 'stride'),
            'reload' => true,
        ]);
    }

    // ========================================
    // EDITION PAGE
    // ========================================

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

    // ========================================
    // ENROLLMENT FORM
    // ========================================

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

    /**
     * AJAX: Validate voucher code
     */
    public function ajaxValidateVoucher(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_enrollment')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $code = sanitize_text_field($_POST['code'] ?? '');
        $editionId = (int) ($_POST['edition_id'] ?? 0);

        if (empty($code)) {
            wp_send_json_error(['message' => __('Vouchercode is vereist.', 'stride')]);
        }

        $voucherService = $this->resolveService(\ntdst\Stride\invoicing\VoucherService::class);
        $editionService = $this->resolveService(\ntdst\Stride\core\EditionService::class);

        if (!$voucherService) {
            wp_send_json_error(['message' => __('Service niet beschikbaar.', 'stride')]);
        }

        // Validate the voucher
        $validation = $voucherService->validateVoucher($code, $editionId, 0, 'edition');

        if (is_wp_error($validation)) {
            wp_send_json_error(['message' => __('Vouchercode ongeldig of verlopen.', 'stride')]);
        }

        // Calculate discount
        $price = $editionService ? $editionService->getPrice($editionId) : 0;
        $discount = $voucherService->calculateDiscount($validation, 'edition', $editionId, $price);

        wp_send_json_success([
            'valid' => true,
            'discount' => $discount,
            'discount_formatted' => '€ ' . number_format($discount, 2, ',', '.'),
            'discount_type' => $validation['discount_type'],
            'message' => sprintf(__('Korting toegepast: -€ %s', 'stride'), number_format($discount, 2, ',', '.')),
        ]);
    }

    /**
     * AJAX: Submit enrollment
     */
    public function ajaxSubmitEnrollment(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_enrollment')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $userId = get_current_user_id();
        if (!$userId) {
            wp_send_json_error(['message' => __('Je moet ingelogd zijn om in te schrijven.', 'stride')]);
        }

        $editionId = (int) ($_POST['edition_id'] ?? 0);
        if (!$editionId) {
            wp_send_json_error(['message' => __('Geen editie opgegeven.', 'stride')]);
        }

        // Get services
        $enrollmentService = $this->resolveService(\ntdst\Stride\enrollment\EnrollmentService::class);
        $editionService = $this->resolveService(\ntdst\Stride\core\EditionService::class);

        if (!$enrollmentService || !$editionService) {
            wp_send_json_error(['message' => __('Service niet beschikbaar.', 'stride')]);
        }

        // Check if enrollment is still open
        if (!$editionService->isEnrollmentOpen($editionId)) {
            wp_send_json_error(['message' => __('Inschrijving is niet meer mogelijk voor deze editie.', 'stride')]);
        }

        // Collect enrollment data
        $enrollmentData = [
            'edition_id' => $editionId,
            'user_id' => $userId,
            'enrollment_type' => sanitize_text_field($_POST['enrollment_type'] ?? 'self'),
            'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
            'email' => sanitize_email($_POST['email'] ?? ''),
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'company' => sanitize_text_field($_POST['company'] ?? ''),
            'vat_number' => sanitize_text_field($_POST['vat_number'] ?? ''),
            'address' => sanitize_text_field($_POST['address'] ?? ''),
            'postal_code' => sanitize_text_field($_POST['postal_code'] ?? ''),
            'city' => sanitize_text_field($_POST['city'] ?? ''),
            'gln_peppol' => sanitize_text_field($_POST['gln_peppol'] ?? ''),
            'invoice_email' => sanitize_email($_POST['invoice_email'] ?? ''),
            'po_number' => sanitize_text_field($_POST['po_number'] ?? ''),
            'voucher_code' => sanitize_text_field($_POST['voucher_code'] ?? ''),
            'selected_sessions' => array_map('intval', $_POST['selected_sessions'] ?? []),
            'terms_accepted' => (bool) ($_POST['terms_accepted'] ?? false),
        ];

        // Validate required fields
        if (empty($enrollmentData['first_name']) || empty($enrollmentData['last_name'])) {
            wp_send_json_error(['message' => __('Voornaam en achternaam zijn vereist.', 'stride')]);
        }

        if (empty($enrollmentData['email'])) {
            wp_send_json_error(['message' => __('E-mailadres is vereist.', 'stride')]);
        }

        if (!$enrollmentData['terms_accepted']) {
            wp_send_json_error(['message' => __('Je moet akkoord gaan met de voorwaarden.', 'stride')]);
        }

        // Process enrollment
        $result = $enrollmentService->processEnrollment($enrollmentData);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'message' => __('Je inschrijving is succesvol verwerkt!', 'stride'),
            'registration_id' => $result['registration_id'] ?? null,
            'quote_id' => $result['quote_id'] ?? null,
            'redirect_url' => home_url('/mijn-account/cursussen/'),
        ]);
    }
}
