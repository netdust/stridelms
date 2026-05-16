<?php
/**
 * Dashboard Tab: Home
 *
 * Layout: actions → agenda → active courses → recent certificates.
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

$actions    = $home_data['actions'] ?? [];
$sessions   = $home_data['upcoming_sessions'] ?? [];
$enrollments  = $home_data['active_enrollments'] ?? [];
$certificates = $home_data['recent_certificates'] ?? [];
$permalink    = get_permalink();

$hasContent = !empty($actions) || !empty($sessions) || !empty($enrollments) || !empty($certificates);

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

?>

<div class="space-y-8" x-data="dashboardHome()">
    <?php if ($hasContent) : ?>

        <!-- Acties -->
        <?php if (!empty($actions)) :
            $visibleCount = 3;
            $hasMore = count($actions) > $visibleCount;
        ?>
            <section class="space-y-2" <?php echo $hasMore ? 'x-data="{ expanded: false }"' : ''; ?>>
                <?php foreach ($actions as $i => $action) :
                    $total      = (int) ($action['total_tasks'] ?? 0);
                    $done       = (int) ($action['done_tasks'] ?? 0);
                    $actionType = $action['type'] ?? '';
                    $hidden     = $hasMore && $i >= $visibleCount;
                    $xAttr      = $hidden ? 'x-show="expanded" x-cloak' : '';
                ?>
                    <?php if ($actionType === 'online_lesson') : ?>
                        <a href="<?php echo esc_url($action['url']); ?>"
                           class="flex items-center gap-2.5 rounded-lg border border-blue-200 bg-blue-50/50 px-3 py-2 hover:border-blue-300 hover:bg-blue-50 transition-colors"
                           <?php echo $xAttr; ?>>
                            <?php echo stridence_icon('play', 'w-4 h-4 text-blue-500 shrink-0'); ?>
                            <span class="text-sm font-medium text-text truncate"><?php echo esc_html($action['course_title']); ?></span>
                            <span class="text-xs text-text-muted shrink-0 ml-auto"><?php echo esc_html($action['label']); ?></span>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-blue-400 shrink-0'); ?>
                        </a>
                    <?php elseif ($actionType === 'session_selection') : ?>
                        <a href="<?php echo esc_url($action['url']); ?>"
                           class="flex items-center gap-2.5 rounded-lg border border-violet-200 bg-violet-50/50 px-3 py-2 hover:border-violet-300 hover:bg-violet-50 transition-colors"
                           <?php echo $xAttr; ?>>
                            <?php echo stridence_icon('list', 'w-4 h-4 text-violet-500 shrink-0'); ?>
                            <span class="text-sm font-medium text-text truncate"><?php echo esc_html($action['course_title']); ?></span>
                            <span class="text-xs text-text-muted shrink-0 ml-auto"><?php echo esc_html($action['label']); ?></span>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-violet-400 shrink-0'); ?>
                        </a>
                    <?php else : ?>
                        <a href="<?php echo esc_url($action['url']); ?>"
                           class="flex items-center gap-2.5 rounded-lg border border-status-warning bg-status-warning-subtle px-3 py-2 hover:border-status-warning hover:bg-status-warning-subtle transition-colors"
                           <?php echo $xAttr; ?>>
                            <?php echo stridence_icon('alert-circle', 'w-4 h-4 text-status-warning shrink-0'); ?>
                            <span class="text-sm font-medium text-text truncate"><?php echo esc_html($action['course_title']); ?></span>
                            <span class="text-xs text-text-muted shrink-0 ml-auto">
                                <?php echo esc_html($action['label']); ?>
                                <?php if ($total > 0) : ?>
                                    · <?php echo esc_html($done . '/' . $total); ?>
                                <?php endif; ?>
                            </span>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-status-warning shrink-0'); ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($hasMore) : ?>
                    <button @click="expanded = !expanded"
                            class="text-sm text-primary hover:underline cursor-pointer">
                        <span x-show="!expanded"><?php echo esc_html(sprintf(__('Toon alle %d acties', 'stridence'), count($actions))); ?></span>
                        <span x-show="expanded" x-cloak><?php esc_html_e('Minder tonen', 'stridence'); ?></span>
                    </button>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- Agenda -->
        <?php if (!empty($sessions)) : ?>
            <section>
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-base font-semibold text-text">
                        <?php esc_html_e('Binnenkort', 'stridence'); ?>
                    </h3>
                    <button type="button"
                            class="inline-flex items-center gap-1.5 text-xs text-text-muted hover:text-primary transition-colors cursor-pointer"
                            @click="downloadIcal()"
                            :disabled="icalLoading"
                            title="<?php esc_attr_e('Exporteer naar agenda', 'stridence'); ?>">
                        <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                        <span><?php esc_html_e('Exporteer', 'stridence'); ?></span>
                    </button>
                </div>
                <div class="space-y-1.5">
                    <?php foreach ($sessions as $session) :
                        $isToday    = ($session['date'] ?? '') === date('Y-m-d');
                        $isTomorrow = ($session['date'] ?? '') === date('Y-m-d', strtotime('+1 day'));
                        $editionSlug = get_post_field('post_name', (int) ($session['edition_id'] ?? 0));
                        $detailUrl   = $editionSlug ? home_url('/vormingen/' . $editionSlug . '/') : '';
                    ?>
                        <a href="<?php echo esc_url($detailUrl); ?>"
                           class="flex items-center gap-3 rounded-lg border border-border/60 bg-surface-card pl-1.5 pr-3 py-2 hover:border-border transition-colors">
                            <?php if ($isToday || $isTomorrow) : ?>
                                <span class="w-10 h-10 rounded-md bg-primary/10 flex items-center justify-center shrink-0">
                                    <span class="text-[10px] font-bold text-primary leading-none"><?php echo $isToday ? esc_html__('Vandaag', 'stridence') : esc_html__('Morgen', 'stridence'); ?></span>
                                </span>
                            <?php else : ?>
                                <span class="w-10 h-10 rounded-md bg-surface-alt flex flex-col items-center justify-center shrink-0">
                                    <span class="text-[9px] uppercase font-semibold text-text-muted leading-none"><?php echo esc_html(date_i18n('M', strtotime($session['date']))); ?></span>
                                    <span class="text-sm font-bold text-text leading-none mt-0.5"><?php echo esc_html(date('j', strtotime($session['date']))); ?></span>
                                </span>
                            <?php endif; ?>
                            <span class="flex-1 min-w-0 text-sm leading-snug">
                                <span class="font-medium text-text"><?php echo esc_html($session['course_title'] ?? ''); ?></span>
                                <br>
                                <span class="text-xs text-text-muted"><?php
                                    if (!empty($session['start_time'])) {
                                        echo esc_html($session['start_time']);
                                        if (!empty($session['end_time'])) {
                                            echo ' – ' . esc_html($session['end_time']);
                                        }
                                    }
                                    if (!empty($session['location'])) {
                                        echo ' · ' . esc_html($session['location']);
                                    }
                                ?></span>
                            </span>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-muted shrink-0'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Opleidingen -->
        <?php if (!empty($enrollments)) : ?>
            <section>
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-base font-semibold text-text">
                        <?php esc_html_e('Opleidingen', 'stridence'); ?>
                    </h3>
                    <a href="<?php echo esc_url(add_query_arg('tab', 'inschrijvingen', $permalink)); ?>"
                       class="text-sm text-primary hover:underline">
                        <?php esc_html_e('Alles bekijken', 'stridence'); ?>
                    </a>
                </div>
                <div class="space-y-4">
                    <?php foreach (array_slice($enrollmentsJson, 0, 4) as $i => $enrollment) :
                        $args = stridence_build_course_card_args_from_enrollment($enrollment);
                        $args['initial_open'] = ($i === 0);
                        get_template_part('templates/components/course-card', null, $args);
                    endforeach; ?>
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
                        <div class="flex items-center gap-3 px-3 py-2.5 rounded-lg border border-border/60 bg-surface-card">
                            <span class="w-8 h-8 rounded-lg bg-success/10 flex items-center justify-center shrink-0">
                                <?php echo stridence_icon('award', 'w-4 h-4 text-success'); ?>
                            </span>
                            <div class="flex-1 min-w-0">
                                <span class="text-sm font-medium text-text truncate block">
                                    <?php echo esc_html($cert['course_title'] ?? ''); ?>
                                </span>
                                <?php if (!empty($cert['completed_at'])) : ?>
                                    <span class="text-xs text-text-muted">
                                        <?php echo esc_html(stride_format_date($cert['completed_at'])); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php $certUrl = $cert['certificate_url'] ?? ''; ?>
                            <?php if ($certUrl) : ?>
                                <a href="<?php echo esc_url($certUrl); ?>"
                                   class="text-sm text-primary hover:underline shrink-0"
                                   target="_blank"
                                   rel="noopener">
                                    <?php echo stridence_icon('download', 'w-4 h-4'); ?>
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
        <?php stridence_template_part('templates/dashboard/partials/panel-enrollment'); ?>
    <?php endif; ?>
</div>
