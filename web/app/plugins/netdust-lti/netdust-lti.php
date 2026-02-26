<?php
/**
 * Plugin Name: Netdust LTI
 * Plugin URI: https://netdust.be
 * Description: LTI 1.3 Tool Provider for LearnDash integration
 * Version: 1.0.0
 * Author: Netdust
 * Requires PHP: 8.1
 * Requires at least: 6.0
 */

declare(strict_types=1);

namespace NetdustLTI;

defined('ABSPATH') || exit;

// Autoload early for activation hook
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Register activation/deactivation hooks at top level (before plugins_loaded)
register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate_plugin');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate_plugin');

function activate_plugin(): void
{
    Database\Migrations::run();
    generate_keys_if_needed();

    // Register CPTs and routes before flushing so they're included
    if (function_exists('ntdst_get')) {
        ntdst_get(Data\LTIDataService::class);
        ntdst_get(Platform\PlatformRouter::class);
    }

    flush_rewrite_rules();

    // Schedule cleanup cron job
    if (!wp_next_scheduled('netdust_lti_cleanup')) {
        wp_schedule_event(time(), 'hourly', 'netdust_lti_cleanup');
    }
}

function deactivate_plugin(): void
{
    flush_rewrite_rules();

    // Clear cleanup cron job
    $timestamp = wp_next_scheduled('netdust_lti_cleanup');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'netdust_lti_cleanup');
    }
}

/**
 * Generate RSA key pair for LTI 1.3 JWT signing if not already present.
 *
 * SECURITY NOTE: Keys are stored in wp_options table. For production environments
 * with strict security requirements, consider:
 * - Storing keys in encrypted files outside webroot
 * - Using environment variables or secrets management
 * - Restricting database access appropriately
 */
function generate_keys_if_needed(): void
{
    if (get_option('netdust_lti_private_key')) {
        return;
    }

    $config = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $keyPair = openssl_pkey_new($config);
    openssl_pkey_export($keyPair, $privateKey);
    $keyDetails = openssl_pkey_get_details($keyPair);

    update_option('netdust_lti_private_key', $privateKey, false); // Don't autoload
    update_option('netdust_lti_public_key', $keyDetails['key'], false);
    update_option('netdust_lti_kid', 'netdust-lti-' . time());
}

// Check NTDST Core dependency
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p><strong>Netdust LTI</strong> requires NTDST Core to be active.</p></div>';
    });
    return;
}

// Bootstrap
add_action('plugins_loaded', function() {
    ntdst_get(Plugin::class);
}, 20);
