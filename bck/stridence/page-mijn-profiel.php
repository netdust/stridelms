<?php
/**
 * Template for: Mijn Profiel
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

// Redirect if not logged in
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

get_header();

// Include dashboard layout
include get_stylesheet_directory() . '/templates/dashboard/profile.php';

get_footer();
