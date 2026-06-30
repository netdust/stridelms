<?php
/**
 * Template Name: Mijn Account
 *
 * Dashboard shell with sidebar navigation (desktop) and a sticky top bar
 * with nav chips (mobile).
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

// Only fetch full home data on the home tab. Non-home tabs hydrate nothing
// here: the sidebar/mobile top bar are static (built below) and each tab
// template fetches its own data through UserDashboardService's per-request
// memo. (Audit CR-2: getNavData() full hydration on non-home tabs had zero
// consumers, so the call was deleted; nav-mobile.php renders the same
// static nav arrays as the sidebar.)
$dashboardService = ntdst_get(\Stride\Modules\User\UserDashboardService::class);
$home_data = $current_tab === 'home' ? $dashboardService->getHomeData($user->ID) : null;

// Compute greeting variables (needed by $page_titles below)
$firstName = explode(' ', trim($user->display_name))[0];
$initials = strtoupper(mb_substr($user->first_name ?: $firstName, 0, 1) . mb_substr($user->last_name ?: '', 0, 1)) ?: '?';
$hour = (int) date('G');
$greeting = match (true) {
    $hour < 12  => __('Goedemorgen', 'stridence'),
    $hour < 18  => __('Goedemiddag', 'stridence'),
    default     => __('Goedenavond', 'stridence'),
};

// Build sidebar navigation.
// All items are always visible — each tab template has its own empty-state
// when the user has no data yet. Keeps the sidebar consistent across tabs
// (per bug_dashboard_nav_inconsistent: Home and other tabs were computing
// visibility from different data sources, causing items to appear/disappear).
$primary_nav = [
    'home'            => ['label' => __('Home', 'stridence'), 'icon' => 'home', 'visible' => true],
    'inschrijvingen'  => ['label' => __('Opleidingen', 'stridence'), 'icon' => 'book-open', 'visible' => true],
    'trajecten'       => ['label' => __('Trajecten', 'stridence'), 'icon' => 'layers', 'visible' => true],
    'offertes'        => ['label' => __('Offertes', 'stridence'), 'icon' => 'file-text', 'visible' => true],
];

$utility_nav = [
    'meldingen'       => ['label' => __('Meldingen', 'stridence'), 'icon' => 'bell', 'visible' => true],
    'downloads'       => ['label' => __('Downloads', 'stridence'), 'icon' => 'download', 'visible' => true],
    'certificaten'    => ['label' => __('Certificaten', 'stridence'), 'icon' => 'award', 'visible' => true],
];

// Notification count
$notificationService = ntdst_get(\Stride\Modules\Notification\NotificationService::class);
$unread_count = $notificationService->getUnreadCount($user->ID);

$page_titles = [
    'home'           => $greeting . ', ' . $firstName,
    'inschrijvingen' => __('Opleidingen', 'stridence'),
    'trajecten'      => __('Trajecten', 'stridence'),
    'offertes'       => __('Offertes', 'stridence'),
    'certificaten'   => __('Certificaten', 'stridence'),
    'profiel'        => __('Profiel', 'stridence'),
    'meldingen'      => __('Meldingen', 'stridence'),
    'downloads'      => __('Downloads', 'stridence'),
];

// Per-tab sub lines under the serif page title (Helder Tij mockup titles map).
// Home has no entry: the home tab renders its own greeting block.
$page_subs = [
    'inschrijvingen' => __('Alle inschrijvingen voor klassikale en online opleidingen.', 'stridence'),
    'trajecten'      => __('Meerdelige leertrajecten en je voortgang.', 'stridence'),
    'offertes'       => __('Aanvragen voor jou of je team.', 'stridence'),
    'certificaten'   => __('Behaalde attesten en certificaten.', 'stridence'),
    'profiel'        => __('Persoonlijke gegevens en facturatie.', 'stridence'),
    'meldingen'      => __('Updates over je inschrijvingen en trajecten.', 'stridence'),
    'downloads'      => __('Cursusmateriaal en documenten.', 'stridence'),
];

get_header('dashboard');
?>

<div class="min-h-screen flex bg-surface">

    <!-- Sidebar (desktop only — internals owned by nav-sidebar.php) -->
    <div class="hidden lg:block">
        <?php stridence_template_part('templates/dashboard/nav-sidebar', null, [
            'current_tab'   => $current_tab,
            'primary_nav'   => $primary_nav,
            'utility_nav'   => $utility_nav,
            'user'          => $user,
            'unread_count'  => $unread_count,
        ]); ?>
    </div>

    <!-- Main Column -->
    <div class="flex-1 min-w-0 flex flex-col">

        <!-- Dashboard top bar — restores continuity with the public site:
             a "Terug" link to the previous page + the primary site nav, so
             the dashboard is never a dead end. Desktop only; mobile keeps
             its own nav-mobile bar below. -->
        <div class="hidden lg:flex items-center justify-between gap-4 h-14 lg:px-10 border-b border-border-soft bg-surface-card/80 backdrop-blur sticky top-0 z-20">
            <a href="<?php echo esc_url(wp_get_referer() ?: home_url('/')); ?>"
               onclick="if (document.referrer && document.referrer !== location.href) { history.back(); return false; }"
               class="inline-flex items-center gap-1.5 text-sm font-semibold text-text-muted hover:text-text transition-colors duration-fast">
                <?php echo stridence_icon('arrow-left', 'w-4 h-4'); ?>
                <?php esc_html_e('Terug', 'stridence'); ?>
            </a>

            <nav class="flex items-center gap-1">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container' => false,
                    'menu_class' => 'flex items-center gap-1',
                    'fallback_cb' => 'stridence_fallback_menu',
                    'walker' => new Stridence_Nav_Walker(),
                ]);
?>
            </nav>
        </div>

        <!-- Mobile Navigation (internals owned by nav-mobile.php; rendered
             directly in the main column so its sticky top-0 can stick — a
             same-height wrapper div would pin it in place) -->
        <?php stridence_template_part('templates/dashboard/nav-mobile', null, [
            'current_tab'  => $current_tab,
            'primary_nav'  => $primary_nav,
            'utility_nav'  => $utility_nav,
            'initials'     => $initials,
            'unread_count' => $unread_count,
        ]); ?>

        <!-- Content -->
        <main class="max-w-[1080px] mx-auto w-full px-5 py-6 lg:px-10 lg:py-10 flex flex-col gap-6">

            <!-- Page Header (home tab renders its own greeting inside the actions panel) -->
            <?php $pageTitle = $page_titles[$current_tab] ?? ''; ?>
            <?php if ($pageTitle && $current_tab !== 'home') : ?>
                <header class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 class="font-serif font-normal text-[clamp(26px,3.5vw,34px)] leading-[1.1] text-text">
                            <?php echo esc_html($pageTitle); ?>
                        </h1>
                        <?php if (!empty($page_subs[$current_tab])) : ?>
                            <p class="text-sm text-text-muted mt-1.5">
                                <?php echo esc_html($page_subs[$current_tab]); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </header>
            <?php endif; ?>

            <!-- Page Content -->
            <?php
            if ($current_tab === 'home') {
                stridence_template_part('templates/dashboard/tab-home', null, [
                    'user'      => $user,
                    'home_data' => $home_data,
                    'greeting'  => $greeting,
                    'firstName' => $firstName,
                ]);
            } else {
                stridence_template_part("templates/dashboard/tab-{$current_tab}", null, [
                    'user' => $user,
                ]);
            }
?>
        </main>
    </div>

    <?php stridence_template_part('templates/dashboard/partials/toast'); ?>
</div>

<?php get_footer('dashboard'); ?>
