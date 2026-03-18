<?php
/**
 * Progress Bar Partial
 *
 * Thin horizontal progress bar used across enrollment cards.
 *
 * @param array $args {
 *     @type int $percentage  Progress 0-100
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$percentage = (int) ($args['percentage'] ?? 0);
if ($percentage <= 0) {
    return;
}
$percentage = min(100, $percentage);
?>
<div class="mt-3">
    <div class="h-1 bg-surface-alt rounded-full overflow-hidden">
        <div class="h-full <?php echo $percentage >= 100 ? 'bg-success' : 'bg-primary'; ?> rounded-full transition-all duration-500"
             style="width: <?php echo esc_attr((string) $percentage); ?>%"></div>
    </div>
</div>
