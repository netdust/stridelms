<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use Stride\Handlers\ProfileHandler;
use IntegrationTestCase;

/**
 * Integration tests for ProfileHandler
 *
 * Tests profile updates against real WordPress database.
 * Run: ddev exec vendor/bin/phpunit --testsuite Integration --filter ProfileHandler
 */
class ProfileHandlerIntegrationTest extends IntegrationTestCase
{
    private ProfileHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new ProfileHandler();
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        // Clean up meta keys used in tests
        $this->cleanupUserMeta(self::$testUserId, [
            'phone',
            'first_name',
            'last_name',
            'invoice_organization_name',
            'vat_number',
            'invoice_address',
            'invoice_postal_code',
            'invoice_city',
            'invoice_email',
            'gln_number',
            'company',
            'address_line_1',
            'postal_code',
            'city',
            'stride_notify_reminders',
            'stride_notify_new_courses',
            'stride_notify_newsletter',
            'stride_communication_language',
        ]);

        parent::tearDown();
    }

    // =========================================================================
    // AUTHENTICATION
    // =========================================================================

    /**
     * @test
     */
    public function rejectsUnauthenticatedUser(): void
    {
        wp_set_current_user(0);

        $result = $this->handler->handleUpdateProfile([], ['form_type' => 'personal']);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_logged_in', $result->get_error_code());
    }

    // =========================================================================
    // PERSONAL PROFILE - REAL DATABASE
    // =========================================================================

    /**
     * @test
     */
    public function updatesPersonalProfileInDatabase(): void
    {
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'personal',
            'first_name' => 'Integration',
            'last_name' => 'Tester',
            'phone' => '+31687654321',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Verify in actual database
        $this->assertUserMeta(self::$testUserId, 'phone', '+31687654321');
        $this->assertUserMeta(self::$testUserId, 'first_name', 'Integration');
        $this->assertUserMeta(self::$testUserId, 'last_name', 'Tester');
    }

    /**
     * @test
     */
    public function sanitizesPersonalProfileInput(): void
    {
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'personal',
            'first_name' => '<script>alert("xss")</script>Test',
            'last_name' => 'User<br>',
            'phone' => '  +31612345678  ',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Verify sanitized values in database
        $phone = get_user_meta(self::$testUserId, 'phone', true);
        $this->assertEquals('+31612345678', $phone, 'Phone should be trimmed');

        // First/last name go through wp_update_user which sanitizes
        $user = get_userdata(self::$testUserId);
        $this->assertStringNotContainsString('<script>', $user->first_name);
    }

    // =========================================================================
    // BILLING PROFILE - REAL DATABASE
    // =========================================================================

    /**
     * @test
     */
    public function updatesBillingProfileInDatabase(): void
    {
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'billing',
            'company' => 'Test Company BV',
            'vat_number' => 'NL123456789B01',
            'address' => 'Teststraat 123',
            'postal_code' => '1234 AB',
            'city' => 'Amsterdam',
            'invoice_email' => 'billing@test.local',
            'gln_number' => '1234567890123',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Verify billing meta fields
        $this->assertUserMeta(self::$testUserId, 'billing_company', 'Test Company BV');
        $this->assertUserMeta(self::$testUserId, 'billing_vat', 'NL123456789B01');
        $this->assertUserMeta(self::$testUserId, 'billing_address_1', 'Teststraat 123');
        $this->assertUserMeta(self::$testUserId, 'billing_postcode', '1234 AB');
        $this->assertUserMeta(self::$testUserId, 'billing_city', 'Amsterdam');
        $this->assertUserMeta(self::$testUserId, 'invoice_email', 'billing@test.local');
        $this->assertUserMeta(self::$testUserId, 'gln_number', '1234567890123');
    }

    /**
     * @test
     */
    public function handlesEmptyBillingFields(): void
    {
        // First set some values
        update_user_meta(self::$testUserId, 'billing_company', 'Old Company');

        // Then clear them
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'billing',
            'company' => '',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Empty string should be saved (clearing the field)
        $this->assertUserMeta(self::$testUserId, 'billing_company', '');
    }

    // =========================================================================
    // NOTIFICATION PREFERENCES - REAL DATABASE
    // =========================================================================

    /**
     * @test
     */
    public function updatesNotificationPreferencesInDatabase(): void
    {
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'notifications',
            'notify_reminders' => '1',
            'notify_new_courses' => '1',
            // notify_newsletter intentionally omitted (unchecked)
            'communication_language' => 'nl',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Checked boxes should be 'yes'
        $this->assertUserMeta(self::$testUserId, 'stride_notify_reminders', 'yes');
        $this->assertUserMeta(self::$testUserId, 'stride_notify_new_courses', 'yes');

        // Unchecked box should be 'no'
        $this->assertUserMeta(self::$testUserId, 'stride_notify_newsletter', 'no');

        // Language saved
        $this->assertUserMeta(self::$testUserId, 'stride_communication_language', 'nl');
    }

    /**
     * @test
     */
    public function validatesLanguageAndDefaultsToNl(): void
    {
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'notifications',
            'communication_language' => 'invalid_language_code',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // Invalid language should default to 'nl'
        $this->assertUserMeta(self::$testUserId, 'stride_communication_language', 'nl');
    }

    /**
     * @test
     * @dataProvider validLanguageProvider
     */
    public function acceptsValidLanguages(string $lang): void
    {
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'notifications',
            'communication_language' => $lang,
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertUserMeta(self::$testUserId, 'stride_communication_language', $lang);
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
    public function handlesAllNotificationsUnchecked(): void
    {
        // First enable all
        update_user_meta(self::$testUserId, 'stride_notify_reminders', 'yes');
        update_user_meta(self::$testUserId, 'stride_notify_new_courses', 'yes');
        update_user_meta(self::$testUserId, 'stride_notify_newsletter', 'yes');

        // Submit with no checkboxes (all unchecked)
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'notifications',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);

        // All should now be 'no'
        $this->assertUserMeta(self::$testUserId, 'stride_notify_reminders', 'no');
        $this->assertUserMeta(self::$testUserId, 'stride_notify_new_courses', 'no');
        $this->assertUserMeta(self::$testUserId, 'stride_notify_newsletter', 'no');
    }

    // =========================================================================
    // FORM TYPE ROUTING
    // =========================================================================

    /**
     * @test
     */
    public function routesToCorrectHandler(): void
    {
        // Personal
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'personal',
            'first_name' => 'Test',
        ]);
        $this->assertStringContainsString('Persoonlijke', $result['message']);

        // Billing
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'billing',
            'billing_company' => 'Test',
        ]);
        $this->assertStringContainsString('Facturatie', $result['message']);

        // Notifications
        $result = $this->handler->handleUpdateProfile([], [
            'form_type' => 'notifications',
        ]);
        $this->assertStringContainsString('Meldingsvoorkeuren', $result['message']);
    }

    /**
     * @test
     */
    public function defaultsToPersonalFormType(): void
    {
        $result = $this->handler->handleUpdateProfile([], [
            // No form_type specified
            'first_name' => 'Default',
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Persoonlijke', $result['message']);
    }
}
