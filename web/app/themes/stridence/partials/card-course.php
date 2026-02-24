<?php
/**
 * Course Card Partial
 *
 * Renders a simple course card without edition context.
 * Displays thumbnail, title, excerpt, and "Meer info" button.
 *
 * @param array $args {
 *     @type WP_Post $course Course post object
 * }
 */

defined('ABSPATH') || exit;

$course = $args['course'] ?? null;

// Early return if no course
if (!$course instanceof WP_Post) {
    return;
}

// Get course data
$permalink = get_permalink($course);
$title     = get_the_title($course);

// Generate excerpt: prefer post_excerpt, fallback to trimmed content
$excerpt = !empty($course->post_excerpt)
    ? $course->post_excerpt
    : wp_trim_words(wp_strip_all_tags($course->post_content), 20, '...');

// Get thumbnail - use stride_course_card size (400x225), fallback to medium
$thumbnail = get_the_post_thumbnail(
    $course,
    'stride_course_card',
    ['class' => 'w-full h-full object-cover transition-transform hover:scale-105']
);

?>
<article class="card overflow-hidden flex flex-col h-full">
    <!-- Thumbnail -->
    <a href="<?php echo esc_url($permalink); ?>" class="block aspect-video overflow-hidden bg-surface-alt">
        <?php if ($thumbnail): ?>
            <?php echo $thumbnail; ?>
        <?php else: ?>
            <div class="w-full h-full flex items-center justify-center">
                <?php echo stridence_icon('book-open', 'w-12 h-12 text-text-muted'); ?>
            </div>
        <?php endif; ?>
    </a>

    <div class="p-5 flex-1 flex flex-col">
        <h3 class="font-heading font-semibold text-lg mb-2 line-clamp-2">
            <a href="<?php echo esc_url($permalink); ?>" class="text-text hover:text-primary transition-colors">
                <?php echo esc_html($title); ?>
            </a>
        </h3>

        <p class="text-sm text-text-muted line-clamp-2 mb-4 flex-1">
            <?php echo esc_html($excerpt); ?>
        </p>

        <a href="<?php echo esc_url($permalink); ?>" class="btn-primary w-full text-center">
            Meer info
        </a>
    </div>
</article>
