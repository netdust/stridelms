<?php
/**
 * Activation Email Template
 *
 * Variables: $activate_url, $expiry_hours, $site_name
 */
defined('ABSPATH') || exit;
?>
<h1><?php esc_html_e('Activate your account', 'ntdst-auth'); ?></h1>

<p><?php esc_html_e('Thank you for registering at', 'ntdst-auth'); ?> <?php echo esc_html($site_name); ?>. <?php esc_html_e('Click the button below to activate your account.', 'ntdst-auth'); ?></p>

<p><?php esc_html_e('This link will expire in', 'ntdst-auth'); ?> <?php echo esc_html($expiry_hours); ?> <?php esc_html_e('hours.', 'ntdst-auth'); ?></p>

<p style="text-align: center; margin: 30px 0;">
    <a href="<?php echo esc_url($activate_url); ?>" style="display: inline-block; padding: 14px 28px; background-color: #32d296; color: #ffffff; text-decoration: none; border-radius: 4px; font-weight: bold;">
        <?php esc_html_e('Activate Account', 'ntdst-auth'); ?>
    </a>
</p>

<p style="color: #666666; font-size: 14px;">
    <?php esc_html_e("If you didn't create an account, you can safely ignore this email.", 'ntdst-auth'); ?>
</p>

<p style="color: #999999; font-size: 12px; margin-top: 30px;">
    <?php esc_html_e("If the button doesn't work, copy and paste this link into your browser:", 'ntdst-auth'); ?><br>
    <a href="<?php echo esc_url($activate_url); ?>" style="color: #32d296; word-break: break-all;"><?php echo esc_url($activate_url); ?></a>
</p>
