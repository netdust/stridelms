<?php
/**
 * Main Template File
 *
 * Fallback template for all content.
 *
 * @package stridence
 */

get_header();
?>

<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text">
            <?php
            if (is_home()) {
                esc_html_e('Blog', 'stridence');
            } elseif (is_archive()) {
                the_archive_title();
            } else {
                esc_html_e('Berichten', 'stridence');
            }
?>
        </h1>
    </div>
</div>

<div class="container py-8 lg:py-12">
    <?php if (have_posts()) : ?>
        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php while (have_posts()) : the_post(); ?>
                <article <?php post_class('card p-6'); ?>>
                    <?php if (has_post_thumbnail()) : ?>
                        <a href="<?php the_permalink(); ?>" class="block mb-4 overflow-hidden rounded-lg">
                            <?php the_post_thumbnail('stride_course_card', ['class' => 'w-full h-auto']); ?>
                        </a>
                    <?php endif; ?>

                    <h2 class="font-heading text-xl font-semibold mb-2">
                        <a href="<?php the_permalink(); ?>" class="text-text hover:text-primary">
                            <?php the_title(); ?>
                        </a>
                    </h2>

                    <div class="text-text-muted text-sm mb-4">
                        <?php the_excerpt(); ?>
                    </div>

                    <a href="<?php the_permalink(); ?>" class="btn-ghost btn-sm">
                        <?php esc_html_e('Lees meer', 'stridence'); ?>
                    </a>
                </article>
            <?php endwhile; ?>
        </div>

        <?php the_posts_pagination([
'prev_text' => '&larr; ' . __('Vorige', 'stridence'),
'next_text' => __('Volgende', 'stridence') . ' &rarr;',
'class' => 'mt-12',
        ]); ?>

    <?php else : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
'icon'    => 'file-text',
'title'   => __('Geen berichten gevonden', 'stridence'),
'message' => __('Er zijn momenteel geen berichten beschikbaar.', 'stridence'),
        ]);
        ?>
    <?php endif; ?>
</div>

<?php
get_footer();
