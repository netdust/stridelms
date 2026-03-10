<?php
/**
 * Dashboard stat cards — compact row of key metrics.
 *
 * @var array $args {
 *     @type array $stats Array of stat items, each with 'value' and 'label'.
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$stats = $args['stats'] ?? [];
if (empty($stats)) {
    return;
}
?>

<div class="flex gap-3 flex-wrap">
    <?php foreach ($stats as $stat) : ?>
        <div class="flex-1 min-w-[120px] px-4 py-3 rounded-lg border border-border/60 bg-surface-card">
            <div class="text-2xl font-semibold text-text"><?php echo esc_html((string) $stat['value']); ?></div>
            <div class="text-xs text-text-muted mt-0.5"><?php echo esc_html($stat['label']); ?></div>
        </div>
    <?php endforeach; ?>
</div>
