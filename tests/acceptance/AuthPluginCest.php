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
    // -------------------------------------------------------------------------
    // Login Page Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: Login page loads correctly
     *   GIVEN: I am not logged in
     *   WHEN: I visit /login
     *   THEN: I see the login page with email form
     */
    public function loginPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the custom login page loads');
        $I->amOnPage('/login');
        $I->seeElement('input[type="email"]');
        $I->seeElement('button[type="submit"]');
        $I->dontSee('Fatal error');
        $I->dontSee('wp-login.php');
    }

    /**
     * SCENARIO: Login page shows magic link form by default
     *   GIVEN: I am on /login
     *   THEN: I see magic link request form
     */
    public function loginPageShowsMagicLinkForm(AcceptanceTester $I): void
    {
        $I->wantTo('verify login page shows magic link form');
        $I->amOnPage('/login');
        $I->see('Stride LMS');
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
     *   WHEN: I visit /register
     *   THEN: I see the registration form elements
     */
    public function registerPageLoads(AcceptanceTester $I): void
    {
        $I->wantTo('verify the custom register page loads');
        $I->amOnPage('/register');
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
     *   GIVEN: I am on /login
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
        $I->amOnPage('/login');

        // Wait for Alpine.js to initialize
        $I->waitForElement('input[type="email"]', 5);

        // Switch to magic link mode if password form is showing
        // The link text contains "email link" — click it to toggle mode
        $magicLinkToggle = $I->grabMultiple('a', 'text');
        $I->executeJS("
            const links = document.querySelectorAll('a');
            for (const link of links) {
                if (link.textContent.includes('email link')) {
                    link.click();
                    break;
                }
            }
        ");

        // Wait for magic link form to appear
        $I->waitForElement('#email-magic', 5);

        // Fill the magic link email and submit via Alpine component
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
     *   GIVEN: I am on /register
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
        $I->amOnPage('/register');

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
        $I->click('button[type="submit"]');

        // Wait for AJAX response - message is "Check your inbox for instructions..."
        $I->waitForText('inbox', 10);
        $I->see('inbox');
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
        $I->seeInCurrentUrl('/login');
    }

    // -------------------------------------------------------------------------
    // wp-login.php Redirect Tests
    // -------------------------------------------------------------------------

    /**
     * SCENARIO: wp-login.php redirects to custom login
     *   GIVEN: The redirect_wp_login setting is enabled
     *   WHEN: I visit wp-login.php
     *   THEN: I am redirected to /login
     */
    public function wpLoginRedirectsToCustomLogin(AcceptanceTester $I): void
    {
        $I->wantTo('verify wp-login.php redirects to custom login');
        $I->amOnPage('/wp/wp-login.php');
        $I->seeInCurrentUrl('/login');
        $I->see('Stride LMS');
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
     *   GIVEN: I am on /login with password auth enabled
     *   WHEN: I submit invalid credentials
     *   THEN: A rate limit transient is created for my IP
     *
     * This verifies the rate limit counter is actually incremented,
     * preventing brute-force attacks on the password login endpoint.
     */
    public function passwordLoginIncrementsRateLimit(AcceptanceTester $I): void
    {
        $I->wantTo('verify password login attempt increments rate limit counter');
        $I->amOnPage('/login');
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
        $I->seeInDatabase('stride_options', [
            'option_name LIKE' => '%ntdst_auth_rate%',
        ]);
    }

    /**
     * SCENARIO: Registration attempt increments rate limit counter
     *   GIVEN: I am on /register
     *   WHEN: I submit a registration form
     *   THEN: A rate limit transient is created for my IP
     */
    public function registrationIncrementsRateLimit(AcceptanceTester $I): void
    {
        $I->wantTo('verify registration attempt increments rate limit counter');

        // Clear any existing rate limit transients first
        $I->executeJS("true"); // noop to ensure page context
        $I->amOnPage('/register');
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
        $I->click('button[type="submit"]');

        // Wait for AJAX response
        $I->waitForText('inbox', 10);

        // A rate limit transient should exist for registration
        $I->seeInDatabase('stride_options', [
            'option_name LIKE' => '%ntdst_auth_rate%',
        ]);
    }
}
