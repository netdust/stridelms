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

// Load shared admin chrome helpers (stride_tool_header, stride_load_tool_chrome).
require_once __DIR__ . '/templates/admin/_tool-header.php';

// Load core formatting helpers (stride_format_date) — mail/notification
// rendering must not depend on the active theme (INV-5, audit H-6).
require_once __DIR__ . '/Support/formatting.php';

// Load config
$config = require __DIR__ . '/plugin-config.php';

// Register stride-core's own template path so mu-plugin code can render
// shared presentation partials (templates/forms/fields, templates/admin,
// templates/pdf) without depending on the active theme.
//
// Load-order trap: bedrock-autoloader.php loads this file BEFORE
// ntdst-coreloader.php ('b' < 'n'), so NTDST_Template_Loader does not
// exist yet at this point — an eager class_exists guard here silently
// skipped the registration and broke every stride-core template render
// that wasn't lucky enough to have its path primed by another caller.
// Register now when possible, otherwise as soon as all mu-plugins loaded.
$strideRegisterTemplates = static function (): void {
    if (class_exists('NTDST_Template_Loader')
        && !in_array(__DIR__ . '/templates', NTDST_Template_Loader::getCustomPaths(), true)
    ) {
        NTDST_Template_Loader::addPath(__DIR__ . '/templates');
    }
};
if (class_exists('NTDST_Template_Loader')) {
    $strideRegisterTemplates();
} else {
    add_action('muplugins_loaded', $strideRegisterTemplates, 0);
}

// CPTs registered via their services (EditionService, SessionService, etc.)

// Create custom tables on activation
add_action('init', function (): void {
    if (!get_option('stride_tables_created')) {
        \Stride\Modules\Enrollment\RegistrationTable::create();
        update_option('stride_tables_created', '1');
    }

    // Versioned schema upgrades for installs whose table predates a change
    // (gated internally on stride_registrations_schema_version).
    \Stride\Modules\Enrollment\RegistrationTable::migrate();

    // One-off relocation of completion proofs into protected storage
    // (gated internally on stride_proof_storage_version; audit M-2).
    \Stride\Modules\Enrollment\CompletionProofStorage::migrate();
}, 1);

// Create attendance table if missing
add_action('init', function (): void {
    if (!get_option('stride_attendance_table_created')) {
        \Stride\Modules\Attendance\AttendanceTable::create();
        update_option('stride_attendance_table_created', '1');
    }

    // Versioned schema upgrades for installs whose table predates a change
    // (gated internally on stride_attendance_schema_version; 4B.4 drops the
    // redundant idx_session_user).
    \Stride\Modules\Attendance\AttendanceTable::migrate();
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

    // Enroll-time profile-type policy (INV-12). Plain-DI collaborator, not a
    // booted service — resolved on demand alongside the repositories it reads,
    // never in the eager services[] list. Autowired (its deps are the two repos
    // registered above + ProfileTypeService).
    ntdst_set(\Stride\Modules\User\ProfileTypePolicy::class);

    // Metabox rules sanitizer (M5). Plain-DI collaborator shared by the Edition +
    // Trajectory admin handleSave paths — the single place the profiletype_rules
    // shape + allowlist-drop lives. Autowired (dep: ProfileTypeService).
    ntdst_set(\Stride\Modules\User\ProfiletypeRulesSanitizer::class);

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

    // Dashboard "Voor jou" page metabox (T10, M5). Plain-DI class that MUST hook
    // at boot (save_post_page + init/registerMeta + the page metabox render), so
    // it is eager-booted here like DashboardShortcode — NOT an on-demand
    // collaborator (autowired dep: ProfileTypeService).
    ntdst_set(\Stride\Modules\User\DashboardPageMetabox::class);
    ntdst_get(\Stride\Modules\User\DashboardPageMetabox::class);

    ntdst_set(\Stride\Modules\Audit\ActivityShortcode::class);
    ntdst_get(\Stride\Modules\Audit\ActivityShortcode::class);

    // Register handlers (all handlers in one place for explicit configuration)
    $handlers = [
        \Stride\Handlers\EnrollmentQuoteHandler::class,
        \Stride\Handlers\EnrollmentFormHandler::class,
        \Stride\Handlers\QuoteUpdateHandler::class,
        \Stride\Handlers\ProfileHandler::class,
        \Stride\Handlers\ICalHandler::class,
        \Stride\Handlers\AnnualReportHandler::class,
        \Stride\Handlers\BulkRegistrationHandler::class,
        \Stride\Handlers\RosterBulkHandler::class,
    ];

    foreach ($handlers as $handlerClass) {
        ntdst_set($handlerClass);
        ntdst_get($handlerClass);
    }
});

// Mail triggers now registered by StrideMailBridge service
