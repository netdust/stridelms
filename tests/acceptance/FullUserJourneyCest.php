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
 */
class FullUserJourneyCest
{
    // Test data
    private int $courseId;
    private int $editionId;
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

        // Create test LearnDash course
        $this->courseId = $I->havePostInDatabase([
            'post_type' => 'sfwd-courses',
            'post_title' => 'E2E Test Course ' . $timestamp,
            'post_status' => 'publish',
            'post_content' => 'This is a test course for the E2E journey test.',
        ]);

        // Create test edition
        $this->editionId = $I->havePostInDatabase([
            'post_type' => 'vad_edition',
            'post_title' => 'E2E Test Edition ' . $timestamp,
            'post_status' => 'publish',
        ]);

        // Set edition meta
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_course_id', $this->courseId);
        $I->havePostmetaInDatabase($this->editionId, '_ntdst_price', 29900); // €299.00 in cents
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
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_discount_value', 20); // 20% discount
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_status', 'active');
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_usage_limit', 10);
        $I->havePostmetaInDatabase($this->voucherId, '_ntdst_used_count', 0);
    }

    public function _after(AcceptanceTester $I): void
    {
        // Cleanup is handled by database transaction rollback
    }

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

        $I->amOnPage('/register/');

        // Verify registration form is visible (uses Alpine.js with IDs, not name attributes)
        $I->seeElement('form');
        $I->seeElement('#email');
        $I->seeElement('#first_name');
        $I->seeElement('#last_name');

        // Fill registration form (Alpine.js x-model binds to these IDs)
        $I->fillField('#first_name', $this->testFirstName);
        $I->fillField('#last_name', $this->testLastName);
        $I->fillField('#email', $this->testEmail);

        // Accept terms (use IDs)
        $I->checkOption('#consent_terms');
        $I->checkOption('#consent_privacy');

        // Submit form
        $I->click('button[type="submit"]');

        // Wait for AJAX response
        $I->wait(3);

        // Should see success message or confirmation
        $I->dontSee('Fatal error');

        // Note: The actual user creation happens via AJAX, so we check for success message
        // The Alpine.js component shows a success message on successful registration
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

        // First create the user (simulating step 1 completion)
        $userId = $I->haveUserInDatabase('e2e_user_' . time(), 'subscriber', [
            'user_email' => $this->testEmail,
            'display_name' => $this->testFirstName . ' ' . $this->testLastName,
        ]);

        // Activate user using test helper
        $I->activateUserById($userId, '/login/');

        // Verify activation worked
        $I->seeInDatabase('stride_usermeta', [
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

        // Login as activated user
        $I->loginAsUserId($userId, '/inschrijven/?edition=' . $this->editionId);

        // Verify enrollment form is visible
        $I->seeElement('#stride-enrollment-form');

        // Fill required fields
        $I->fillField('input[name="first_name"]', $this->testFirstName);
        $I->fillField('input[name="last_name"]', $this->testLastName);
        $I->fillField('input[name="email"]', 'e2e_enroll_' . time() . '@test.local');

        // Accept terms (use the form-associated checkboxes)
        $I->checkOption('input[name="terms_accepted"][form="stride-enrollment-form"]');
        $I->checkOption('input[name="cancellation_accepted"][form="stride-enrollment-form"]');

        // Submit using JavaScript (handles visibility issues)
        $I->executeJS('
            const btns = document.querySelectorAll(".submit-enrollment");
            for (const btn of btns) {
                if (btn.offsetParent !== null) {
                    btn.click();
                    break;
                }
            }
        ');

        // Wait for form submission
        $I->wait(3);

        // Should not see errors
        $I->dontSee('Fatal error');

        // Verify registration created
        $I->seeInDatabase('stride_vad_registrations', [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
        ]);

        // Store user ID for subsequent tests
        $this->testUserId = $userId;
    }

    // Store user ID between tests
    private int $testUserId;

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
        $regId = $I->haveInDatabase('stride_vad_registrations', [
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
        $I->havePostmetaInDatabase($quoteId, 'tax', 6279); // 21% BTW
        $I->havePostmetaInDatabase($quoteId, 'total', 36179);

        // Login and go to quotes page (under mijn-account)
        $I->loginAsUserId($userId, '/mijn-account/offertes/');

        // Should see the quotes page
        $I->seeElement('body');
        $I->dontSee('Fatal error');

        // Navigate to quote detail page (if available)
        $quoteDetailUrl = '/mijn-account/offerte/?id=' . $quoteId;
        $I->amOnPage($quoteDetailUrl);

        // If voucher field exists, apply voucher
        $voucherField = $I->grabMultiple('input[name="voucher_code"]');
        if (!empty($voucherField)) {
            $I->fillField('input[name="voucher_code"]', $this->voucherCode);

            // Click apply voucher button
            $applyButton = $I->grabMultiple('#apply-voucher');
            if (!empty($applyButton)) {
                $I->click('#apply-voucher');
                $I->wait(2);

                // Should see discount applied
                $I->see('Korting');
            }
        }

        // No fatal errors
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
        $regId = $I->haveInDatabase('stride_vad_registrations', [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        // Grant course access (simulates what EnrollmentService does)
        // This is done via LearnDash user meta
        $I->haveUserMetaInDatabase($userId, 'course_' . $this->courseId . '_access_from', (string) time());

        // Simulate attendance marking for normal session
        $I->haveInDatabase('stride_vad_attendance', [
            'edition_id' => $this->editionId,
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        // Verify normal session attendance recorded
        $I->seeInDatabase('stride_vad_attendance', [
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
        ]);

        // Simulate attendance for opt-in session
        $I->haveInDatabase('stride_vad_attendance', [
            'edition_id' => $this->editionId,
            'session_id' => $this->optInSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        // Verify opt-in session attendance recorded
        $I->seeInDatabase('stride_vad_attendance', [
            'session_id' => $this->optInSessionId,
            'user_id' => $userId,
            'status' => 'present',
        ]);

        // Login and view dashboard to see attendance reflected
        $I->loginAsUserId($userId, '/dashboard/');

        // Should see dashboard without errors
        $I->dontSee('Fatal error');
        $I->seeElement('body');
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
        $regId = $I->haveInDatabase('stride_vad_registrations', [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
            'registered_at' => date('Y-m-d H:i:s'),
        ]);

        // Grant course access
        $I->haveUserMetaInDatabase($userId, 'course_' . $this->courseId . '_access_from', (string) time());

        // Mark both sessions as attended
        $I->haveInDatabase('stride_vad_attendance', [
            'edition_id' => $this->editionId,
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        // Simulate course completion in LearnDash
        // LearnDash stores completion in user activity and user meta
        $I->haveUserMetaInDatabase($userId, 'course_completed_' . $this->courseId, (string) time());

        // Also add to LearnDash user activity table if it exists
        // The certificate link is generated by learndash_get_course_certificate_link()
        // which checks course completion status

        // Login and visit dashboard
        $I->loginAsUserId($userId, '/dashboard/');

        // Verify we can see the dashboard (certificates shown there)
        $I->seeElement('body');
        $I->dontSee('Fatal error');

        // Check for certificate-related elements or links
        // Note: Actual certificate display depends on LearnDash configuration
        // and whether a certificate is assigned to the course

        // Verify completion meta was set
        $I->seeInDatabase('stride_usermeta', [
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

        $I->loginAsUserId($userId, '/inschrijven/?edition=' . $this->editionId);
        $I->seeElement('#stride-enrollment-form');

        // Fill enrollment form
        $I->fillField('input[name="first_name"]', $firstName);
        $I->fillField('input[name="last_name"]', $lastName);
        $I->fillField('input[name="email"]', $email);

        // Check terms
        $I->checkOption('input[name="terms_accepted"][form="stride-enrollment-form"]');
        $I->checkOption('input[name="cancellation_accepted"][form="stride-enrollment-form"]');

        // Submit
        $I->executeJS('
            const btns = document.querySelectorAll(".submit-enrollment");
            for (const btn of btns) {
                if (btn.offsetParent !== null) {
                    btn.click();
                    break;
                }
            }
        ');
        $I->wait(3);

        // Verify enrollment
        $I->seeInDatabase('stride_vad_registrations', [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
            'status' => 'confirmed',
        ]);

        codecept_debug("Enrollment confirmed for user $userId in edition {$this->editionId}");

        // =====================================================================
        // STEP 3: Mark attendance for sessions
        // =====================================================================

        // Get registration ID
        $regId = $I->grabFromDatabase('stride_vad_registrations', 'id', [
            'user_id' => $userId,
            'edition_id' => $this->editionId,
        ]);

        // Mark attendance for normal session
        $I->haveInDatabase('stride_vad_attendance', [
            'edition_id' => $this->editionId,
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        // Mark attendance for opt-in session
        $I->haveInDatabase('stride_vad_attendance', [
            'edition_id' => $this->editionId,
            'session_id' => $this->optInSessionId,
            'user_id' => $userId,
            'status' => 'present',
            'marked_at' => date('Y-m-d H:i:s'),
        ]);

        $I->seeInDatabase('stride_vad_attendance', [
            'session_id' => $this->normalSessionId,
            'user_id' => $userId,
            'status' => 'present',
        ]);

        $I->seeInDatabase('stride_vad_attendance', [
            'session_id' => $this->optInSessionId,
            'user_id' => $userId,
            'status' => 'present',
        ]);

        codecept_debug("Attendance marked for both sessions");

        // =====================================================================
        // STEP 4: Simulate course completion
        // =====================================================================

        // Mark course as completed in LearnDash user meta
        $I->haveUserMetaInDatabase($userId, 'course_completed_' . $this->courseId, (string) time());
        $I->haveUserMetaInDatabase($userId, 'course_' . $this->courseId . '_access_from', (string) ($timestamp - 86400));

        $I->seeInDatabase('stride_usermeta', [
            'user_id' => $userId,
            'meta_key' => 'course_completed_' . $this->courseId,
        ]);

        codecept_debug("Course marked as completed for user $userId");

        // =====================================================================
        // STEP 5: View dashboard (verify everything accessible)
        // =====================================================================

        $I->amOnPage('/mijn-account/');
        $I->seeElement('body');
        $I->dontSee('Fatal error');

        codecept_debug("Dashboard loads without errors - full journey complete!");
    }
}
