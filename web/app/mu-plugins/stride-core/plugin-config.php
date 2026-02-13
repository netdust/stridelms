<?php

/**
 * Stride LMS Core - Plugin Configuration
 *
 * Service registration for the Stride business logic plugin.
 * Services are loaded in order of dependencies.
 *
 * @package ntdst\Stride
 */

defined('ABSPATH') || exit;

return [
    'services' => [
        // ========================================
        // CORE SERVICES (Phase 1.5)
        // Foundation for all other services
        // ========================================
        \ntdst\Stride\core\RegistrationRepository::class,  // Table operations
        \ntdst\Stride\core\EditionService::class,          // Edition management
        \ntdst\Stride\core\SessionService::class,          // Session management
        \ntdst\Stride\core\CourseService::class,           // Course operations
        \ntdst\Stride\core\CompletionEngine::class,        // Attendance-based completion
        \ntdst\Stride\core\SubscriberService::class,       // User/subscriber data
        \ntdst\Stride\core\OrganizationService::class,     // Organization management
        \ntdst\Stride\core\HistoricalDataService::class,   // V3 data bridge

        // ========================================
        // ENROLLMENT SERVICES (Phase 2)
        // ========================================
        \ntdst\Stride\enrollment\EnrollmentService::class,
        // FormSubmissionHandler is initialized by EnrollmentService (not a service)
        \ntdst\Stride\enrollment\FluentFormsFieldHandler::class,

        // ========================================
        // INVOICING SERVICES (Phase 3-4)
        // ========================================
        \ntdst\Stride\invoicing\QuoteService::class,
        \ntdst\Stride\invoicing\VoucherService::class,

        // ========================================
        // HANDLERS (Bridge classes)
        // ========================================
        \ntdst\Stride\handlers\EnrollmentQuoteHandler::class,
        \ntdst\Stride\handlers\QuoteUpdateHandler::class,

        // ========================================
        // SYNC SERVICES
        // ========================================
        \ntdst\Stride\sync\UserDataSync::class,
        // UserDataSyncHooks is initialized by UserDataSync (not a service)

        // ========================================
        // UTILITY SERVICES
        // ========================================
        \ntdst\Stride\smartcode\SmartCodeService::class,

        // ========================================
        // ADMIN SERVICES
        // ========================================
        \ntdst\Stride\admin\AdminMenuService::class,
    ],
];
