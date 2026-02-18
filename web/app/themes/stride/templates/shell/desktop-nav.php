<?php
/**
 * Desktop Navigation Template
 *
 * Displays the main navigation bar for desktop devices.
 * Only visible on medium screens and up (hidden on mobile via CSS).
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<nav class="stride-desktop-nav">
    <div class="uk-container">
        <div class="stride-desktop-nav__inner">
            <!-- Logo -->
            <a href="<?php echo esc_url(home_url('/')); ?>" class="stride-desktop-nav__logo">
                <?php if (has_custom_logo()) : ?>
                    <?php
                    $custom_logo_id = get_theme_mod('custom_logo');
                    $logo = wp_get_attachment_image_src($custom_logo_id, 'full');
                    if ($logo) {
                        echo '<img src="' . esc_url($logo[0]) . '" alt="' . esc_attr(get_bloginfo('name')) . '" style="height: 36px; width: auto;">';
                    }
                    ?>
                <?php else : ?>
                    <span class="uk-text-bold uk-text-large"><?php bloginfo('name'); ?></span>
                <?php endif; ?>
            </a>

            <!-- Main Menu -->
            <ul class="stride-desktop-nav__menu">
                <li<?php echo is_page('cursussen') || is_singular('sfwd-courses') ? ' class="uk-active"' : ''; ?>>
                    <a href="<?php echo esc_url(home_url('/cursussen/')); ?>"><?php esc_html_e('Cursussen', 'stride'); ?></a>
                </li>
                <li<?php echo is_page('trajecten') || is_singular('trajectory') ? ' class="uk-active"' : ''; ?>>
                    <a href="<?php echo esc_url(home_url('/trajecten/')); ?>"><?php esc_html_e('Trajecten', 'stride'); ?></a>
                </li>
            </ul>

            <!-- User Actions -->
            <div class="stride-desktop-nav__actions">
                <?php if (is_user_logged_in()) : ?>
                    <?php $current_user = wp_get_current_user(); ?>
                    <div class="uk-inline">
                        <button class="uk-button uk-button-default" type="button" style="display: flex; align-items: center; gap: 8px;">
                            <span class="stride-avatar stride-avatar--sm">
                                <?php echo get_avatar($current_user->ID, 32) ?: esc_html(strtoupper(substr($current_user->display_name, 0, 1))); ?>
                            </span>
                            <span><?php echo esc_html($current_user->display_name); ?></span>
                            <span uk-icon="icon: chevron-down; ratio: 0.8"></span>
                        </button>
                        <div uk-dropdown="mode: click; pos: bottom-right">
                            <ul class="uk-nav uk-dropdown-nav">
                                <li>
                                    <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>">
                                        <span uk-icon="icon: home; ratio: 0.9"></span>
                                        <?php esc_html_e('Dashboard', 'stride'); ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>">
                                        <span uk-icon="icon: album; ratio: 0.9"></span>
                                        <?php esc_html_e('Mijn cursussen', 'stride'); ?>
                                    </a>
                                </li>
                                <li>
                                    <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>">
                                        <span uk-icon="icon: file-text; ratio: 0.9"></span>
                                        <?php esc_html_e('Mijn offertes', 'stride'); ?>
                                    </a>
                                </li>
                                <li class="uk-nav-divider"></li>
                                <li>
                                    <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>">
                                        <span uk-icon="icon: sign-out; ratio: 0.9"></span>
                                        <?php esc_html_e('Uitloggen', 'stride'); ?>
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php else : ?>
                    <a href="<?php echo esc_url(wp_login_url(get_permalink())); ?>" class="uk-button uk-button-primary">
                        <?php esc_html_e('Inloggen', 'stride'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
