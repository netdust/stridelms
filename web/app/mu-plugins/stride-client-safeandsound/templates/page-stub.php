<?php
/**
 * Template Name: Safe & Sound — Long-form page
 *
 * Bespoke template for pages whose content ships its own brand hero
 * (Contact, FAQ, Over ons, Agenda, T&Cs). Skips Stridence's auto-injected
 * page-title bar so the pattern's hero is the first thing visible.
 *
 * @package stride-client-safeandsound
 */

get_header();
?>

<main id="main-content">
    <?php while (have_posts()) : the_post(); ?>
        <article <?php post_class('safeandsound-stub-page'); ?>>
            <?php the_content(); ?>
        </article>
    <?php endwhile; ?>
</main>

<?php
get_footer();
