<?php

declare(strict_types=1);

/**
 * Stride Core Plugin Configuration
 *
 * Service registration and DI bindings.
 */

use Stride\Adapters\LearnDashAdapter;
use Stride\Contracts\LMSAdapterInterface;

return [
    /**
     * DI Container Bindings
     *
     * Interface => Implementation mappings
     */
    'bindings' => [
        LMSAdapterInterface::class => LearnDashAdapter::class,
    ],

    /**
     * Services to auto-register
     *
     * Services are loaded in order, by priority from metadata().
     */
    'services' => [
        // Core services will be added as modules are built
    ],

    /**
     * Module configuration
     */
    'modules' => [
        'edition' => [
            // Edition module config
        ],
        'enrollment' => [
            // Enrollment module config
        ],
        'invoicing' => [
            // Invoicing module config
        ],
        'trajectory' => [
            // Trajectory module config
        ],
    ],
];
