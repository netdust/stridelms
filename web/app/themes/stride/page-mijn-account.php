<?php
/**
 * Template Name: Mijn Account
 * Template Post Type: page
 *
 * Dashboard page template for logged-in users.
 * Renders the dashboard home or redirects to login.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Require login - redirect to login page with return URL
if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

get_header();

// Load the dashboard home template
$template = locate_template('templates/dashboard/home.php');
if ($template) {
    include $template;
} else {
    // Fallback if template not found
    echo '<div class="uk-alert uk-alert-warning">';
    esc_html_e('Dashboard template niet gevonden.', 'stride');
    echo '</div>';
}

get_footer();
