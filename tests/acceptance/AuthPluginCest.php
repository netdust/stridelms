<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for ntdst-auth plugin.
 *
 * Tests the authentication flows: login page, register page,
 * magic link requests, and admin settings.
 */
class AuthPluginCest
{
    public function _before(AcceptanceTester $I): void
    {
        // Rate-limit counters live in transients and persist across suite
        // runs — saturated register/login counters from a previous run would
        // block this run's attempts with 'rate_limited' instead of success.
        $I->dontHaveInDatabase(
            $I->grabPrefixedTableNameFor('options'),
            ['option_name like' => '_transient%ntdst_rate_%']
        );
    }

    // -------------------------------------------------------------------------
    // Login Page Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: Login page loads correctly
     *   GIVEN: I am not logged in
     *   WHEN: I visit /aanmelden
     *   THEN: I see the login page with email form
     */
    public function loginPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the custom login page loads');
        $I->amOnPage('/aanmelden');
        $I->seeElement('input[type="email"]');
        $I->seeElement('button[type="submit"]');
        $I->dontSee('Fatal error');
        $I->dontSee('wp-login.php');
    }

    /**
     * SCENARIO: Login page shows magic link form by default
     *   GIVEN: I am on /aanmelden
     *   THEN: I see magic link request form
     */
    public function loginPageShowsMagicLinkForm(AcceptanceTester $I): void
    {
        $I->wantTo('verify login page shows magic link form');
        $I->amOnPage('/aanmelden');
        // Structural signal that this is the Stride custom login page (not wp-login.php):
        // the magic-link form has an Alpine @submit handler unique to our auth plugin.
        $I->seeInSource('requestMagicLink');
        $I->see('Email');
        $I->seeElement('input[type="email"]');
        $I->seeElement('button[type="submit"]');
    }

    // -------------------------------------------------------------------------
    // Register Page Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: Register page loads correctly
     *   GIVEN: I am not logged in
     *   WHEN: I visit /registreren
     *   THEN: I see the registration form elements
     */
    public function registerPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the custom register page loads');
        $I->amOnPage('/registreren');
        $I->seeElement('input[type="email"]');
        $I->seeElement('input[type="checkbox"]');
        $I->seeElement('button[type="submit"]');
        $I->dontSee('Fatal error');
    }

    // -------------------------------------------------------------------------
    // Magic Link Request Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: Magic link request shows success regardless of email existence
     *   GIVEN: I am on /aanmelden
     *   WHEN: I switch to magic link tab, enter any email and submit
     *   THEN: I see success message (anti-enumeration)
     *
     * When password auth is enabled (current DB config), the login page defaults
     * to password mode. Click "Sign in with email link instead" to switch to
     * magic link mode, which shows the #email-magic input.
     */
    public function magicLinkRequestShowsSuccessForAnyEmail(AcceptanceTester $I): void
    {
        $I->wantTo('verify magic link request shows success for any email');
        $I->amOnPage('/aanmelden');

        // Wait for Alpine.js to initialize
        $I->waitForElement('input[type="email"]', 5);

        // Drive the Alpine component directly — works whether the page is in
        // magic-only mode (current config: enable_password=false, field
        // id="email") or dual mode with a toggle (field id="email-magic").
        $I->executeJS("
            const container = document.querySelector('[x-data]');
            const comp = Alpine.\$data(container);
            comp.email = 'nonexistent-email-12345@example.com';
            comp.requestMagicLink();
        ");

        // Wait for AJAX response
        $I->waitForText('login link', 10);
        $I->see('login link');
        $I->dontSee('not found');
        $I->dontSee('does not exist');
    }

    // -------------------------------------------------------------------------
    // Registration Flow Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: Registration shows success message
     *   GIVEN: I am on /registreren
     *   WHEN: I fill required fields (including profile type) and accept terms
     *   THEN: I see "inbox" message
     *
     * Register form IDs: #first_name, #last_name, #email, #profile_type,
     *   #consent_terms, #consent_privacy
     * Alpine component: authRegister() submits via AJAX to ntdst_auth_register action.
     * Profile type select is required when ProfileTypeService has types configured.
     */
    public function registrationShowsSuccessMessage(AcceptanceTester $I): void
    {
        $I->wantTo('verify registration shows success message');

        // Clear rate limits to avoid being blocked by previous test runs
        $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('options'), ['option_name LIKE' => '%ntdst_auth_rate%']);

        $I->amOnPage('/registreren');

        // Wait for Alpine.js to initialize
        $I->waitForElement('#email', 5);

        // Generate unique email for this test
        $testEmail = 'test-' . time() . '@example.com';

        // Fill fields by id (Alpine x-model binds to these)
        $I->fillField('#first_name', 'Test');
        $I->fillField('#last_name', 'User');
        $I->fillField('#email', $testEmail);

        // Select profile type if the select exists (required when ProfileTypeService has types)
        $profileTypeSelects = $I->grabMultiple('#profile_type');
        if (!empty($profileTypeSelects)) {
            // Select the first non-empty option via JS
            $I->executeJS("
                const select = document.getElementById('profile_type');
                if (select && select.options.length > 1) {
                    select.value = select.options[1].value;
                    select.dispatchEvent(new Event('input', { bubbles: true }));
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            ");
        }

        // Accept terms checkboxes
        $I->checkOption('#consent_terms');
        $I->checkOption('#consent_privacy');

        // Submit form
        $this->submitRegistrationAndAwaitInbox($I);
        $I->see('inbox');
    }

    /**
     * Submit the registration form and wait for the success message.
     *
     * Under full-suite load the DDEV PHP-FPM pool gets saturated by LearnDash
     * ProPanel reporting AJAX fired from wp-admin pages other Cests opened —
     * the registration fetch then fails CLIENT-side and Alpine renders
     * "Network error". One retry distinguishes pool saturation from a real
     * registration failure; anything other than a network error still throws.
     */
    private function submitRegistrationAndAwaitInbox(AcceptanceTester $I): void
    {
        $I->click('button[type="submit"]');

        try {
            $I->waitForText('inbox', 20);
        } catch (\Exception $e) {
            $message = (string) $I->executeJS(
                'const el = document.querySelector("[x-data]"); return el ? (Alpine.$data(el).message || "") : "";'
            );
            // Under full-suite load the first submit can fail client-side
            // ("network") or trip the per-IP rate limiter from a half-completed
            // attempt ("te veel"/"too many"). Both are environmental — clear
            // the rate counters and retry once. A real validation failure
            // (any other message) still throws.
            $transient = stripos($message, 'network') !== false
                || stripos($message, 'te veel') !== false
                || stripos($message, 'too many') !== false
                || stripos($message, 'rate') !== false;
            if (!$transient) {
                throw $e;
            }
            $I->comment('Transient submit failure under load — clearing rate limits and retrying once');
            $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('options'), ['option_name like' => '_transient%ntdst_rate_%']);
            $I->reloadPage();
            $I->waitForElement('#email', 5);
            $this->refillRegistrationForm($I);
            $I->click('button[type="submit"]');
            $I->waitForText('inbox', 25);
        }
    }

    /**
     * Re-fill the registration form after a reload (used by the retry path).
     * Mirrors the field set both registration tests use.
     */
    private function refillRegistrationForm(AcceptanceTester $I): void
    {
        $I->fillField('#first_name', 'Retry');
        $I->fillField('#last_name', 'User');
        $I->fillField('#email', 'retry-' . time() . '-' . random_int(1000, 9999) . '@example.com');

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

        $I->checkOption('#consent_terms');
        $I->checkOption('#consent_privacy');
    }

    // -------------------------------------------------------------------------
    // Error Flow Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: Invalid magic link token shows error
     *   GIVEN: I have a malformed or tampered token
     *   WHEN: I visit /auth/verify/{bad_token}
     *   THEN: I see error page with "Invalid" message
     */
    public function invalidTokenShowsError(AcceptanceTester $I): void
    {
        $I->wantTo('verify invalid token shows error');
        $I->amOnPage('/auth/verify/invalid-token-12345');
        $I->see('Link Invalid');
        $I->dontSee('Fatal error');
    }

    /**
     * SCENARIO: Invalid activation token shows error
     *   GIVEN: I have a malformed activation token
     *   WHEN: I visit /auth/activate/{bad_token}
     *   THEN: I see error page
     */
    public function invalidActivationTokenShowsError(AcceptanceTester $I): void
    {
        $I->wantTo('verify invalid activation token shows error');
        $I->amOnPage('/auth/activate/invalid-activation-token');
        $I->dontSee('Fatal error');
        // Should see some kind of error message
        $I->see('Invalid');
    }

    // -------------------------------------------------------------------------
    // Auth Routes Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: Auth verify route exists
     *   GIVEN: I visit /auth/verify with a token
     *   WHEN: The token is processed
     *   THEN: I don't see a 404 page or fatal error
     */
    public function authVerifyRouteExists(AcceptanceTester $I): void
    {
        $I->wantTo('verify auth/verify route exists');
        $I->amOnPage('/auth/verify/test-token');
        $I->dontSee('Page not found');
        $I->dontSee('Fatal error');
        // Should see error page (invalid token) not 404
        $I->see('Link');
    }

    /**
     * SCENARIO: Auth activate route exists
     *   GIVEN: I visit /auth/activate with a token
     *   WHEN: The route is processed
     *   THEN: I don't see a 404 page or fatal error
     */
    public function authActivateRouteExists(AcceptanceTester $I): void
    {
        $I->wantTo('verify auth/activate route exists');
        $I->amOnPage('/auth/activate/test-token');
        $I->dontSee('Page not found');
        $I->dontSee('Fatal error');
    }

    /**
     * SCENARIO: Auth logout route exists and redirects
     *   GIVEN: I am not logged in
     *   WHEN: I visit /auth/logout
     *   THEN: I am redirected to login page
     */
    public function authLogoutRouteRedirects(AcceptanceTester $I): void
    {
        $I->wantTo('verify auth/logout route redirects to login');
        $I->amOnPage('/auth/logout');
        $I->seeInCurrentUrl('/aanmelden');
    }

    // -------------------------------------------------------------------------
    // wp-login.php Redirect Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: wp-login.php redirects to custom login
     *   GIVEN: The redirect_wp_login setting is enabled
     *   WHEN: I visit wp-login.php
     *   THEN: I am redirected to /aanmelden
     */
    public function wpLoginRedirectsToCustomLogin(AcceptanceTester $I): void
    {
        $I->wantTo('verify wp-login.php redirects to custom login');
        $I->amOnPage('/wp/wp-login.php');
        $I->seeInCurrentUrl('/aanmelden');
        // Custom login page exposes the Alpine-driven magic link form;
        // wp-login.php does not. Brand-name-agnostic.
        $I->seeInSource('requestMagicLink');
    }

    /**
     * SCENARIO: wp-login.php logout action still works
     *   GIVEN: I am on wp-login.php with action=logout
     *   WHEN: The page loads
     *   THEN: I am NOT redirected (logout action is allowed)
     */
    public function wpLoginLogoutActionNotRedirected(AcceptanceTester $I): void
    {
        $I->wantTo('verify wp-login.php logout action is not redirected');
        $I->amOnPage('/wp/wp-login.php?action=logout');
        // Should stay on wp-login.php for logout action
        $I->seeInCurrentUrl('wp-login.php');
    }

    /**
     * SCENARIO: wp-login.php password reset action still works
     *   GIVEN: I am on wp-login.php with action=lostpassword
     *   WHEN: The page loads
     *   THEN: I am NOT redirected (password reset is allowed)
     */
    public function wpLoginPasswordResetNotRedirected(AcceptanceTester $I): void
    {
        $I->wantTo('verify wp-login.php password reset is not redirected');
        $I->amOnPage('/wp/wp-login.php?action=lostpassword');
        // Should stay on wp-login.php for password reset
        $I->seeInCurrentUrl('wp-login.php');
    }

    // -------------------------------------------------------------------------
    // Rate Limiting Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: Password login attempt increments rate limit counter
     *   GIVEN: I am on /aanmelden with password auth enabled
     *   WHEN: I submit invalid credentials
     *   THEN: A rate limit transient is created for my IP
     *
     * This verifies the rate limit counter is actually incremented,
     * preventing brute-force attacks on the password login endpoint.
     */
    public function passwordLoginIncrementsRateLimit(AcceptanceTester $I): void
    {
        $I->wantTo('verify password login attempt increments rate limit counter');
        $I->amOnPage('/aanmelden');
        $I->waitForElement('input[type="email"]', 5);

        // Submit invalid credentials via the password form
        $I->executeJS("
            const container = document.querySelector('[x-data]');
            const comp = Alpine.\$data(container);
            comp.email = 'nonexistent@example.com';
            comp.password = 'wrong-password';
            comp.loginPassword();
        ");

        // Wait for AJAX to complete
        $I->wait(2);

        // A rate limit transient should exist in the options table
        // Transient key pattern: _transient_ntdst_auth_rate_{md5('login_ip_' + ip)}
        $I->seeInDatabase($I->grabPrefixedTableNameFor('options'), [
            'option_name LIKE' => '%ntdst_auth_rate%',
        ]);
    }

    /**
     * SCENARIO: Registration attempt increments rate limit counter
     *   GIVEN: I am on /registreren
     *   WHEN: I submit a registration form
     *   THEN: A rate limit transient is created for my IP
     */
    public function registrationIncrementsRateLimit(AcceptanceTester $I): void
    {
        $I->wantTo('verify registration attempt increments rate limit counter');

        // Clear any existing rate limit transients so this test isn't blocked
        $I->dontHaveInDatabase($I->grabPrefixedTableNameFor('options'), ['option_name LIKE' => '%ntdst_auth_rate%']);

        $I->amOnPage('/registreren');
        $I->waitForElement('#email', 5);

        $testEmail = 'ratelimit-test-' . time() . '@example.com';

        $I->fillField('#first_name', 'Rate');
        $I->fillField('#last_name', 'Test');
        $I->fillField('#email', $testEmail);

        // Select profile type if present
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

        $I->checkOption('#consent_terms');
        $I->checkOption('#consent_privacy');
        $this->submitRegistrationAndAwaitInbox($I);

        // A rate limit transient should exist for registration
        $I->seeInDatabase($I->grabPrefixedTableNameFor('options'), [
            'option_name LIKE' => '%ntdst_auth_rate%',
        ]);
    }
}
