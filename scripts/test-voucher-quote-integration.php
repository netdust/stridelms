<?php
/**
 * Stride LMS V1 - Voucher to Quote Integration Test
 *
 * Tests the complete flow of applying a voucher to a quote.
 *
 * Run with: ddev exec wp eval-file scripts/test-voucher-quote-integration.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-voucher-quote-integration.php\n";
    exit(1);
}

use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\QuoteCPT;
use Stride\Modules\Invoicing\VoucherCPT;
use Stride\Modules\Edition\EditionCPT;
use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Domain\DiscountType;

echo "=== Stride LMS V1 - Voucher to Quote Integration Test ===" . PHP_EOL . PHP_EOL;

$voucherService = ntdst_get(VoucherService::class);
$quoteService = ntdst_get(QuoteService::class);

$created = [];

try {
    // Step 1: Create test user
    echo "1. Creating test user..." . PHP_EOL;
    $userId = wp_create_user('voucher_test_user_' . time(), 'testpass123', 'voucher_test_' . time() . '@test.local');
    if (is_wp_error($userId)) {
        throw new Exception('Failed to create user: ' . $userId->get_error_message());
    }
    $created['user_id'] = $userId;
    echo "   Created user ID: {$userId}" . PHP_EOL . PHP_EOL;

    // Step 2: Create test edition (minimal, direct post creation)
    echo "2. Creating test edition..." . PHP_EOL;
    $editionId = wp_insert_post([
        'post_type' => EditionCPT::POST_TYPE,
        'post_title' => 'Test Edition ' . time(),
        'post_status' => 'publish',
    ]);
    if (is_wp_error($editionId)) {
        throw new Exception('Failed to create edition: ' . $editionId->get_error_message());
    }
    update_post_meta($editionId, 'price', 500.00); // €500.00
    update_post_meta($editionId, 'status', 'open');
    $created['edition_id'] = $editionId;
    echo "   Created edition ID: {$editionId}" . PHP_EOL . PHP_EOL;

    // Step 3: Create test registration (minimal)
    echo "3. Creating test registration..." . PHP_EOL;
    global $wpdb;
    $table = $wpdb->prefix . 'vad_registrations';
    $wpdb->insert($table, [
        'user_id' => $userId,
        'edition_id' => $editionId,
        'status' => 'confirmed',
        'enrollment_path' => 'individual',
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql'),
    ]);
    $registrationId = $wpdb->insert_id;
    $created['registration_id'] = $registrationId;
    echo "   Created registration ID: {$registrationId}" . PHP_EOL . PHP_EOL;

    // Step 4: Create a quote for this registration
    echo "4. Creating quote for registration..." . PHP_EOL;
    $items = [
        [
            'title' => 'Test Edition',
            'quantity' => 1,
            'unit_price' => Money::eur(500),
        ],
    ];
    $billing = [
        'name' => 'Test User',
        'email' => 'test@test.local',
    ];

    $quoteId = $quoteService->createQuote(
        $userId,
        $registrationId,
        $editionId,
        $items,
        $billing,
        null, // No voucher yet
        null  // No discount yet
    );

    if (is_wp_error($quoteId)) {
        throw new Exception('Failed to create quote: ' . $quoteId->get_error_message());
    }
    $created['quote_id'] = $quoteId;

    $quote = $quoteService->getQuote($quoteId);
    echo "   Created quote ID: {$quoteId}" . PHP_EOL;
    echo "   Quote number: " . ($quote['quote_number'] ?? 'N/A') . PHP_EOL;
    echo "   Status: " . ($quote['status_enum']->label() ?? 'N/A') . PHP_EOL;
    echo "   Subtotal: " . $quote['subtotal_money']->format() . PHP_EOL;
    echo "   Discount: " . $quote['discount_money']->format() . PHP_EOL;
    echo "   Tax (21%): " . $quote['tax_money']->format() . PHP_EOL;
    echo "   Total: " . $quote['total_money']->format() . PHP_EOL . PHP_EOL;

    // Step 5: Create a voucher
    echo "5. Creating test voucher (20% off)..." . PHP_EOL;
    $voucherId = $voucherService->createVoucher([
        'code' => 'INTEGRATION-TEST-' . time(),
        'discount_type' => DiscountType::Percentage->value,
        'discount_value' => 20,
        'usage_limit' => 5,
    ]);

    if (is_wp_error($voucherId)) {
        throw new Exception('Failed to create voucher: ' . $voucherId->get_error_message());
    }
    $created['voucher_id'] = $voucherId;

    $voucher = $voucherService->getVoucher($voucherId);
    echo "   Created voucher ID: {$voucherId}" . PHP_EOL;
    echo "   Code: " . $voucher['code'] . PHP_EOL;
    echo "   Discount: " . $voucher['discount_value'] . "%" . PHP_EOL . PHP_EOL;

    // Step 6: Apply voucher to quote
    echo "6. Applying voucher to quote..." . PHP_EOL;
    $result = $quoteService->applyVoucher($quoteId, $voucher['code']);

    if (is_wp_error($result)) {
        throw new Exception('Failed to apply voucher: ' . $result->get_error_message());
    }

    // Debug: Check raw meta values
    echo "   DEBUG: Raw meta values after applyVoucher:" . PHP_EOL;
    echo "   - discount (raw): " . get_post_meta($quoteId, 'discount', true) . PHP_EOL;
    echo "   - voucher_code (raw): " . get_post_meta($quoteId, 'voucher_code', true) . PHP_EOL;
    echo "   - tax (raw): " . get_post_meta($quoteId, 'tax', true) . PHP_EOL;
    echo "   - total (raw): " . get_post_meta($quoteId, 'total', true) . PHP_EOL;

    // Refresh quote data (skip cache to get fresh values)
    $quote = $quoteService->getQuote($quoteId, true);
    echo "   Voucher applied successfully!" . PHP_EOL;
    echo "   Updated Subtotal: " . $quote['subtotal_money']->format() . PHP_EOL;
    echo "   Updated Discount: " . $quote['discount_money']->format() . PHP_EOL;
    echo "   Updated Tax: " . $quote['tax_money']->format() . PHP_EOL;
    echo "   Updated Total: " . $quote['total_money']->format() . PHP_EOL;
    echo "   Voucher Code: " . ($quote['voucher_code'] ?? 'N/A') . PHP_EOL . PHP_EOL;

    // Step 7: Verify calculations
    echo "7. Verifying calculations..." . PHP_EOL;
    $subtotalCents = $quote['subtotal_money']->inCents(); // 50000 (€500)
    $expectedDiscount = (int) round($subtotalCents * 0.20); // 10000 (€100)
    $actualDiscount = $quote['discount_money']->inCents();
    $discountMatch = $actualDiscount === $expectedDiscount;

    $netAmount = $subtotalCents - $actualDiscount; // 40000 (€400)
    $expectedTax = (int) round($netAmount * 0.21); // 8400 (€84)
    $actualTax = $quote['tax_money']->inCents();
    $taxMatch = $actualTax === $expectedTax;

    $expectedTotal = $netAmount + $expectedTax; // 48400 (€484)
    $actualTotal = $quote['total_money']->inCents();
    $totalMatch = $actualTotal === $expectedTotal;

    echo "   Subtotal: " . ($subtotalCents / 100) . " EUR (expected: 500.00 EUR)" . PHP_EOL;
    echo "   Discount: " . ($actualDiscount / 100) . " EUR (expected: " . ($expectedDiscount / 100) . " EUR) - " . ($discountMatch ? "OK" : "MISMATCH") . PHP_EOL;
    echo "   Tax: " . ($actualTax / 100) . " EUR (expected: " . ($expectedTax / 100) . " EUR) - " . ($taxMatch ? "OK" : "MISMATCH") . PHP_EOL;
    echo "   Total: " . ($actualTotal / 100) . " EUR (expected: " . ($expectedTotal / 100) . " EUR) - " . ($totalMatch ? "OK" : "MISMATCH") . PHP_EOL . PHP_EOL;

    // Step 8: Verify voucher redemption
    echo "8. Verifying voucher redemption..." . PHP_EOL;
    $updatedVoucher = $voucherService->getVoucher($voucherId);
    $usedCount = $updatedVoucher['used_count'] ?? 0;
    $redemptions = $updatedVoucher['redemptions'] ?? [];

    echo "   Used count: {$usedCount} (expected: 1) - " . ($usedCount === 1 ? "OK" : "MISMATCH") . PHP_EOL;
    echo "   Redemptions logged: " . count($redemptions) . PHP_EOL . PHP_EOL;

    // Summary
    $allPassed = $discountMatch && $taxMatch && $totalMatch && ($usedCount === 1);
    if ($allPassed) {
        echo "=== SUCCESS: All voucher-to-quote integration tests passed! ===" . PHP_EOL;
    } else {
        echo "=== FAILED: Some tests did not pass ===" . PHP_EOL;
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
} finally {
    // Cleanup
    echo PHP_EOL . "Cleaning up test data..." . PHP_EOL;

    if (!empty($created['quote_id'])) {
        wp_delete_post($created['quote_id'], true);
        echo "   Deleted quote {$created['quote_id']}" . PHP_EOL;
    }

    if (!empty($created['voucher_id'])) {
        wp_delete_post($created['voucher_id'], true);
        echo "   Deleted voucher {$created['voucher_id']}" . PHP_EOL;
    }

    if (!empty($created['registration_id'])) {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        $wpdb->delete($table, ['id' => $created['registration_id']]);
        echo "   Deleted registration {$created['registration_id']}" . PHP_EOL;
    }

    if (!empty($created['edition_id'])) {
        wp_delete_post($created['edition_id'], true);
        echo "   Deleted edition {$created['edition_id']}" . PHP_EOL;
    }

    if (!empty($created['user_id'])) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($created['user_id']);
        echo "   Deleted user {$created['user_id']}" . PHP_EOL;
    }

    echo "Cleanup complete." . PHP_EOL;
}
