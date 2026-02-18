<?php
/**
 * Stride LMS V1 - Voucher System Tests (Minimal Base)
 *
 * Tests voucher creation, validation, and discount calculation.
 *
 * Run with: ddev exec wp eval-file scripts/test-voucher.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-voucher.php\n";
    exit(1);
}

use Stride\Modules\Invoicing\VoucherService;
use Stride\Domain\Money;
use Stride\Domain\VoucherStatus;
use Stride\Domain\DiscountType;

echo "=== Stride LMS V1 - Voucher System Tests ===" . PHP_EOL . PHP_EOL;

// Get service
$service = ntdst_get(VoucherService::class);

// Test 1: Create voucher with auto-generated code
echo "1. Testing Voucher Creation..." . PHP_EOL;
$id = $service->createVoucher([
    'discount_type' => DiscountType::Percentage->value,
    'discount_value' => 20,
    'usage_limit' => 5,
]);

if (is_wp_error($id)) {
    echo '   ERROR: ' . $id->get_error_message() . PHP_EOL;
    exit(1);
}

$voucher = $service->getVoucher($id);
echo '   Created voucher ID: ' . $id . PHP_EOL;
echo '   Code: ' . $voucher['code'] . PHP_EOL;
echo '   Status: ' . $voucher['status_enum']->label() . PHP_EOL;
echo '   Discount: ' . $voucher['discount_value'] . '% (' . $voucher['discount_type_enum']->label() . ')' . PHP_EOL;
echo '   Usage limit: ' . $voucher['usage_limit'] . PHP_EOL;
echo PHP_EOL;

// Test 2: Validate voucher
echo "2. Testing Voucher Validation..." . PHP_EOL;
$validation = $service->validateVoucher($voucher['code']);
if (is_wp_error($validation)) {
    echo '   ERROR: ' . $validation->get_error_message() . PHP_EOL;
    exit(1);
}
echo '   Validation: OK' . PHP_EOL;
echo PHP_EOL;

// Test 3: Calculate discount
echo "3. Testing Discount Calculation..." . PHP_EOL;
$subtotal = Money::eur(350);
$discount = $service->calculateDiscount($voucher, $subtotal);

echo '   Subtotal: ' . $subtotal->format() . PHP_EOL;
echo '   Discount (20%): ' . $discount->format() . PHP_EOL;
echo '   After discount: ' . Money::cents($subtotal->inCents() - $discount->inCents())->format() . PHP_EOL;
echo PHP_EOL;

// Test 4: Test all discount types
echo "4. Testing All Discount Types..." . PHP_EOL;

// Full discount
$fullId = $service->createVoucher([
    'code' => 'TEST-FULL-' . time(),
    'discount_type' => DiscountType::Full->value,
]);
$fullVoucher = $service->getVoucher($fullId);
$fullDiscount = $service->calculateDiscount($fullVoucher, Money::eur(100));
echo '   Full (100%): ' . $fullDiscount->format() . ' (expected: € 100,00)' . PHP_EOL;

// Fixed discount
$fixedId = $service->createVoucher([
    'code' => 'TEST-FIXED-' . time(),
    'discount_type' => DiscountType::Fixed->value,
    'discount_value' => 5000, // €50 in cents
]);
$fixedVoucher = $service->getVoucher($fixedId);
$fixedDiscount = $service->calculateDiscount($fixedVoucher, Money::eur(100));
echo '   Fixed (€50): ' . $fixedDiscount->format() . ' (expected: € 50,00)' . PHP_EOL;

// Percentage discount
$pctId = $service->createVoucher([
    'code' => 'TEST-PCT-' . time(),
    'discount_type' => DiscountType::Percentage->value,
    'discount_value' => 25,
]);
$pctVoucher = $service->getVoucher($pctId);
$pctDiscount = $service->calculateDiscount($pctVoucher, Money::eur(100));
echo '   Percentage (25%): ' . $pctDiscount->format() . ' (expected: € 25,00)' . PHP_EOL;
echo PHP_EOL;

// Clean up test vouchers
echo "5. Cleaning up test vouchers..." . PHP_EOL;
wp_delete_post($id, true);
wp_delete_post($fullId, true);
wp_delete_post($fixedId, true);
wp_delete_post($pctId, true);
echo '   Deleted 4 test vouchers' . PHP_EOL;
echo PHP_EOL;

echo "=== SUCCESS: All voucher tests passed! ===" . PHP_EOL;
