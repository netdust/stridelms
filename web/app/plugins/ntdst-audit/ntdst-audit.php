<?php
/**
 * Plugin Name: NTDST Audit
 * Description: Generic audit logging for WordPress with admin viewer and REST API
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Requires DB: MariaDB 10.2.3+ or MySQL 8.0.21+ (JSON_VALUE inside a generated column)
 * Author: NTDST
 * Text Domain: ntdst-audit
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Check ntdst-core dependency
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>NTDST Audit</strong> requires ntdst-core to be active.</p></div>';
    });
    return;
}

define('NTDST_AUDIT_PATH', plugin_dir_path(__FILE__));
define('NTDST_AUDIT_URL', plugin_dir_url(__FILE__));
define('NTDST_AUDIT_VERSION', '1.1.0');

// Autoloader for NTDST\Audit\ namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'NTDST\\Audit\\';
    $base_dir = NTDST_AUDIT_PATH . 'src/';

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

// Load config and register services
add_action('plugins_loaded', function () {
    $config = require NTDST_AUDIT_PATH . 'plugin-config.php';

    foreach ($config['services'] as $service) {
        ntdst_get($service);
    }
}, 20);
