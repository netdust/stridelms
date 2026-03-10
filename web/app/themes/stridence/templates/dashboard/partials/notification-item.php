<?php
/**
 * Notification Item Partial
 *
 * Renders a single notification row with unread indicator, icon, title, body, and time.
 *
 * @param array $args {
 *     @type array $notification {
 *         @type string $id        Notification ID
 *         @type string $type      Type: 'session', 'action', 'certificate', 'quote'
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

// Icon and color per type
[$icon, $iconColor, $iconBg] = match ($type) {
    'session'     => ['calendar', 'text-blue-600', 'bg-blue-50'],
    'certificate' => ['award', 'text-green-600', 'bg-green-50'],
    'quote'       => ['file-text', 'text-amber-600', 'bg-amber-50'],
    default       => ['bell', 'text-primary', 'bg-primary/10'],
};

// Relative time in Dutch
$timeAgo = '';
if ($timestamp > 0) {
    $timeAgo = human_time_diff($timestamp, current_time('timestamp')) . ' ' . __('geleden', 'stridence');
}
?>
<a href="<?php echo esc_url($url); ?>"
   class="notification-item flex items-start gap-3 px-4 py-3 rounded-lg border border-border/60 bg-surface-card hover:border-primary/25 transition-colors<?php echo !$read ? ' notification-unread' : ''; ?>">

    <!-- Unread dot + icon -->
    <div class="relative shrink-0">
        <span class="w-8 h-8 rounded-lg <?php echo esc_attr($iconBg); ?> flex items-center justify-center">
            <?php echo stridence_icon($icon, 'w-4 h-4 ' . $iconColor); ?>
        </span>
        <?php if (!$read) : ?>
            <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-primary ring-2 ring-surface-card"></span>
        <?php endif; ?>
    </div>

    <!-- Content -->
    <div class="flex-1 min-w-0">
        <p class="text-sm font-medium text-text truncate"><?php echo esc_html($title); ?></p>
        <?php if ($body !== '') : ?>
            <p class="text-xs text-text-muted truncate mt-0.5"><?php echo esc_html($body); ?></p>
        <?php endif; ?>
    </div>

    <!-- Timestamp -->
    <?php if ($timeAgo !== '') : ?>
        <span class="text-xs text-text-muted whitespace-nowrap shrink-0 pt-0.5">
            <?php echo esc_html($timeAgo); ?>
        </span>
    <?php endif; ?>
</a>
