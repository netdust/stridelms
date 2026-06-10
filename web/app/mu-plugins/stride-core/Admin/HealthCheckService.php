<?php

declare(strict_types=1);

namespace Stride\Admin;

final class HealthCheckService
{
    private const STALE_THRESHOLD = 86400; // 24 hours

    /**
     * @return array{registration: string, mail: string}
     */
    public function evaluate(int $lastRegistration, int $lastMailSend, bool $hasOpenEditions): array
    {
        $now = time();
        $registrationOk = !$hasOpenEditions || ($lastRegistration > 0 && ($now - $lastRegistration) < self::STALE_THRESHOLD);
        $mailOk = $lastMailSend > 0 && ($now - $lastMailSend) < self::STALE_THRESHOLD;

        return [
            'registration' => $registrationOk ? 'green' : 'amber',
            'mail' => $mailOk ? 'green' : 'amber',
        ];
    }
}
