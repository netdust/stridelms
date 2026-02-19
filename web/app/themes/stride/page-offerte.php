<?php
/**
 * Template Name: Offerte Detail
 * Template Post Type: page
 *
 * Quote detail page template.
 * Displays a single quote with line items and billing info.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

get_header();
?>

<div class="uk-container uk-container-large uk-margin-large-top uk-margin-large-bottom">
    <?php
    // Load the quote detail template
    $template = locate_template('templates/quote/detail.php');
    if ($template) {
        include $template;
    } else {
        echo '<div class="uk-alert uk-alert-warning">';
        esc_html_e('Offerte template niet gevonden.', 'stride');
        echo '</div>';
    }
    ?>
</div>

<?php
get_footer();
