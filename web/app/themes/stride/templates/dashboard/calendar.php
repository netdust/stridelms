<?php
/**
 * My Calendar/Agenda Template
 *
 * User's upcoming course dates with iCal download options.
 *
 * @var int $user_id
 * @var array $upcoming_dates
 * @var DashboardService $dashboard_service
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<div class="stride-dashboard">
    <div class="uk-container">
        <!-- Page Header -->
        <div class="stride-dashboard-header uk-margin-medium-bottom">
            <h1 class="uk-h2 uk-margin-remove-bottom">
                <?php esc_html_e('Mijn Agenda', 'stride'); ?>
            </h1>
            <p class="uk-text-muted uk-margin-small-top">
                <?php esc_html_e('Bekijk je komende cursussen en sessies.', 'stride'); ?>
            </p>
        </div>

        <?php if (!empty($upcoming_dates)): ?>
            <!-- Upcoming Dates List -->
            <div class="stride-card">
                <ul class="stride-upcoming-list">
                    <?php
                    $currentMonth = '';
                    foreach ($upcoming_dates as $date):
                        $dateMonth = date_i18n('F Y', $date['timestamp']);

                        // Show month header if new month
                        if ($dateMonth !== $currentMonth):
                            if ($currentMonth !== ''): ?>
                                </ul>
                                <h3 class="uk-h5 uk-text-uppercase uk-text-muted uk-margin-medium-top uk-margin-small-bottom">
                                    <?php echo esc_html($dateMonth); ?>
                                </h3>
                                <ul class="stride-upcoming-list">
                            <?php else: ?>
                                <h3 class="uk-h5 uk-text-uppercase uk-text-muted uk-margin-small-bottom uk-margin-remove-top">
                                    <?php echo esc_html($dateMonth); ?>
                                </h3>
                            <?php endif;
                            $currentMonth = $dateMonth;
                        endif;
                        ?>

                        <li class="stride-upcoming-item">
                            <div class="stride-upcoming-date">
                                <div class="stride-upcoming-day"><?php echo esc_html($date['day']); ?></div>
                                <div class="stride-upcoming-month"><?php echo esc_html($date['month']); ?></div>
                            </div>

                            <div class="stride-upcoming-info uk-flex-1">
                                <h4 class="stride-upcoming-title">
                                    <a href="<?php echo esc_url(get_permalink($date['course_id'])); ?>">
                                        <?php echo esc_html($date['course_title']); ?>
                                    </a>
                                </h4>

                                <div class="uk-flex uk-flex-wrap uk-flex-middle" style="gap: 16px;">
                                    <?php if ($date['time'] && $date['time'] !== '00:00'): ?>
                                        <span class="uk-text-muted uk-text-small">
                                            <span uk-icon="icon: clock; ratio: 0.7"></span>
                                            <?php echo esc_html($date['time']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($date['location']): ?>
                                        <span class="uk-text-muted uk-text-small">
                                            <span uk-icon="icon: location; ratio: 0.7"></span>
                                            <?php echo esc_html($date['location']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="stride-upcoming-actions uk-flex" style="gap: 8px;">
                                <!-- Days until -->
                                <?php
                                $daysUntil = floor(($date['timestamp'] - time()) / 86400);
                                if ($daysUntil === 0): ?>
                                    <span class="stride-badge stride-badge-in-progress">
                                        <?php esc_html_e('Vandaag', 'stride'); ?>
                                    </span>
                                <?php elseif ($daysUntil === 1): ?>
                                    <span class="stride-badge stride-badge-in-progress">
                                        <?php esc_html_e('Morgen', 'stride'); ?>
                                    </span>
                                <?php elseif ($daysUntil <= 7): ?>
                                    <span class="uk-text-warning uk-text-small">
                                        <?php printf(esc_html__('Over %d dagen', 'stride'), $daysUntil); ?>
                                    </span>
                                <?php endif; ?>

                                <!-- iCal download -->
                                <a href="#"
                                   data-ical-download
                                   data-course-id="<?php echo esc_attr($date['course_id']); ?>"
                                   <?php if (!empty($date['edition_id'])): ?>data-edition-id="<?php echo esc_attr($date['edition_id']); ?>"<?php endif; ?>
                                   <?php if (!empty($date['session_id'])): ?>data-session-id="<?php echo esc_attr($date['session_id']); ?>"<?php endif; ?>
                                   class="uk-icon-link"
                                   uk-icon="icon: download"
                                   title="<?php esc_attr_e('Toevoegen aan agenda', 'stride'); ?>">
                                </a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Export All -->
            <div class="uk-margin-medium-top uk-text-center">
                <a href="#" class="uk-button uk-button-default" id="export-all-calendar">
                    <span uk-icon="icon: calendar"></span>
                    <?php esc_html_e('Exporteer volledige agenda', 'stride'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="stride-card">
                <div class="stride-empty-state">
                    <span class="stride-empty-state-icon" uk-icon="icon: calendar; ratio: 3"></span>
                    <h3 class="stride-empty-state-title">
                        <?php esc_html_e('Geen komende afspraken', 'stride'); ?>
                    </h3>
                    <p class="stride-empty-state-text">
                        <?php esc_html_e('Je hebt momenteel geen geplande cursussen of sessies.', 'stride'); ?>
                    </p>
                    <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary">
                        <?php esc_html_e('Bekijk cursusaanbod', 'stride'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Calendar Tips -->
        <div class="stride-card uk-margin-medium-top">
            <div class="stride-card-header">
                <h2 class="stride-card-title">
                    <span uk-icon="icon: info"></span>
                    <?php esc_html_e('Tips', 'stride'); ?>
                </h2>
            </div>

            <ul class="uk-list uk-list-disc uk-text-muted">
                <li><?php esc_html_e('Klik op het download icoon om een cursus toe te voegen aan je persoonlijke agenda (Outlook, Google Calendar, Apple Calendar).', 'stride'); ?></li>
                <li><?php esc_html_e('Je ontvangt ook automatisch een e-mail herinnering enkele dagen voor elke cursus.', 'stride'); ?></li>
            </ul>
        </div>

        <!-- Back to Dashboard -->
        <div class="uk-margin-medium-top">
            <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="uk-link-muted">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Terug naar dashboard', 'stride'); ?>
            </a>
        </div>
    </div>
</div>
