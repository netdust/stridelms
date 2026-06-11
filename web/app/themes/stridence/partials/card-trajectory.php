<?php
/**
 * Trajectory Card Partial — Helder Tij.
 *
 * Renders a trajectory card per the sheet: badge row ([Traject] + status),
 * 17px title, journey dots strip, 13px meta block, footer with arrow CTA.
 * The whole card is the link — hover lift lives on this wrapper.
 *
 * All data must be passed via $args — no service calls or meta lookups
 * inside partials. The dots strip renders one dot per course; without
 * per-user progress data (not passed by any caller today) every dot uses
 * the "upcoming" treatment from the sheet.
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
<a href="<?php echo esc_url($permalink); ?>"
   class="bg-surface-card rounded-[14px] shadow-card p-6 flex flex-col gap-3.5 h-full text-text transition-all duration-normal ease-out hover:shadow-elevated hover:-translate-y-0.5">

    <!-- Badge row -->
    <div class="flex gap-1.5 flex-wrap">
        <?php stridence_template_part('partials/badge-status', null, [
            'status' => 'trajectory',
            'size'   => 'sm',
        ]); ?>
        <?php stridence_template_part('partials/badge-status', null, [
            'status' => $badge_status,
            'size'   => 'sm',
        ]); ?>
    </div>

    <!-- Title -->
    <h3 class="text-[17px] font-bold leading-snug text-pretty text-text line-clamp-2">
        <?php echo esc_html($title); ?>
    </h3>

    <!-- Journey dots strip (sheet: 12px dots, 2px lines; no per-user
         progress data is passed, so every step renders "upcoming") -->
    <?php if ($course_count > 0) : ?>
        <div class="flex items-center" aria-hidden="true">
            <?php for ($i = 0; $i < $course_count; $i++) : ?>
                <?php if ($i > 0) : ?>
                    <span class="h-0.5 flex-1 bg-border"></span>
                <?php endif; ?>
                <span class="w-3 h-3 rounded-full bg-surface-card shadow-[inset_0_0_0_2px_rgb(var(--color-border))] shrink-0"></span>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

    <!-- Meta block -->
    <?php if ($course_count > 0 || $deadline) : ?>
        <div class="flex flex-col gap-1.5 text-[13px] text-text-muted">
            <?php if ($course_count > 0) : ?>
                <div>
                    <strong class="text-text font-semibold">
                        <?php
                        echo esc_html(sprintf(
                            /* translators: %d: number of courses */
                            _n('%d opleiding', '%d opleidingen', $course_count, 'stridence'),
                            $course_count,
                        ));
                        ?>
                    </strong>
                </div>
            <?php endif; ?>

            <?php if ($deadline) : ?>
                <div>
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: enrollment deadline date */
                        __('Inschrijven tot %s', 'stridence'),
                        stride_format_date($deadline),
                    ));
                    ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Footer row -->
    <div class="mt-auto pt-1 flex items-center justify-end gap-3">
        <span class="text-sm font-bold text-primary"><?php esc_html_e('Bekijk traject', 'stridence'); ?> &rarr;</span>
    </div>
</a>
