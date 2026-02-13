<?php
/**
 * Trajectory Catalog/Archive Template
 *
 * Public trajectory listing.
 *
 * @var array $trajectories
 * @var int $total_trajectories
 * @var string $current_mode
 * @var string $current_search
 * @var bool $show_filters
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<div class="stride-trajectory-catalog">
    <div class="uk-container">
        <!-- Page Header -->
        <div class="stride-dashboard-header uk-margin-medium-bottom">
            <h1 class="uk-h2 uk-margin-remove-bottom">
                <?php esc_html_e('Leertrajecten', 'stride'); ?>
            </h1>
            <p class="uk-text-muted uk-margin-small-top">
                <?php printf(
                    esc_html(_n('%d traject beschikbaar', '%d trajecten beschikbaar', $total_trajectories, 'stride')),
                    $total_trajectories
                ); ?>
            </p>
        </div>

        <?php if ($show_filters): ?>
            <!-- Filters -->
            <div class="stride-card uk-margin-medium-bottom">
                <form method="get" action="" class="uk-grid-small" uk-grid>
                    <!-- Search -->
                    <div class="uk-width-1-2@m">
                        <div class="uk-inline uk-width-1-1">
                            <span class="uk-form-icon" uk-icon="icon: search"></span>
                            <input type="text" name="search" class="uk-input"
                                   placeholder="<?php esc_attr_e('Zoek trajecten...', 'stride'); ?>"
                                   value="<?php echo esc_attr($current_search); ?>">
                        </div>
                    </div>

                    <!-- Mode Filter -->
                    <div class="uk-width-1-4@m">
                        <select name="mode" class="uk-select">
                            <option value=""><?php esc_html_e('Alle types', 'stride'); ?></option>
                            <option value="self_paced" <?php selected($current_mode, 'self_paced'); ?>>
                                <?php esc_html_e('Zelfstandig tempo', 'stride'); ?>
                            </option>
                            <option value="cohort" <?php selected($current_mode, 'cohort'); ?>>
                                <?php esc_html_e('Cohort', 'stride'); ?>
                            </option>
                        </select>
                    </div>

                    <!-- Submit -->
                    <div class="uk-width-auto@m">
                        <button type="submit" class="uk-button uk-button-primary">
                            <span uk-icon="icon: search"></span>
                            <?php esc_html_e('Zoeken', 'stride'); ?>
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Trajectories Grid -->
        <?php if (!empty($trajectories)): ?>
            <div uk-grid class="uk-child-width-1-2@s uk-child-width-1-3@m uk-grid-match">
                <?php foreach ($trajectories as $trajectory): ?>
                    <?php
                    $mode = $trajectory['mode'] ?? 'self_paced';
                    $isCohort = $mode === 'cohort';
                    $thumbnail = $trajectory['thumbnail'] ?? get_stylesheet_directory_uri() . '/assets/images/trajectory-placeholder.jpg';
                    $courseCount = count($trajectory['courses'] ?? []);
                    ?>
                    <div>
                        <div class="stride-course-card">
                            <!-- Trajectory Image -->
                            <div class="stride-course-card-image" style="background-image: url('<?php echo esc_url($thumbnail); ?>'); background-color: var(--stride-secondary);">
                                <span class="stride-badge <?php echo $isCohort ? 'stride-badge-info' : 'stride-badge-in-person'; ?>">
                                    <?php echo $isCohort ? esc_html__('Cohort', 'stride') : esc_html__('Zelfstandig', 'stride'); ?>
                                </span>
                            </div>

                            <!-- Trajectory Body -->
                            <div class="stride-course-card-body">
                                <h3 class="stride-course-card-title">
                                    <a href="<?php echo esc_url(get_permalink($trajectory['id'])); ?>">
                                        <?php echo esc_html($trajectory['title']); ?>
                                    </a>
                                </h3>

                                <div class="stride-course-card-meta">
                                    <span uk-icon="icon: git-branch; ratio: 0.8"></span>
                                    <?php printf(
                                        esc_html(_n('%d module', '%d modules', $courseCount, 'stride')),
                                        $courseCount
                                    ); ?>
                                </div>

                                <?php if (!empty($trajectory['description'])): ?>
                                    <p class="uk-text-small uk-text-muted uk-margin-small-bottom">
                                        <?php echo esc_html(wp_trim_words(strip_tags($trajectory['description']), 20)); ?>
                                    </p>
                                <?php endif; ?>

                                <?php if ($isCohort && !empty($trajectory['enrollment_deadline'])): ?>
                                    <div class="uk-text-small uk-text-warning uk-margin-small-bottom">
                                        <span uk-icon="icon: clock; ratio: 0.8"></span>
                                        <?php printf(
                                            esc_html__('Inschrijven voor %s', 'stride'),
                                            date_i18n('j F Y', strtotime($trajectory['enrollment_deadline']))
                                        ); ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Card Footer -->
                                <div class="stride-course-card-footer">
                                    <div class="uk-text-small uk-text-muted">
                                        <?php if ($isCohort): ?>
                                            <span uk-icon="icon: users; ratio: 0.8"></span>
                                            <?php esc_html_e('Groepstraject', 'stride'); ?>
                                        <?php else: ?>
                                            <span uk-icon="icon: user; ratio: 0.8"></span>
                                            <?php esc_html_e('Op eigen tempo', 'stride'); ?>
                                        <?php endif; ?>
                                    </div>

                                    <a href="<?php echo esc_url(get_permalink($trajectory['id'])); ?>"
                                       class="uk-button uk-button-primary uk-button-small">
                                        <?php esc_html_e('Meer info', 'stride'); ?>
                                    </a>
                                </div>
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
                        <?php esc_html_e('Geen trajecten gevonden', 'stride'); ?>
                    </h3>
                    <p class="stride-empty-state-text">
                        <?php esc_html_e('Er zijn momenteel geen trajecten beschikbaar.', 'stride'); ?>
                    </p>
                    <?php if ($current_search || $current_mode): ?>
                        <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="uk-button uk-button-default">
                            <?php esc_html_e('Filters wissen', 'stride'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
