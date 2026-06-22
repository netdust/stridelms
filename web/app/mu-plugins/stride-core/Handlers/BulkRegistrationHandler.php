<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Bulk registration mutation handlers (Admin Workspace, Phase 1C).
 *
 * Each action registers on the ntdst/api_data registry; the framework has
 * already verified nonce + Origin/Referer CSRF + rate-limit (INV-2). Each
 * handler:
 *   M2 — checks current_user_can('stride_manage') FIRST, before the loop.
 *   M3 — per-row existence + valid-transition check; failures into failed[].
 *   M9 — returns the {total, succeeded, failed, summary} report; no auto-retry.
 * Every per-row call goes through the SAME single-item domain path the case
 * view uses (lesson_pure_passthrough_is_drift) — no second code path.
 */
final class BulkRegistrationHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_bulk_approve', [$this, 'handleBulkApprove'], 10, 2);
        add_filter('ntdst/api_data/stride_bulk_cancel', [$this, 'handleBulkCancel'], 10, 2);
        // Task 2.2 adds: quote_sent, quote_exported, promote_waitlist, message,
        // approve_post_course, generate_doc. Task 2.3 adds: set_field.
    }

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
     * Shared bulk loop + per-row report (M3 + M9).
     *
     * Resolves each id via the repository (INV-3); a row that doesn't resolve
     * lands in failed[] with not_found and is never mutated. Per-row WP_Error
     * results are captured into failed[], never swallowed (INV-4).
     *
     * @param array<string,mixed>                                $params  registry params; $params['ids'] = row ids.
     * @param callable(int $id, object $registration): (true|WP_Error) $perRow
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}
     */
    private function runBulk(array $params, callable $perRow): array
    {
        $ids = array_values(array_unique(array_map('absint', (array) ($params['ids'] ?? []))));
        $repo = ntdst_get(RegistrationRepository::class);

        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            $registration = $repo->find($id);
            if ($registration === null || is_wp_error($registration)) {
                $failed[] = ['id' => $id, 'code' => 'not_found', 'message' => __('Inschrijving niet gevonden.', 'stride')];
                continue;
            }

            $result = $perRow($id, $registration);
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
     * stride_bulk_approve — pending/interest → confirmed.
     *
     * Wraps the DOMAIN SEQUENCE (D1): completeTask('approval') then
     * confirmRegistration() — NOT the controller's approveRegistration().
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkApprove(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            // M3: only pending/interest may be approved into the pipe. A confirmed
            // row is rejected HERE, before the domain confirm path is re-entered —
            // this is the gate that prevents a second LD grant (D2).
            $from = RegistrationStatus::tryFrom((string) $reg->status);
            if ($from !== RegistrationStatus::Pending && $from !== RegistrationStatus::Interest) {
                return new WP_Error('invalid_status', __('Deze inschrijving kan niet goedgekeurd worden.', 'stride'));
            }

            $completion = ntdst_get(EnrollmentCompletion::class);
            $enrollment = ntdst_get(EnrollmentService::class);

            $task = $completion->completeTask($id, 'approval');
            if (is_wp_error($task)) {
                // task_not_required is benign for an already-approved row — still report it.
                return $task;
            }

            // D2: confirmRegistration returns WP_Error('invalid_status') for non-pending,
            // so an already-confirmed row never double-grants.
            return $enrollment->confirmRegistration($id);
        });
    }

    /**
     * stride_bulk_cancel — → cancelled (release seat + revokeAccess + notify).
     *
     * Wraps EnrollmentService::cancel() (V7).
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkCancel(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            $enrollment = ntdst_get(EnrollmentService::class);
            $result = $enrollment->cancel($id); // bool|WP_Error
            if (is_wp_error($result)) {
                return $result;
            }

            return true;
        });
    }
}
