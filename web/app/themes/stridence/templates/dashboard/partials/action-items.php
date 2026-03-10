<?php
/**
 * Dashboard action items — pending enrollment and post-course tasks.
 *
 * Uses the standard .action-item classes with amber border for consistency
 * with the dashboard home screen action list.
 *
 * @var array $args {
 *     @type array $items Action items from UserDashboardService::buildActionItems()
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$items = $args['items'] ?? [];
if (empty($items)) {
    return;
}
?>
<section class="mb-6">
    <div class="space-y-2">
        <?php foreach ($items as $item) : ?>
            <a href="<?php echo esc_url($item['url']); ?>"
               class="action-item action-border-amber">
                <div class="flex items-center gap-3 min-w-0 flex-1">
                    <span class="w-8 h-8 rounded-lg bg-warning/10 flex items-center justify-center shrink-0">
                        <?php echo stridence_icon('alert-circle', 'w-4 h-4 text-warning'); ?>
                    </span>
                    <div class="min-w-0">
                        <span class="font-medium text-text text-sm truncate block"><?php echo esc_html($item['course_title']); ?></span>
                        <span class="text-xs text-text-muted"><?php echo esc_html($item['label']); ?></span>
                    </div>
                </div>
                <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-muted shrink-0'); ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
