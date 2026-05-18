<?php
/**
 * Course Sidebar Edition Template — discovery view
 *
 * Lists scheduled editions for a course, separated into Komende (upcoming)
 * and Voorbije (past). Each row links to the edition's detail page where
 * the actual enrollment/interest/waitlist CTA lives. No CTAs on the course
 * page itself — this is a discovery surface, not a transactional one.
 *
 * When the course has no upcoming editions, surfaces a "Op de hoogte blijven"
 * course-level interest CTA (routes to /interesse/?course={id}).
 *
 * @param array $args {
 *     @type array $editions  Array of edition arrays from EditionService
 *     @type int   $course_id Course post ID
 * }
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Domain\OfferingStatus;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\EnrollmentService;

$editions  = $args['editions'] ?? [];
$course_id = (int) ($args['course_id'] ?? 0);
$user_id   = get_current_user_id();

$editionService    = ntdst_get(EditionService::class);
$enrollmentService = $user_id ? ntdst_get(EnrollmentService::class) : null;

$today = strtotime(date('Y-m-d'));

// Resolve each edition's status from the live service (the array's nested
// 'meta' key is unreliable — the service is the single source of truth).
$upcoming = [];
$past     = [];

foreach ($editions as $edition) {
    $edition_id = (int) ($edition['id'] ?? $edition['ID'] ?? 0);
    if (!$edition_id) {
        continue;
    }

    $start_date = $edition['start_date'] ?? ($edition['meta']['start_date'] ?? '');
    $venue      = $edition['venue'] ?? ($edition['meta']['venue'] ?? '');
    $status     = $editionService->getStatus($edition_id);
    $is_enrolled = $enrollmentService && $enrollmentService->isEnrolled($user_id, $edition_id);

    $row = [
        'id'          => $edition_id,
        'start_date'  => $start_date,
        'venue'       => $venue,
        'status'      => $status,
        'is_enrolled' => $is_enrolled,
        'permalink'   => get_permalink($edition_id),
    ];

    $start_ts = $start_date ? strtotime($start_date) : 0;
    if ($start_ts && $start_ts < $today) {
        $past[] = $row;
    } else {
        $upcoming[] = $row;
    }
}

// Sort: upcoming ascending (next first), past descending (most recent first)
usort($upcoming, fn($a, $b) => strcmp((string) $a['start_date'], (string) $b['start_date']));
usort($past, fn($a, $b) => strcmp((string) $b['start_date'], (string) $a['start_date']));

$render_edition_row = function (array $row, bool $faded = false) {
    $faded_class = $faded ? 'opacity-70' : '';
    $border_class = $row['is_enrolled'] ? 'border-primary/30 bg-primary/5' : 'border-border';
    ?>
    <a href="<?php echo esc_url($row['permalink']); ?>"
       class="block p-4 border rounded-lg hover:border-primary/40 hover:bg-surface-alt transition <?php echo esc_attr($border_class . ' ' . $faded_class); ?>">
        <div class="flex items-start justify-between mb-2">
            <div class="font-medium text-text">
                <?php if ($row['start_date']) : ?>
                    <?php echo esc_html(stride_format_date($row['start_date'])); ?>
                <?php else : ?>
                    <span class="text-text-muted"><?php esc_html_e('Datum nog niet bekend', 'stridence'); ?></span>
                <?php endif; ?>
            </div>
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
        </div>

        <?php if (!empty($row['venue'])) : ?>
            <div class="text-sm text-text-muted flex items-center gap-1">
                <?php echo stridence_icon('map-pin', 'w-4 h-4 shrink-0'); ?>
                <span><?php echo esc_html($row['venue']); ?></span>
            </div>
        <?php endif; ?>
    </a>
    <?php
};
?>
<aside class="card p-6 sticky top-24 space-y-6">

    <?php if (!empty($upcoming)) : ?>
        <div>
            <h3 class="font-heading font-semibold text-lg mb-4">
                <?php esc_html_e('Komende edities', 'stridence'); ?>
            </h3>
            <div class="space-y-3">
                <?php foreach ($upcoming as $row) : ?>
                    <?php $render_edition_row($row, false); ?>
                <?php endforeach; ?>
            </div>
        </div>
    <?php else : ?>
        <div>
            <h3 class="font-heading font-semibold text-lg mb-2">
                <?php esc_html_e('Geen edities gepland', 'stridence'); ?>
            </h3>
            <p class="text-text-muted text-sm mb-4">
                <?php esc_html_e('Er staan momenteel geen nieuwe edities gepland. Neem contact op voor meer informatie.', 'stridence'); ?>
            </p>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="btn-ghost w-full text-center block">
                <?php esc_html_e('Contact opnemen', 'stridence'); ?>
            </a>
        </div>
    <?php endif; ?>

    <?php if (!empty($past)) : ?>
        <details class="group">
            <summary class="cursor-pointer flex items-center justify-between text-sm font-medium text-text-muted hover:text-text">
                <span><?php printf(esc_html(_n('%d voorbije editie', '%d voorbije edities', count($past), 'stridence')), count($past)); ?></span>
                <?php echo stridence_icon('chevron-down', 'w-4 h-4 group-open:rotate-180 transition'); ?>
            </summary>
            <div class="space-y-3 mt-4">
                <?php foreach ($past as $row) : ?>
                    <?php $render_edition_row($row, true); ?>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>
</aside>
