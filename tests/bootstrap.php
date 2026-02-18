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

// Base test case
require_once __DIR__ . '/TestCase.php';

// Load service contracts and core classes
require_once dirname(__DIR__) . '/web/app/themes/stride/services/contracts/StorageBackendInterface.php';
require_once dirname(__DIR__) . '/web/app/themes/stride/services/contracts/FluentCRMAdapterInterface.php';
require_once dirname(__DIR__) . '/web/app/themes/stride/services/contracts/LearnDashAdapterInterface.php';
require_once dirname(__DIR__) . '/web/app/themes/stride/services/FieldRegistry.php';

// Mock adapters
require_once __DIR__ . '/Mocks/MockFluentCRMAdapter.php';
require_once __DIR__ . '/Mocks/MockLearnDashAdapter.php';
require_once __DIR__ . '/Mocks/MockStorageBackend.php';
require_once __DIR__ . '/Mocks/MockUserDataSync.php';
