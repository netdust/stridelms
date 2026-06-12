<?php

declare(strict_types=1);

return [
    'services' => [
        \NTDST\Audit\AuditService::class,
        \NTDST\Audit\Bridges\WPAuditBridge::class,
        \NTDST\Audit\Admin\AdminController::class,
        \NTDST\Audit\Admin\APIController::class,
    ],
];
