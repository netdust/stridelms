<?php
/**
 * End-to-End Test for ntdst-auth plugin
 *
 * Tests the full registration → activation → magic link flow
 *
 * Usage: ddev exec wp eval-file scripts/test-auth-e2e.php
 */

// Only run in development
if (wp_get_environment_type() !== 'development' && wp_get_environment_type() !== 'local') {
    WP_CLI::error('This script can only run in development environment.');
}

class AuthE2ETest
{
    private string $testEmail;
    private int $testUserId = 0;
    private array $results = [];

    public function __construct()
    {
        $this->testEmail = 'e2e-test-' . time() . '@example.com';
    }

    public function run(): void
    {
        WP_CLI::log("=== NTDST Auth Plugin - End-to-End Test ===\n");
        WP_CLI::log("Test email: {$this->testEmail}\n");

        // Clear Mailpit
        $this->clearMailpit();

        // Test 1: Registration
        $this->testRegistration();

        // Test 2: Check user in database
        $this->testUserCreated();

        // Test 3: Check consent data
        $this->testConsentRecorded();

        // Test 4: Check activation token exists
        $this->testActivationTokenCreated();

        // Test 5: Check activation email sent
        $this->testActivationEmailSent();

        // Test 6: Activate user via token
        $this->testActivationFlow();

        // Test 7: Request magic link for activated user
        $this->testMagicLinkRequest();

        // Test 8: Check magic link email sent
        $this->testMagicLinkEmailSent();

        // Test 9: Verify magic link token exists
        $this->testMagicLinkTokenCreated();

        // Cleanup
        $this->cleanup();

        // Summary
        $this->printSummary();
    }

    private function testRegistration(): void
    {
        WP_CLI::log("TEST 1: Registration via AJAX handler...");

        $registration = ntdst_get(\NTDST\Auth\RegistrationService::class);

        $result = $registration->register([
            'email' => $this->testEmail,
            'first_name' => 'E2E',
            'last_name' => 'TestUser',
            'consent_terms' => true,
            'consent_privacy' => true,
        ]);

        if (is_wp_error($result)) {
            $this->fail('Registration', $result->get_error_message());
            return;
        }

        if ($result['success'] ?? false) {
            $this->pass('Registration', 'Success message returned');
        } else {
            $this->fail('Registration', 'Unexpected response: ' . print_r($result, true));
        }
    }

    private function testUserCreated(): void
    {
        WP_CLI::log("TEST 2: User created in database...");

        $user = get_user_by('email', $this->testEmail);

        if (!$user) {
            $this->fail('User Created', 'User not found in database');
            return;
        }

        $this->testUserId = $user->ID;

        if ($user->first_name === 'E2E' && $user->last_name === 'TestUser') {
            $this->pass('User Created', "User ID: {$user->ID}, Name: {$user->first_name} {$user->last_name}");
        } else {
            $this->fail('User Created', "Wrong user data: {$user->first_name} {$user->last_name}");
        }
    }

    private function testConsentRecorded(): void
    {
        WP_CLI::log("TEST 3: Consent recorded in user meta...");

        if (!$this->testUserId) {
            $this->skip('Consent Recorded', 'No user ID');
            return;
        }

        $consent = get_user_meta($this->testUserId, 'ntdst_auth_consent', true);

        if (!$consent || !is_array($consent)) {
            $this->fail('Consent Recorded', 'Consent meta not found');
            return;
        }

        if (!empty($consent['terms']) && !empty($consent['privacy']) && !empty($consent['timestamp'])) {
            $this->pass('Consent Recorded', "Terms: yes, Privacy: yes, Version: {$consent['version']}");
        } else {
            $this->fail('Consent Recorded', 'Incomplete consent data: ' . print_r($consent, true));
        }
    }

    private function testActivationTokenCreated(): void
    {
        WP_CLI::log("TEST 4: Activation token created (transient)...");

        global $wpdb;

        // Look for activation transient
        $transients = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_ntdst_auth_activate_%'
             ORDER BY option_id DESC LIMIT 5"
        );

