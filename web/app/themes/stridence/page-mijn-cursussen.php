<?php
/**
 * Template for: Mijn Cursussen
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

get_header();
include get_stylesheet_directory() . '/templates/dashboard/courses.php';
get_footer();
