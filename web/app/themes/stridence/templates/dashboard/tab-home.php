<?php
/**
 * Dashboard Tab: Home — Helder Tij.
 *
 * Layout: greeting → hero next-step band → stat cards → "Acties nodig" card
 *         → agenda → enrollment panels grid → recent certificates.
 * All sections adaptive — only render when data exists. Stat values are
 * derived from the data already passed in (no extra service calls).
 *
 * @param array $args {
 *     @type WP_User $user      Current user object
 *     @type array   $home_data Aggregated data from UserDashboardService::getHomeData()
 *     @type string  $greeting  Time-of-day greeting
 *     @type string  $firstName User first name
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$user      = $args['user'] ?? wp_get_current_user();
$home_data = $args['home_data'] ?? [];
$greeting  = $args['greeting'] ?? '';
$firstName = $args['firstName'] ?? '';

$hero         = $home_data['hero'] ?? null;
$actions      = $home_data['actions'] ?? [];
$sessions     = $home_data['upcoming_sessions'] ?? [];
$enrollments  = $home_data['active_enrollments'] ?? [];
$certificates = $home_data['recent_certificates'] ?? [];
$permalink    = get_permalink();

$hasContent = !empty($actions) || !empty($sessions) || !empty($enrollments) || !empty($certificates);

// Stat cards — derived from the home payload itself (no new data flow).
$stats = [];
if ($hasContent) {
    $taskActionCount = count(array_filter($actions, fn($a) => ($a['type'] ?? '') !== 'online_lesson'));

    $stats[] = [
        'label'   => __('Actieve inschrijvingen', 'stridence'),
        'value'   => count($enrollments),
        'context' => $taskActionCount > 0
            ? sprintf(
                /* translators: %d: number of enrollments awaiting an action */
                _n('waarvan %d wacht op actie', 'waarvan %d wachten op actie', $taskActionCount, 'stridence'),
                $taskActionCount,
            )
            : '',
        'color'   => $taskActionCount > 0 ? 'warning' : '',
    ];
    $stats[] = [
        'label'   => __('Komende sessies', 'stridence'),
        'value'   => count($sessions),
        'context' => !empty($sessions[0]['date'])
            ? sprintf(
                /* translators: %s: date of the next session */
                __('eerstvolgende op %s', 'stridence'),
                stride_format_date($sessions[0]['date']),
            )
            : '',
    ];
    $stats[] = [
        'label'   => __('Recent behaald', 'stridence'),
        'value'   => count($certificates),
        'context' => !empty($certificates[0]['completed_at'])
            ? sprintf(
                /* translators: %s: date of the most recent certificate */
                __('laatste op %s', 'stridence'),
                stride_format_date($certificates[0]['completed_at']),
            )
            : '',
        'color'   => count($certificates) > 0 ? 'success' : '',
    ];
}
?>

