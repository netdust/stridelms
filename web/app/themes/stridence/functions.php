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
// MENU HIGHLIGHTING FOR DETAIL PAGES
// ========================================

/**
 * Highlight parent menu item on single course/trajectory/edition pages.
 *
 * WordPress doesn't auto-highlight menu items for custom post types
 * linked via custom URLs or unrelated pages. This filter maps:
 * - sfwd-courses (online) → "Online" page menu item
 * - sfwd-courses (classroom) → "Klassikaal" page menu item
 * - sfwd-lessons / sfwd-topic → same as parent course
 * - vad_trajectory → "Trajecten" custom menu item
 * - vad_edition → follows linked course format
 */
add_filter('wp_nav_menu_objects', function (array $items): array {
    if (is_admin()) {
        return $items;
    }

    $target_slug = stridence_get_active_menu_slug();
    if (!$target_slug) {
        return $items;
    }

    foreach ($items as $item) {
        $match = ($target_slug === 'trajecten')
            ? stridence_menu_item_matches_url($item, '/trajecten/')
            : stridence_menu_item_is_page($item, $target_slug);

        if ($match) {
            $item->classes[] = 'current-menu-item';
        }
    }

    return $items;
});

/**
 * Determine which menu item slug should be highlighted on detail pages.
 */
function stridence_get_active_menu_slug(): string
{
    if (is_singular('vad_trajectory')) {
        return 'trajecten';
    }

    $course_id = 0;

    if (is_singular('sfwd-courses')) {
        $course_id = get_the_ID();
    } elseif (is_singular(['sfwd-lessons', 'sfwd-topic']) && function_exists('learndash_get_course_id')) {
        $course_id = learndash_get_course_id(get_the_ID());
    } elseif (is_singular('vad_edition')) {
        $course_id = (int) get_post_meta(get_the_ID(), '_ntdst_course_id', true);
    }

    if (!$course_id) {
        return '';
    }

    return stridence_is_online_course($course_id) ? 'online' : 'klassikaal';
}

/**
 * Check if a course is online based on stride_format taxonomy.
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

/**
 * Check if menu item points to a page by slug.
 */
function stridence_menu_item_is_page(object $item, string $slug): bool
{
    if ($item->type === 'post_type' && $item->object === 'page') {
        $page = get_post($item->object_id);
        return $page && $page->post_name === $slug;
    }
    return false;
}

/**
 * Check if menu item URL contains a path.
 */
function stridence_menu_item_matches_url(object $item, string $path): bool
{
    return str_contains($item->url ?? '', rtrim($path, '/'));
}

// ========================================
// SCORM LESSON DETECTION
// ========================================

// Add body class when lesson contains SCORM/xAPI content
add_filter('body_class', function (array $classes): array {
    if (is_singular('sfwd-lessons') || is_singular('sfwd-topic')) {
        global $post;
        if ($post && (
            has_shortcode($post->post_content, 'vc_snc') ||
            strpos($post->post_content, '[vc_snc') !== false
        )) {
            $classes[] = 'has-scorm-content';

            // Check if course has only one lesson - hide sidebar
            if (function_exists('learndash_get_course_id')) {
                $course_id = learndash_get_course_id($post->ID);
                if ($course_id) {
                    $lessons = learndash_get_course_lessons_list($course_id);
                    if (is_array($lessons) && count($lessons) <= 1) {
                        $classes[] = 'single-lesson-course';
                    }
                }
            }
        }
    }
    return $classes;
});

// ========================================
// LEARNDASH FOCUS MODE CUSTOMIZATION
// ========================================

// Add back button to brand logo area (empty when no logo set)
add_filter('learndash_focus_header_element', function (string $header_element, array $header, int $course_id, int $user_id): string {
    // Only customize if no logo is set (header_element is empty)
    if (!empty($header_element)) {
        return $header_element;
    }

    // Create a back button with arrow icon
    $course_url = get_permalink($course_id);
    $course_title = get_the_title($course_id);

    return sprintf(
        '<a href="%s" class="ld-brand-back-link" title="%s">%s<span class="ld-brand-back-text">%s</span></a>',
        esc_url($course_url),
        esc_attr(sprintf(__('Terug naar %s', 'stridence'), $course_title)),
        stridence_icon('chevron-left', 'ld-brand-back-icon'),
        esc_html__('Terug', 'stridence')
    );
}, 10, 4);

