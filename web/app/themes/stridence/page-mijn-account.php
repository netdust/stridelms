<?php
/**
 * Template Name: Mijn Account
 *
 * Dashboard shell with floating dock navigation and centered content.
 * Requires login - redirects to login page if not authenticated.
 * URL state via ?tab=xxx parameter. Default tab is home.
 *
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Require authentication
if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url(get_permalink()));
    exit;
}

$user = wp_get_current_user();

// Get current tab from URL (default: home)
$valid_tabs = ['home', 'inschrijvingen', 'trajecten', 'offertes', 'certificaten', 'profiel'];
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'home';

if (!in_array($current_tab, $valid_tabs, true)) {
    $current_tab = 'home';
}

// Fetch home data for nav_items (and home tab content)
$dashboardService = ntdst_get(\Stride\Modules\User\UserDashboardService::class);
$home_data = $dashboardService->getHomeData($user->ID);
$nav_items = $home_data['nav_items'] ?? [];

get_header();
?>

<div class="min-h-screen bg-surface pb-20 lg:pb-0">
    <!-- Floating Dock (desktop only) -->
    <?php
    get_template_part('templates/dashboard/nav-dock', null, [
        'current_tab' => $current_tab,
        'nav_items'   => $nav_items,
    ]);
    ?>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-6 lg:px-8 py-8 lg:py-12">
        <?php
        if ($current_tab === 'home') {
            get_template_part('templates/dashboard/tab-home', null, [
                'user'      => $user,
                'home_data' => $home_data,
            ]);
        } else {
            get_template_part("templates/dashboard/tab-{$current_tab}", null, [
                'user' => $user,
            ]);
        }
        ?>
    </main>

    <!-- Mobile Navigation -->
    <?php
    get_template_part('templates/dashboard/nav-mobile', null, [
        'current_tab' => $current_tab,
        'nav_items'   => $nav_items,
    ]);
    ?>
</div>

<?php get_footer(); ?>