<div class="flex flex-col gap-6" x-data="dashboardHome()">
    <?php if ($hasContent) : ?>

        <!-- Greeting -->
        <?php if ($greeting && $firstName) : ?>
            <header>
                <h1 class="font-serif font-normal text-[clamp(26px,3.5vw,34px)] leading-[1.1] text-text">
                    <?php echo esc_html($greeting . ', ' . $firstName); ?>
                </h1>
                <?php if (!empty($actions)) : ?>
                    <p class="text-sm text-text-muted mt-1.5">
                        <?php echo esc_html(sprintf(_n('%d actie nodig', '%d acties nodig', count($actions), 'stridence'), count($actions))); ?>
                    </p>
                <?php endif; ?>
            </header>
        <?php endif; ?>

        <!-- Hero next-step band -->
        <?php stridence_template_part('templates/dashboard/partials/hero-action', null, ['hero' => $hero]); ?>

        <!-- Stat cards -->
        <?php stridence_template_part('templates/dashboard/partials/stat-cards', null, ['stats' => $stats]); ?>

        <!-- Acties nodig -->
        <?php stridence_template_part('templates/dashboard/partials/action-items', null, ['items' => $actions]); ?>

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
                <div class="flex flex-col gap-2">
                    <?php foreach ($sessions as $session) :
                        $isToday    = ($session['date'] ?? '') === date('Y-m-d');
                        $isTomorrow = ($session['date'] ?? '') === date('Y-m-d', strtotime('+1 day'));
                        $editionSlug = get_post_field('post_name', (int) ($session['edition_id'] ?? 0));
                        $detailUrl   = $editionSlug ? home_url('/edities/' . $editionSlug . '/') : '';
                        ?>
                        <a href="<?php echo esc_url($detailUrl); ?>"
                           class="flex items-center gap-3 bg-surface-card rounded-[12px] shadow-card pl-2 pr-4 py-2.5 hover:bg-surface transition-colors">
                            <?php if ($isToday || $isTomorrow) : ?>
                                <span class="w-10 h-10 rounded-[8px] bg-badge-online-bg flex items-center justify-center shrink-0">
                                    <span class="text-[10px] font-bold text-badge-online-text leading-none"><?php echo $isToday ? esc_html__('Vandaag', 'stridence') : esc_html__('Morgen', 'stridence'); ?></span>
                                </span>
                            <?php else : ?>
                                <span class="w-10 h-10 rounded-[8px] bg-surface-alt flex flex-col items-center justify-center shrink-0">
                                    <span class="text-[9px] uppercase font-semibold text-text-muted leading-none"><?php echo esc_html(date_i18n('M', strtotime($session['date']))); ?></span>
                                    <span class="text-sm font-bold text-text leading-none mt-0.5"><?php echo esc_html(date('j', strtotime($session['date']))); ?></span>
                                </span>
                            <?php endif; ?>
                            <span class="flex-1 min-w-0 leading-snug">
                                <span class="text-[14px] font-bold text-text"><?php echo esc_html($session['course_title'] ?? ''); ?></span>
                                <br>
                                <span class="text-[13px] text-text-muted"><?php
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
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-faint shrink-0'); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <!-- Opleidingen — enrollment panels grid -->
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
                <div class="grid grid-cols-[repeat(auto-fit,minmax(320px,1fr))] gap-[18px]">
                    <?php foreach (array_slice($enrollments, 0, 4) as $enrollment) :
                        stridence_template_part('templates/dashboard/partials/panel-enrollment', null, [
                            'enrollment' => $enrollment,
                        ]);
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
                <div class="flex flex-col gap-2">
                    <?php foreach (array_slice($certificates, 0, 3) as $cert) : ?>
                        <div class="flex items-center gap-3.5 bg-surface-card rounded-[12px] shadow-card p-4">
                            <span class="w-[38px] h-[38px] rounded-[10px] bg-badge-online-bg text-badge-online-text flex items-center justify-center shrink-0 text-[14px] font-extrabold">
                                ✓
                            </span>
                            <div class="flex-1 min-w-0">
                                <span class="text-[14px] font-bold text-text truncate block">
                                    <?php echo esc_html($cert['course_title'] ?? ''); ?>
                                </span>
                                <?php if (!empty($cert['completed_at'])) : ?>
                                    <span class="text-[12px] text-text-faint">
                                        <?php
                                        printf(
                                            /* translators: %s: completion date */
                                            esc_html__('behaald op %s', 'stridence'),
                                            esc_html(stride_format_date($cert['completed_at'])),
                                        );
                                    ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php $certUrl = $cert['certificate_url'] ?? ''; ?>
                            <?php if ($certUrl) : ?>
                                <a href="<?php echo esc_url($certUrl); ?>"
                                   class="text-primary hover:text-primary-hover shrink-0"
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
        <?php
        stridence_template_part('partials/empty-state', null, [
            'icon'    => 'book-open',
            'title'   => __('Welkom bij Stride!', 'stridence'),
            'message' => __('Je hebt nog geen actieve opleidingen. Ontdek ons aanbod en schrijf je in voor je eerste opleiding.', 'stridence'),
            'action'  => __('Bekijk opleidingen', 'stridence'),
            'url'     => home_url('/klassikaal/'),
        ]);
        ?>

    <?php endif; ?>
</div>