// Simplify user dropdown menu - remove LD defaults, add Stride profile link
add_filter('learndash_focus_header_user_dropdown_items', function (array $menu_items, int $course_id, int $user_id): array {
    // Get dashboard URL
    $dashboard_url = home_url('/mijn-account/');

    return [
        'dashboard' => [
            'url'   => $dashboard_url,
            'label' => __('Mijn dashboard', 'stridence'),
        ],
        'profile' => [
            'url'   => $dashboard_url . '?tab=profiel',
            'label' => __('Profiel', 'stridence'),
        ],
        'course-home' => [
            'url'   => get_permalink($course_id),
            'label' => __('Cursus overzicht', 'stridence'),
        ],
        'logout' => [
            'url'   => wp_logout_url(get_permalink($course_id)),
            'label' => __('Uitloggen', 'stridence'),
        ],
    ];
}, 10, 3);

// ========================================
// ROUTING
// ========================================

// Personal trajectory dashboard routing via ntdst_router
add_action('init', function (): void {
    ntdst_router()->get('mijn-account/trajecten/:slug', function (array $params) {
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url(home_url('/mijn-account/trajecten/' . $params['slug'] . '/')));
            exit;
        }

        get_header();
        get_template_part('templates/trajectory/dashboard', null, [
            'trajectory_slug' => sanitize_title($params['slug']),
            'user' => wp_get_current_user(),
        ]);
        get_footer();
    });
}, 20);

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
        // Register a dummy handle so wp_add_inline_script works
        wp_register_script('stridence-main', false);
        wp_enqueue_script('stridence-main');
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

// Disable WordPress 6.9 Speculation Rules (prefetch/prerender).
// Brave browser strips cookies from prefetched requests, causing
// pages to load as unauthenticated. This makes enrollment state,
// dashboard content, and login state appear stale on first visit.
add_filter('wp_speculation_rules_configuration', '__return_null');

// Prevent browser from caching pages (stale logged-in/out state)
add_action('send_headers', function () {
    if (!is_admin()) {
        nocache_headers();
        header('Vary: Cookie', false);
    }
});

// Block browser-initiated prefetch requests (Brave, Chrome).
// These requests strip cookies, so the server renders unauthenticated
// content. The browser then serves this stale response when the user
// navigates, showing wrong enrollment/login state until refresh.
add_action('init', function () {
    $purpose = $_SERVER['HTTP_PURPOSE'] ?? $_SERVER['HTTP_SEC_PURPOSE'] ?? '';
    if (stripos($purpose, 'prefetch') !== false) {
        status_header(503);
        exit;
    }
}, 1);

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
// LEARNDASH PERMALINK OVERRIDE
// ========================================

/**
 * Override LearnDash course permalink slug to 'opleidingen'
 *
 * LearnDash stores its slug in learndash_settings_permalinks option.
 * We filter the option value to override without changing the database.
 */
add_filter('option_learndash_settings_permalinks', function ($value) {
    if (is_array($value)) {
        $value['courses'] = 'opleidingen';
    }
    return $value;
});

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
// SHORTCODES
// ========================================

/**
 * Enrollment form shortcode
 *
 * Usage: [stride_enrollment]
 * URL parameter: ?editie=<edition_id>
 */
