<?php
/**
 * Dashboard Tab: Mijn opleidingen (My Courses)
 *
 * Shows user's classroom edition registrations AND online LearnDash courses
 * as individual cards (matching home tab pattern).
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\User\UserDashboardService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get all enrollment data from service
$dashboardService = ntdst_get(UserDashboardService::class);
$data = $dashboardService->getEnrollmentData($user_id);

$active_editions    = $data['active_editions'];
$active_online      = $data['active_online'];
$completed_items    = $data['completed_items'];
$cancelled_editions = $data['cancelled_editions'];
?>

<div class="space-y-8" x-data="dashboardHome()">
    <!-- Klassikale opleidingen (Classroom Editions) -->
    <?php if (!empty($active_editions)) : ?>
        <section>
            <h3 class="text-base font-semibold text-text mb-3">
                <?php esc_html_e('Klassikale opleidingen', 'stridence'); ?>
            </h3>
            <div class="space-y-4">
                <?php foreach ($active_editions as $i => $reg) :
                    $args = stridence_build_course_card_args_from_enrollment($reg);
                    $args['initial_open'] = ($i === 0);
                    get_template_part('templates/components/course-card', null, $args);
                endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Online cursussen -->
    <?php if (!empty($active_online)) : ?>
        <section>
            <h3 class="text-base font-semibold text-text mb-3">
                <?php esc_html_e('Online cursussen', 'stridence'); ?>
            </h3>
            <div class="space-y-4">
                <?php foreach ($active_online as $i => $course) :
                    $args = stridence_build_course_card_args_from_enrollment($course);
                    // First online card auto-expands ONLY if active_editions is empty
                    $args['initial_open'] = ($i === 0 && empty($active_editions));
                    get_template_part('templates/components/course-card', null, $args);
                endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Empty state when no active courses at all -->
    <?php if (empty($active_editions) && empty($active_online)) : ?>
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon'    => 'book-open',
            'title'   => __('Geen actieve opleidingen', 'stridence'),
            'message' => __('Je hebt momenteel geen actieve inschrijvingen. Bekijk ons aanbod en schrijf je in voor een opleiding.', 'stridence'),
            'action'  => __('Bekijk opleidingen', 'stridence'),
            'url'     => home_url('/klassikaal/'),
        ]);
        ?>
    <?php endif; ?>

    <!-- Afgerond (Completed - merged editions + online courses) -->
    <?php if (!empty($completed_items)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-3"
                    @click="open = !open">
                <h3 class="text-base font-semibold text-text">
                    <?php printf(
                        esc_html__('Afgerond (%d)', 'stridence'),
                        count($completed_items),
                    ); ?>
                </h3>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="space-y-2">
                    <?php foreach ($completed_items as $reg) :
                        $args = stridence_build_course_card_args_from_enrollment($reg, completed: true);
                        get_template_part('templates/components/course-card', null, $args);
                    endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Cancelled Registrations -->
    <?php if (!empty($cancelled_editions)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-3"
                    @click="open = !open">
                <h3 class="text-base font-semibold text-text-muted">
                    <?php
                    printf(
                        esc_html__('Geannuleerd (%d)', 'stridence'),
                        count($cancelled_editions),
                    );
        ?>
                </h3>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="space-y-2">
                    <?php foreach ($cancelled_editions as $reg) : ?>
                        <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg border border-border/60 bg-surface-card text-text-muted">
                            <div class="flex-1 min-w-0">
                                <span class="font-medium text-sm truncate block"><?php echo esc_html($reg['course_title']); ?></span>
                                <?php if ($reg['start_date']) : ?>
                                    <span class="text-xs"><?php echo esc_html(stride_format_date($reg['start_date'])); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</div>
