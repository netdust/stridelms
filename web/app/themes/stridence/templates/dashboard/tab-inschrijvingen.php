<?php
/**
 * Dashboard Tab: Mijn inschrijvingen
 *
 * Helder Tij redesign — flat row cards per enrollment.
 * Row card: badge row + 16px/700 title + 13px meta line (dates/venue/progress)
 * Right column: action warning + ghost/primary CTAs per existing branches.
 * Cancelled rows: opacity-80, muted title, ghost "Bekijk alternatieven".
 * Online rows: progress bar (existing partial).
 * Empty state via partials/empty-state.
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

<div class="space-y-8">

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
    <?php else : ?>

        <!-- Active enrollments — flat row cards -->
        <section>
            <div class="flex flex-col gap-[14px]">

                <?php foreach ($active_editions as $reg) :
                    // Calculate pending action count from task_summary
                    $taskSummary = $reg['task_summary'] ?? null;
                    $pendingCount = 0;
                    if ($taskSummary) {
                        $pendingCount = max(0, (int) ($taskSummary['total'] ?? 0) - (int) ($taskSummary['completed'] ?? 0));
                    }

                    // CTA branches: primary (Ga verder) or secondary (Bekijk details)
                    $cta = $reg['cta'] ?? null;
                    $editionId = (int) ($reg['edition_id'] ?? 0);
                    $editionPermalink = $editionId ? get_permalink($editionId) : null;

                    // Meta line: start date, venue, progress
                    $startDate = $reg['start_date'] ?? null;
                    $venue     = $reg['venue'] ?? null;
                    $progress  = $reg['progress'] ?? null;
                    ?>
                    <div class="bg-surface-card rounded-[16px] shadow-card p-[22px] px-6 flex flex-wrap justify-between gap-4"
                         <?php if ($editionId) : ?>data-edition-id="<?php echo (int) $editionId; ?>"<?php endif; ?>>
                        <!-- LEFT: badge + title + meta -->
                        <div class="flex-1 min-w-[240px]">
                            <!-- Badge row: type pill (inline) + enrolled pill -->
                            <div class="flex flex-wrap gap-[6px] mb-[10px]">
                                <span class="text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1 bg-badge-online-bg text-badge-online-text">
                                    <?php esc_html_e('Klassikaal', 'stridence'); ?>
                                </span>
                                <?php stridence_template_part('partials/badge-status', null, ['status' => 'enrolled', 'size' => 'sm']); ?>
                            </div>

                            <!-- Title 16px/700 -->
                            <div class="text-[16px] font-bold text-text leading-snug">
                                <?php echo esc_html($reg['course_title'] ?? ''); ?>
                            </div>

                            <!-- Meta line 13px muted: dates strong / venue / progress -->
                            <?php $hasMetaLine = $startDate || $venue || ($progress && is_array($progress)); ?>
                            <?php if ($hasMetaLine) : ?>
                                <div class="text-[13px] text-text-muted mt-1 flex flex-wrap gap-x-[10px] gap-y-0.5">
                                    <?php if ($startDate) : ?>
                                        <span><strong class="text-text font-semibold"><?php echo esc_html(stride_format_date($startDate)); ?></strong></span>
                                    <?php endif; ?>
                                    <?php if ($venue) : ?>
                                        <span><?php echo esc_html($venue); ?></span>
                                    <?php endif; ?>
                                    <?php if (is_array($progress) && ($progress['required'] ?? 0) > 0) :
                                        $attended = (int) ($progress['attended'] ?? 0);
                                        $required = (int) $progress['required'];
                                        ?>
                                        <span><?php echo esc_html(sprintf(
                                            /* translators: %1$d attended, %2$d required sessions */
                                            __('%1$d/%2$d sessies', 'stridence'),
                                            $attended,
                                            $required,
                                        )); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php // Online progress bar — keep for any edition that has online progress?>
                        </div>

                        <!-- RIGHT: action warning + CTA buttons -->
                        <div class="flex flex-col gap-[6px] items-end justify-center shrink-0">
                            <?php if ($pendingCount > 0) : ?>
                                <span class="text-[12px] font-bold text-badge-few-text">
                                    <?php echo esc_html(sprintf(
                                        /* translators: %d: number of pending actions */
                                        _n('%d actie open', '%d acties open', $pendingCount, 'stridence'),
                                        $pendingCount,
                                    )); ?>
                                </span>
                            <?php endif; ?>

                            <?php if ($cta) : ?>
                                <a href="<?php echo esc_url($cta['url']); ?>"
                                   class="btn-primary btn-sm">
                                    <?php echo esc_html($cta['label']); ?>
                                </a>
                            <?php elseif ($editionPermalink) : ?>
                                <a href="<?php echo esc_url($editionPermalink); ?>"
                                   class="btn-ghost btn-sm">
                                    <?php esc_html_e('Bekijk details', 'stridence'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($active_online as $reg) :
                    $progressPct      = (int) ($reg['progress'] ?? 0);
                    $courseId         = (int) ($reg['course_id'] ?? 0);
                    $cta              = null;
                    $ctaUrl           = $reg['course_url'] ?? '';
                    if (!$ctaUrl && $courseId) {
                        $courseSlug = get_post_field('post_name', $courseId);
                        $ctaUrl = $courseSlug ? home_url('/edities/' . $courseSlug . '/') : '';
                    }
                    if ($ctaUrl) {
                        $cta = [
                            'url'   => $ctaUrl,
                            'label' => $progressPct > 0 ? __('Ga verder', 'stridence') : __('Start cursus', 'stridence'),
                        ];
                    }
                    ?>
                    <div class="bg-surface-card rounded-[16px] shadow-card p-[22px] px-6 flex flex-wrap justify-between gap-4">
                        <!-- LEFT: badge + title + progress bar -->
                        <div class="flex-1 min-w-[240px]">
                            <!-- Badge row -->
                            <div class="flex flex-wrap gap-[6px] mb-[10px]">
                                <?php stridence_template_part('partials/badge-status', null, ['status' => 'online', 'size' => 'sm']); ?>
                                <?php if ($progressPct > 0) :
                                    stridence_template_part('partials/badge-status', null, [
                                        'status' => 'enrolled',
                                        'size'   => 'sm',
                                    ]);
                                endif; ?>
                            </div>

                            <!-- Title 16px/700 -->
                            <div class="text-[16px] font-bold text-text leading-snug">
                                <?php echo esc_html($reg['course_title'] ?? ''); ?>
                            </div>

                            <!-- Progress bar for online courses -->
                            <?php if ($progressPct > 0) : ?>
                                <div class="mt-[10px] max-w-[320px]">
                                    <div class="h-[7px] rounded-full bg-surface-alt overflow-hidden">
                                        <div class="h-full bg-primary rounded-full"
                                             style="width: <?php echo (int) $progressPct; ?>%"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- RIGHT: primary CTA -->
                        <?php if ($cta) : ?>
                            <div class="flex flex-col justify-center shrink-0">
                                <a href="<?php echo esc_url($cta['url']); ?>"
                                   class="btn-primary btn-sm">
                                    <?php echo esc_html($cta['label']); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

            </div>
        </section>

    <?php endif; ?>

    <!-- Afgerond (Completed) — collapsible section -->
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
                <div class="flex flex-col gap-[14px]">
                    <?php foreach ($completed_items as $reg) :
                        $args = stridence_build_course_card_args_from_enrollment($reg, completed: true);
                        stridence_template_part('partials/card-course-expandable', null, $args);
                    endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Geannuleerd (Cancelled) — collapsible section -->
    <?php if (!empty($cancelled_editions)) : ?>
        <section x-data="{ open: false }">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 mb-3"
                    @click="open = !open">
                <h3 class="text-base font-semibold text-text-muted">
                    <?php printf(
                        esc_html__('Geannuleerd (%d)', 'stridence'),
                        count($cancelled_editions),
                    ); ?>
                </h3>
                <span class="text-text-muted transition-transform duration-200"
                      :class="{ 'rotate-180': open }">
                    <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                </span>
            </button>

            <div x-show="open" x-collapse>
                <div class="flex flex-col gap-[14px]">
                    <?php foreach ($cancelled_editions as $reg) :
                        $courseTitle  = esc_html($reg['course_title'] ?? '');
                        $startDate    = $reg['start_date'] ?? null;
                        $courseId     = (int) ($reg['course_id'] ?? 0);
                        $altUrl       = $courseId ? home_url('/edities/' . get_post_field('post_name', $courseId) . '/') : home_url('/klassikaal/');
                        ?>
                        <div class="bg-surface-card rounded-[16px] shadow-card p-[22px] px-6 flex flex-wrap justify-between gap-4 opacity-80">
                            <!-- LEFT: badge + title (muted) + date -->
                            <div class="flex-1 min-w-[240px]">
                                <div class="flex flex-wrap gap-[6px] mb-[10px]">
                                    <span class="text-[11px] font-bold px-[9px] py-[3px] rounded-full inline-flex items-center gap-1 bg-badge-online-bg text-badge-online-text">
                                        <?php esc_html_e('Klassikaal', 'stridence'); ?>
                                    </span>
                                    <?php stridence_template_part('partials/badge-status', null, ['status' => 'cancelled', 'size' => 'sm']); ?>
                                </div>
                                <div class="text-[16px] font-bold text-text-muted leading-snug">
                                    <?php echo $courseTitle; ?>
                                </div>
                                <?php if ($startDate) : ?>
                                    <div class="text-[13px] text-text-muted mt-1">
                                        <?php echo esc_html(stride_format_date($startDate)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- RIGHT: ghost "Bekijk alternatieven" -->
                            <div class="flex flex-col justify-center shrink-0">
                                <a href="<?php echo esc_url($altUrl); ?>"
                                   class="btn-ghost btn-sm">
                                    <?php esc_html_e('Bekijk alternatieven', 'stridence'); ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

</div>
