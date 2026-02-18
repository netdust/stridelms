<?php
/**
 * Dashboard Home Template
 *
 * Main dashboard overview with upcoming courses, quick links, and recent activity.
 *
 * @var WP_User $user
 * @var int $user_id
 * @var string $first_name
 * @var array $upcoming_dates
 * @var array $recent_activity
 * @var array $stats
 * @var DashboardService $dashboard_service
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<div class="stride-dashboard">
    <div class="uk-container">
        <!-- Welcome Header -->
        <div class="stride-dashboard-header uk-margin-medium-bottom">
            <h1 class="uk-h2 uk-margin-remove-bottom">
                <?php printf(esc_html__('Welkom, %s!', 'stride'), esc_html($first_name)); ?>
            </h1>
            <p class="uk-text-muted uk-margin-small-top">
                <?php esc_html_e('Bekijk je cursussen, trajecten en voortgang.', 'stride'); ?>
            </p>
        </div>

        <div uk-grid class="uk-grid-medium">
            <!-- Main Content Column -->
            <div class="uk-width-2-3@m">
                <!-- Upcoming Courses Section -->
                <div class="stride-card uk-margin-bottom">
                    <div class="stride-card-header">
                        <h2 class="stride-card-title">
                            <span uk-icon="icon: calendar"></span>
                            <?php esc_html_e('Komende cursussen', 'stride'); ?>
                        </h2>
                        <a href="<?php echo esc_url(home_url('/mijn-account/agenda/')); ?>" class="uk-link-muted uk-text-small">
                            <?php esc_html_e('Bekijk alles', 'stride'); ?>
                            <span uk-icon="icon: chevron-right; ratio: 0.8"></span>
                        </a>
                    </div>

                    <?php if (!empty($upcoming_dates)): ?>
                        <ul class="stride-upcoming-list">
                            <?php foreach ($upcoming_dates as $date): ?>
                                <li class="stride-upcoming-item">
                                    <div class="stride-upcoming-date">
                                        <div class="stride-upcoming-day"><?php echo esc_html($date['day']); ?></div>
                                        <div class="stride-upcoming-month"><?php echo esc_html($date['month']); ?></div>
                                    </div>
                                    <div class="stride-upcoming-info">
                                        <h4 class="stride-upcoming-title">
                                            <a href="<?php echo esc_url(get_permalink($date['course_id'])); ?>">
                                                <?php echo esc_html($date['course_title']); ?>
                                            </a>
                                        </h4>
                                        <?php if ($date['location']): ?>
                                            <p class="stride-upcoming-location">
                                                <span uk-icon="icon: location; ratio: 0.8"></span>
                                                <?php echo esc_html($date['location']); ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stride-upcoming-actions">
                                        <?php if (!empty($date['time'])): ?>
                                            <span class="stride-upcoming-time uk-text-muted uk-text-small uk-margin-small-right">
                                                <?php echo esc_html($date['time']); ?>
                                            </span>
                                        <?php endif; ?>
                                        <a href="#"
                                           data-ical-download
                                           data-course-id="<?php echo esc_attr($date['course_id']); ?>"
                                           <?php if (!empty($date['edition_id'])): ?>data-edition-id="<?php echo esc_attr($date['edition_id']); ?>"<?php endif; ?>
                                           <?php if (!empty($date['session_id'])): ?>data-session-id="<?php echo esc_attr($date['session_id']); ?>"<?php endif; ?>
                                           class="uk-icon-link" uk-icon="icon: download" title="<?php esc_attr_e('Toevoegen aan agenda', 'stride'); ?>"></a>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="stride-empty-state">
                            <span class="stride-empty-state-icon" uk-icon="icon: calendar; ratio: 2"></span>
                            <p class="stride-empty-state-title"><?php esc_html_e('Geen komende cursussen', 'stride'); ?></p>
                            <p class="stride-empty-state-text"><?php esc_html_e('Je hebt momenteel geen geplande cursussen.', 'stride'); ?></p>
                            <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary uk-button-small">
                                <?php esc_html_e('Bekijk cursusaanbod', 'stride'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Activity Section -->
                <div class="stride-card">
                    <div class="stride-card-header">
                        <h2 class="stride-card-title">
                            <span uk-icon="icon: clock"></span>
                            <?php esc_html_e('Recente activiteit', 'stride'); ?>
                        </h2>
                    </div>

                    <?php if (!empty($recent_activity)): ?>
                        <ul class="stride-activity-list">
                            <?php foreach ($recent_activity as $activity): ?>
                                <li class="stride-activity-item">
                                    <div class="stride-activity-icon">
                                        <span uk-icon="icon: <?php echo esc_attr($activity['icon']); ?>"></span>
                                    </div>
                                    <div class="stride-activity-content">
                                        <p class="stride-activity-title"><?php echo esc_html($activity['message']); ?></p>
                                        <span class="stride-activity-time"><?php echo esc_html($activity['time_ago']); ?></span>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="stride-empty-state">
                            <span class="stride-empty-state-icon" uk-icon="icon: clock; ratio: 2"></span>
                            <p class="stride-empty-state-title"><?php esc_html_e('Geen recente activiteit', 'stride'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sidebar Column -->
            <div class="uk-width-1-3@m">
                <!-- Quick Links -->
                <div class="stride-card uk-margin-bottom">
                    <div class="stride-card-header">
                        <h2 class="stride-card-title">
                            <span uk-icon="icon: grid"></span>
                            <?php esc_html_e('Snelle links', 'stride'); ?>
                        </h2>
                    </div>

                    <div class="stride-quick-links">
                        <a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>" class="stride-quick-link">
                            <span class="stride-quick-link-icon" uk-icon="icon: album; ratio: 1.5"></span>
                            <span class="stride-quick-link-label"><?php esc_html_e('Mijn Cursussen', 'stride'); ?></span>
                        </a>

                        <a href="<?php echo esc_url(home_url('/mijn-account/trajecten/')); ?>" class="stride-quick-link">
                            <span class="stride-quick-link-icon" uk-icon="icon: git-branch; ratio: 1.5"></span>
                            <span class="stride-quick-link-label"><?php esc_html_e('Mijn Trajecten', 'stride'); ?></span>
                        </a>

                        <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>" class="stride-quick-link">
                            <span class="stride-quick-link-icon" uk-icon="icon: file-text; ratio: 1.5"></span>
                            <span class="stride-quick-link-label"><?php esc_html_e('Mijn Offertes', 'stride'); ?></span>
                        </a>

                        <a href="<?php echo esc_url(home_url('/mijn-account/profiel/')); ?>" class="stride-quick-link">
                            <span class="stride-quick-link-icon" uk-icon="icon: user; ratio: 1.5"></span>
                            <span class="stride-quick-link-label"><?php esc_html_e('Profiel', 'stride'); ?></span>
                        </a>
                    </div>
                </div>

                <!-- Stats Card -->
                <div class="stride-card">
                    <div class="stride-card-header">
                        <h2 class="stride-card-title">
                            <span uk-icon="icon: info"></span>
                            <?php esc_html_e('Jouw statistieken', 'stride'); ?>
                        </h2>
                    </div>

                    <dl class="uk-description-list uk-description-list-divider">
                        <dt><?php esc_html_e('Totaal cursussen', 'stride'); ?></dt>
                        <dd><?php echo esc_html($stats['total_courses']); ?></dd>

                        <dt><?php esc_html_e('Afgerond', 'stride'); ?></dt>
                        <dd>
                            <span class="uk-text-success"><?php echo esc_html($stats['completed_courses']); ?></span>
                            <?php if ($stats['total_courses'] > 0): ?>
                                <span class="uk-text-muted uk-text-small">
                                    (<?php echo round(($stats['completed_courses'] / $stats['total_courses']) * 100); ?>%)
                                </span>
                            <?php endif; ?>
                        </dd>

                        <dt><?php esc_html_e('In uitvoering', 'stride'); ?></dt>
                        <dd class="uk-text-warning"><?php echo esc_html($stats['in_progress_courses']); ?></dd>

                        <?php if ($stats['total_trajectories'] > 0): ?>
                            <dt><?php esc_html_e('Trajecten', 'stride'); ?></dt>
                            <dd><?php echo esc_html($stats['total_trajectories']); ?></dd>
                        <?php endif; ?>

                        <?php if ($stats['pending_quotes'] > 0): ?>
                            <dt><?php esc_html_e('Openstaande offertes', 'stride'); ?></dt>
                            <dd class="uk-text-primary"><?php echo esc_html($stats['pending_quotes']); ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>
