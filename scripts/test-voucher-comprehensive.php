<?php
/**
 * Stride LMS - Voucher Service Comprehensive Tests
 *
 * Tests voucher creation, validation, discount calculations, and redemption.
 *
 * Run with: ddev exec wp eval-file scripts/test-voucher-comprehensive.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-voucher-comprehensive.php\n";
    exit(1);
}

use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Domain\Money;
use Stride\Domain\VoucherStatus;
use Stride\Domain\DiscountType;

class StrideVoucherComprehensiveTest
{
    private VoucherService $voucherService;
    private QuoteService $quoteService;

    private array $created = [
        'voucher_ids' => [],
        'quote_ids' => [],
        'user_ids' => [],
        'edition_ids' => [],
        'course_ids' => [],
        'registration_ids' => [],
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->voucherService = ntdst_get(VoucherService::class);
        $this->quoteService = ntdst_get(QuoteService::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Voucher Comprehensive Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->setupTestData();
            $this->testFullDiscountVoucher();
            $this->testFixedAmountVoucher();
            $this->testPercentageVoucher();
            $this->testVoucherValidationExpired();
            $this->testVoucherValidationUsageLimit();
            $this->testVoucherValidationEditionRestriction();
            $this->testApplyVoucherToQuote();
            $this->testVoucherRedemption();
            $this->testConcurrentVoucherRedemption();
            $this->testVoucherCodeCaseInsensitivity();
        } catch (Exception $e) {
            echo "\n[FATAL] " . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        } finally {
            $this->cleanup();
        }

        echo "\n=== Test Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo ($this->failed === 0 ? "ALL TESTS PASSED!" : "SOME TESTS FAILED") . "\n";
    }

    private function assert(bool $condition, string $message): void
    {
        if ($condition) {
            echo "  [PASS] {$message}\n";
            $this->passed++;
        } else {
            echo "  [FAIL] {$message}\n";
            $this->failed++;
        }
    }

    private function setupTestData(): void
    {
        echo "0. Setting up test data...\n";

        // Create a LearnDash course
        $courseId = wp_insert_post([
            'post_type' => 'sfwd-courses',
            'post_title' => 'Voucher Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_ids'][] = $courseId;

        // Create editions for testing
        $editionA = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Voucher Test Edition A ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionA, 'course_id', $courseId);
        update_post_meta($editionA, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionA, 'price', 500.00); // €500.00
        update_post_meta($editionA, 'capacity', 20);
        update_post_meta($editionA, 'status', 'open');
        $this->created['edition_ids']['A'] = $editionA;

        $editionB = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Voucher Test Edition B ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionB, 'course_id', $courseId);
        update_post_meta($editionB, 'start_date', date('Y-m-d', strtotime('+31 days')));
        update_post_meta($editionB, 'price', 400.00); // €400.00
        update_post_meta($editionB, 'capacity', 20);
        update_post_meta($editionB, 'status', 'open');
        $this->created['edition_ids']['B'] = $editionB;

        echo "  - Created course and 2 editions\n\n";
    }

    // ========================================
    // Test 3.1: Full Discount Voucher
    // ========================================

    private function testFullDiscountVoucher(): void
    {
        echo "3.1. Testing Full Discount Voucher...\n";

        $voucherId = $this->voucherService->createVoucher([
            'code' => 'FULL-' . time(),
            'discount_type' => DiscountType::Full->value,
        ]);

        $this->assert(
            !is_wp_error($voucherId),
            "Full discount voucher created"
        );

        if (!is_wp_error($voucherId)) {
            $this->created['voucher_ids'][] = $voucherId;

            $voucher = $this->voucherService->getVoucher($voucherId);
            $subtotal = Money::eur(500.00);
            $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

            $this->assert(
                $discount->inCents() === $subtotal->inCents(),
                "Full discount equals subtotal (50000 cents, got: " . $discount->inCents() . ")"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 3.2: Fixed Amount Voucher
    // ========================================

    private function testFixedAmountVoucher(): void
    {
        echo "3.2. Testing Fixed Amount Voucher...\n";

        $voucherId = $this->voucherService->createVoucher([
            'code' => 'FIXED-' . time(),
            'discount_type' => DiscountType::Fixed->value,
            'discount_value' => 5000, // €50.00 in cents
        ]);

        $this->assert(
            !is_wp_error($voucherId),
            "Fixed discount voucher created"
        );

        if (!is_wp_error($voucherId)) {
            $this->created['voucher_ids'][] = $voucherId;

            $voucher = $this->voucherService->getVoucher($voucherId);
            $subtotal = Money::eur(500.00);
            $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

            $this->assert(
                $discount->inCents() === 5000,
                "Fixed discount is 5000 cents (€50.00, got: " . $discount->inCents() . ")"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 3.3: Percentage Voucher
    // ========================================

    private function testPercentageVoucher(): void
    {
        echo "3.3. Testing Percentage Voucher...\n";

        $voucherId = $this->voucherService->createVoucher([
            'code' => 'PCT-' . time(),
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 20, // 20%
        ]);

        $this->assert(
            !is_wp_error($voucherId),
            "Percentage discount voucher created"
        );

        if (!is_wp_error($voucherId)) {
            $this->created['voucher_ids'][] = $voucherId;

            $voucher = $this->voucherService->getVoucher($voucherId);
            $subtotal = Money::eur(500.00);
            $discount = $this->voucherService->calculateDiscount($voucher, $subtotal);

            // 20% of 50000 = 10000
            $this->assert(
                $discount->inCents() === 10000,
                "20% discount is 10000 cents (€100.00, got: " . $discount->inCents() . ")"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 3.4: Voucher Validation - Expired
    // ========================================

    private function testVoucherValidationExpired(): void
    {
        echo "3.4. Testing Voucher Validation - Expired...\n";

        $voucherId = $this->voucherService->createVoucher([
            'code' => 'EXPIRED-' . time(),
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 10,
            'valid_until' => date('Y-m-d', strtotime('-1 day')), // Yesterday
        ]);

        $this->assert(
            !is_wp_error($voucherId),
            "Expired voucher created"
        );

        if (!is_wp_error($voucherId)) {
            $this->created['voucher_ids'][] = $voucherId;

            $voucher = $this->voucherService->getVoucher($voucherId);
            $validation = $this->voucherService->validateVoucher($voucher['code']);

            $this->assert(
                is_wp_error($validation),
                "Expired voucher fails validation"
            );

            if (is_wp_error($validation)) {
                $this->assert(
                    $validation->get_error_code() === 'expired',
                    "Error code is 'expired' (got: " . $validation->get_error_code() . ")"
                );
            }
        }

        echo "\n";
    }

    // ========================================
    // Test 3.5: Voucher Validation - Usage Limit
    // ========================================

    private function testVoucherValidationUsageLimit(): void
    {
        echo "3.5. Testing Voucher Validation - Usage Limit...\n";

        $voucherId = $this->voucherService->createVoucher([
            'code' => 'LIMITED-' . time(),
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 10,
            'usage_limit' => 1, // Only 1 use
        ]);

        $this->assert(
            !is_wp_error($voucherId),
            "Limited use voucher created"
        );

        if (!is_wp_error($voucherId)) {
            $this->created['voucher_ids'][] = $voucherId;

            // First validation should succeed
            $voucher = $this->voucherService->getVoucher($voucherId);
            $validation1 = $this->voucherService->validateVoucher($voucher['code']);

            $this->assert(
                !is_wp_error($validation1),
                "First validation succeeds"
            );

            // Simulate usage by incrementing used_count
            update_post_meta($voucherId, 'used_count', 1);
            // Clear all WordPress cache to ensure fresh data read
            clean_post_cache($voucherId);
            wp_cache_flush();

            // Second validation should fail
            $validation2 = $this->voucherService->validateVoucher($voucher['code']);

            $this->assert(
                is_wp_error($validation2),
                "Exhausted voucher fails validation"
            );

            if (is_wp_error($validation2)) {
                $this->assert(
                    $validation2->get_error_code() === 'exhausted',
                    "Error code is 'exhausted' (got: " . $validation2->get_error_code() . ")"
                );
            }
        }

        echo "\n";
    }

    // ========================================
    // Test 3.6: Voucher Validation - Edition Restriction
    // ========================================

    private function testVoucherValidationEditionRestriction(): void
    {
        echo "3.6. Testing Voucher Validation - Edition Restriction...\n";

        $editionA = $this->created['edition_ids']['A'];
        $editionB = $this->created['edition_ids']['B'];

        $voucherId = $this->voucherService->createVoucher([
            'code' => 'EDITION-' . time(),
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 10,
            'edition_id' => $editionA, // Restricted to edition A
        ]);

        $this->assert(
            !is_wp_error($voucherId),
            "Edition-restricted voucher created"
        );

        if (!is_wp_error($voucherId)) {
            $this->created['voucher_ids'][] = $voucherId;

            $voucher = $this->voucherService->getVoucher($voucherId);

            // Validation for edition A should succeed
            $validationA = $this->voucherService->validateVoucher($voucher['code'], $editionA);
            $this->assert(
                !is_wp_error($validationA),
                "Validation for edition A succeeds"
            );

            // Validation for edition B should fail
            $validationB = $this->voucherService->validateVoucher($voucher['code'], $editionB);
            $this->assert(
                is_wp_error($validationB),
                "Validation for edition B fails"
            );

            if (is_wp_error($validationB)) {
                $this->assert(
                    $validationB->get_error_code() === 'wrong_edition',
                    "Error code is 'wrong_edition' (got: " . $validationB->get_error_code() . ")"
                );
            }
        }

        echo "\n";
    }

    // ========================================
    // Test 3.7: Apply Voucher to Quote
    // ========================================

    private function testApplyVoucherToQuote(): void
    {
        echo "3.7. Testing Apply Voucher to Quote...\n";

        $userId = $this->createTestUser('voucher_quote_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids']['A'];
        $registrationId = $this->createMockRegistration($userId, $editionId);

        // Create a 20% voucher
        $voucherId = $this->voucherService->createVoucher([
            'code' => 'APPLY-' . time(),
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 20,
            'usage_limit' => 10,
        ]);
        $this->created['voucher_ids'][] = $voucherId;
        $voucher = $this->voucherService->getVoucher($voucherId);

        // Create quote with subtotal 200.00 (20000 cents)
        $items = [
            [
                'title' => 'Test Item',
                'quantity' => 1,
                'unit_price' => Money::eur(200.00),
            ],
        ];

        $quoteId = $this->quoteService->createQuote(
            $userId,
            $registrationId,
            $editionId,
            $items,
            [],
            null,
            Money::zero()
        );

        if (is_wp_error($quoteId)) {
            echo "  [FAIL] Could not create quote for test\n";
            $this->failed++;
            return;
        }

        $this->created['quote_ids'][] = $quoteId;

        // Apply voucher
        $applyResult = $this->quoteService->applyVoucher($quoteId, $voucher['code']);

        $this->assert(
            !is_wp_error($applyResult),
            "applyVoucher() succeeds"
        );

        // Check quote totals
        $quote = $this->quoteService->getQuote($quoteId, true);

        // 20% of 20000 = 4000 cents discount
        $this->assert(
            (int)($quote['discount'] ?? 0) === 4000,
            "Discount is 4000 cents (20% of 20000, got: " . ($quote['discount'] ?? 'null') . ")"
        );

        // Subtotal - Discount = 16000, Tax (21%) = 3360, Total = 19360
        $expectedTotal = 16000 + 3360; // 19360
        $this->assert(
            (int)($quote['total'] ?? 0) === $expectedTotal,
            "Total recalculated correctly (19360 cents, got: " . ($quote['total'] ?? 'null') . ")"
        );

        echo "\n";
    }

    // ========================================
    // Test 3.8: Voucher Redemption (Atomic)
    // ========================================

    private function testVoucherRedemption(): void
    {
        echo "3.8. Testing Voucher Redemption (Atomic)...\n";

        $userId = $this->createTestUser('voucher_redeem_' . time());
        $this->created['user_ids'][] = $userId;

        $voucherId = $this->voucherService->createVoucher([
            'code' => 'REDEEM-' . time(),
            'discount_type' => DiscountType::Full->value,
            'usage_limit' => 5,
        ]);
        $this->created['voucher_ids'][] = $voucherId;

        $voucher = $this->voucherService->getVoucher($voucherId);

        // Initial state
        $this->assert(
            $voucher['used_count'] === 0,
            "Initial used_count is 0"
        );

        // Redeem voucher
        $quoteId = 99999; // Mock quote ID
        $redeemResult = $this->voucherService->redeemVoucher($voucher['code'], $userId, $quoteId);

        $this->assert(
            !is_wp_error($redeemResult),
            "redeemVoucher() succeeds"
        );

        // Check used_count incremented
        $voucherAfter = $this->voucherService->getVoucher($voucherId);
        $this->assert(
            $voucherAfter['used_count'] === 1,
            "used_count incremented to 1 (got: " . $voucherAfter['used_count'] . ")"
        );

        // Check redemption recorded
        $redemptions = $voucherAfter['redemptions'] ?? [];
        $this->assert(
            count($redemptions) === 1,
            "Redemption recorded (count: " . count($redemptions) . ")"
        );

        if (count($redemptions) > 0) {
            $this->assert(
                ($redemptions[0]['user_id'] ?? 0) === $userId,
                "Redemption has correct user_id"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 3.9: Concurrent Voucher Redemption (Race Condition)
    // ========================================

    private function testConcurrentVoucherRedemption(): void
    {
        echo "3.9. Testing Concurrent Voucher Redemption (Race Condition)...\n";

        // This test verifies transaction locking prevents double-redemption
        // In a real scenario, we'd need concurrent processes

        $userA = $this->createTestUser('voucher_race_a_' . time());
        $userB = $this->createTestUser('voucher_race_b_' . time());
        $this->created['user_ids'][] = $userA;
        $this->created['user_ids'][] = $userB;

        $voucherId = $this->voucherService->createVoucher([
            'code' => 'RACE-' . time(),
            'discount_type' => DiscountType::Full->value,
            'usage_limit' => 1, // Only 1 use!
        ]);
        $this->created['voucher_ids'][] = $voucherId;

        $voucher = $this->voucherService->getVoucher($voucherId);

        // First redemption should succeed
        $result1 = $this->voucherService->redeemVoucher($voucher['code'], $userA, 1001);

        $this->assert(
            !is_wp_error($result1),
            "First redemption succeeds"
        );

        // Second redemption should fail (voucher exhausted)
        $result2 = $this->voucherService->redeemVoucher($voucher['code'], $userB, 1002);

        $this->assert(
            is_wp_error($result2),
            "Second redemption fails (voucher exhausted)"
        );

        // Verify voucher status is exhausted
        $voucherAfter = $this->voucherService->getVoucher($voucherId);
        $this->assert(
            $voucherAfter['status_enum'] === VoucherStatus::Exhausted,
            "Voucher status is 'exhausted'"
        );

        echo "\n";
    }

    // ========================================
    // Test 3.10: Voucher Code Case Insensitivity
    // ========================================

    private function testVoucherCodeCaseInsensitivity(): void
    {
        echo "3.10. Testing Voucher Code Case Insensitivity...\n";

        $code = 'CASE-' . time();

        $voucherId = $this->voucherService->createVoucher([
            'code' => $code, // Will be stored as uppercase
            'discount_type' => DiscountType::Percentage->value,
            'discount_value' => 10,
        ]);

        $this->assert(
            !is_wp_error($voucherId),
            "Voucher created with code: {$code}"
        );

        if (!is_wp_error($voucherId)) {
            $this->created['voucher_ids'][] = $voucherId;

            // Validate with lowercase
            $lowerCode = strtolower($code);
            $voucher = $this->voucherService->getVoucherByCode($lowerCode);

            $this->assert(
                $voucher !== null,
                "Voucher found with lowercase code: {$lowerCode}"
            );

            // Validate with mixed case
            $mixedCode = ucfirst(strtolower($code));
            $validation = $this->voucherService->validateVoucher($mixedCode);

            $this->assert(
                !is_wp_error($validation),
                "Validation succeeds with mixed case: {$mixedCode}"
            );
        }

        echo "\n";
    }

    // ========================================
    // HELPERS
    // ========================================

    private function createTestUser(string $username): int
    {
        $email = $username . '@test.local';
        $userId = wp_create_user($username, 'testpass123', $email);

        if (!is_wp_error($userId)) {
            update_user_meta($userId, 'first_name', 'Test');
            update_user_meta($userId, 'last_name', 'User');
            update_user_meta($userId, '_stride_test_voucher', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function createMockRegistration(int $userId, int $editionId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';

        $wpdb->insert($table, [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_path' => 'individual',
        ]);

        $regId = (int) $wpdb->insert_id;
        $this->created['registration_ids'][] = $regId;

        return $regId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        // Delete vouchers
        foreach ($this->created['voucher_ids'] as $voucherId) {
            wp_delete_post($voucherId, true);
        }
        echo "  - Deleted " . count($this->created['voucher_ids']) . " vouchers\n";

        // Delete quotes
        foreach ($this->created['quote_ids'] as $quoteId) {
            wp_delete_post($quoteId, true);
        }
        echo "  - Deleted " . count($this->created['quote_ids']) . " quotes\n";

        // Delete registrations
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        foreach ($this->created['registration_ids'] as $regId) {
            $wpdb->delete($table, ['id' => $regId], ['%d']);
        }
        echo "  - Deleted " . count($this->created['registration_ids']) . " registrations\n";

        // Delete editions
        foreach ($this->created['edition_ids'] as $editionId) {
            wp_delete_post($editionId, true);
        }
        echo "  - Deleted " . count($this->created['edition_ids']) . " editions\n";

        // Delete courses
        foreach ($this->created['course_ids'] as $courseId) {
            wp_delete_post($courseId, true);
        }
        echo "  - Deleted " . count($this->created['course_ids']) . " courses\n";

        // Delete users
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->created['user_ids'] as $userId) {
            if ($userId) {
                wp_delete_user($userId);
            }
        }
        echo "  - Deleted " . count($this->created['user_ids']) . " users\n";

        echo "  Cleanup complete.\n";
    }
}

// Run the test
$test = new StrideVoucherComprehensiveTest();
$test->run();
