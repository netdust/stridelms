<?php
/**
 * Trajectory Card Partial — shared by the public catalog AND the dashboard.
 *
 * Pure renderer: ALL data arrives via the normalized args contract built by
 * stridence_build_trajectory_card_args() — no service calls or meta lookups
 * here. Per-user state (progress/started_at/dashboard_url) is present only on
 * the dashboard; its absence makes this a catalog card.
 *
 * @param array $args {
 *     @type int         $id             Trajectory post id (permalink base).
 *     @type string      $title
 *     @type string      $status         OfferingStatus value (badge).
 *     @type int         $course_count
 *     @type int         $elective_count Number of elective groups.
 *     @type float       $price          Euros (0 → "Gratis").
 *     @type string      $deadline       '' or Y-m-d.
 *     @type int|null    $progress       0-100 when enrolled; null on catalog.
 *     @type string      $started_at     '' or Y-m-d (enrollment date).
 *     @type string      $dashboard_url  '' or the /mijn-account/trajecten/<slug>/ url.
 *     @type string      $mode           'catalog' (default) | 'dashboard'.
 * }
 * @package stridence
 */

defined('ABSPATH') || exit;

$id            = (int) ($args['id'] ?? 0);
if (!$id) {
    return;
}
$title         = (string) ($args['title'] ?? '');
$status        = (string) ($args['status'] ?? 'open');
$course_count  = (int) ($args['course_count'] ?? 0);
$elective_count = (int) ($args['elective_count'] ?? 0);
$price         = (float) ($args['price'] ?? 0);
$deadline      = (string) ($args['deadline'] ?? '');
$progress      = $args['progress'] ?? null;
$started_at    = (string) ($args['started_at'] ?? '');
$mode          = ($args['mode'] ?? 'catalog') === 'dashboard' ? 'dashboard' : 'catalog';
$enrolled      = $progress !== null;

$permalink     = get_permalink($id);
$target_url    = ($mode === 'dashboard' && !empty($args['dashboard_url']))
    ? (string) $args['dashboard_url']
    : $permalink;

// Map trajectory status to a frontend badge variant.
$badge_status_map = [
    'open'         => 'open',
    'announcement' => 'announcement',
    'ongoing'      => 'pending',
    'in_progress'  => 'pending',
    'completed'    => 'completed',
    'draft'        => 'pending',
    'closed'       => 'cancelled',
];
$badge_status = $badge_status_map[$status] ?? 'open';

// The card is a link only on the catalog (whole-card link); the dashboard
// uses an explicit "Open traject" button so the card body can hold a CTA.
$card_classes = 'bg-surface-card rounded-[14px] shadow-card p-6 flex flex-col gap-3.5 h-full text-text';
$is_link      = $mode === 'catalog';
?>
<<?php echo $is_link ? 'a href="' . esc_url($target_url) . '"' : 'div'; ?>
   class="<?php echo esc_attr($card_classes); ?><?php echo $is_link ? ' transition-all duration-normal ease-out hover:shadow-elevated hover:-translate-y-0.5' : ''; ?>">

    <!-- Badge row -->
    <div class="flex gap-1.5 flex-wrap items-center">
        <?php stridence_template_part('partials/badge-status', null, ['status' => 'trajectory', 'size' => 'sm']); ?>
        <?php stridence_template_part('partials/badge-status', null, ['status' => $badge_status, 'size' => 'sm']); ?>
        <?php if ($enrolled) : ?>
            <span class="text-[12px] font-bold px-[11px] py-1 rounded-full inline-flex items-center gap-1 bg-badge-online-bg text-badge-online-text">
                <?php
                /* translators: %d: completion percentage */
                echo esc_html(sprintf(__('%d%% voltooid', 'stridence'), (int) $progress));
                ?>
            </span>
        <?php endif; ?>
    </div>

    <!-- Title -->
    <h3 class="text-[17px] font-bold leading-snug text-pretty text-text line-clamp-2">
        <?php echo esc_html($title); ?>
    </h3>

    <!-- Meta block: course count (+ keuzemodules), then the date line -->
    <div class="flex flex-col gap-1.5 text-[13px] text-text-muted">
        <?php if ($course_count > 0) : ?>
            <div>
                <strong class="text-text font-semibold">
                    <?php echo esc_html(sprintf(_n('%d opleiding', '%d opleidingen', $course_count, 'stridence'), $course_count)); ?>
                </strong>
                <?php if ($elective_count > 0) : ?>
                    <span class="mx-1 opacity-40">&middot;</span>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of elective (choice) modules */
                        _n('waarvan %d keuzemodule', 'waarvan %d keuzemodules', $elective_count, 'stridence'),
                        $elective_count,
                    )); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($enrolled && $started_at !== '') : ?>
            <div>
                <?php
                /* translators: %s: start month + year */
                echo esc_html(sprintf(__('Gestart %s', 'stridence'), date_i18n('F Y', strtotime($started_at))));
                ?>
            </div>
        <?php elseif (!$enrolled && $deadline !== '') : ?>
            <div>
                <?php
                /* translators: %s: enrollment deadline date */
                echo esc_html(sprintf(__('Inschrijven tot %s', 'stridence'), stride_format_date($deadline)));
                ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Price -->
    <div class="text-[18px] font-extrabold <?php echo $price > 0 ? 'text-text' : 'text-badge-free-text'; ?>">
        <?php echo $price > 0 ? esc_html(stride_format_money((int) round($price * 100))) : esc_html__('Gratis', 'stridence'); ?>
    </div>

    <!-- Footer -->
    <div class="mt-auto pt-1 flex items-center justify-end gap-3">
        <?php if ($mode === 'dashboard') : ?>
            <a href="<?php echo esc_url($target_url); ?>" class="btn-primary btn-sm">
                <?php esc_html_e('Open traject', 'stridence'); ?>
            </a>
        <?php else : ?>
            <span class="text-sm font-bold text-primary"><?php esc_html_e('Bekijk traject', 'stridence'); ?> &rarr;</span>
        <?php endif; ?>
    </div>
</<?php echo $is_link ? 'a' : 'div'; ?>>
