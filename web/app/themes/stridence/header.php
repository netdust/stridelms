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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">
    <?php wp_head(); ?>
</head>
<body <?php body_class('bg-surface text-text'); ?>>
<?php wp_body_open(); ?>

<div id="page" class="min-h-screen flex flex-col">

    <!-- Skip Link -->
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 bg-primary text-white px-4 py-2 rounded-lg z-50">
        <?php esc_html_e('Naar hoofdinhoud', 'stridence'); ?>
    </a>

    <!-- Header -->
    <header class="sticky top-0 z-40 bg-surface-card border-b border-border" x-data="mobileMenu()">
        <div class="container">
            <div class="flex items-center justify-between h-16 lg:h-20">

                <!-- Logo -->
                <a href="<?php echo esc_url(home_url('/')); ?>" class="flex-shrink-0">
                    <?php if (has_custom_logo()) : ?>
                        <?php the_custom_logo(); ?>
                    <?php else : ?>
                        <span class="text-xl font-heading font-bold text-primary">
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
                    <!-- Search Toggle -->
                    <button type="button" class="nav-link p-2" aria-label="<?php esc_attr_e('Zoeken', 'stridence'); ?>">
                        <?php echo stridence_icon('search', 'w-5 h-5'); ?>
                    </button>

                    <!-- User Menu -->
                    <?php if (is_user_logged_in()) : ?>
                        <?php $current_user = wp_get_current_user(); ?>
                        <div x-data="dropdown()" class="relative">
                            <button @click="toggle()" class="flex items-center gap-2 nav-link">
                                <span class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-medium text-sm">
                                    <?php echo esc_html(strtoupper(substr($current_user->display_name, 0, 1))); ?>
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
                                 class="absolute right-0 mt-2 w-48 bg-surface-card rounded-lg shadow-overlay border border-border py-1 z-50">
                                <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="block px-4 py-2 text-sm hover:bg-surface-alt">
                                    <?php esc_html_e('Mijn account', 'stridence'); ?>
                                </a>
                                <a href="<?php echo esc_url(home_url('/mijn-account/?tab=offertes')); ?>" class="block px-4 py-2 text-sm hover:bg-surface-alt">
                                    <?php esc_html_e('Mijn offertes', 'stridence'); ?>
                                </a>
                                <hr class="my-1 border-border">
                                <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="block px-4 py-2 text-sm text-text-muted hover:bg-surface-alt">
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
                <hr class="my-4 border-border">
                <?php if (is_user_logged_in()) : ?>
                    <div class="space-y-1">
                        <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="block nav-link">
                            <?php esc_html_e('Mijn account', 'stridence'); ?>
                        </a>
                        <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>" class="block nav-link text-text-muted">
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
