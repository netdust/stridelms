<?php

declare(strict_types=1);

namespace Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use Stride\Admin\StrideSettingsService;

/**
 * Task 5.2 — gate_reminder_days is exposed on the Notifications settings tab
 * and its value is clamped [1,365] on READ (defensive: a value written before
 * the clamp existed, or edited directly in the DB, still reads clamped).
 *
 * Covers StrideSettingsService::getNotificationRules() read-path clamp.
 * The save-path clamp (handleSaveSettings → saveNotificationSettings) plus the
 * gated-save denial path is covered by the Integration test
 * (tests/Integration/GateReminderDaysSaveTest.php) since it needs the real
 * capability/nonce-gated filter chain.
 */
class GateReminderDaysClampTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        global $_test_options;
        $_test_options = [];
    }

    protected function tearDown(): void
    {
        global $_test_options;
        $_test_options = [];
        parent::tearDown();
    }

    private function seedGateReminderValue(int|string $rawValue): void
    {
        global $_test_options;
        $_test_options['stride_notification_rules'] = [
            'gate_reminder_days' => [
                'enabled' => true,
                'value' => $rawValue,
            ],
        ];
    }

    public function test_zero_clamps_to_minimum_one(): void
    {
        $this->seedGateReminderValue(0);

        $rules = StrideSettingsService::getNotificationRules();

        $this->assertSame(1, $rules['gate_reminder_days']['value']);
    }

    public function test_negative_value_clamps_to_minimum_one(): void
    {
        $this->seedGateReminderValue(-5);

        $rules = StrideSettingsService::getNotificationRules();

        $this->assertSame(1, $rules['gate_reminder_days']['value']);
    }

    public function test_large_value_clamps_to_maximum_365(): void
    {
        $this->seedGateReminderValue(99999);

        $rules = StrideSettingsService::getNotificationRules();

        $this->assertSame(365, $rules['gate_reminder_days']['value']);
    }

    public function test_non_numeric_value_clamps_to_minimum_one(): void
    {
        $this->seedGateReminderValue('abc');

        $rules = StrideSettingsService::getNotificationRules();

        $this->assertSame(1, $rules['gate_reminder_days']['value']);
    }

    public function test_in_range_value_is_unchanged(): void
    {
        $this->seedGateReminderValue(7);

        $rules = StrideSettingsService::getNotificationRules();

        $this->assertSame(7, $rules['gate_reminder_days']['value']);
    }
}
