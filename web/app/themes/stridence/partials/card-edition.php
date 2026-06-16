<?php
/**
 * Edition Card Partial — PURE RENDERER (Task G1 / audit 2.2 — Helder Tij).
 *
 * Renders an edition card per the Helder Tij sheet: badge row, 17px title,
 * 13px meta block with strong dates, footer with price + arrow CTA. The
 * whole card is the link — hover lift lives on this wrapper.
 *
 * All per-card lookups are data-in: callers run the catalog batch pre-pass
 * (helpers/catalog.php — stridence_catalog_render_cards()) and pass the
 * resolved values. This partial must NOT call services/repositories — that
 * is what made the catalog N+1 (CR-3). When prefetched keys are absent
 * (mid-flow fallback), the card renders from the stored edition values.
 *
 * Variants (driven ONLY by existing args):
 * - enrolled:  ✓ Ingeschreven badge + "Volgende sessie" meta + own CTA
 * - cancelled: opacity-85, muted title, alternatives CTA
 * - free:      Gratis badge + green "Gratis" footer price (price == 0)
 *
 * @param array $args {
 *     @type array        $edition         Edition data array with id/ID, start_date, venue/location, price, capacity, status
 *     @type WP_Post      $course          Optional course post for title
 *     @type string       $status          Prefetched EFFECTIVE status value (INV-7 — from EditionService::getEffectiveStatuses())
 *     @type int|null     $spots_remaining Prefetched spots remaining (null = unlimited/unknown)
 *     @type bool         $is_enrolled     Prefetched: current user has a confirmed registration
 *     @type int|null     $progress        Prefetched LD progress %, only for enrolled cards with a course
 * }
 */

defined('ABSPATH') || exit;

$edition = $args['edition'] ?? null;
$course  = $args['course'] ?? null;

// Early return if no edition array (all call sites pass arrays — catalog.php
// builds them; the guard keeps a stray non-array degraded, never fatal).
if (!is_array($edition) || !$edition) {
    return;
}

// Get edition data — direct array access, key fallbacks preserved from the
// old object/array accessor.
$edition_id      = $edition['id'] ?? $edition['ID'] ?? 0;
$edition_title   = $edition['title'] ?? null;
$start_date      = $edition['start_date'] ?? null;
$venue           = $edition['venue'] ?? $edition['location'] ?? null;
$price           = $edition['price'] ?? null;
$course_id       = $edition['course_id'] ?? null;

// Status + spots come prefetched from the catalog pre-pass: the EFFECTIVE
// status (incl. the past-date override that flips stored "open" → Afgelopen)
// is decided once, in EditionService (INV-7), never per card here.
// Mid-flow fallback: a card whose prefetch data is missing renders from the
// stored array value — degraded but never fatal, never a per-card query.
$status          = isset($args['status']) ? (string) $args['status'] : (string) ($edition['status'] ?? 'open');
$spots_remaining = isset($args['spots_remaining']) ? (int) $args['spots_remaining'] : null;

// Defensive mirror of the eligible-items builder filter (shake-out F2):
// an edition whose course is no longer published must not render a card
// at all — not even the edition-title fallback. Catches stale item arrays
// built before the course was trashed (mid-flow). Cache-hit when the
// pre-pass primed course posts; no fatal either way.
if ($course_id && get_post_status($course_id) !== 'publish') {
    return;
}

// Fetch course if not provided but course_id available. Cache-hit: the
// pre-pass primed all course posts. Only a PUBLISHED course may leak its
// title into a public card (INF-1 — draft/private/trashed courses must
// not disclose); otherwise the card falls back to the edition title
// (no fatal).
if (!$course && $course_id && get_post_status($course_id) === 'publish') {
    $course = get_post($course_id);
}

// Get course data - link to edition detail page, not course
$edition_link = $edition_id ? get_permalink($edition_id) : '#';
$course_title = ($course instanceof WP_Post ? get_the_title($course) : null) ?: $edition_title ?: 'Cursus';

// Enrolled state + progress come prefetched (one enrolled-set read + LD
// progress per enrolled card in the pre-pass — never a lookup per card).
$is_enrolled = (bool) ($args['is_enrolled'] ?? false);
$progress    = isset($args['progress']) ? (int) $args['progress'] : null;

// Variant flags — all derived from args already passed, no new data flow.
$is_cancelled = ($status === 'cancelled');
$is_free      = ($price !== null && (float) $price <= 0);

// Interest variant — the "Binnenkort — toon interesse" anchor. Keyed off the
// EFFECTIVE status (INV-7), NOT date-absence: a KLASSIKAAL dateless edition
// resolves to 'announcement' (allowsInterest), while an ONLINE dateless edition
// stays 'open' (allowsEnrollment) and therefore renders a normal enroll card.
// This is the whole reason online always-on editions need no special-casing
// here (Stefan, 2026-06-14). Pure data-in: $status is already passed by the
// catalog pre-pass; no service call.
$is_interest = ($status === 'announcement') && !$is_cancelled && !$is_enrolled;

