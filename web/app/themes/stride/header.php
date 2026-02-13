<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page" class="stride-site">
    <header id="masthead" class="stride-header uk-navbar-container" uk-navbar>
        <div class="uk-container">
            <div uk-navbar>
                <!-- Left: Logo -->
                <div class="uk-navbar-left">
                    <?php if (has_custom_logo()) : ?>
                        <a class="uk-navbar-item uk-logo" href="<?php echo esc_url(home_url('/')); ?>">
                            <?php
                            $custom_logo_id = get_theme_mod('custom_logo');
                            $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
                            if ($logo) {
                                echo '<img src="' . esc_url($logo[0]) . '" alt="' . get_bloginfo('name') . '" style="max-height: 40px;">';
                            }
                            ?>
                        </a>
                    <?php else : ?>
                        <a class="uk-navbar-item uk-logo" href="<?php echo esc_url(home_url('/')); ?>">
                            <?php bloginfo('name'); ?>
                        </a>
                    <?php endif; ?>

                    <!-- Main Navigation (Desktop) -->
                    <nav class="uk-visible@m">
                        <?php
                        wp_nav_menu([
                            'theme_location' => 'primary',
                            'menu_id' => 'primary-menu',
                            'container' => false,
                            'menu_class' => 'uk-navbar-nav',
                            'fallback_cb' => 'stride_fallback_menu',
                            'walker' => new Stride_UIkit_Nav_Walker(),
                        ]);
                        ?>
                    </nav>
                </div>

                <!-- Right: User Menu -->
                <div class="uk-navbar-right">
                    <?php if (is_user_logged_in()) : ?>
                        <?php $current_user = wp_get_current_user(); ?>
                        <ul class="uk-navbar-nav">
                            <li>
                                <a href="#">
                                    <span uk-icon="icon: user"></span>
                                    <span class="uk-visible@s"><?php echo esc_html($current_user->display_name); ?></span>
                                </a>
                                <div class="uk-navbar-dropdown">
                                    <ul class="uk-nav uk-navbar-dropdown-nav">
                                        <li><a href="<?php echo esc_url(home_url('/mijn-account/')); ?>">
                                            <span uk-icon="icon: home; ratio: 0.8"></span> <?php esc_html_e('Dashboard', 'stride'); ?>
                                        </a></li>
                                        <li><a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>">
                                            <span uk-icon="icon: album; ratio: 0.8"></span> <?php esc_html_e('Mijn Cursussen', 'stride'); ?>
                                        </a></li>
                                        <li><a href="<?php echo esc_url(home_url('/mijn-account/trajecten/')); ?>">
                                            <span uk-icon="icon: git-branch; ratio: 0.8"></span> <?php esc_html_e('Mijn Trajecten', 'stride'); ?>
                                        </a></li>
                                        <li><a href="<?php echo esc_url(home_url('/mijn-account/profiel/')); ?>">
                                            <span uk-icon="icon: cog; ratio: 0.8"></span> <?php esc_html_e('Profiel', 'stride'); ?>
                                        </a></li>
                                        <li class="uk-nav-divider"></li>
                                        <li><a href="<?php echo esc_url(wp_logout_url(home_url())); ?>">
                                            <span uk-icon="icon: sign-out; ratio: 0.8"></span> <?php esc_html_e('Uitloggen', 'stride'); ?>
                                        </a></li>
                                    </ul>
                                </div>
                            </li>
                        </ul>
                    <?php else : ?>
                        <ul class="uk-navbar-nav">
                            <li><a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>">
                                <span uk-icon="icon: sign-in"></span>
                                <span class="uk-visible@s"><?php esc_html_e('Inloggen', 'stride'); ?></span>
                            </a></li>
                        </ul>
                    <?php endif; ?>

                    <!-- Mobile Menu Toggle -->
                    <a class="uk-navbar-toggle uk-hidden@m" uk-navbar-toggle-icon href="#" uk-toggle="target: #mobile-nav"></a>
                </div>
            </div>
        </div>
    </header>

    <!-- Mobile Navigation Offcanvas -->
    <div id="mobile-nav" uk-offcanvas="overlay: true">
        <div class="uk-offcanvas-bar">
            <button class="uk-offcanvas-close" type="button" uk-close></button>

            <?php if (is_user_logged_in()) : ?>
                <?php $current_user = wp_get_current_user(); ?>
                <div class="uk-margin-bottom uk-text-center">
                    <span uk-icon="icon: user; ratio: 2"></span>
                    <p class="uk-margin-small-top"><?php echo esc_html($current_user->display_name); ?></p>
                </div>
            <?php endif; ?>

            <ul class="uk-nav uk-nav-default uk-nav-parent-icon" uk-nav>
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container' => false,
                    'items_wrap' => '%3$s',
                    'fallback_cb' => 'stride_fallback_menu_items',
                ]);
                ?>

                <?php if (is_user_logged_in()) : ?>
                    <li class="uk-nav-divider"></li>
                    <li class="uk-nav-header"><?php esc_html_e('Mijn Account', 'stride'); ?></li>
                    <li><a href="<?php echo esc_url(home_url('/mijn-account/')); ?>"><?php esc_html_e('Dashboard', 'stride'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>"><?php esc_html_e('Mijn Cursussen', 'stride'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/mijn-account/trajecten/')); ?>"><?php esc_html_e('Mijn Trajecten', 'stride'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/mijn-account/profiel/')); ?>"><?php esc_html_e('Profiel', 'stride'); ?></a></li>
                    <li class="uk-nav-divider"></li>
                    <li><a href="<?php echo esc_url(wp_logout_url(home_url())); ?>"><?php esc_html_e('Uitloggen', 'stride'); ?></a></li>
                <?php else : ?>
                    <li class="uk-nav-divider"></li>
                    <li><a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>"><?php esc_html_e('Inloggen', 'stride'); ?></a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <main id="content" class="stride-content">
