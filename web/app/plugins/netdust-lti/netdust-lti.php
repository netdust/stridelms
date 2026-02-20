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

// Check NTDST Core dependency
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p><strong>Netdust LTI</strong> requires NTDST Core to be active.</p></div>';
    });
    return;
}

// Autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Bootstrap
add_action('plugins_loaded', function() {
    ntdst_get(Plugin::class);
}, 20);
