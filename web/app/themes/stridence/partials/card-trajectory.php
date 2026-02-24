<?php
/**
 * Trajectory Card Partial
 *
 * Renders a trajectory card with title, status badge, excerpt, course count, and deadline.
 *
 * @param array $args {
 *     @type WP_Post|object $trajectory Trajectory post object or custom object with id, title, excerpt properties
 * }
 */

defined('ABSPATH') || exit;

$trajectory = $args['trajectory'] ?? null;

// Early return if no trajectory
if (!$trajectory) {
    return;
}

// Handle both WP_Post and custom objects
if ($trajectory instanceof WP_Post) {
    $id        = $trajectory->ID;
    $permalink = get_permalink($trajectory);
    $title     = get_the_title($trajectory);
    $excerpt   = !empty($trajectory->post_excerpt)
        ? $trajectory->post_excerpt
        : wp_trim_words(wp_strip_all_tags($trajectory->post_content), 20, '...');
} else {
    // Custom object - expect id, title, excerpt, permalink properties
    $id        = $trajectory->id ?? 0;
    $permalink = $trajectory->permalink ?? '#';
    $title     = $trajectory->title ?? '';
    $excerpt   = $trajectory->excerpt ?? '';
}

// Get meta fields
$course_count = (int) get_post_meta($id, '_trajectory_course_count', true);
$deadline     = get_post_meta($id, '_trajectory_deadline', true);
$status       = get_post_meta($id, '_trajectory_status', true) ?: 'open';

// Map trajectory status to badge status
// Trajectory uses: open, ongoing, completed
// Badge supports: open, completed (and others)
$badge_status_map = [
    'open'      => 'open',
    'ongoing'   => 'pending',
    'completed' => 'completed',
];
$badge_status = $badge_status_map[$status] ?? 'open';

?>
<article class="card p-5 flex flex-col h-full">
    <div class="flex items-start justify-between gap-3 mb-3">
        <h3 class="font-heading font-semibold text-lg line-clamp-2 flex-1">
            <a href="<?php echo esc_url($permalink); ?>" class="text-text hover:text-primary transition-colors">
                <?php echo esc_html($title); ?>
            </a>
        </h3>
        <?php get_template_part('partials/badge-status', null, ['status' => $badge_status]); ?>
    </div>

    <p class="text-sm text-text-muted line-clamp-2 mb-4 flex-1">
        <?php echo esc_html($excerpt); ?>
    </p>

    <?php if ($course_count > 0 || $deadline): ?>
        <div class="space-y-2 mb-4">
            <?php if ($course_count > 0): ?>
                <div class="flex items-center gap-2 text-sm text-text-muted">
                    <?php echo stridence_icon('book-open', 'w-4 h-4 shrink-0'); ?>
                    <span>
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of courses */
                            _n('%d cursus', '%d cursussen', $course_count, 'stridence'),
                            $course_count
                        ));
                        ?>
                    </span>
                </div>
            <?php endif; ?>

            <?php if ($deadline): ?>
                <div class="flex items-center gap-2 text-sm text-text-muted">
                    <?php echo stridence_icon('clock', 'w-4 h-4 shrink-0'); ?>
                    <span>Deadline: <?php echo esc_html(stride_format_date($deadline)); ?></span>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <a href="<?php echo esc_url($permalink); ?>" class="btn-ghost w-full text-center">
        Bekijk traject
    </a>
</article>
