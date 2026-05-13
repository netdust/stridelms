<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Handlers\QuoteUpdateHandler;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;

/**
 * Integration tests for QuoteUpdateHandler
 *
 * Tests quote update, voucher application, and cancellation.
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter QuoteUpdateHandler
 */
class QuoteUpdateHandlerIntegrationTest extends IntegrationTestCase
{
    private QuoteUpdateHandler $handler;
    private QuoteService $quoteService;
    private VoucherService $voucherService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new QuoteUpdateHandler();
        $this->quoteService = ntdst_get(QuoteService::class);
        $this->voucherService = ntdst_get(VoucherService::class);
        $this->actingAs(self::$testUserId);
    }

    // =========================================================================
    // AUTHENTICATION
    // =========================================================================

    /**
     * @test
     */
    public function updateQuoteRejectsUnauthenticatedUser(): void
    {
        wp_set_current_user(0);

        $result = $this->handler->handleUpdateQuote([], ['quote_id' => 1]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_logged_in', $result->get_error_code());
    }

    /**
     * @test
     */
    public function applyVoucherRejectsUnauthenticatedUser(): void
    {
        wp_set_current_user(0);

        $result = $this->handler->handleApplyVoucher([], [
            'quote_id' => 1,
            'voucher_code' => 'TEST',
        ]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_logged_in', $result->get_error_code());
    }

    /**
     * @test
     */
    public function cancelQuoteRejectsUnauthenticatedUser(): void
    {
        wp_set_current_user(0);

        $result = $this->handler->handleCancelQuote([], ['quote_id' => 1]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_logged_in', $result->get_error_code());
    }

    // =========================================================================
    // INPUT VALIDATION
    // =========================================================================

    /**
     * @test
     */
    public function updateQuoteRejectsMissingQuoteId(): void
    {
        $result = $this->handler->handleUpdateQuote([], []);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_input', $result->get_error_code());
    }

    /**
     * @test
     */
    public function applyVoucherRejectsMissingInput(): void
    {
        $result = $this->handler->handleApplyVoucher([], ['quote_id' => 1]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_input', $result->get_error_code());
    }

    /**
     * @test
     */
    public function cancelQuoteRejectsMissingQuoteId(): void
    {
        $result = $this->handler->handleCancelQuote([], []);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('invalid_input', $result->get_error_code());
    }

    // =========================================================================
    // ACCESS CONTROL
    // =========================================================================

    /**
     * @test
     */
    public function updateQuoteRejectsNonOwner(): void
    {
        $editionId = $this->createTestEdition();

        // Create quote for different user
        $otherUserId = wp_create_user('other_' . time(), 'pass123', 'other_' . time() . '@test.local');
        $quoteId = $this->createTestQuote($otherUserId, $editionId);

        $result = $this->handler->handleUpdateQuote([], ['quote_id' => $quoteId]);

        $this->assertTrue(is_wp_error($result));
        $this->assertContains($result->get_error_code(), ['forbidden', 'not_found']);

        // Cleanup
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($otherUserId);
    }

    /**
     * @test
     */
    public function cancelQuoteRejectsNonOwner(): void
    {
        $editionId = $this->createTestEdition();

        // Create quote for different user
        $otherUserId = wp_create_user('other2_' . time(), 'pass123', 'other2_' . time() . '@test.local');
        $quoteId = $this->createTestQuote($otherUserId, $editionId);

        $result = $this->handler->handleCancelQuote([], ['quote_id' => $quoteId]);

        $this->assertTrue(is_wp_error($result));
        $this->assertContains($result->get_error_code(), ['forbidden', 'not_found']);

        // Cleanup
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($otherUserId);
    }

    // =========================================================================
    // UPDATE QUOTE
    // =========================================================================

    /**
     * @test
     */
    public function canUpdateQuoteBilling(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId);

        $result = $this->handler->handleUpdateQuote([], [
            'quote_id' => $quoteId,
            'billing' => [
                'organisation' => 'Test Company BV',
                'email' => 'billing@test.local',
                'address' => 'Teststraat 123',
                'postal_code' => '1234 AB',
                'city' => 'Amsterdam',
                'vat_number' => 'NL123456789B01',
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('bijgewerkt', $result['message']);
    }

    /**
     * @test
     */
    public function updateQuoteRejectsNonDraftQuote(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId, [
            'meta' => ['status' => 'sent'],
        ]);

        $result = $this->handler->handleUpdateQuote([], [
            'quote_id' => $quoteId,
            'billing' => ['organisation' => 'Test'],
        ]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('not_editable', $result->get_error_code());
    }

    // =========================================================================
    // CANCEL QUOTE
    // =========================================================================

    /**
     * @test
     */
    public function canCancelOwnQuote(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId);

        $result = $this->handler->handleCancelQuote([], ['quote_id' => $quoteId]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertStringContainsString('geannuleerd', $result['message']);
    }

    // =========================================================================
    // FILTER REGISTRATION
    // =========================================================================

    /**
     * @test
     */
    public function updateQuoteFilterIsRegistered(): void
    {
        $hasFilter = has_filter('ntdst/api_data/stride_update_quote');
        $this->assertNotFalse($hasFilter);
    }

    /**
     * @test
     */
    public function applyVoucherFilterIsRegistered(): void
    {
        $hasFilter = has_filter('ntdst/api_data/stride_apply_quote_voucher');
        $this->assertNotFalse($hasFilter);
    }

    /**
     * @test
     */
    public function cancelQuoteFilterIsRegistered(): void
    {
        $hasFilter = has_filter('ntdst/api_data/stride_cancel_quote');
        $this->assertNotFalse($hasFilter);
    }

    // =========================================================================
    // FILTER EXECUTION
    // =========================================================================

    /**
     * @test
     */
    public function updateQuoteFilterExecutes(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId);

        $result = apply_filters('ntdst/api_data/stride_update_quote', [], [
            'quote_id' => $quoteId,
            'billing' => ['organisation' => 'Filter Test'],
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    /**
     * @test
     */
    public function cancelQuoteFilterExecutes(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId);

        $result = apply_filters('ntdst/api_data/stride_cancel_quote', [], [
            'quote_id' => $quoteId,
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // LOCKED QUOTE — edit restriction
    // =========================================================================

    /**
     * @test
     */
    public function updateQuoteRejectsWhenLocked(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId);
        update_post_meta($quoteId, 'locked', true);

        $result = $this->handler->handleUpdateQuote([], [
            'quote_id' => $quoteId,
            'billing' => ['company' => 'Should Not Save'],
        ]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('locked', $result->get_error_code());
    }

    /**
     * @test
     */
    public function applyVoucherRejectsWhenLocked(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId);
        update_post_meta($quoteId, 'locked', true);

        $result = $this->handler->handleApplyVoucher([], [
            'quote_id' => $quoteId,
            'voucher_code' => 'ANYTHING',
        ]);

        $this->assertTrue(is_wp_error($result));
        $this->assertEquals('locked', $result->get_error_code());
    }

    /**
     * @test
     */
    public function unlockedQuoteCanStillBeUpdated(): void
    {
        // Sanity check: regression guard for the locked check above.
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId);
        // explicitly unlocked
        update_post_meta($quoteId, 'locked', false);

        $result = $this->handler->handleUpdateQuote([], [
            'quote_id' => $quoteId,
            'billing' => ['company' => 'Updated Co'],
        ]);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    // =========================================================================
    // BULK lock/unlock by edition
    // =========================================================================

    /**
     * @test
     */
    public function bulkSetLockedByEditionLocksAllLinkedQuotes(): void
    {
        $editionId = $this->createTestEdition();
        $quote1 = $this->createTestQuote(self::$testUserId, $editionId);
        $quote2 = $this->createTestQuote(self::$testUserId, $editionId);
        $quote3 = $this->createTestQuote(self::$testUserId, $editionId);

        $summary = $this->quoteService->bulkSetLockedByEdition($editionId, true);

        $this->assertEquals(3, $summary['total']);
        $this->assertEquals(3, $summary['changed']);
        $this->assertEquals(0, $summary['unchanged']);
        $this->assertTrue((bool) get_post_meta($quote1, 'locked', true));
        $this->assertTrue((bool) get_post_meta($quote2, 'locked', true));
        $this->assertTrue((bool) get_post_meta($quote3, 'locked', true));
    }

    /**
     * @test
     */
    public function bulkSetLockedIsIdempotent(): void
    {
        $editionId = $this->createTestEdition();
        $quoteId = $this->createTestQuote(self::$testUserId, $editionId);
        update_post_meta($quoteId, 'locked', true);

        $summary = $this->quoteService->bulkSetLockedByEdition($editionId, true);

        $this->assertEquals(1, $summary['total']);
        $this->assertEquals(0, $summary['changed']);
        $this->assertEquals(1, $summary['unchanged']);
    }

    /**
     * @test
     */
    public function bulkSetLockedUnlocksAllLinkedQuotes(): void
    {
        $editionId = $this->createTestEdition();
        $quote1 = $this->createTestQuote(self::$testUserId, $editionId);
        $quote2 = $this->createTestQuote(self::$testUserId, $editionId);
        update_post_meta($quote1, 'locked', true);
        update_post_meta($quote2, 'locked', true);

        $summary = $this->quoteService->bulkSetLockedByEdition($editionId, false);

        $this->assertEquals(2, $summary['changed']);
        $this->assertFalse((bool) get_post_meta($quote1, 'locked', true));
        $this->assertFalse((bool) get_post_meta($quote2, 'locked', true));
    }

    /**
     * @test
     */
    public function bulkSetLockedDoesNotTouchOtherEditions(): void
    {
        $editionA = $this->createTestEdition();
        $editionB = $this->createTestEdition();
        $quoteA = $this->createTestQuote(self::$testUserId, $editionA);
        $quoteB = $this->createTestQuote(self::$testUserId, $editionB);

        $this->quoteService->bulkSetLockedByEdition($editionA, true);

        $this->assertTrue((bool) get_post_meta($quoteA, 'locked', true));
        $this->assertFalse((bool) get_post_meta($quoteB, 'locked', true));
    }
}
