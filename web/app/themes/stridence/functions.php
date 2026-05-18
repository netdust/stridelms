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
require_once STRIDENCE_DIR . '/helpers/templates.php';

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

// Hand theme setup to NTDST_Theme: title-tag, html5, custom-logo,
// menus, image sizes, sidebars, excerpt and content width are all
// driven by theme-config.php.
if (class_exists('NTDST_Theme')) {
    new NTDST_Theme([
        'textdomain'    => $config['theme']['textdomain'] ?? 'stridence',
        'content_width' => $config['theme']['content_width'] ?? 1280,
        'theme_support' => $config['support'] ?? [],
        'image_sizes'   => $config['image_sizes'] ?? [],
        'menus'         => $config['menus'] ?? [],
        'sidebars'      => $config['sidebars'] ?? [],
        'excerpt'       => $config['excerpt'] ?? ['length' => 55, 'more' => ''],
        'assets'        => $config['assets'] ?? ['styles' => [], 'scripts' => []],
    ]);
}

// ========================================
// HOOKS (grouped by concern, bound to NTDST_Theme)
// ========================================

require_once __DIR__ . '/services/frontend/hooks/NavigationHooks.php';
require_once __DIR__ . '/services/frontend/hooks/LearnDashHooks.php';
require_once __DIR__ . '/services/frontend/hooks/BrowserHooks.php';
require_once __DIR__ . '/services/frontend/hooks/AssetHooks.php';

if (class_exists('NTDST_Theme')) {
    $theme = ntdst_get(\NTDST_Theme::class);
    (new \stridence\services\frontend\hooks\NavigationHooks())->bind($theme);
    (new \stridence\services\frontend\hooks\LearnDashHooks())->bind($theme);
    (new \stridence\services\frontend\hooks\BrowserHooks())->bind($theme);
    (new \stridence\services\frontend\hooks\AssetHooks())->bind($theme);
}

/**
 * Check if a course is online based on stride_format taxonomy.
 * Free function — also called from single-sfwd-courses.php template.
 */
function stridence_is_online_course(int $course_id): bool
{
    $formats = get_the_terms($course_id, 'stride_format');
    if (!$formats || is_wp_error($formats)) {
        return false;
    }
    foreach ($formats as $fmt) {
        if (in_array($fmt->slug, ['online', 'webinar', 'e-learning'], true)) {
            return true;
        }
    }
    return false;
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
    echo '<li><a href="' . esc_url(home_url('/opleidingen/')) . '" class="nav-link">' . esc_html__('Opleidingen', 'stridence') . '</a></li>';
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
    echo '<li><a href="' . esc_url(home_url('/opleidingen/')) . '" class="block nav-link">' . esc_html__('Opleidingen', 'stridence') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/trajecten/')) . '" class="block nav-link">' . esc_html__('Trajecten', 'stridence') . '</a></li>';
    echo '</ul>';
}

// ========================================
// SHORTCODES
// ========================================

require_once __DIR__ . '/services/frontend/shortcodes/EnrollmentShortcode.php';
require_once __DIR__ . '/services/frontend/shortcodes/InterestShortcodes.php';
require_once __DIR__ . '/services/frontend/shortcodes/WaitlistShortcodes.php';
require_once __DIR__ . '/services/frontend/shortcodes/IntakeShortcodes.php';
require_once __DIR__ . '/services/frontend/shortcodes/EvaluationShortcodes.php';

(new \stridence\services\frontend\shortcodes\EnrollmentShortcode())->register();
(new \stridence\services\frontend\shortcodes\InterestShortcodes())->register();
(new \stridence\services\frontend\shortcodes\WaitlistShortcodes())->register();
(new \stridence\services\frontend\shortcodes\IntakeShortcodes())->register();
(new \stridence\services\frontend\shortcodes\EvaluationShortcodes())->register();

// ========================================
// HELPER FUNCTIONS
// ========================================

