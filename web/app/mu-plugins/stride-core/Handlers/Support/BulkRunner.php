<?php

declare(strict_types=1);

namespace Stride\Handlers\Support;

use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Shared bulk-mutation engine for the Admin Workspace bulk handlers.
 *
 * Extracted from BulkRegistrationHandler (Phase 1C) so the per-row-report loop,
 * the M2 capability gate, the B3 batch cap, the select-all expansion (CR-1) and
 * the M10 completion event are a SINGLE source both the global-grid bulk handler
 * (BulkRegistrationHandler) and the cohort-lens roster bulk handler
 * (RosterBulkHandler, Phase 2a) share — instead of two drifting copies
 * (lesson_pure_passthrough_is_drift). The behavior is identical to the Phase-1C
 * private methods this replaces; the existing BulkRegistrationHandler suite is
 * the characterization safety net for that move.
 *
 * What this trait owns (the engine):
 *   M2  — denyIfNotManager(): stride_manage checked FIRST, before any loop.
 *   B3  — MAX_BATCH hard cap, rejected BEFORE the loop.
 *   CR-1— resolveBulkIds(): select-all filter expansion at the single chokepoint.
 *   M3/M9 — runBulk(): per-row resolve + report {total, succeeded, failed, summary};
 *          a row that doesn't resolve → failed[] not_found; a per-row WP_Error →
 *          failed[]; a raw Throwable → failed[] exception (logged, never leaked),
 *          loop continues (non-atomic).
 *   M10 — finishBatch(): the coarse bulk_completed recount event at the tail.
 *
 * What the trait does NOT own (the per-action / per-scope policy stays in the
 * concrete handler): the action registration, the per-row domain closure, and
 * any scope guard (e.g. RosterBulkHandler's CM-1 edition-scope check, threaded
 * INTO the per-row closure passed to runBulk).
 */
trait BulkRunner
{
    /**
     * B3 — hard backstop on bulk batch size. An authorized request with thousands
     * of ids would otherwise run an unbounded synchronous loop. 500 is generous vs
     * realistic selections.
     */
    private const MAX_BATCH = 500;

    /**
     * The select-all expansion fetches ONE id past the cap (CR-2): fetching
     * MAX_BATCH + 1 is what lets runBulk's `count($ids) > MAX_BATCH` guard
     * distinguish "exactly MAX_BATCH, OK" from "over cap, reject as too_many" —
     * fetching exactly MAX_BATCH would silently truncate an over-cap filter to a
     * 500-row partial mutation. Named so a future caller cannot desync the +1 from
     * the guard.
     */
    public const EXPANSION_FETCH_LIMIT = self::MAX_BATCH + 1;

    /**
     * M2 capability gate — call as the FIRST line of every handler.
     *
     * @return WP_Error|null WP_Error(403) when denied, null when allowed.
     */
    private function denyIfNotManager(): ?WP_Error
    {
        if (!current_user_can('stride_manage')) {
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'), ['status' => 403]);
        }

        return null;
    }

    /**
     * Select-all-across-pages: expand a carried grid filter to the full matching
     * id-set, server-side, BEFORE the per-row loop. When $params['select_all'] is
     * set, the payload carries a structured grid `filter` (NOT a 4k-row id list)
     * which is expanded via RegistrationRepository::idsForGridFilter — the SAME
     * buildGridFilters WHERE the grid read uses. The expansion fetches
     * MAX_BATCH + 1 ids so an over-cap result is rejected by runBulk's cap guard as
     * too_many — never truncated. A plain {ids:[…]} payload is returned unchanged.
     *
     * @param  array<string,mixed> $params registry params (full POST body).
     * @return array<string,mixed>         $params with ['ids'] populated under select_all.
     */
    private function resolveBulkIds(array $params): array
    {
        if (empty($params['select_all'])) {
            return $params;
        }

        $filter = is_array($params['filter'] ?? null) ? $params['filter'] : [];
        $repo   = ntdst_get(RegistrationRepository::class);

        $params['ids'] = $repo->idsForGridFilter($filter, self::EXPANSION_FETCH_LIMIT);

        return $params;
    }

