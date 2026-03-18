<?php
/**
 * Lesson Template
 *
 * Single template for LearnDash lessons (sfwd-lessons post type).
 * Delegates rendering to LearnDash which handles Focus Mode if enabled.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// LearnDash Focus Mode uses template_include filter (priority 99) to replace
// the entire template. When Focus Mode is enabled, this file is never loaded.
// This template only runs when Focus Mode is disabled.

get_header();
?>

<article <?php post_class('pb-12'); ?>>
    <div class="container py-8">
        <?php
        while (have_posts()) :
            the_post();
            // LearnDash hooks into the_content() to render lesson content
            // This includes Focus Mode when enabled
            the_content();
        endwhile;
        ?>
    </div>
</article>

<?php
get_footer();
