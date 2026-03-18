<?php
/**
 * Personal Trajectory Dashboard
 *
 * Main shell for user's enrolled trajectory view with tabbed navigation.
 * Validates enrollment and loads appropriate tab content.
 *
 * @param array $args {
 *     @type string $trajectory_slug Trajectory post slug
 *     @type WP_User $user Current user
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Trajectory\TrajectoryDashboardService;

$trajectory_slug = $args['trajectory_slug'] ?? '';
$user = $args['user'] ?? wp_get_current_user();

if (empty($trajectory_slug)) {
    wp_safe_redirect(add_query_arg('tab', 'trajecten', get_permalink()));
    exit;
}

// Get service and trajectory
$dashboardService = ntdst_get(TrajectoryDashboardService::class);
$trajectory = $dashboardService->getTrajectoryBySlug($trajectory_slug);

// 404 if trajectory not found
if (!$trajectory) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    stridence_template_part('404');
    exit;
}

// Check enrollment
$enrollment = $dashboardService->getEnrollmentForUser($user->ID, $trajectory->ID);

if (!$enrollment) {
    // Not enrolled - redirect to public trajectory page
    wp_safe_redirect(get_permalink($trajectory->ID));
    exit;
}

// Get tab state
$valid_tabs = ['voortgang', 'keuzes', 'materialen', 'berichten'];
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'voortgang';

if (!in_array($current_tab, $valid_tabs, true)) {
    $current_tab = 'voortgang';
}

// Build back URL
$dashboard_url = get_permalink(get_page_by_path('mijn-account'));
$trajecten_tab_url = add_query_arg('tab', 'trajecten', $dashboard_url);

// Tab definitions
$tabs = [
    'voortgang' => [
        'label' => __('Voortgang', 'stridence'),
        'icon' => 'trending-up',
    ],
    'keuzes' => [
        'label' => __('Keuzes', 'stridence'),
        'icon' => 'check-square',
    ],
    'materialen' => [
        'label' => __('Materialen', 'stridence'),
        'icon' => 'file-text',
    ],
    'berichten' => [
        'label' => __('Berichten', 'stridence'),
        'icon' => 'bell',
    ],
];
?>

<div class="min-h-screen bg-surface-alt pb-20 lg:pb-0">
    <!-- Page Header -->
    <div class="bg-surface border-b border-border">
        <div class="container py-6 lg:py-8">
            <!-- Back link -->
            <a href="<?php echo esc_url($trajecten_tab_url); ?>"
               class="inline-flex items-center gap-1 text-sm text-text-muted hover:text-primary mb-4">
                <?php echo stridence_icon('chevron-left', 'w-4 h-4'); ?>
                <?php esc_html_e('Terug naar trajecten', 'stridence'); ?>
            </a>

            <div class="flex items-start gap-4">
                <div class="w-12 h-12 lg:w-16 lg:h-16 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                    <?php echo stridence_icon('layers', 'w-6 h-6 lg:w-8 lg:h-8 text-primary'); ?>
                </div>
                <div>
                    <h1 class="font-heading text-xl lg:text-2xl font-bold text-text">
                        <?php echo esc_html($trajectory->post_title); ?>
                    </h1>
                    <p class="text-sm text-text-muted mt-1">
                        <?php
                        printf(
                            /* translators: %s: enrollment date */
                            esc_html__('Ingeschreven sinds %s', 'stridence'),
                            esc_html(date_i18n('j F Y', strtotime($enrollment->registered_at)))
                        );
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="bg-surface border-b border-border sticky top-0 z-30">
        <div class="container">
            <nav class="flex gap-1 overflow-x-auto -mb-px" aria-label="<?php esc_attr_e('Traject navigatie', 'stridence'); ?>">
                <?php foreach ($tabs as $slug => $tab) :
                    $is_active = ($current_tab === $slug);
                    $url = add_query_arg('tab', $slug);

                    $classes = $is_active
                        ? 'flex items-center gap-2 px-4 py-3 text-sm font-medium text-primary border-b-2 border-primary whitespace-nowrap'
                        : 'flex items-center gap-2 px-4 py-3 text-sm font-medium text-text-muted hover:text-text border-b-2 border-transparent whitespace-nowrap';
                ?>
                    <a href="<?php echo esc_url($url); ?>"
                       class="<?php echo esc_attr($classes); ?>"
                       <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                        <?php echo stridence_icon($tab['icon'], 'w-4 h-4'); ?>
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="container py-6 lg:py-8">
        <?php
        stridence_template_part("templates/trajectory/tab-{$current_tab}", null, [
            'trajectory' => $trajectory,
            'enrollment' => $enrollment,
            'user' => $user,
            'dashboard_service' => $dashboardService,
        ]);
        ?>
    </div>
</div>
