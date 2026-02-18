<?php
/**
 * My Trajectories Template
 *
 * User's trajectories listing with progress.
 *
 * @var int $user_id
 * @var array $trajectories
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
                <?php esc_html_e('Mijn Trajecten', 'stride'); ?>
            </h1>
            <p class="uk-text-muted uk-margin-small-top">
                <?php esc_html_e('Volg je leertrajecten en bekijk je voortgang.', 'stride'); ?>
            </p>
        </div>

        <?php if (!empty($trajectories)): ?>
            <div uk-grid class="uk-child-width-1-2@m">
                <?php foreach ($trajectories as $trajectory): ?>
                    <div>
                        <div class="stride-card">
                            <div class="stride-card-header">
                                <h3 class="stride-card-title uk-margin-remove">
                                    <?php echo esc_html($trajectory['title']); ?>
                                </h3>
                                <?php if (!empty($trajectory['mode']) && $trajectory['mode'] === 'cohort'): ?>
                                    <span class="stride-badge stride-badge-info uk-text-small">
                                        <?php esc_html_e('Cohort', 'stride'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Progress -->
                            <div class="stride-progress-wrapper uk-margin-small-bottom">
                                <div class="stride-progress-label">
                                    <span><?php esc_html_e('Voortgang', 'stride'); ?></span>
                                    <span><?php echo esc_html($trajectory['progress']); ?>%</span>
                                </div>
                                <div class="stride-progress-bar">
                                    <div class="stride-progress-fill <?php echo $trajectory['progress'] === 100 ? 'completed' : ''; ?>"
                                         style="width: <?php echo esc_attr($trajectory['progress']); ?>%;"></div>
                                </div>
                            </div>

                            <!-- Stats -->
                            <div class="uk-margin-small-bottom">
                                <span class="uk-text-muted">
                                    <span uk-icon="icon: check; ratio: 0.8"></span>
                                    <?php printf(
                                        esc_html__('%d van %d modules afgerond', 'stride'),
                                        $trajectory['completed_modules'],
                                        $trajectory['total_modules']
                                    ); ?>
                                </span>
                            </div>

                            <!-- Current module preview -->
                            <?php if ($trajectory['current_module']): ?>
                                <div class="uk-margin-bottom">
                                    <span class="uk-text-small uk-text-uppercase uk-text-muted">
                                        <?php esc_html_e('Volgende stap', 'stride'); ?>
                                    </span>
                                    <p class="uk-margin-remove-top uk-margin-small-bottom">
                                        <strong><?php echo esc_html($trajectory['current_module']['title']); ?></strong>
                                    </p>
                                    <?php if ($trajectory['current_module']['next_date']): ?>
                                        <span class="uk-text-muted uk-text-small">
                                            <span uk-icon="icon: calendar; ratio: 0.8"></span>
                                            <?php echo esc_html(date_i18n('j F Y', $trajectory['current_module']['next_date'])); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Action -->
                            <div class="uk-margin-top">
                                <a href="<?php echo esc_url(home_url('/mijn-account/traject/?trajectory=' . $trajectory['id'])); ?>"
                                   class="uk-button uk-button-primary">
                                    <?php esc_html_e('Bekijk traject', 'stride'); ?>
                                    <span uk-icon="icon: arrow-right; ratio: 0.8"></span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="stride-card">
                <div class="stride-empty-state">
                    <span class="stride-empty-state-icon" uk-icon="icon: git-branch; ratio: 3"></span>
                    <h3 class="stride-empty-state-title">
                        <?php esc_html_e('Geen trajecten', 'stride'); ?>
                    </h3>
                    <p class="stride-empty-state-text">
                        <?php esc_html_e('Je bent nog niet ingeschreven voor een leertraject.', 'stride'); ?>
                    </p>
                    <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="uk-button uk-button-primary">
                        <?php esc_html_e('Bekijk trajecten', 'stride'); ?>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <!-- Back to Dashboard -->
        <div class="uk-margin-medium-top">
            <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="uk-link-muted">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Terug naar dashboard', 'stride'); ?>
            </a>
        </div>
    </div>
</div>
