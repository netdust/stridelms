<?php
/**
 * Main template file
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();
?>

<div class="str-container">
    <?php if (have_posts()): ?>
        <div class="str-posts">
            <?php while (have_posts()): the_post(); ?>
                <article <?php post_class('str-post'); ?>>
                    <h2 class="str-post__title">
                        <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                    </h2>
                    <div class="str-post__excerpt">
                        <?php the_excerpt(); ?>
                    </div>
                </article>
            <?php endwhile; ?>
        </div>

        <?php the_posts_navigation(); ?>
    <?php else: ?>
        <p><?php esc_html_e('Geen berichten gevonden.', 'stridence'); ?></p>
    <?php endif; ?>
</div>

<?php
get_footer();
