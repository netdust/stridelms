<?php
/**
 * Personal Trajectory Dashboard — Helder Tij
 *
 * Main shell for user's enrolled trajectory view with tabbed navigation.
 * Validates enrollment and loads appropriate tab content.
 *
 * Header band: breadcrumb, Traject + Ingeschreven badges, serif title,
 * meta dot-row and an 84px progress ring (white track on the tinted band).
 * Tabs keep the existing ?tab= server-side switching (restyled to the
 * underline recipe); the Keuzes tab shows an accent count badge while
 * the elective choice window is open and selections are incomplete.
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
use Stride\Modules\Trajectory\TrajectoryService;

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

// Progress data for the header band (same source as tab-voortgang)
$progress = $dashboardService->getProgressData($user->ID, $trajectory->ID);
$completed_count = (int) $progress['completed_count'];
$total_required = (int) $progress['total_required'];
$progress_percent = $total_required > 0 ? (int) round(($completed_count / $total_required) * 100) : 0;

// Open-choice count for the Keuzes tab badge. The window rule is the SERVICE's
// single decision point (TrajectoryService::isChoiceWindowOpen) — the same call
// tab-keuzes uses. The dashboard previously re-derived it inline and required
// BOTH dates, which drifted from the service (Shake-out BUG-4): a trajectory
// without configured dates rendered "closed" here while the server accepted
// submissions. Delegating closes the drift.
$open_choices = 0;
if (ntdst_get(TrajectoryService::class)->isChoiceWindowOpen($trajectory->ID)) {
    // Picks as COURSE ids through the single decision point — the raw
    // selections column stores flat EDITION ids, never grouped course ids.
    $trajectory_selection = ntdst_get(\Stride\Modules\Trajectory\TrajectorySelection::class);
    $selected_course_ids = $trajectory_selection->getSelectedCourseIds((int) ($enrollment->id ?? 0));

    foreach ($progress['elective_groups'] as $group) {
        $required = (int) ($group['required'] ?? 0);
        $chosen = $trajectory_selection->countChosenInGroup($group, $selected_course_ids);

        if ($required > 0 && $chosen < $required) {
            $open_choices++;
        }
    }
}

// Meta dot-row — only segments with existing data
$meta_segments = [];

if ($total_required > 0) {
    $meta_segments[] = sprintf(
        /* translators: %d: number of trajectory parts */
        _n('%d onderdeel', '%d onderdelen', $total_required, 'stridence'),
        $total_required,
    );
}

if (!empty($enrollment->registered_at)) {
    $meta_segments[] = sprintf(
        /* translators: %s: enrollment month and year */
        __('gestart %s', 'stridence'),
        date_i18n('F Y', strtotime($enrollment->registered_at)),
    );
}

// Breadcrumb items (Trajecten crumb links back to the account trajecten tab)
$breadcrumbs = [
    ['label' => __('Trajecten', 'stridence'), 'url' => $trajecten_tab_url],
    ['label' => $trajectory->post_title],
];

// Tab definitions
$tabs = [
    'voortgang' => __('Voortgang', 'stridence'),
    'keuzes' => __('Keuzes', 'stridence'),
    'materialen' => __('Materialen', 'stridence'),
    'berichten' => __('Berichten', 'stridence'),
];
?>

<div class="min-h-screen pb-20 lg:pb-0">
    <!-- Header band -->
    <div class="bg-surface-alt border-b border-border">
        <div class="container py-8 lg:py-10">
            <?php
            stridence_template_part('partials/breadcrumb', null, [
                'items' => $breadcrumbs,
            ]);
?>

            <div class="flex flex-wrap items-center gap-6 lg:gap-10">
                <div class="flex-1 min-w-[280px]">
                    <div class="flex items-center gap-2">
                        <?php
        stridence_template_part('partials/badge-status', null, [
            'status' => 'trajectory',
        ]);
stridence_template_part('partials/badge-status', null, [
    'status' => 'enrolled',
]);
?>
                    </div>

                    <h1 class="font-serif font-normal text-[clamp(30px,4.5vw,44px)] leading-[1.12] text-text max-w-[680px] mt-3.5 mb-2.5">
                        <?php echo esc_html($trajectory->post_title); ?>
                    </h1>

                    <?php if (!empty($meta_segments)) : ?>
                        <div class="text-[15px] text-text-muted">
                            <?php echo esc_html(implode(' · ', $meta_segments)); ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($total_required > 0) : ?>
                    <div class="flex items-center gap-4">
                        <?php
                        stridence_template_part('templates/dashboard/partials/progress-ring', null, [
                            'progress' => $progress_percent,
                            'size' => 84,
                            'track' => 'white',
                        ]);
                    ?>
                        <div class="text-[13px] text-text-muted leading-snug">
                            <?php
                    printf(
                        /* translators: %1$d: completed parts, %2$d: total parts */
                        esc_html__('%1$d van %2$d onderdelen afgerond', 'stridence'),
                        $completed_count,
                        $total_required,
                    );
                    ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tabs + panel -->
    <div class="container pt-6 lg:pt-9">
        <nav class="border-b border-border-soft flex gap-6 overflow-x-auto scrollbar-hide" aria-label="<?php esc_attr_e('Traject navigatie', 'stridence'); ?>">
            <?php foreach ($tabs as $slug => $label) :
                $is_active = ($current_tab === $slug);
                $url = add_query_arg('tab', $slug);

                $classes = $is_active
                    ? 'inline-flex items-center gap-1.5 text-[15px] font-bold pb-3 px-0.5 whitespace-nowrap transition-colors text-primary shadow-[inset_0_-2px_0_0] shadow-primary'
                    : 'inline-flex items-center gap-1.5 text-[15px] font-bold pb-3 px-0.5 whitespace-nowrap transition-colors text-text-faint hover:text-text';
                ?>
                <a href="<?php echo esc_url($url); ?>"
                   class="<?php echo esc_attr($classes); ?>"
                   <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                    <?php echo esc_html($label); ?>
                    <?php if ($slug === 'keuzes' && $open_choices > 0) : ?>
                        <span class="bg-accent text-white text-[11px] font-bold rounded-full px-1.5 py-px">
                            <?php echo esc_html((string) $open_choices); ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Tab Content -->
        <div class="pt-7 pb-12 lg:pb-16">
            <?php
            stridence_template_part("templates/trajectory/tab-{$current_tab}", null, [
                'trajectory' => $trajectory,
                'enrollment' => $enrollment,
                'user' => $user,
                'dashboard_service' => $dashboardService,
                'progress' => $progress,
            ]);
?>
        </div>
    </div>
</div>