        $found = false;
        foreach ($transients as $t) {
            $data = maybe_unserialize($t->option_value);
            if (is_array($data) && ($data['email'] ?? '') === $this->testEmail) {
                $found = true;
                $this->pass('Activation Token', "Token found for {$this->testEmail}, type: {$data['type']}");
                break;
            }
        }

        if (!$found) {
            $this->fail('Activation Token', 'No activation transient found for this email');
        }
    }

    private function testActivationEmailSent(): void
    {
        WP_CLI::log("TEST 5: Activation email sent (Mailpit)...");

        sleep(1); // Give Mailpit time to receive

        $email = $this->getLatestEmailTo($this->testEmail);

        if (!$email) {
            $this->fail('Activation Email', 'No email found in Mailpit');
            return;
        }

        $subject = $email['Subject'] ?? '';
        if (stripos($subject, 'activate') !== false || stripos($subject, 'activeer') !== false) {
            $this->pass('Activation Email', "Subject: {$subject}");

            // Store the activation URL for next test
            $body = $this->getEmailBody($email['ID']);
            if (preg_match('#https?://[^\s"<>]+/auth/activate/[a-f0-9]+#i', $body, $matches)) {
                $this->results['activation_url'] = $matches[0];
                WP_CLI::log("   → Activation URL found: {$matches[0]}");
            }
        } else {
            $this->fail('Activation Email', "Unexpected subject: {$subject}");
        }
    }

    private function testActivationFlow(): void
    {
        WP_CLI::log("TEST 6: Activation via token...");

        if (!$this->testUserId) {
            $this->skip('Activation Flow', 'No user ID');
            return;
        }

        // Check user is NOT activated yet
        $activatedBefore = get_user_meta($this->testUserId, 'ntdst_auth_activated', true);
        if ($activatedBefore) {
            $this->fail('Activation Flow', 'User was already activated before test');
            return;
        }

        // Get activation URL from email and extract token
        $url = $this->results['activation_url'] ?? null;
        if (!$url) {
            $this->fail('Activation Flow', 'No activation URL from email');
            return;
        }

        // Extract token from URL
        if (!preg_match('#/auth/activate/([a-f0-9]+)#', $url, $matches)) {
            $this->fail('Activation Flow', 'Could not extract token from URL');
            return;
        }

        $token = $matches[1];

        // Call activation directly
        $registration = ntdst_get(\NTDST\Auth\RegistrationService::class);
        $result = $registration->activate($token);

        if (is_wp_error($result)) {
            $this->fail('Activation Flow', $result->get_error_message());
            return;
        }

        // Verify user is now activated
        wp_cache_delete($this->testUserId, 'user_meta');
        $activatedAfter = get_user_meta($this->testUserId, 'ntdst_auth_activated', true);
        $activatedAt = get_user_meta($this->testUserId, 'ntdst_auth_activated_at', true);

        if ($activatedAfter && $activatedAt) {
            $this->pass('Activation Flow', 'User activated at ' . date('Y-m-d H:i:s', $activatedAt));
        } else {
            $this->fail('Activation Flow', 'Activation meta not set after activation');
        }
    }

    private function testMagicLinkRequest(): void
    {
        WP_CLI::log("TEST 7: Magic link request for activated user...");

        // Clear mailpit first
        $this->clearMailpit();

        $authService = ntdst_get(\NTDST\Auth\AuthService::class);
        $result = $authService->requestMagicLink($this->testEmail);

        if ($result['success'] ?? false) {
            $this->pass('Magic Link Request', $result['message']);
        } else {
            $this->fail('Magic Link Request', $result['message'] ?? 'Unknown error');
        }
    }

    private function testMagicLinkEmailSent(): void
    {
        WP_CLI::log("TEST 8: Magic link email sent (Mailpit)...");

        sleep(1);

        $email = $this->getLatestEmailTo($this->testEmail);

        if (!$email) {
            $this->fail('Magic Link Email', 'No email found in Mailpit');
            return;
        }

        $subject = $email['Subject'] ?? '';
        if (stripos($subject, 'login') !== false || stripos($subject, 'link') !== false || stripos($subject, 'inloggen') !== false) {
            $this->pass('Magic Link Email', "Subject: {$subject}");

            // Store the magic link URL for next test
            $body = $this->getEmailBody($email['ID']);
            if (preg_match('#https?://[^\s"<>]+/auth/verify/[a-f0-9]+#i', $body, $matches)) {
                $this->results['magic_link_url'] = $matches[0];
                WP_CLI::log("   → Magic link URL found: {$matches[0]}");
            }
        } else {
            $this->fail('Magic Link Email', "Unexpected subject: {$subject}");
        }
    }

    private function testMagicLinkTokenCreated(): void
    {
        WP_CLI::log("TEST 9: Magic link token created (transient)...");

        global $wpdb;

        // Look for magic link transient
        $transients = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_ntdst_auth_magic_%'
             ORDER BY option_id DESC LIMIT 5"
        );

        $found = false;
        foreach ($transients as $t) {
            $data = maybe_unserialize($t->option_value);
            if (is_array($data) && ($data['email'] ?? '') === $this->testEmail) {
                $found = true;
                $this->pass('Magic Link Token', "Token found, max uses: {$data['max_uses']}, current uses: {$data['uses']}");
                break;
            }
        }

        if (!$found) {
            $this->fail('Magic Link Token', 'No magic link transient found for this email');
        }
    }

    private function clearMailpit(): void
    {
        @file_get_contents('https://stride.ddev.site:8026/api/v1/messages', false, stream_context_create([
            'http' => ['method' => 'DELETE'],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]));
    }

    private function getLatestEmailTo(string $email): ?array
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $response = @file_get_contents("https://stride.ddev.site:8026/api/v1/messages", false, $ctx);

        if (!$response) {
            return null;
        }

        $data = json_decode($response, true);
        $messages = $data['messages'] ?? [];

        foreach ($messages as $msg) {
            $to = $msg['To'] ?? [];
            foreach ($to as $recipient) {
                if (($recipient['Address'] ?? '') === $email) {
                    return $msg;
                }
            }
        }

        return null;
    }

    private function getEmailBody(string $messageId): string
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $response = @file_get_contents("https://stride.ddev.site:8026/api/v1/message/{$messageId}", false, $ctx);

        if (!$response) {
            return '';
        }

        $data = json_decode($response, true);
        return $data['Text'] ?? $data['HTML'] ?? '';
    }

    private function cleanup(): void
    {
        WP_CLI::log("\nCleaning up test user...");

        if ($this->testUserId) {
            wp_delete_user($this->testUserId);
            WP_CLI::log("   → Deleted user ID: {$this->testUserId}");
        }
    }

    private function pass(string $test, string $message): void
    {
        $this->results[$test] = ['status' => 'PASS', 'message' => $message];
        WP_CLI::success("{$test}: {$message}");
    }

    private function fail(string $test, string $message): void
    {
        $this->results[$test] = ['status' => 'FAIL', 'message' => $message];
        WP_CLI::warning("{$test}: {$message}");
    }

    private function skip(string $test, string $message): void
    {
        $this->results[$test] = ['status' => 'SKIP', 'message' => $message];
        WP_CLI::log("⏭️  {$test}: SKIPPED - {$message}");
    }

    private function printSummary(): void
    {
        WP_CLI::log("\n=== TEST SUMMARY ===");

        $passed = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($this->results as $test => $result) {
            if (is_array($result) && isset($result['status'])) {
                switch ($result['status']) {
                    case 'PASS': $passed++; break;
                    case 'FAIL': $failed++; break;
                    case 'SKIP': $skipped++; break;
                }
            }
        }

        WP_CLI::log("Passed: {$passed}");
        WP_CLI::log("Failed: {$failed}");
        WP_CLI::log("Skipped: {$skipped}");

        if ($failed === 0) {
            WP_CLI::success("\n✅ ALL TESTS PASSED - Plugin is working end-to-end!");
        } else {
            WP_CLI::error("\n❌ SOME TESTS FAILED - Plugin has issues!");
        }
    }
}

// Run the test
$test = new AuthE2ETest();
$test->run();
