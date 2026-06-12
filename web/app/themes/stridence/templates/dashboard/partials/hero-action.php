<?php
/**
 * Hero Action Partial — Helder Tij next-step band.
 *
 * Single most important next step for the dashboard home screen, rendered
 * as the tinted band from the design sheet: uppercase eyebrow, bold title,
 * muted sub line, primary CTA. Content branches per hero type resolved by
 * UserDashboardService::resolveHero() — data shape unchanged.
 *
 * @param array $args {
 *     @type array $hero {
 *         @type string $type Hero type: upcoming_session, action_required, continue_course, active_enrollment, certificate_ready
 *         @type array  $data Type-specific data
 *     }
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$hero = $args['hero'] ?? null;
if (!$hero || empty($hero['type']) || empty($hero['data'])) {
    return;
}

$type = $hero['type'];
$data = $hero['data'];

$title    = (string) ($data['course_title'] ?? '');
$subParts = [];
$cta      = null; // ['url' => string, 'label' => string, 'external' => bool]

switch ($type) {
    case 'upcoming_session':
        $isToday = ($data['date'] ?? '') === wp_date('Y-m-d');
        $eyebrow = $isToday ? __('Vandaag', 'stridence') : __('Binnenkort', 'stridence');
        if (!empty($data['date'])) {
            $subParts[] = stride_format_date($data['date']);
        }
        if (!empty($data['start_time'])) {
            $time = $data['start_time'];
            if (!empty($data['end_time'])) {
                $time .= ' – ' . $data['end_time'];
            }
            $subParts[] = $time;
        }
        if (!empty($data['venue'])) {
            $subParts[] = $data['venue'];
        }
        break;

    case 'action_required':
        $eyebrow = __('Actie vereist', 'stridence');
        if (!empty($data['label'])) {
            $subParts[] = $data['label'];
        }
        if (!empty($data['url'])) {
            $cta = [
                'url'   => $data['url'],
                'label' => !empty($data['label']) ? $data['label'] : __('Voltooien', 'stridence'),
            ];
        }
        break;

    case 'continue_course':
        $eyebrow    = __('Ga verder', 'stridence');
        $subParts[] = $data['format_label'] ?? __('Online', 'stridence');
        if (($data['total_lessons'] ?? 0) > 0) {
            $subParts[] = sprintf(
                __('%d van %d lessen', 'stridence'),
                (int) ($data['completed_lessons'] ?? 0),
                (int) $data['total_lessons'],
            );
        }
        if (!empty($data['course_url'])) {
            $cta = ['url' => $data['course_url'], 'label' => __('Verder leren', 'stridence')];
        }
        break;

    case 'active_enrollment':
        $eyebrow = __('Actieve opleiding', 'stridence');
        if (!empty($data['start_date'])) {
            $subParts[] = stride_format_date($data['start_date']);
        }
        break;

    case 'certificate_ready':
        $eyebrow = __('Gefeliciteerd!', 'stridence');
        if (!empty($data['certificate_url'])) {
            $cta = [
                'url'      => $data['certificate_url'],
                'label'    => __('Download certificaat', 'stridence'),
                'external' => true,
            ];
        }
        break;

    default:
        return;
}
?>

<div class="bg-badge-online-bg rounded-[16px] p-6 lg:p-7 flex flex-wrap items-center justify-between gap-5">
    <div class="flex-1 min-w-[240px]">
        <div class="text-[12px] font-bold uppercase tracking-wide text-badge-online-text">
            <?php echo esc_html($eyebrow); ?>
        </div>
        <h3 class="text-[19px] font-bold text-text leading-snug mt-2">
            <?php echo esc_html($title); ?>
        </h3>
        <?php if (!empty($subParts)) : ?>
            <p class="text-[14px] text-text-muted mt-1">
                <?php echo esc_html(implode(' · ', $subParts)); ?>
            </p>
        <?php endif; ?>
    </div>
    <?php if ($cta) : ?>
        <a href="<?php echo esc_url($cta['url']); ?>"
           class="btn-primary shrink-0"
           <?php echo !empty($cta['external']) ? 'target="_blank" rel="noopener"' : ''; ?>>
            <?php echo esc_html($cta['label']); ?>
        </a>
    <?php endif; ?>
</div>
