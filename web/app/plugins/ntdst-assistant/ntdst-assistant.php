<?php
declare(strict_types=1);

/**
 * Plugin Name: NTDST Assistant
 * Description: AI chat assistant for WordPress admins powered by Claude API
 * Version: 1.0.0
 * Author: NTDST
 * Requires at least: 6.9
 * Requires PHP: 8.1
 */

defined('ABSPATH') || exit;

// Check ntdst-core is available
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>NTDST Assistant</strong> requires ntdst-core to be active.';
        echo '</p></div>';
    });
    return;
}

// Check WordPress version (Abilities API requires 6.9)
if (!function_exists('wp_register_ability')) {
    add_action('admin_notices', function (): void {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>NTDST Assistant</strong> requires WordPress 6.9+ (Abilities API).';
        echo '</p></div>';
    });
    return;
}

// Load config
$ntdstAssistantConfig = require __DIR__ . '/plugin-config.php';

// Register DI bindings
add_action('ntdst/core_ready', function () use ($ntdstAssistantConfig): void {
    foreach ($ntdstAssistantConfig['bindings'] as $interface => $implementation) {
        ntdst_set($interface, $implementation);
    }
});

// Register services
add_action('ntdst/features_ready', function () use ($ntdstAssistantConfig): void {
    foreach ($ntdstAssistantConfig['services'] as $serviceClass) {
        if (class_exists($serviceClass)) {
            ntdst_get($serviceClass);
        }
    }
});

// Cron: cleanup expired export files
register_activation_hook(__FILE__, function (): void {
    if (!wp_next_scheduled('ntdst_assistant_cleanup_exports')) {
        wp_schedule_event(time(), 'hourly', 'ntdst_assistant_cleanup_exports');
    }
});

register_deactivation_hook(__FILE__, function (): void {
    wp_clear_scheduled_hook('ntdst_assistant_cleanup_exports');
});

add_action('ntdst_assistant_cleanup_exports', function (): void {
    if (class_exists(\NtdstAssistant\ExportService::class)) {
        ntdst_get(\NtdstAssistant\ExportService::class)->cleanup();
    }
});
