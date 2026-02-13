<?php

/**
 * Plugin Name: Stride LMS Core
 * Description: Business logic and services for Stride LMS
 * Version: 1.0.0
 * Author: Stefan Vandermeulen
 *
 * Architecture:
 * - core/         → Core services (Edition, Session, Course, Registration)
 * - enrollment/   → Enrollment workflow
 * - invoicing/    → Quote/Voucher system
 * - handlers/     → Bridge classes between modules
 * - sync/         → User data synchronization
 * - adapters/     → External system adapters (LearnDash, FluentCRM)
 * - contracts/    → Interfaces
 * - admin/        → Admin functionality
 * - smartcode/    → Dynamic content system
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('STRIDE_CORE_PATH', __DIR__ . '/stride-core');
define('STRIDE_CORE_URL', plugins_url('stride-core', __FILE__));

// PSR-4 autoloader for ntdst\Stride namespace
spl_autoload_register(function ($class) {
    $prefix = 'ntdst\\Stride\\';
    $prefix_len = strlen($prefix);

    // Check if class uses our namespace
    if (strncmp($prefix, $class, $prefix_len) !== 0) {
        return;
    }

    // Get relative class name
    $relative_class = substr($class, $prefix_len);

    // Convert namespace to path
    $file = STRIDE_CORE_PATH . '/' . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load plugin configuration and register services
add_action('after_setup_theme', function () {
    // Only load if NTDST Core is available
    if (!function_exists('ntdst_set')) {
        return;
    }

    $config = require STRIDE_CORE_PATH . '/plugin-config.php';

    // Register all plugin services with NTDST Container
    foreach ($config['services'] as $service) {
        ntdst_set($service, fn() => new $service());
    }

    // Register adapter bindings
    ntdst_set(
        \ntdst\Stride\contracts\LearnDashAdapterInterface::class,
        fn() => new \ntdst\Stride\adapters\LearnDashAdapter()
    );

    ntdst_set(
        \ntdst\Stride\contracts\FluentCRMAdapterInterface::class,
        fn() => new \ntdst\Stride\adapters\FluentCRMAdapter()
    );

    // Boot services (instantiate to register hooks)
    foreach ($config['services'] as $service) {
        ntdst_get($service);
    }
}, 4); // Priority 4: Before theme bootstrap (priority 5)

// Create tables on first load
add_action('init', function () {
    // Check if tables need to be created (run once)
    $db_version = get_option('stride_core_db_version', '0');

    if (version_compare($db_version, '1.0.0', '<')) {
        if (class_exists(\ntdst\Stride\core\RegistrationRepository::class)) {
            \ntdst\Stride\core\RegistrationRepository::createTable();
        }
        update_option('stride_core_db_version', '1.0.0');
    }
}, 1);
