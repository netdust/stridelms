<?php

/**
 * PHPUnit Bootstrap
 *
 * Sets up the testing environment with WordPress function stubs
 * and loads the autoloader.
 *
 * For Integration tests (with real WordPress), use --testsuite Integration
 * which detects and loads WordPress instead of stubs.
 */

// Composer autoloader first
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Detect if we're running integration tests (via PHPUnit testsuite or environment)
$isIntegration = false;
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_contains($arg, 'Integration') || str_contains($arg, 'integration')) {
        $isIntegration = true;
        break;
    }
}

// Also check environment variable (can be set explicitly)
if (getenv('STRIDE_INTEGRATION_TESTS') === '1') {
    $isIntegration = true;
}

// Integration tests: Load real WordPress
if ($isIntegration) {
    require_once __DIR__ . '/Integration/bootstrap.php';
    return; // Integration bootstrap handles everything
}

// Unit tests: Load stubs and mocks
// Enable bypass-finals to allow mocking final classes
DG\BypassFinals::enable();

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

// Define NTDST_PATH so files that reference it load cleanly
defined('NTDST_PATH') || define('NTDST_PATH', dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core');

// Load NTDST Core classes needed for testing
$ntdstCoreFiles = [
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/api/Response.php',
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/core/Container.php',
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/core/SectorRegistry.php',
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/core/Bootstrap.php',
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/core/Router.php',
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/api/Endpoints.php',
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/core/Theme.php',
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/api/MetaboxGenerator.php',
    dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/services/Mailer.php',
];

// RelationField depends on NTDST_Service_Meta — load the contract first
// (if not already provided by the infrastructure stubs).
if (!interface_exists('NTDST_Service_Meta', false)) {
    require_once dirname(__DIR__) . '/web/app/mu-plugins/ntdst-core/core/ServiceInterface.php';
}

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
    // Theme services
    dirname(__DIR__) . '/web/app/themes/stridence/services/frontend/TrajectoryDashboardService.php',
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
