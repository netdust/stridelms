<?php
/**
 * Welcome Email Template
 *
 * Variables: $login_url, $site_name
 */
defined('ABSPATH') || exit;
?>
<h1><?php esc_html_e('Welcome!', 'ntdst-auth'); ?></h1>

<p><?php esc_html_e('Your account at', 'ntdst-auth'); ?> <?php echo esc_html($site_name); ?> <?php esc_html_e('has been activated successfully.', 'ntdst-auth'); ?></p>

<p><?php esc_html_e("You're all set! You can now sign in to access your account.", 'ntdst-auth'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($login_url); ?>" style="display: inline-block; padding: 14px 28px; background-color: #1e87f0; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
        <?php esc_html_e('Sign In', 'ntdst-auth'); ?>
    </a>
</p>

<p style="color: #666666; font-size: 14px;">
    <?php esc_html_e('Thank you for joining us!', 'ntdst-auth'); ?>
</p>
