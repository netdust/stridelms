<?php
/**
 * Notification Item Partial
 *
 * Renders a single notification row: a per-type icon tile, title, body, and
 * time. Unread rows get a tinted accent background + full-colour icon; read
 * rows a soft-bordered card with a muted icon.
 *
 * @param array $args {
 *     @type array $notification {
 *         @type string $id        Notification ID
 *         @type string $type      Type: 'enrollment', 'attendance', 'completion', 'certificate', 'session'
 *         @type string $title     Main text
 *         @type string $body      Secondary text (can be empty)
 *         @type string $url       Link URL
 *         @type int    $timestamp Unix timestamp
 *         @type bool   $read      Whether notification has been read
 *     }
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$notification = $args['notification'] ?? [];
if (empty($notification)) {
    return;
}

$id        = $notification['id'] ?? '';
$type      = $notification['type'] ?? 'action';
$title     = $notification['title'] ?? '';
$body      = $notification['body'] ?? '';
$url       = $notification['url'] ?? '#';
$timestamp = (int) ($notification['timestamp'] ?? 0);
$read      = (bool) ($notification['read'] ?? false);

// Per-type icon + colour so notifications are scannable at a glance.
// Keys mirror NotificationMapper's $type values; 'action' is the fallback.
// Tile backgrounds use an opacity modifier on a flat semantic colour
// (bg-success/10) — the theme only defines *-subtle for primary/accent, so a
// `bg-success-subtle` utility would not exist (renders blank).
$typeStyles = [
    'certificate' => ['icon' => 'award',         'fg' => 'text-success', 'bg' => 'bg-success/10'],
    'completion'  => ['icon' => 'check-circle',   'fg' => 'text-success', 'bg' => 'bg-success/10'],
    'enrollment'  => ['icon' => 'check-square',   'fg' => 'text-accent',  'bg' => 'bg-accent-subtle'],
    'attendance'  => ['icon' => 'map-pin',        'fg' => 'text-info',    'bg' => 'bg-info/10'],
    'session'     => ['icon' => 'calendar',       'fg' => 'text-primary', 'bg' => 'bg-primary-subtle'],
];
$style = $typeStyles[$type] ?? ['icon' => 'bell', 'fg' => 'text-accent', 'bg' => 'bg-accent-subtle'];
// Read rows mute the icon tile so unread items stay the visual anchor.
$iconFg = $read ? 'text-text-faint' : $style['fg'];
$iconBg = $read ? 'bg-surface-alt' : $style['bg'];

// Relative time in Dutch
$timeAgo = '';
if ($timestamp > 0) {
    $timeAgo = human_time_diff($timestamp, time()) . ' ' . __('geleden', 'stridence');
}
?>
<a href="<?php echo esc_url($url); ?>"
   class="flex items-center gap-3 p-4 rounded-[12px] transition-colors cursor-pointer <?php echo esc_attr($read ? 'bg-surface-card border border-border-soft hover:bg-surface-alt' : 'bg-accent-subtle/60 hover:bg-accent-subtle'); ?>">

    <!-- Per-type icon tile (replaces the generic unread dot) -->
    <span class="flex items-center justify-center w-9 h-9 rounded-full shrink-0 <?php echo esc_attr($iconBg); ?>">
        <?php echo stridence_icon($style['icon'], 'w-[18px] h-[18px] ' . $iconFg); ?>
    </span>

    <!-- Content -->
    <div class="flex-1 min-w-0">
        <p class="text-[14px] leading-snug m-0 <?php echo esc_attr($read ? 'font-semibold text-text-muted' : 'font-bold text-text'); ?>"><?php echo esc_html($title); ?></p>
        <?php if ($body !== '') : ?>
            <p class="text-[13px] text-text-muted mt-0.5 m-0"><?php echo esc_html($body); ?></p>
        <?php endif; ?>
    </div>

    <!-- Timestamp -->
    <?php if ($timeAgo !== '') : ?>
        <span class="text-[12px] text-text-faint whitespace-nowrap shrink-0 self-center">
            <?php echo esc_html($timeAgo); ?>
        </span>
    <?php endif; ?>
</a>
