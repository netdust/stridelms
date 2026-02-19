<?php
/**
 * Test enrollment form processing
 *
 * Usage: ddev exec wp eval-file scripts/test-enrollment-form.php
 */

if (!defined('ABSPATH')) {
    echo "Run via: ddev exec wp eval-file scripts/test-enrollment-form.php\n";
    exit(1);
}

$failures = [];

function assert_true($condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "  [PASS] {$message}\n";
    } else {
        echo "  [FAIL] {$message}\n";
        $failures[] = $message;
    }
}

echo "=== Enrollment Form Integration Tests ===\n\n";

// Get services
$enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
$voucherService = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);

// Test 1: Services exist
echo "--- Test 1: Services exist ---\n";
assert_true($enrollmentService !== null, 'EnrollmentService exists');
assert_true($quoteService !== null, 'QuoteService exists');
assert_true($voucherService !== null, 'VoucherService exists');

// Test 2: processEnrollment method exists
echo "\n--- Test 2: Method exists ---\n";
assert_true(
    method_exists($enrollmentService, 'processEnrollment'),
    'processEnrollment method exists'
);

// Test 3: Namespace resolution
echo "\n--- Test 3: Namespace resolution ---\n";
assert_true(
    class_exists(\Stride\Modules\Enrollment\EnrollmentService::class),
    'EnrollmentService class exists'
);
assert_true(
    class_exists(\Stride\Modules\Invoicing\QuoteService::class),
    'QuoteService class exists'
);
assert_true(
    class_exists(\Stride\Modules\Invoicing\VoucherService::class),
    'VoucherService class exists'
);
assert_true(
    class_exists(\Stride\Handlers\EnrollmentQuoteHandler::class),
    'EnrollmentQuoteHandler class exists'
);

// Test 4: Theme files exist
echo "\n--- Test 4: Theme files exist ---\n";
$themeDir = get_stylesheet_directory();
assert_true(
    file_exists($themeDir . '/templates/quote/update-form.php'),
    'Quote update form template exists'
);
assert_true(
    file_exists($themeDir . '/services/frontend/DashboardShortcodes.php'),
    'DashboardShortcodes service file exists'
);

// Test 5: Self-enrollment with billing data (if editions exist)
echo "\n--- Test 5: Self-enrollment simulation ---\n";

// Find a test edition
$editions = get_posts([
    'post_type' => 'vad_edition',
    'numberposts' => 1,
    'post_status' => 'publish',
]);

if (empty($editions)) {
    echo "  [SKIP] No editions found - skipping enrollment test\n";
    echo "  (Run scripts/seed.php to create test data)\n";
} else {
    $editionId = $editions[0]->ID;

    // Create a test user for enrollment
    $testEmail = 'test_enrollment_' . time() . '@test.local';
    $testUserId = wp_create_user('test_enroll_' . time(), wp_generate_password(), $testEmail);

    if (is_wp_error($testUserId)) {
        echo "  [SKIP] Could not create test user: " . $testUserId->get_error_message() . "\n";
    } else {
        $enrollmentData = [
            'edition_id' => $editionId,
            'user_id' => $testUserId,
            'enrollment_type' => 'self',
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => $testEmail,
            'phone' => '+32 123 456 789',
            'company' => 'Test Company BV',
            'vat_number' => 'BE0123456789',
            'address' => 'Teststraat 123',
            'postal_code' => '1000',
            'city' => 'Brussel',
            'terms_accepted' => true,
        ];

        $result = $enrollmentService->processEnrollment($enrollmentData);

        if (is_wp_error($result)) {
            $errorCode = $result->get_error_code();
            if ($errorCode === 'already_enrolled') {
                echo "  [SKIP] User already enrolled (expected for repeated tests)\n";
            } elseif ($errorCode === 'enrollment_closed') {
                echo "  [SKIP] Edition enrollment closed\n";
            } else {
                echo "  [FAIL] Enrollment error: " . $result->get_error_message() . "\n";
                $failures[] = 'Self-enrollment returned unexpected error';
            }
        } else {
            assert_true(isset($result['registration_id']), 'Registration ID returned');
            assert_true(isset($result['participant_id']), 'Participant ID returned');
            assert_true($result['participant_id'] === $testUserId, 'Participant ID matches user');

            // Check if quote was created (may not exist for free editions)
            if (isset($result['quote_id']) && $result['quote_id']) {
                $quote = $quoteService->getQuote($result['quote_id']);
                assert_true(!is_wp_error($quote), 'Quote retrieved successfully');

                if (!is_wp_error($quote)) {
                    // Check billing data was stored
                    $billing = $quote['billing'] ?? [];
                    assert_true(
                        !empty($billing['company']) || !empty($billing['vat_number']),
                        'Quote has billing data from form'
                    );
                }
            } else {
                echo "  [INFO] No quote created (edition may be free)\n";
            }

            // Check user meta was updated
            $savedCompany = get_user_meta($testUserId, 'company', true);
            assert_true($savedCompany === 'Test Company BV', 'User company meta saved');
        }

        // Cleanup test user
        wp_delete_user($testUserId);
    }
}

// Test 6: Pending billing transient mechanism
echo "\n--- Test 6: Pending billing mechanism ---\n";
$testBillingUserId = 999999;
$testEditionId = 888888;
$testBilling = [
    'name' => 'Test Name',
    'email' => 'test@example.com',
    'company' => 'Test Corp',
    'voucher_code' => 'TESTVOUCHER',
];

// Store billing
$key = 'stride_pending_billing_' . $testBillingUserId . '_' . $testEditionId;
set_transient($key, $testBilling, HOUR_IN_SECONDS);

// Retrieve billing
$retrieved = get_transient($key);
assert_true($retrieved !== false, 'Pending billing stored successfully');
assert_true($retrieved['company'] === 'Test Corp', 'Billing data matches');

// Cleanup
delete_transient($key);
$afterDelete = get_transient($key);
assert_true($afterDelete === false, 'Transient deleted successfully');

// Test 7: Quote update form template renders (basic check)
echo "\n--- Test 7: Template structure ---\n";
$templatePath = $themeDir . '/templates/quote/update-form.php';
$templateContent = file_get_contents($templatePath);
assert_true(
    strpos($templateContent, 'stride-quote-update-form') !== false,
    'Template contains form ID'
);
assert_true(
    strpos($templateContent, 'billing[organisation]') !== false,
    'Template contains billing organisation field'
);
assert_true(
    strpos($templateContent, 'voucher_code') !== false,
    'Template contains voucher field'
);
assert_true(
    strpos($templateContent, 'stride_update_quote') !== false,
    'Template references AJAX action'
);

// Summary
echo "\n=== Summary ===\n";
if (empty($failures)) {
    echo "All tests passed!\n";
    exit(0);
} else {
    echo count($failures) . " test(s) failed:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
