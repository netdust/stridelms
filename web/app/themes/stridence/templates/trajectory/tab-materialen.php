<?php
/**
 * Trajectory Tab: Materialen (Materials) — Helder Tij
 *
 * Shows course materials from LearnDash for accessible courses.
 *
 * Restyle only: white rows with a 38px file tile, 14px bold title and
 * a rotating chevron affordance. The mechanism is unchanged — each row
 * expands (Alpine `expandable`, open by default) to the LearnDash
 * materials HTML; there is no per-file download URL in the data.
 *
 * @param array $args {
 *     @type WP_Post $trajectory
 *     @type object $enrollment
 *     @type WP_User $user
 *     @type TrajectoryDashboardService $dashboard_service
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$trajectory = $args['trajectory'];
$user = $args['user'];
$dashboardService = $args['dashboard_service'];

// Get materials
$materials = $dashboardService->getMaterials($trajectory->ID, $user->ID);
?>

<div class="space-y-6">
    <h2 class="text-lg font-bold text-text">
        <?php esc_html_e('Materialen', 'stridence'); ?>
    </h2>

    <?php if (empty($materials)) : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon' => 'file-text',
            'title' => __('Geen materialen', 'stridence'),
            'message' => __('Er zijn nog geen materialen beschikbaar voor dit traject.', 'stridence'),
        ]);
        ?>
    <?php else : ?>
        <div class="flex flex-col gap-2.5">
            <?php foreach ($materials as $material) : ?>
                <div class="bg-surface-card rounded-[12px] shadow-card" x-data="expandable(true)">
                    <button type="button"
                            class="w-full p-4 flex items-center gap-3.5 text-left"
                            @click="toggle()">
                        <span class="shrink-0 w-[38px] h-[38px] rounded-[10px] bg-surface-alt text-text-muted flex items-center justify-center">
                            <?php echo stridence_icon('file-text', 'w-[18px] h-[18px]'); ?>
                        </span>
                        <span class="flex-1 min-w-0">
                            <span class="block text-[14px] font-bold text-text">
                                <?php echo esc_html($material['title']); ?>
                            </span>
                            <span class="block text-[12px] text-text-faint mt-0.5">
                                <?php esc_html_e('Cursusmateriaal', 'stridence'); ?>
                            </span>
                        </span>
                        <span class="shrink-0 text-text-muted transition-transform duration-200"
                              :class="{ 'rotate-180': open }">
                            <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                        </span>
                    </button>

                    <div x-show="open" x-collapse class="border-t border-border-soft">
                        <div class="p-4 prose prose-sm max-w-none">
                            <?php echo wp_kses_post($material['materials']); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
