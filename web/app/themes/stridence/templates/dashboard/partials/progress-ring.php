<?php
/**
 * Progress Ring Partial
 *
 * SVG-based circular progress indicator with animated stroke.
 * Uses Alpine.js for entrance animation of the progress arc.
 *
 * @param array $args {
 *     @type int $progress Progress percentage (0-100)
 *     @type int $size     Ring diameter in pixels (default: 48)
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$progress = max(0, min(100, (int) ($args['progress'] ?? 0)));
$size     = (int) ($args['size'] ?? 48);

$radius        = 15.9155;
$circumference = 2 * M_PI * $radius; // ~100
$dashoffset    = $circumference - ($progress / 100) * $circumference;
?>

<div class="shrink-0"
     x-data="{ offset: <?php echo esc_attr((string) $circumference); ?> }"
     x-init="setTimeout(() => offset = <?php echo esc_attr((string) $dashoffset); ?>, 100)">
    <svg class="progress-ring"
         width="<?php echo esc_attr((string) $size); ?>"
         height="<?php echo esc_attr((string) $size); ?>"
         viewBox="0 0 36 36">
        <!-- Background circle -->
        <circle cx="18" cy="18"
                r="<?php echo esc_attr((string) $radius); ?>"
                fill="none"
                stroke="rgb(var(--color-surface-alt))"
                stroke-width="3"
                stroke-linecap="round" />
        <!-- Progress arc -->
        <circle cx="18" cy="18"
                r="<?php echo esc_attr((string) $radius); ?>"
                fill="none"
                stroke="rgb(var(--color-primary))"
                stroke-width="3"
                stroke-linecap="round"
                stroke-dasharray="<?php echo esc_attr((string) $circumference); ?>"
                :stroke-dashoffset="offset"
                transform="rotate(-90 18 18)" />
        <!-- Center text -->
        <text x="18" y="18"
              text-anchor="middle"
              dominant-baseline="central"
              class="fill-text text-[0.55rem] font-semibold">
            <?php echo esc_html($progress . '%'); ?>
        </text>
    </svg>
</div>
