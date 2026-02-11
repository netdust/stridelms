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
    <header id="masthead" class="stride-header">
        <div class="stride-header-inner">
            <?php if (has_custom_logo()) : ?>
                <div class="stride-logo">
                    <?php the_custom_logo(); ?>
                </div>
            <?php else : ?>
                <div class="stride-site-title">
                    <a href="<?php echo esc_url(home_url('/')); ?>" rel="home">
                        <?php bloginfo('name'); ?>
                    </a>
                </div>
            <?php endif; ?>

            <nav id="site-navigation" class="stride-navigation">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'menu_id' => 'primary-menu',
                    'container_class' => 'stride-menu-container',
                    'fallback_cb' => false,
                ]);
                ?>
            </nav>
        </div>
    </header>
