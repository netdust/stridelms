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
 * QuoteAdminController::handleSave() — event-firing + denial paths.
 *
 * Pins the three do_action() side-effect blocks in handleSave()
 * (QuoteAdminController.php lines 362-392), each of which is guarded and each
 * of which has denial conditions that unit tests can't reach. Drives the REAL
 * save path (nonce + capability guard + $_POST) against a REAL non-new quote
 * created via QuoteService::createQuote() (which generates a quote_number, so
 * handleSave takes the update path — NOT handleNewQuoteCreation).
 *
 * Contract asserted here (ground-truthed from source, product code UNCHANGED):
 *   SEND (lines 368-380): sanitize_email($_POST['stride_send_to']), fires
 *     stride/quote/send_email($postId, $sendTo, $sendCc) ONLY if $sendTo is a
 *     non-empty valid email.
 *     1. valid stride_send_to        ⇒ fires once with that email.
 *     2. DENIAL: empty stride_send_to ⇒ does NOT fire.
 *     3. DENIAL: invalid email        ⇒ sanitize_email empties it ⇒ does NOT fire.
 *   CANCEL (lines 362-366): fires stride/quote/cancelled(['quote_id'=>$postId])
 *     ONLY when $updateData['status'] === 'cancelled' AND
 *     !empty($_POST['stride_cancel_registration']). Note $updateData['status']
 *     is only set on an ACTUAL transition (new status valid AND differs from
 *     current — line 314), so both a real →cancelled AND the flag are required.
 *     4. →cancelled + flag           ⇒ fires with quote_id.
 *     5. DENIAL: →cancelled, no flag ⇒ does NOT fire.
 *     6. DENIAL: →sent + flag        ⇒ status not cancelled ⇒ does NOT fire.
 *   REGEN (lines 382-392): fires stride/quote/regenerate_pdf($postId) whenever
 *     !empty($_POST['stride_regenerate_pdf']).
 *     7. stride_regenerate_pdf=1     ⇒ fires with quote_id.
 *
 * Event spies are registered per-test with a $fired capture buffer and removed
 * in tearDown so no cross-test leakage. Real listeners (StrideMailBridge,
 * AuditBridge, QuotePDFGenerator, EnrollmentService) may ALSO run in this env;
 * we assert only against our spy, never against the real side effect, so a
 * missing mailer/PDF binary can't make these tests flap.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec --raw -- bash -c \
 *   'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit \
 *    -c phpunit-integration.xml.dist --filter QuoteAdminHandleSaveEvents'
 */
final class QuoteAdminHandleSaveEventsTest extends IntegrationTestCase
{
    /** @var array<int, callable> spy closures to detach in tearDown */
    private array $spies = [];

