<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\Admin\EditionAdminController;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionRepository;
use Stride\Modules\Edition\SessionService;
use lucatume\WPBrowser\WordPress\WPDieException;

/**
 * SESSION create/update/delete via the Edition admin AJAX handlers.
 *
 * Session admin has NO metabox handleSave — sessions are managed inline on the
 * Edition page via three wp_ajax handlers on EditionAdminController:
 *   - ajaxAddSession()    (source 572) — nonce+cap, sanitizeSessionData, createSession
 *   - ajaxUpdateSession() (source 605) — nonce+cap, exists-check, sanitize, updateSession
 *   - ajaxDeleteSession() (source 641) — nonce+cap, hard wp_delete_post($id, true)
 *
 * The load-bearing contract is sanitizeSessionData() (source 1181-1229), which
 * maps the posted $_POST fields PER SessionType, and converts the euro
 * price_modifier to cents (comma OR dot decimal).
 *
 * These tests drive the REAL AJAX path (nonce + stride_manage cap + per-edition
 * edit_post + $_POST) against EXISTING product code, then assert on the PERSISTED
 * session (read back through SessionService::getSession) — not on the response
 * JSON. Every handler ends in wp_send_json_* which, in the wp-browser harness,
 * throws WPDieException; each invocation is therefore wrapped in a capture that
 * swallows that die (see driveAjax()) so the persisted state can be inspected.
 *
 * Product code UNCHANGED — this is a coverage/pinning test only.
 *
 * Ground-truthed from source:
 *   - NONCE_AJAX = 'stride_edition_admin'; check_ajax_referer reads $_REQUEST['nonce'].
 *   - verifyAjaxNonce() requires the stride_manage cap; each handler then adds a
 *     per-edition current_user_can('edit_post', $editionId). An administrator has
 *     both, so the fixture user is promoted to administrator.
 *   - SessionType cases: in_person, webinar, online, assignment.
 *   - price_modifier: (int) round(floatval(str_replace(',', '.', input)) * 100), '' => 0.
 *   - online/assignment: title derived from get_post(lesson_id)->post_title,
 *     lesson_ids=[lessonId], location 'Online'.
 *   - SessionRepository::validate requires edition_id + date, and end_time > start_time.
 *
 * CLEANUP: sessions are vad_session posts created by the handler (via
 * SessionRepository::create -> wp_insert_post); the base class only auto-tracks
 * posts created through its create* helpers, so every session id is pushed onto
 * self::$testPosts[] explicitly. No registrations are created.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec --raw -- bash -c \
 *   'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit \
 *    -c phpunit-integration.xml.dist --filter SessionAdminAjax'
 */
