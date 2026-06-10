<?php
/**
 * Dashboard stat cards — compact metrics with colored icon accents.
 *
 * @var array $args {
 *     @type array $stats Array of stat items with 'value', 'label', 'icon', 'color'.
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$stats = $args['stats'] ?? [];
if (empty($stats)) {
    return;
}

$colorMap = [
    'primary' => ['bg' => 'bg-primary/10', 'text' => 'text-primary'],
    'warning' => ['bg' => 'bg-status-warning-subtle', 'text' => 'text-status-warning'],
    'success' => ['bg' => 'bg-status-success-subtle', 'text' => 'text-status-success'],
];
?>

<div class="grid grid-cols-2 sm:grid-cols-<?php echo esc_attr((string) min(count($stats), 3)); ?> gap-3">
    <?php foreach ($stats as $stat) :
        $color = $stat['color'] ?? 'primary';
        $icon  = $stat['icon'] ?? 'info';
        $c     = $colorMap[$color] ?? $colorMap['primary'];
        ?>
        <div class="px-4 py-3.5 rounded-xl bg-surface-card border border-border/50">
            <div class="flex items-center gap-3">
                <span class="w-9 h-9 rounded-lg <?php echo esc_attr($c['bg']); ?> flex items-center justify-center shrink-0">
                    <?php echo stridence_icon($icon, 'w-[18px] h-[18px] ' . $c['text']); ?>
                </span>
                <div>
                    <div class="text-xl font-bold text-text leading-tight"><?php echo esc_html((string) $stat['value']); ?></div>
                    <div class="text-xs text-text-muted"><?php echo esc_html($stat['label']); ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
