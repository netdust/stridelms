<?php
/**
 * Plugin Name: NTDST Auth
 * Description: Magic link authentication with registration and GDPR compliance
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: NTDST
 * Text Domain: ntdst-auth
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Check ntdst-core dependency
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>NTDST Auth</strong> requires ntdst-core to be active.</p></div>';
    });
    return;
}

define('NTDST_AUTH_PATH', plugin_dir_path(__FILE__));
define('NTDST_AUTH_URL', plugin_dir_url(__FILE__));
define('NTDST_AUTH_VERSION', '1.0.0');

// Autoloader for NTDST\Auth\ namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'NTDST\\Auth\\';
    $base_dir = NTDST_AUTH_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load translations (e.g. languages/ntdst-auth-nl_BE.mo). Without this the
// UI falls back to the English source strings even on a non-English site.
add_action('init', function () {
    load_plugin_textdomain('ntdst-auth', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Load config and register services
add_action('plugins_loaded', function () {
    $config = require NTDST_AUTH_PATH . 'plugin-config.php';

    foreach ($config['services'] as $service) {
        ntdst_get($service);
    }
}, 20);

// Add email template path
add_filter('ntdst_mail_template_paths', function (array $paths): array {
    array_unshift($paths, NTDST_AUTH_PATH . 'templates/emails');
    return $paths;
});
