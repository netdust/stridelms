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

// CPTs registered via their services (EditionService, SessionService, etc.)

// Create custom tables on activation
add_action('init', function (): void {
    if (!get_option('stride_tables_created')) {
        \Stride\Modules\Enrollment\RegistrationTable::create();
        update_option('stride_tables_created', '1');
    }
}, 1);

// Migrate registration table to unified schema (v2)
add_action('init', function (): void {
    if (!get_option('stride_registration_table_v2')) {
        \Stride\Modules\Enrollment\RegistrationTable::migrate();
        update_option('stride_registration_table_v2', '1');
    }
}, 1);

// Create attendance table if missing
add_action('init', function (): void {
    if (!get_option('stride_attendance_table_created')) {
        \Stride\Modules\Attendance\AttendanceTable::create();
        update_option('stride_attendance_table_created', '1');
    }
}, 1);

// Register DI bindings
add_action('ntdst/core_ready', function () use ($config): void {
    // Register repositories first
    ntdst_set(\Stride\Modules\Edition\EditionRepository::class);
    ntdst_set(\Stride\Modules\Edition\SessionRepository::class);
    ntdst_set(\Stride\Modules\Enrollment\RegistrationRepository::class);
    ntdst_set(\Stride\Modules\Invoicing\QuoteRepository::class);
    ntdst_set(\Stride\Modules\Invoicing\VoucherRepository::class);
    ntdst_set(\Stride\Modules\Trajectory\TrajectoryRepository::class);
    ntdst_set(\Stride\Modules\Attendance\AttendanceRepository::class);

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

    // Register handlers (all handlers in one place for explicit configuration)
    $handlers = [
        \Stride\Handlers\EnrollmentQuoteHandler::class,
        \Stride\Handlers\EnrollmentFormHandler::class,
        \Stride\Handlers\QuoteUpdateHandler::class,
        \Stride\Handlers\ProfileHandler::class,
        \Stride\Handlers\ICalHandler::class,
    ];

    foreach ($handlers as $handlerClass) {
        ntdst_set($handlerClass);
        ntdst_get($handlerClass);
    }
});
