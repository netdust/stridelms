<?php
/**
 * Single Trajectory Journey Template
 *
 * Visual journey view of a trajectory with progress tracking.
 *
 * @var int $user_id
 * @var array $trajectory
 * @var DashboardService $dashboard_service
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<div class="stride-dashboard">
    <div class="uk-container">
        <!-- Breadcrumb -->
        <nav class="uk-margin-bottom">
            <ul class="uk-breadcrumb">
                <li><a href="<?php echo esc_url(home_url('/mijn-account/')); ?>"><?php esc_html_e('Dashboard', 'stride'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/mijn-account/trajecten/')); ?>"><?php esc_html_e('Trajecten', 'stride'); ?></a></li>
                <li><span><?php echo esc_html($trajectory['title']); ?></span></li>
            </ul>
        </nav>

        <!-- Page Header -->
        <div class="stride-dashboard-header uk-margin-medium-bottom">
            <div class="uk-flex uk-flex-middle" style="gap: 12px;">
                <h1 class="uk-h2 uk-margin-remove-bottom">
                    <?php echo esc_html($trajectory['title']); ?>
                </h1>
                <?php if (!empty($trajectory['mode']) && $trajectory['mode'] === 'cohort'): ?>
                    <span class="stride-badge stride-badge-info">
                        <?php esc_html_e('Cohort', 'stride'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Overall Progress -->
            <div class="uk-margin-top" style="max-width: 400px;">
                <div class="stride-progress-wrapper">
                    <div class="stride-progress-label">
                        <span>
                            <?php printf(
                                esc_html__('%d van %d modules afgerond', 'stride'),
                                $trajectory['completed_count'],
                                $trajectory['total_count']
                            ); ?>
                        </span>
                        <span><?php echo esc_html($trajectory['progress']); ?>%</span>
                    </div>
                    <div class="stride-progress-bar">
                        <div class="stride-progress-fill <?php echo $trajectory['progress'] === 100 ? 'completed' : ''; ?>"
                             style="width: <?php echo esc_attr($trajectory['progress']); ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Journey Visualization -->
        <div class="stride-card uk-margin-medium-bottom">
            <div class="stride-card-header">
                <h2 class="stride-card-title">
                    <span uk-icon="icon: git-branch"></span>
                    <?php esc_html_e('Jouw leerpad', 'stride'); ?>
                </h2>
            </div>

            <!-- Journey Steps (Desktop) -->
            <div class="stride-journey uk-visible@m">
                <div class="stride-journey-steps">
                    <?php foreach ($trajectory['mandatory_modules'] as $index => $module): ?>
                        <?php
                        $stepClass = match ($module['status']) {
                            'completed' => 'completed',
                            'current' => 'current',
                            default => 'locked',
                        };
                        ?>
                        <div class="stride-journey-step <?php echo esc_attr($stepClass); ?>">
                            <div class="stride-journey-icon">
                                <?php if ($module['status'] === 'completed'): ?>
                                    <span uk-icon="icon: check"></span>
                                <?php elseif ($module['status'] === 'current'): ?>
                                    <?php echo esc_html($index + 1); ?>
                                <?php else: ?>
                                    <span uk-icon="icon: lock; ratio: 0.8"></span>
                                <?php endif; ?>
                            </div>
                            <div class="stride-journey-label">
                                <?php if ($module['status'] !== 'locked'): ?>
                                    <a href="<?php echo esc_url($module['permalink']); ?>">
                                        <?php echo esc_html($module['title']); ?>
                                    </a>
                                <?php else: ?>
                                    <?php echo esc_html($module['title']); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Journey Steps (Mobile) - Vertical List -->
            <div class="stride-journey uk-hidden@m">
                <ul class="uk-list uk-list-divider">
                    <?php foreach ($trajectory['mandatory_modules'] as $index => $module): ?>
                        <?php
                        $stepClass = match ($module['status']) {
                            'completed' => 'completed',
                            'current' => 'current',
                            default => 'locked',
                        };
                        ?>
                        <li class="uk-flex uk-flex-middle">
                            <div class="stride-journey-icon uk-margin-right" style="flex-shrink: 0;">
                                <?php if ($module['status'] === 'completed'): ?>
                                    <span uk-icon="icon: check"></span>
                                <?php elseif ($module['status'] === 'current'): ?>
                                    <?php echo esc_html($index + 1); ?>
                                <?php else: ?>
                                    <span uk-icon="icon: lock; ratio: 0.8"></span>
                                <?php endif; ?>
                            </div>
                            <div class="uk-flex-1">
                                <?php if ($module['status'] !== 'locked'): ?>
                                    <a href="<?php echo esc_url($module['permalink']); ?>" class="<?php echo $module['status'] === 'current' ? 'uk-text-bold' : ''; ?>">
                                        <?php echo esc_html($module['title']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="uk-text-muted"><?php echo esc_html($module['title']); ?></span>
                                <?php endif; ?>

                                <?php if ($module['next_date'] && $module['status'] !== 'completed'): ?>
                                    <div class="uk-text-small uk-text-muted">
                                        <span uk-icon="icon: calendar; ratio: 0.7"></span>
                                        <?php echo esc_html(date_i18n('j F Y', $module['next_date'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($module['status'] === 'completed'): ?>
                                <span class="stride-badge stride-badge-completed"><?php esc_html_e('Afgerond', 'stride'); ?></span>
                            <?php elseif ($module['status'] === 'current'): ?>
                                <span class="stride-badge stride-badge-in-progress"><?php esc_html_e('Actief', 'stride'); ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <!-- Next Session Card -->
        <?php if ($trajectory['next_session']): ?>
            <div class="stride-card uk-margin-medium-bottom">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <span uk-icon="icon: future"></span>
                        <?php esc_html_e('Volgende stap', 'stride'); ?>
                    </h2>
                </div>

                <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap" uk-grid>
                    <div>
                        <h3 class="uk-h4 uk-margin-remove-bottom">
                            <?php echo esc_html($trajectory['next_session']['title']); ?>
                        </h3>
                        <?php if ($trajectory['next_session']['next_date']): ?>
                            <p class="uk-margin-small-top uk-margin-remove-bottom">
                                <span uk-icon="icon: calendar"></span>
                                <strong><?php echo esc_html(date_i18n('l j F Y', $trajectory['next_session']['next_date'])); ?></strong>
                            </p>
                        <?php endif; ?>
                        <?php if ($trajectory['next_session']['location']): ?>
                            <p class="uk-text-muted uk-margin-remove">
                                <span uk-icon="icon: location; ratio: 0.8"></span>
                                <?php echo esc_html($trajectory['next_session']['location']); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($trajectory['next_session']['next_date']): ?>
                            <a href="#" data-ical-download="<?php echo esc_attr($trajectory['next_session']['id']); ?>"
                               class="uk-button uk-button-default uk-margin-small-right">
                                <span uk-icon="icon: calendar"></span>
                                <?php esc_html_e('Toevoegen aan agenda', 'stride'); ?>
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url($trajectory['next_session']['permalink']); ?>"
                           class="uk-button uk-button-primary">
                            <?php esc_html_e('Bekijk details', 'stride'); ?>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Elective Modules -->
        <?php if (!empty($trajectory['elective_modules'])): ?>
            <div class="stride-card">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <span uk-icon="icon: list"></span>
                        <?php esc_html_e('Keuzemodules', 'stride'); ?>
                    </h2>
                </div>

                <div class="stride-electives">
                    <p class="stride-electives-header">
                        <?php esc_html_e('Selecteer keuzemodules om je traject te voltooien:', 'stride'); ?>
                    </p>

                    <?php foreach ($trajectory['elective_modules'] as $module): ?>
                        <div class="stride-elective-item <?php echo $module['is_completed'] ? 'completed' : ''; ?>">
                            <div class="stride-elective-checkbox">
                                <?php if ($module['is_completed']): ?>
                                    <span uk-icon="icon: check; ratio: 0.8"></span>
                                <?php endif; ?>
                            </div>
                            <div class="uk-flex-1">
                                <a href="<?php echo esc_url($module['permalink']); ?>">
                                    <?php echo esc_html($module['title']); ?>
                                </a>
                            </div>
                            <?php if ($module['is_completed']): ?>
                                <span class="stride-badge stride-badge-completed"><?php esc_html_e('Afgerond', 'stride'); ?></span>
                            <?php elseif ($module['next_date']): ?>
                                <span class="uk-text-muted uk-text-small">
                                    <?php echo esc_html(date_i18n('j M Y', $module['next_date'])); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Description -->
        <?php if (!empty($trajectory['description'])): ?>
            <div class="stride-card uk-margin-medium-top">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <span uk-icon="icon: info"></span>
                        <?php esc_html_e('Over dit traject', 'stride'); ?>
                    </h2>
                </div>
                <div class="uk-text-small">
                    <?php echo wp_kses_post($trajectory['description']); ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Back to Trajectories -->
        <div class="uk-margin-medium-top">
            <a href="<?php echo esc_url(home_url('/mijn-account/trajecten/')); ?>" class="uk-link-muted">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Terug naar trajecten', 'stride'); ?>
            </a>
        </div>
    </div>
</div>
