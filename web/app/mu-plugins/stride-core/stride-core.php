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

// Register CPTs early (Edition/Session registered via their services)
add_action('init', [\Stride\Modules\Invoicing\QuoteCPT::class, 'register'], 5);
add_action('init', [\Stride\Modules\Invoicing\VoucherCPT::class, 'register'], 5);
add_action('init', [\Stride\Modules\Trajectory\TrajectoryCPT::class, 'register'], 5);

// Create custom tables on activation
add_action('init', function (): void {
    if (!get_option('stride_tables_created')) {
        \Stride\Modules\Enrollment\RegistrationTable::create();
        \Stride\Modules\Edition\SessionRegistrationTable::create();
        \Stride\Modules\Trajectory\TrajectoryEnrollmentTable::create();
        update_option('stride_tables_created', '1');
    }
}, 1);

// Create session_registrations table if missing (added in later version)
add_action('init', function (): void {
    if (!get_option('stride_session_registrations_table_created')) {
        \Stride\Modules\Edition\SessionRegistrationTable::create();
        update_option('stride_session_registrations_table_created', '1');
    }
}, 1);

// Create trajectory_enrollments table if missing
add_action('init', function (): void {
    if (!get_option('stride_trajectory_enrollments_table_created')) {
        \Stride\Modules\Trajectory\TrajectoryEnrollmentTable::create();
        update_option('stride_trajectory_enrollments_table_created', '1');
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
