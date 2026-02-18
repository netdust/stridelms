<?php
/**
 * Stride LMS - Quote Service Tests
 *
 * Tests quote creation, status transitions, and calculations.
 *
 * Run with: ddev exec wp eval-file scripts/test-quote.php
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/test-quote.php\n";
    exit(1);
}

use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Edition\EditionService;
use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;

class StrideQuoteServiceTest
{
    private QuoteService $quoteService;
    private EditionService $editionService;

    private array $created = [
        'user_ids' => [],
        'edition_ids' => [],
        'course_ids' => [],
        'registration_ids' => [],
        'quote_ids' => [],
    ];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->quoteService = ntdst_get(QuoteService::class);
        $this->editionService = ntdst_get(EditionService::class);
    }

    public function run(): void
    {
        echo "=== Stride LMS Quote Service Tests ===\n\n";

        wp_set_current_user(1);

        try {
            $this->setupTestData();
            $this->testQuoteCreation();
            $this->testQuoteStatusTransitions();
            $this->testInvalidStatusTransition();
            $this->testQuoteWithLineItems();
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
        $this->created['course_ids'][] = $courseId;

        // Create an edition
        $editionId = wp_insert_post([
            'post_type' => 'vad_edition',
            'post_title' => 'Quote Test Edition ' . time(),
            'post_status' => 'publish',
            'post_author' => 1,
        ]);
        update_post_meta($editionId, 'course_id', $courseId);
        update_post_meta($editionId, 'start_date', date('Y-m-d', strtotime('+30 days')));
        update_post_meta($editionId, 'end_date', date('Y-m-d', strtotime('+31 days')));
        update_post_meta($editionId, 'price', 500.00); // €500.00 // 500.00 in cents
        update_post_meta($editionId, 'capacity', 20);
        update_post_meta($editionId, 'status', 'open');
        $this->created['edition_ids'][] = $editionId;

        echo "  - Created course {$courseId} and edition {$editionId}\n\n";
    }

    // ========================================
    // Test 2.1: Quote Creation
    // ========================================

    private function testQuoteCreation(): void
    {
        echo "2.1. Testing Quote Creation...\n";

        $userId = $this->createTestUser('quote_create_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids'][0];

        // Create a mock registration ID
        $registrationId = $this->createMockRegistration($userId, $editionId);

        // Create quote with known amount
        $items = [
            [
                'title' => 'Test Edition',
                'quantity' => 1,
                'unit_price' => Money::eur(500.00),
            ],
        ];

        $quoteId = $this->quoteService->createQuote(
            $userId,
            $registrationId,
            $editionId,
            $items,
            ['organisation' => 'Test Org', 'city' => 'Brussels'],
            null,
            Money::zero()
        );

        $this->assert(
            !is_wp_error($quoteId),
            "Quote creation returns ID"
        );

        if (!is_wp_error($quoteId)) {
            $this->created['quote_ids'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            if (!is_wp_error($quote)) {
                // Check quote number format (OFF-YYYY-NNNN)
                $numberFormat = preg_match('/^OFF-\d{4}-\d{4,5}$/', $quote['quote_number'] ?? '');
                $this->assert(
                    $numberFormat === 1,
                    "Quote number has format OFF-YYYY-NNNN (got: " . ($quote['quote_number'] ?? 'null') . ")"
                );

                // Check subtotal (500.00 = 50000 cents)
                $this->assert(
                    (int)($quote['subtotal'] ?? 0) === 50000,
                    "Subtotal is correct (50000 cents, got: " . ($quote['subtotal'] ?? 'null') . ")"
                );

                // Check tax (21% VAT = 10500 cents)
                $expectedTax = 10500;
                $this->assert(
                    (int)($quote['tax'] ?? 0) === $expectedTax,
                    "Tax is 21% VAT (10500 cents, got: " . ($quote['tax'] ?? 'null') . ")"
                );

                // Check total (subtotal + tax = 60500 cents)
                $expectedTotal = 60500;
                $this->assert(
                    (int)($quote['total'] ?? 0) === $expectedTotal,
                    "Total is correct (60500 cents, got: " . ($quote['total'] ?? 'null') . ")"
                );
            }
        }

        echo "\n";
    }

    // ========================================
    // Test 2.2: Quote Status Transitions
    // ========================================

    private function testQuoteStatusTransitions(): void
    {
        echo "2.2. Testing Quote Status Transitions...\n";

        $userId = $this->createTestUser('quote_status_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids'][0];
        $registrationId = $this->createMockRegistration($userId, $editionId);

        $items = [
            [
                'title' => 'Status Test',
                'quantity' => 1,
                'unit_price' => Money::eur(300.00),
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

        // Verify initial status is draft
        $quote = $this->quoteService->getQuote($quoteId, true);
        $this->assert(
            ($quote['status'] ?? '') === QuoteStatus::Draft->value,
            "Initial status is 'draft'"
        );

        // Mark as sent
        $sendResult = $this->quoteService->markAsSent($quoteId);
        $this->assert(
            !is_wp_error($sendResult) && $sendResult === true,
            "markAsSent() succeeds"
        );

        $quote = $this->quoteService->getQuote($quoteId, true);
        $this->assert(
            ($quote['status'] ?? '') === QuoteStatus::Sent->value,
            "Status is now 'sent'"
        );

        $this->assert(
            !empty($quote['sent_at']),
            "sent_at timestamp is set"
        );

        echo "\n";
    }

    // ========================================
    // Test 2.3: Invalid Status Transition
    // ========================================

    private function testInvalidStatusTransition(): void
    {
        echo "2.3. Testing Invalid Status Transition...\n";

        $userId = $this->createTestUser('quote_invalid_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids'][0];
        $registrationId = $this->createMockRegistration($userId, $editionId);

        $items = [
            [
                'title' => 'Invalid Status Test',
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

        // Mark as sent first
        $this->quoteService->markAsSent($quoteId);

        // Try to mark as sent again (should fail - only draft can be sent)
        $sendAgainResult = $this->quoteService->markAsSent($quoteId);

        $this->assert(
            is_wp_error($sendAgainResult),
            "Cannot mark sent quote as sent again"
        );

        if (is_wp_error($sendAgainResult)) {
            $this->assert(
                $sendAgainResult->get_error_code() === 'invalid_status',
                "Error code is 'invalid_status' (got: " . $sendAgainResult->get_error_code() . ")"
            );
        }

        echo "\n";
    }

    // ========================================
    // Test 2.4: Quote with Line Items
    // ========================================

    private function testQuoteWithLineItems(): void
    {
        echo "2.4. Testing Quote with Multiple Line Items...\n";

        $userId = $this->createTestUser('quote_items_' . time());
        $this->created['user_ids'][] = $userId;
        $editionId = $this->created['edition_ids'][0];
        $registrationId = $this->createMockRegistration($userId, $editionId);

        // Create quote with multiple line items
        $items = [
            [
                'title' => 'Main Course Fee',
                'quantity' => 1,
                'unit_price' => Money::eur(300.00),
            ],
            [
                'title' => 'Materials',
                'quantity' => 2,
                'unit_price' => Money::eur(50.00),
            ],
            [
                'title' => 'Certificate Fee',
                'quantity' => 1,
                'unit_price' => Money::eur(25.00),
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

        $this->assert(
            !is_wp_error($quoteId),
            "Quote with multiple items created"
        );

        if (!is_wp_error($quoteId)) {
            $this->created['quote_ids'][] = $quoteId;

            $quote = $this->quoteService->getQuote($quoteId);

            if (!is_wp_error($quote)) {
                // Check items stored
                $storedItems = $quote['items'] ?? [];
                $this->assert(
                    count($storedItems) === 3,
                    "Quote has 3 line items (got: " . count($storedItems) . ")"
                );

                // Calculate expected subtotal: 300 + (2 * 50) + 25 = 425.00 = 42500 cents
                $expectedSubtotal = 42500;
                $this->assert(
                    (int)($quote['subtotal'] ?? 0) === $expectedSubtotal,
                    "Subtotal is aggregated correctly (42500 cents, got: " . ($quote['subtotal'] ?? 'null') . ")"
                );
            }
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
$test = new StrideQuoteServiceTest();
$test->run();
