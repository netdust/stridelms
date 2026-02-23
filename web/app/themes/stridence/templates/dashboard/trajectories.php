<?php
/**
 * My Trajectories Dashboard Page
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$current_page = 'trajectories';
$userId = get_current_user_id();

// Get user's enrolled trajectories
$trajectories = [];
// Integration with TrajectoryService would populate this

include get_stylesheet_directory() . '/templates/partials/dashboard-layout.php';
?>

<header class="str-dashboard__header">
    <h1 class="str-dashboard__title"><?php esc_html_e('Mijn trajecten', 'stridence'); ?></h1>
    <p class="str-dashboard__subtitle">
        <?php esc_html_e('Je ingeschreven leerpaden', 'stridence'); ?>
    </p>
</header>

<?php if (!empty($trajectories)): ?>
    <div class="str-trajectory-list">
        <?php foreach ($trajectories as $trajectory): ?>
            <div class="str-trajectory-list__item">
                <div class="str-trajectory-list__content">
                    <h3 class="str-trajectory-list__title">
                        <a href="<?php echo esc_url($trajectory['url']); ?>">
                            <?php echo esc_html($trajectory['title']); ?>
                        </a>
                    </h3>
                    <div class="str-trajectory-list__meta">
                        <?php printf(
                            esc_html__('%d van %d cursussen voltooid', 'stridence'),
                            $trajectory['completed'],
                            $trajectory['total']
                        ); ?>
                    </div>
                    <div class="str-progress str-progress--sm">
                        <div class="str-progress__bar" style="width: <?php echo esc_attr($trajectory['progress']); ?>%;"></div>
                    </div>
                </div>
                <a href="<?php echo esc_url($trajectory['url']); ?>" class="str-btn str-btn--primary str-btn--sm">
                    <?php esc_html_e('Bekijken', 'stridence'); ?>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="str-empty-state">
        <?php stridence_icon('gift', '', 48); ?>
        <h2><?php esc_html_e('Geen trajecten', 'stridence'); ?></h2>
        <p><?php esc_html_e('Je bent nog niet ingeschreven voor leertrajecten.', 'stridence'); ?></p>
        <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="str-btn str-btn--primary">
            <?php esc_html_e('Bekijk trajecten', 'stridence'); ?>
        </a>
    </div>
<?php endif; ?>

<?php
include get_stylesheet_directory() . '/templates/partials/dashboard-layout-close.php';
get_footer();
?>
