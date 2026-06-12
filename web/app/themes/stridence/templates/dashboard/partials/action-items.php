<?php
/**
 * Dashboard "Acties nodig" card — Helder Tij.
 *
 * White card with 17px/700 title and one row per pending action:
 * 14px/700 title + 13px muted sub + small primary CTA. Existing
 * action URLs/labels/types are unchanged; rows beyond the first three
 * stay behind the existing expand/collapse Alpine state.
 *
 * Note: the design sheet's segmented control ("Wacht op …"/"Meldingen")
 * is not rendered here — the home payload carries a single flat action
 * list (no meldingen/wachten buckets), and adding those would be new
 * data flow. The admin dashboard owns that tabbed variant.
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

$visibleCount = 3;
$hasMore      = count($items) > $visibleCount;

// CTA copy per existing action type — reuses the labels the edition CTA
// and online rows already use elsewhere on the dashboard.
$ctaLabels = [
    'session_selection' => __('Sessiekeuze maken', 'stridence'),
    'post_course'       => __('Vorming afronden', 'stridence'),
    'enrollment'        => __('Inschrijving voltooien', 'stridence'),
    'online_lesson'     => __('Ga verder', 'stridence'),
];
?>

<section class="bg-surface-card rounded-[16px] shadow-card p-6 flex flex-col gap-4"
         <?php echo $hasMore ? 'x-data="{ expanded: false }"' : ''; ?>>
    <h3 class="text-[17px] font-bold text-text">
        <?php esc_html_e('Acties nodig', 'stridence'); ?>
    </h3>

    <div class="flex flex-col gap-2">
        <?php foreach ($items as $i => $item) :
            $type   = $item['type'] ?? '';
            $hidden = $hasMore && $i >= $visibleCount;
            $xAttr  = $hidden ? 'x-show="expanded" x-cloak' : '';

            $sub   = (string) ($item['label'] ?? '');
            $total = (int) ($item['total_tasks'] ?? 0);
            $done  = (int) ($item['done_tasks'] ?? 0);
            if ($total > 0) {
                $sub .= ' · ' . $done . '/' . $total;
            }
            ?>
            <div class="bg-surface rounded-[12px] p-4 flex justify-between items-center gap-3.5 flex-wrap" <?php echo $xAttr; ?>>
                <div class="flex-1 min-w-[200px]">
                    <div class="text-[14px] font-bold text-text leading-snug">
                        <?php echo esc_html($item['course_title'] ?? ''); ?>
                    </div>
                    <?php if ($sub !== '') : ?>
                        <div class="text-[13px] text-text-muted mt-0.5">
                            <?php echo esc_html($sub); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <a href="<?php echo esc_url($item['url'] ?? ''); ?>" class="btn-primary btn-sm shrink-0">
                    <?php echo esc_html($ctaLabels[$type] ?? __('Voltooien', 'stridence')); ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($hasMore) : ?>
        <button @click="expanded = !expanded"
                class="text-sm text-primary hover:underline cursor-pointer self-start">
            <span x-show="!expanded"><?php echo esc_html(sprintf(__('Toon alle %d acties', 'stridence'), count($items))); ?></span>
            <span x-show="expanded" x-cloak><?php esc_html_e('Minder tonen', 'stridence'); ?></span>
        </button>
    <?php endif; ?>
</section>
