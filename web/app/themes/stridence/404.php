<?php
/**
 * 404 Template
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();
?>

<div class="container py-16">
    <?php
    stridence_template_part('partials/empty-state', null, [
        'icon'    => 'search',
        'title'   => __('Pagina niet gevonden', 'stridence'),
        'message' => __('De pagina die je zoekt bestaat niet of is verplaatst.', 'stridence'),
        'action'  => __('Terug naar home', 'stridence'),
        'url'     => home_url('/'),
    ]);
?>
</div>

<?php
get_footer();
