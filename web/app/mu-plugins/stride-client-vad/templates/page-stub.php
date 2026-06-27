<?php
/**
 * Template Name: VAD — Lange-content pagina
 *
 * Long-form layout for VAD stub pages (over-ons, privacy, voorwaarden).
 * Two-column intro band at top (when pattern provides one), then prose body.
 *
 * @package stride-client-vad
 */

get_header();
?>

<article class="prose-stride" style="
    max-width: 880px;
    margin: 0 auto;
    padding: clamp(48px, 7vw, 96px) var(--vad-gutter, clamp(20px, 4vw, 56px));
    color: rgb(var(--color-text));
">
    <header style="margin-bottom: 40px; border-bottom: 1px solid rgb(var(--color-border)); padding-bottom: 24px;">
        <p style="
            font-family: var(--font-sans);
            font-weight: 700;
            font-size: 13px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgb(var(--color-accent));
            margin: 0 0 8px;
        "><?php esc_html_e('VAD-academie', 'stridence'); ?></p>

        <h1 style="
            font-family: var(--font-heading);
            font-weight: 600;
            font-size: clamp(32px, 4.5vw, 42px);
            line-height: 1.2;
            margin: 0;
            color: rgb(var(--color-primary));
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
