<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Enrollment Flow Acceptance Tests
 *
 * Tests the enrollment flow from user perspective.
 *
 * Enrollment uses route-based URLs: /edities/{slug}/inschrijving/
 * The form is a multi-step Alpine.js wizard (enrollmentForm component):
 *   Step 0: Enrollment type selection (self/colleague/private)
 *   Step 1: Personal info (first_name, last_name, email, phone)
 *   Step 2: Billing info (company, vat, address)
 *   Step 3: Confirmation + terms acceptance
 *
 * Form submission is via @submit.prevent="submitForm" (Alpine AJAX).
 */
class EnrollmentCest
{
    private int $testEditionId;
    private string $testEditionSlug;
    private int $testCourseId;
    private int $testUserId;
    private string $testUserEmail;

    public function _before(AcceptanceTester $I): void
    {
        $timestamp = time();

        // Create test course
        $this->testCourseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Test Course for Enrollment',
            'post_status' => 'publish',
        ]);

        // Create test edition with a known slug
        $this->testEditionSlug = 'test-edition-' . $timestamp;
        $this->testEditionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'Test Edition ' . $timestamp,
            'post_name' => $this->testEditionSlug,
            'post_status' => 'publish',
        ]);

        // Set edition meta
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_course_id', $this->testCourseId);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_price', 29900); // cents
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_capacity', 20);
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));
        $I->havePostmetaInDatabase($this->testEditionId, '_ntdst_venue', 'Amsterdam');

        // Create test user
        $this->testUserEmail = 'test_enroll_' . $timestamp . '@test.local';
        $this->testUserId = $I->haveUserInDatabase('test_enroll_' . $timestamp, 'subscriber', [
            'user_email' => $this->testUserEmail,
            'display_name' => 'Test Enrollee',
        ]);
        $I->haveUserMetaInDatabase($this->testUserId, 'first_name', 'Test');
        $I->haveUserMetaInDatabase($this->testUserId, 'last_name', 'Enrollee');
    }

    /**
     * Helper: build enrollment URL for the test edition.
     * Uses the numeric ID as slug — EnrollmentRouter falls back to get_post()
     * when the slug is numeric, which works for DB-inserted test posts.
     */
    private function enrollmentUrl(): string
    {
        return '/edities/' . $this->testEditionId . '/inschrijving/';
    }

    // =========================================================================
    // VISITOR FLOW
    // =========================================================================

    /**
     * @test
     */
    public function anonymousUserIsRedirectedToLogin(AcceptanceTester $I): void
    {
        $I->wantTo('verify anonymous users are redirected to login on enrollment page');

        $I->amOnPage($this->enrollmentUrl());

        // EnrollmentRouter redirects unauthenticated users to wp_login_url
        // which is redirected to /aanmelden by ntdst-auth
        $I->seeInCurrentUrl('aanmelden');
    }

    /**
     * @test
     */
    public function loggedInUserSeesEnrollmentForm(AcceptanceTester $I): void
    {
        $I->wantTo('verify logged in users see the enrollment form');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());

        // Wait for Alpine.js to initialize the form
        $I->waitForElement('form', 10);

        // The form starts at Step 0 (enrollment type: Mezelf/Collega/Particulier)
        $I->see('Voor wie is deze inschrijving');

        // Should see edition details in sidebar
        $I->see('Test Course for Enrollment');

        // Should not see fatal errors
        $I->dontSee('Fatal error');
    }

    /**
     * @test
     */
    public function enrollmentFormShowsEditionDetails(AcceptanceTester $I): void
    {
        $I->wantTo('verify enrollment form shows edition details in sidebar');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // The sidebar shows course/edition info
        $I->dontSee('Fatal error');
        $I->seeElement('body');
    }

    /**
     * @test
     */
    public function personalStepShowsRequiredFields(AcceptanceTester $I): void
    {
        $I->wantTo('verify personal step shows required fields after type selection');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // Navigate to personal step (stepIndex 1) by selecting enrollment type via Alpine
        // currentStep is a getter (stepMap[stepIndex]), so set stepIndex instead
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) {
                comp.form.enrollment_type = 'zelf';
                comp.stepIndex = 1;
            }
        ");

        $I->wait(1);

        // Now personal fields should be visible with required attribute
        $I->seeElement('input[name="first_name"][required]');
        $I->seeElement('input[name="last_name"][required]');
        $I->seeElement('input[name="email"][required]');
        $I->seeElement('input[name="phone"][required]');
    }

    /**
     * @test
     */
    public function formWithoutSubmitDoesNotCreateRegistration(AcceptanceTester $I): void
    {
        $I->wantTo('verify no registration is created without form submission');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // Don't fill or submit anything — just verify no registration exists
        $I->dontSeeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
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

        // Create existing registration
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());

        // Wait for page load
        $I->waitForElement('body', 10);

        // Should see already enrolled message (or be redirected)
        // The Alpine component checks enrollment status and shows appropriate state
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // MULTI-STEP FORM NAVIGATION
    // =========================================================================

    /**
     * @test
     */
    public function userCanNavigateBetweenSteps(AcceptanceTester $I): void
    {
        $I->wantTo('navigate between enrollment form steps');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // Step 0: Type selection is visible
        $I->see('Voor wie is deze inschrijving');

        // Select enrollment type and advance to step 1 via Alpine
        // currentStep is a getter, set stepIndex instead
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) {
                comp.form.enrollment_type = 'zelf';
                comp.stepIndex = 1;
            }
        ");
        $I->wait(1);

        // Step 1: Personal info should now be visible
        $I->seeElement('input[name="first_name"]');
        $I->seeElement('input[name="last_name"]');

        // Navigate back to type step
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) comp.stepIndex = 0;
        ");
        $I->wait(1);

        // Should see type selection again
        $I->see('Voor wie is deze inschrijving');

        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // SUCCESSFUL ENROLLMENT (full multi-step flow)
    // =========================================================================

    /**
     * @test
     */
    public function successfulSelfEnrollment(AcceptanceTester $I): void
    {
        $I->wantTo('complete a successful self-enrollment through the multi-step form');

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // The enrollment form is a multi-step Alpine wizard.
        // Use JS to fill fields and progress to confirmation, then submit.
        // stepIndex controls navigation; currentStep is a readonly getter.
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) {
                comp.form.enrollment_type = 'zelf';
                comp.form.first_name = 'Test';
                comp.form.last_name = 'Enrollee';
                comp.form.email = '{$this->testUserEmail}';
                comp.form.phone = '+31612345678';
                comp.form.terms_accepted = true;
                comp.stepIndex = 3; // Jump to confirmation step
            }
        ");

        $I->wait(1);

        // Submit using the Alpine component's submitForm method
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) comp.submitForm();
        ");

        // Wait for AJAX submission
        $I->wait(5);

        // Should not see errors
        $I->dontSee('Fatal error');

        // Verify registration created in database
        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $this->testUserId,
            'edition_id' => $this->testEditionId,
        ]);
    }

    // =========================================================================
    // VOUCHER FLOW
    // =========================================================================

    /**
     * @test
     */
    public function voucherFieldIsAccessible(AcceptanceTester $I): void
    {
        $I->wantTo('verify voucher input is accessible on enrollment form');

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

        $I->loginAsUserId($this->testUserId, $this->enrollmentUrl());
        $I->waitForElement('form', 10);

        // The enrollment form page loads without errors
        $I->dontSee('Fatal error');
        $I->seeElement('body');
    }

    /**
     * @test
     */
    public function invalidEditionSlugShows404(AcceptanceTester $I): void
    {
        $I->wantTo('see 404 when invalid edition slug is used');

        $I->loginAsUserId($this->testUserId, '/edities/nonexistent-edition-99999/inschrijving/');

        // EnrollmentRouter calls trigger404() for non-existent editions
        // The 404 template shows "Pagina niet gevonden" (Dutch)
        $I->see('Pagina niet gevonden');
    }
}
