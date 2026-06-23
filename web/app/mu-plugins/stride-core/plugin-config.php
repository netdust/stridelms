<?php

declare(strict_types=1);

/**
 * Stride Core Plugin Configuration
 *
 * Service registration and DI bindings.
 */

use Stride\Integrations\LearnDash\LearnDashService;
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
        LMSAdapterInterface::class => LearnDashService::class,
        EditionQueryInterface::class => EditionService::class,
    ],

    /**
     * Services to auto-register
     *
     * Services are loaded in order, by priority from metadata().
     */
    'services' => [
        \Stride\Integrations\LearnDash\LearnDashService::class,
        \Stride\Admin\AdminDashboardService::class,
        \Stride\Admin\StrideToolsService::class,
        \Stride\Modules\Membership\MembershipService::class,
        \Stride\Modules\Edition\EditionService::class,
        \Stride\Modules\Edition\EditionDuplicator::class,
        \Stride\Modules\Edition\CourseEnrollHandler::class,
        \Stride\Modules\Enrollment\EnrollmentService::class,
        \Stride\Modules\Questionnaire\QuestionnaireService::class,
        \Stride\Modules\Trajectory\TrajectoryService::class,
        \Stride\Modules\Attendance\AttendanceService::class,
        \Stride\Modules\Invoicing\QuoteService::class,
        \Stride\Modules\Notification\NotificationService::class,
        \Stride\Modules\Audit\AuditBridge::class,
        \Stride\Modules\Mail\StrideMailBridge::class,
        \Stride\Modules\PartnerAPI\PartnerAPIController::class,
        \Stride\Modules\User\ProfileTypeService::class,
        \Stride\Modules\User\UserLifecycleService::class,
        \Stride\Modules\Assistant\ReadAbilityRegistrar::class,
        \Stride\Modules\Assistant\WriteAbilityRegistrar::class,
        \Stride\Modules\Reporting\AnnualReportService::class,
        \Stride\Modules\Reporting\AnnualReportPdfGenerator::class,
        \Stride\Modules\Reporting\Admin\AnnualReportPage::class,
        \Stride\Admin\AdminRegistrationQueryService::class,
        \Stride\Admin\AdminStatsService::class,
        \Stride\Admin\AdminUserService::class,
        \Stride\Admin\AdminTrajectoryService::class,
        \Stride\Admin\AdminEditionRosterService::class,
    ],

    /**
     * Module configuration
     */
    'modules' => [
        'learndash' => [],
        'admin_dashboard' => [],
        'edition' => [],
        'enrollment' => [],
        'trajectory' => [],
        'attendance' => [],
        'invoicing' => [],
        'notification' => [],
        'audit' => [],
        'partner_api' => [],
    ],
];
