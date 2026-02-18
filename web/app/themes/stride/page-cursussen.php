<?php
/**
 * Template Name: Cursussen
 * Template Post Type: page
 *
 * Course catalog page template.
 * Displays upcoming editions with filtering.
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
    $template = locate_template('templates/course/catalog.php');
    if ($template) {
        include $template;
    } else {
        // Fallback if template not found
        echo '<div class="uk-alert uk-alert-warning">';
        esc_html_e('Cursuscatalogus template niet gevonden.', 'stride');
        echo '</div>';
    }
    ?>
</div>
<?php

get_footer();