final class SessionAdminAjaxTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // verifyAjaxNonce() needs stride_manage AND each handler needs per-edition
        // edit_post; the base fixture user is a plain subscriber. Promote it to
        // administrator (same pattern as QuoteAdminHandleSaveStatusTest).
        wp_set_current_user((int) self::$testUserId);
        wp_get_current_user()->set_role('administrator');
    }

    protected function tearDown(): void
    {
        foreach (['nonce', 'edition_id', 'session_id', 'session_type', 'date',
                  'start_time', 'end_time', 'slot', 'price_modifier', 'capacity',
                  'title', 'location', 'description', 'webinar_link', 'lesson_id'] as $k) {
            unset($_POST[$k], $_REQUEST[$k]);
        }

        parent::tearDown();
    }

    private function controller(): EditionAdminController
    {
        return new EditionAdminController(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionRepository::class),
            ntdst_get(AttendanceRepository::class),
        );
    }

    private function sessionService(): SessionService
    {
        return ntdst_get(SessionService::class);
    }

    /**
     * Drive an AJAX handler through the real path with a valid nonce.
     *
     * Sets the current user, creates the nonce AFTER (nonces are user-context
     * dependent), places every posted key in BOTH $_POST and $_REQUEST (the
     * handlers read $_POST; check_ajax_referer reads $_REQUEST['nonce']), invokes
     * the handler, and captures the terminal wp_send_json_* die (which throws
     * WPDieException in the harness) plus its echoed JSON. Cleanup is done in
     * tearDown().
     *
     * @param array<string,mixed> $post
     * @return array{payload: array<string,mixed>|null, died: bool}
     */
    private function driveAjax(string $method, array $post, bool $validNonce = true): array
    {
        wp_set_current_user((int) self::$testUserId);

        $nonce = $validNonce ? wp_create_nonce('stride_edition_admin') : 'not-a-valid-nonce';
        $_POST['nonce'] = $_REQUEST['nonce'] = $nonce;

        foreach ($post as $key => $value) {
            $_POST[$key] = $_REQUEST[$key] = $value;
        }

        $controller = $this->controller();

        $forceAjax = static fn (): bool => true;
        $thrower = static function (): callable {
            return static function (): void {
                throw new WPDieException('');
            };
        };

        add_filter('wp_doing_ajax', $forceAjax);
        add_filter('wp_die_ajax_handler', $thrower);

        $died = false;
        ob_start();
        try {
            $controller->{$method}();
        } catch (WPDieException $e) {
            $died = true;
        } finally {
            $json = ob_get_clean();
            remove_filter('wp_doing_ajax', $forceAjax);
            remove_filter('wp_die_ajax_handler', $thrower);
        }

        $payload = $json === '' ? null : json_decode($json, true);

        return ['payload' => is_array($payload) ? $payload : null, 'died' => $died];
    }

    /**
     * Create an in_person session via the AJAX add handler, track it for cleanup,
     * and return its id. Fails the test if creation did not succeed.
     *
     * @param array<string,mixed> $overrides posted-field overrides
     */
    private function addSession(int $editionId, array $overrides = []): int
    {
        $post = array_merge([
            'edition_id'   => $editionId,
            'session_type' => 'in_person',
            'date'         => '2026-09-01',
            'start_time'   => '09:00',
            'end_time'     => '17:00',
            'title'        => 'Sessie 1',
            'location'     => 'Brussel',
            'description'  => 'Intro dag',
        ], $overrides);

        $result = $this->driveAjax('ajaxAddSession', $post);

        $this->assertTrue(
            $result['payload']['success'] ?? false,
            'addSession must return a success response (payload: ' . json_encode($result['payload']) . ')',
        );

        $sessionId = (int) ($result['payload']['data']['session_id'] ?? 0);
        $this->assertGreaterThan(0, $sessionId, 'addSession must return a new session id');
        self::$testPosts[] = $sessionId;

        return $sessionId;
    }

    // =====================================================================
    // 1) Per-type field mapping (sanitizeSessionData)
    // =====================================================================

    public function test_add_in_person_session_maps_title_location_description(): void
    {
        $editionId = $this->createTestEdition();

        $sessionId = $this->addSession($editionId, [
            'session_type' => 'in_person',
            'title'        => 'Fysieke sessie A',
            'location'     => 'Antwerpen',
            'description'  => 'Fysieke uitleg',
        ]);

        $session = $this->sessionService()->getSession($sessionId);
        $this->assertNotNull($session, 'the persisted in_person session must be readable');
        $this->assertSame('in_person', $session['type'], 'in_person session must persist type=in_person');
        $this->assertSame('Fysieke sessie A', $session['title'], 'in_person session must persist the posted title');
        $this->assertSame('Antwerpen', $session['location'], 'in_person session must persist the posted location');
        $this->assertSame('Fysieke uitleg', $session['description'], 'in_person session must persist the posted description');
    }

    public function test_session_description_keeps_safelisted_rich_text_and_strips_the_rest(): void
    {
        $editionId = $this->createTestEdition();

        $posted = '<h1>Dagprogramma</h1>'
            . '<p>Met <strong>Dr. Jansen</strong> en <em>Prof. De Vos</em></p>'
            . '<ul><li>09:00 Ontvangst</li></ul>'
            . '<script>alert(1)</script>'
            . '<img src="x" onerror="alert(1)">';

        $sessionId = $this->addSession($editionId, [
            'session_type' => 'in_person',
            'title'        => 'Sessie met programma',
            'location'     => 'Gent',
            'description'  => $posted,
        ]);

        $description = $this->sessionService()->getSession($sessionId)['description'];

        // Safelisted formatting survives so a speaker list / day programme renders.
        $this->assertStringContainsString('<h1>Dagprogramma</h1>', $description);
        $this->assertStringContainsString('<strong>Dr. Jansen</strong>', $description);
        $this->assertStringContainsString('<em>Prof. De Vos</em>', $description);
        $this->assertStringContainsString('<li>09:00 Ontvangst</li>', $description);

        // Everything outside the safelist is stripped (no stored-XSS vector).
        $this->assertStringNotContainsString('<script', $description);
        $this->assertStringNotContainsString('<img', $description);
        $this->assertStringNotContainsString('onerror', $description);
    }

    public function test_add_webinar_session_persists_link_and_forces_online_location(): void
    {
        $editionId = $this->createTestEdition();

        $sessionId = $this->addSession($editionId, [
            'session_type' => 'webinar',
            'title'        => 'Webinar A',
            'webinar_link' => 'https://zoom.example.test/meeting/123',
            'location'     => 'ignored-by-webinar', // webinar forces 'Online'
            'description'  => 'Live online',
        ]);
        self::$testPosts[] = $sessionId;

        $session = $this->sessionService()->getSession($sessionId);
        $this->assertNotNull($session, 'the persisted webinar session must be readable');
        $this->assertSame('webinar', $session['type'], 'webinar session must persist type=webinar');
        $this->assertSame(
            'https://zoom.example.test/meeting/123',
            $session['webinar_link'],
            'webinar session must persist the esc_url_raw webinar_link',
        );
        $this->assertSame(
            'Online',
            $session['location'],
            'webinar session must force location to "Online" regardless of the posted location',
        );
    }

    public function test_add_online_session_derives_title_from_lesson(): void
    {
        $editionId = $this->createTestEdition();

        // The online/assignment branch derives the title from get_post(lesson_id).
        // A course post is a valid post to derive a title from (createTestCourse
        // is the available fixture helper and tracks the post for cleanup).
        $lessonId = $this->createTestCourse(['post_title' => 'Module: Veiligheid basis']);

        $result = $this->driveAjax('ajaxAddSession', [
            'edition_id'   => $editionId,
            'session_type' => 'online',
            'date'         => '2026-09-02',
            'lesson_id'    => $lessonId,
        ]);

        $this->assertTrue($result['payload']['success'] ?? false, 'online add must succeed');
        $sessionId = (int) ($result['payload']['data']['session_id'] ?? 0);
        $this->assertGreaterThan(0, $sessionId, 'online add must return a session id');
        self::$testPosts[] = $sessionId;

        $session = $this->sessionService()->getSession($sessionId);
        $this->assertNotNull($session, 'the persisted online session must be readable');
        $this->assertSame('online', $session['type'], 'online session must persist type=online');
        $this->assertSame(
            'Module: Veiligheid basis',
            $session['title'],
            'online session title must be derived from the lesson post_title',
        );
        $this->assertSame(
            [$lessonId],
            $session['lesson_ids'],
            'online session must persist lesson_ids=[lessonId]',
        );
        $this->assertSame('Online', $session['location'], 'online session must persist location=Online');
    }

    // =====================================================================
    // 2) price_modifier euros -> cents (the load-bearing conversion)
    // =====================================================================

    /**
     * @dataProvider priceModifierProvider
     */
    public function test_price_modifier_euros_convert_to_cents(string $input, int $expectedCents): void
    {
        $editionId = $this->createTestEdition();

        $sessionId = $this->addSession($editionId, ['price_modifier' => $input]);

        $session = $this->sessionService()->getSession($sessionId);
        $this->assertNotNull($session, 'the persisted session must be readable');
        $this->assertSame(
            $expectedCents,
            $session['price_modifier'],
            sprintf('price_modifier "%s" must persist as %d cents', $input, $expectedCents),
        );
    }

    /** @return array<string, array{0:string,1:int}> */
    public static function priceModifierProvider(): array
    {
        return [
            'comma decimal 45,00' => ['45,00', 4500],
            'dot decimal 10.50'   => ['10.50', 1050],
            'empty string => 0'   => ['', 0],
            // Negative modifiers (discounts) persist with their sign. The
            // SessionCPT 'price_modifier' field is type 'signed_int', which the
            // ntdst-core Data API sanitizes with intval() (Data.php — preserves
            // the sign), NOT absint(). Honours the field's schema description
            // ("negative = discount", SessionCPT.php:98). This case is the
            // regression guard for that fix: -5 EUR => -500 cents.
            'negative -5 (discount) => -500' => ['-5', -500],
        ];
    }

    // =====================================================================
    // 2b) capacity (seat count) — shared field, absint, 0 = unlimited
    // =====================================================================

    public function test_add_session_persists_posted_capacity(): void
    {
        $editionId = $this->createTestEdition();

        $sessionId = $this->addSession($editionId, ['capacity' => 15]);

        $session = $this->sessionService()->getSession($sessionId);
        $this->assertNotNull($session, 'the persisted session must be readable');
        $this->assertSame(
            15,
            $session['capacity'],
            'a posted capacity=15 must persist as the session seat capacity',
        );
    }

    public function test_add_session_without_capacity_defaults_to_unlimited(): void
    {
        $editionId = $this->createTestEdition();

        // addSession posts no 'capacity' key.
        $sessionId = $this->addSession($editionId);

        $session = $this->sessionService()->getSession($sessionId);
        $this->assertNotNull($session, 'the persisted session must be readable');
        $this->assertSame(
            0,
            $session['capacity'],
            'a session added with no capacity posted must default to 0 (unlimited)',
        );
    }

    public function test_update_session_persists_changed_capacity(): void
    {
        $editionId = $this->createTestEdition();
        $sessionId = $this->addSession($editionId, ['capacity' => 10]);

        $result = $this->driveAjax('ajaxUpdateSession', [
            'session_id'   => $sessionId,
            'edition_id'   => $editionId,
            'session_type' => 'in_person',
            'date'         => '2026-09-01',
            'start_time'   => '09:00',
            'end_time'     => '17:00',
            'title'        => 'Sessie 1',
            'location'     => 'Brussel',
            'description'  => 'Intro dag',
            'capacity'     => 25,
        ]);

        $this->assertTrue($result['payload']['success'] ?? false, 'update must return a success response');

        $session = $this->sessionService()->getSession($sessionId);
        $this->assertNotNull($session, 'the updated session must be readable');
        $this->assertSame(25, $session['capacity'], 'update must persist the new capacity');
    }

    // =====================================================================
    // 3) Update persists a changed field
    // =====================================================================

    public function test_update_session_persists_changed_title_and_description(): void
    {
        $editionId = $this->createTestEdition();
        $sessionId = $this->addSession($editionId, [
            'title'       => 'Oude titel',
            'description' => 'oude beschrijving',
        ]);

        $result = $this->driveAjax('ajaxUpdateSession', [
            'session_id'   => $sessionId,
            'edition_id'   => $editionId,
            'session_type' => 'in_person',
            'date'         => '2026-09-01',
            'start_time'   => '09:00',
            'end_time'     => '17:00',
            'title'        => 'Nieuwe titel',
            'location'     => 'Antwerpen',
            'description'  => 'nieuwe beschrijving',
        ]);

        $this->assertTrue($result['payload']['success'] ?? false, 'update must return a success response');

        $session = $this->sessionService()->getSession($sessionId);
        $this->assertNotNull($session, 'the updated session must be readable');
        $this->assertSame('Nieuwe titel', $session['title'], 'update must persist the new title');
        $this->assertSame('nieuwe beschrijving', $session['description'], 'update must persist the new description');
    }

    public function test_update_session_fires_note_updated_only_when_description_changes(): void
    {
        $editionId = $this->createTestEdition();
        $sessionId = $this->addSession($editionId, [
            'title'       => 'Titel',
            'description' => 'oorspronkelijke beschrijving',
        ]);

        $fired = 0;
        $spy = static function () use (&$fired): void {
            $fired++;
        };
        add_action('stride/session/note_updated', $spy);

        // Same description, changed title only -> hook must NOT fire.
        $this->driveAjax('ajaxUpdateSession', [
            'session_id'   => $sessionId,
            'edition_id'   => $editionId,
            'session_type' => 'in_person',
            'date'         => '2026-09-01',
            'start_time'   => '09:00',
            'end_time'     => '17:00',
            'title'        => 'Andere titel',
            'location'     => 'Antwerpen',
            'description'  => 'oorspronkelijke beschrijving',
        ]);
        $this->assertSame(0, $fired, 'note_updated must NOT fire when the description is unchanged');

        // Changed description -> hook must fire exactly once.
        $this->driveAjax('ajaxUpdateSession', [
            'session_id'   => $sessionId,
            'edition_id'   => $editionId,
            'session_type' => 'in_person',
            'date'         => '2026-09-01',
            'start_time'   => '09:00',
            'end_time'     => '17:00',
            'title'        => 'Andere titel',
            'location'     => 'Antwerpen',
            'description'  => 'gewijzigde beschrijving',
        ]);
        $this->assertSame(1, $fired, 'note_updated must fire exactly once when the description changes');

        remove_action('stride/session/note_updated', $spy);
    }

    // =====================================================================
    // 4) Delete hard-removes the session post
    // =====================================================================

    public function test_delete_session_hard_removes_the_post(): void
    {
        $editionId = $this->createTestEdition();
        $sessionId = $this->addSession($editionId);

        $this->assertNotNull(
            $this->sessionService()->getSession($sessionId),
            'precondition: the session must exist before deletion',
        );

        $result = $this->driveAjax('ajaxDeleteSession', [
            'session_id' => $sessionId,
            'edition_id' => $editionId,
        ]);

        $this->assertTrue($result['payload']['success'] ?? false, 'delete must return a success response');

        // Hard delete (wp_delete_post($id, true)) — the post is gone entirely.
        $this->assertNull(get_post($sessionId), 'the session post must be hard-deleted (get_post returns null)');
        $this->assertNull(
            $this->sessionService()->getSession($sessionId),
            'a deleted session must no longer be readable via getSession',
        );
    }

    // =====================================================================
    // 5) Guard denial: a bad nonce creates no session
    // =====================================================================

    public function test_bad_nonce_creates_no_session(): void
    {
        $editionId = $this->createTestEdition();

        $before = count($this->sessionService()->getSessionsForEdition($editionId));
        $this->assertSame(0, $before, 'precondition: the edition starts with no sessions');

        $result = $this->driveAjax('ajaxAddSession', [
            'edition_id'   => $editionId,
            'session_type' => 'in_person',
            'date'         => '2026-09-01',
            'start_time'   => '09:00',
            'end_time'     => '17:00',
            'title'        => 'Should not persist',
            'location'     => 'Nergens',
            'description'  => 'Should not persist',
        ], validNonce: false);

        $this->assertFalse(
            $result['payload']['success'] ?? true,
            'an invalid nonce must produce an error response, not a success',
        );

        $after = $this->sessionService()->getSessionsForEdition($editionId);
        $this->assertCount(
            0,
            $after,
            'an invalid nonce must create NO session for the edition',
        );
    }
}
