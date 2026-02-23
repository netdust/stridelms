<?php
/**
 * Stridence Header
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page" class="str-site">
    <header class="str-header">
        <div class="str-container str-header__inner">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="str-header__logo">
                <?php if (has_custom_logo()): ?>
                    <?php the_custom_logo(); ?>
                <?php else: ?>
                    <span class="str-header__site-name"><?php bloginfo('name'); ?></span>
                <?php endif; ?>
            </a>

            <nav class="str-header__nav" aria-label="<?php esc_attr_e('Hoofdnavigatie', 'stridence'); ?>">
                <?php
                wp_nav_menu([
                    'theme_location' => 'primary',
                    'container' => false,
                    'menu_class' => 'str-nav',
                    'fallback_cb' => false,
                    'depth' => 2,
                ]);
                ?>
            </nav>

            <div class="str-header__actions">
                <?php if (is_user_logged_in()): ?>
                    <?php $user = wp_get_current_user(); ?>
                    <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="str-header__account">
                        <img src="<?php echo esc_url(get_avatar_url($user->ID, ['size' => 32])); ?>" alt="" class="str-header__avatar">
                        <span class="str-header__user-name"><?php echo esc_html($user->display_name); ?></span>
                    </a>
                <?php else: ?>
                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="str-btn str-btn--primary str-btn--sm">
                        <?php esc_html_e('Inloggen', 'stridence'); ?>
                    </a>
                <?php endif; ?>

                <button type="button" class="str-header__toggle" aria-expanded="false" aria-controls="mobile-menu">
                    <span class="str-header__toggle-icon"></span>
                    <span class="screen-reader-text"><?php esc_html_e('Menu', 'stridence'); ?></span>
                </button>
            </div>
        </div>

        <!-- Mobile Menu -->
        <nav id="mobile-menu" class="str-mobile-nav" hidden>
            <?php
            wp_nav_menu([
                'theme_location' => 'primary',
                'container' => false,
                'menu_class' => 'str-mobile-nav__list',
                'fallback_cb' => false,
            ]);
            ?>
        </nav>
    </header>

    <main id="content" class="str-content">
