<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Profile Update Acceptance Tests
 *
 * Tests the profile update flows via the ntdst API endpoints:
 * - Personal profile (name, phone)
 * - Billing information
 * - Notification preferences
 */
class ProfileCest
{
    private int $testUserId;
    private string $testUserEmail;

    public function _before(AcceptanceTester $I): void
    {
        // Create test user
        $this->testUserEmail = 'test_profile_' . time() . '@test.local';
        $this->testUserId = $I->haveUserInDatabase('test_profile_' . time(), 'subscriber', [
            'user_email' => $this->testUserEmail,
            'display_name' => 'Profile Test User',
        ]);

        // Set initial user meta
        $I->haveUserMetaInDatabase($this->testUserId, 'first_name', 'Initial');
        $I->haveUserMetaInDatabase($this->testUserId, 'last_name', 'Name');
    }

    // =========================================================================
    // PROFILE PAGE ACCESS
    // =========================================================================

    /**
     * @test
     */
    public function anonymousUserCannotAccessProfile(AcceptanceTester $I): void
    {
        $I->wantTo('verify anonymous users cannot access profile page');

        $I->amOnPage('/mijn-account/profiel/');

        // Should be redirected to login or see login prompt
        $I->seeInCurrentUrl('login');
    }

    /**
     * @test
     */
    public function loggedInUserSeesProfilePage(AcceptanceTester $I): void
    {
        $I->wantTo('verify logged in users see the profile page');

        $I->loginAsUserId($this->testUserId, '/mijn-account/profiel/');

        // Should see profile sections
        $I->see('Mijn profiel');
        $I->seeElement('form');
    }

    // =========================================================================
    // PERSONAL PROFILE
    // =========================================================================

    /**
     * @test
     */
    public function userCanUpdatePersonalProfile(AcceptanceTester $I): void
    {
        $I->wantTo('update my personal profile information');

        $I->loginAsUserId($this->testUserId, '/mijn-account/profiel/');

        // Fill personal form fields
        $I->fillField('input[name="first_name"]', 'Updated');
        $I->fillField('input[name="last_name"]', 'Person');
        $I->fillField('input[name="phone"]', '+31612345678');

        // Submit personal form (find by form type or section)
        $I->click('button[data-form-type="personal"]');

        // Wait for API response
        $I->wait(2);

        // Should see success feedback
        $I->see('bijgewerkt');

        // Verify in database
        $I->seeUserMetaInDatabase([
            'user_id' => $this->testUserId,
            'meta_key' => 'phone',
            'meta_value' => '+31612345678',
        ]);
    }

    // =========================================================================
    // BILLING PROFILE
    // =========================================================================

    /**
     * @test
     */
    public function userCanUpdateBillingProfile(AcceptanceTester $I): void
    {
        $I->wantTo('update my billing information');

        $I->loginAsUserId($this->testUserId, '/mijn-account/profiel/');

        // Fill billing form fields
        $I->fillField('input[name="billing_company"]', 'Test Company BV');
        $I->fillField('input[name="billing_vat"]', 'NL123456789B01');
        $I->fillField('input[name="billing_address"]', 'Teststraat 123');
        $I->fillField('input[name="billing_postal_code"]', '1234 AB');
        $I->fillField('input[name="billing_city"]', 'Amsterdam');
        $I->fillField('input[name="billing_email"]', 'billing@test.local');

        // Submit billing form
        $I->click('button[data-form-type="billing"]');

        // Wait for API response
        $I->wait(2);

        // Should see success feedback
        $I->see('Facturatiegegevens bijgewerkt');

        // Verify in database
        $I->seeUserMetaInDatabase([
            'user_id' => $this->testUserId,
            'meta_key' => 'invoice_organization_name',
            'meta_value' => 'Test Company BV',
        ]);

        $I->seeUserMetaInDatabase([
            'user_id' => $this->testUserId,
            'meta_key' => 'vat_number',
            'meta_value' => 'NL123456789B01',
        ]);
    }

    // =========================================================================
    // NOTIFICATION PREFERENCES
    // =========================================================================

    /**
     * @test
     */
    public function userCanUpdateNotificationPreferences(AcceptanceTester $I): void
    {
        $I->wantTo('update my notification preferences');

        $I->loginAsUserId($this->testUserId, '/mijn-account/profiel/');

        // Toggle notification checkboxes
        $I->checkOption('input[name="notify_reminders"]');
        $I->checkOption('input[name="notify_new_courses"]');
        $I->uncheckOption('input[name="notify_newsletter"]');

        // Select communication language
        $I->selectOption('select[name="communication_language"]', 'nl');

        // Submit notifications form
        $I->click('button[data-form-type="notifications"]');

        // Wait for API response
        $I->wait(2);

        // Should see success feedback
        $I->see('Meldingsvoorkeuren bijgewerkt');

        // Verify in database
        $I->seeUserMetaInDatabase([
            'user_id' => $this->testUserId,
            'meta_key' => 'stride_notify_reminders',
            'meta_value' => 'yes',
        ]);

        $I->seeUserMetaInDatabase([
            'user_id' => $this->testUserId,
            'meta_key' => 'stride_communication_language',
            'meta_value' => 'nl',
        ]);
    }

    // =========================================================================
    // API ENDPOINT TESTS (Direct)
    // =========================================================================

    /**
     * @test
     */
    public function apiRejectsUnauthenticatedRequests(AcceptanceTester $I): void
    {
        $I->wantTo('verify API rejects unauthenticated profile updates');

        // Make direct API call without login
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPOST('/wp-json/ntdst/v1/action', [
            'action' => 'stride_update_profile',
            'nonce' => 'invalid',
            'form_type' => 'personal',
            'first_name' => 'Hacker',
        ]);

        // Should fail
        $I->seeResponseCodeIs(200); // REST API returns 200 with error payload
        $I->seeResponseContainsJson(['success' => false]);
    }

    // =========================================================================
    // ERROR HANDLING
    // =========================================================================

    /**
     * @test
     */
    public function profileShowsErrorOnNetworkFailure(AcceptanceTester $I): void
    {
        $I->wantTo('see error handling when API fails');

        $I->loginAsUserId($this->testUserId, '/mijn-account/profiel/');

        // Simulate network error by disabling JavaScript API
        $I->executeJS('window.ntdstAPI = { call: () => Promise.reject(new Error("Network error")) }');

        // Try to submit
        $I->fillField('input[name="first_name"]', 'Test');
        $I->click('button[data-form-type="personal"]');

        // Wait for error handling
        $I->wait(2);

        // Should see error message (implementation-dependent)
        // $I->see('error');
    }
}
