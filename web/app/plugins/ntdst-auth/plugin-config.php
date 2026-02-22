<?php

declare(strict_types=1);

return [
    'services' => [
        \NTDST\Auth\SettingsService::class,
        \NTDST\Auth\TokenService::class,
        \NTDST\Auth\ConsentService::class,
        \NTDST\Auth\RegistrationService::class,
        \NTDST\Auth\AuthService::class,
        \NTDST\Auth\Handlers\AuthHandler::class,
    ],
];
