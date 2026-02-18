<?php
/**
 * Stride LMS - Quote Lifecycle Tests
 *
 * Tests quote creation, status transitions, and billing scenarios.
 *
 * Run with: ddev exec wp eval-file scripts/test-quote-lifecycle.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-quote-lifecycle.php\n";
    exit(1);
}

use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Edition\EditionService;

class StrideQuoteLifecycleTest
{
    private QuoteService $quoteService;
    private VoucherService $voucherService;
    private EditionService $editionService;
    private SubscriberService $subscriberService;

    private array $created = [
        'quote_ids' => [],
        'voucher_ids' => [],
        'user_ids' => [],
        'edition_id' => null,
        'course_id' => null,
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->quoteService = ntdst_get(QuoteService::class);
        $this->voucherService = ntdst_get(VoucherService::class);
        $this->editionService = ntdst_get(EditionService::class);
        $this->subscriberService = ntdst_get(SubscriberService::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Quote Lifecycle Tests ===\n\n";

        // Set current user to admin for permission checks
        wp_set_current_user(1);

        try {
            $this->setupTestData();
            $this->testQuoteCreation();
            $this->testQuoteStatusTransitions();
            $this->testQuoteWithVoucher();
            $this->testBillingData();
            $this->testQuoteLocking();
            $this->testOGMPaymentReference();
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
            'post_title' => 'Quote Test Course ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        $this->created['course_id'] = $courseId;

        // Create an edition with pricing
        $startDate = date('Y-m-d', strtotime('+30 days'));
        $editionId = $this->editionService->createEdition([
            FieldRegistry::EDITION_COURSE_ID => $courseId,
            'title' => 'Quote Test Edition ' . time(),
            FieldRegistry::EDITION_START_DATE => $startDate,
            FieldRegistry::EDITION_END_DATE => date('Y-m-d', strtotime('+31 days')),
            FieldRegistry::EDITION_PRICE => 300.00,
            FieldRegistry::EDITION_CAPACITY => 20,
            FieldRegistry::EDITION_STATUS => FieldRegistry::EDITION_STATUS_OPEN,
        ]);
        $this->created['edition_id'] = $editionId;

        // Register the edition price resolver filter
        add_filter('stride/quote/resolve_item', function ($item, $itemType, $itemId) {
            if ($itemType === 'edition' && $itemId === $this->created['edition_id']) {
                $edition = $this->editionService->getEdition($itemId);
                return [
                    'valid' => true,
                    'title' => $edition['title'] ?? 'Test Edition',
                    'price' => $this->editionService->getPrice($itemId),
                    'meta' => ['edition_id' => $itemId],
                ];
            }
            return $item;
        }, 10, 3);

        add_filter('stride/quote/resolve_price', function ($price, $itemType, $itemId) {
            if ($itemType === 'edition' && $itemId === $this->created['edition_id']) {
                return $this->editionService->getPrice($itemId);
            }
            return $price;
        }, 10, 3);

        echo "  - Created course {$courseId} and edition {$editionId}\n\n";
    }

    // ========================================
    // A. QUOTE CREATION (6 tests)
    // ========================================

    private function testQuoteCreation(): void
    {
        echo "A. Testing Quote Creation...\n";

        // Create test user
        $userId = $this->createTestUser('quote_test_user1_' . time());
        $this->created['user_ids'][] = $userId;

        // A1. Create quote for edition enrollment
        $quoteId = $this->quoteService->createQuoteForItem(
            $userId,
            'edition',
            $this->created['edition_id'],
            []
        );
        $this->assert(!is_wp_error($quoteId), "A1. Create quote for edition enrollment");

        if (!is_wp_error($quoteId)) {
            $this->created['quote_ids'][] = $quoteId;
            $quote = $this->quoteService->getQuote($quoteId);

            // A2. Quote has correct subtotal (edition price)
            $this->assert(
                $quote && abs($quote['subtotal'] - 300.00) < 0.01,
                "A2. Quote has correct subtotal (300.00, got: " . ($quote['subtotal'] ?? 'null') . ")"
            );

            // A3. Quote has correct VAT (21%)
            $expectedTax = 300.00 * 0.21;
            $this->assert(
                $quote && abs($quote['tax'] - $expectedTax) < 0.01,
                "A3. Quote has correct VAT (63.00, got: " . ($quote['tax'] ?? 'null') . ")"
            );

            // A4. Quote has correct total (subtotal + VAT)
            $expectedTotal = 300.00 * 1.21;
            $this->assert(
                $quote && abs($quote['total'] - $expectedTotal) < 0.01,
                "A4. Quote has correct total (363.00, got: " . ($quote['total'] ?? 'null') . ")"
            );

            // A5. Quote number format (OFF-YYYY-NNNNN)
            $numberMatches = preg_match('/^OFF-\d{4}-\d{5}$/', $quote['number']);
            $this->assert(
                $numberMatches === 1,
                "A5. Quote number format matches OFF-YYYY-NNNNN: {$quote['number']}"
            );
        }

        // A6. Duplicate quote prevention (same user + item)
        $quoteId2 = $this->quoteService->createQuoteForItem(
            $userId,
            'edition',
            $this->created['edition_id'],
            []
        );
        $this->assert(
            is_wp_error($quoteId2) && $quoteId2->get_error_code() === 'quote_exists',
            "A6. Duplicate quote prevention (same user + item)"
        );

        echo "\n";
    }

    // ========================================
    // B. QUOTE STATUS TRANSITIONS (8 tests)
    // ========================================

    private function testQuoteStatusTransitions(): void
    {
        echo "B. Testing Quote Status Transitions...\n";

        // Create fresh user and quote for transition tests
        $userId = $this->createTestUser('quote_status_user_' . time());
        $this->created['user_ids'][] = $userId;

        $quoteId = $this->quoteService->createQuoteForItem(
            $userId,
            'edition',
            $this->created['edition_id'],
            []
        );
        $this->created['quote_ids'][] = $quoteId;

        // B1. Draft quote can be updated
        $updateResult = $this->quoteService->updateQuote($quoteId, [
            'order_number' => 'PO-12345',
        ]);
        $this->assert(!is_wp_error($updateResult), "B1. Draft quote can be updated");
        $quote = $this->quoteService->getQuote($quoteId);
        $this->assert($quote['order_number'] === 'PO-12345', "    - Order number updated");

        // B2. Draft quote can be sent
        $sendResult = $this->quoteService->sendQuote($quoteId);
        $this->assert(!is_wp_error($sendResult), "B2. Draft quote can be sent");
        $quote = $this->quoteService->getQuote($quoteId);
        $this->assert($quote['status'] === QuoteService::STATUS_SENT, "    - Status is 'sent'");
        $this->assert(!empty($quote['sent_at']), "    - sent_at timestamp set");

        // B3. Sent quote can be exported
        $exportResult = $this->quoteService->exportQuote($quoteId);
        $this->assert(!is_wp_error($exportResult), "B3. Sent quote can be exported");
        $quote = $this->quoteService->getQuote($quoteId);
        $this->assert($quote['status'] === QuoteService::STATUS_EXPORTED, "    - Status is 'exported'");
        $this->assert(!empty($quote['exported_at']), "    - exported_at timestamp set");

        // B4. Exported quote cannot be cancelled (blocked)
        $cancelResult = $this->quoteService->cancelQuote($quoteId);
        $this->assert(
            is_wp_error($cancelResult) && $cancelResult->get_error_code() === 'cannot_cancel',
            "B4. Exported quote cannot be cancelled"
        );

        // Create new quote for cancellation tests
        $userId2 = $this->createTestUser('quote_cancel_user_' . time());
        $this->created['user_ids'][] = $userId2;
        $quote2Id = $this->quoteService->createQuoteForItem($userId2, 'edition', $this->created['edition_id'], []);
        $this->created['quote_ids'][] = $quote2Id;

        // B5. Draft quote can be cancelled
        $cancelResult = $this->quoteService->cancelQuote($quote2Id, 'Test cancellation');
        $this->assert(!is_wp_error($cancelResult), "B5. Draft quote can be cancelled");
        $quote2 = $this->quoteService->getQuote($quote2Id);
        $this->assert($quote2['status'] === QuoteService::STATUS_CANCELLED, "    - Status is 'cancelled'");

        // Create another quote for sent+cancel test
        $userId3 = $this->createTestUser('quote_sent_cancel_user_' . time());
        $this->created['user_ids'][] = $userId3;
        $quote3Id = $this->quoteService->createQuoteForItem($userId3, 'edition', $this->created['edition_id'], []);
        $this->created['quote_ids'][] = $quote3Id;
        $this->quoteService->sendQuote($quote3Id);

        // B6. Sent quote can be cancelled
        $cancelResult = $this->quoteService->cancelQuote($quote3Id, 'Customer requested');
        $this->assert(!is_wp_error($cancelResult), "B6. Sent quote can be cancelled");

        // B7. Cancelled quote cannot be re-sent
        $resendResult = $this->quoteService->sendQuote($quote3Id);
        $this->assert(
            is_wp_error($resendResult),
            "B7. Cancelled quote cannot be re-sent"
        );

        // B8. Status transition fires correct hooks
        $hookFired = false;
        add_action('stride/quote/created', function () use (&$hookFired) {
            $hookFired = true;
        });
        $userId4 = $this->createTestUser('quote_hook_user_' . time());
        $this->created['user_ids'][] = $userId4;
        $quote4Id = $this->quoteService->createQuoteForItem($userId4, 'edition', $this->created['edition_id'], []);
        $this->created['quote_ids'][] = $quote4Id;
        $this->assert($hookFired, "B8. Status transition fires correct hooks (stride/quote/created)");

        echo "\n";
    }

    // ========================================
    // C. QUOTE WITH VOUCHER (4 tests)
    // ========================================

    private function testQuoteWithVoucher(): void
    {
        echo "C. Testing Quote With Voucher...\n";

        // Create voucher
        $voucherId = $this->voucherService->createVoucher([
            'discount_type' => VoucherService::DISCOUNT_PERCENTAGE,
            'discount_value' => 20,
        ]);
        $this->created['voucher_ids'][] = $voucherId;
        $voucher = $this->voucherService->getVoucher($voucherId);

        // Register voucher discount filter
        add_filter('stride/quote/calculate_discount', function ($discount, $code, $itemType, $itemId, $price) use ($voucher) {
            if ($code === $voucher['code']) {
                return $this->voucherService->calculateDiscount($voucher, $itemType, $itemId, $price);
            }
            return $discount;
        }, 10, 5);

        // Create user and quote with voucher
        $userId = $this->createTestUser('quote_voucher_user_' . time());
        $this->created['user_ids'][] = $userId;

        // C1. Create quote with voucher discount
        $quoteId = $this->quoteService->createQuoteForItem(
            $userId,
            'edition',
            $this->created['edition_id'],
            ['voucher_code' => $voucher['code']]
        );
        $this->assert(!is_wp_error($quoteId), "C1. Create quote with voucher discount");

        if (!is_wp_error($quoteId)) {
            $this->created['quote_ids'][] = $quoteId;
            $quote = $this->quoteService->getQuote($quoteId);

            // C2. Quote items include discount line
            $items = $quote['items'] ?? [];
            $hasDiscountLine = false;
            $discountAmount = 0;
            foreach ($items as $item) {
                if (($item['type'] ?? '') === 'discount') {
                    $hasDiscountLine = true;
                    $discountAmount = $item['amount'] ?? 0;
                    break;
                }
            }
            $this->assert($hasDiscountLine, "C2. Quote items include discount line");

            // C3. Discount line has correct negative amount OR discount stored in quote field
            // 20% of 300 = 60
            $expectedDiscount = 60.00;
            $quoteDiscount = $quote['discount'] ?? 0;
            $discountCorrect = abs($discountAmount + $expectedDiscount) < 0.01 || abs($quoteDiscount - $expectedDiscount) < 0.01;
            $this->assert(
                $discountCorrect,
                "C3. Discount correct (line: {$discountAmount}, quote.discount: {$quoteDiscount})"
            );

            // C4. Quote total reflects discount
            // Subtotal: 300, Discount: 60, Net: 240, VAT (21%): 50.40, Total: 290.40
            $expectedTotal = (300.00 - 60.00) * 1.21;
            $this->assert(
                abs($quote['total'] - $expectedTotal) < 0.01,
                "C4. Quote total reflects discount (290.40, got: {$quote['total']})"
            );
        }

        echo "\n";
    }

    // ========================================
    // D. BILLING DATA (4 tests)
    // ========================================

    private function testBillingData(): void
    {
        echo "D. Testing Billing Data...\n";

        // Create user with billing info
        $userId = $this->createTestUser('quote_billing_user_' . time());
        $this->created['user_ids'][] = $userId;

        // Set some user meta for billing
        update_user_meta($userId, 'billing_company', 'Test Company BV');
        update_user_meta($userId, 'billing_address_1', 'Test Street 123');
        update_user_meta($userId, 'billing_city', 'Brussels');
        update_user_meta($userId, 'billing_postcode', '1000');

        // D1. Quote has user billing address
        $quoteId = $this->quoteService->createQuoteForItem(
            $userId,
            'edition',
            $this->created['edition_id'],
            [
                'invoice_org_name' => 'Invoice Company BV',
                'invoice_address' => 'Invoice Street 456',
                'invoice_city' => 'Antwerp',
                'invoice_postal_code' => '2000',
            ]
        );
        $this->assert(!is_wp_error($quoteId), "D1. Quote created with billing data");

        if (!is_wp_error($quoteId)) {
            $this->created['quote_ids'][] = $quoteId;
            $quote = $this->quoteService->getQuote($quoteId);
            $billing = $quote['billing'] ?? [];

            $this->assert(
                ($billing['organisation'] ?? '') === 'Invoice Company BV',
                "    - Organisation set from invoice data"
            );
            $this->assert(
                ($billing['city'] ?? '') === 'Antwerp',
                "    - City set from invoice data"
            );
        }

        // D2. Quote has company billing (if user linked)
        // Note: We can't easily test FluentCRM company linking without mocking
        // So we test the billing data passed directly
        $this->assert(
            isset($quote['billing']) && !empty($quote['billing']),
            "D2. Quote has billing data object"
        );

        // D3. VAT number validation (mock)
        // Create quote with VAT number
        $userId2 = $this->createTestUser('quote_vat_user_' . time());
        $this->created['user_ids'][] = $userId2;
        $quote2Id = $this->quoteService->createQuoteForItem(
            $userId2,
            'edition',
            $this->created['edition_id'],
            ['invoice_vat' => 'BE0123456789']
        );
        $this->created['quote_ids'][] = $quote2Id;
        $quote2 = $this->quoteService->getQuote($quote2Id);
        $this->assert(
            ($quote2['billing']['vat_number'] ?? '') === 'BE0123456789',
            "D3. VAT number stored in billing data"
        );

        // D4. Billing address fallback chain
        // When no explicit invoice data provided, should use user's default
        $userId3 = $this->createTestUser('quote_fallback_user_' . time());
        $this->created['user_ids'][] = $userId3;
        $quote3Id = $this->quoteService->createQuoteForItem(
            $userId3,
            'edition',
            $this->created['edition_id'],
            [] // No explicit billing
        );
        $this->created['quote_ids'][] = $quote3Id;
        $quote3 = $this->quoteService->getQuote($quote3Id);
        $this->assert(
            isset($quote3['billing']),
            "D4. Billing address fallback chain (billing object exists)"
        );

        echo "\n";
    }

    // ========================================
    // E. QUOTE LOCKING (6 tests)
    // ========================================

    private function testQuoteLocking(): void
    {
        echo "E. Testing Quote Locking...\n";

        // Create user and quote
        $userId = $this->createTestUser('quote_lock_user_' . time());
        $this->created['user_ids'][] = $userId;

        $quoteId = $this->quoteService->createQuoteForItem(
            $userId,
            'edition',
            $this->created['edition_id'],
            []
        );
        $this->created['quote_ids'][] = $quoteId;
        $quote = $this->quoteService->getQuote($quoteId);

        // E1. Quote starts unlocked
        $this->assert(
            !$quote['locked'],
            "E1. Quote starts unlocked"
        );

        // E2. canEditQuote returns true for unlocked quote
        $canEdit = $this->quoteService->canEditQuote($quoteId);
        $this->assert(
            !is_wp_error($canEdit),
            "E2. canEditQuote returns true for unlocked quote"
        );

        // E3. Lock quote manually
        $lockResult = $this->quoteService->lockQuote($quoteId, 'test_lock');
        $this->assert(
            !is_wp_error($lockResult),
            "E3. Lock quote manually succeeds"
        );

        $lockedQuote = $this->quoteService->getQuote($quoteId);
        $this->assert(
            $lockedQuote['locked'] === true,
            "    - Quote is now locked"
        );

        // E4. Locked quote rejects updates (non-admin)
        wp_set_current_user($userId); // Switch to non-admin user
        $canEditLocked = $this->quoteService->canEditQuote($quoteId);
        $this->assert(
            is_wp_error($canEditLocked),
            "E4. Locked quote rejects updates for non-admin"
        );
        wp_set_current_user(1); // Switch back to admin

        // E5. Unlock quote (admin only)
        $unlockResult = $this->quoteService->unlockQuote($quoteId);
        $this->assert(
            !is_wp_error($unlockResult),
            "E5. Unlock quote succeeds for admin"
        );

        $unlockedQuote = $this->quoteService->getQuote($quoteId);
        $this->assert(
            $unlockedQuote['locked'] === false,
            "    - Quote is now unlocked"
        );

        // E6. Locking adds note to audit trail
        $notes = $lockedQuote['notes'] ?? [];
        $hasLockNote = false;
        foreach ($notes as $note) {
            if (str_contains($note['message'] ?? '', 'vergrendeld')) {
                $hasLockNote = true;
                break;
            }
        }
        $this->assert(
            $hasLockNote,
            "E6. Locking adds note to audit trail"
        );

        echo "\n";
    }

    // ========================================
    // F. OGM PAYMENT REFERENCE (6 tests)
    // ========================================

    private function testOGMPaymentReference(): void
    {
        echo "F. Testing OGM Payment Reference...\n";

        $generator = new OGMGenerator();

        // F1. Generate OGM from quote number
        $ogm = $generator->generate('OFF-2026-00123');
        $this->assert(
            !empty($ogm) && str_starts_with($ogm, '+++'),
            "F1. Generate OGM from quote number (got: {$ogm})"
        );

        // F2. OGM has correct format +++NNN/NNNN/NNNCC+++
        $formatMatch = preg_match('/^\+\+\+\d{3}\/\d{4}\/\d{5}\+\+\+$/', $ogm);
        $this->assert(
            $formatMatch === 1,
            "F2. OGM has correct format (+++NNN/NNNN/NNNCC+++)"
        );

        // F3. Generated OGM passes validation
        $isValid = $generator->validate($ogm);
        $this->assert(
            $isValid,
            "F3. Generated OGM passes validation"
        );

        // F4. Invalid OGM fails validation
        $invalidOgm = '+++123/4567/89099+++'; // Wrong check digits
        $isInvalid = !$generator->validate($invalidOgm);
        $this->assert(
            $isInvalid,
            "F4. Invalid OGM fails validation"
        );

        // F5. Quote includes payment reference
        $userId = $this->createTestUser('quote_ogm_user_' . time());
        $this->created['user_ids'][] = $userId;

        $quoteId = $this->quoteService->createQuoteForItem(
            $userId,
            'edition',
            $this->created['edition_id'],
            []
        );
        $this->created['quote_ids'][] = $quoteId;
        $quote = $this->quoteService->getQuote($quoteId);

        $this->assert(
            !empty($quote['payment_reference']),
            "F5. Quote includes payment reference (got: " . ($quote['payment_reference'] ?? 'none') . ")"
        );

        // F6. Quote payment reference matches its number
        if (!empty($quote['payment_reference']) && !empty($quote['number'])) {
            $expectedOgm = $generator->generate($quote['number']);
            $this->assert(
                $quote['payment_reference'] === $expectedOgm,
                "F6. Quote payment reference matches its number"
            );
        } else {
            $this->assert(false, "F6. Quote payment reference matches its number (missing data)");
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
            update_user_meta($userId, '_stride_test_quote', true);
        }

        return is_wp_error($userId) ? 0 : $userId;
    }

    private function cleanup(): void
    {
        echo "Cleaning Up Test Data...\n";

        // Delete quotes
        foreach ($this->created['quote_ids'] as $quoteId) {
            wp_delete_post($quoteId, true);
        }
        echo "  - Deleted " . count($this->created['quote_ids']) . " quotes\n";

        // Delete vouchers
        foreach ($this->created['voucher_ids'] as $voucherId) {
            wp_delete_post($voucherId, true);
        }
        echo "  - Deleted " . count($this->created['voucher_ids']) . " vouchers\n";

        // Delete edition
        if ($this->created['edition_id']) {
            wp_delete_post($this->created['edition_id'], true);
            echo "  - Deleted edition {$this->created['edition_id']}\n";
        }

        // Delete course
        if ($this->created['course_id']) {
            wp_delete_post($this->created['course_id'], true);
            echo "  - Deleted course {$this->created['course_id']}\n";
        }

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
$test = new StrideQuoteLifecycleTest();
$test->run();
