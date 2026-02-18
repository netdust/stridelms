<?php
/**
 * Template Name: Mijn Profiel
 * Template Post Type: page
 *
 * User profile page template.
 * Displays user info and quick links.
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
?>

<div class="uk-container uk-container-large uk-margin-large-top uk-margin-large-bottom">
    <?php
    // Load the profile template
    $template = locate_template('templates/dashboard/profile.php');
    if ($template) {
        include $template;
    } else {
        // Fallback if template not found
        echo '<div class="uk-alert uk-alert-warning">';
        esc_html_e('Profiel template niet gevonden.', 'stride');
        echo '</div>';
    }
    ?>
</div>

<?php
get_footer();
