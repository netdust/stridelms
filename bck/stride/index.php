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

<div class="uk-container uk-container-large">
    <?php if (have_posts()) : ?>
        <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('stride-article'); ?>>
                <?php if (!is_front_page()) : ?>
                    <header class="stride-page-header">
                        <?php the_title('<h1 class="stride-page-title">', '</h1>'); ?>
                    </header>
                <?php endif; ?>

                <div class="stride-article-content">
                    <?php the_content(); ?>
                </div>
            </article>
        <?php endwhile; ?>

        <?php the_posts_navigation(); ?>
    <?php else : ?>
        <div class="stride-card">
            <div class="stride-empty-state">
                <span class="stride-empty-state-icon" uk-icon="icon: file-text; ratio: 3"></span>
                <h3 class="stride-empty-state-title"><?php esc_html_e('Geen inhoud gevonden', 'stride'); ?></h3>
                <p class="stride-empty-state-text"><?php esc_html_e('De pagina die je zoekt bestaat niet.', 'stride'); ?></p>
                <a href="<?php echo esc_url(home_url('/')); ?>" class="uk-button uk-button-primary">
                    <?php esc_html_e('Terug naar home', 'stride'); ?>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
get_footer();
