<?php

declare(strict_types=1);

/**
 * Plugin Name: Stride Core
 * Description: Business logic for Stride LMS
 * Version: 1.0.0
 * Author: NTDST
 */

defined('ABSPATH') || exit;

// Load autoloader
require_once __DIR__ . '/autoload.php';

// Load config
$config = require __DIR__ . '/plugin-config.php';

// Register CPTs early
add_action('init', [\Stride\Modules\Edition\EditionCPT::class, 'register'], 5);
add_action('init', [\Stride\Modules\Edition\SessionCPT::class, 'register'], 5);
add_action('init', [\Stride\Modules\Invoicing\QuoteCPT::class, 'register'], 5);
add_action('init', [\Stride\Modules\Invoicing\VoucherCPT::class, 'register'], 5);

// Create custom tables on activation
add_action('init', function (): void {
    if (!get_option('stride_tables_created')) {
        \Stride\Modules\Enrollment\RegistrationTable::create();
        update_option('stride_tables_created', '1');
    }
}, 1);

// Register DI bindings
add_action('ntdst/core_ready', function () use ($config): void {
    // Register repositories first
    ntdst_set(\Stride\Modules\Edition\EditionRepository::class);
    ntdst_set(\Stride\Modules\Enrollment\RegistrationRepository::class);
    ntdst_set(\Stride\Modules\Invoicing\QuoteRepository::class);
    ntdst_set(\Stride\Modules\Invoicing\VoucherRepository::class);

    // Register interface bindings
    foreach ($config['bindings'] as $interface => $implementation) {
        ntdst_set($interface, $implementation);
    }
});

// Register services
add_action('ntdst/features_ready', function () use ($config): void {
    foreach ($config['services'] as $serviceClass) {
        if (class_exists($serviceClass)) {
            ntdst_get($serviceClass);
        }
    }

    // Register shortcodes
    ntdst_set(\Stride\Modules\User\DashboardShortcode::class);
    ntdst_get(\Stride\Modules\User\DashboardShortcode::class);

    ntdst_set(\Stride\Modules\User\QuotesShortcode::class);
    ntdst_get(\Stride\Modules\User\QuotesShortcode::class);

    // Register handlers
    ntdst_set(\Stride\Handlers\EnrollmentQuoteHandler::class);
    ntdst_get(\Stride\Handlers\EnrollmentQuoteHandler::class);
});
