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

use Stride\Integrations\LearnDash\LearnDashHelper;
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
                <?php foreach ($active_editions as $reg) :
                    $taskSummary = $reg['task_summary'] ?? null;
                    $pendingTasks = 0;
                    if ($taskSummary) {
                        $pendingTasks = (int) ($taskSummary['total'] ?? 0) - (int) ($taskSummary['completed'] ?? 0);
                    }

                    // Progress
                    $attended = (int) ($reg['progress']['attended'] ?? 0);
                    $required = (int) ($reg['progress']['required'] ?? 0);
                    $progressPct = $required > 0 ? (int) round(($attended / $required) * 100) : 0;
                    $progressLabel = $required > 0
                        ? sprintf(
                            _n('%d van %d sessie', '%d van %d sessies', $required, 'stridence'),
                            $attended, $required
                        )
                        : '';

                    // CTA
                    $completeUrl = $reg['complete_url'] ?? '';
                    $ctaUrl = '';
                    $ctaLabel = '';
                    if ($pendingTasks > 0 && $completeUrl) {
                        $ctaUrl = $completeUrl;
                        $tasks = $taskSummary['tasks'] ?? [];
                        $hasSessionSelection = isset($tasks['session_selection']) && ($tasks['session_selection']['status'] ?? '') !== 'completed';
                        $hasPostCourse = false;
                        foreach (['post_evaluation', 'post_documents', 'post_approval'] as $pt) {
                            if (isset($tasks[$pt]) && ($tasks[$pt]['status'] ?? '') !== 'completed') {
                                $hasPostCourse = true;
                                break;
                            }
                        }
                        $ctaLabel = match (true) {
                            $hasSessionSelection => __('Sessiekeuze maken', 'stridence'),
                            $hasPostCourse       => __('Vorming afronden', 'stridence'),
                            default              => __('Inschrijving voltooien', 'stridence'),
                        };
                    }

                    // Next session info
                    $nextSession = $reg['next_session'] ?? null;
                    $nextDate = $nextSession['date'] ?? '';

                    // Future sessions for collapsible list
                    $futureSessions = array_filter($reg['sessions'] ?? [], fn($s) => ($s['date'] ?? '') >= date('Y-m-d') && ($s['type'] ?? '') !== 'online' && ($s['type'] ?? '') !== 'assignment');

                    // Detail link
                    $editionSlug = get_post_field('post_name', (int) $reg['edition_id']);
                    $detailUrl = $editionSlug ? home_url('/vormingen/' . $editionSlug . '/') : get_permalink($reg['edition_id']);
                ?>
                    <div class="rounded-xl border border-border bg-surface-card shadow-sm" x-data="{ sessionsOpen: false }">
                        <!-- Card body -->
                        <div class="px-4 pt-3.5 pb-3">
                            <div class="flex items-start gap-3">
                                <?php
                                    $displayDate = $nextDate ?: ($reg['start_date'] ?? '');
                                    $isToday    = $displayDate === date('Y-m-d');
                                    $isTomorrow = $displayDate === date('Y-m-d', strtotime('+1 day'));
                                ?>
                                <?php if ($displayDate && ($isToday || $isTomorrow)) : ?>
                                    <span class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center shrink-0 mt-0.5">
                                        <span class="text-[10px] font-bold text-primary leading-none"><?php echo $isToday ? esc_html__('Vandaag', 'stridence') : esc_html__('Morgen', 'stridence'); ?></span>
                                    </span>
                                <?php elseif ($displayDate) : ?>
                                    <span class="w-10 h-10 rounded-lg bg-surface-alt flex flex-col items-center justify-center shrink-0 mt-0.5">
                                        <span class="text-[9px] uppercase font-semibold text-text-muted leading-none"><?php echo esc_html(date_i18n('M', strtotime($displayDate))); ?></span>
                                        <span class="text-sm font-bold text-text leading-none mt-0.5"><?php echo esc_html(date('j', strtotime($displayDate))); ?></span>
                                    </span>
                                <?php else : ?>
                                    <span class="w-10 h-10 rounded-lg bg-surface-alt flex items-center justify-center shrink-0 mt-0.5">
                                        <?php echo stridence_icon('calendar', 'w-4 h-4 text-text-muted'); ?>
                                    </span>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <span class="block text-sm font-semibold text-text leading-snug"><?php echo esc_html($reg['course_title']); ?></span>
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-text-muted">
                                        <?php if ($reg['venue']) : ?>
                                            <span class="flex items-center gap-1">
                                                <?php echo stridence_icon('map-pin', 'w-3 h-3'); ?>
                                                <?php echo esc_html($reg['venue']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($nextSession && !empty($nextSession['start_time'])) : ?>
                                            <span><?php
                                                echo esc_html($nextSession['start_time']);
                                                if (!empty($nextSession['end_time'])) {
                                                    echo ' – ' . esc_html($nextSession['end_time']);
                                                }
                                            ?></span>
                                        <?php endif; ?>
                                        <?php if ($progressLabel) : ?>
                                            <span><?php echo esc_html($progressLabel); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($pendingTasks > 0) : ?>
                                    <span class="w-2 h-2 rounded-full bg-amber-400 shrink-0 mt-2" title="<?php echo esc_attr(sprintf(
                                        _n('%d openstaande taak', '%d openstaande taken', $pendingTasks, 'stridence'),
                                        $pendingTasks
                                    )); ?>"></span>
                                <?php endif; ?>
                            </div>
                            <?php if ($progressPct > 0) : ?>
                                <div class="mt-3">
                                    <div class="h-1 bg-surface-alt rounded-full overflow-hidden">
                                        <div class="h-full <?php echo $progressPct >= 100 ? 'bg-success' : 'bg-primary'; ?> rounded-full transition-all duration-500"
                                             style="width: <?php echo esc_attr(min(100, $progressPct)); ?>%"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Sessions (collapsible) -->
                        <?php if (!empty($futureSessions)) : ?>
                            <div class="border-t border-border/50">
                                <button @click="sessionsOpen = !sessionsOpen"
                                        class="w-full flex items-center justify-between px-4 py-2 text-xs text-text-muted hover:text-text transition-colors cursor-pointer">
                                    <span><?php echo esc_html(sprintf(
                                        _n('%d komende sessie', '%d komende sessies', count($futureSessions), 'stridence'),
                                        count($futureSessions)
                                    )); ?></span>
                                    <span class="transition-transform duration-200" :class="{ 'rotate-180': sessionsOpen }">
                                        <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                                    </span>
                                </button>
                                <div x-show="sessionsOpen" x-collapse x-cloak>
                                    <div class="px-4 pb-3 space-y-1">
                                        <?php foreach (array_values($futureSessions) as $s) :
                                            $sDate = $s['date'] ?? '';
                                            $sStart = $s['start_time'] ?? '';
                                            $sTitle = $s['title'] ?? '';
                                            $sAttendance = $s['attendance'] ?? null;
                                            $sSelected = !empty($s['selected']);
                                        ?>
                                            <div class="flex items-center gap-2 text-xs">
                                                <?php if ($sSelected) : ?>
                                                    <?php echo stridence_icon('check', 'w-3.5 h-3.5 text-primary shrink-0'); ?>
                                                <?php elseif ($sAttendance === 'present') : ?>
                                                    <?php echo stridence_icon('check', 'w-3.5 h-3.5 text-success shrink-0'); ?>
                                                <?php elseif ($sAttendance === 'absent') : ?>
                                                    <?php echo stridence_icon('x', 'w-3.5 h-3.5 text-error shrink-0'); ?>
                                                <?php else : ?>
                                                    <span class="w-1.5 h-1.5 rounded-full bg-border shrink-0"></span>
                                                <?php endif; ?>
                                                <span class="text-text-muted w-16 shrink-0">
                                                    <?php echo esc_html($sDate ? date_i18n('j M', strtotime($sDate)) : ''); ?>
                                                </span>
                                                <span class="text-text-muted shrink-0">
                                                    <?php if ($sStart) echo esc_html($sStart); ?>
                                                </span>
                                                <?php if ($sTitle) : ?>
                                                    <span class="text-text truncate <?php echo $sSelected ? 'font-medium text-primary' : ''; ?>"><?php echo esc_html($sTitle); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Footer -->
                        <div class="flex items-center gap-3 px-4 py-2 border-t border-border bg-surface-alt/60 rounded-b-xl">
                            <?php if ($ctaUrl) : ?>
                                <a href="<?php echo esc_url($ctaUrl); ?>"
                                   class="text-xs font-semibold text-primary hover:underline">
                                    <?php echo esc_html($ctaLabel); ?>
                                </a>
                            <?php endif; ?>
                            <a href="<?php echo esc_url($detailUrl); ?>"
                               class="text-xs text-text-muted hover:text-text transition-colors">
                                <?php esc_html_e('Bekijk', 'stridence'); ?>
                            </a>
                            <button type="button"
                                    class="ml-auto text-text-muted hover:text-primary transition-colors cursor-pointer"
                                    @click="downloadIcal(<?php echo (int) $reg['edition_id']; ?>)"
                                    title="<?php esc_attr_e('Toevoegen aan agenda', 'stridence'); ?>">
                                <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
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
                <?php foreach ($active_online as $course) :
                    $progress = (int) ($course['progress'] ?? 0);
                    $completedLessons = (int) ($course['completed_lessons'] ?? 0);
                    $totalLessons = (int) ($course['total_lessons'] ?? 0);
                    $progressLabel = $totalLessons > 0
                        ? sprintf(
                            _n('%d van %d les', '%d van %d lessen', $totalLessons, 'stridence'),
                            $completedLessons, $totalLessons
                        )
                        : '';
                    $ctaLabel = $progress > 0 ? __('Verder leren', 'stridence') : __('Start cursus', 'stridence');
                    $daysLeft = $course['days_remaining'] ?? null;
                ?>
                    <div class="rounded-xl border border-border bg-surface-card shadow-sm">
                        <!-- Card body -->
                        <div class="px-4 pt-3.5 pb-3">
                            <div class="flex items-start gap-3">
                                <span class="w-8 h-8 rounded-lg bg-accent/10 text-accent flex items-center justify-center shrink-0 mt-0.5">
                                    <?php echo stridence_icon('wifi', 'w-4 h-4'); ?>
                                </span>
                                <div class="flex-1 min-w-0">
                                    <span class="block text-sm font-semibold text-text leading-snug"><?php echo esc_html($course['course_title']); ?></span>
                                    <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-text-muted">
                                        <span><?php echo esc_html($course['format_label']); ?></span>
                                        <?php if ($daysLeft !== null && $daysLeft > 0 && $daysLeft <= 30) : ?>
                                            <span class="text-amber-600"><?php echo esc_html(sprintf(__('Nog %d dagen', 'stridence'), $daysLeft)); ?></span>
                                        <?php endif; ?>
                                        <?php if ($progressLabel) : ?>
                                            <span><?php echo esc_html($progressLabel); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if ($progress > 0) : ?>
                                <div class="mt-3">
                                    <div class="h-1 bg-surface-alt rounded-full overflow-hidden">
                                        <div class="h-full <?php echo $progress >= 100 ? 'bg-success' : 'bg-primary'; ?> rounded-full transition-all duration-500"
                                             style="width: <?php echo esc_attr(min(100, $progress)); ?>%"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Footer -->
                        <div class="flex items-center gap-3 px-4 py-2 border-t border-border bg-surface-alt/60 rounded-b-xl">
                            <a href="<?php echo esc_url($course['course_url']); ?>"
                               class="text-xs font-semibold text-primary hover:underline">
                                <?php echo esc_html($ctaLabel); ?>
                            </a>
                            <a href="<?php echo esc_url(get_permalink($course['course_id'])); ?>"
                               class="text-xs text-text-muted hover:text-text transition-colors">
                                <?php esc_html_e('Bekijk', 'stridence'); ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Empty state when no active courses at all -->
    <?php if (empty($active_editions) && empty($active_online)) : ?>
        <?php
        get_template_part('partials/empty-state', null, [
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
                        count($completed_items)
                    ); ?>
                </h3>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="space-y-2">
                    <?php foreach ($completed_items as $item) :
                        $cert_url = $item['certificate_url'] ?? '';
                        if (!$cert_url && !empty($item['course_id'])) {
                            $cert_url = LearnDashHelper::getCertificateLink((int) $item['course_id'], $user_id);
                        }
                        $isOnline = ($item['type'] ?? '') === 'online';
                    ?>
                        <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg border border-border/60 bg-surface-card">
                            <span class="w-8 h-8 rounded-lg bg-success/10 flex items-center justify-center shrink-0">
                                <?php echo stridence_icon('award', 'w-4 h-4 text-success'); ?>
                            </span>
                            <div class="flex-1 min-w-0">
                                <span class="text-sm font-medium text-text truncate block"><?php echo esc_html($item['course_title']); ?></span>
                                <span class="text-xs text-text-muted">
                                    <?php
                                    $date = $item['completed_at'] ?? $item['start_date'] ?? '';
                                    if ($date) {
                                        echo esc_html(stride_format_date($date));
                                    }
                                    ?>
                                </span>
                            </div>
                            <?php if ($cert_url) : ?>
                                <a href="<?php echo esc_url(add_query_arg('tab', 'certificaten', get_permalink())); ?>"
                                   class="text-sm text-primary hover:underline shrink-0">
                                    <?php echo stridence_icon('download', 'w-4 h-4'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
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
                        count($cancelled_editions)
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
