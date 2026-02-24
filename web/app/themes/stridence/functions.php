<?php
/**
 * Stridence Theme - Functions
 *
 * Modern LMS theme for Stride using Tailwind + Alpine + Vite.
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

// ========================================
// CONSTANTS
// ========================================

define('STRIDENCE_VERSION', '1.0.0');
define('STRIDENCE_DIR', get_stylesheet_directory());
define('STRIDENCE_URI', get_stylesheet_directory_uri());

// ========================================
// LOAD HELPERS
// ========================================

require_once STRIDENCE_DIR . '/helpers/icons.php';
require_once STRIDENCE_DIR . '/helpers/formatting.php';

// ========================================
// BOOTSTRAP (NTDST Core Integration)
// ========================================

$config = require STRIDENCE_DIR . '/theme-config.php';

// Create and register bootstrap instance (from ntdst-core)
if (class_exists('NTDST_Bootstrap')) {
    $bootstrap = new NTDST_Bootstrap($config);
    $bootstrap->register();
    ntdst_set(NTDST_Bootstrap::class, fn() => $bootstrap);

    // Boot core services (priority 5)
    add_action('after_setup_theme', fn() => $bootstrap->bootCore(), 5);

    // Boot feature services (priority 15)
    add_action('after_setup_theme', fn() => $bootstrap->bootFeatures(), 15);
}

// ========================================
// THEME SETUP
// ========================================

add_action('after_setup_theme', function () {
    // Content width
    global $content_width;
    if (!isset($content_width)) {
        $content_width = 1280;
    }

    // Theme support
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);
    add_theme_support('custom-logo', [
        'height' => 100,
        'width' => 400,
        'flex-height' => true,
        'flex-width' => true,
    ]);
    add_theme_support('responsive-embeds');

    // Navigation menus
    register_nav_menus([
        'primary' => __('Hoofdmenu', 'stridence'),
        'footer' => __('Footermenu', 'stridence'),
    ]);
}, 10);

// ========================================
// VITE ASSETS
// ========================================

/**
 * Check if Vite dev server is running
 */
function stridence_is_vite_dev(): bool
{
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return false;
    }

    // In production, manifest exists
    $manifest_path = STRIDENCE_DIR . '/dist/.vite/manifest.json';
    return !file_exists($manifest_path);
}

/**
 * Get Vite manifest
 */
function stridence_get_manifest(): ?array
{
    static $manifest = null;

    if ($manifest === null) {
        $manifest_path = STRIDENCE_DIR . '/dist/.vite/manifest.json';
        if (file_exists($manifest_path)) {
            $manifest = json_decode(file_get_contents($manifest_path), true);
        } else {
            $manifest = [];
        }
    }

    return $manifest;
}

/**
 * Enqueue theme assets
 */
add_action('wp_enqueue_scripts', function () {
    if (stridence_is_vite_dev()) {
        // Development: Load from Vite dev server
        add_action('wp_head', function () {
            echo '<script type="module" src="http://localhost:5173/@vite/client"></script>';
            echo '<script type="module" src="http://localhost:5173/main.js"></script>';
        }, 1);
    } else {
        // Production: Load built assets
        $manifest = stridence_get_manifest();

        if (isset($manifest['main.js'])) {
            $entry = $manifest['main.js'];

            // CSS
            if (!empty($entry['css'])) {
                foreach ($entry['css'] as $index => $css_file) {
                    wp_enqueue_style(
                        'stridence-' . $index,
                        STRIDENCE_URI . '/dist/' . $css_file,
                        [],
                        STRIDENCE_VERSION
                    );
                }
            }

            // JS
            wp_enqueue_script(
                'stridence-main',
                STRIDENCE_URI . '/dist/' . $entry['file'],
                [],
                STRIDENCE_VERSION,
                true
            );

            // Add module type
            add_filter('script_loader_tag', function ($tag, $handle) {
                if ($handle === 'stridence-main') {
                    return str_replace(' src', ' type="module" src', $tag);
                }
                return $tag;
            }, 10, 2);
        }
    }

    // Localize script with config
    wp_add_inline_script('stridence-main', 'window.strideConfig = ' . wp_json_encode([
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('stride_frontend'),
        'restNonce' => wp_create_nonce('wp_rest'),
        'debug' => defined('WP_DEBUG') && WP_DEBUG,
        'strings' => [
            'saving' => __('Opslaan...', 'stridence'),
            'saved' => __('Opgeslagen', 'stridence'),
            'error' => __('Er is een fout opgetreden', 'stridence'),
            'confirm' => __('Weet je het zeker?', 'stridence'),
        ],
    ]) . ';', 'before');
}, 10);

// ========================================
// PWA META TAGS
// ========================================

add_action('wp_head', function () {
    ?>
    <meta name="theme-color" content="#1d4e89">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Stride">
    <?php
}, 1);

// ========================================
// CLEANUP
// ========================================

// Remove emoji scripts
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

// No-cache headers in development
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('send_headers', function () {
        if (!is_admin()) {
            nocache_headers();
        }
    });
}

