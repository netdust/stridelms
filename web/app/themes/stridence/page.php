<?php
/**
 * Page Template
 *
 * Default template for WordPress pages.
 * Renders full content with shortcode support.
 *
 * @package stridence
 */

get_header();
?>

<main id="main-content">
    <?php while (have_posts()) : the_post(); ?>
        <article <?php post_class(); ?>>
            <?php
            // Get the content - this executes shortcodes
            $content = get_the_content();
        $content = apply_filters('the_content', $content);

        // Check if content contains our form shortcodes (full-width layout)
        $has_form_shortcode = has_shortcode(get_the_content(), 'stride_enrollment')
                           || has_shortcode(get_the_content(), 'stride_interest')
                           || has_shortcode(get_the_content(), 'stride_waitlist');

        if ($has_form_shortcode) {
            // Full-width output for form pages (shortcode handles its own container)
            echo $content;
        } else {
            // Standard page layout with container
            ?>
                <header class="bg-surface-alt border-b border-border">
                    <div class="container py-8 lg:py-12">
                        <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text">
                            <?php the_title(); ?>
                        </h1>
                    </div>
                </header>

                <div class="container py-8 lg:py-12">
                    <div class="prose-stride max-w-3xl">
                        <?php echo $content; ?>
                    </div>
                </div>
                <?php
        }
        ?>
        </article>
    <?php endwhile; ?>
</main>

<?php
get_footer();
