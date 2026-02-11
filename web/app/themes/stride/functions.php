<?php

/**
 * Stride LMS - Theme Entry Point
 *
 * Clean, predictable bootstrap with clear lifecycle phases
 *
 * Lifecycle:
 * 1. Load configuration (immediate)
 * 2. Register services in DI container (immediate)
 * 3. Boot core services (after_setup_theme:5)
 * 4. Setup theme (after_setup_theme:10)
 * 5. Boot feature services (after_setup_theme:15)
 *
 * @package stride
 * @author Stefan Vandermeulen
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

// ========================================
// BOOTSTRAP
// ========================================

// Load configuration
$config = require __DIR__ . '/theme-config.php';

// Create and register bootstrap instance (from ntdst-core)
$bootstrap = new NTDST_Bootstrap($config);
$bootstrap->register();

// Store bootstrap globally for access
ntdst_set(NTDST_Bootstrap::class, fn() => $bootstrap);

// ========================================
// LIFECYCLE HOOKS
// ========================================

/**
 * Phase 1: Boot Core Services
 * Priority: 5 (early)
 */
add_action('after_setup_theme', function () use ($bootstrap) {
    $bootstrap->bootCore();
}, 5);

/**
 * Phase 2: Setup Theme
 * Priority: 10 (default)
 */
add_action('after_setup_theme', function () use ($config) {
    // Initialize theme with configuration
    $theme = new NTDST_Theme($config);

    // ========================================
    // STRIDE LMS CUSTOMIZATIONS
    // ========================================

    $theme
        // Add custom body classes
        ->filter('body_class', function ($classes) {
            if (is_front_page()) {
                $classes[] = 'stride-homepage';
            }

            // Add logged-in user context
            if (is_user_logged_in()) {
                $classes[] = 'stride-user-logged-in';
            }

            return $classes;
        })

        // Set custom template path
        ->templatePath(get_stylesheet_directory() . '/templates');

    // Allow other code to customize theme
    do_action('stride/theme_configured', $theme);

}, 10);

/**
 * Phase 3: Boot Feature Services
 * Priority: 15 (late)
 */
add_action('after_setup_theme', function () use ($bootstrap) {
    $bootstrap->bootFeatures();
}, 15);

// ========================================
// HELPER FUNCTIONS
// ========================================

/**
 * Get the theme instance
 *
 * @return NTDST_Theme|null
 */
function stride_theme()
{
    return ntdst_get(NTDST_Theme::class);
}

/**
 * Get the bootstrap instance
 *
 * @return NTDST_Bootstrap|null
 */
function stride_bootstrap()
{
    return ntdst_get(NTDST_Bootstrap::class);
}

/**
 * Get a service from the container
 *
 * @param string $class Service class name
 * @return mixed
 */
function stride_service(string $class)
{
    return ntdst_get($class);
}
