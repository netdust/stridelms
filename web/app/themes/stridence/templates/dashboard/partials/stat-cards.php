<?php
/**
 * Dashboard stat cards — Helder Tij metric cards.
 *
 * White card per stat: 13px/600 muted label, 30px/800 tabular value,
 * optional 12px context line. Context keeps the success/warning colour
 * pair when the stat encodes one ('color' key).
 *
 * @var array $args {
 *     @type array $stats Array of stat items with 'label', 'value',
 *                        optional 'context' and optional 'color'
 *                        ('success'|'warning').
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$stats = $args['stats'] ?? [];
if (empty($stats)) {
    return;
}

// Context-line colour per encoded stat colour (success/warning pairs).
$contextColorMap = [
    'success' => 'text-badge-free-text font-bold',
    'warning' => 'text-badge-few-text font-bold',
];
?>

<div class="grid grid-cols-[repeat(auto-fit,minmax(200px,1fr))] gap-[14px]">
    <?php foreach ($stats as $stat) :
        $context  = (string) ($stat['context'] ?? '');
        $ctxClass = $contextColorMap[$stat['color'] ?? ''] ?? 'text-text-faint';
        ?>
        <div class="bg-surface-card rounded-[14px] shadow-card py-[18px] px-5">
            <div class="text-[13px] font-semibold text-text-muted">
                <?php echo esc_html((string) ($stat['label'] ?? '')); ?>
            </div>
            <div class="text-[30px] font-extrabold text-text tabular-nums leading-tight mt-1.5">
                <?php echo esc_html((string) ($stat['value'] ?? '')); ?>
            </div>
            <?php if ($context !== '') : ?>
                <div class="text-[12px] mt-0.5 <?php echo esc_attr($ctxClass); ?>">
                    <?php echo esc_html($context); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
