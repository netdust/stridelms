<?php
/**
 * Template Name: Trajecten
 * Template Post Type: page
 *
 * Trajectory catalog page template.
 * Displays trajectories open for enrollment.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

get_header();

// Main content wrapper with container
?>
<div class="uk-container uk-container-large uk-margin-medium-top uk-margin-large-bottom">
    <?php
    // Load the catalog template
    $template = locate_template('templates/trajectory/catalog.php');
    if ($template) {
        include $template;
    } else {
        // Fallback if template not found
        echo '<div class="uk-alert uk-alert-warning">';
        esc_html_e('Trajectcatalogus template niet gevonden.', 'stride');
        echo '</div>';
    }
    ?>
</div>
<?php

get_footer();