    protected function setUp(): void
    {
        parent::setUp();

        // handleSave()'s current_user_can('edit_post', $id) guard resolves via
        // map_meta_cap for the vad_quote CPT — the base fixture user is a plain
        // subscriber, so promote it to administrator (same pattern as Q-T1 /
        // every other admin-save integration test).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    protected function tearDown(): void
    {
        foreach ($this->spies as [$hook, $cb]) {
            remove_action($hook, $cb, 10);
        }
        $this->spies = [];

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
     * Register an action spy that appends every invocation's args to $buffer.
     * The spy is remembered for detach in tearDown. Accepts up to 3 args so it
     * matches the widest event (send_email fires with 3).
     *
     * @param array<int, array<string, mixed>> $buffer captured by reference
     */
    private function spyOn(string $hook, array &$buffer): void
    {
        $cb = static function ($a = null, $b = null, $c = null) use (&$buffer): void {
            $buffer[] = ['a' => $a, 'b' => $b, 'c' => $c];
        };
        add_action($hook, $cb, 10, 3);
        $this->spies[] = [$hook, $cb];
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

    // ---------------------------------------------------------------------
    // SEND
    // ---------------------------------------------------------------------

    public function test_send_with_valid_email_fires_send_email_event(): void
    {
        $quoteId = $this->createQuoteFixture();

        $fired = [];
        $this->spyOn('stride/quote/send_email', $fired);

        $this->save($quoteId, [
            'stride_send_quote' => '1',
            'stride_send_to'    => 'klant@example.com',
            'stride_send_cc'    => 'cc@example.com',
        ]);

        $this->assertCount(
            1,
            $fired,
            'a send with a valid stride_send_to must fire stride/quote/send_email exactly once',
        );
        $this->assertSame($quoteId, $fired[0]['a'], 'send_email must fire with the quote post id');
        $this->assertSame('klant@example.com', $fired[0]['b'], 'send_email must fire with the sanitized send-to email');
        $this->assertSame('cc@example.com', $fired[0]['c'], 'send_email must fire with the sanitized cc email');
    }

    public function test_send_with_empty_email_does_not_fire_send_email_event(): void
    {
        $quoteId = $this->createQuoteFixture();

        $fired = [];
        $this->spyOn('stride/quote/send_email', $fired);

        // stride_send_quote posted, but stride_send_to empty ⇒ $sendTo falsy ⇒ no event.
        $this->save($quoteId, [
            'stride_send_quote' => '1',
            'stride_send_to'    => '',
        ]);

        $this->assertCount(
            0,
            $fired,
            'a send with an EMPTY stride_send_to must NOT fire stride/quote/send_email',
        );
    }

    public function test_send_with_invalid_email_does_not_fire_send_email_event(): void
    {
        $quoteId = $this->createQuoteFixture();

        $fired = [];
        $this->spyOn('stride/quote/send_email', $fired);

        // sanitize_email('not-an-email') returns '' ⇒ $sendTo falsy ⇒ no event.
        $this->save($quoteId, [
            'stride_send_quote' => '1',
            'stride_send_to'    => 'not-an-email',
        ]);

        $this->assertCount(
            0,
            $fired,
            'a send with an INVALID stride_send_to (sanitize_email empties it) must NOT fire stride/quote/send_email',
        );
    }

    // ---------------------------------------------------------------------
    // CANCEL-WITH-REGISTRATION
    // ---------------------------------------------------------------------

    public function test_cancel_with_registration_flag_fires_cancelled_event(): void
    {
        $quoteId = $this->createQuoteFixture(); // starts 'draft'

        $fired = [];
        $this->spyOn('stride/quote/cancelled', $fired);

        // Real →cancelled transition AND the flag ⇒ event fires.
        $this->save($quoteId, [
            'stride_change_status'       => 'cancelled',
            'stride_cancel_registration' => '1',
        ]);

        $this->assertCount(
            1,
            $fired,
            '→cancelled WITH stride_cancel_registration must fire stride/quote/cancelled exactly once',
        );
        $this->assertSame(
            ['quote_id' => $quoteId],
            $fired[0]['a'],
            'stride/quote/cancelled must fire with the ["quote_id" => id] payload',
        );
    }

    public function test_cancel_without_registration_flag_does_not_fire_cancelled_event(): void
    {
        $quoteId = $this->createQuoteFixture(); // 'draft'

        $fired = [];
        $this->spyOn('stride/quote/cancelled', $fired);

        // Real →cancelled transition but NO flag ⇒ no event.
        $this->save($quoteId, [
            'stride_change_status' => 'cancelled',
        ]);

        $this->assertCount(
            0,
            $fired,
            '→cancelled WITHOUT stride_cancel_registration must NOT fire stride/quote/cancelled',
        );
    }

    public function test_registration_flag_without_cancel_status_does_not_fire_cancelled_event(): void
    {
        $quoteId = $this->createQuoteFixture(); // 'draft'

        $fired = [];
        $this->spyOn('stride/quote/cancelled', $fired);

        // Flag set, but status transitions to 'sent' (not cancelled) ⇒ no event.
        $this->save($quoteId, [
            'stride_change_status'       => 'sent',
            'stride_cancel_registration' => '1',
        ]);

        $this->assertCount(
            0,
            $fired,
            'stride_cancel_registration with a non-cancelled status change must NOT fire stride/quote/cancelled',
        );
    }

    // ---------------------------------------------------------------------
    // REGENERATE PDF
    // ---------------------------------------------------------------------

    public function test_regenerate_pdf_flag_fires_regenerate_pdf_event(): void
    {
        $quoteId = $this->createQuoteFixture();

        $fired = [];
        $this->spyOn('stride/quote/regenerate_pdf', $fired);

        $this->save($quoteId, [
            'stride_regenerate_pdf' => '1',
        ]);

        $this->assertCount(
            1,
            $fired,
            'stride_regenerate_pdf=1 must fire stride/quote/regenerate_pdf exactly once',
        );
        $this->assertSame(
            $quoteId,
            $fired[0]['a'],
            'stride/quote/regenerate_pdf must fire with the quote post id',
        );
    }
}
