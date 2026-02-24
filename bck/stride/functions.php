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
// PWA MANIFEST & META TAGS
// ========================================

/**
 * Add PWA manifest and related meta tags
 * Priority: 1 (very early in head)
 */
add_action('wp_head', function () {
    ?>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#2D3E50">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Stride">
    <link rel="apple-touch-icon" href="<?php echo esc_url(get_stylesheet_directory_uri()); ?>/assets/img/icon-180.png">
    <?php
}, 1);

// ========================================
// DEVELOPMENT: DISABLE BROWSER CACHING
// ========================================

/**
 * Send no-cache headers in development environment
 */
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('send_headers', function () {
        if (!is_admin()) {
            nocache_headers();
        }
    });
}

// ========================================
// REMOVE WORDPRESS EMOJI SCRIPT
// ========================================

/**
 * Remove WordPress emoji detection script (reduces inline JS)
 * Modern browsers handle emojis natively, so this is rarely needed.
 */
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('admin_print_scripts', 'print_emoji_detection_script');
remove_action('admin_print_styles', 'print_emoji_styles');

// ========================================
// FRONTEND ASSETS
// ========================================

/**
 * Enqueue frontend styles and scripts
 *
 * NOTE: This is a minimal setup. Frontend assets will be added
 * when the frontend is properly implemented.
 */
add_action('wp_enqueue_scripts', function () {
    // Inter font from Google Fonts
    wp_enqueue_style(
        'stride-font-inter',
        'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
        [],
        null
    );

    // UIkit CSS
    wp_enqueue_style(
        'uikit',
        'https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/css/uikit.min.css',
        ['stride-font-inter'],
        '3.21.6'
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

    // Stride custom JS (namespace setup only)
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

/**
 * Enqueue Stride CSS with high priority to load AFTER LearnDash
 * Priority 99 ensures it loads last, overriding any plugin styles
 */
add_action('wp_enqueue_scripts', function () {
    // Stride Design System CSS - load after all other styles
    // Use filemtime for cache busting during development
    $css_file = get_stylesheet_directory() . '/assets/css/stride.css';
    $version = file_exists($css_file) ? filemtime($css_file) : wp_get_theme()->get('Version');

    wp_enqueue_style(
        'stride-css',
        get_stylesheet_directory_uri() . '/assets/css/stride.css',
        ['uikit'],
        $version
    );
}, 99);

/**
 * Enqueue LearnDash Focus Mode overrides
 *
 * Only loads on LearnDash content pages (courses, lessons, topics, quizzes)
 * to apply Stride design system styling to Focus Mode.
 */
add_action('wp_enqueue_scripts', function () {
    // Only load on LearnDash content
    if (is_singular(['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'])) {
        wp_enqueue_style(
            'stride-focus-mode',
            get_stylesheet_directory_uri() . '/assets/css/focus-mode.css',
            ['stride-css'],
            wp_get_theme()->get('Version')
        );
    }
}, 20); // After other styles

// ========================================
// LEARNDASH CUSTOMIZATIONS
// ========================================

/**
 * Initialize LearnDash customizer
 *
 * Applies Stride styling to LearnDash templates and adds
 * editions/sessions section for classroom courses.
 */
add_action('after_setup_theme', function () {
    require_once get_template_directory() . '/services/frontend/LearnDashCustomizer.php';
    new \stride\services\frontend\LearnDashCustomizer();
}, 20);

// ========================================
// THEME ACTIVATION
// ========================================

/**
 * Theme activation - flush rewrite rules and create pages
 * Note: Table creation is now handled by stride-core plugin
 */
add_action('after_switch_theme', function () {
    // Flush rewrite rules for CPTs
    flush_rewrite_rules();

    // Create catalog pages
    stride_create_catalog_pages();

    // Setup primary menu
    stride_setup_primary_menu();
});

/**
 * Create catalog pages if they don't exist
 */
function stride_create_catalog_pages(): void
{
    $pages = [
        'cursussen' => [
            'title' => __('Cursussen', 'stride'),
            'content' => '[stride_course_catalog]',
        ],
        'trajecten' => [
            'title' => __('Trajecten', 'stride'),
            'content' => '[stride_trajectory_catalog]',
        ],
        'mijn-account' => [
            'title' => __('Mijn Account', 'stride'),
            'content' => '[stride_dashboard]',
        ],
    ];

    foreach ($pages as $slug => $page) {
        // Check if page exists
        $existing = get_page_by_path($slug);
        if (!$existing) {
            wp_insert_post([
                'post_title' => $page['title'],
                'post_name' => $slug,
                'post_content' => $page['content'],
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
        }
    }
}

/**
 * Setup primary menu with default items
 */
function stride_setup_primary_menu(): void
{
    // Check if primary menu exists
    $menu_name = 'Hoofdmenu';
    $menu_exists = wp_get_nav_menu_object($menu_name);

    if (!$menu_exists) {
        // Create the menu
        $menu_id = wp_create_nav_menu($menu_name);

        if (is_wp_error($menu_id)) {
            return;
        }

        // Add menu items
        $pages = [
            'cursussen' => __('Cursussen', 'stride'),
            'trajecten' => __('Trajecten', 'stride'),
        ];

        $position = 1;
        foreach ($pages as $slug => $title) {
            $page = get_page_by_path($slug);
            if ($page) {
                wp_update_nav_menu_item($menu_id, 0, [
                    'menu-item-title' => $title,
                    'menu-item-object' => 'page',
                    'menu-item-object-id' => $page->ID,
                    'menu-item-type' => 'post_type',
                    'menu-item-status' => 'publish',
                    'menu-item-position' => $position++,
                ]);
            }
        }

        // Assign menu to primary location
        $locations = get_theme_mod('nav_menu_locations', []);
        $locations['primary'] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);
    }
}

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
    echo '<li><a href="' . esc_url(home_url('/trajecten/')) . '">' . esc_html__('Trajecten', 'stride') . '</a></li>';
    echo '</ul>';
}

/**
 * Fallback menu items for mobile nav
 */
function stride_fallback_menu_items(): void
{
    echo '<li><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Home', 'stride') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/cursussen/')) . '">' . esc_html__('Cursussen', 'stride') . '</a></li>';
    echo '<li><a href="' . esc_url(home_url('/trajecten/')) . '">' . esc_html__('Trajecten', 'stride') . '</a></li>';
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

