<?php
/**
 * Course Card Partial — PURE RENDERER (Task G1 / audit 2.2 — Helder Tij).
 *
 * Renders a (pure-LD online) course card per the Helder Tij sheet: badge
 * row, 17px title, 13px meta block, optional 7px progress bar, footer with
 * arrow CTA. The whole card is the link — hover lift lives on this wrapper.
 *
 * All per-card lookups are data-in: callers run the catalog batch pre-pass
 * (helpers/catalog.php — stridence_prefetch_course_cards() resolves the
 * primary visible edition with the same enrollable > active ranking this
 * partial previously computed with a WP_Query PER CARD, plus the visitor's
 * LD state). When prefetched keys are absent (mid-flow fallback), the card
 * renders the generic Online badge only — degraded but never fatal.
 *
 * Variants (driven ONLY by existing args):
 * - enrolled:        ✓ Ingeschreven badge (user_state, progress 0)
 * - online-progress: "X% voltooid" badge + 7px progress bar + "Ga verder" CTA
 * - completed:       "Afgerond" badge (progress >= 100)
 *
 * @param array $args {
 *     @type WP_Post    $course          Course post object
 *     @type array|null $primary_edition Prefetched ['id' => int, 'status' => OfferingStatus, 'spots' => ?int] or null (pure-LD)
 *     @type array|null $user_state      Prefetched ['enrolled' => bool, 'progress' => int] or null (guest / not enrolled)
 * }
 */

defined('ABSPATH') || exit;

$course = $args['course'] ?? null;

// Early return if no course
if (!$course instanceof WP_Post) {
    return;
}

$primary_edition = $args['primary_edition'] ?? null;
$user_state      = $args['user_state'] ?? null;

// Pure-LD course card. Link to the canonical course URL — LD owns
// /opleidingen/<slug>/, Stride decorates it via single-sfwd-courses.php.
// See tasks/url-structure-rework.md.
$permalink = get_permalink($course);
$title     = get_the_title($course);

// Generate excerpt: prefer post_excerpt, fallback to trimmed content
$excerpt = !empty($course->post_excerpt)
    ? $course->post_excerpt
    : wp_trim_words(wp_strip_all_tags($course->post_content), 20, '...');

// User-level state (enrolled / in-progress / completed) wins when present —
// that's the visitor's own status with the course, regardless of edition.
// Otherwise: if course has a primary visible edition, show its effective
// status. Pure-LD courses (no edition) only carry the Online type badge.
$is_enrolled = !empty($user_state['enrolled']);
$progress    = $is_enrolled ? (int) ($user_state['progress'] ?? 0) : 0;
$in_progress = $is_enrolled && $progress > 0 && $progress < 100;
$completed   = $is_enrolled && $progress >= 100;

// CTA label per the sheet: "Ga verder" while in progress, otherwise
// "Start opleiding" (completed cards link back to the course overview).
if ($in_progress) {
    $cta_label = __('Ga verder', 'stridence');
} elseif ($completed) {
    $cta_label = __('Bekijk opleiding', 'stridence');
} else {
    $cta_label = __('Start opleiding', 'stridence');
}

?>
<a href="<?php echo esc_url($permalink); ?>"
   class="bg-surface-card rounded-[14px] shadow-card p-6 flex flex-col gap-3.5 h-full text-text transition-all duration-normal ease-out hover:shadow-elevated hover:-translate-y-0.5">

    <!-- Status row: dot + text on the left, enrolled state on the right -->
    <div class="flex items-center justify-between gap-2">
        <?php stridence_template_part('partials/badge-status', null, [
            'status' => 'online',
            'style'  => 'dot',
        ]); ?>

        <?php if ($completed) : ?>
            <span class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-badge-online-text"><?php esc_html_e('✓ Afgerond', 'stridence'); ?></span>
        <?php elseif ($in_progress) : ?>
            <span class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-badge-online-text"><?php
                /* translators: %d: completion percentage */
                echo esc_html(sprintf(__('%d%% voltooid', 'stridence'), $progress));
            ?></span>
        <?php elseif ($is_enrolled) : ?>
            <span class="inline-flex items-center gap-1.5 text-[13px] font-semibold text-badge-online-text"><?php esc_html_e('✓ Ingeschreven', 'stridence'); ?></span>
        <?php endif; ?>
    </div>

    <!-- Title -->
    <h3 class="text-[17px] font-bold leading-snug text-pretty text-text line-clamp-2">
        <?php echo esc_html($title); ?>
    </h3>

    <!-- Meta block -->
    <?php if ($excerpt) : ?>
        <p class="text-[13px] text-text-muted line-clamp-2">
            <?php echo esc_html($excerpt); ?>
        </p>
    <?php endif; ?>

    <!-- Progress bar (online-progress variant) — pct only, so the
         count-based progress-bar partial's args don't fit: inline track/fill. -->
    <?php if ($in_progress) : ?>
        <div class="h-[7px] rounded-full bg-surface-alt overflow-hidden">
            <div class="h-full bg-primary rounded-full" style="width: <?php echo (int) $progress; ?>%"></div>
        </div>
    <?php endif; ?>

    <!-- Footer row (divider above) -->
    <div class="mt-auto pt-4 border-t border-border flex items-center justify-end gap-3">
        <span class="text-sm font-bold text-primary"><?php echo esc_html($cta_label); ?> &rarr;</span>
    </div>
</a>
