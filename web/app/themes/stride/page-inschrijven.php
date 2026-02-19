<?php
/**
 * Template Name: Inschrijven
 * Template Post Type: page
 *
 * Enrollment form page template.
 * Handles course enrollment with billing info and voucher codes.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

get_header();
?>

<div class="uk-container uk-container-large uk-margin-large-top uk-margin-large-bottom">
    <?php
    // Load the enrollment form template
    $template = locate_template('templates/enrollment/form.php');
    if ($template) {
        include $template;
    } else {
        echo '<div class="uk-alert uk-alert-warning">';
        esc_html_e('Inschrijvingsformulier niet gevonden.', 'stride');
        echo '</div>';
    }
    ?>
</div>

<?php
get_footer();
