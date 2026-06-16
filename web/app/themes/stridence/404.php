<?php
/**
 * 404 Template
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();
?>

<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text">
            <?php esc_html_e('Pagina niet gevonden', 'stridence'); ?>
        </h1>
    </div>
</div>

<div class="container py-12">
    <?php
    stridence_template_part('partials/error-state', null, [
        'icon'         => 'search',
        'title'        => __('Pagina niet gevonden', 'stridence'),
        'message'      => __('De pagina die je zoekt bestaat niet of is verplaatst.', 'stridence'),
        'action_label' => __('Terug naar home', 'stridence'),
        'action_url'   => home_url('/'),
    ]);
?>
</div>

<?php
get_footer();
