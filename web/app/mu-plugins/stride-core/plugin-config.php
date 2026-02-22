<?php

declare(strict_types=1);

/**
 * Stride Core Plugin Configuration
 *
 * Service registration and DI bindings.
 */

use Stride\Adapters\LearnDashAdapter;
use Stride\Contracts\EditionQueryInterface;
use Stride\Contracts\LMSAdapterInterface;
use Stride\Modules\Edition\EditionService;

return [
    /**
     * DI Container Bindings
     *
     * Interface => Implementation mappings
     */
    'bindings' => [
        LMSAdapterInterface::class => LearnDashAdapter::class,
        EditionQueryInterface::class => EditionService::class,
    ],

    /**
     * Services to auto-register
     *
     * Services are loaded in order, by priority from metadata().
     */
    'services' => [
        \Stride\Admin\AdminDashboardService::class,
        \Stride\Admin\AdminAPIController::class,
        \Stride\Modules\Edition\EditionService::class,
        \Stride\Modules\Edition\SessionService::class,
        \Stride\Modules\Edition\SessionSelectionService::class,
        \Stride\Modules\Edition\Admin\EditionAdminController::class,
        \Stride\Modules\Enrollment\EnrollmentService::class,
        \Stride\Modules\Invoicing\QuoteService::class,
        \Stride\Modules\Invoicing\VoucherService::class,
        \Stride\Modules\Invoicing\Admin\QuoteAdminController::class,
        \Stride\Modules\Invoicing\Admin\VoucherAdminController::class,
        \Stride\Modules\Trajectory\TrajectoryService::class,
        \Stride\Modules\Trajectory\TrajectorySelectionService::class,
        \Stride\Modules\Trajectory\Admin\TrajectoryAdminController::class,
        \Stride\Modules\Attendance\AttendanceService::class,
        \Stride\Modules\Completion\CompletionService::class,
        \Stride\Modules\Audit\AuditBridge::class,
    ],

    /**
     * Module configuration
     */
    'modules' => [
        'edition' => [],
        'enrollment' => [],
        'invoicing' => [],
        'trajectory' => [],
        'attendance' => [],
        'completion' => [],
    ],
];
