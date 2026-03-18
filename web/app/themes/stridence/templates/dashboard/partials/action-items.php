<?php
/**
 * Dashboard action items — pending enrollment and post-course tasks.
 *
 * Matches the home tab action item pattern: colored border + background
 * with inline Tailwind utilities.
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
        <?php foreach ($items as $item) :
            $type = $item['type'] ?? '';

            // Match home tab action styling per type
            if ($type === 'online_lesson') {
                $borderClass = 'border-blue-200 bg-blue-50/50 hover:border-blue-300 hover:bg-blue-50';
                $iconColor = 'text-blue-500';
                $chevronColor = 'text-blue-400';
                $icon = 'play';
            } elseif ($type === 'session_selection') {
                $borderClass = 'border-violet-200 bg-violet-50/50 hover:border-violet-300 hover:bg-violet-50';
                $iconColor = 'text-violet-500';
                $chevronColor = 'text-violet-400';
                $icon = 'list';
            } else {
                $borderClass = 'border-amber-200 bg-amber-50/50 hover:border-amber-300 hover:bg-amber-50';
                $iconColor = 'text-amber-500';
                $chevronColor = 'text-amber-400';
                $icon = 'alert-circle';
            }
        ?>
            <a href="<?php echo esc_url($item['url']); ?>"
               class="flex items-center gap-2.5 rounded-lg border <?php echo $borderClass; ?> px-3 py-2 transition-colors">
                <?php echo stridence_icon($icon, 'w-4 h-4 ' . $iconColor . ' shrink-0'); ?>
                <span class="text-sm font-medium text-text truncate"><?php echo esc_html($item['course_title']); ?></span>
                <span class="text-xs text-text-muted shrink-0 ml-auto">
                    <?php echo esc_html($item['label']); ?>
                    <?php
                    $total = (int) ($item['total_tasks'] ?? 0);
                    $done  = (int) ($item['done_tasks'] ?? 0);
                    if ($total > 0) :
                    ?>
                        &middot; <?php echo esc_html($done . '/' . $total); ?>
                    <?php endif; ?>
                </span>
                <?php echo stridence_icon('chevron-right', 'w-4 h-4 ' . $chevronColor . ' shrink-0'); ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
