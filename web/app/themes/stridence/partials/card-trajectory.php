<?php
/**
 * Trajectory Card Partial
 *
 * Renders a trajectory card with title, status badge, excerpt, course count, and deadline.
 * All data must be passed via $args - no service calls or meta lookups inside partials.
 *
 * @param array $args {
 *     @type array|WP_Post $trajectory Trajectory data array or WP_Post (legacy support)
 *         Array format (from Data Manager): id, title, content, meta[status, enrollment_deadline, course_count]
 *         WP_Post: Trajectory post object
 * }
 */

defined('ABSPATH') || exit;

$trajectory = $args['trajectory'] ?? null;

// Early return if no trajectory
if (!$trajectory) {
    return;
}

// Handle array format (Data Manager) vs WP_Post (legacy)
if (is_array($trajectory)) {
    // Data Manager array format - meta nested under 'meta' key
    $id        = (int) ($trajectory['id'] ?? $trajectory['ID'] ?? 0);
    $permalink = get_permalink($id);
    $title     = $trajectory['title'] ?? $trajectory['post_title'] ?? '';
    $content   = $trajectory['content'] ?? $trajectory['post_content'] ?? '';
    $excerpt   = !empty($trajectory['excerpt'])
        ? $trajectory['excerpt']
        : wp_trim_words(wp_strip_all_tags($content), 20, '...');

    // Meta fields from Data Manager (nested under 'meta' key with possible prefix)
    $meta         = $trajectory['meta'] ?? [];
    $course_count = (int) ($meta['course_count'] ?? $meta['_ntdst_course_count'] ?? 0);
    $deadline     = $meta['enrollment_deadline'] ?? $meta['_ntdst_enrollment_deadline'] ?? '';
    $status       = $meta['status'] ?? $meta['_ntdst_status'] ?? 'open';
} elseif ($trajectory instanceof WP_Post) {
    // Legacy WP_Post support - still requires parent to pass meta via separate args
    $id        = $trajectory->ID;
    $permalink = get_permalink($trajectory);
    $title     = get_the_title($trajectory);
    $excerpt   = !empty($trajectory->post_excerpt)
        ? $trajectory->post_excerpt
        : wp_trim_words(wp_strip_all_tags($trajectory->post_content), 20, '...');

    // Use args for meta if provided, otherwise empty
    $course_count = (int) ($args['course_count'] ?? 0);
    $deadline     = $args['deadline'] ?? '';
    $status       = $args['status'] ?? 'open';
} else {
    return;
}

// Map trajectory status to badge status
$badge_status_map = [
    'open'         => 'open',
    'announcement' => 'announcement',
    'ongoing'      => 'pending',
    'completed'    => 'completed',
    'draft'        => 'pending',
    'closed'       => 'cancelled',
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
        <?php stridence_template_part('partials/badge-status', null, ['status' => $badge_status]); ?>
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
                            $course_count,
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
