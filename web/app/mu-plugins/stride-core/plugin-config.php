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
        \Stride\Modules\Edition\EditionService::class,
        \Stride\Modules\Enrollment\EnrollmentService::class,
        \Stride\Modules\Invoicing\QuoteService::class,
        \Stride\Modules\Invoicing\VoucherService::class,
    ],

    /**
     * Module configuration
     */
    'modules' => [
        'edition' => [],
        'enrollment' => [],
        'invoicing' => [],
        'trajectory' => [],
    ],
];
