<?php
/**
 * Stridence - Modern LMS Theme
 *
 * Tailwind CSS + Alpine.js + Vite stack
 * Loads Stride LMS services via NTDST_Bootstrap
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

// ========================================
// STRIDE BOOTSTRAP INTEGRATION
// ========================================

$strideThemePath = get_theme_root() . '/stride';
$config = require $strideThemePath . '/theme-config.php';
$config['theme']['textdomain'] = 'stridence';

$bootstrap = new NTDST_Bootstrap($config);
$bootstrap->register();
ntdst_set(NTDST_Bootstrap::class, fn() => $bootstrap);

// Lifecycle hooks
add_action('after_setup_theme', fn() => $bootstrap->bootCore(), 5);
add_action('after_setup_theme', function () use ($config) {
    register_nav_menus([
        'primary' => __('Hoofdmenu', 'stridence'),
        'footer' => __('Footermenu', 'stridence'),
    ]);
    $theme = new NTDST_Theme($config);
    $theme->filter('body_class', fn($classes) => array_merge($classes, ['stridence']));
    $theme->templatePath(get_stylesheet_directory() . '/templates');
}, 10);
add_action('after_setup_theme', fn() => $bootstrap->bootFeatures(), 15);

// LearnDash customizer
add_action('after_setup_theme', function () use ($strideThemePath) {
    require_once $strideThemePath . '/services/frontend/LearnDashCustomizer.php';
    new \stride\services\frontend\LearnDashCustomizer();
}, 20);

// ========================================
// VITE ASSET LOADING
// ========================================

/**
 * Check if Vite dev server is running
 */
function stridence_is_vite_dev(): bool
{
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return false;
    }

    static $is_dev = null;
    if ($is_dev !== null) {
        return $is_dev;
    }

    $vite_server = 'http://localhost:5173';
    $response = @file_get_contents($vite_server . '/@vite/client', false, stream_context_create([
        'http' => ['timeout' => 0.5]
    ]));

    $is_dev = $response !== false;
    return $is_dev;
}

/**
 * Get Vite asset URL
 */
function stridence_vite_asset(string $entry): array
{
    $theme_uri = get_stylesheet_directory_uri();
    $theme_dir = get_stylesheet_directory();

    // Development mode
    if (stridence_is_vite_dev()) {
        return [
            'js' => "http://localhost:5173/src/{$entry}",
            'css' => null, // CSS is injected by Vite in dev
        ];
    }

    // Production mode - read manifest
    $manifest_path = $theme_dir . '/dist/.vite/manifest.json';
    if (!file_exists($manifest_path)) {
        return ['js' => null, 'css' => null];
    }

    $manifest = json_decode(file_get_contents($manifest_path), true);
    $entry_key = "src/{$entry}";

    if (!isset($manifest[$entry_key])) {
        return ['js' => null, 'css' => null];
    }

    $entry_data = $manifest[$entry_key];

    return [
        'js' => $theme_uri . '/dist/' . $entry_data['file'],
        'css' => isset($entry_data['css'][0]) ? $theme_uri . '/dist/' . $entry_data['css'][0] : null,
    ];
}

/**
 * Enqueue Vite assets
 */
add_action('wp_enqueue_scripts', function () {
    // Dequeue everything we don't need
    wp_dequeue_style('uikit');
    wp_dequeue_style('stride-font-inter');
    wp_dequeue_style('stride-css');
    wp_dequeue_script('uikit');
    wp_dequeue_script('uikit-icons');
    wp_dequeue_script('stride');

    // Dequeue Kadence
    wp_dequeue_style('kadence-global');
    wp_dequeue_style('kadence-header');
    wp_dequeue_style('kadence-content');
    wp_dequeue_style('kadence-footer');
    wp_dequeue_script('kadence-navigation');

    // Dequeue LearnDash bloat on non-course pages
    if (!is_singular(['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'])) {
        // LearnDash styles
        wp_dequeue_style('learndash-front');
        wp_dequeue_style('learndash-css');
        wp_dequeue_style('learndash-course-grid');
        wp_dequeue_style('learndash-course-grid-skin-grid');
        wp_dequeue_style('learndash-course-grid-pagination');
        wp_dequeue_style('learndash-course-grid-filter');
        wp_dequeue_style('learndash-course-grid-card-grid-1');
        wp_dequeue_style('learndash_quiz_front_css');
        wp_dequeue_style('learndash_lesson_video');
        wp_dequeue_style('learndash-admin-bar');

        // LearnDash scripts
        wp_dequeue_script('learndash-front');
        wp_dequeue_script('learndash-js');
        wp_dequeue_script('learndash-main');
        wp_dequeue_script('learndash-breakpoints');
        wp_dequeue_script('learndash-course-grid-skin-grid');

        // TinCanny
        wp_dequeue_style('datatables-styles');
        wp_dequeue_style('uotc-group-quiz-report');
        wp_dequeue_style('wp-h5p-xapi');
        wp_dequeue_style('snc-style');
        wp_dequeue_script('tc_runtime');
        wp_dequeue_script('tc_vendors');
        wp_dequeue_script('wp-h5p-xapi');

        // Uncanny Toolkit
        wp_dequeue_style('uncannyowl-learndash-toolkit-free');
        wp_dequeue_script('uncannyowl-learndash-toolkit-free');
    }

    $assets = stridence_vite_asset('main.js');

    // Development: load Vite client + module
    if (stridence_is_vite_dev()) {
        // Vite client for HMR
        wp_enqueue_script('vite-client', 'http://localhost:5173/@vite/client', [], null);
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'vite-client') {
                return str_replace('<script', '<script type="module"', $tag);
            }
            return $tag;
        }, 10, 2);

        // Main entry
        wp_enqueue_script('stridence-main', $assets['js'], [], null);
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'stridence-main') {
                return str_replace('<script', '<script type="module"', $tag);
            }
            return $tag;
        }, 10, 2);
    } else {
        // Production: load built assets
        if ($assets['css']) {
            wp_enqueue_style('stridence-main', $assets['css'], [], null);
        }
        if ($assets['js']) {
            wp_enqueue_script('stridence-main', $assets['js'], [], null, true);
            add_filter('script_loader_tag', function ($tag, $handle) {
                if ($handle === 'stridence-main') {
                    return str_replace('<script', '<script type="module"', $tag);
                }
                return $tag;
            }, 10, 2);
        }
    }

    // Localize config for Alpine/JS
    wp_localize_script(stridence_is_vite_dev() ? 'stridence-main' : 'stridence-main', 'stridence', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('stridence'),
        'home_url' => home_url('/'),
    ]);
}, 100);

