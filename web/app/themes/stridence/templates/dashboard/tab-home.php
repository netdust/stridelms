<?php
/**
 * Dashboard Tab: Home
 *
 * Flat layout: greeting, stat cards, actions, course grid, completions.
 * No hero banner — the action list IS the hero.
 * All sections adaptive — only render when data exists.
 *
 * @param array $args {
 *     @type WP_User $user      Current user object
 *     @type array   $home_data Aggregated data from UserDashboardService::getHomeData()
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$user      = $args['user'] ?? wp_get_current_user();
$home_data = $args['home_data'] ?? [];

$actions      = $home_data['actions'] ?? [];
$enrollments  = $home_data['active_enrollments'] ?? [];
$certificates = $home_data['recent_certificates'] ?? [];
$permalink    = get_permalink();

$hasContent = !empty($actions) || !empty($enrollments) || !empty($certificates);

// Compute stats from available data
$activeCount = count($enrollments);
$actionCount = count($actions);
$certCount   = count($certificates);

$stats = [];
if ($activeCount > 0) {
    $stats[] = ['value' => $activeCount, 'label' => __('Actieve opleidingen', 'stridence'), 'icon' => 'book-open', 'color' => 'primary'];
}
if ($actionCount > 0) {
    $stats[] = ['value' => $actionCount, 'label' => __('Acties vereist', 'stridence'), 'icon' => 'alert-circle', 'color' => 'warning'];
}
if ($certCount > 0) {
    $stats[] = ['value' => $certCount, 'label' => __('Certificaten', 'stridence'), 'icon' => 'award', 'color' => 'success'];
}

// Prepare enrollment data as JSON for Alpine panel
$enrollmentsJson = [];
foreach ($enrollments as $enrollment) {
    $item = $enrollment;
    if (!empty($item['start_date'])) {
        $item['start_date_formatted'] = stride_format_date($item['start_date']);
    }
    if (!empty($item['sessions']) && is_array($item['sessions'])) {
        foreach ($item['sessions'] as &$session) {
            if (!empty($session['date'])) {
                $session['date_formatted'] = stride_format_date($session['date']);
            }
        }
        unset($session);
    }
    $enrollmentsJson[] = $item;
}

// Greeting
$firstName = trim($user->first_name ?? '');
$greeting  = $firstName
    ? sprintf(__('Hallo %s', 'stridence'), $firstName)
    : __('Hallo', 'stridence');

// Status summary line
$statusParts = [];
if ($actionCount > 0) {
    $statusParts[] = sprintf(
        _n('%d actie vereist', '%d acties vereist', $actionCount, 'stridence'),
        $actionCount
    );
}
if ($activeCount > 0) {
    $statusParts[] = sprintf(
        _n('%d actieve opleiding', '%d actieve opleidingen', $activeCount, 'stridence'),
        $activeCount
    );
}
$statusLine = !empty($statusParts) ? implode(' · ', $statusParts) : __('Je bent helemaal bij.', 'stridence');
?>

<div class="space-y-8" x-data="dashboardHome()">
    <?php if ($hasContent) : ?>

        <!-- Status summary (greeting is in the top bar) -->
        <p class="text-sm text-text-muted"><?php echo esc_html($statusLine); ?></p>

        <!-- Stat cards -->
        <?php if (!empty($stats)) : ?>
            <?php
            get_template_part('templates/dashboard/partials/stat-cards', null, [
                'stats' => $stats,
            ]);
            ?>
        <?php endif; ?>

        <!-- Acties -->
        <?php if (!empty($actions)) : ?>
            <?php
            $actionIconMap = [
                'blue'  => ['icon' => 'calendar', 'bg' => 'bg-blue-50', 'text' => 'text-blue-600'],
                'amber' => ['icon' => 'alert-circle', 'bg' => 'bg-amber-50', 'text' => 'text-amber-600'],
                'green' => ['icon' => 'file-text', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-600'],
            ];
            ?>
            <section>
                <h3 class="text-base font-semibold text-text mb-3">
                    <?php esc_html_e('Acties', 'stridence'); ?>
                </h3>
                <div class="space-y-2">
                    <?php foreach ($actions as $action) :
                        $color = $action['color'] ?? 'blue';
                        $ic = $actionIconMap[$color] ?? $actionIconMap['blue'];
                    ?>
                        <a href="<?php echo esc_url($action['url']); ?>"
                           class="action-item action-border-<?php echo esc_attr($color); ?>">
                            <span class="w-8 h-8 rounded-lg <?php echo esc_attr($ic['bg']); ?> flex items-center justify-center shrink-0">
                                <?php echo stridence_icon($ic['icon'], 'w-4 h-4 ' . $ic['text']); ?>
                            </span>
                            <span class="flex-1 text-sm text-text"><?php echo esc_html($action['label']); ?></span>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-muted shrink-0'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Verder leren -->
        <?php if (!empty($enrollments)) : ?>
            <section>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-semibold text-text">
                        <?php esc_html_e('Verder leren', 'stridence'); ?>
                    </h3>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'inschrijvingen', $permalink)); ?>"
                       class="text-sm text-primary hover:underline">
                        <?php esc_html_e('Alles bekijken', 'stridence'); ?>
                    </a>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach (array_slice($enrollmentsJson, 0, 4) as $enrollment) : ?>
                        <?php get_template_part('templates/dashboard/partials/enrollment-card', null, [
                            'enrollment'    => $enrollment,
                            'panel_enabled' => true,
                        ]); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Recent behaald -->
        <?php if (!empty($certificates)) : ?>
            <section>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-semibold text-text">
                        <?php esc_html_e('Recent behaald', 'stridence'); ?>
                    </h3>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'certificaten', $permalink)); ?>"
                       class="text-sm text-primary hover:underline">
                        <?php esc_html_e('Alle certificaten', 'stridence'); ?>
                    </a>
                </div>
                <div class="space-y-1">
                    <?php foreach (array_slice($certificates, 0, 3) as $cert) : ?>
                        <div class="flex items-center gap-3 px-4 py-3 rounded-lg border border-border/60 bg-surface-card">
                            <span class="w-8 h-8 rounded-lg bg-success/10 flex items-center justify-center shrink-0">
                                <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                            </span>
                            <div class="flex-1 min-w-0">
                                <span class="text-sm font-medium text-text truncate block">
                                    <?php echo esc_html($cert['course_title'] ?? ''); ?>
                                </span>
                                <?php $completedAt = $cert['completed_at'] ?? ''; ?>
                                <?php if ($completedAt) : ?>
                                    <span class="text-xs text-text-muted">
                                        <?php echo esc_html(stride_format_date($completedAt)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php $certUrl = $cert['certificate_url'] ?? ''; ?>
                            <?php if ($certUrl) : ?>
                                <a href="<?php echo esc_url($certUrl); ?>"
                                   class="btn-ghost btn-sm shrink-0"
                                   target="_blank"
                                   rel="noopener">
                                    <?php echo stridence_icon('download', 'w-4 h-4 mr-1'); ?>
                                    <?php esc_html_e('Download', 'stridence'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

    <?php else : ?>

        <!-- Welcome empty state -->
        <div class="text-center py-16 px-4">
            <div class="w-16 h-16 rounded-2xl bg-primary/10 flex items-center justify-center mx-auto mb-5">
                <?php echo stridence_icon('book-open', 'w-8 h-8 text-primary'); ?>
            </div>
            <h2 class="text-lg font-semibold text-text mb-2">
                <?php esc_html_e('Welkom bij Stride!', 'stridence'); ?>
            </h2>
            <p class="text-sm text-text-muted max-w-md mx-auto mb-6">
                <?php esc_html_e('Je hebt nog geen actieve opleidingen. Ontdek ons aanbod en schrijf je in voor je eerste opleiding.', 'stridence'); ?>
            </p>
            <a href="<?php echo esc_url(home_url('/klassikaal/')); ?>" class="btn-primary">
                <?php esc_html_e('Bekijk opleidingen', 'stridence'); ?>
            </a>
        </div>

    <?php endif; ?>

    <!-- Enrollment Side Panel -->
    <?php if (!empty($enrollments)) : ?>
        <?php get_template_part('templates/dashboard/partials/panel-enrollment'); ?>
    <?php endif; ?>
</div>
