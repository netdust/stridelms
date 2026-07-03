<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stride\Admin\ActionQueueService;

/**
 * Task 5.1 — guards that `gate_reminder_days` exists in
 * ActionQueueService::DEFAULTS so StrideSettingsService::getNotificationRules()
 * returns it (read by GateReminderService, not evaluated as an action-queue
 * rule — no evaluate() branch is added for it).
 */
class ActionQueueDefaultsTest extends TestCase
{
    public function test_gate_reminder_days_default_is_registered(): void
    {
        $this->assertSame(
            ['enabled' => true, 'value' => 7],
            ActionQueueService::DEFAULTS['gate_reminder_days'] ?? null,
        );
    }
}
