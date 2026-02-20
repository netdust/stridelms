<?php
/**
 * Template Name: Mijn Agenda
 * Template Post Type: page
 *
 * User's calendar/agenda page template.
 * Displays upcoming sessions and events.
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
    // Load the calendar template
    $template = locate_template('templates/dashboard/calendar.php');
    if ($template) {
        include $template;
    } else {
        // Fallback if template not found
        echo '<div class="uk-alert uk-alert-warning">';
        esc_html_e('Agenda template niet gevonden.', 'stride');
        echo '</div>';
    }
    ?>
</div>

<?php
get_footer();
