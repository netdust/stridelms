<?php

/**
 * Stride LMS - Theme Configuration
 *
 * All configuration in one place - no logic, just data
 * This is the single source of truth for theme settings
 *
 * @package stride
 */

defined('ABSPATH') || exit;

return [
    // ========================================
    // THEME METADATA
    // ========================================
    'theme' => [
        'textdomain' => 'stride',
        'version' => '1.0.0',
        'content_width' => 1200,
    ],

    // ========================================
    // WORDPRESS FEATURES
    // ========================================
    'support' => [
        'title-tag' => true,
        'post-thumbnails' => true,
        'automatic-feed-links' => true,
        'customize-selective-refresh-widgets' => true,
        'html5' => ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script'],
        'custom-logo' => [
            'height' => 100,
            'width' => 400,
            'flex-height' => true,
            'flex-width' => true,
        ],
        'responsive-embeds' => true,
    ],

    // ========================================
    // IMAGE SIZES
    // ========================================
    'image_sizes' => [
        'stride_thumbnail' => [150, 150, true, 'Thumbnail'],
        'stride_medium' => [300, 300, false, 'Medium'],
        'stride_large' => [1024, 1024, false, 'Large'],
        'stride_course_card' => [400, 225, true, 'Course Card'],
    ],

    // ========================================
    // NAVIGATION MENUS
    // ========================================
    'menus' => [
        'primary' => 'Primary Menu',
        'footer' => 'Footer Menu',
        'user_dashboard' => 'User Dashboard Menu',
    ],

    // ========================================
    // SIDEBAR WIDGET AREAS
    // Note: Translation handled at registration time, not here
    // ========================================
    'sidebars' => [
        [
            'name' => 'Main Sidebar',
            'id' => 'sidebar-main',
            'description' => 'Main sidebar area',
        ],
        [
            'name' => 'Dashboard Sidebar',
            'id' => 'sidebar-dashboard',
            'description' => 'User dashboard sidebar',
        ],
    ],

    // ========================================
    // EXCERPT SETTINGS
    // ========================================
    'excerpt' => [
        'length' => 55,
        'more' => ' <a href="%s">Read More</a>',
    ],

    // ========================================
    // ASSETS (Scripts & Styles)
    // ========================================
    'assets' => [
        'scripts' => [],
        'styles' => [],
    ],

    // ========================================
    // SERVICES CONFIGURATION
    // ========================================
    'services' => [
        // Core services (always loaded)
        'core' => [
            // Historical data bridge (loads early, priority 5)
            'stride\\services\\core\\HistoricalDataService',

            // Add core services here as they are built
            // 'stride\\services\\core\\CourseService',
            // 'stride\\services\\enrollment\\EnrollmentService',
        ],

        // Admin-only services
        'admin' => [
            // 'stride\\services\\admin\\AdminDashboardService',
        ],

        // Conditional services
        'conditional' => [
            // FluentCRM integration
            'fluentcrm' => [
                'service' => 'stride\\services\\integrations\\FluentCRMService',
                'condition' => fn() => defined('FLUENTCRM'),
            ],

            // LearnDash integration
            'learndash' => [
                'service' => 'stride\\services\\integrations\\LearnDashService',
                'condition' => fn() => defined('LEARNDASH_VERSION'),
            ],
        ],

        // Auto-discovery settings
        'auto_discover' => true,
        'discovery_paths' => [
            get_stylesheet_directory() . '/services',
        ],
    ],

    // ========================================
    // MODULE-SPECIFIC DEFAULTS
    // ========================================
    'modules' => [
        'security' => [
            'hide_wp_version' => true,
            'remove_generator_tags' => true,
            'disable_xmlrpc' => true,
            'generic_login_errors' => true,
        ],

        'performance' => [
            'post_revisions' => 5,
            'autosave_interval' => 300,
        ],

        // Stride LMS specific settings
        'enrollment' => [
            'require_confirmation' => true,
            'send_notifications' => true,
            'invoice_enabled' => true,
        ],

        'vouchers' => [
            'types' => ['action', 'member', 'speaker', 'day'],
            'auto_day_voucher' => true,
        ],

        'invoicing' => [
            'prefix' => 'STRIDE',
            'tax_rate' => 21.0,
            'currency' => 'EUR',
            'ogm_enabled' => true, // Belgian OGM payment reference
        ],
    ],
];
