<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\User\UserLifecycleService;
use Stride\Support\EnrollmentDataExtras;

/**
 * Read-model assembly for the per-edition cohort roster (Admin Workspace Phase 2a).
 *
 * Given an edition, assembles its loaded registration set into roster rows:
 * each registrant's session selections (read through the
 * RegistrationRepository convergence point — never the raw `selections` column,
 * INV-6b), batch-read attendance (AttendanceRepository::getLatestBySessionForUsers over the
 * loaded set, CM-3), and a per-row anonymise redaction (CM-3b — a GDPR-erased
 * registrant appears with PII tombstoned, not omitted, not in full).
 *
 * Mirrors the Phase-1 AdminUserService shape: a sanctioned read-model in the
 * INV-3 accepted zone. Net-new registration query *shapes* live in
 * RegistrationRepository, not here. The anonymise-redaction gate reads through
 * the shared UserLifecycleService::isAnonymised predicate (CR-6 convergence
 * point), never an inlined `_stride_anonymised_at` literal.
 *
 * SECURITY (CM-3 / M5): getRosterForEdition takes (int $editionId, array $filters)
 * and NOTHING that binds enrollment_data/selections into a SQL WHERE/GROUP BY.
 * $filters is applied over the already-loaded set, never interpolated into SQL.
 *
 * Extras extraction (the enrollment_data logistics fields) is Task 2a.2 — the
 * row shape carries an `extras` slot now so 2a.2 fills it without re-shaping.
 *
 * Registered in plugin-config.php.
 */
final class AdminEditionRosterService
{
    /** Tombstone shown in place of an anonymised registrant's name (CM-3b). */
    private const ANON_TOMBSTONE = '(verwijderd)';