    /**
     * Shared bulk loop + per-row report (M3 + M9).
     *
     * Resolves each id via the repository (INV-3); a row that doesn't resolve
     * lands in failed[] with not_found and is never mutated. Per-row WP_Error
     * results are captured into failed[], never swallowed (INV-4). A raw Throwable
     * from the per-row closure is caught, logged (detail never leaked), reported
     * failed, and the loop continues (M9 non-atomic).
     *
     * @param array<string,mixed>                                       $params  registry params; $params['ids'] = row ids.
     * @param callable(int $id, object $registration): (true|WP_Error)  $perRow
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    private function runBulk(array $params, callable $perRow): array|WP_Error
    {
        // CR-1: select-all expansion lives at the SINGLE chokepoint every public
        // handler routes through, so a future handler cannot forget it and
        // silently no-op a select_all request. Idempotent for a plain {ids:[…]}.
        $params = $this->resolveBulkIds($params);

        $ids = array_values(array_unique(array_map('absint', (array) ($params['ids'] ?? []))));

        // B3: hard cap BEFORE the loop.
        if (count($ids) > self::MAX_BATCH) {
            return new WP_Error(
                'too_many',
                __('Te veel inschrijvingen geselecteerd (max 500).', 'stride'),
                ['status' => 400],
            );
        }

        $repo = ntdst_get(RegistrationRepository::class);

        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            $registration = $repo->find($id);
            if ($registration === null || is_wp_error($registration)) {
                $failed[] = ['id' => $id, 'code' => 'not_found', 'message' => __('Inschrijving niet gevonden.', 'stride')];
                continue;
            }

            // M9 non-atomic: a per-row domain method may throw a raw Throwable
            // rather than return a WP_Error. Such a throw must NOT abort the batch —
            // the throwing row is reported failed and the loop continues. The raw
            // exception detail is logged but NEVER leaked to the client report.
            try {
                $result = $perRow($id, $registration);
            } catch (\Throwable $e) {
                ntdst_log('enrollment')->error('Bulk row threw an exception; row reported failed', [
                    'registration_id' => $id,
                    'exception' => $e->getMessage(),
                ]);
                $failed[] = [
                    'id' => $id,
                    'code' => 'exception',
                    'message' => __('Er ging iets mis bij deze inschrijving.', 'stride'),
                ];
                continue;
            }

            if (is_wp_error($result)) {
                $failed[] = [
                    'id' => $id,
                    'code' => $result->get_error_code(),
                    'message' => $result->get_error_message(),
                ];
                continue;
            }
            $succeeded[] = ['id' => $id];
        }

        return [
            'total' => count($ids),
            'succeeded' => $succeeded,
            'failed' => $failed,
            'summary' => ['ok' => count($succeeded), 'error' => count($failed)],
        ];
    }

    /**
     * M10 — fire the coarse "a batch finished, recount" event at the tail of every
     * public bulk handler, then return the report unchanged. A WP_Error (e.g. the
     * batch cap or a pre-loop denial) is propagated untouched and fires NO event —
     * the batch never ran.
     *
     * @param array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error $report
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    private function finishBatch(array|WP_Error $report): array|WP_Error
    {
        if (is_wp_error($report)) {
            return $report;
        }

        do_action('stride/registration/bulk_completed', ['summary' => $report['summary'] ?? []]);

        return $report;
    }

    /**
     * The ONE approve core every bulk-approve surface runs per row (grid,
     * dossier single-id, edition roster, trajectory roster): transition-map
     * gate → completeTask('approval') with the task_not_required exemption →
     * domain confirm. Previously copy-pasted three times with "kept in
     * lockstep" comments — this is the lockstep.
     *
     * task_not_required = the row has NO approval task (legacy/admin-created
     * pendings, approval not enabled on the offering). There is nothing to
     * complete, but the row is still legitimately approvable — the domain
     * confirmRegistration() re-guards the status transition itself. Treating
     * it as failure made such rows permanently un-approvable with an
     * untranslated internal error in the failure report.
     *
     * @return true|WP_Error
     */
    private function approveRow(int $id, object $reg): true|WP_Error
    {
        $from = \Stride\Domain\RegistrationStatus::tryFrom((string) $reg->status);
        if ($from === null || !\Stride\Modules\Enrollment\RegistrationTransitions::isAllowed($from, \Stride\Domain\RegistrationStatus::Confirmed)) {
            return new WP_Error('invalid_status', __('Deze inschrijving kan niet goedgekeurd worden.', 'stride'));
        }

        $completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);
        $enrollment = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);

        $task = $completion->completeTask($id, 'approval');
        if (is_wp_error($task) && $task->get_error_code() !== 'task_not_required') {
            return $task;
        }

        // The domain confirm re-guards (invalid_status for non-pending), so an
        // already-confirmed row never double-grants LD access.
        return $enrollment->confirmRegistration($id);
    }
}
