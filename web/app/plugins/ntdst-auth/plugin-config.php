<?php

declare(strict_types=1);

return [
    'services' => [
        \NTDST\Auth\AuthService::class,
        \NTDST\Auth\RegistrationService::class,
        \NTDST\Auth\Handlers\AuthHandler::class,
    ],
];
