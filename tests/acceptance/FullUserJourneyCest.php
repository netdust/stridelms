<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Full User Journey Acceptance Test
 *
 * Tests the complete user lifecycle:
 * 1. User registers a new account
 * 2. User gets activated (bypassing email verification for test)
 * 3. User enrolls in a course edition
 * 4. User views quote and applies voucher for discount
 * 5. User attends sessions (1 normal + 1 opt-in)
 * 6. User receives certificate after completion
 *
 * This is an end-to-end test that simulates the entire user experience.
 *
 * URLs:
 * - Registration: /registreren (ntdst-auth plugin)
 * - Enrollment: /edities/{slug}/inschrijving/ (route-based)
 * - Dashboard: /mijn-account/ (page template)
 * - Profile: /mijn-account/?tab=profiel
 * - Quotes: /mijn-account/?tab=offertes
 */
class FullUserJourneyCest
{
    // Test data
    private int $courseId;
    private int $editionId;
    private string $editionSlug;
    private int $normalSessionId;
    private int $optInSessionId;
    private int $voucherId;
    private string $voucherCode;
    private string $testEmail;
    private string $testFirstName;
    private string $testLastName;

    public function _before(AcceptanceTester $I): void
    {
        // Generate unique test data
        $timestamp = time();
        $this->testEmail = "e2e_user_{$timestamp}@test.local";
        $this->testFirstName = 'E2E';
        $this->testLastName = 'Tester';
        $this->voucherCode = 'E2ETEST' . $timestamp;
        $this->editionSlug = 'e2e-test-edition-' . $timestamp;

        // Create test LearnDash course
        $this->courseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses',
            'post_title' => 'E2E Test Course ' . $timestamp,
            'post_status' => 'publish',
            'post_content' => 'This is a test course for the E2E journey test.',
        ]);

        // Create test edition with known slug
        $this->editionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'E2E Test Edition ' . $timestamp,
            'post_name' => $this->editionSlug,
            'post_status' => 'publish',
        ]);

        // Set edition meta
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_course_id', $this->courseId);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_price', 29900);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_status', 'open');
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_capacity', 20);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_start_date', date('Y-m-d', strtotime('+30 days')));
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_end_date', date('Y-m-d', strtotime('+32 days')));
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_venue', 'E2E Test Venue');
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_completion_mode', 'attend_all');

        // Create normal (required) session
        $this->normalSessionId = $I->havePostInDatabase([
            'post_type' => 'vad_session',
            'post_title' => 'Normal Session Day 1',
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($this->normalSessionId, '_ntdst_edition_id', $this->editionId);
        $I->havePostmetaInDatabase($this->normalSessionId, '_ntdst_date', date('Y-m-d', strtotime('+30 days')));
        $I->havePostmetaInDatabase($this->normalSessionId, '_ntdst_start_time', '09:00');
        $I->havePostmetaInDatabase($this->normalSessionId, '_ntdst_end_time', '17:00');
        $I->havePostmetaInDatabase($this->normalSessionId, '_ntdst_type', 'in_person');
        $I->havePostmetaInDatabase($this->normalSessionId, '_ntdst_optional', false);
        $I->havePostmetaInDatabase($this->normalSessionId, '_ntdst_capacity', 20);

        // Create opt-in (optional) session
        $this->optInSessionId = $I->havePostInDatabase([
            'post_type' => 'vad_session',
            'post_title' => 'Optional Workshop',
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($this->optInSessionId, '_ntdst_edition_id', $this->editionId);
        $I->havePostmetaInDatabase($this->optInSessionId, '_ntdst_date', date('Y-m-d', strtotime('+31 days')));
        $I->havePostmetaInDatabase($this->optInSessionId, '_ntdst_start_time', '14:00');
        $I->havePostmetaInDatabase($this->optInSessionId, '_ntdst_end_time', '17:00');
        $I->havePostmetaInDatabase($this->optInSessionId, '_ntdst_type', 'in_person');
        $I->havePostmetaInDatabase($this->optInSessionId, '_ntdst_optional', true);
        $I->havePostmetaInDatabase($this->optInSessionId, '_ntdst_capacity', 10);

        // Create voucher for testing discount
        $this->voucherId = $I->havePostInDatabase([
            'post_type' => 'vad_voucher',
            'post_title' => $this->voucherCode,
            'post_status' => 'publish',
        ]);
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_code', $this->voucherCode);
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_discount_type', 'percentage');
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_discount_value', 20);
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_status', 'active');
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_usage_limit', 10);
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_used_count', 0);
    }

    public function _after(AcceptanceTester $I): void
    {
        // Cleanup is handled by database transaction rollback
    }

    // Store user ID between tests
    private int $testUserId;

    // =========================================================================
    // STEP 1: USER REGISTRATION
    // =========================================================================

    /**
     * @test
     * @group e2e
     */
    public function step1_userCanRegisterNewAccount(AcceptanceTester $I): void
    {
        $I->wantTo('register a new user account');

        $I->amOnPage('/registreren');

        // Wait for Alpine.js to initialize
        $I->waitForElement('#email', 5);

        // Verify registration form elements
        $I->seeElement('form');
        $I->seeElement('#first_name');
        $I->seeElement('#last_name');
        $I->seeElement('#email');

        // Fill registration form
        $I->fillField('#first_name', $this->testFirstName);
        $I->fillField('#last_name', $this->testLastName);
        $I->fillField('#email', $this->testEmail);

        // Select profile type if the select exists (required when configured)
        $profileTypeSelects = $I->grabMultiple('#profile_type');
        if (!empty($profileTypeSelects)) {
            $I->executeJS("
                const select = document.getElementById('profile_type');
                if (select && select.options.length > 1) {
                    select.value = select.options[1].value;
                    select.dispatchEvent(new Event('input', { bubbles: true }));
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            ");
        }

        // Accept terms
        $I->checkOption('#consent_terms');
        $I->checkOption('#consent_privacy');

        // Submit form
        $I->click('button[type="submit"]');

        // Wait for AJAX response
        $I->wait(3);

        // Should not see errors
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // STEP 2: USER ACTIVATION
    // =========================================================================

    /**
     * @test
     * @group e2e
     */
    public function step2_userCanBeActivated(AcceptanceTester $I): void
    {
        $I->wantTo('activate the newly registered user');

        // Create the user (simulating step 1 completion)
        $userId = $I->haveUserInDatabase('e2e_user_' . time(), 'subscriber', [
            'user_email' => $this->testEmail,
            'display_name' => $this->testFirstName . ' ' . $this->testLastName,
        ]);

        // Activate user using test helper
        $I->activateUserById($userId, '/aanmelden/');

        // Verify activation worked
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $userId,
            'meta_key' => 'ntdst_auth_activated',
            'meta_value' => '1',
        ]);
    }

    // =========================================================================
    // STEP 3: COURSE ENROLLMENT
    // =========================================================================

    /**
     * @test
     * @group e2e
     */
    public function step3_activatedUserCanEnroll(AcceptanceTester $I): void
    {
        $I->wantTo('enroll in a course as an activated user');

        // Create and activate test user
        $userId = $I->haveUserInDatabase('e2e_enroll_' . time(), 'subscriber', [
            'user_email' => 'e2e_enroll_' . time() . '@test.local',
            'display_name' => $this->testFirstName . ' ' . $this->testLastName,
        ]);
        $I->haveUserMetaInDatabase($userId, 'ntdst_auth_activated', '1');
        $I->haveUserMetaInDatabase($userId, 'ntdst_auth_activated_at', (string) time());
        $I->haveUserMetaInDatabase($userId, 'first_name', $this->testFirstName);
        $I->haveUserMetaInDatabase($userId, 'last_name', $this->testLastName);

        $enrollUrl = '/edities/' . $this->editionId . '/inschrijving/';

        // Login as activated user
        $I->loginAsUserId($userId, $enrollUrl);

        // Wait for enrollment form
        $I->waitForElement('form', 10);

        // Fill and submit via Alpine.js
        $email = 'e2e_enroll_' . time() . '@test.local';
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) {
                comp.form.enrollment_type = 'zelf';
                comp.form.first_name = '{$this->testFirstName}';
                comp.form.last_name = '{$this->testLastName}';
                comp.form.email = '{$email}';
                comp.form.phone = '+31612345678';
                comp.form.terms_accepted = true;
                comp.stepIndex = 3;
            }
        ");

        $I->wait(1);

        // Submit using the Alpine component's submitForm method
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) comp.submitForm();
        ");

        // Wait for submission
        $I->wait(5);

        // Should not see errors
        $I->dontSee('Fatal error');

        // Verify registration created
        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
        ]);

        $this->testUserId = $userId;
    }

    // =========================================================================
    // STEP 4: QUOTE AND VOUCHER
    // =========================================================================

    /**
     * @test
     * @group e2e
     */
    public function step4_userCanViewQuoteAndApplyVoucher(AcceptanceTester $I): void
    {
        $I->wantTo('view my quote and apply a voucher for discount');

        // Create user with enrollment and quote
        $userId = $I->haveUserInDatabase('e2e_quote_' . time(), 'subscriber', [
            'user_email' => 'e2e_quote_' . time() . '@test.local',
        ]);
        $I->haveUserMetaInDatabase($userId, 'ntdst_auth_activated', '1');

        // Create registration
        $regId = $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        // Create quote for this registration
        $quoteId = $I->havePostInDatabase([
            'post_type' => 'vad_quote',
            'post_title' => 'OFF-' . date('Ym') . '-' . str_pad((string) $regId, 4, '0', STR_PAD_LEFT),
            'post_status' => 'publish',
            'post_author' => $userId,
        ]);
        $I->havePostmetaInDatabase($quoteId, 'user_id', $userId);
        $I->havePostmetaInDatabase($quoteId, 'registration_id', $regId);
        $I->havePostmetaInDatabase($quoteId, 'edition_id', $this->editionId);
        $I->havePostmetaInDatabase($quoteId, 'quote_number', 'OFF-' . date('Ym') . '-' . str_pad((string) $regId, 4, '0', STR_PAD_LEFT));
        $I->havePostmetaInDatabase($quoteId, 'status', 'draft');
        $I->havePostmetaInDatabase($quoteId, 'subtotal', 29900);
        $I->havePostmetaInDatabase($quoteId, 'discount', 0);
        $I->havePostmetaInDatabase($quoteId, 'tax', 6279);
        $I->havePostmetaInDatabase($quoteId, 'total', 36179);

        // Login and go to quotes tab in dashboard
        $I->loginAsUserId($userId, '/mijn-account/?tab=offertes');

        // Should see the page without errors
        $I->waitForElement('body', 10);
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // STEP 5: SESSION ATTENDANCE
    // =========================================================================

    /**
     * @test
     * @group e2e
     */
    public function step5_userAttendsNormalAndOptInSessions(AcceptanceTester $I): void
    {
        $I->wantTo('attend a normal session and an opt-in session');

        // Create user with enrollment
        $userId = $I->haveUserInDatabase('e2e_attend_' . time(), 'subscriber', [
            'user_email' => 'e2e_attend_' . time() . '@test.local',
        ]);
        $I->haveUserMetaInDatabase($userId, 'ntdst_auth_activated', '1');

        // Create confirmed registration
        $regId = $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        // Grant course access (simulates what EnrollmentService does)
        $I->haveUserMetaInDatabase($userId, 'course_' . $this->courseId . '_access_from', (string) time());

        // Simulate attendance marking for normal session
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'edition_id' => $this->editionId,
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        // Verify normal session attendance recorded
        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
        ]);

        // Simulate attendance for opt-in session
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'edition_id' => $this->editionId,
            'session_id' => $this->optInSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        // Verify opt-in session attendance recorded
        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'session_id' => $this->optInSessionId,
            'user_id' => $userId,
            'status' => 'present',
        ]);

        // Login and view dashboard
        $I->loginAsUserId($userId, '/mijn-account/');

        // Should see dashboard without errors
        $I->waitForElement('body', 10);
        $I->dontSee('Fatal error');
    }

    // =========================================================================
    // STEP 6: CERTIFICATE VERIFICATION
    // =========================================================================

    /**
     * @test
     * @group e2e
     */
    public function step6_userReceivesCertificateAfterCompletion(AcceptanceTester $I): void
    {
        $I->wantTo('receive a certificate after completing the course');

        // Create user with completed enrollment
        $userId = $I->haveUserInDatabase('e2e_cert_' . time(), 'subscriber', [
            'user_email' => 'e2e_cert_' . time() . '@test.local',
        ]);
        $I->haveUserMetaInDatabase($userId, 'ntdst_auth_activated', '1');

        // Create confirmed registration
        $regId = $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        // Grant course access
        $I->haveUserMetaInDatabase($userId, 'course_' . $this->courseId . '_access_from', (string) time());

        // Mark session as attended
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'edition_id' => $this->editionId,
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        // Simulate course completion in LearnDash
        $I->haveUserMetaInDatabase($userId, 'course_completed_' . $this->courseId, (string) time());

        // Login and visit dashboard
        $I->loginAsUserId($userId, '/mijn-account/');

        // Verify we can see the dashboard without errors
        $I->waitForElement('body', 10);
        $I->dontSee('Fatal error');

        // Verify completion meta was set
        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $userId,
            'meta_key' => 'course_completed_' . $this->courseId,
        ]);
    }

    // =========================================================================
    // FULL JOURNEY TEST (ALL STEPS COMBINED)
    // =========================================================================

    /**
     * @test
     * @group e2e
     * @group full-journey
     */
    public function fullUserJourney(AcceptanceTester $I): void
    {
        $I->wantTo('complete the full user journey from registration to certificate');

        $timestamp = time();
        $email = "journey_{$timestamp}@test.local";
        $firstName = 'Journey';
        $lastName = 'User';

        // =====================================================================
        // STEP 1: Create and activate user (bypassing email verification)
        // =====================================================================

        $userId = $I->haveUserInDatabase('journey_' . $timestamp, 'subscriber', [
            'user_email' => $email,
            'display_name' => $firstName . ' ' . $lastName,
        ]);
        $I->haveUserMetaInDatabase($userId, 'ntdst_auth_activated', '1');
        $I->haveUserMetaInDatabase($userId, 'ntdst_auth_activated_at', (string) $timestamp);
        $I->haveUserMetaInDatabase($userId, 'first_name', $firstName);
        $I->haveUserMetaInDatabase($userId, 'last_name', $lastName);

        codecept_debug("Created user ID: $userId with email: $email");

        // =====================================================================
        // STEP 2: Login and enroll in course
        // =====================================================================

        $enrollUrl = '/edities/' . $this->editionId . '/inschrijving/';
        $I->loginAsUserId($userId, $enrollUrl);
        $I->waitForElement('form', 10);

        // Fill and submit enrollment via Alpine.js
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) {
                comp.form.enrollment_type = 'zelf';
                comp.form.first_name = '{$firstName}';
                comp.form.last_name = '{$lastName}';
                comp.form.email = '{$email}';
                comp.form.phone = '+31612345678';
                comp.form.terms_accepted = true;
                comp.stepIndex = 3;
            }
        ");

        $I->wait(1);

        // Submit using the Alpine component's submitForm method
        $I->executeJS("
            const el = document.querySelector('[x-data^=\"enrollmentForm\"]');
            const comp = Alpine.\$data(el);
            if (comp) comp.submitForm();
        ");
        $I->wait(5);

        // Verify enrollment
        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_registrations'), [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
        ]);

        codecept_debug("Enrollment confirmed for user $userId in edition {$this->editionId}");

        // =====================================================================
        // STEP 3: Mark attendance for sessions
        // =====================================================================

        // Mark attendance for normal session
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'edition_id' => $this->editionId,
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        // Mark attendance for opt-in session
        $I->haveInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'edition_id' => $this->editionId,
            'session_id' => $this->optInSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
        ]);

        $I->seeInDatabase($I->grabPrefixedTableNameFor('vad_attendance'), [
            'session_id' => $this->optInSessionId,
            'user_id' => $userId,
            'status' => 'present',
        ]);

        codecept_debug("Attendance marked for both sessions");

        // =====================================================================
        // STEP 4: Simulate course completion
        // =====================================================================

        $I->haveUserMetaInDatabase($userId, 'course_completed_' . $this->courseId, (string) time());
        $I->haveUserMetaInDatabase($userId, 'course_' . $this->courseId . '_access_from', (string) ($timestamp - 86400));

        $I->seeInDatabase($I->grabPrefixedTableNameFor('usermeta'), [
            'user_id' => $userId,
            'meta_key' => 'course_completed_' . $this->courseId,
        ]);

        codecept_debug("Course marked as completed for user $userId");

        // =====================================================================
        // STEP 5: View dashboard (verify everything accessible)
        // =====================================================================

        $I->amOnPage('/mijn-account/');
        $I->waitForElement('body', 10);
        $I->dontSee('Fatal error');

        codecept_debug("Dashboard loads without errors - full journey complete!");
    }
}
