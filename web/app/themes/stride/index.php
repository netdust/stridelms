<?php
/**
 * Stride LMS - Main Template
 *
 * The main template file, required for all themes
 *
 * @package stride
 */

get_header();
?>

<main id="main" class="stride-main">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('stride-article'); ?>>
                <header class="entry-header">
                    <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
                </header>

                <div class="entry-content">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>

        <?php the_posts_navigation(); ?>
    <?php else : ?>
        <p><?php esc_html_e('No content found.', 'stride'); ?></p>
    <?php endif; ?>
</main>

<?php
get_footer();
