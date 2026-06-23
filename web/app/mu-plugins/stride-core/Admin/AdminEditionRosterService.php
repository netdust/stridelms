<?php

declare(strict_types=1);

namespace Stride\Admin;

use Stride\Admin\Support\AdminBatchHelpers;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Read-model assembly for the per-edition cohort roster (Admin Workspace Phase 2a).
 *
 * Given an edition, assembles its loaded registration set into roster rows:
 * each registrant's session selections (read through the
 * RegistrationRepository convergence point — never the raw `selections` column,
 * INV-6b), batch-read attendance (AttendanceRepository::getByUsers over the
 * loaded set, CM-3), and a per-row anonymise redaction (CM-3b — a GDPR-erased
 * registrant appears with PII tombstoned, not omitted, not in full).
 *
 * Mirrors the Phase-1 AdminUserService shape: a sanctioned read-model in the
 * INV-3 accepted zone, `use AdminBatchHelpers;` for shared batch reads. Net-new
 * registration query *shapes* live in RegistrationRepository, not here.
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
    use AdminBatchHelpers;

    /** Tombstone shown in place of an anonymised registrant's name (CM-3b). */
    private const ANON_TOMBSTONE = '(verwijderd)';

    /**
     * enrollment_data stages walked for extras/logistics fields.
     *
     * Mirrors EditionRegistrationExporter::summarizeEnrollmentData (the precedent
     * reader, D3) so the roster surface and the export surface agree on what an
     * "extra" is. Pre-account stages (interest/waitlist) and the append-only
     * initial_selection log are intentionally excluded.
     */
    private const EXTRAS_STAGES = ['enrollment_personal', 'enrollment_billing', 'intake', 'evaluation'];

    /**
     * Known-PII / already-columned keys NOT surfaced as extras.
     *
     * Aligned with the exporter's skip list (D3) so the two surfaces agree: a
     * key here is shown in its own column elsewhere or is personal/billing PII,
     * never a logistics "extra" (book/lunch/dietary).
     */
    private const EXTRAS_SKIP_KEYS = [
        'name', 'email', 'phone', 'first_name', 'last_name',
        'company', 'billing_company', 'billing_vat', 'billing_address_1',
        'billing_postcode', 'billing_city', 'invoice_email', 'gln_number',
        'organisation', 'department',
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
     * @return array{edition_id:int, rows:array<int,array<string,mixed>>}
     */
    public function getRosterForEdition(int $editionId, array $filters = []): array
    {
        $registrations = $this->registrations->findByEdition($editionId);

        $regIds = array_map(static fn($r) => (int) $r->id, $registrations);
        $userIds = array_values(array_unique(array_filter(
            array_map(static fn($r) => (int) $r->user_id, $registrations),
        )));

        // Selections through the convergence point (batched — no raw decode here).
        $selectionsByReg = $this->registrations->getSelectionsForRegistrations($regIds);

        // Attendance batch-read over the loaded set (CM-3).
        $attendanceByUser = $this->aggregateAttendance($userIds, $editionId);

        $rows = [];
        $extrasKeys = [];
        foreach ($registrations as $reg) {
            $regId = (int) $reg->id;
            $userId = (int) $reg->user_id;
            $isAnonymised = $this->isAnonymised($userId);

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
     * Mirrors EditionRegistrationExporter::summarizeEnrollmentData (D3): walks the
     * configured stages, reads each `$stageEnvelope['data']`, skips the known-PII
     * keys. Keys are DISCOVERED from the row's data, never a fixed allowlist — an
     * edition whose registrants submitted a `dieet` key surfaces it; one that
     * didn't does not. NO enrollment_data key is ever bound into SQL.
     *
     * @param  object $reg  A raw findByEdition() row (enrollment_data is a JSON string).
     * @return array<string, scalar>  Discovered extras for this row.
     */
    private function extractExtras(object $reg): array
    {
        $raw = $reg->enrollment_data ?? null;
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($data)) {
            return [];
        }

        $extras = [];
        foreach (self::EXTRAS_STAGES as $stage) {
            $envelope = $data[$stage] ?? null;
            if (!is_array($envelope)) {
                continue;
            }
            $stageData = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
            foreach ($stageData as $key => $value) {
                if (in_array($key, self::EXTRAS_SKIP_KEYS, true)) {
                    continue;
                }
                // Only scalar logistics answers are extras; structured sub-objects
                // are not chip-able filter values.
                if (is_scalar($value)) {
                    $extras[(string) $key] = $value;
                }
            }
        }

        return $extras;
    }

    /**
     * Aggregate attendance per user for the loaded set in one batched read.
     *
     * @param  array<int> $userIds
     * @return array<int, array{present:int, absent:int, excused:int}>
     */
    private function aggregateAttendance(array $userIds, int $editionId): array
    {
        if (empty($userIds)) {
            return [];
        }

        $records = $this->attendance->getByUsers($userIds, $editionId);

        $byUser = [];
        foreach ($records as $record) {
            $uid = (int) $record->user_id;
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = $this->emptyAttendance();
            }
            $status = (string) $record->status;
            if (isset($byUser[$uid][$status])) {
                $byUser[$uid][$status]++;
            }
        }

        return $byUser;
    }

    /**
     * @return array{present:int, absent:int, excused:int}
     */
    private function emptyAttendance(): array
    {
        return ['present' => 0, 'absent' => 0, 'excused' => 0];
    }

    private function isAnonymised(int $userId): bool
    {
        return (int) get_user_meta($userId, '_stride_anonymised_at', true) > 0;
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
