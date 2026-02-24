<?php
/**
 * Progress Bar Partial
 *
 * Renders a progress bar with label and completion status.
 *
 * @param array $args {
 *     @type int    $attended Hours/sessions attended
 *     @type int    $required Total required
 *     @type string $label    Optional label (default: "Aanwezigheid")
 * }
 */

defined('ABSPATH') || exit;

$attended = (int) ($args['attended'] ?? 0);
$required = (int) ($args['required'] ?? 0);
$label    = $args['label'] ?? 'Aanwezigheid';

// Calculate percentage safely (avoid division by zero), cap at 100%
$percentage = $required > 0 ? min(100, round(($attended / $required) * 100)) : 0;
$is_complete = $attended >= $required && $required > 0;

// Colors based on completion state
$text_color = $is_complete ? 'text-success' : 'text-text';
$bar_color  = $is_complete ? 'bg-success' : 'bg-primary';

?>
<div class="space-y-1">
    <div class="flex justify-between text-sm">
        <span class="text-text-muted"><?php echo esc_html($label); ?></span>
        <span class="font-medium <?php echo esc_attr($text_color); ?>">
            <?php echo esc_html($attended); ?>/<?php echo esc_html($required); ?>
            <?php if ($is_complete): ?>
                <?php echo stridence_icon('check-circle', 'w-4 h-4 inline-block ml-1'); ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="h-2 bg-border rounded-full overflow-hidden">
        <div
            class="h-full rounded-full <?php echo esc_attr($bar_color); ?>"
            style="width: <?php echo esc_attr($percentage); ?>%"
        ></div>
    </div>
</div>
