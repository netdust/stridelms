<?php
/**
 * Template Name: Mijn Account
 *
 * Dashboard shell with tabbed interface.
 * Requires login - redirects to login page if not authenticated.
 * URL state via ?tab=xxx parameter.
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

// Get current tab from URL (default: inschrijvingen)
$valid_tabs = ['inschrijvingen', 'trajecten', 'offertes', 'certificaten', 'profiel'];
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'inschrijvingen';

if (!in_array($current_tab, $valid_tabs, true)) {
    $current_tab = 'inschrijvingen';
}

get_header();
?>

<div class="min-h-screen bg-surface-alt pb-20 lg:pb-0 overflow-x-hidden">
    <!-- Page Header -->
    <div class="bg-surface border-b border-border">
        <div class="container py-6 lg:py-8">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 lg:w-16 lg:h-16 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                    <?php echo stridence_icon('user', 'w-6 h-6 lg:w-8 lg:h-8 text-primary'); ?>
                </div>
                <div class="min-w-0">
                    <h1 class="font-heading text-xl lg:text-2xl font-bold text-text truncate">
                        <?php
                        printf(
                            /* translators: %s: user first name */
                            esc_html__('Welkom, %s', 'stridence'),
                            esc_html($user->first_name ?: $user->display_name)
                        );
                        ?>
                    </h1>
                    <p class="text-sm text-text-muted truncate">
                        <?php echo esc_html($user->user_email); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Dashboard Layout -->
    <div class="container py-6 lg:py-8">
        <div class="grid lg:grid-cols-4 gap-6 lg:gap-8">
            <!-- Sidebar Navigation (Desktop) -->
            <aside class="hidden lg:block">
                <div class="sticky top-24">
                    <?php
                    get_template_part('templates/dashboard/nav-sidebar', null, [
                        'current_tab' => $current_tab,
                    ]);
                    ?>
                </div>
            </aside>

            <!-- Main Content Area -->
            <main class="lg:col-span-3 min-w-0">
                <?php
                // Load active tab content
                get_template_part("templates/dashboard/tab-{$current_tab}", null, [
                    'user' => $user,
                ]);
                ?>
            </main>
        </div>
    </div>

    <!-- Mobile Navigation -->
    <?php
    get_template_part('templates/dashboard/nav-mobile', null, [
        'current_tab' => $current_tab,
    ]);
    ?>
</div>

<?php get_footer(); ?>
