<?php
/**
 * Template Name: Kindred — Long-form page
 *
 * Narrow editorial layout for stub pages (about, terms, privacy).
 *
 * @package stride-client-kindred
 */

get_header();
?>

<article class="prose-stride" style="
    max-width: 720px;
    margin: 0 auto;
    padding: clamp(56px, 9vw, 120px) var(--kindred-gutter);
">
    <header style="margin-bottom: 48px;">
        <span class="t-mono t-eyebrow"><?php esc_html_e('KINDRED HR', 'stridence'); ?></span>
        <h1 class="t-fraunces" style="
            font-weight: 400;
            font-size: clamp(40px, 5vw, 64px);
            line-height: 1.05;
            letter-spacing: -0.03em;
            margin: 16px 0 0;
            max-width: 18ch;
            color: rgb(var(--color-text));
        "><?php the_title(); ?></h1>
    </header>

    <?php
    if (have_posts()) :
        while (have_posts()) : the_post();
            the_content();
        endwhile;
    endif;
    ?>
</article>

<?php get_footer();
