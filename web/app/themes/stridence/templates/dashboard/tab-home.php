<?php
/**
 * Dashboard Tab: Home
 *
 * Adaptive home screen that only renders sections when data exists.
 * Sections: greeting, hero, actions, enrollments, trajectories, certificates.
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

$userData     = $home_data['user'] ?? [];
$hero         = $home_data['hero'] ?? null;
$actions      = $home_data['actions'] ?? [];
$enrollments  = $home_data['active_enrollments'] ?? [];
$trajectories = $home_data['active_trajectories'] ?? [];
$certificates = $home_data['recent_certificates'] ?? [];

// Time-of-day greeting
$hour = (int) date('G');
$greeting = match (true) {
    $hour < 12  => __('Goedemorgen', 'stridence'),
    $hour < 18  => __('Goedemiddag', 'stridence'),
    default     => __('Goedenavond', 'stridence'),
};

$firstName = explode(' ', $userData['name'] ?? '')[0];
$initials  = $userData['initials'] ?? '?';
$permalink = get_permalink();

$hasContent = $hero || !empty($actions) || !empty($enrollments) || !empty($trajectories) || !empty($certificates);

// Prepare enrollment data as JSON for Alpine panel
$enrollmentsJson = [];
foreach ($enrollments as $enrollment) {
    $item = $enrollment;
    // Add formatted dates for display in the panel
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
?>

<div class="space-y-8" x-data="dashboardHome()">
    <!-- Greeting Header -->
    <header class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
            <span class="text-primary font-semibold text-sm"><?php echo esc_html($initials); ?></span>
        </div>
        <div>
            <h1 class="font-heading text-2xl font-bold text-text">
                <?php echo esc_html($greeting . ', ' . $firstName); ?>
            </h1>
            <p class="text-sm text-text-muted">
                <?php echo esc_html(wp_date('l j F Y')); ?>
            </p>
        </div>
    </header>

    <?php if ($hasContent) : ?>

        <!-- Hero Action -->
        <?php if ($hero) : ?>
            <?php
            get_template_part('templates/dashboard/partials/hero-action', null, [
                'hero' => $hero,
            ]);
            ?>
        <?php endif; ?>

        <!-- Acties (nudges) -->
        <?php if (!empty($actions)) : ?>
            <section>
                <h2 class="font-heading text-lg font-bold text-text mb-3">
                    <?php esc_html_e('Acties', 'stridence'); ?>
                </h2>
                <div class="space-y-2">
                    <?php foreach ($actions as $action) : ?>
                        <a href="<?php echo esc_url($action['url']); ?>"
                           class="action-item action-border-<?php echo esc_attr($action['color']); ?>">
                            <span class="flex-1 text-sm text-text"><?php echo esc_html($action['label']); ?></span>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-muted shrink-0'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Mijn opleidingen -->
        <?php if (!empty($enrollments)) : ?>
            <section>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-heading text-lg font-bold text-text">
                        <?php esc_html_e('Mijn opleidingen', 'stridence'); ?>
                    </h2>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'inschrijvingen', $permalink)); ?>"
                       class="text-sm text-primary hover:underline">
                        <?php esc_html_e('Alles bekijken', 'stridence'); ?>
                    </a>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <?php foreach (array_slice($enrollmentsJson, 0, 4) as $enrollment) : ?>
                        <?php
                        get_template_part('templates/dashboard/partials/enrollment-card', null, [
                            'enrollment'    => $enrollment,
                            'panel_enabled' => true,
                        ]);
                        ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Mijn trajecten -->
        <?php if (!empty($trajectories)) : ?>
            <section>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-heading text-lg font-bold text-text">
                        <?php esc_html_e('Mijn trajecten', 'stridence'); ?>
                    </h2>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'trajecten', $permalink)); ?>"
                       class="text-sm text-primary hover:underline">
                        <?php esc_html_e('Alles bekijken', 'stridence'); ?>
                    </a>
                </div>
                <div class="space-y-3">
                    <?php foreach ($trajectories as $trajectory) : ?>
                        <a href="<?php echo esc_url($trajectory['url']); ?>"
                           class="dash-card-interactive flex items-center justify-between gap-4">
                            <div class="flex items-center gap-3 min-w-0">
                                <?php echo stridence_icon('layers', 'w-5 h-5 text-primary shrink-0'); ?>
                                <span class="font-medium text-text truncate"><?php echo esc_html($trajectory['title']); ?></span>
                            </div>
                            <?php echo stridence_icon('chevron-right', 'w-5 h-5 text-text-muted shrink-0'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Recent behaald -->
        <?php if (!empty($certificates)) : ?>
            <section>
                <div class="flex items-center justify-between mb-4">
                    <h2 class="font-heading text-lg font-bold text-text">
                        <?php esc_html_e('Recent behaald', 'stridence'); ?>
                    </h2>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'certificaten', $permalink)); ?>"
                       class="text-sm text-primary hover:underline">
                        <?php esc_html_e('Alle certificaten', 'stridence'); ?>
                    </a>
                </div>
                <div class="dash-card !p-0 divide-y divide-border">
                    <?php foreach ($certificates as $cert) : ?>
                        <div class="px-6 py-4 flex items-center justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <h3 class="font-medium text-text truncate">
                                    <?php echo esc_html($cert['course_title'] ?? ''); ?>
                                </h3>
                                <?php $completedAt = $cert['completed_at'] ?? ''; ?>
                                <?php if ($completedAt) : ?>
                                    <p class="text-sm text-text-muted">
                                        <?php echo esc_html(stride_format_date($completedAt)); ?>
                                    </p>
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
        <?php
        get_template_part('partials/empty-state', null, [
            'icon'    => 'book-open',
            'title'   => __('Welkom bij Stride!', 'stridence'),
            'message' => __('Je hebt nog geen actieve opleidingen of trajecten. Ontdek ons aanbod en schrijf je in voor je eerste opleiding.', 'stridence'),
            'action'  => __('Bekijk opleidingen', 'stridence'),
            'url'     => home_url('/klassikaal/'),
        ]);
        ?>

    <?php endif; ?>

    <!-- Enrollment Side Panel -->
    <?php if (!empty($enrollments)) : ?>
        <?php get_template_part('templates/dashboard/partials/panel-enrollment'); ?>
    <?php endif; ?>
</div>
