<?php
/**
 * Debug Quote Meta Updates
 */

if (!defined('ABSPATH')) {
    exit(1);
}

use Stride\Modules\Invoicing\QuoteCPT;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\QuoteService;

echo "=== Debug Quote Meta Updates ===" . PHP_EOL . PHP_EOL;

// Create a simple quote
$quoteId = wp_insert_post([
    'post_type' => QuoteCPT::POST_TYPE,
    'post_title' => 'Debug Quote',
    'post_status' => 'publish',
]);

echo "1. Created quote: {$quoteId}" . PHP_EOL;

// Set some initial meta
update_post_meta($quoteId, 'status', 'draft');
update_post_meta($quoteId, 'subtotal', 50000);
echo "   Set initial meta: status=draft, subtotal=50000" . PHP_EOL . PHP_EOL;

// Test 1: Direct WP meta update
echo "2. Testing direct WordPress meta update..." . PHP_EOL;
$updated = update_post_meta($quoteId, 'discount', 10000);
echo "   update_post_meta('discount', 10000): " . ($updated ? 'returned ID/true' : 'returned false') . PHP_EOL;

$discount = get_post_meta($quoteId, 'discount', true);
echo "   get_post_meta('discount'): {$discount}" . PHP_EOL . PHP_EOL;

// Test 2: Via QuoteRepository
echo "3. Testing QuoteRepository->updateMeta()..." . PHP_EOL;
$repo = ntdst_get(QuoteRepository::class);
$result = $repo->updateMeta($quoteId, [
    'voucher_code' => 'TEST-CODE',
    'discount' => 20000,
]);
echo "   updateMeta returned: " . ($result ? 'true' : 'false') . PHP_EOL;

$voucherCode = get_post_meta($quoteId, 'voucher_code', true);
$discount2 = get_post_meta($quoteId, 'discount', true);
echo "   get_post_meta('voucher_code'): '{$voucherCode}'" . PHP_EOL;
echo "   get_post_meta('discount'): {$discount2}" . PHP_EOL . PHP_EOL;

// Test 3: Via model()->updateMeta()
echo "4. Testing model()->updateMeta() individually..." . PHP_EOL;
$model = ntdst_data()->model(QuoteCPT::POST_TYPE);
$result = $model->updateMeta($quoteId, 'test_field', 'test_value');
echo "   model->updateMeta('test_field', 'test_value'): " . var_export($result, true) . PHP_EOL;

$testField = get_post_meta($quoteId, 'test_field', true);
echo "   get_post_meta('test_field'): '{$testField}'" . PHP_EOL . PHP_EOL;

// Clean up
wp_delete_post($quoteId, true);
echo "Cleaned up. Test complete." . PHP_EOL;
