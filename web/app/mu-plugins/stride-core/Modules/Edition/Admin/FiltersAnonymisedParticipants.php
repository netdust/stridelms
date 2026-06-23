<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Modules\User\UserLifecycleService;

/**
 * The ONE place every Edition exporter drops GDPR-erased participants.
 *
 * B1 (security review 2026-06-23): the `_stride_anonymised_at` skip used to
 * live as a single inline literal on EditionFilesZipExporter (its old :76
 * check). The other four exporters (Registration, Attendance, Namecard,
 * Bundle-via-its-components) had NO skip. Task 2a.10 newly surfaces all five as
 * one-click roster downloads behind `canManageAdmin`, so an admin would egress
 * the PII of erased users through the four skip-less exporters.
 *
 * Lifting the skip here makes it universal from one point and keys it on the
 * `UserLifecycleService::isAnonymised()` convergence predicate (cluster 2a-A,
 * CR-6) rather than a sixth literal copy of `_stride_anonymised_at`. Every
 * exporter calls dropAnonymisedRows() on its participant rows immediately after
 * loading them, so the erased user never reaches the rendered output.
 *
 * Scope: this is the anonymise-SKIP only (a PII-erasure guard). Field-scoping
 * (per-recipient column allowlists) remains Phase 3.
 */
trait FiltersAnonymisedParticipants
{
    /**
     * Drop every row whose participant has been GDPR-anonymised.
     *
     * @param array<int, array<string, mixed>> $rows registration rows
     * @param string                            $userIdKey the row key holding the user id
     * @return array<int, array<string, mixed>> rows with anonymised participants removed (keys reindexed)
     */
    protected function dropAnonymisedRows(array $rows, string $userIdKey = 'user_id'): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($userIdKey): bool {
                $userId = (int) ($row[$userIdKey] ?? 0);

                // Anonymous-stage rows (interest/waitlist with user_id 0) carry
                // no account PII to erase — keep them; they are filtered/handled
                // by the exporter's own stage logic.
                if ($userId <= 0) {
                    return true;
                }

                return !UserLifecycleService::isAnonymised($userId);
            },
        ));
    }
}
