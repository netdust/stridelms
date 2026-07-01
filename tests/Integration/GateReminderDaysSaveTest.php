<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Admin\StrideSettingsService;

/**
 * Task 5.2 — gate_reminder_days rides the EXISTING gated notifications save
 * (StrideSettingsService::handleSaveSettings, behind manage_options).
 *
 * Covers the threat-model A3 mitigations:
 *   1. Capability-gated save: only manage_options can persist the setting.
 *   2. Clamp [1,365] applied on the real save path (not just at read time).
 *   3. A non-privileged actor's save attempt is denied and does NOT persist.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter GateReminderDaysSaveTest
 */
final class GateReminderDaysSaveTest extends IntegrationTestCase
{
    private const OPTION_NOTIFICATIONS = 'stride_notification_rules';

    protected function setUp(): void
    {
        parent::setUp();
        delete_option(self::OPTION_NOTIFICATIONS);
    }

    protected function tearDown(): void
    {
        delete_option(self::OPTION_NOTIFICATIONS);
        $user = get_user_by('ID', self::$testUserId);
        if ($user) {
            $user->set_role('subscriber');
        }
        wp_set_current_user(0);
        parent::tearDown();
    }

    /**
     * @test
     */
    public function adminSavingOutOfRangeValuePersistsClampedResult(): void
    {
        $user = get_user_by('ID', self::$testUserId);
        $user->set_role('administrator');
        $this->actingAs(self::$testUserId);

        $service = new StrideSettingsService();

        $result = $service->handleSaveSettings(null, [
            'tab' => 'notifications',
            'gate_reminder_days_enabled' => '1',
            'gate_reminder_days_value' => '99999',
        ]);

        $this->assertIsArray($result, 'Admin save should succeed, not return a WP_Error');

        $rules = StrideSettingsService::getNotificationRules();
        $this->assertSame(365, $rules['gate_reminder_days']['value'], 'Out-of-range value must clamp to 365 on the real save path');
        $this->assertTrue($rules['gate_reminder_days']['enabled']);
    }

    /**
     * @test
     */
    public function adminSavingNegativeValuePersistsClampedMinimum(): void
    {
        $user = get_user_by('ID', self::$testUserId);
        $user->set_role('administrator');
        $this->actingAs(self::$testUserId);

        $service = new StrideSettingsService();

        $result = $service->handleSaveSettings(null, [
            'tab' => 'notifications',
            'gate_reminder_days_enabled' => '1',
            'gate_reminder_days_value' => '-5',
        ]);

        $this->assertIsArray($result, 'Admin save should succeed, not return a WP_Error');

        $rules = StrideSettingsService::getNotificationRules();
        $this->assertSame(1, $rules['gate_reminder_days']['value'], 'Negative value must clamp to the minimum of 1 on the real save path');
    }

    /**
     * @test
     */
    public function nonPrivilegedUserCannotPersistSetting(): void
    {
        $user = get_user_by('ID', self::$testUserId);
        $user->set_role('subscriber');
        $this->actingAs(self::$testUserId);

        $service = new StrideSettingsService();

        $result = $service->handleSaveSettings(null, [
            'tab' => 'notifications',
            'gate_reminder_days_enabled' => '1',
            'gate_reminder_days_value' => '42',
        ]);

        $this->assertInstanceOf(
            \WP_Error::class,
            $result,
            'Non-privileged actor must be denied (WP_Error), not silently succeed',
        );

        $rules = StrideSettingsService::getNotificationRules();
        $this->assertSame(
            7,
            $rules['gate_reminder_days']['value'],
            'Denied save must NOT persist — value should remain the untouched default',
        );
    }
}
