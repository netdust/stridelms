<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\Money;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Invoicing\Admin\QuoteAdminController;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;

/**
 * QuoteAdminController::handleSave() — status-transition + auto-lock behavior.
 *
 * Pins the status block (QuoteAdminController::handleSave() lines 310-329),
 * which is currently completely untested. Drives the REAL save path (nonce +
 * capability guard + $_POST) against a REAL non-new quote created via
 * QuoteService::createQuote() (which generates a quote_number, so handleSave
 * takes the update path — NOT handleNewQuoteCreation).
 *
 * Contract asserted here (ground-truthed from source, product code UNCHANGED):
 *   1. →sent stamps sent_at.
 *   2. →sent is idempotent: an existing sent_at is NOT overwritten.
 *   3. →exported sets locked=true even with NO stride_lock_action posted
 *      (the auto-lock invariant — the load-bearing assertion).
 *   4. →exported stamps exported_at and is idempotent.
 *   5. →cancelled stamps cancelled_at.
 *   6. DENIAL: an invalid status ('bogus') changes nothing.
 *   7. DENIAL: posting the SAME status as current is a no-op (no re-stamp).
 *
 * READ-BACK NOTE: `sent_at` and `locked` are declared in QuoteCPT::getFields(),
 * so they surface through QuoteService::getQuote()/QuoteRepository::getField().
 * `exported_at` and `cancelled_at` are NOT in the CPT schema — handleSave writes
 * them via updateMeta (which persists any key), but they never surface through
 * the schema-limited `fields` read path. The CPT uses meta_prefix => '', so the
 * raw meta key equals the field name; those two are therefore asserted via raw
 * get_post_meta($id, 'exported_at'|'cancelled_at', true).
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec bash -c \
 *   'STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter QuoteAdminHandleSaveStatus'
 */
final class QuoteAdminHandleSaveStatusTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // handleSave()'s current_user_can('edit_post', $id) guard resolves via
        // map_meta_cap for the vad_quote CPT — the base fixture user is a plain
        // subscriber, so promote it to administrator (same pattern as every
        // other admin-save integration test, e.g. EditionDeadlineFieldsPersistTest).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    private function controller(): QuoteAdminController
    {
        return new QuoteAdminController(
            ntdst_get(QuoteService::class),
            ntdst_get(QuoteRepository::class),
            ntdst_get(VoucherService::class),
            ntdst_get(EditionRepository::class),
        );
    }

    private function quoteService(): QuoteService
    {
        return ntdst_get(QuoteService::class);
    }

    /**
     * Create a REAL, non-new quote (has a quote_number → update path, not
     * handleNewQuoteCreation). Returns the quote post ID.
     */
    private function createQuoteFixture(): int
    {
        $editionId = $this->createTestEdition();

        $items = [[
            'title'      => 'Test Edition',
            'quantity'   => 1,
            'unit_price' => Money::cents(10000),
        ]];

        $quoteId = $this->quoteService()->createQuote(
            (int) self::$testUserId,
            0, // registrationId — stored only, never dereferenced by createQuote
            $editionId,
            $items,
        );

        $this->assertIsInt($quoteId, 'createQuote must return an int quote id (fixture setup)');
        self::$testPosts[] = $quoteId;

        return $quoteId;
    }

    /**
     * Drive the real save path: set current user, create the nonce AFTER the
     * user is set (nonces are user-context-dependent), merge $post into $_POST,
     * invoke handleSave(), then clean up $_POST keys.
     *
     * @param array<string, mixed> $post
     */
    private function save(int $quoteId, array $post): void
    {
        wp_set_current_user((int) self::$testUserId);

        $_POST['stride_quote_nonce'] = wp_create_nonce('stride_save_quote');
        foreach ($post as $key => $value) {
            $_POST[$key] = $value;
        }

        $this->controller()->handleSave($quoteId, get_post($quoteId));

        unset($_POST['stride_quote_nonce']);
        foreach (array_keys($post) as $key) {
            unset($_POST[$key]);
        }
    }

    /** Schema field — surfaces through getField(). */
    private function field(int $quoteId, string $key): mixed
    {
        return ntdst_get(QuoteRepository::class)->getField($quoteId, $key);
    }

    /** Non-schema field (exported_at/cancelled_at) — read raw (meta_prefix is ''). */
    private function rawMeta(int $quoteId, string $key): string
    {
        return (string) get_post_meta($quoteId, $key, true);
    }

    /**
     * Read the persisted status. NOTE: named currentStatus() — NOT status() —
     * because PHPUnit\Framework\TestCase::status() is a `final` method and a
     * `status()` helper here fatals at class-compile time (E_ERROR: cannot
     * override final method), which phpunit swallows into a silent exit 255.
     */
    private function currentStatus(int $quoteId): string
    {
        $quote = $this->quoteService()->getQuote($quoteId, true);

        return is_wp_error($quote) ? '' : (string) ($quote['status'] ?? '');
    }

    // ---------------------------------------------------------------------

    public function test_transition_to_sent_stamps_sent_at(): void
    {
        $quoteId = $this->createQuoteFixture(); // starts as 'draft'

        $this->save($quoteId, ['stride_change_status' => 'sent']);

        $this->assertSame('sent', $this->currentStatus($quoteId), '→sent must change status to sent');
        $this->assertNotEmpty(
            $this->field($quoteId, 'sent_at'),
            '→sent must stamp a non-empty sent_at timestamp',
        );
    }

    public function test_transition_to_sent_is_idempotent(): void
    {
        $quoteId = $this->createQuoteFixture(); // 'draft'

        // First →sent stamps sent_at.
        $this->save($quoteId, ['stride_change_status' => 'sent']);
        $originalSentAt = (string) $this->field($quoteId, 'sent_at');
        $this->assertNotEmpty($originalSentAt, 'precondition: first →sent must stamp sent_at');

        // The status block only fires when the new status DIFFERS from current.
        // Move away from 'sent' (→draft), then back to 'sent'. sent_at is already
        // set, so the second →sent must NOT overwrite it (idempotent stamp).
        $this->save($quoteId, ['stride_change_status' => 'draft']);
        $this->assertSame('draft', $this->currentStatus($quoteId), 'precondition: must be back to draft');

        $this->save($quoteId, ['stride_change_status' => 'sent']);

        $this->assertSame('sent', $this->currentStatus($quoteId), 'second →sent must land on sent again');
        $this->assertSame(
            $originalSentAt,
            (string) $this->field($quoteId, 'sent_at'),
            'a second →sent must NOT overwrite an existing sent_at (idempotent stamp)',
        );
    }

    public function test_transition_to_exported_auto_locks_without_lock_action(): void
    {
        $quoteId = $this->createQuoteFixture(); // 'draft', unlocked

        // No stride_lock_action posted — ONLY the status change.
        $this->save($quoteId, ['stride_change_status' => 'exported']);

        $this->assertSame('exported', $this->currentStatus($quoteId), '→exported must change status to exported');
        $this->assertTrue(
            (bool) $this->field($quoteId, 'locked'),
            '→exported must auto-lock the quote (locked=true) even with NO stride_lock_action posted',
        );
    }

    public function test_transition_to_exported_stamps_exported_at_idempotently(): void
    {
        $quoteId = $this->createQuoteFixture(); // 'draft'

        $this->save($quoteId, ['stride_change_status' => 'exported']);
        $originalExportedAt = $this->rawMeta($quoteId, 'exported_at');
        $this->assertNotEmpty($originalExportedAt, '→exported must stamp a non-empty exported_at');

        // Transition away (→cancelled), then back to exported. exported_at already
        // set → must NOT be overwritten by the second →exported.
        $this->save($quoteId, ['stride_change_status' => 'cancelled']);
        $this->assertSame('cancelled', $this->currentStatus($quoteId), 'precondition: must be cancelled');

        $this->save($quoteId, ['stride_change_status' => 'exported']);

        $this->assertSame('exported', $this->currentStatus($quoteId), 'second →exported must land on exported again');
        $this->assertSame(
            $originalExportedAt,
            $this->rawMeta($quoteId, 'exported_at'),
            'a second →exported must NOT overwrite an existing exported_at (idempotent stamp)',
        );
    }

    public function test_transition_to_cancelled_stamps_cancelled_at(): void
    {
        $quoteId = $this->createQuoteFixture(); // 'draft'

        $this->save($quoteId, ['stride_change_status' => 'cancelled']);

        $this->assertSame('cancelled', $this->currentStatus($quoteId), '→cancelled must change status to cancelled');
        $this->assertNotEmpty(
            $this->rawMeta($quoteId, 'cancelled_at'),
            '→cancelled must stamp a non-empty cancelled_at timestamp',
        );
    }

    public function test_invalid_status_is_rejected(): void
    {
        $quoteId = $this->createQuoteFixture(); // 'draft'

        $this->save($quoteId, ['stride_change_status' => 'bogus']);

        $this->assertSame(
            'draft',
            $this->currentStatus($quoteId),
            'an invalid status (bogus) must NOT change the stored status',
        );
        $this->assertEmpty(
            $this->field($quoteId, 'sent_at'),
            'an invalid status must NOT stamp sent_at',
        );
        $this->assertEmpty(
            $this->rawMeta($quoteId, 'exported_at'),
            'an invalid status must NOT stamp exported_at',
        );
        $this->assertEmpty(
            $this->rawMeta($quoteId, 'cancelled_at'),
            'an invalid status must NOT stamp cancelled_at',
        );
    }

    public function test_same_status_is_a_no_op(): void
    {
        $quoteId = $this->createQuoteFixture(); // 'draft'

        // Move to 'sent' so sent_at is stamped, capturing the value.
        $this->save($quoteId, ['stride_change_status' => 'sent']);
        $originalSentAt = (string) $this->field($quoteId, 'sent_at');
        $this->assertNotEmpty($originalSentAt, 'precondition: sent_at stamped on first →sent');

        // Post the SAME status as current ('sent'). The block only fires when the
        // new status DIFFERS — so this must be a no-op and must NOT re-stamp.
        $this->save($quoteId, ['stride_change_status' => 'sent']);

        $this->assertSame('sent', $this->currentStatus($quoteId), 'status must remain sent');
        $this->assertSame(
            $originalSentAt,
            (string) $this->field($quoteId, 'sent_at'),
            'posting the same status as current must NOT re-stamp sent_at (no-op transition)',
        );
    }
}
