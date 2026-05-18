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

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;

$course_id   = $args['course_id'] ?? get_the_ID();
$breadcrumbs = $args['breadcrumbs'] ?? [];
$is_online   = $args['is_online'] ?? false;
$editions    = $args['editions'] ?? [];

// For in-person courses, compute a header meta line: next upcoming edition date,
// upcoming count, price range. This matches the visual weight of the edition page's
// meta line (date / venue / price) but with course-level info.
$next_edition_date = null;
$upcoming_count    = 0;
$price_min_cents   = null;
$price_max_cents   = null;

if (!$is_online && !empty($editions)) {
    $editionService = ntdst_get(EditionService::class);
    $editionRepo    = ntdst_get(EditionRepository::class);
    $today_ts       = strtotime(date('Y-m-d'));

    foreach ($editions as $edition) {
        $eid = (int) ($edition['id'] ?? $edition['ID'] ?? 0);
        if (!$eid) {
            continue;
        }
        $start = (string) $editionRepo->getField($eid, 'start_date', '');
        $start_ts = $start ? strtotime($start) : 0;
        if (!$start_ts || $start_ts < $today_ts) {
            continue;
        }
        $upcoming_count++;
        if ($next_edition_date === null || $start_ts < strtotime($next_edition_date)) {
            $next_edition_date = $start;
        }
        try {
            $price = $editionService->getPrice($eid);
            $cents = $price ? $price->inCents() : 0;
            if ($cents > 0) {
                $price_min_cents = $price_min_cents === null ? $cents : min($price_min_cents, $cents);
                $price_max_cents = $price_max_cents === null ? $cents : max($price_max_cents, $cents);
            }
        } catch (\Throwable $e) {
            // Ignore — price is optional in header
        }
    }
}

?>
<div class="bg-surface-alt border-b border-border">
    <div class="container py-8 lg:py-12">
        <?php
        stridence_template_part('partials/breadcrumb', null, [
            'items' => $breadcrumbs,
        ]);
        ?>

        <!-- Format badge -->
        <div class="flex items-center gap-2 mb-4">
            <?php if ($is_online) :
                // Distinguish e-learning vs webinar via stride_format taxonomy
                $format_terms = get_the_terms($course_id, 'stride_format');
                $format_slugs = (!empty($format_terms) && !is_wp_error($format_terms))
                    ? wp_list_pluck($format_terms, 'slug')
                    : [];

                if (in_array('e-learning', $format_slugs, true)) {
                    $format_label = __('E-learning', 'stridence');
                    $format_icon = 'monitor';
                } elseif (in_array('webinar', $format_slugs, true)) {
                    $format_label = __('Webinar', 'stridence');
                    $format_icon = 'video';
                } else {
                    $format_label = __('Online cursus', 'stridence');
                    $format_icon = 'wifi';
                }
            ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-accent text-text-inverse">
                    <?php echo stridence_icon($format_icon, 'w-3 h-3'); ?>
                    <?php echo esc_html($format_label); ?>
                </span>
            <?php else : ?>
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium bg-primary text-text-inverse">
                    <?php echo stridence_icon('map-pin', 'w-3 h-3'); ?>
                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                </span>
            <?php endif; ?>
        </div>

        <h1 class="font-heading text-3xl lg:text-4xl font-bold text-text mb-4">
            <?php echo get_the_title($course_id); ?>
        </h1>

        <?php if (has_excerpt($course_id)) : ?>
            <p class="text-lg text-text-muted max-w-3xl mb-4">
                <?php echo esc_html(get_the_excerpt($course_id)); ?>
            </p>
        <?php endif; ?>

        <?php if (!$is_online && ($next_edition_date || $upcoming_count > 0 || $price_min_cents !== null)) : ?>
            <div class="flex flex-wrap gap-6 text-text-muted">
                <?php if ($next_edition_date) : ?>
                    <span class="flex items-center gap-2">
                        <?php echo stridence_icon('calendar', 'w-5 h-5'); ?>
                        <?php
                        echo esc_html(sprintf(
                            __('Volgende editie: %s', 'stridence'),
                            stride_format_date($next_edition_date)
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
                            $upcoming_count
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
                                stride_format_money($price_min_cents)
                            ));
                        }
                        ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