// Sheet pill recipe (card size 'sm') for the enrolled-progress badges the
// badge-status partial has no variant for — exact recipe classes inline.
$pill_sm = 'text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1';

// CTA label per variant.
if ($is_cancelled) {
    $cta_label = __('Bekijk alternatieven', 'stridence');
} elseif ($is_enrolled) {
    $cta_label = __('Bekijk je inschrijving', 'stridence');
} elseif ($is_interest) {
    $cta_label = __('Toon interesse', 'stridence');
} else {
    $cta_label = __('Bekijk editie', 'stridence');
}

?>
<a href="<?php echo esc_url($edition_link); ?>"
   class="bg-surface-card rounded-[14px] shadow-card p-6 flex flex-col gap-3.5 h-full text-text transition-all duration-normal ease-out hover:shadow-elevated hover:-translate-y-0.5<?php echo $is_cancelled ? ' opacity-85' : ''; ?>">

    <!-- Badge row -->
    <div class="flex gap-1.5 flex-wrap">
        <?php stridence_template_part('partials/badge-status', null, [
            'status' => $status,
            'spots'  => $spots_remaining,
            'size'   => 'sm',
        ]); ?>

        <?php if ($is_free && !$is_cancelled) : ?>
            <?php stridence_template_part('partials/badge-status', null, [
                'status' => 'free',
                'size'   => 'sm',
            ]); ?>
        <?php endif; ?>

        <?php if ($is_enrolled && !$is_cancelled) : ?>
            <?php if ($progress !== null && $progress >= 100) : ?>
                <span class="<?php echo esc_attr($pill_sm); ?> bg-badge-free-bg text-badge-free-text"><?php esc_html_e('Afgerond', 'stridence'); ?></span>
            <?php elseif ($progress !== null && $progress > 0) : ?>
                <span class="<?php echo esc_attr($pill_sm); ?> bg-badge-free-bg text-badge-free-text"><?php
                    /* translators: %d: completion percentage */
                    echo esc_html(sprintf(__('%d%% voltooid', 'stridence'), $progress));
                ?></span>
            <?php else : ?>
                <?php stridence_template_part('partials/badge-status', null, [
                    'status' => 'enrolled',
                    'size'   => 'sm',
                ]); ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Title -->
    <h3 class="text-[17px] font-bold leading-snug text-pretty line-clamp-2 <?php echo $is_cancelled ? 'text-text-muted' : 'text-text'; ?>">
        <?php echo esc_html($course_title); ?>
    </h3>

    <!-- Meta block -->
    <div class="flex flex-col gap-1.5 text-[13px] text-text-muted">
        <?php if ($is_cancelled) : ?>
            <div>
                <?php
                echo esc_html(
                    $is_enrolled
                        ? __('Deze editie werd geannuleerd. Je werd per e-mail verwittigd.', 'stridence')
                        : __('Deze editie werd geannuleerd.', 'stridence'),
                );
            ?>
            </div>
        <?php elseif ($is_interest && !$start_date) : ?>
            <?php // Dateless interest anchor (effective status Announcement): no
              // date to show — surface the interest framing instead.?>
            <div class="font-semibold text-text"><?php esc_html_e('Geen datum — toon interesse', 'stridence'); ?></div>
        <?php else : ?>
            <?php if ($start_date) : ?>
                <div>
                    <?php if ($is_enrolled) : ?>
                        <strong class="text-text font-semibold"><?php esc_html_e('Volgende sessie:', 'stridence'); ?></strong>
                        <?php echo esc_html(stride_format_date($start_date)); ?>
                    <?php else : ?>
                        <strong class="text-text font-semibold"><?php echo esc_html(stride_format_date($start_date)); ?></strong>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($venue) : ?>
                <div><?php echo esc_html($venue); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Footer row -->
    <?php $show_price = !$is_cancelled && !$is_enrolled && $price !== null; ?>
    <div class="mt-auto pt-1 flex items-center gap-3 <?php echo $show_price ? 'justify-between' : 'justify-end'; ?>">
        <?php if ($show_price) : ?>
            <?php if ($is_free) : ?>
                <span class="text-[16px] font-extrabold text-badge-free-text"><?php esc_html_e('Gratis', 'stridence'); ?></span>
            <?php else : ?>
                <span class="text-[16px] font-extrabold"><?php echo esc_html(stride_format_money((int) ($price * 100))); ?></span>
            <?php endif; ?>
        <?php endif; ?>
        <span class="text-sm font-bold text-primary"><?php echo esc_html($cta_label); ?> &rarr;</span>
    </div>
</a>
