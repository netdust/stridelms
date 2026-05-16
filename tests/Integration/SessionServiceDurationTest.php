<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Edition\SessionService;

/**
 * Regression for B2-001: getTotalDurationForSessions queried meta_key
 * 'start_time'/'end_time' but Stride session meta is `_ntdst_` prefixed.
 * Result: every certificate / report showed 0 hours attended.
 */
final class SessionServiceDurationTest extends IntegrationTestCase
{
    private SessionService $service;
    private array $sessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(SessionService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->sessionIds as $id) {
            wp_delete_post($id, true);
        }
        $this->sessionIds = [];
        parent::tearDown();
    }

    private function createSession(string $start, string $end): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => 'Test session',
            'post_status' => 'publish',
        ]);
        // Stride uses the `_ntdst_` meta prefix — matches what
        // SessionRepository::getField('start_time') reads.
        update_post_meta($id, '_ntdst_start_time', $start);
        update_post_meta($id, '_ntdst_end_time', $end);
        $this->sessionIds[] = $id;

        return $id;
    }

    public function testTotalDurationSumsSingleSession(): void
    {
        $id = $this->createSession('09:00', '17:00');

        self::assertSame(8.0, $this->service->getTotalDurationForSessions([$id]));
    }

    public function testTotalDurationSumsMultipleSessions(): void
    {
        $a = $this->createSession('09:00', '12:00'); // 3h
        $b = $this->createSession('13:00', '17:00'); // 4h
        $c = $this->createSession('19:00', '21:30'); // 2.5h

        self::assertSame(9.5, $this->service->getTotalDurationForSessions([$a, $b, $c]));
    }

    public function testEmptyInputReturnsZero(): void
    {
        self::assertSame(0.0, $this->service->getTotalDurationForSessions([]));
    }

    public function testSessionWithoutMetaContributesZero(): void
    {
        $withMeta = $this->createSession('09:00', '11:00'); // 2h
        $blank = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => 'Blank',
            'post_status' => 'publish',
        ]);
        $this->sessionIds[] = $blank;

        self::assertSame(2.0, $this->service->getTotalDurationForSessions([$withMeta, $blank]));
    }
}
