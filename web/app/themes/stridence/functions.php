<?php
/**
 * Stridence - Minimal LMS Theme
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

// Load helpers
require_once get_stylesheet_directory() . '/helpers/icons.php';

/**
 * Theme setup
 */
add_action('after_setup_theme', function () {
    // Add theme support
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', [
        'height' => 60,
        'width' => 200,
        'flex-height' => true,
        'flex-width' => true,
    ]);
    add_theme_support('html5', [
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'style',
        'script',
    ]);

    // LearnDash support
    add_theme_support('learndash-course-grid');

    // Register menus
    register_nav_menus([
        'primary' => __('Hoofdmenu', 'stridence'),
        'footer' => __('Footermenu', 'stridence'),
    ]);

    // Text domain
    load_theme_textdomain('stridence', get_stylesheet_directory() . '/languages');
});

/**
 * Enqueue styles and scripts
 */
add_action('wp_enqueue_scripts', function () {
    // Main stylesheet
    wp_enqueue_style(
        'stridence-style',
        get_stylesheet_directory_uri() . '/assets/css/stridence.css',
        [],
        filemtime(get_stylesheet_directory() . '/assets/css/stridence.css')
    );

    // Header/mobile menu script
    wp_enqueue_script(
        'stridence-nav',
        get_stylesheet_directory_uri() . '/assets/js/nav.js',
        [],
        filemtime(get_stylesheet_directory() . '/assets/js/nav.js'),
        true
    );
});

/**
 * Add body classes
 */
add_filter('body_class', function ($classes) {
    if (is_user_logged_in()) {
        $classes[] = 'logged-in';
    }
    return $classes;
});
