<?php
/**
 * Theme Header
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php
    $stridence_font_url = apply_filters('stridence_font_url', 'https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,300..800;1,6..72,300..800&family=Plus+Jakarta+Sans:wght@300..800&family=Manrope:wght@400;500;600;700&display=swap');
    if ($stridence_font_url) :
    ?>
    <link href="<?php echo esc_url($stridence_font_url); ?>" rel="stylesheet">
    <?php endif; ?>
    <?php wp_head(); ?>
</head>
<body <?php body_class('bg-surface text-text'); ?>>
<?php wp_body_open(); ?>

<div id="page" class="min-h-screen flex flex-col">

    <!-- Skip Link -->
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 bg-primary text-text-inverse px-4 py-2 rounded-lg z-50">
        <?php esc_html_e('Naar hoofdinhoud', 'stridence'); ?>
    </a>

    <!-- Header -->
    <header class="sticky top-0 z-40 glass-nav" x-data="mobileMenu()">
        <div class="container">
            <div class="flex items-center justify-between h-16 lg:h-20">

                <!-- Logo -->
                <a href="<?php echo esc_url(home_url('/')); ?>" class="flex-shrink-0">
                    <?php if (has_custom_logo()) : ?>
                        <?php the_custom_logo(); ?>
                    <?php else : ?>
                        <span class="text-xl font-serif italic font-semibold text-accent">
                            <?php bloginfo('name'); ?>
                        </span>
                    <?php endif; ?>
                </a>

                <!-- Desktop Navigation -->
                <nav class="hidden lg:flex items-center gap-1">
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

                <!-- Desktop Right Section -->
                <div class="hidden lg:flex items-center gap-3">
                    <!-- User Menu -->
                    <?php if (is_user_logged_in()) : ?>
                        <?php
                        $current_user = wp_get_current_user();
                        $notif_count  = 0;
                        if (class_exists(\Stride\Modules\Notification\NotificationService::class)) {
                            $notif_count = ntdst_get(\Stride\Modules\Notification\NotificationService::class)
                                ->getUnreadCount($current_user->ID);
                        }
                        ?>

                        <div x-data="dropdown()" class="relative">
                            <button @click="toggle()" class="flex items-center gap-2 nav-link">
                                <span class="relative w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-medium text-sm">
                                    <?php echo esc_html(strtoupper(substr($current_user->display_name, 0, 1))); ?>
                                    <?php if ($notif_count > 0) : ?>
                                        <span class="absolute -top-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-primary ring-2 ring-surface-card"></span>
                                    <?php endif; ?>
                                </span>
                                <span class="hidden xl:inline"><?php echo esc_html($current_user->display_name); ?></span>
                                <?php echo stridence_icon('chevron-down', 'w-4 h-4'); ?>
                            </button>
                            <div x-show="open"
                                 x-transition:enter="transition ease-out duration-fast"
                                 x-transition:enter-start="opacity-0 translate-y-1"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 x-transition:leave="transition ease-in duration-fast"
                                 x-transition:leave-start="opacity-100 translate-y-0"
                                 x-transition:leave-end="opacity-0 translate-y-1"
                                 @click.outside="close()"
                                 class="absolute right-0 mt-2 w-48 bg-surface-card rounded-xl shadow-overlay py-1 z-50">
                                <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="block px-4 py-2 text-sm hover:bg-surface-alt">
                                    <?php esc_html_e('Mijn account', 'stridence'); ?>
                                </a>
                                <a href="<?php echo esc_url(home_url('/mijn-account/?tab=meldingen')); ?>" class="flex items-center justify-between px-4 py-2 text-sm hover:bg-surface-alt">
                                    <span><?php esc_html_e('Meldingen', 'stridence'); ?></span>
                                    <?php if ($notif_count > 0) : ?>
                                        <span class="bg-primary text-text-inverse text-[10px] font-semibold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1">
                                            <?php echo esc_html($notif_count); ?>
                                        </span>
                                    <?php endif; ?>
                                </a>
                                <a href="<?php echo esc_url(home_url('/mijn-account/?tab=offertes')); ?>" class="block px-4 py-2 text-sm hover:bg-surface-alt">
                                    <?php esc_html_e('Mijn offertes', 'stridence'); ?>
                                </a>
                                <hr class="my-1 border-border">
                                <a href="<?php echo esc_url(home_url('/auth/logout')); ?>" class="block px-4 py-2 text-sm text-text-muted hover:bg-surface-alt">
                                    <?php esc_html_e('Uitloggen', 'stridence'); ?>
                                </a>
                            </div>
                        </div>
                    <?php else : ?>
                        <a href="<?php echo esc_url(wp_login_url()); ?>" class="btn-secondary btn-sm">
                            <?php esc_html_e('Inloggen', 'stridence'); ?>
                        </a>
                        <a href="<?php echo esc_url(wp_registration_url()); ?>" class="btn-primary btn-sm">
                            <?php esc_html_e('Registreren', 'stridence'); ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Mobile Menu Toggle -->
                <button @click="toggle()" class="lg:hidden p-2 -mr-2" aria-label="<?php esc_attr_e('Menu', 'stridence'); ?>">
                    <span x-show="!open"><?php echo stridence_icon('menu', 'w-6 h-6'); ?></span>
                    <span x-show="open" x-cloak><?php echo stridence_icon('x', 'w-6 h-6'); ?></span>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <div x-show="open"
             x-transition:enter="transition ease-out duration-normal"
             x-transition:enter-start="opacity-0 -translate-y-2"
             x-transition:enter-end="opacity-100 translate-y-0"
             x-transition:leave="transition ease-in duration-fast"
             x-transition:leave-start="opacity-100 translate-y-0"
             x-transition:leave-end="opacity-0 -translate-y-2"
             x-cloak
             class="lg:hidden border-t border-border bg-surface-card">
            <div class="container py-4">
                <nav class="space-y-1">
                    <?php
                    wp_nav_menu([
                        'theme_location' => 'primary',
                        'container' => false,
                        'menu_class' => 'space-y-1',
                        'fallback_cb' => 'stridence_fallback_menu_mobile',
                        'walker' => new Stridence_Mobile_Nav_Walker(),
                    ]);
                    ?>
                </nav>
                <div class="py-3"></div>
                <?php if (is_user_logged_in()) : ?>
                    <div class="space-y-1">
                        <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="block nav-link">
                            <?php esc_html_e('Mijn account', 'stridence'); ?>
                        </a>
                        <a href="<?php echo esc_url(home_url('/mijn-account/?tab=meldingen')); ?>" class="flex items-center justify-between nav-link">
                            <span><?php esc_html_e('Meldingen', 'stridence'); ?></span>
                            <?php if (!empty($notif_count)) : ?>
                                <span class="bg-primary text-text-inverse text-[10px] font-semibold rounded-full min-w-[18px] h-[18px] flex items-center justify-center px-1">
                                    <?php echo esc_html($notif_count); ?>
                                </span>
                            <?php endif; ?>
                        </a>
                        <a href="<?php echo esc_url(home_url('/auth/logout')); ?>" class="block nav-link text-text-muted">
                            <?php esc_html_e('Uitloggen', 'stridence'); ?>
                        </a>
                    </div>
                <?php else : ?>
                    <div class="flex gap-3">
                        <a href="<?php echo esc_url(wp_login_url()); ?>" class="btn-secondary flex-1 text-center">
                            <?php esc_html_e('Inloggen', 'stridence'); ?>
                        </a>
                        <a href="<?php echo esc_url(wp_registration_url()); ?>" class="btn-primary flex-1 text-center">
                            <?php esc_html_e('Registreren', 'stridence'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main id="main" class="flex-1">