add_shortcode('stride_enrollment', function ($atts = []) {
    $edition_id = isset($_GET['editie']) ? absint($_GET['editie']) : 0;

    if (!$edition_id) {
        return stridence_render_error_state(
            'alert-circle',
            __('Geen editie geselecteerd', 'stridence'),
            __('Selecteer eerst een editie via de cursuspagina.', 'stridence'),
            __('Naar cursussen', 'stridence'),
            get_post_type_archive_link('sfwd-courses')
        );
    }

    $edition = get_post($edition_id);
    if (!$edition || $edition->post_type !== 'vad_edition') {
        return stridence_render_error_state(
            'alert-circle',
            __('Editie niet gevonden', 'stridence'),
            __('Deze editie bestaat niet of is verwijderd.', 'stridence'),
            __('Naar cursussen', 'stridence'),
            get_post_type_archive_link('sfwd-courses')
        );
    }

    // Pre-fetch edition data for template (used by Alpine component)
    $item_data = [
        'id' => $edition_id,
        'title' => $edition->post_title,
    ];

    ob_start();
    get_template_part('templates/forms/enrollment', null, [
        'item_id' => $edition_id,
        'item_type' => 'edition',
        'item_data' => $item_data,
    ]);
    return ob_get_clean();
});

/**
 * Interest form shortcode
 *
 * Uses the unified enrollment form in interest mode.
 *
 * Usage: [stride_interest]
 * URL parameters: ?cursus=<course_id> or ?traject=<trajectory_id>
 */
add_shortcode('stride_interest', function ($atts = []) {
    $course_id = isset($_GET['cursus']) ? absint($_GET['cursus']) : 0;
    $trajectory_id = isset($_GET['traject']) ? absint($_GET['traject']) : 0;

    // Handle trajectory interest
    if ($trajectory_id) {
        $trajectory = get_post($trajectory_id);
        if (!$trajectory || $trajectory->post_type !== 'vad_trajectory') {
            return stridence_render_error_state(
                'alert-circle',
                __('Traject niet gevonden', 'stridence'),
                __('Dit traject bestaat niet of is verwijderd.', 'stridence'),
                __('Naar trajecten', 'stridence'),
                get_post_type_archive_link('vad_trajectory')
            );
        }

        ob_start();
        get_template_part('templates/forms/enrollment', null, [
            'item_id'         => $trajectory_id,
            'item_type'       => 'trajectory',
            'item_data'       => ['id' => $trajectory_id, 'title' => $trajectory->post_title],
            'enrollment_mode' => 'interest',
        ]);
        return ob_get_clean();
    }

    // Handle course interest
    if (!$course_id) {
        return stridence_render_error_state(
            'alert-circle',
            __('Geen cursus geselecteerd', 'stridence'),
            __('Selecteer eerst een cursus of traject.', 'stridence'),
            __('Naar cursussen', 'stridence'),
            get_post_type_archive_link('sfwd-courses')
        );
    }

    $course = get_post($course_id);
    if (!$course || $course->post_type !== 'sfwd-courses') {
        return stridence_render_error_state(
            'alert-circle',
            __('Cursus niet gevonden', 'stridence'),
            __('Deze cursus bestaat niet of is verwijderd.', 'stridence'),
            __('Naar cursussen', 'stridence'),
            get_post_type_archive_link('sfwd-courses')
        );
    }

    ob_start();
    get_template_part('templates/forms/enrollment', null, [
        'item_id'         => $course_id,
        'item_type'       => 'edition',
        'item_data'       => ['id' => $course_id, 'title' => $course->post_title],
        'enrollment_mode' => 'interest',
    ]);
    return ob_get_clean();
});

/**
 * Render error state card
 */
function stridence_render_error_state(string $icon, string $title, string $message, string $action_label, string $action_url): string
{
    ob_start();
    ?>
    <div class="container py-8 lg:py-12">
        <div class="card p-8 text-center max-w-lg mx-auto">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-error/10 flex items-center justify-center">
                <?php echo stridence_icon($icon, 'w-8 h-8 text-error'); ?>
            </div>
            <h2 class="text-lg font-semibold mb-2"><?php echo esc_html($title); ?></h2>
            <p class="text-text-muted mb-6"><?php echo esc_html($message); ?></p>
            <a href="<?php echo esc_url($action_url); ?>" class="btn-primary">
                <?php echo stridence_icon('arrow-left', 'w-4 h-4 mr-2'); ?>
                <?php echo esc_html($action_label); ?>
            </a>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ========================================
// HELPER FUNCTIONS
// ========================================

