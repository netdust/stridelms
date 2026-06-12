<?php

declare(strict_types=1);

namespace Stride\Admin;

final class HealthCheckService
{
    private const STALE_THRESHOLD = 86400; // 24 hours

    /**
     * @param bool $auditActive Whether the ntdst-audit AuditService class is
     *                          loaded (AF-2 residual: PII reveals proceed
     *                          UNLOGGED without it — red, not amber).
     * @return array{registration: string, mail: string, audit: string}
     */
    public function evaluate(int $lastRegistration, int $lastMailSend, bool $hasOpenEditions, bool $auditActive = true): array
    {
        $now = time();
        $registrationOk = !$hasOpenEditions || ($lastRegistration > 0 && ($now - $lastRegistration) < self::STALE_THRESHOLD);
        $mailOk = $lastMailSend > 0 && ($now - $lastMailSend) < self::STALE_THRESHOLD;

        return [
            'registration' => $registrationOk ? 'green' : 'amber',
            'mail' => $mailOk ? 'green' : 'amber',
            'audit' => $auditActive ? 'green' : 'red',
        ];
    }
}