    /**
     * Statuses that make a registrant part of the cohort roster (CR-1).
     *
     * PRODUCT DECISION (2026-06-23): the cohort roster is the people who are
     * actually enrolled — confirmed + completed. cancelled/waitlist/interest/
     * pending are NOT in the cohort and must never reach the roster (2a-C bulk
     * actions iterate these rows). Matches RegistrationStatus::hasAccess().
     */
    private const COHORT_STATUSES = [
        RegistrationStatus::Confirmed,
        RegistrationStatus::Completed,
    ];

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly AttendanceRepository $attendance,
    ) {}

    /**
     * Assemble the per-session-capable roster for one edition (loaded set only).
     *
     * @param  int $editionId
     * @param  array<string,mixed> $filters  Applied over the LOADED set only —
     *         never interpolated into SQL (CM-3 / M5). Currently a placeholder
     *         the UI/Task 2a.2 narrows client-side; no key reaches a query.
     * @return array{edition_id:int, rows:array<int,array<string,mixed>>, extras_keys:array<int,string>}
     */
    public function getRosterForEdition(int $editionId, array $filters = []): array
    {
        // Degrade gracefully on an unmigrated DB (a documented Stride state),
        // mirroring the sibling getEditionRegistrations guard (CR-B3) — never
        // hit the table with a raw query when it isn't there.
        if (!RegistrationTable::exists()) {
            return ['edition_id' => $editionId, 'rows' => [], 'extras_keys' => []];
        }

        // Cohort roster = confirmed + completed only (CR-1). Out-of-cohort rows
        // (cancelled/waitlist/interest/pending — incl. the CR-2 blank-name
        // user_id=0 rows) are excluded at the query, not iterated then filtered.
        $cohortStatuses = array_map(static fn(RegistrationStatus $s) => $s->value, self::COHORT_STATUSES);
        $registrations = $this->registrations->findByEditionWithStatuses($editionId, $cohortStatuses);

        $regIds = array_map(static fn($r) => (int) $r->id, $registrations);
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn($r) => (int) $r->user_id, $registrations),
        )));

        // Prime the user + user-meta caches ONCE before the row loop so the
        // per-row reads below — displayName()'s get_userdata() (S4 :userdata) and
        // the inline get_user_meta($userId, 'organisation') — are cache hits.
        // Without this, each is a query on first touch: O(2N) on a cohort of N.
        // Same precedent as searchUsers / exportRegistrations in AdminAPIController.
        if (!empty($userIds)) {
            cache_users($userIds);
            update_meta_cache('user', $userIds);
        }

        // Selections through the convergence point (batched — no raw decode here).
        $selectionsByReg = $this->registrations->getSelectionsForRegistrations($regIds);

        // Attendance batch-read over the loaded set (CM-3): a per-session
        // latest-wins map per user, with the aggregate counts DERIVED from
        // that same map (one definition — the client's optimistic patch
        // recomputes its aggregate the identical way, F-C2).
        [$attendanceByUser, $attendanceSessionsByUser] = $this->attendanceMaps($userIds, $editionId);

        $rows = [];
        $extrasKeys = [];
        foreach ($registrations as $reg) {
            $regId = (int) $reg->id;
            $userId = (int) $reg->user_id;
            $isAnonymised = UserLifecycleService::isAnonymised($userId);

            // Extras from enrollment_data JSON for the LOADED set only (CM-3/M5):
            // never bound into a WHERE/GROUP BY. Suppressed for erased users (CM-3b).
            $extras = $isAnonymised ? [] : $this->extractExtras($reg);
            foreach (array_keys($extras) as $key) {
                $extrasKeys[$key] = true;
            }

            $rows[] = [
                'registration_id' => $regId,
                'user_id'         => $userId,
                'name'            => $isAnonymised
                    ? self::ANON_TOMBSTONE
                    : $this->displayName($userId),
                'organisation'    => $isAnonymised
                    ? ''
                    : (string) (get_user_meta($userId, 'organisation', true) ?: ''),
                'status'          => (string) $reg->status,
                'is_anonymised'   => $isAnonymised,
                'selections'      => $selectionsByReg[$regId] ?? [],
                'attendance'      => $attendanceByUser[$userId] ?? $this->emptyAttendance(),
                // Per-session state (F-C2): {sessionId: 'present'|'absent'|
                // 'excused'} so the lens can show WHO is marked for the
                // selected session and light the active mark button. Keys are
                // strings (JSON object keys always are — the client matches
                // on String(sessionId)).
                'attendance_by_session' => $attendanceSessionsByUser[$userId] ?? [],
                'extras'          => $extras,
            ];
        }

        return [
            'edition_id'  => $editionId,
            'rows'        => $rows,
            // Distinct extras keys present across the LOADED set — discovered from
            // the data (not a fixed allowlist), for the UI to build filter chips
            // (2a.9). Client-side / loaded-set filtering only, never a SQL param.
            'extras_keys' => array_keys($extrasKeys),
        ];
    }

    /**
     * Extract the logistics "extras" ({key: value}) for one registration from
     * its enrollment_data JSON — loaded-set only (CM-3/M5).
     *
     * Delegates the stage walk + PII-skip decision to the shared
     * {@see EnrollmentDataExtras} contract (the ONE source the exporter also
     * consumes, so the two surfaces cannot drift on which key is PII — CR-3/CR-5),
     * then keeps only scalar answers: structured sub-objects are not chip-able
     * filter values for the roster. Keys are DISCOVERED from the row's data, never
     * a fixed allowlist. NO enrollment_data key is ever bound into SQL.
     *
     * @param  object $reg  A raw findByEdition() row (enrollment_data is a JSON string).
     * @return array<string, scalar>  Discovered scalar extras for this row.
     */
    private function extractExtras(object $reg): array
    {
        $raw = $reg->enrollment_data ?? null;
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($data)) {
            return [];
        }

        return array_filter(EnrollmentDataExtras::extract($data), 'is_scalar');
    }

    /**
     * Attendance per user for the loaded set: a per-session status map, plus
     * aggregate counts DERIVED from that map (one definition, F-C2 — the
     * client's optimistic recompute matches byte-for-byte).
     *
     * The input is ALREADY one non-empty record per (user, session) — the
     * deduped latest-wins read (AttendanceRepository::
     * getLatestBySessionForUsers), the SAME reader the Partner API consumes,
     * so the two surfaces can never disagree about duplicate or empty-status
     * artifact records. No skip-guards here: the dedup semantics live in ONE
     * place (the repository) — never re-add an isset()/empty-status guard in
     * this loop.
     *
     * @param  array<int> $userIds
     * @return array{
     *   0: array<int, array{present:int, absent:int, excused:int}>,
     *   1: array<int, array<string, string>>
     * } [aggregates by user, sessionId=>status map by user]
     */
    private function attendanceMaps(array $userIds, int $editionId): array
    {
        if (empty($userIds)) {
            return [[], []];
        }

        $records = $this->attendance->getLatestBySessionForUsers($userIds, $editionId);

        $sessionsByUser = [];
        foreach ($records as $record) {
            $uid = (int) $record->user_id;
            $sessionKey = (string) (int) ($record->session_id ?? 0);
            $sessionsByUser[$uid][$sessionKey] = (string) $record->status;
        }

        $byUser = [];
        foreach ($sessionsByUser as $uid => $map) {
            $agg = $this->emptyAttendance();
            foreach ($map as $status) {
                if (isset($agg[$status])) {
                    $agg[$status]++;
                }
            }
            $byUser[$uid] = $agg;
        }

        return [$byUser, $sessionsByUser];
    }

    /**
     * @return array{present:int, absent:int, excused:int}
     */
    private function emptyAttendance(): array
    {
        return ['present' => 0, 'absent' => 0, 'excused' => 0];
    }

    private function displayName(int $userId): string
    {
        $user = get_userdata($userId);
        if (!$user) {
            return '';
        }
        $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        return $name !== '' ? $name : (string) $user->display_name;
    }
}
