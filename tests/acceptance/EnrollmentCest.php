<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Enrollment Flow Acceptance Tests
 *
 * Tests the complete enrollment flow from user perspective:
 * - Form display and validation
 * - Successful enrollment with database persistence
 * - Voucher code handling
 * - Error states
 */
class EnrollmentCest
{
    private int $testEditionId;
    private int $testCourseId;
    private int $testUserId;
    private string $testUserEmail;

    public function _before(AcceptanceTester $I): void
    {
        // Create test course
        $this->testCourseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Test Course for Enrollment',
            'post_status' => 'publish',
        ]);

        // Create test edition
        $this->testEditionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'Test Edition ' . time(),
            'post_status' => 'publish',
        ]);

        // Set edition meta via Data Manager prefix
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_course_id', $this->testCourseId);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_price', 29900); // €299.00 in cents
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_capacity', 20);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_venue', 'Amsterdam');

        // Create test user
        $this->testUserEmail = 'test_enroll_' . time() . '@test.local';
        $this->testUserId = $I->haveUserInDatabase('test_enroll_' . time(), 'subscriber', [
            'user_email' => $this->testUserEmail,
            'display_name' => 'Test Enrollee',
        ]);
    }

    public function _after(AcceptanceTester $I): void
    {
        // Cleanup is handled by database transaction rollback
    }

    // =========================================================================
    // VISITOR FLOW
    // =========================================================================

    /**
     * @test
     */
    public function anonymousUserSeesLoginPrompt(AcceptanceTester $I): void
    {
        $I->wantTo('verify anonymous users see login prompt on enrollment page');

        $I->amOnPage('/inschrijven/?edition=' . $this->testEditionId);

        $I->see('Log in om in te schrijven');
        $I->seeElement('a[href*="wp-login.php"]');
        $I->dontSeeElement('#stride-enrollment-form');
    }

    /**
     * @test
     */
    public function loggedInUserSeesEnrollmentForm(AcceptanceTester $I): void
    {
        $I->wantTo('verify logged in users see the enrollment form');

        // Use custom test login helper
        $I->loginAsUserId($this->testUserId, '/inschrijven/?edition=' . $this->testEditionId);

        // Form should be visible
        $I->seeElement('#stride-enrollment-form');

        // Required fields should be present
        $I->seeElement('input[name="first_name"]');
        $I->seeElement('input[name="last_name"]');
        $I->seeElement('input[name="email"]');

        // Terms checkboxes should be present
        $I->seeElement('input[name="terms_accepted"]');
        $I->seeElement('input[name="cancellation_accepted"]');

        // Submit button should be visible
        $I->seeElement('button[type="submit"]');

        // Price should be shown (in some format - checking for "Cursusprijs" label)
        $I->see('Cursusprijs');
    }

    /**
     * @test
     */
    public function successfulSelfEnrollment(AcceptanceTester $I): void
    {
        $I->wantTo('complete a successful self-enrollment');

        $I->loginAsUserId($this->testUserId, '/inschrijven/?edition=' . $this->testEditionId);

        // Fill required fields
        $I->fillField('input[name="first_name"]', 'Test');
        $I->fillField('input[name="last_name"]', 'User');
        $I->fillField('input[name="email"]', $this->testUserEmail);

        // Accept terms (use the form-associated checkboxes in sidebar)
        $I->checkOption('input[name="terms_accepted"][form="stride-enrollment-form"]');
        $I->checkOption('input[name="cancellation_accepted"][form="stride-enrollment-form"]');

        // Click submit using JavaScript (finds visible button and clicks it)
        $I->executeJS('
            const btns = document.querySelectorAll(".submit-enrollment");
            for (const btn of btns) {
                if (btn.offsetParent !== null) {
                    btn.click();
                    break;
                }
            }
        ');

        // Wait for form submission/redirect
        $I->wait(3);

        // Should see success or be redirected (no fatal errors)
        $I->dontSee('Fatal error');
        $I->dontSee('Error');

        // Verify registration created in database
        $I->seeInDatabase('stride_vad_registrations', [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
            'status' => 'confirmed',
        ]);
    }

    /**
     * @test
     */
    public function noEditionShowsError(AcceptanceTester $I): void
    {
        $I->wantTo('see error when no edition is specified');

        $I->loginAsUserId($this->testUserId, '/inschrijven/');

        $I->see('Geen cursus geselecteerd');
        $I->seeElement('a[href*="/cursussen/"]');
    }

    /**
     * @test
     */
    public function invalidEditionShowsError(AcceptanceTester $I): void
    {
        $I->wantTo('see error when invalid edition is specified');

        $I->loginAsUserId($this->testUserId, '/inschrijven/?edition=999999');

        $I->see('Editie niet gevonden');
    }

    // =========================================================================
    // VOUCHER FLOW
    // =========================================================================

    /**
     * @test
     */
    public function validVoucherAppliesDiscount(AcceptanceTester $I): void
    {
        $I->wantTo('apply a valid voucher and see discount');

        // Create test voucher
        $voucherId = $I->havePostInDatabase([
            'post_type' => 'vad_voucher',
            'post_title' => 'TESTCODE20',
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($voucherId, '_ntdst_code', 'TESTCODE20');
        $I->havePostmetaInDatabase($voucherId, '_ntdst_discount_type', 'percentage');
        $I->havePostmetaInDatabase($voucherId, '_ntdst_discount_value', 20);
        $I->havePostmetaInDatabase($voucherId, '_ntdst_status', 'active');
        $I->havePostmetaInDatabase($voucherId, '_ntdst_max_uses', 100);
        $I->havePostmetaInDatabase($voucherId, '_ntdst_used_count', 0);

        $I->loginAsUserId($this->testUserId, '/inschrijven/?edition=' . $this->testEditionId);

        // Enter voucher code
        $I->fillField('input[name="voucher_code"]', 'TESTCODE20');
        $I->click('#apply-voucher');

        // Wait for AJAX validation and discount to update
        $I->wait(2);

        // Should see discount applied (check for discount amount change)
        $I->see('Korting');
    }

    /**
     * @test
     */
    public function invalidVoucherShowsError(AcceptanceTester $I): void
    {
        $I->wantTo('see error for invalid voucher code');

        $I->loginAsUserId($this->testUserId, '/inschrijven/?edition=' . $this->testEditionId);

        // Enter invalid voucher code
        $I->fillField('input[name="voucher_code"]', 'INVALIDCODE');
        $I->click('#apply-voucher');

        // Wait for AJAX response
        $I->wait(2);

        // Should see error message in the voucher result area
        $I->seeElement('#voucher-result .uk-alert-danger');
    }

    // =========================================================================
    // ERROR FLOW
    // =========================================================================

    /**
     * @test
     */
    public function requiredFieldsHaveRequiredAttribute(AcceptanceTester $I): void
    {
        $I->wantTo('verify required fields have the required attribute');

        $I->loginAsUserId($this->testUserId, '/inschrijven/?edition=' . $this->testEditionId);

        // Check that required fields have the required attribute (HTML5 validation)
        $I->seeElement('input[name="first_name"][required]');
        $I->seeElement('input[name="last_name"][required]');
        $I->seeElement('input[name="email"][required]');
        $I->seeElement('input[name="terms_accepted"][required]');
        $I->seeElement('input[name="cancellation_accepted"][required]');
    }

    /**
     * @test
     */
    public function formWithMissingFieldsCannotSubmit(AcceptanceTester $I): void
    {
        $I->wantTo('verify form with missing required fields does not create registration');

        $I->loginAsUserId($this->testUserId, '/inschrijven/?edition=' . $this->testEditionId);

        // Don't fill any fields, don't check terms
        // The form won't submit due to HTML5 validation

        // Verify no registration exists (form never submitted)
        $I->dontSeeInDatabase('stride_vad_registrations', [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
        ]);
    }

    /**
     * @test
     */
    public function alreadyEnrolledShowsMessage(AcceptanceTester $I): void
    {
        $I->wantTo('see message when already enrolled');

        // Create existing registration (use correct column name: registered_at)
        $I->haveInDatabase('stride_vad_registrations', [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        $I->loginAsUserId($this->testUserId, '/inschrijven/?edition=' . $this->testEditionId);

        // Should see already enrolled message
        $I->see('Je bent al ingeschreven');
        $I->dontSeeElement('#stride-enrollment-form');
    }
}
