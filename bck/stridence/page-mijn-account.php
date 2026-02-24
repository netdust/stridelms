the <?php
/**
 * Template for: Mijn Account (Dashboard Overview)
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

if (!is_user_logged_in()) {
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

get_header();
include get_stylesheet_directory() . '/templates/dashboard/overview.php';
get_footer();
