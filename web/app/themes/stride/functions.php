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
// DEPENDENCY INJECTION BINDINGS
// ========================================

// Note: Adapter bindings are now registered in stride-coreloader.php
// Theme only registers presentation-layer bindings if needed

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
    // Register navigation menus
    register_nav_menus([
        'primary' => __('Hoofdmenu', 'stride'),
        'footer' => __('Footermenu', 'stride'),
    ]);

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
// FRONTEND ASSETS
// ========================================

/**
 * Enqueue frontend styles and scripts
 */
add_action('wp_enqueue_scripts', function () {
    // UIkit CSS
    wp_enqueue_style(
        'uikit',
        'https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/css/uikit.min.css',
        [],
        '3.21.6'
    );

    // Stride custom styles
    wp_enqueue_style(
        'stride',
        get_stylesheet_directory_uri() . '/assets/css/stride.css',
        ['uikit'],
        wp_get_theme()->get('Version')
    );

    // UIkit JS
    wp_enqueue_script(
        'uikit',
        'https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit.min.js',
        [],
        '3.21.6',
        true
    );

    // UIkit Icons
    wp_enqueue_script(
        'uikit-icons',
        'https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit-icons.min.js',
        ['uikit'],
        '3.21.6',
        true
    );

    // Stride custom JS
    wp_enqueue_script(
        'stride',
        get_stylesheet_directory_uri() . '/assets/js/stride.js',
        ['uikit', 'uikit-icons'],
        wp_get_theme()->get('Version'),
        true
    );

    // Localize script with config
    wp_localize_script('stride', 'strideConfig', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('stride_frontend'),
        'strings' => [
            'saving' => __('Opslaan...', 'stride'),
            'saved' => __('Opgeslagen', 'stride'),
            'error' => __('Er is een fout opgetreden', 'stride'),
            'confirm' => __('Weet je het zeker?', 'stride'),
        ],
    ]);
}, 10);

// ========================================
// THEME ACTIVATION
// ========================================

/**
 * Theme activation - flush rewrite rules
 * Note: Table creation is now handled by stride-core plugin
 */
add_action('after_switch_theme', function () {
    // Flush rewrite rules for CPTs
    flush_rewrite_rules();
});

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

// ========================================
// NAVIGATION HELPERS
// ========================================

/**
 * Fallback menu when no menu is assigned
 */
function stride_fallback_menu(): void
{
    echo '<ul class="uk-navbar-nav">';
    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'stride') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/cursussen/')) . '">' . esc_html__('Cursussen', 'stride') . '</a></li>';
    echo '</ul>';
}

/**
 * Fallback menu items for mobile nav
 */
function stride_fallback_menu_items(): void
{
    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'stride') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/cursussen/')) . '">' . esc_html__('Cursussen', 'stride') . '</a></li>';
}

/**
 * UIkit Nav Walker for navbar menus
 */
class Stride_UIkit_Nav_Walker extends Walker_Nav_Menu
{
    /**
     * Start level (submenu)
     */
    public function start_lvl(&$output, $depth = 0, $args = null): void
    {
        $output .= '<div class="uk-navbar-dropdown"><ul class="uk-nav uk-navbar-dropdown-nav">';
    }

    /**
     * End level
     */
    public function end_lvl(&$output, $depth = 0, $args = null): void
    {
        $output .= '</ul></div>';
    }

    /**
     * Start element (menu item)
     */
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0): void
    {
        $classes = empty($item->classes) ? [] : (array) $item->classes;
        $classes[] = 'menu-item-' . $item->ID;

        // Check if item has children
        $has_children = in_array('menu-item-has-children', $classes);

        // Active state
        if (in_array('current-menu-item', $classes) || in_array('current-menu-ancestor', $classes)) {
            $classes[] = 'uk-active';
        }

        $class_names = join(' ', array_filter($classes));
        $class_names = $class_names ? ' class="' . esc_attr($class_names) . '"' : '';

        $output .= '<li' . $class_names . '>';

        $atts = [];
        $atts['title'] = !empty($item->attr_title) ? $item->attr_title : '';
        $atts['target'] = !empty($item->target) ? $item->target : '';
        $atts['rel'] = !empty($item->xfn) ? $item->xfn : '';
        $atts['href'] = !empty($item->url) ? $item->url : '';

        $attributes = '';
        foreach ($atts as $attr => $value) {
            if (!empty($value)) {
                $value = ('href' === $attr) ? esc_url($value) : esc_attr($value);
                $attributes .= ' ' . $attr . '="' . $value . '"';
            }
        }

        $title = apply_filters('the_title', $item->title, $item->ID);

        $item_output = $args->before ?? '';
        $item_output .= '<a' . $attributes . '>';
        $item_output .= ($args->link_before ?? '') . $title . ($args->link_after ?? '');
        $item_output .= '</a>';
        $item_output .= $args->after ?? '';

        $output .= apply_filters('walker_nav_menu_start_el', $item_output, $item, $depth, $args);
    }

    /**
     * End element
     */
    public function end_el(&$output, $item, $depth = 0, $args = null): void
    {
        $output .= '</li>';
    }
}
