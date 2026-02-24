<?php
/**
 * Session Row Partial
 *
 * Renders a single session row with date block, time/location, and attendance status.
 *
 * @param array $args {
 *     @type object $session    Session object with date, start_time, end_time, location
 *     @type string $attendance Attendance status: 'present', 'absent', 'pending', or null
 * }
 */

defined('ABSPATH') || exit;

$session    = $args['session'] ?? null;
$attendance = $args['attendance'] ?? null;

// Early return if no session
if (!$session) {
    return;
}

// Handle flexible property names for date
$date = $session->date ?? $session->session_date ?? null;

// Handle flexible property names for location
$location = $session->location ?? $session->venue ?? '';

// Get time values
$start_time = $session->start_time ?? '';
$end_time   = $session->end_time ?? '';

// Format date parts (day number and month abbreviation)
$day   = $date ? stride_format_date($date, 'd') : '';
$month = $date ? strtoupper(substr(stride_format_date($date, 'F'), 0, 3)) : '';

// Attendance configuration: icon, color class, and Dutch label
$attendance_config = [
    'present' => [
        'icon'  => 'check-circle',
        'class' => 'text-success',
        'label' => 'Aanwezig',
    ],
    'absent' => [
        'icon'  => 'x-circle',
        'class' => 'text-error',
        'label' => 'Afwezig',
    ],
    'pending' => [
        'icon'  => 'clock',
        'class' => 'text-text-muted',
        'label' => 'Nog niet geregistreerd',
    ],
];

$att = $attendance ? ($attendance_config[$attendance] ?? null) : null;

?>
<div class="flex items-center justify-between py-3 border-b border-border last:border-0">
    <div class="flex items-center gap-4">
        <!-- Date block -->
        <div class="text-center min-w-[60px]">
            <div class="text-lg font-semibold text-text"><?php echo esc_html($day); ?></div>
            <div class="text-xs text-text-muted uppercase"><?php echo esc_html($month); ?></div>
        </div>
        <!-- Time + location -->
        <div>
            <div class="font-medium text-text"><?php echo esc_html($start_time); ?> - <?php echo esc_html($end_time); ?></div>
            <?php if ($location): ?>
                <div class="text-sm text-text-muted flex items-center gap-1">
                    <?php echo stridence_icon('map-pin', 'w-3 h-3'); ?>
                    <?php echo esc_html($location); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($att): ?>
        <!-- Attendance icon -->
        <div class="flex items-center gap-1 <?php echo esc_attr($att['class']); ?>" title="<?php echo esc_attr($att['label']); ?>">
            <?php echo stridence_icon($att['icon'], 'w-5 h-5'); ?>
            <span class="sr-only"><?php echo esc_html($att['label']); ?></span>
        </div>
    <?php endif; ?>
</div>
