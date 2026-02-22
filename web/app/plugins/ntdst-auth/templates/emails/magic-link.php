<?php
/**
 * Magic Link Email Template
 *
 * Variables: $login_url, $expiry_minutes, $site_name
 */
defined('ABSPATH') || exit;
?>
<h1><?php esc_html_e('Sign in to', 'ntdst-auth'); ?> <?php echo esc_html($site_name); ?></h1>

<p><?php esc_html_e('Click the button below to sign in to your account. This link will expire in', 'ntdst-auth'); ?> <?php echo esc_html($expiry_minutes); ?> <?php esc_html_e('minutes.', 'ntdst-auth'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($login_url); ?>" style="display: inline-block; padding: 14px 28px; background-color: #1e87f0; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
        <?php esc_html_e('Sign In', 'ntdst-auth'); ?>
    </a>
</p>

<p style="color: #666666; font-size: 14px;">
    <?php esc_html_e("If you didn't request this link, you can safely ignore this email.", 'ntdst-auth'); ?>
</p>

<p style="color: #999999; font-size: 12px; margin-top: 30px;">
    <?php esc_html_e("If the button doesn't work, copy and paste this link into your browser:", 'ntdst-auth'); ?><br>
    <a href="<?php echo esc_url($login_url); ?>" style="color: #1e87f0; word-break: break-all;"><?php echo esc_url($login_url); ?></a>
</p>
