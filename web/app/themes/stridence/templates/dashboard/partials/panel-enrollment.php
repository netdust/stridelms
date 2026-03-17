<?php
/**
 * Enrollment Side Panel Partial
 *
 * Slide-in panel for enrollment quick-view from the dashboard home screen.
 * Controlled entirely by Alpine.js — all data comes from the `activeEnrollment`
 * object in the parent `dashboardHome` component.
 *
 * Expected Alpine state (from dashboardHome):
 *   panelOpen: bool
 *   activeEnrollment: {
 *     type: 'edition'|'online',
 *     course_title: string,
 *     // Edition: start_date, venue, edition_id, sessions[], progress.attended, progress.required, progress.percentage
 *     // Online: course_url, progress (int), total_lessons, completed_lessons, days_remaining, format_label
 *   }
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$permalink = get_permalink();
?>

<!-- Backdrop -->
<div x-show="panelOpen"
     x-cloak
     class="slide-panel-backdrop"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     @click="closePanel()"
     @keydown.escape.window="closePanel()">
</div>

<!-- Panel -->
<div x-show="panelOpen"
     x-cloak
     class="dash-panel"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="translate-x-full sm:translate-x-full translate-y-full sm:translate-y-0"
     x-transition:enter-end="translate-x-0 translate-y-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="translate-x-0 translate-y-0"
     x-transition:leave-end="translate-x-full sm:translate-x-full translate-y-full sm:translate-y-0"
     @click.stop>

    <template x-if="activeEnrollment">
        <div class="flex flex-col h-full">

            <!-- Header -->
            <div class="slide-panel-header">
                <div class="min-w-0">
                    <h3 x-text="activeEnrollment.course_title"></h3>
                </div>
                <button type="button"
                        @click="closePanel()"
                        class="shrink-0 p-1.5 -m-1.5 rounded-lg text-text-muted hover:bg-surface-alt hover:text-text transition-colors">
                    <?php echo stridence_icon('x', 'w-5 h-5'); ?>
                </button>
            </div>

            <!-- Body -->
            <div class="slide-panel-body">

                <!-- Type badge + progress -->
                <div class="flex items-center justify-between gap-3">
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-medium"
                          :class="activeEnrollment.type === 'edition'
                              ? 'bg-primary/10 text-primary'
                              : 'bg-accent/10 text-accent'">
                        <span x-text="activeEnrollment.type === 'edition' ? '<?php esc_attr_e('Klassikaal', 'stridence'); ?>' : (activeEnrollment.format_label || '<?php esc_attr_e('Online', 'stridence'); ?>')"></span>
                    </span>

                    <!-- Progress percentage -->
                    <template x-if="activeEnrollment.type === 'edition' && activeEnrollment.progress && activeEnrollment.progress.percentage > 0">
                        <span class="text-sm font-semibold text-primary"
                              x-text="Math.round(activeEnrollment.progress.percentage) + '%'"></span>
                    </template>
                    <template x-if="activeEnrollment.type === 'online' && activeEnrollment.progress > 0">
                        <span class="text-sm font-semibold text-primary"
                              x-text="activeEnrollment.progress + '%'"></span>
                    </template>
                </div>

                <!-- Progress bar (edition) -->
                <template x-if="activeEnrollment.type === 'edition' && activeEnrollment.progress && activeEnrollment.progress.percentage > 0">
                    <div>
                        <div class="w-full h-2 rounded-full bg-surface-alt overflow-hidden">
                            <div class="h-full rounded-full bg-primary transition-all duration-500"
                                 :style="'width: ' + Math.round(activeEnrollment.progress.percentage) + '%'"></div>
                        </div>
                        <p class="text-xs text-text-muted mt-1"
                           x-text="activeEnrollment.progress.attended + ' / ' + activeEnrollment.progress.required + ' <?php esc_attr_e('sessies bijgewoond', 'stridence'); ?>'"></p>
                    </div>
                </template>

                <!-- Progress bar (online) -->
                <template x-if="activeEnrollment.type === 'online' && activeEnrollment.progress > 0">
                    <div>
                        <div class="w-full h-2 rounded-full bg-surface-alt overflow-hidden">
                            <div class="h-full rounded-full bg-primary transition-all duration-500"
                                 :style="'width: ' + activeEnrollment.progress + '%'"></div>
                        </div>
                        <p class="text-xs text-text-muted mt-1"
                           x-text="activeEnrollment.completed_lessons + ' / ' + activeEnrollment.total_lessons + ' <?php esc_attr_e('lessen voltooid', 'stridence'); ?>'"></p>
                    </div>
                </template>

                <!-- Sessions list (edition type) -->
                <template x-if="activeEnrollment.type === 'edition' && activeEnrollment.sessions && activeEnrollment.sessions.length > 0">
                    <div>
                        <h4 class="text-sm font-semibold text-text-muted uppercase tracking-wide mb-3">
                            <?php esc_html_e('Sessies', 'stridence'); ?>
                        </h4>
                        <div class="space-y-2">
                            <template x-for="session in activeEnrollment.sessions" :key="session.id || session.date">
                                <div class="flex items-center gap-3 py-2 px-3 rounded-lg bg-surface-alt/50"
                                     :class="{ 'opacity-40 line-through': activeEnrollment.sessions.some(s => s.selected) && !session.selected && session.slot }">
                                    <!-- Attendance dot -->
                                    <span class="w-2.5 h-2.5 rounded-full shrink-0"
                                          :class="{
                                              'bg-success': session.attendance === 'present',
                                              'bg-error': session.attendance === 'absent',
                                              'bg-border': !session.attendance || session.attendance === 'pending'
                                          }"></span>
                                    <!-- Date + time -->
                                    <div class="flex-1 min-w-0">
                                        <span class="text-sm font-medium text-text" x-text="session.date_formatted || session.date"></span>
                                        <template x-if="session.start_time">
                                            <span class="text-xs text-text-muted ml-1"
                                                  x-text="session.start_time + (session.end_time ? ' - ' + session.end_time : '')"></span>
                                        </template>
                                    </div>
                                    <!-- Status label -->
                                    <span class="text-xs shrink-0"
                                          :class="{
                                              'text-success': session.attendance === 'present',
                                              'text-error': session.attendance === 'absent',
                                              'text-text-muted': !session.attendance || session.attendance === 'pending'
                                          }"
                                          x-text="session.attendance === 'present' ? '<?php esc_attr_e('Aanwezig', 'stridence'); ?>'
                                              : session.attendance === 'absent' ? '<?php esc_attr_e('Afwezig', 'stridence'); ?>'
                                              : '<?php esc_attr_e('Gepland', 'stridence'); ?>'"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <!-- Online info -->
                <template x-if="activeEnrollment.type === 'online'">
                    <div>
                        <h4 class="text-sm font-semibold text-text-muted uppercase tracking-wide mb-3">
                            <?php esc_html_e('Voortgang', 'stridence'); ?>
                        </h4>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-text-muted"><?php esc_html_e('Lessen', 'stridence'); ?></dt>
                                <dd class="font-medium" x-text="activeEnrollment.completed_lessons + ' / ' + activeEnrollment.total_lessons"></dd>
                            </div>
                            <template x-if="activeEnrollment.days_remaining !== null && activeEnrollment.days_remaining !== undefined">
                                <div class="flex justify-between">
                                    <dt class="text-text-muted"><?php esc_html_e('Dagen resterend', 'stridence'); ?></dt>
                                    <dd class="font-medium" x-text="activeEnrollment.days_remaining"></dd>
                                </div>
                            </template>
                        </dl>
                    </div>
                </template>

                <!-- Edition info (date + venue) -->
                <template x-if="activeEnrollment.type === 'edition'">
                    <div>
                        <h4 class="text-sm font-semibold text-text-muted uppercase tracking-wide mb-3">
                            <?php esc_html_e('Details', 'stridence'); ?>
                        </h4>
                        <dl class="space-y-2 text-sm">
                            <template x-if="activeEnrollment.start_date">
                                <div class="flex justify-between">
                                    <dt class="text-text-muted flex items-center gap-1.5">
                                        <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                        <?php esc_html_e('Startdatum', 'stridence'); ?>
                                    </dt>
                                    <dd class="font-medium" x-text="activeEnrollment.start_date_formatted || activeEnrollment.start_date"></dd>
                                </div>
                            </template>
                            <template x-if="activeEnrollment.venue">
                                <div class="flex justify-between">
                                    <dt class="text-text-muted flex items-center gap-1.5">
                                        <?php echo stridence_icon('map-pin', 'w-4 h-4'); ?>
                                        <?php esc_html_e('Locatie', 'stridence'); ?>
                                    </dt>
                                    <dd class="font-medium" x-text="activeEnrollment.venue"></dd>
                                </div>
                            </template>
                        </dl>
                    </div>
                </template>

                <!-- Quick action links -->
                <div class="space-y-2">
                    <template x-if="activeEnrollment.type === 'online' && activeEnrollment.course_url">
                        <a :href="activeEnrollment.course_url"
                           class="action-item action-border-blue">
                            <span class="flex-1 text-sm text-text"><?php esc_html_e('Ga verder met cursus', 'stridence'); ?></span>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-muted shrink-0'); ?>
                        </a>
                    </template>
                    <template x-if="activeEnrollment.complete_url">
                        <a :href="activeEnrollment.complete_url"
                           class="action-item action-border-amber">
                            <span class="flex-1 text-sm text-text"><?php esc_html_e('Taken bekijken', 'stridence'); ?></span>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 text-text-muted shrink-0'); ?>
                        </a>
                    </template>
                </div>

            </div>

            <!-- Footer -->
            <div class="slide-panel-footer">
                <template x-if="activeEnrollment.type === 'online' && activeEnrollment.course_url">
                    <a :href="activeEnrollment.course_url"
                       class="btn-primary w-full justify-center">
                        <?php echo stridence_icon('trending-up', 'w-4 h-4 mr-1'); ?>
                        <?php esc_html_e('Ga verder', 'stridence'); ?>
                    </a>
                </template>
                <template x-if="activeEnrollment.type === 'edition'">
                    <a href="<?php echo esc_url(add_query_arg('tab', 'inschrijvingen', $permalink)); ?>"
                       class="btn-secondary w-full justify-center">
                        <?php echo stridence_icon('list', 'w-4 h-4 mr-1'); ?>
                        <?php esc_html_e('Alle details', 'stridence'); ?>
                    </a>
                </template>
            </div>

        </div>
    </template>
</div>
