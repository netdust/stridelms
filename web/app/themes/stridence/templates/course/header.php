<?php
/**
 * Course Header Template Part
 *
 * @param array $args {
 *     @type int   $course_id   Course post ID
 *     @type array $breadcrumbs Breadcrumb items
 *     @type bool  $is_online   Whether course is online
 *     @type array $editions    Editions for this course (optional, in-person only)
 * }
 */

defined('ABSPATH') || exit;

use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionService;

$course_id   = $args['course_id'] ?? get_the_ID();
$breadcrumbs = $args['breadcrumbs'] ?? [];
$is_online   = $args['is_online'] ?? false;
$editions    = $args['editions'] ?? [];

// Online meta (Helder Tij): only render segments whose data actually exists.
// Duration and "afsluitende toets" have no live source yet — omitted, see
// docs/plans/2026-06-11-helder-tij-field-inventory.md.
$is_free_course = false;
$module_count   = 0;

if ($is_online) {
    $access_mode    = LearnDashHelper::getAccessMode($course_id);
    $is_free_course = in_array($access_mode, [LearnDashHelper::MODE_OPEN, LearnDashHelper::MODE_FREE], true);
    $module_count   = count(LearnDashHelper::getLessons($course_id));
}

// For in-person courses, the header shows a course-level meta line: next upcoming
// edition date, upcoming count, price range. The aggregation policy (optional-price
// semantics, the "upcoming" date boundary) lives in EditionService::getCourseHeaderSummary
// (Cluster 3 / B5) — the template only renders the returned struct.
$next_edition_date = null;
$upcoming_count    = 0;
$price_min_cents   = null;
$price_max_cents   = null;

if (!$is_online && !empty($editions)) {
    $summary           = ntdst_get(EditionService::class)->getCourseHeaderSummary((int) $course_id);
    $next_edition_date = $summary['next_edition_date'];
    $upcoming_count    = $summary['upcoming_count'];
    $price_min_cents   = $summary['price_min_cents'];
    $price_max_cents   = $summary['price_max_cents'];
}

?>
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <?php
        stridence_template_part('partials/breadcrumb', null, [
            'items' => $breadcrumbs,
        ]);
?>

        <!-- Badge row (Helder Tij) -->
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($is_online) : ?>
                <?php stridence_template_part('partials/badge-status', null, ['status' => 'online']); ?>
                <?php if ($is_free_course) : ?>
                    <?php stridence_template_part('partials/badge-status', null, ['status' => 'free']); ?>
                <?php endif; ?>
            <?php else : ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-primary text-text-inverse">
                    <?php echo stridence_icon('map-pin', 'w-3 h-3'); ?>
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </span>
            <?php endif; ?>
        </div>

        <h1 class="font-serif font-normal text-[clamp(30px,4.5vw,44px)] leading-[1.12] text-text max-w-[760px] mt-3.5 mb-3">
            <?php echo get_the_title($course_id); ?>
        </h1>

        <?php if (has_excerpt($course_id)) : ?>
            <p class="text-lg text-text-muted max-w-3xl mb-3">
                <?php echo esc_html(get_the_excerpt($course_id)); ?>
            </p>
        <?php endif; ?>

        <?php if ($is_online) : ?>
            <!-- Meta dot-row — only segments with live data (no fake data) -->
            <div class="flex flex-wrap items-center gap-[10px] text-[15px] text-text-muted">
                <span><?php esc_html_e('Op eigen tempo', 'stridence'); ?></span>
                <?php if ($module_count > 0) : ?>
                    <span class="text-text-faint" aria-hidden="true">&middot;</span>
                    <span><?php
                        /* translators: %d: number of course modules/lessons */
                        echo esc_html(sprintf(_n('%d module', '%d modules', $module_count, 'stridence'), $module_count));
                    ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$is_online && ($next_edition_date || $upcoming_count > 0 || $price_min_cents !== null)) : ?>
            <div class="flex flex-wrap gap-6 text-text-muted">
                <?php if ($next_edition_date) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('calendar', 'w-5 h-5'); ?>
                        <?php
            echo esc_html(sprintf(
                __('Volgende editie: %s', 'stridence'),
                stride_format_date($next_edition_date),
            ));
                    ?>
                    </span>
                <?php endif; ?>

                <?php if ($upcoming_count > 1) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('layers', 'w-5 h-5'); ?>
                        <?php
                    echo esc_html(sprintf(
                        _n('%d geplande editie', '%d geplande edities', $upcoming_count, 'stridence'),
                        $upcoming_count,
                    ));
                    ?>
                    </span>
                <?php endif; ?>

                <?php if ($price_min_cents !== null) : ?>
                    <span class="flex items-center gap-2 font-semibold text-text">
                        <?php echo stridence_icon('receipt', 'w-5 h-5 text-text-muted'); ?>
                        <?php
                    if ($price_min_cents === $price_max_cents) {
                        echo esc_html(stride_format_money($price_min_cents));
                    } else {
                        echo esc_html(sprintf(
                            __('vanaf %s', 'stridence'),
                            stride_format_money($price_min_cents),
                        ));
                    }
            ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