// ========================================
// NAVIGATION WALKERS
// ========================================

/**
 * Desktop navigation walker
 */
class Stridence_Nav_Walker extends Walker_Nav_Menu
{
    public function start_lvl(&$output, $depth = 0, $args = null): void
    {
        $output .= '<div class="absolute left-0 mt-2 w-48 bg-surface-card rounded-lg shadow-overlay border border-border py-1 hidden group-hover:block z-50"><ul>';
    }

    public function end_lvl(&$output, $depth = 0, $args = null): void
    {
        $output .= '</ul></div>';
    }

    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0): void
    {
        $classes = empty($item->classes) ? [] : (array) $item->classes;
        $has_children = in_array('menu-item-has-children', $classes);

        $li_classes = $has_children ? 'relative group' : '';
        $output .= '<li class="' . esc_attr($li_classes) . '">';

        $link_classes = 'nav-link';
        if (in_array('current-menu-item', $classes) || in_array('current-menu-ancestor', $classes)) {
            $link_classes .= ' nav-link-active';
        }

        $atts = [
            'href' => !empty($item->url) ? esc_url($item->url) : '',
            'class' => $link_classes,
        ];

        if (!empty($item->target)) {
            $atts['target'] = $item->target;
        }

        $attributes = '';
        foreach ($atts as $attr => $value) {
            if (!empty($value)) {
                $attributes .= ' ' . $attr . '="' . esc_attr($value) . '"';
            }
        }

        $title = apply_filters('the_title', $item->title, $item->ID);
        $output .= '<a' . $attributes . '>' . esc_html($title);

        if ($has_children && $depth === 0) {
            $output .= ' ' . stridence_icon('chevron-down', 'w-3 h-3 inline-block');
        }

        $output .= '</a>';
    }

    public function end_el(&$output, $item, $depth = 0, $args = null): void
    {
        $output .= '</li>';
    }
}

/**
 * Mobile navigation walker
 */
class Stridence_Mobile_Nav_Walker extends Walker_Nav_Menu
{
    public function start_lvl(&$output, $depth = 0, $args = null): void
    {
        $output .= '<ul class="pl-4 mt-1 space-y-1">';
    }

    public function end_lvl(&$output, $depth = 0, $args = null): void
    {
        $output .= '</ul>';
    }

    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0): void
    {
        $classes = empty($item->classes) ? [] : (array) $item->classes;

        $output .= '<li>';

        $link_classes = 'block nav-link';
        if (in_array('current-menu-item', $classes)) {
            $link_classes .= ' nav-link-active';
        }

        $atts = [
            'href' => !empty($item->url) ? esc_url($item->url) : '',
            'class' => $link_classes,
        ];

        $attributes = '';
        foreach ($atts as $attr => $value) {
            if (!empty($value)) {
                $attributes .= ' ' . $attr . '="' . esc_attr($value) . '"';
            }
        }

        $title = apply_filters('the_title', $item->title, $item->ID);
        $output .= '<a' . $attributes . '>' . esc_html($title) . '</a>';
    }

    public function end_el(&$output, $item, $depth = 0, $args = null): void
    {
        $output .= '</li>';
    }
}

/**
 * Fallback menu for desktop
 */
function stridence_fallback_menu(): void
{
    echo '<ul class="flex items-center gap-1">';
    echo '<li><a href="' . esc_url(home_url('/')) . '" class="nav-link">' . esc_html__('Home', 'stridence') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/cursussen/')) . '" class="nav-link">' . esc_html__('Cursussen', 'stridence') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/trajecten/')) . '" class="nav-link">' . esc_html__('Trajecten', 'stridence') . '</a></li>';
    echo '</ul>';
}

/**
 * Fallback menu for mobile
 */
function stridence_fallback_menu_mobile(): void
{
    echo '<ul class="space-y-1">';
    echo '<li><a href="' . esc_url(home_url('/')) . '" class="block nav-link">' . esc_html__('Home', 'stridence') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/cursussen/')) . '" class="block nav-link">' . esc_html__('Cursussen', 'stridence') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/trajecten/')) . '" class="block nav-link">' . esc_html__('Trajecten', 'stridence') . '</a></li>';
    echo '</ul>';
}

// ========================================
// BODY CLASSES
// ========================================

add_filter('body_class', function ($classes) {
    if (is_front_page()) {
        $classes[] = 'stridence-homepage';
    }

    if (is_user_logged_in()) {
        $classes[] = 'stridence-logged-in';
    }

    return $classes;
});

// ========================================
// HELPER FUNCTIONS
// ========================================

/**
 * Get service from container
 */
function stride_service(string $class)
{
    if (function_exists('ntdst_get')) {
        return ntdst_get($class);
    }
    return null;
}

/**
 * Get the theme instance
 */
function stride_theme()
{
    return ntdst_get(NTDST_Theme::class);
}

/**
 * Get the bootstrap instance
 */
function stride_bootstrap()
{
    return ntdst_get(NTDST_Bootstrap::class);
}