// ========================================
// CLEANUP
// ========================================

remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'rsd_link');

// Remove speculative loading rules (WordPress 6.5+ bloat)
remove_action('wp_footer', 'wp_output_speculation_rules');
remove_action('wp_head', 'wp_output_speculation_rules');

// Kill dashicons on frontend (admin bar will still work)
add_action('wp_enqueue_scripts', function () {
    if (!is_admin() && !is_customize_preview()) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
});

// Aggressive cleanup at wp_print_scripts/styles
add_action('wp_print_scripts', function () {
    wp_dequeue_script('kadence-navigation');
    wp_deregister_script('kadence-navigation');

    if (!is_singular(['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'])) {
        // LearnDash
        wp_dequeue_script('learndash-js');
        wp_dequeue_script('learndash-front');
        wp_dequeue_script('learndash-main');
        wp_dequeue_script('learndash-breakpoints');
        wp_deregister_script('learndash-js');

        // TinCanny
        wp_dequeue_script('tc_runtime');
        wp_dequeue_script('tc_vendors');
        wp_deregister_script('tc_runtime');
        wp_deregister_script('tc_vendors');

        // jQuery - only if not needed
        if (!is_user_logged_in()) {
            wp_dequeue_script('jquery');
            wp_dequeue_script('jquery-core');
            wp_dequeue_script('jquery-migrate');
        }
    }
}, 999);

add_action('wp_print_styles', function () {
    // Kadence
    wp_dequeue_style('kadence-global');
    wp_dequeue_style('kadence-header');
    wp_dequeue_style('kadence-content');
    wp_dequeue_style('kadence-footer');

    if (!is_singular(['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'])) {
        // LearnDash
        wp_dequeue_style('learndash-css');
        wp_dequeue_style('learndash-front');
        wp_dequeue_style('jquery-dropdown-css');
        wp_deregister_style('learndash-css');

        // TinCanny
        wp_dequeue_style('snc-style');
        wp_deregister_style('snc-style');
    }
}, 999);

// ========================================
// THEME SUPPORT
// ========================================

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo', ['height' => 60, 'width' => 200, 'flex-height' => true, 'flex-width' => true]);
    add_theme_support('learndash-course-grid');
    load_theme_textdomain('stridence', get_stylesheet_directory() . '/languages');
});

// ========================================
// HELPER FUNCTIONS
// ========================================

function stride_theme() { return ntdst_get(NTDST_Theme::class); }
function stride_bootstrap() { return ntdst_get(NTDST_Bootstrap::class); }
function stride_service(string $class) { return ntdst_get($class); }

/**
 * Simple icon helper (inline SVG)
 */
function stridence_icon(string $name, string $class = '', int $size = 24): void
{
    $icons = [
        'menu' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>',
        'x' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>',
        'chevron-right' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>',
        'arrow-right' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>',
        'calendar' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>',
        'clock' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'map-pin' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>',
        'book' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
        'laptop' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
        'gift' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v13m0-13V6a2 2 0 112 2h-2zm0 0V5.5A2.5 2.5 0 109.5 8H12zm-7 4h14M5 12a2 2 0 110-4h14a2 2 0 110 4M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/>',
        'check' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>',
        'check-circle' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        'award' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"/>',
        'academic-cap' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 14l9-5-9-5-9 5 9 5zm0 0l6.16-3.422a12.083 12.083 0 01.665 6.479A11.952 11.952 0 0012 20.055a11.952 11.952 0 00-6.824-2.998 12.078 12.078 0 01.665-6.479L12 14zm-4 6v-7.5l4-2.222"/>',
        'user' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
    ];

    $path = $icons[$name] ?? '';
    $class_attr = $class ? " class=\"{$class}\"" : '';

    echo "<svg{$class_attr} width=\"{$size}\" height=\"{$size}\" viewBox=\"0 0 24 24\" fill=\"none\" stroke=\"currentColor\" xmlns=\"http://www.w3.org/2000/svg\">{$path}</svg>";
}
