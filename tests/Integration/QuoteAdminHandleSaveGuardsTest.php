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
 * QuoteAdminController::handleSave() — GUARDS + LOCK/EDIT BOUNDARY + NOTES.
 *
 * Sibling to QuoteAdminHandleSaveStatusTest (which pins the status block). This
 * test pins the parts of handleSave() that STOP a write (the three guards), the
 * subtle lock/edit boundary (billing + items freeze when locked, but unlock +
 * status change run REGARDLESS of $isEditable), and the notes sanitizer.
 *
 * Ground-truthed from source (product code UNCHANGED):
 *   GUARDS (QuoteAdminController::handleSave lines 259-272):
 *     - nonce missing/invalid  ⇒ return before any write.
 *     - DOING_AUTOSAVE          ⇒ return (not exercised here — global-define hazard).
 *     - !current_user_can('edit_post', $id) ⇒ return before any write.
 *   LOCK/EDIT BOUNDARY (lines 282-329):
 *     - $isLocked => $isEditable=false.
 *     - billing (287) and items (292) are gated by `if ($isEditable && ...)`.
 *     - lock-action (300-308) and status (310-329) run REGARDLESS of $isEditable,
 *       so a LOCKED quote can still be unlocked and status-changed.
 *   NOTES (lines 337-355):
 *     - decodes ntdst_fields[notes] (JSON string OR array), DROPS entries with
 *       a truthy `_deleted`, clamps `type` to admin/customer (default 'admin').
 *
 * READ-BACK: status/locked/billing/items/notes are all declared in
 * QuoteCPT::getFields() (billing/items/notes = json, locked = bool), so they
 * surface through QuoteRepository::getField() / QuoteService::getQuote(). Reads
 * use skipCache=true (getQuote($id, true)) because they follow a mutation.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 forwarded via --raw (disposable-DB gate;
 * a silent exit 255 = a swallowed PHP fatal, e.g. overriding a final method):
 *   ddev exec --raw -- bash -c 'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 \
 *     vendor/bin/phpunit -c phpunit-integration.xml.dist --filter QuoteAdminHandleSaveGuards'
 */
final class QuoteAdminHandleSaveGuardsTest extends IntegrationTestCase
{
    private array $subscriberIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The base fixture user is a plain subscriber; handleSave()'s
        // current_user_can('edit_post', $id) guard resolves via map_meta_cap for
        // the vad_quote CPT, so promote to administrator for the "allowed" saves.
        // (Same pattern as QuoteAdminHandleSaveStatusTest.)
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    protected function tearDown(): void
    {
        // The base class only cleans up self::$testPosts + self::$testUserId.
        // Any extra subscriber users we minted for the cap-denial test must be
        // removed here so they don't leak across tests / classes.
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->subscriberIds as $uid) {
            wp_delete_user($uid);
        }
        $this->subscriberIds = [];

        parent::tearDown();
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

    private function repository(): QuoteRepository
    {
        return ntdst_get(QuoteRepository::class);
    }

    /**
     * Create a REAL, non-new quote (has a quote_number → update path, not
     * handleNewQuoteCreation). Seeds a known billing company so lock-freeze
     * assertions have a clear before/after. Returns the quote post ID.
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
            ['company' => 'Original Company BV'], // known baseline billing
        );

        $this->assertIsInt($quoteId, 'createQuote must return an int quote id (fixture setup)');
        self::$testPosts[] = $quoteId;

        return $quoteId;
    }

    /**
     * Drive the real save path AS THE PROMOTED ADMIN. Creates the nonce AFTER the
     * user is set (nonces are user-context-dependent), merges $post into $_POST,
     * invokes handleSave(), then cleans up.
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

    /** Schema field — surfaces through getQuote() (skipCache after a mutation). */
    private function field(int $quoteId, string $key): mixed
    {
        $quote = $this->quoteService()->getQuote($quoteId, true);

        return is_wp_error($quote) ? null : ($quote[$key] ?? null);
    }

    private function billingCompany(int $quoteId): string
    {
        $billing = $this->field($quoteId, 'billing');

        return is_array($billing) ? (string) ($billing['company'] ?? '') : '';
    }

    // ---------------------------------------------------------------------
    // GUARD: nonce
    // ---------------------------------------------------------------------

