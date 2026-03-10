<?php
/**
 * Template Name: Mijn Account
 *
 * Dashboard shell with sidebar navigation (desktop) and bottom nav (mobile).
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
$valid_tabs = ['home', 'inschrijvingen', 'trajecten', 'offertes', 'certificaten', 'profiel', 'meldingen', 'downloads'];
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'home';

if (!in_array($current_tab, $valid_tabs, true)) {
    $current_tab = 'home';
}

// Fetch home data for nav_items (and home tab content)
$dashboardService = ntdst_get(\Stride\Modules\User\UserDashboardService::class);
$home_data = $dashboardService->getHomeData($user->ID);
$nav_items = $home_data['nav_items'] ?? [];

// Compute greeting variables (needed by $page_titles below)
$firstName = explode(' ', trim($user->display_name))[0];
$initials = strtoupper(mb_substr($user->first_name ?: $firstName, 0, 1) . mb_substr($user->last_name ?: '', 0, 1)) ?: '?';
$hour = (int) date('G');
$greeting = match (true) {
    $hour < 12  => __('Goedemorgen', 'stridence'),
    $hour < 18  => __('Goedemiddag', 'stridence'),
    default     => __('Goedenavond', 'stridence'),
};

// Build sidebar navigation
$primary_nav = [
    'home'            => ['label' => __('Home', 'stridence'), 'icon' => 'home', 'visible' => true],
    'inschrijvingen'  => ['label' => __('Mijn opleidingen', 'stridence'), 'icon' => 'book-open', 'visible' => !empty($nav_items['opleidingen'])],
    'trajecten'       => ['label' => __('Trajecten', 'stridence'), 'icon' => 'layers', 'visible' => !empty($nav_items['trajecten'])],
    'offertes'        => ['label' => __('Offertes', 'stridence'), 'icon' => 'file-text', 'visible' => !empty($nav_items['offertes'])],
];

$utility_nav = [
    'meldingen'       => ['label' => __('Meldingen', 'stridence'), 'icon' => 'bell', 'visible' => true],
    'downloads'       => ['label' => __('Downloads', 'stridence'), 'icon' => 'download', 'visible' => true],
    'certificaten'    => ['label' => __('Certificaten', 'stridence'), 'icon' => 'award', 'visible' => !empty($nav_items['certificaten'])],
];

// Notification count
$notificationService = ntdst_get(\Stride\Modules\Notification\NotificationService::class);
$unread_count = $notificationService->getUnreadCount($user->ID);

$page_titles = [
    'home'           => $greeting . ', ' . $firstName,
    'inschrijvingen' => __('Mijn opleidingen', 'stridence'),
    'trajecten'      => __('Trajecten', 'stridence'),
    'offertes'       => __('Offertes', 'stridence'),
    'certificaten'   => __('Certificaten', 'stridence'),
    'profiel'        => __('Profiel', 'stridence'),
    'meldingen'      => __('Meldingen', 'stridence'),
    'downloads'      => __('Downloads', 'stridence'),
];

get_header('dashboard');
?>

<div class="min-h-screen bg-surface">
    <!-- Sidebar (desktop only) -->
    <div class="hidden lg:block">
        <?php get_template_part('templates/dashboard/nav-sidebar', null, [
            'current_tab'   => $current_tab,
            'primary_nav'   => $primary_nav,
            'utility_nav'   => $utility_nav,
            'user'          => $user,
            'unread_count'  => $unread_count,
        ]); ?>
    </div>

    <!-- Main Content Area -->
    <main class="lg:ml-sidebar min-h-screen">
        <!-- Top Bar -->
        <div class="sticky top-0 z-30 bg-surface/80 backdrop-blur-sm border-b border-border/60">
            <div class="max-w-content mx-auto px-4 md:px-6 lg:px-8 h-14 flex items-center justify-between">
                <h1 class="text-lg font-semibold text-text tracking-tight">
                    <?php echo esc_html($page_titles[$current_tab] ?? 'Dashboard'); ?>
                </h1>
            </div>
        </div>

        <!-- Page Content -->
        <div class="max-w-content mx-auto px-4 md:px-6 lg:px-8 py-6 lg:py-8">
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
        </div>
    </main>

    <!-- Mobile Bottom Navigation -->
    <div class="lg:hidden">
        <?php get_template_part('templates/dashboard/nav-mobile', null, [
            'current_tab'  => $current_tab,
            'nav_items'    => $nav_items,
            'unread_count' => $unread_count,
        ]); ?>
    </div>

    <?php get_template_part('templates/dashboard/partials/toast'); ?>
</div>

<?php get_footer('dashboard'); ?>
