<?php
/**
 * My Courses Template
 *
 * User's enrolled courses with filtering options.
 *
 * @var int $user_id
 * @var array $courses
 * @var string $current_filter
 * @var array $filters
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
            <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap" uk-grid>
                <div>
                    <h1 class="uk-h2 uk-margin-remove-bottom">
                        <?php esc_html_e('Mijn Cursussen', 'stride'); ?>
                    </h1>
                    <p class="uk-text-muted uk-margin-small-top">
                        <?php printf(
                            esc_html(_n('%d cursus', '%d cursussen', count($courses), 'stride')),
                            count($courses)
                        ); ?>
                    </p>
                </div>
                <div>
                    <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary uk-button-small">
                        <span uk-icon="icon: plus"></span>
                        <?php esc_html_e('Nieuwe cursus', 'stride'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="stride-filter-tabs uk-margin-medium-bottom">
            <?php foreach ($filters as $key => $label): ?>
                <a href="?filter=<?php echo esc_attr($key); ?>"
                   class="stride-filter-tab <?php echo $current_filter === $key ? 'active' : ''; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Courses Grid -->
        <?php if (!empty($courses)): ?>
            <div class="stride-courses-grid" uk-grid="masonry: false" data-uk-grid>
                <?php foreach ($courses as $course): ?>
                    <div class="uk-width-1-3@m uk-width-1-2@s" data-course-item>
                        <?php
                        // Include course card partial
                        include get_stylesheet_directory() . '/templates/partials/course-card.php';
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="stride-empty-state">
                <span class="stride-empty-state-icon" uk-icon="icon: album; ratio: 3"></span>
                <h3 class="stride-empty-state-title">
                    <?php if ($current_filter !== 'all'): ?>
                        <?php esc_html_e('Geen cursussen gevonden', 'stride'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Je hebt nog geen cursussen', 'stride'); ?>
                    <?php endif; ?>
                </h3>
                <p class="stride-empty-state-text">
                    <?php if ($current_filter !== 'all'): ?>
                        <?php esc_html_e('Probeer een andere filter of bekijk al je cursussen.', 'stride'); ?>
                    <?php else: ?>
                        <?php esc_html_e('Schrijf je in voor een cursus om te beginnen.', 'stride'); ?>
                    <?php endif; ?>
                </p>
                <?php if ($current_filter !== 'all'): ?>
                    <a href="?filter=all" class="uk-button uk-button-default uk-margin-small-right">
                        <?php esc_html_e('Alle cursussen', 'stride'); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary">
                    <?php esc_html_e('Bekijk cursusaanbod', 'stride'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