    public function test_missing_nonce_blocks_billing_write(): void
    {
        $quoteId = $this->createQuoteFixture();
        $this->assertSame('Original Company BV', $this->billingCompany($quoteId), 'precondition: baseline billing');

        // Drive handleSave DIRECTLY with NO stride_quote_nonce in $_POST — the
        // first guard must return before processing billing.
        wp_set_current_user((int) self::$testUserId);
        unset($_POST['stride_quote_nonce']);
        $_POST['billing'] = ['company' => 'Hacked Company BV'];

        $this->controller()->handleSave($quoteId, get_post($quoteId));

        unset($_POST['billing']);

        $this->assertSame(
            'Original Company BV',
            $this->billingCompany($quoteId),
            'a posted billing change with NO nonce must NOT persist (nonce guard)',
        );
    }

    public function test_invalid_nonce_blocks_billing_write(): void
    {
        $quoteId = $this->createQuoteFixture();

        wp_set_current_user((int) self::$testUserId);
        $_POST['stride_quote_nonce'] = 'totally-invalid-nonce';
        $_POST['billing'] = ['company' => 'Hacked Company BV'];

        $this->controller()->handleSave($quoteId, get_post($quoteId));

        unset($_POST['stride_quote_nonce'], $_POST['billing']);

        $this->assertSame(
            'Original Company BV',
            $this->billingCompany($quoteId),
            'a posted billing change with an INVALID nonce must NOT persist (nonce guard)',
        );
    }

    // ---------------------------------------------------------------------
    // GUARD: capability (edit_post)
    // ---------------------------------------------------------------------

    public function test_user_without_edit_cap_blocks_billing_write(): void
    {
        $quoteId = $this->createQuoteFixture();
        $this->assertSame('Original Company BV', $this->billingCompany($quoteId), 'precondition: baseline billing');

        // Mint a FRESH subscriber (NOT promoted). It lacks edit_posts /
        // edit_others_posts, and it is not the quote author, so
        // current_user_can('edit_post', $quoteId) is false for it.
        $subscriberId = wp_create_user(
            'guard_sub_' . wp_generate_password(6, false),
            'testpass123',
            'guard_sub_' . wp_generate_password(6, false) . '@test.local',
        );
        $this->assertIsInt($subscriberId, 'subscriber creation must succeed');
        $this->subscriberIds[] = $subscriberId;

        $user = new \WP_User($subscriberId);
        $user->set_role('subscriber');

        // Act AS the subscriber and create the nonce AS the subscriber, so ONLY
        // the capability guard fails (the nonce is valid for this user).
        wp_set_current_user($subscriberId);
        $this->assertFalse(
            current_user_can('edit_post', $quoteId),
            'precondition: a fresh subscriber must NOT have edit_post on this quote',
        );

        $_POST['stride_quote_nonce'] = wp_create_nonce('stride_save_quote');
        $_POST['billing'] = ['company' => 'Hacked Company BV'];

        $this->controller()->handleSave($quoteId, get_post($quoteId));

        unset($_POST['stride_quote_nonce'], $_POST['billing']);

        $this->assertSame(
            'Original Company BV',
            $this->billingCompany($quoteId),
            'a posted billing change from a user WITHOUT edit_post must NOT persist (capability guard)',
        );
    }

    // ---------------------------------------------------------------------
    // LOCK/EDIT BOUNDARY: locked freezes billing + items
    // ---------------------------------------------------------------------

    public function test_locked_quote_freezes_billing(): void
    {
        $quoteId = $this->createQuoteFixture();
        $this->repository()->updateMeta($quoteId, ['locked' => true]);
        $this->assertTrue((bool) $this->field($quoteId, 'locked'), 'precondition: quote is locked');

        // Post a NEW billing company. On a locked quote ($isEditable=false) the
        // billing block (line 287) is skipped entirely.
        $this->save($quoteId, ['billing' => ['company' => 'Changed Company BV']]);

        $this->assertSame(
            'Original Company BV',
            $this->billingCompany($quoteId),
            'a LOCKED quote must NOT accept a posted billing change (billing frozen behind $isEditable)',
        );
    }

