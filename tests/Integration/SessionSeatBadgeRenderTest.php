<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Seats-4 (frontend display): the session pickers show seat availability and
 * disable full sessions. This exercises the theme-side compute + render:
 *
 *   - stridence_session_seat_state()  — mirrors the server gate for the UI
 *   - stridence_session_seat_badge()  — Dutch badge ("Vol" / "N plaatsen over")
 *   - partials/session-row.php         — renders the badge (edition detail page)
 *
 * The AUTHORITATIVE seat gate lives in SessionService (Seats-2/3, tested by
 * SessionSeatCapacityTest / SessionSeatGateTest). This test only proves the
 * display layer surfaces that state — it is the one cheap render assertion the
 * plan called for (Tier B UI otherwise).
 *
 * Run: ddev exec --raw -- bash -c 'cd /var/www/html; STRIDE_TEST_DB_DISPOSABLE=1 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter SessionSeatBadgeRender'
 */
final class SessionSeatBadgeRenderTest extends IntegrationTestCase
{
    private SessionService $service;
    private RegistrationRepository $registrations;

    /** @var array<int> */
    private array $testRegIds = [];

    /** @var array<int> */
    private array $testUserIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service       = ntdst_get(SessionService::class);
        $this->registrations = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->testRegIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->testRegIds = [];

        foreach (self::$testPosts as $id) {
            wp_delete_post($id, true);
        }
        self::$testPosts = [];

        if ($this->testUserIds !== []) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            foreach ($this->testUserIds as $uid) {
                wp_delete_user($uid);
            }
            $this->testUserIds = [];
        }

        parent::tearDown();
    }

    private function createEdition(): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_edition',
            'post_title'  => 'Seat-badge test edition',
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = (int) $id;

        return (int) $id;
    }

    private function createSession(int $editionId, int $capacity): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => 'Seat-badge test session',
            'post_status' => 'publish',
        ]);
        update_post_meta($id, '_ntdst_edition_id', $editionId);
        update_post_meta($id, '_ntdst_capacity', $capacity);
        self::$testPosts[] = (int) $id;

        return (int) $id;
    }

    private function fillSession(int $editionId, int $sessionId, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $username = 'seatbadge_' . wp_generate_password(8, false);
            $userId   = wp_create_user($username, 'testpass123', $username . '@test.local');
            self::assertIsInt($userId);
            $this->testUserIds[] = $userId;

            $regId = $this->registrations->create([
                'user_id'    => $userId,
                'edition_id' => $editionId,
                'status'     => 'confirmed',
            ]);
            self::assertIsInt($regId);
            $this->testRegIds[] = $regId;
            $this->registrations->setSelections($regId, [$sessionId]);
        }
    }

    /**
     * Render partials/session-row.php with the given seat_state and return HTML.
     */
    private function renderRow(int $sessionId, array $seatState): string
    {
        ob_start();
        stridence_template_part('partials/session-row', null, [
            'session'    => (object) [
                'id'         => $sessionId,
                'title'      => 'Sessie X',
                'date'       => '2026-09-01',
                'start_time' => '09:00',
                'end_time'   => '12:00',
                'location'   => 'Zaal 1',
                'type'       => 'in_person',
            ],
            'attendance' => null,
            'selected'   => false,
            'not_chosen' => false,
            'seat_state' => $seatState,
        ]);

        return (string) ob_get_clean();
    }

    public function testFullSessionRendersVolBadge(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);
        $this->fillSession($editionId, $sessionId, 2); // 2/2 → full

        $state = stridence_session_seat_state($sessionId, 2, []);

        self::assertTrue($state['isFull'], 'a 2/2 session must be full');
        self::assertTrue($state['disabled'], 'a full session the user does not hold is disabled');
        self::assertSame(0, $state['remaining']);

        $html = $this->renderRow($sessionId, $state);
        self::assertStringContainsString('Vol', $html, 'full session must render the Vol badge');
    }

    public function testOwnHeldSeatIsNotDisabledEvenWhenFull(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 2);
        $this->fillSession($editionId, $sessionId, 2); // 2/2 → full

        // Current user already holds this session → exempt from disable.
        $state = stridence_session_seat_state($sessionId, 2, [$sessionId]);

        self::assertTrue($state['isFull'], 'still counts as full');
        self::assertTrue($state['ownSeat'], 'current user holds this seat');
        self::assertFalse($state['disabled'], 'a held seat is never disabled even when full');
    }

    public function testAvailableSessionRendersRemainingBadge(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 3);
        $this->fillSession($editionId, $sessionId, 1); // 1/3 → 2 remaining

        $state = stridence_session_seat_state($sessionId, 3, []);

        self::assertFalse($state['isFull']);
        self::assertSame(2, $state['remaining']);

        $html = $this->renderRow($sessionId, $state);
        self::assertStringContainsString('2 plaatsen over', $html, 'shows remaining seat count');
        self::assertStringNotContainsString('Vol', $html);
    }

    public function testUnlimitedCapacityRendersNoBadge(): void
    {
        $editionId = $this->createEdition();
        $sessionId = $this->createSession($editionId, 0); // 0 = unlimited
        $this->fillSession($editionId, $sessionId, 3);

        $state = stridence_session_seat_state($sessionId, 0, []);

        self::assertTrue($state['unlimited']);
        self::assertFalse($state['disabled']);
        self::assertSame('', stridence_session_seat_badge($state), 'unlimited → no badge string');
    }
}
