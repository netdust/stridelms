<?php
/**
 * Dashboard Pages Setup Script
 *
 * Creates the necessary WordPress pages for the user dashboard.
 *
 * Run via WP-CLI:
 * wp eval-file web/app/themes/stride/setup/create-dashboard-pages.php
 *
 * Or include in theme activation hook.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

/**
 * Create dashboard pages with shortcodes
 */
function stride_create_dashboard_pages(): void
{
    $pages = [
        // Main dashboard page
        [
            'post_title' => 'Mijn Account',
            'post_name' => 'mijn-account',
            'post_content' => '[stride_dashboard]',
            'children' => [
                [
                    'post_title' => 'Mijn Cursussen',
                    'post_name' => 'cursussen',
                    'post_content' => '[stride_my_courses]',
                ],
                [
                    'post_title' => 'Mijn Trajecten',
                    'post_name' => 'trajecten',
                    'post_content' => '[stride_my_trajectories]',
                ],
                [
                    'post_title' => 'Traject',
                    'post_name' => 'traject',
                    'post_content' => '[stride_trajectory]',
                ],
                [
                    'post_title' => 'Mijn Offertes',
                    'post_name' => 'offertes',
                    'post_content' => '[stride_my_quotes]',
                ],
                [
                    'post_title' => 'Mijn Profiel',
                    'post_name' => 'profiel',
                    'post_content' => '[stride_my_profile]',
                ],
                [
                    'post_title' => 'Mijn Agenda',
                    'post_name' => 'agenda',
                    'post_content' => '[stride_my_calendar]',
                ],
            ],
        ],

        // Course catalog (public)
        [
            'post_title' => 'Cursussen',
            'post_name' => 'cursussen',
            'post_content' => '[stride_course_catalog]',
        ],
    ];

    foreach ($pages as $page) {
        stride_create_page_recursive($page);
    }

    WP_CLI::success('Dashboard pages created successfully!');
}

/**
 * Create a page and its children recursively
 */
function stride_create_page_recursive(array $page, int $parentId = 0): int
{
    // Check if page exists
    $existing = get_page_by_path($page['post_name']);

    if ($existing) {
        WP_CLI::log('Page already exists: ' . $page['post_title']);
        $pageId = $existing->ID;
    } else {
        // Create page
        $pageId = wp_insert_post([
            'post_title' => $page['post_title'],
            'post_name' => $page['post_name'],
            'post_content' => $page['post_content'],
            'post_status' => 'publish',
            'post_type' => 'page',
            'post_parent' => $parentId,
        ]);

        if (is_wp_error($pageId)) {
            WP_CLI::warning('Failed to create page: ' . $page['post_title']);
            return 0;
        }

        WP_CLI::log('Created page: ' . $page['post_title'] . ' (ID: ' . $pageId . ')');
    }

    // Create child pages
    if (!empty($page['children'])) {
        foreach ($page['children'] as $child) {
            stride_create_page_recursive($child, $pageId);
        }
    }

    return $pageId;
}

// Run if WP-CLI context
if (defined('WP_CLI') && WP_CLI) {
    stride_create_dashboard_pages();
}