    public function test_locked_quote_freezes_items(): void
    {
        $quoteId = $this->createQuoteFixture();

        // Baseline total from the fixture (10000c item → totals derived).
        $originalTotal = (int) $this->field($quoteId, 'total');
        $this->assertGreaterThan(0, $originalTotal, 'precondition: fixture has a positive total');

        $this->repository()->updateMeta($quoteId, ['locked' => true]);
        $this->assertTrue((bool) $this->field($quoteId, 'locked'), 'precondition: quote is locked');

        // Post a completely different items set. On a locked quote the items
        // block (line 292) is skipped, so items + derived totals must not change.
        $this->save($quoteId, ['items' => [[
            'title'      => 'Injected Line',
            'quantity'   => 5,
            'unit_price' => 999,
        ]]]);

        $this->assertSame(
            $originalTotal,
            (int) $this->field($quoteId, 'total'),
            'a LOCKED quote must NOT recompute total from posted items (items frozen behind $isEditable)',
        );
    }

    // ---------------------------------------------------------------------
    // LOCK/EDIT BOUNDARY: unlock + status run REGARDLESS of $isEditable
    // ---------------------------------------------------------------------

    public function test_locked_quote_still_honors_unlock(): void
    {
        $quoteId = $this->createQuoteFixture();
        $this->repository()->updateMeta($quoteId, ['locked' => true]);
        $this->assertTrue((bool) $this->field($quoteId, 'locked'), 'precondition: quote is locked');

        // The lock-action block (lines 300-308) runs REGARDLESS of $isEditable —
        // a locked quote must still be unlockable.
        $this->save($quoteId, ['stride_lock_action' => 'unlock']);

        $this->assertFalse(
            (bool) $this->field($quoteId, 'locked'),
            'a LOCKED quote must still honor stride_lock_action=unlock (lock block runs regardless of $isEditable)',
        );
    }

    public function test_locked_quote_still_honors_status_change(): void
    {
        $quoteId = $this->createQuoteFixture(); // status 'draft'
        $this->repository()->updateMeta($quoteId, ['locked' => true]);
        $this->assertTrue((bool) $this->field($quoteId, 'locked'), 'precondition: quote is locked');

        // The status block (lines 310-329) runs REGARDLESS of $isEditable —
        // a locked quote must still accept a status change.
        $this->save($quoteId, ['stride_change_status' => 'sent']);

        $this->assertSame(
            'sent',
            (string) $this->field($quoteId, 'status'),
            'a LOCKED quote must still honor stride_change_status=sent (status block runs regardless of $isEditable)',
        );
    }

    // ---------------------------------------------------------------------
    // NOTES: drop _deleted, clamp invalid type to 'admin'
    // ---------------------------------------------------------------------

    public function test_notes_drops_deleted_and_clamps_invalid_type(): void
    {
        $quoteId = $this->createQuoteFixture();

        $posted = [
            [
                'content'  => 'Keep me',
                'type'     => 'customer',
                'author'   => 'Alice',
                'date'     => '2026-07-05',
            ],
            [
                'content'  => 'Delete me',
                'type'     => 'admin',
                'author'   => 'Bob',
                'date'     => '2026-07-05',
                '_deleted' => true,
            ],
            [
                'content'  => 'Weird type',
                'type'     => 'not-a-real-type',
                'author'   => 'Carol',
                'date'     => '2026-07-05',
            ],
        ];

        // Notes are posted under ntdst_fields[notes] as a JSON string (matches the
        // hidden #stride_notes_data input the metabox renders).
        $this->save($quoteId, ['ntdst_fields' => ['notes' => json_encode($posted)]]);

        $notes = $this->field($quoteId, 'notes');
        $this->assertIsArray($notes, 'notes must surface as an array');

        // The _deleted note is dropped → 2 survive (Keep me + Weird type).
        $this->assertCount(
            2,
            $notes,
            'the _deleted note must be dropped; only the two non-deleted notes persist',
        );

        $contents = array_column($notes, 'content');
        $this->assertContains('Keep me', $contents, 'the normal note must persist');
        $this->assertContains('Weird type', $contents, 'the invalid-type note itself must persist (only its type is clamped)');
        $this->assertNotContains('Delete me', $contents, 'the _deleted note must NOT persist');

        // The first note keeps its valid 'customer' type; the invalid-type note is
        // clamped to 'admin'.
        $byContent = [];
        foreach ($notes as $note) {
            $byContent[$note['content']] = $note;
        }
        $this->assertSame('customer', $byContent['Keep me']['type'], 'a valid type (customer) must be preserved');
        $this->assertSame(
            'admin',
            $byContent['Weird type']['type'],
            'an invalid note type must be clamped to the default "admin"',
        );
    }
}
