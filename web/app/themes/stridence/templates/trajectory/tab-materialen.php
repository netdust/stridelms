<?php
/**
 * Trajectory Tab: Materialen (Materials)
 *
 * Shows course materials from LearnDash for accessible courses.
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
    <h2 class="text-lg font-semibold text-text">
        <?php esc_html_e('Materialen', 'stridence'); ?>
    </h2>

    <?php if (empty($materials)) : ?>
        <?php
        get_template_part('partials/empty-state', null, [
            'icon' => 'file-text',
            'title' => __('Geen materialen', 'stridence'),
            'message' => __('Er zijn nog geen materialen beschikbaar voor dit traject.', 'stridence'),
        ]);
        ?>
    <?php else : ?>
        <div class="space-y-4">
            <?php foreach ($materials as $material) : ?>
                <div class="card" x-data="expandable(true)">
                    <button type="button"
                            class="w-full p-4 flex items-center justify-between gap-4 text-left"
                            @click="toggle()">
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                                <?php echo stridence_icon('file-text', 'w-5 h-5 text-primary'); ?>
                            </span>
                            <h3 class="font-medium text-text">
                                <?php echo esc_html($material['title']); ?>
                            </h3>
                        </div>
                        <span class="shrink-0 text-text-muted transition-transform duration-200"
                              :class="{ 'rotate-180': open }">
                            <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                        </span>
                    </button>

                    <div x-show="open" x-collapse class="border-t border-border">
                        <div class="p-4 prose prose-sm max-w-none">
                            <?php echo wp_kses_post($material['materials']); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
