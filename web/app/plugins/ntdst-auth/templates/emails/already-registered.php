<?php
/**
 * Already Registered Email Template
 *
 * Variables: $login_url, $site_name
 */
defined('ABSPATH') || exit;
?>
<h1><?php esc_html_e('Account already exists', 'ntdst-auth'); ?></h1>

<p><?php esc_html_e('Someone tried to create an account at', 'ntdst-auth'); ?> <?php echo esc_html($site_name); ?> <?php esc_html_e('using this email address, but you already have an account.', 'ntdst-auth'); ?></p>

<p><?php esc_html_e('You can sign in using the link below:', 'ntdst-auth'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($login_url); ?>" style="display: inline-block; padding: 14px 28px; background-color: #1e87f0; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
        <?php esc_html_e('Sign In', 'ntdst-auth'); ?>
    </a>
</p>

<p style="color: #666666; font-size: 14px;">
    <?php esc_html_e("If you didn't try to register, someone else may have entered your email by mistake. You can safely ignore this email.", 'ntdst-auth'); ?>
</p>
