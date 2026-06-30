<?php
/**
 * Course Editions List — discovery view, rendered in main content column
 *
 * Lists scheduled editions for a course, separated into Komende (upcoming)
 * and Voorbije (past). Each row is a clickable list item linking to the
 * edition's detail page where the actual enrollment/interest/waitlist CTA
 * lives. No CTAs here — discovery surface, not transactional.
 *
 * Row info: date(s), venue, session count, price, status badge.
 *
 * Pure renderer: the visibility policy, upcoming/past partition and sort are owned
 * by EditionService::getPubliclyVisibleEditions($course_id, $user_id) — this template
 * only renders the returned {upcoming, past} struct.
 *
 * @param array $args {
 *     @type int  $course_id Course post ID
 *     @type bool $is_online Whether this is a pure-LD online course (no editions)
 * }
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionService;

$course_id = (int) ($args['course_id'] ?? 0);
$is_online = (bool) ($args['is_online'] ?? false);
$user_id   = get_current_user_id();

// The visibility POLICY + upcoming/past PARTITION + sort live in stride-core now
// (EditionService::getPubliclyVisibleEditions, Cluster 3 / Task 3.6 / B6) — this
// template is a pure renderer over the returned struct. INV-7 effective status,
// the enrolled-exception, and the optional-price try/catch are all owned by the
// method; the template no longer reaches into the repository or duplicates the rule.
$visible  = ntdst_get(EditionService::class)->getPubliclyVisibleEditions($course_id, $user_id ?: null);
$upcoming = $visible['upcoming'];
$past     = $visible['past'];

/**
 * Renders one edition row — matches dashboard tab-inschrijvingen visual rhythm
 * (border card, hover state, info-dense single row).
 */
$render_edition_row = function (array $row, bool $faded = false) {
    $faded_class = $faded ? 'opacity-70' : '';
    $border_class = $row['is_enrolled'] ? 'border-primary/30 bg-primary/5' : 'border-border bg-surface-card';

    $date_label = '';
    if ($row['start_date']) {
        $date_label = stride_format_date($row['start_date']);
        if ($row['end_date'] && $row['end_date'] !== $row['start_date']) {
            $date_label .= ' – ' . stride_format_date($row['end_date']);
        }
    }
    ?>
    <a href="<?php echo esc_url($row['permalink']); ?>"
       class="flex items-center gap-4 p-4 border rounded-lg hover:border-primary/40 hover:shadow-sm transition <?php echo esc_attr($border_class . ' ' . $faded_class); ?>">

        <!-- Date column (left) -->
        <div class="shrink-0 w-20 text-center">
            <?php if ($row['start_date']) :
                $start_ts = strtotime($row['start_date']);
                ?>
                <div class="text-xs uppercase text-text-muted tracking-wide leading-tight">
                    <?php echo esc_html(date_i18n('M', $start_ts)); ?>
                </div>
                <div class="text-2xl font-bold text-text leading-tight">
                    <?php echo esc_html(date_i18n('j', $start_ts)); ?>
                </div>
                <div class="text-xs text-text-muted leading-tight">
                    <?php echo esc_html(date_i18n('Y', $start_ts)); ?>
                </div>
            <?php else : ?>
                <div class="text-xs text-text-muted">
                    <?php esc_html_e('Datum onbekend', 'stridence'); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Body (middle, grows) -->
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1 flex-wrap">
                <?php if ($row['is_enrolled']) : ?>
                    <span class="inline-flex items-center gap-1 text-xs font-medium text-status-success bg-status-success-subtle px-2 py-0.5 rounded-full">
                        <?php echo stridence_icon('check-circle', 'w-3.5 h-3.5'); ?>
                        <?php esc_html_e('Ingeschreven', 'stridence'); ?>
                    </span>
                <?php else : ?>
                    <span class="inline-flex items-center text-xs font-medium px-2 py-0.5 rounded-full bg-surface-alt text-text-muted">
                        <?php echo esc_html($row['status']->label()); ?>
                    </span>
                <?php endif; ?>
                <?php if ($row['end_date'] && $row['end_date'] !== $row['start_date']) : ?>
                    <span class="text-xs text-text-muted">
                        <?php echo esc_html(sprintf(__('t.e.m. %s', 'stridence'), stride_format_date($row['end_date']))); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-4 text-sm text-text-muted flex-wrap">
                <?php if (!empty($row['venue'])) : ?>
                    <span class="inline-flex items-center gap-1 min-w-0">
                        <?php echo stridence_icon('map-pin', 'w-4 h-4 shrink-0'); ?>
                        <span class="truncate"><?php echo esc_html($row['venue']); ?></span>
                    </span>
                <?php endif; ?>
                <?php if ($row['session_count'] > 0) : ?>
                    <span class="inline-flex items-center gap-1">
                        <?php echo stridence_icon('calendar', 'w-4 h-4 shrink-0'); ?>
                        <?php echo esc_html(sprintf(_n('%d sessie', '%d sessies', $row['session_count'], 'stridence'), $row['session_count'])); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Price (right) -->
        <?php if (!$faded && $row['price_cents'] > 0) : ?>
            <div class="shrink-0 text-right">
                <div class="font-semibold text-text">
                    <?php echo esc_html(stride_format_money($row['price_cents'])); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Chevron -->
        <div class="shrink-0 text-text-muted">
            <?php echo stridence_icon('chevron-right', 'w-5 h-5'); ?>
        </div>
    </a>
    <?php
};
?>

