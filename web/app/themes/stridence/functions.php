<?php
/**
 * Stridence - Kadence Child Theme for Stride LMS
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

// Load helpers
require_once get_stylesheet_directory() . '/helpers/icons.php';

/**
 * Enqueue parent and child theme styles
 */
add_action('wp_enqueue_scripts', function () {
    // Parent theme style
    wp_enqueue_style(
        'kadence-parent-style',
        get_template_directory_uri() . '/style.css',
        [],
        wp_get_theme('kadence')->get('Version')
    );

    // Child theme style
    wp_enqueue_style(
        'stridence-style',
        get_stylesheet_uri(),
        ['kadence-parent-style'],
        wp_get_theme()->get('Version')
    );

    // Stridence base styles (always load)
    wp_enqueue_style(
        'stridence-base',
        get_stylesheet_directory_uri() . '/assets/css/stridence.css',
        ['kadence-parent-style'],
        filemtime(get_stylesheet_directory() . '/assets/css/stridence.css')
    );

    // LearnDash custom styles (only on LD pages)
    if (stridence_is_learndash_page()) {
        wp_enqueue_style(
            'stridence-learndash',
            get_stylesheet_directory_uri() . '/assets/css/learndash.css',
            ['kadence-parent-style'],
            filemtime(get_stylesheet_directory() . '/assets/css/learndash.css')
        );
    }
}, 20);

/**
 * Check if current page is a LearnDash page
 */
function stridence_is_learndash_page(): bool
{
    if (!function_exists('learndash_get_post_types')) {
        return false;
    }

    $post_type = get_post_type();
    $ld_post_types = learndash_get_post_types();

    return in_array($post_type, $ld_post_types, true);
}

/**
 * Initialize LearnDash customizations
 */
add_action('after_setup_theme', function () {
    if (class_exists('SFWD_LMS')) {
        require_once get_stylesheet_directory() . '/services/LearnDashCustomizer.php';
        new Stridence\Services\LearnDashCustomizer();
    }
}, 20);

/**
 * Add theme support
 */
add_action('after_setup_theme', function () {
    // Add support for LearnDash course grid
    add_theme_support('learndash-course-grid');

    // Load text domain
    load_child_theme_textdomain('stridence', get_stylesheet_directory() . '/languages');
});
