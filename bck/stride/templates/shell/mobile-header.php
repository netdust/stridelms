<?php
/**
 * Mobile Header Template
 *
 * Displays a simple mobile header with logo and user actions.
 * Only visible on mobile devices (hidden on medium screens and up via CSS).
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<header class="stride-mobile-header">
    <!-- Logo -->
    <a href="<?php echo esc_url(home_url('/')); ?>" class="stride-mobile-header__logo">
        <?php if (has_custom_logo()) : ?>
            <?php
            $custom_logo_id = get_theme_mod('custom_logo');
            $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
            if ($logo) {
                echo '<img src="' . esc_url($logo[0]) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="height: 32px; width: auto;">';
            }
            ?>
        <?php else : ?>
            <span class="uk-text-bold"><?php bloginfo('name'); ?></span>
        <?php endif; ?>
    </a>

    <!-- Actions -->
    <div class="stride-mobile-header__actions">
        <?php if (is_user_logged_in()) : ?>
            <?php $current_user = wp_get_current_user(); ?>
            <a href="<?php echo esc_url(home_url('/mijn-account/mijn-profiel/')); ?>" class="stride-avatar stride-avatar--sm">
                <?php echo get_avatar($current_user->ID, 32) ?: esc_html(strtoupper(substr($current_user->display_name, 0, 1))); ?>
            </a>
        <?php else : ?>
            <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="uk-button uk-button-primary uk-button-small">
                <?php esc_html_e('Inloggen', 'stride'); ?>
            </a>
        <?php endif; ?>
    </div>
</header>
