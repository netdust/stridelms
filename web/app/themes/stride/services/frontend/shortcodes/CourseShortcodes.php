<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * Course-related shortcodes.
 *
 * - [stride_course_catalog] - Course listing/archive
 * - [stride_course_sidebar] - Course action sidebar
 */
final class CourseShortcodes
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
        add_shortcode('stride_course_catalog', [$this, 'renderCourseCatalog']);
        add_shortcode('stride_course_sidebar', [$this, 'renderCourseSidebar']);
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
}
