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
        'primary' => 'Hoofdmenu',
        'footer' => 'Footermenu',
        'user_dashboard' => 'Dashboardmenu',
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
    // Note: Business logic services are in stride-core mu-plugin
    // Frontend services live in the theme (presentation layer)
    'services' => [
        'core' => [
            // Business logic services moved to stride-core mu-plugin
        ],
        'handlers' => [],
        'admin' => [],
        'conditional' => [],
        'auto_discover' => false,
        'discovery_paths' => [
            __DIR__ . '/services',
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
            'code_prefix' => 'VAD',
            'default_validity_days' => 365,
            'types' => [
                'action' => ['label' => 'Actie', 'discount_type' => 'percentage'],
                'member' => ['label' => 'Lid', 'discount_type' => 'full'],
                'speaker' => ['label' => 'Spreker', 'discount_type' => 'full'],
                'day' => ['label' => 'Dagvoucher', 'discount_type' => 'full'],
            ],
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
