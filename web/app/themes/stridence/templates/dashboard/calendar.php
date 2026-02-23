<?php
/**
 * My Calendar Dashboard Page
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$current_page = 'calendar';
$userId = get_current_user_id();

// Get upcoming sessions
$sessions = [];
// Integration with SessionService would populate this

include get_stylesheet_directory() . '/templates/partials/dashboard-layout.php';
?>

<header class="str-dashboard__header">
    <h1 class="str-dashboard__title"><?php esc_html_e('Mijn agenda', 'stridence'); ?></h1>
    <p class="str-dashboard__subtitle">
        <?php esc_html_e('Overzicht van je geplande sessies', 'stridence'); ?>
    </p>
</header>

<?php if (!empty($sessions)): ?>
    <div class="str-sessions__list">
        <?php foreach ($sessions as $session): ?>
            <?php include get_stylesheet_directory() . '/templates/partials/session-item.php'; ?>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="str-empty-state">
        <?php stridence_icon('calendar', '', 48); ?>
        <h2><?php esc_html_e('Geen sessies gepland', 'stridence'); ?></h2>
        <p><?php esc_html_e('Je hebt momenteel geen klassikale sessies gepland.', 'stridence'); ?></p>
        <a href="<?php echo esc_url(home_url('/cursussen/klassikaal/')); ?>" class="str-btn str-btn--primary">
            <?php esc_html_e('Bekijk klassikale cursussen', 'stridence'); ?>
        </a>
    </div>
<?php endif; ?>

<?php
include get_stylesheet_directory() . '/templates/partials/dashboard-layout-close.php';
get_footer();
?>
