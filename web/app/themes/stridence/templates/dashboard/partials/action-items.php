<?php
/**
 * Dashboard action items — pending enrollment and post-course tasks.
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
<section class="mb-8">
    <div class="dash-card divide-y divide-border border-amber-200 bg-amber-50/50">
        <?php foreach ($items as $item) : ?>
            <a href="<?= esc_url($item['url']) ?>"
               class="p-4 flex items-center justify-between gap-4 hover:bg-amber-50 transition-colors">
                <div class="flex items-center gap-3">
                    <?= stridence_icon('alert-circle', 'w-5 h-5 text-amber-500 shrink-0') ?>
                    <div>
                        <span class="font-medium text-text"><?= esc_html($item['course_title']) ?></span>
                        <span class="text-sm text-text-muted ml-2"><?= esc_html($item['label']) ?></span>
                    </div>
                </div>
                <?= stridence_icon('chevron-right', 'w-5 h-5 text-text-muted shrink-0') ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
