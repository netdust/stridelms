<?php
/**
 * Template for: Mijn Trajecten
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

get_header();
include get_stylesheet_directory() . '/templates/dashboard/trajectories.php';
get_footer();
