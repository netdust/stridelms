<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\Attendance;

use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Tests\TestCase;

/**
 * THE attendance-truth dedup (decision 2026-07-15): one record per
 * (user, session), latest-first input order wins. Shared by the admin roster
 * AND the Partner API — a duplicate historical record (pre-upsert history,
 * migrated v3 data) must never double an admin count or a partner's invoiced
 * hours, and the two surfaces must agree on the same records.
 */
final class AttendanceDedupeTest extends TestCase
{
    private function rec(int $user, int $session, string $status, int $id = 0): object
    {
        return (object) ['id' => $id, 'user_id' => $user, 'session_id' => $session, 'status' => $status];
    }

    public function test_keeps_the_first_seen_record_per_user_session_pair(): void
    {
        // Input arrives latest-first (marked_at DESC, id DESC) — the newer
        // 'absent' correction wins over the older 'present'.
        $out = AttendanceRepository::dedupeLatestBySession([
            $this->rec(7, 10, 'absent', 3),
            $this->rec(7, 10, 'present', 1),
            $this->rec(7, 11, 'present', 2),
        ]);

        $this->assertCount(2, $out);
        $this->assertSame('absent', $out[0]->status);
        $this->assertSame(11, $out[1]->session_id);
    }

    public function test_same_session_for_different_users_is_not_a_duplicate(): void
    {
        $out = AttendanceRepository::dedupeLatestBySession([
            $this->rec(7, 10, 'present'),
            $this->rec(8, 10, 'present'),
        ]);

        $this->assertCount(2, $out);
    }

    public function test_empty_input_stays_empty(): void
    {
        $this->assertSame([], AttendanceRepository::dedupeLatestBySession([]));
    }

    public function test_an_empty_status_artifact_neither_reports_nor_masks_an_older_real_mark(): void
    {
        // Legacy/migration rows can carry status '' (clears DELETE records
        // today). Such a row must not be emitted (the Partner API would
        // report "" with billable hours) and must not claim the (user,
        // session) slot — the older real mark wins, as the admin roster's
        // original guard always ruled.
        $out = AttendanceRepository::dedupeLatestBySession([
            $this->rec(7, 10, '', 5),         // latest — artifact
            $this->rec(7, 10, 'present', 2),  // older real mark → wins
            $this->rec(8, 11, '', 3),         // artifact with no older mark → gone
        ]);

        $this->assertCount(1, $out);
        $this->assertSame('present', $out[0]->status);
        $this->assertSame(7, $out[0]->user_id);
    }
}
