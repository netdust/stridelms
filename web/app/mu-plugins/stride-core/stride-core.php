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

// Create attendance table if missing
add_action('init', function (): void {
    if (!get_option('stride_attendance_table_created')) {
        \Stride\Modules\Attendance\AttendanceTable::create();
        update_option('stride_attendance_table_created', '1');
    }
}, 1);

// Register partner role for Partner API
add_action('init', function (): void {
    if (!get_role('partner')) {
        add_role('partner', 'Partner', ['read' => true]);
    }
}, 1);

// Register Stride capabilities and roles (version-gated for updates)
add_action('init', function (): void {
    $rolesVersion = 1;
    $currentVersion = (int) get_option('stride_roles_version', 0);

    if ($currentVersion < $rolesVersion) {
        // Remove existing roles so capability changes are applied
        remove_role('stride_coordinator');
        remove_role('stride_supervisor');

        // Training Coordinator — full Stride management, no WordPress settings
        add_role('stride_coordinator', 'Training Coordinator', [
            'read'              => true,
            'edit_posts'        => true,
            'edit_others_posts' => true,
            'publish_posts'     => true,
            'delete_posts'      => true,
            'upload_files'      => true,
            'stride_manage'     => true,
            'stride_view'       => true,
        ]);

        // Supervisor — read-only Stride access
        add_role('stride_supervisor', 'Supervisor', [
            'read'        => true,
            'stride_view' => true,
        ]);

        // Ensure administrator has Stride capabilities
        $admin = get_role('administrator');
        if ($admin) {
            $admin->add_cap('stride_manage');
            $admin->add_cap('stride_view');
        }

        update_option('stride_roles_version', $rolesVersion);
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

    // AttendanceRepository registered by AttendanceService::init()

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

    // Register user dashboard data service
    ntdst_set(\Stride\Modules\User\UserDashboardService::class);
    ntdst_get(\Stride\Modules\User\UserDashboardService::class);

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
        \Stride\Handlers\AnnualReportHandler::class,
    ];

    foreach ($handlers as $handlerClass) {
        ntdst_set($handlerClass);
        ntdst_get($handlerClass);
    }
});

// Mail triggers now registered by StrideMailBridge service
