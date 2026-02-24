<?php

/**
 * PHPUnit Bootstrap
 *
 * Sets up the testing environment with WordPress function stubs
 * and loads the autoloader.
 */

// Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Brain\Monkey setup for WordPress function mocking
use Brain\Monkey;

// Define WordPress constants needed by services
defined('ABSPATH') || define('ABSPATH', dirname(__DIR__) . '/web/wp/');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', dirname(__DIR__) . '/web/app');

// Load WordPress stubs for testing
require_once __DIR__ . '/Stubs/wordpress-stubs.php';

// Load Stride Infrastructure stubs for testing (must be before mocks)
require_once __DIR__ . '/Stubs/stride-infrastructure-stubs.php';

// Base test case
require_once __DIR__ . '/TestCase.php';

// Load NTDST Core classes needed for testing
$ntdstCoreFiles = [
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/api/Response.php',
];

foreach ($ntdstCoreFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}

// Load service contracts and core classes (if they exist)
// Note: Mocks are temporarily disabled as they need interface alignment
$optionalFiles = [
    dirname(__DIR__) . '/web/app/themes/stride/services/contracts/StorageBackendInterface.php',
    dirname(__DIR__) . '/web/app/themes/stride/services/contracts/FluentCRMAdapterInterface.php',
    dirname(__DIR__) . '/web/app/themes/stride/services/contracts/LearnDashAdapterInterface.php',
    dirname(__DIR__) . '/web/app/themes/stride/services/FieldRegistry.php',
    // Mocks disabled - need interface alignment
    // __DIR__ . '/Mocks/MockFluentCRMAdapter.php',
    // __DIR__ . '/Mocks/MockLearnDashAdapter.php',
    // __DIR__ . '/Mocks/MockStorageBackend.php',
    // __DIR__ . '/Mocks/MockUserDataSync.php',
];

foreach ($optionalFiles as $file) {
    if (file_exists($file)) {
        require_once $file;
    }
}