<section id="edities" class="scroll-mt-32 mb-12">
    <h2 class="font-heading text-2xl font-bold text-text mb-6">
        <?php esc_html_e('Edities', 'stridence'); ?>
    </h2>

    <?php if (!empty($upcoming)) : ?>
        <div class="space-y-3">
            <?php foreach ($upcoming as $row) : ?>
                <?php $render_edition_row($row, false); ?>
            <?php endforeach; ?>
        </div>
    <?php elseif ($is_online && $course_id) : ?>
        <?php
        // Pure-LD online course: no scheduled editions exist. Treat the course
        // itself as the single enrollable instance — link to /edities/<course-slug>/
        // where the CTA chain lives.
        $course_post = get_post($course_id);
        $course_url  = $course_post ? home_url('/edities/' . $course_post->post_name . '/') : '';
        ?>
        <a href="<?php echo esc_url($course_url); ?>"
           class="flex items-center gap-4 p-4 border border-border bg-surface-card rounded-lg hover:border-primary/40 hover:shadow-sm transition">
            <div class="shrink-0 w-20 text-center">
                <?php echo stridence_icon('book-open', 'w-8 h-8 mx-auto text-primary'); ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                    <span class="inline-flex items-center text-xs font-medium px-2 py-0.5 rounded-full bg-surface-alt text-text-muted">
                        <?php esc_html_e('Direct beschikbaar', 'stridence'); ?>
                    </span>
                </div>
                <div class="text-sm text-text-muted">
                    <?php esc_html_e('Online cursus — leer in je eigen tempo.', 'stridence'); ?>
                </div>
            </div>
            <div class="shrink-0 text-text-muted">
                <?php echo stridence_icon('chevron-right', 'w-5 h-5'); ?>
            </div>
        </a>
    <?php else : ?>
        <div class="p-6 border border-border rounded-lg text-center">
            <p class="text-text-muted mb-4">
                <?php esc_html_e('Er staan momenteel geen nieuwe edities gepland.', 'stridence'); ?>
            </p>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-ghost">
                <?php esc_html_e('Contact opnemen', 'stridence'); ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if (!empty($past)) : ?>
        <details class="group mt-6">
            <summary class="cursor-pointer flex items-center gap-2 text-sm font-medium text-text-muted hover:text-text">
                <?php printf(esc_html(_n('%d voorbije editie tonen', '%d voorbije edities tonen', count($past), 'stridence')), count($past)); ?>
                <?php echo stridence_icon('chevron-down', 'w-4 h-4 group-open:rotate-180 transition'); ?>
            </summary>
            <div class="space-y-3 mt-4">
                <?php foreach ($past as $row) : ?>
                    <?php $render_edition_row($row, true); ?>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
</section>
