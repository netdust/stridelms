<?php
/**
 * Notification Item Partial
 *
 * Renders a single notification row: unread accent dot, title, body, and time.
 * Unread rows get a tinted accent background; read rows a soft-bordered card.
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

// Relative time in Dutch
$timeAgo = '';
if ($timestamp > 0) {
    $timeAgo = human_time_diff($timestamp, time()) . ' ' . __('geleden', 'stridence');
}
?>
<a href="<?php echo esc_url($url); ?>"
   class="flex items-start gap-3 p-4 rounded-[12px] transition-colors cursor-pointer <?php echo esc_attr($read ? 'bg-surface-card border border-border-soft hover:bg-surface-alt' : 'bg-accent-subtle/60 hover:bg-accent-subtle'); ?>">

    <!-- Unread dot (transparent placeholder on read rows keeps text aligned) -->
    <span class="w-2 h-2 rounded-full mt-[6px] shrink-0 <?php echo esc_attr($read ? 'bg-transparent' : 'bg-accent'); ?>"></span>

    <!-- Content -->
    <div class="flex-1 min-w-0">
        <p class="text-[14px] m-0 <?php echo esc_attr($read ? 'font-semibold text-text-muted' : 'font-bold text-text'); ?>"><?php echo esc_html($title); ?></p>
        <?php if ($body !== '') : ?>
            <p class="text-[13px] text-text-muted mt-0.5 m-0"><?php echo esc_html($body); ?></p>
        <?php endif; ?>
    </div>

    <!-- Timestamp -->
    <?php if ($timeAgo !== '') : ?>
        <span class="text-[12px] text-text-faint whitespace-nowrap shrink-0">
            <?php echo esc_html($timeAgo); ?>
        </span>
    <?php endif; ?>
</a>
