<?php
/**
 * Trajectory Catalog Archive — Helder Tij.
 *
 * Header band: bg-surface-alt, serif h1, muted intro.
 * Card grid: minmax(320px,1fr) / gap-[18px].
 * Cards via card-trajectory partial (already restyled).
 * Empty state via empty-state partial (band variant).
 * No filter chips — trajectory count does not warrant a filter mechanism.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Active (catalog-visible) trajectories through the repository (INV-3 — no
// raw ntdst_data() query in the theme). findActive() = announcement / open /
// in-progress, title-ordered.
$trajectories = ntdst_get(\Stride\Modules\Trajectory\TrajectoryRepository::class)->findActive();

// Enrolled-set pre-pass — one findByUser() read, not a lookup per card (mirrors
// the edition catalog in helpers/catalog.php). Lets each card render its
// enrolled state (✓ + % + "Open traject") the same way the dashboard does.
$current_user_id      = get_current_user_id();
$enrolled_traj_ids    = $current_user_id
    ? ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class)->getEnrolledTrajectoryIds($current_user_id)
    : [];
$dashboardService     = ntdst_get(\Stride\Modules\Trajectory\TrajectoryDashboardService::class);

get_header();
?>

<!-- Header band: bg-surface-alt, serif h1, muted intro (Helder Tij) -->
<div class="bg-surface-alt">
    <div class="container py-[clamp(28px,5vw,44px)]">
        <h1 class="font-serif font-normal text-[clamp(32px,4.5vw,44px)] leading-[1.1] text-text m-0">
            <?php esc_html_e('Leertrajecten', 'stridence'); ?>
        </h1>
        <p class="text-[16px] text-text-muted mt-[10px] mb-0 max-w-[560px]">
            <?php esc_html_e('Meerdelige trajecten die je stap voor stap naar een kwalificatie of nieuwe rol begeleiden — met keuzemodules op jouw maat.', 'stridence'); ?>
        </p>
    </div>
</div>

<!-- Card grid -->
<div class="container py-[clamp(20px,3vw,32px)] pb-20">

    <?php if (!empty($trajectories)) : ?>

        <?php // 320px min-width — wider trajectory cards vs 300px catalog grids?>
        <div class="grid grid-cols-[repeat(auto-fill,minmax(320px,1fr))] gap-[18px]">
            <?php foreach ($trajectories as $trajectory) :
                $trajectory_id = (int) ($trajectory['id'] ?? $trajectory['ID'] ?? 0);
                if (!$trajectory_id) {
                    continue;
                }

                // Enrolled card opts — same shape + progress formula the
                // dashboard "Mijn trajecten" tab uses (completed / required).
                $card_opts = [];
                if (in_array($trajectory_id, $enrolled_traj_ids, true)) {
                    $progress = $dashboardService->getProgressData($current_user_id, $trajectory_id);
                    $total    = (int) ($progress['total_required'] ?? 0);
                    $slug     = get_post_field('post_name', $trajectory_id);
                    $card_opts = [
                        'progress'      => $total > 0
                            ? (int) round(((int) ($progress['completed_count'] ?? 0) / $total) * 100)
                            : 0,
                        'dashboard_url' => $slug
                            ? home_url('/mijn-account/trajecten/' . $slug . '/')
                            : get_permalink($trajectory_id),
                    ];
                }

                stridence_template_part(
                    'partials/card-trajectory',
                    null,
                    stridence_build_trajectory_card_args($trajectory_id, $card_opts),
                );
            endforeach; ?>
        </div>

    <?php else : ?>

        <?php stridence_template_part('partials/empty-state', null, [
            'icon'    => 'layers',
            'title'   => __('Geen trajecten beschikbaar', 'stridence'),
            'message' => __('Er zijn momenteel geen leertrajecten beschikbaar. Bekijk ons aanbod aan klassikale en online opleidingen.', 'stridence'),
            'action'  => __('Bekijk opleidingen', 'stridence'),
            'url'     => home_url('/opleidingen/'),
            'band'    => true,
        ]); ?>

    <?php endif; ?>

</div>

<?php get_footer(); ?>
