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

            // Phase 1: Core Services
            'stride\\services\\core\\CourseService',
            'stride\\services\\core\\SubscriberService',
            'stride\\services\\core\\OrganizationService',

            // Phase 2: Enrollment Services
            'stride\\services\\enrollment\\EnrollmentService',
            'stride\\services\\enrollment\\FormSubmissionHandler',
            'stride\\services\\enrollment\\FluentFormsHelper',

            // Phase 3: Invoicing Services
            'stride\\services\\invoicing\\QuoteService',
            'stride\\services\\invoicing\\VATValidator',
            'stride\\services\\invoicing\\QuoteUpdateHandler',
            'stride\\services\\invoicing\\QuotePDFGenerator',
            'stride\\services\\invoicing\\ExactOnlineExporter',
        ],

        // Admin-only services
        'admin' => [
            // 'stride\\services\\admin\\AdminDashboardService',
        ],

        // Conditional services
        'conditional' => [
            // FluentCRM integration (optional bridge service)
            'fluentcrm' => [
                'service' => 'stride\\services\\integrations\\FluentCRMService',
                'condition' => fn() => defined('FLUENTCRM'),
            ],

            // LearnDash integration (optional bridge service)
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
            // Quote numbering
            'prefix' => 'STRIDE',
            'tax_rate' => 21.0,
            'currency' => 'EUR',
            'valid_days' => 30, // Quote validity period

            // Company details for PDF generation
            'company' => [
                'name' => 'Stride LMS',
                'address' => '', // Configure in production
                'city' => '',
                'postal_code' => '',
                'country' => 'Belgium',
                'vat' => '', // e.g., BE0123456789
                'email' => '', // e.g., info@example.com
                'phone' => '',
                'bank_account' => '', // IBAN for payment instructions
            ],

            // PDF storage
            'pdf_storage' => 'private', // 'private' (protected) or 'uploads'
        ],
    ],
];
