<?php
declare(strict_types=1);

/**
 * Plugin Name: Netdust Mail
 * Description: Email template management with SmartCodes and action triggers
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Netdust
 * Text Domain: netdust-mail
 */

defined('ABSPATH') || exit;

// Require ntdst-core
if (!function_exists('ntdst_container')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Netdust Mail requires ntdst-core to be active.', 'netdust-mail');
        echo '</p></div>';
    });
    return;
}

define('NDMAIL_VERSION', '1.0.0');
define('NDMAIL_PATH', plugin_dir_path(__FILE__));
define('NDMAIL_URL', plugin_dir_url(__FILE__));

require_once NDMAIL_PATH . 'vendor/autoload.php';

// Register main service with ntdst container
add_action('ntdst/services_registered', function () {
    // Register sub-services first (dependencies)
    ntdst_set(\Netdust\Mail\SmartCodeRegistry::class);
    ntdst_set(\Netdust\Mail\SmartCodeParser::class);
    ntdst_set(\Netdust\Mail\TriggerRegistry::class);
    ntdst_set(\Netdust\Mail\AttachmentHandler::class);
    ntdst_set(\Netdust\Mail\MailTemplateRepository::class);

    // Register main service with explicit factory
    ntdst_set(\Netdust\Mail\MailService::class, function ($container) {
        return new \Netdust\Mail\MailService(
            $container->get(\Netdust\Mail\SmartCodeRegistry::class),
            $container->get(\Netdust\Mail\SmartCodeParser::class),
            $container->get(\Netdust\Mail\TriggerRegistry::class),
            $container->get(\Netdust\Mail\AttachmentHandler::class),
            $container->get(\Netdust\Mail\MailTemplateRepository::class)
        );
    });

    // Register admin controller with explicit factory
    ntdst_set(\Netdust\Mail\Admin\AdminController::class, function ($container) {
        return new \Netdust\Mail\Admin\AdminController(
            $container->get(\Netdust\Mail\SmartCodeRegistry::class),
            $container->get(\Netdust\Mail\TriggerRegistry::class),
            $container->get(\Netdust\Mail\AttachmentHandler::class)
        );
    });
});

// Boot after ntdst features are ready
add_action('ntdst/features_ready', function () {
    ntdst_get(\Netdust\Mail\MailService::class);

    if (is_admin()) {
        ntdst_get(\Netdust\Mail\Admin\AdminController::class);
    }
});

/**
 * Send an email using a template.
 *
 * @param string $templateSlug The template slug to use.
 * @param array  $context      SmartCode context data.
 * @param array  $options      Additional options (to, cc, bcc, attachments).
 * @return bool|\WP_Error True on success, WP_Error on failure.
 */
function ndmail_send(string $templateSlug, array $context, array $options = []): bool|\WP_Error
{
    return ntdst_get(\Netdust\Mail\MailService::class)->send($templateSlug, $context, $options);
}

/**
 * Get a mail builder for a template.
 *
 * @param string $templateSlug The template slug to use.
 * @return \Netdust\Mail\MailBuilder
 */
function ndmail_template(string $templateSlug): \Netdust\Mail\MailBuilder
{
    return ntdst_get(\Netdust\Mail\MailService::class)->template($templateSlug);
}
