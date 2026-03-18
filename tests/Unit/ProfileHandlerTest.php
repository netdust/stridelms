<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Handlers\ProfileHandler;
use Stride\Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Unit tests for ProfileHandler
 *
 * Tests the profile update handler methods for personal,
 * billing, and notification preferences.
 */
class ProfileHandlerTest extends TestCase
{
    private ProfileHandler $handler;
    private ReflectionMethod $updatePersonal;
    private ReflectionMethod $updateBilling;
    private ReflectionMethod $updateNotifications;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset current user
        global $_test_current_user_id;
        $_test_current_user_id = 1;

        // Create handler instance
        $this->handler = new ProfileHandler();

        // Use reflection to access private methods
        $reflection = new ReflectionClass(ProfileHandler::class);

        $this->updatePersonal = $reflection->getMethod('updatePersonal');
        $this->updatePersonal->setAccessible(true);

        $this->updateBilling = $reflection->getMethod('updateBilling');
        $this->updateBilling->setAccessible(true);

        $this->updateNotifications = $reflection->getMethod('updateNotifications');
        $this->updateNotifications->setAccessible(true);
    }

    /**
     * Call private updatePersonal method
     */
    private function callUpdatePersonal(int $userId, array $params): mixed
    {
        return $this->updatePersonal->invoke($this->handler, $userId, $params);
    }

    /**
     * Call private updateBilling method
     */
    private function callUpdateBilling(int $userId, array $params): mixed
    {
        return $this->updateBilling->invoke($this->handler, $userId, $params);
    }

    /**
     * Call private updateNotifications method
     */
    private function callUpdateNotifications(int $userId, array $params): mixed
    {
        return $this->updateNotifications->invoke($this->handler, $userId, $params);
    }

    // =========================================================================
    // HANDLE UPDATE PROFILE (ROUTER)
    // =========================================================================

    /**
     * @test
     */
    public function testHandleUpdateProfileRequiresLogin(): void
    {
        global $_test_current_user_id;
        $_test_current_user_id = 0;

        $handler = new ProfileHandler();
        $result = $handler->handleUpdateProfile([], ['form_type' => 'personal']);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_logged_in', $result->get_error_code());
    }

    /**
     * @test
     */
    public function testHandleUpdateProfileRoutesToPersonal(): void
    {
        $user = $this->createUser(['ID' => 1]);

        $handler = new ProfileHandler();
        $result = $handler->handleUpdateProfile([], [
            'form_type' => 'personal',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Persoonlijke', $result['message']);
    }

    /**
     * @test
     */
    public function testHandleUpdateProfileRoutesToBilling(): void
    {
        $user = $this->createUser(['ID' => 1]);

        $handler = new ProfileHandler();
        $result = $handler->handleUpdateProfile([], [
            'form_type' => 'billing',
            'company' => 'Test Company',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Facturatie', $result['message']);
    }

    /**
     * @test
     */
    public function testHandleUpdateProfileRoutesToNotifications(): void
    {
        $user = $this->createUser(['ID' => 1]);

        $handler = new ProfileHandler();
        $result = $handler->handleUpdateProfile([], [
            'form_type' => 'notifications',
            'notify_reminders' => '1',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Meldingsvoorkeuren', $result['message']);
    }

    // =========================================================================
    // PERSONAL PROFILE
    // =========================================================================

    /**
     * @test
     */
    public function testUpdatePersonalUpdatesUserData(): void
    {
        $user = $this->createUser(['ID' => 10]);

        $result = $this->callUpdatePersonal(10, [
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'phone' => '+31612345678',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Check user meta was updated
        $this->assertUserMeta(10, 'phone', '+31612345678');
    }

    /**
     * @test
     */
    public function testUpdatePersonalSanitizesInput(): void
    {
        $user = $this->createUser(['ID' => 11]);

        $result = $this->callUpdatePersonal(11, [
            'first_name' => '<script>alert("xss")</script>Jane',
            'last_name' => 'Smith<br>',
            'phone' => '  +31612345678  ',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Phone should be trimmed
        $this->assertUserMeta(11, 'phone', '+31612345678');
    }

    // =========================================================================
    // BILLING PROFILE
    // =========================================================================

    /**
     * @test
     */
    public function testUpdateBillingUpdatesAllFields(): void
    {
        $user = $this->createUser(['ID' => 20]);

        $result = $this->callUpdateBilling(20, [
            'company' => 'Acme Corp',
            'vat_number' => 'NL123456789B01',
            'address' => 'Main Street 1',
            'postal_code' => '1234 AB',
            'city' => 'Amsterdam',
            'invoice_email' => 'billing@acme.com',
            'gln_number' => '1234567890123',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Check all fields were saved to billing meta keys
        $this->assertUserMeta(20, 'billing_company', 'Acme Corp');
        $this->assertUserMeta(20, 'billing_vat', 'NL123456789B01');
        $this->assertUserMeta(20, 'billing_address_1', 'Main Street 1');
        $this->assertUserMeta(20, 'billing_postcode', '1234 AB');
        $this->assertUserMeta(20, 'billing_city', 'Amsterdam');
        $this->assertUserMeta(20, 'invoice_email', 'billing@acme.com');
        $this->assertUserMeta(20, 'gln_number', '1234567890123');
    }

    /**
     * @test
     */
    public function testUpdateBillingHandlesEmptyFields(): void
    {
        $user = $this->createUser(['ID' => 21]);

        $result = $this->callUpdateBilling(21, [
            'company' => '',
            'invoice_email' => '',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Empty values should still be saved (clearing the field)
        $this->assertUserMeta(21, 'billing_company', '');
    }

    // =========================================================================
    // NOTIFICATION PREFERENCES
    // =========================================================================

    /**
     * @test
     */
    public function testUpdateNotificationsHandlesCheckboxes(): void
    {
        $user = $this->createUser(['ID' => 30]);

        $result = $this->callUpdateNotifications(30, [
            'notify_reminders' => '1',
            'notify_new_courses' => '1',
            // notify_newsletter not set (unchecked)
            'communication_language' => 'nl',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Checked boxes should be 'yes'
        $this->assertUserMeta(30, 'stride_notify_reminders', 'yes');
        $this->assertUserMeta(30, 'stride_notify_new_courses', 'yes');

        // Unchecked box should be 'no'
        $this->assertUserMeta(30, 'stride_notify_newsletter', 'no');

        // Language should be saved
        $this->assertUserMeta(30, 'stride_communication_language', 'nl');
    }

    /**
     * @test
     */
    public function testUpdateNotificationsValidatesLanguage(): void
    {
        $user = $this->createUser(['ID' => 31]);

        $result = $this->callUpdateNotifications(31, [
            'communication_language' => 'invalid_lang',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Invalid language should default to 'nl'
        $this->assertUserMeta(31, 'stride_communication_language', 'nl');
    }

    /**
     * @test
     * @dataProvider validLanguageProvider
     */
    public function testUpdateNotificationsAcceptsValidLanguages(string $lang): void
    {
        $user = $this->createUser(['ID' => 32 + ord($lang[0])]);
        $userId = 32 + ord($lang[0]);

        $result = $this->callUpdateNotifications($userId, [
            'communication_language' => $lang,
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertUserMeta($userId, 'stride_communication_language', $lang);
    }

    public static function validLanguageProvider(): array
    {
        return [
            'dutch' => ['nl'],
            'french' => ['fr'],
            'english' => ['en'],
        ];
    }

    /**
     * @test
     */
    public function testUpdateNotificationsAllUnchecked(): void
    {
        $user = $this->createUser(['ID' => 40]);

        // No checkboxes set = all unchecked
        $result = $this->callUpdateNotifications(40, []);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        $this->assertUserMeta(40, 'stride_notify_reminders', 'no');
        $this->assertUserMeta(40, 'stride_notify_new_courses', 'no');
        $this->assertUserMeta(40, 'stride_notify_newsletter', 'no');
    }
}
