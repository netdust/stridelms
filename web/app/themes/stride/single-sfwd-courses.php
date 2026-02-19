<?php
/**
 * Single Course Template
 *
 * Displays a LearnDash course using the Stride course landing page design.
 *
 * @package stride
 */

// Enqueue course single CSS
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style(
        'stride-course-single',
        get_template_directory_uri() . '/assets/css/course-single.css',
        [],
        filemtime(get_template_directory() . '/assets/css/course-single.css')
    );
}, 20);

get_header();
?>

<main class="stride-main stride-main--course">
    <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
        <?php get_template_part('templates/course/single'); ?>
    <?php endwhile; endif; ?>
</main>

<?php get_footer(); ?>
