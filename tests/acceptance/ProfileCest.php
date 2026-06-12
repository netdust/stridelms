<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Profile Page Acceptance Tests
 *
 * Tests the profile page at /mijn-account/?tab=profiel
 *
 * The profile uses Alpine.js inline-edit sections (inlineEditSection component).
 * Fields are NOT in traditional HTML forms with name attributes.
 * Instead, they use x-model bindings and saveEdit() via ntdstAPI.
 *
 * Sections:
 * - Personal: first_name, last_name, phone, organisation, department
 * - Billing: company, vat_number, address, postal_code, city, invoice_email, gln_number
 * - Notifications: notify_reminders, notify_new_courses, notify_newsletter, communication_language
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

        $I->amOnPage('/mijn-account/?tab=profiel');

        // page-mijn-account.php redirects to login if not authenticated
        $I->seeInCurrentUrl('aanmelden');
    }

    /**
     * @test
     */
    public function loggedInUserSeesProfilePage(AcceptanceTester $I): void
    {
        $I->wantTo('verify logged in users see the profile page');

        $I->loginAsUserId($this->testUserId, '/mijn-account/?tab=profiel');

        // Wait for page and Alpine to initialize
        $I->waitForElement('body', 10);

        // Should see profile section headings (Dutch)
        $I->see('Persoonlijke gegevens');
        $I->see('Facturatiegegevens');
    }

    // =========================================================================
    // PERSONAL PROFILE
    // =========================================================================

    /**
     * @test
     */
    public function personalSectionShowsUserData(AcceptanceTester $I): void
    {
        $I->wantTo('verify personal section displays user data');

        $I->loginAsUserId($this->testUserId, '/mijn-account/?tab=profiel');
        $I->waitForElement('body', 10);

        // The profile page renders the user's name in display mode
        $I->see('Persoonlijke gegevens');

        // No fatal errors
        $I->dontSee('Fatal error');
    }

    /**
     * @test
     *
     * The profile uses inlineEditSection Alpine component.
     * Editing flow: click "Bewerken" -> fields become editable -> fill -> click "Opslaan"
     * This saves via ntdstAPI.call('stride_update_profile', { form_type: 'personal', ... })
     */
    public function userCanUpdatePersonalProfile(AcceptanceTester $I): void
    {
        $I->wantTo('update my personal profile information');

        $I->loginAsUserId($this->testUserId, '/mijn-account/?tab=profiel');
        $I->waitForElement('body', 10);

        // Click the edit button for personal section
        // The "Bewerken" button is inside a <template x-if="!editing"> so we click it via text
        $I->click('Bewerken');

        // Wait for edit mode to show input fields (Alpine x-show transition)
        $I->waitForElement('input[type="text"]', 5);

        // Fill personal fields via Alpine data (x-model bound, no name attributes)
        $I->executeJS("
            const sections = document.querySelectorAll('[x-data]');
            for (const section of sections) {
                const data = Alpine.\$data(section);
                if (data && data.fields && 'first_name' in data.fields) {
                    data.fields.first_name = 'Updated';
                    data.fields.last_name = 'Person';
                    data.fields.phone = '+31612345678';
                    data.saveEdit();
                    break;
                }
            }
        ");

        // Wait for API response
        $I->wait(3);

        // Should not see fatal errors
        $I->dontSee('Fatal error');

        // Verify in database
        $I->seeUserMetaInDatabase([
            'user_id' => $this->testUserId,
            'meta_key' => 'first_name',
            'meta_value' => 'Updated',
        ]);
    }

    // =========================================================================
    // BILLING PROFILE
    // =========================================================================

    /**
     * @test
     */
    public function billingSectionIsVisible(AcceptanceTester $I): void
    {
        $I->wantTo('verify billing section is visible on profile page');

        $I->loginAsUserId($this->testUserId, '/mijn-account/?tab=profiel');
        $I->waitForElement('body', 10);

        // Billing section heading
        $I->see('Facturatiegegevens');
        $I->dontSee('Fatal error');
    }

    /**
     * @test
     */
    public function userCanUpdateBillingProfile(AcceptanceTester $I): void
    {
        $I->wantTo('update my billing information');

        $I->loginAsUserId($this->testUserId, '/mijn-account/?tab=profiel');
        $I->waitForElement('body', 10);

        // Update billing via Alpine data
        $I->executeJS("
            const sections = document.querySelectorAll('[x-data]');
            for (const section of sections) {
                const data = Alpine.\$data(section);
                if (data && data.fields && 'company' in data.fields) {
                    data.fields.company = 'Test Company BV';
                    data.fields.vat_number = 'NL123456789B01';
                    data.fields.address = 'Teststraat 123';
                    data.fields.postal_code = '1234 AB';
                    data.fields.city = 'Amsterdam';
                    data.fields.invoice_email = 'billing@test.local';
                    data.saveEdit();
                    break;
                }
            }
        ");

        // Wait for API response
        $I->wait(3);

        // Should not see fatal errors
        $I->dontSee('Fatal error');

        // Verify billing company in database (mapped to billing_company meta key)
        $I->seeUserMetaInDatabase([
            'user_id' => $this->testUserId,
            'meta_key' => 'billing_company',
            'meta_value' => 'Test Company BV',
        ]);
    }

    // =========================================================================
    // NOTIFICATION PREFERENCES
    // =========================================================================

    /**
     * @test
     */
    public function notificationSectionIsVisible(AcceptanceTester $I): void
    {
        $I->wantTo('verify notification preferences section is visible');

        $I->loginAsUserId($this->testUserId, '/mijn-account/?tab=profiel');
        $I->waitForElement('body', 10);

        // Notification section heading
        $I->see('Meldingsvoorkeuren');
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // PRIVACY & GDPR
    // =========================================================================

    /**
     * @test
     */
    public function privacySectionIsVisible(AcceptanceTester $I): void
    {
        $I->wantTo('verify privacy & GDPR section is visible on profile page');

        $I->loginAsUserId($this->testUserId, '/mijn-account/?tab=profiel');
        $I->waitForElement('body', 10);

        $I->see('Privacy');
        $I->dontSee('Fatal error');
    }
}
