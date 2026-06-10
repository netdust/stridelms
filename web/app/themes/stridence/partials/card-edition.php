<?php
/**
 * Edition Card Partial — PURE RENDERER (Task G1 / audit 2.2).
 *
 * Renders an edition card with course context, including thumbnail with status badge,
 * course title, date, venue, price, and enrollment CTA.
 *
 * All per-card lookups are data-in: callers run the catalog batch pre-pass
 * (helpers/catalog.php — stridence_catalog_render_cards()) and pass the
 * resolved values. This partial must NOT call services/repositories — that
 * is what made the catalog N+1 (CR-3). When prefetched keys are absent
 * (mid-flow fallback), the card renders from the stored edition values.
 *
 * @param array $args {
 *     @type object|array $edition         Edition object/array with id/ID, start_date, venue/location, price, capacity, status
 *     @type WP_Post      $course          Optional course post for title and thumbnail
 *     @type string       $status          Prefetched EFFECTIVE status value (INV-7 — from EditionService::getEffectiveStatuses())
 *     @type int|null     $spots_remaining Prefetched spots remaining (null = unlimited/unknown)
 *     @type bool         $is_enrolled     Prefetched: current user has a confirmed registration
 *     @type int|null     $progress        Prefetched LD progress %, only for enrolled cards with a course
 * }
 */

defined('ABSPATH') || exit;

$edition = $args['edition'] ?? null;
$course  = $args['course'] ?? null;

// Early return if no edition
if (!$edition) {
    return;
}

// Helper to access edition properties (supports both object and array)
$get = function (string $key, $default = null) use ($edition) {
    if (is_object($edition)) {
        return $edition->{$key} ?? $default;
    }
    if (is_array($edition)) {
        return $edition[$key] ?? $default;
    }
    return $default;
};

// Get edition data
$edition_id      = $get('id') ?? $get('ID');
$edition_title   = $get('title');
$start_date      = $get('start_date');
$venue           = $get('venue') ?? $get('location');
$price           = $get('price');
$course_id       = $get('course_id');

// Status + spots come prefetched from the catalog pre-pass: the EFFECTIVE
// status (incl. the past-date override that flips stored "open" → Afgelopen)
// is decided once, in EditionService (INV-7), never per card here.
// Mid-flow fallback: a card whose prefetch data is missing renders from the
// stored array value — degraded but never fatal, never a per-card query.
$status          = isset($args['status']) ? (string) $args['status'] : (string) $get('status', 'open');
$spots_remaining = isset($args['spots_remaining']) ? (int) $args['spots_remaining'] : null;

// Fetch course if not provided but course_id available. Cache-hit: the
// pre-pass primed all course posts. Only a PUBLISHED course may leak its
// title/thumbnail into a public card (INF-1 — draft/private/trashed
// courses must not disclose); otherwise the card falls back to the
// edition title (no fatal).
if (!$course && $course_id && get_post_status($course_id) === 'publish') {
    $course = get_post($course_id);
}

// Get course data - link to edition detail page, not course
$edition_link = $edition_id ? get_permalink($edition_id) : '#';
$course_title = ($course instanceof WP_Post ? get_the_title($course) : null) ?: $edition_title ?: 'Cursus';

// Get thumbnail
$thumbnail = null;
if ($course instanceof WP_Post) {
    $thumbnail = get_the_post_thumbnail(
        $course,
        'stride_course_card',
        ['class' => 'w-full h-full object-cover transition-transform hover:scale-105'],
    );
}

// Enrolled state + progress come prefetched (one enrolled-set read + LD
// progress per enrolled card in the pre-pass — never a lookup per card).
$is_enrolled = (bool) ($args['is_enrolled'] ?? false);
$progress    = isset($args['progress']) ? (int) $args['progress'] : null;

$enrolled_badge = null;
if ($is_enrolled) {
    if ($progress !== null && $progress >= 100) {
        $enrolled_badge = ['class' => 'bg-success text-text-inverse', 'label' => __('Afgerond', 'stridence'), 'icon' => 'check'];
    } elseif ($progress !== null && $progress > 0) {
        $enrolled_badge = ['class' => 'bg-accent text-text-inverse', 'label' => sprintf(__('%d%% voltooid', 'stridence'), $progress), 'icon' => 'clock'];
    } else {
        $enrolled_badge = ['class' => 'bg-primary text-text-inverse', 'label' => __('Ingeschreven', 'stridence'), 'icon' => 'check'];
    }
}

?>
<article class="card overflow-hidden flex flex-col h-full">
    <!-- Thumbnail with badge overlay -->
    <a href="<?php echo esc_url($edition_link); ?>" class="block aspect-video overflow-hidden relative bg-surface-alt">
        <?php if ($thumbnail): ?>
            <?php echo $thumbnail; ?>
        <?php else: ?>
            <div class="w-full h-full flex items-center justify-center">
                <?php echo stridence_icon('book-open', 'w-12 h-12 text-text-muted'); ?>
            </div>
        <?php endif; ?>
        <!-- Status badge -->
        <div class="absolute top-3 right-3">
            <?php if ($enrolled_badge) : ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?php echo esc_attr($enrolled_badge['class']); ?>">
                    <?php echo stridence_icon($enrolled_badge['icon'], 'w-3 h-3'); ?>
                    <?php echo esc_html($enrolled_badge['label']); ?>
                </span>
            <?php else : ?>
                <?php stridence_template_part('partials/badge-status', null, [
                    'status' => $status,
                    'spots'  => $spots_remaining,
                ]); ?>
            <?php endif; ?>
        </div>
    </a>

    <div class="p-5 flex-1 flex flex-col">
        <h3 class="font-heading font-semibold text-lg mb-3 line-clamp-2">
            <a href="<?php echo esc_url($edition_link); ?>" class="text-text hover:text-primary transition-colors">
                <?php echo esc_html($course_title); ?>
            </a>
        </h3>

        <div class="space-y-2 mb-4 flex-1">
            <?php if ($start_date): ?>
                <!-- Date -->
                <div class="flex items-center gap-2 text-sm text-text-muted">
                    <?php echo stridence_icon('calendar', 'w-4 h-4 shrink-0'); ?>
                    <span><?php echo esc_html(stride_format_date($start_date)); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($venue): ?>
                <!-- Venue -->
                <div class="flex items-center gap-2 text-sm text-text-muted">
                    <?php echo stridence_icon('map-pin', 'w-4 h-4 shrink-0'); ?>
                    <span><?php echo esc_html($venue); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($price !== null): ?>
                <!-- Price -->
                <div class="flex items-center gap-2 text-sm font-semibold text-text">
                    <?php echo stridence_icon('receipt', 'w-4 h-4 shrink-0 text-text-muted'); ?>
                    <span><?php echo esc_html(stride_format_money((int) ($price * 100))); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- CTA button -->
        <a href="<?php echo esc_url($edition_link); ?>" class="btn-primary w-full text-center">
            <?php esc_html_e('Meer info', 'stridence'); ?>
        </a>
    </div>
</article>
