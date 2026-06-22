<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteRepository;
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

        // Task 2.2 — quote workflow, waitlist promote, post-course approval, and
        // the two honestly-deferred stubs (message, generate_doc).
        add_filter('ntdst/api_data/stride_bulk_quote_sent', [$this, 'handleBulkQuoteSent'], 10, 2);
        add_filter('ntdst/api_data/stride_bulk_quote_exported', [$this, 'handleBulkQuoteExported'], 10, 2);
        add_filter('ntdst/api_data/stride_bulk_promote_waitlist', [$this, 'handleBulkPromoteWaitlist'], 10, 2);
        add_filter('ntdst/api_data/stride_bulk_approve_post_course', [$this, 'handleBulkApprovePostCourse'], 10, 2);
        add_filter('ntdst/api_data/stride_bulk_message', [$this, 'handleBulkMessage'], 10, 2);
        add_filter('ntdst/api_data/stride_bulk_generate_doc', [$this, 'handleBulkGenerateDoc'], 10, 2);
        // Task 2.3 adds: set_field.
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

    /**
     * Shared quote-status setter for the bulk quote actions.
     *
     * Resolves each registration's quote (V11) and sets QuoteStatus via
     * QuoteRepository::updateStatus — the SAME field the grid offerte column,
     * the Vandaag offerte queue and the annual report read. There is NO paid
     * field on QuoteStatus; a row with no quote lands in failed[] with no_quote.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    private function setQuoteStatusForRows(array $params, QuoteStatus $status): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        $quoteRepo = ntdst_get(QuoteRepository::class);

        return $this->runBulk($params, function (int $id, object $reg) use ($quoteRepo, $status): true|WP_Error {
            $map = $quoteRepo->findQuoteIdsByRegistrations([$id]); // regId => quoteId (V11)
            $quoteId = (int) ($map[$id] ?? 0);
            if (!$quoteId) {
                return new WP_Error('no_quote', __('Geen offerte voor deze inschrijving.', 'stride'));
            }
            if (!$quoteRepo->updateStatus($quoteId, $status)) {
                return new WP_Error('quote_update_failed', __('Offertestatus kon niet worden bijgewerkt.', 'stride'));
            }

            return true;
        });
    }

    /**
     * stride_bulk_quote_sent — set the linked quote to Sent.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkQuoteSent(mixed $data, array $params): array|WP_Error
    {
        return $this->setQuoteStatusForRows($params, QuoteStatus::Sent);
    }

    /**
     * stride_bulk_quote_exported — set the linked quote to Exported.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkQuoteExported(mixed $data, array $params): array|WP_Error
    {
        return $this->setQuoteStatusForRows($params, QuoteStatus::Exported);
    }

    /**
     * stride_bulk_promote_waitlist — waitlist → confirmed.
     *
     * THIN wrapper over the single-item domain method
     * EnrollmentService::promoteFromWaitlist (one code path: the per-row capacity
     * re-check, INV-7 terminal-edition gate, grant + confirmed-event all live in
     * the domain method — lesson_pure_passthrough_is_drift).
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkPromoteWaitlist(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            $enrollment = ntdst_get(EnrollmentService::class);

            return $enrollment->promoteFromWaitlist($id);
        });
    }

    /**
     * stride_bulk_approve_post_course — post-course approval gate.
     *
     * Wraps the DOMAIN call completeTask('post_approval') (D1) — NOT the
     * controller's approvePostCourse(WP_REST_Request).
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkApprovePostCourse(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            $completion = ntdst_get(EnrollmentCompletion::class);

            return $completion->completeTask($id, 'post_approval');
        });
    }

    /**
     * stride_bulk_message — HONEST DEFERRED STUB (D3, Phase 2).
     *
     * Templated-email broadcast lives in netdust-mail (project_mail_broadcast_feature),
     * not Stride; NotificationService has no per-row templated-send. Rather than
     * silently claim success, every per-row result is an explicit not_available
     * WP_Error so the report shows them as failed and the UI can disable the action.
     * The action stays registered so its name exists on the registry. The M2
     * capability gate still runs FIRST — a view-only actor is denied before the loop.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkMessage(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            return new WP_Error('not_available', __('Berichten versturen volgt in een latere fase.', 'stride'));
        });
    }

    /**
     * stride_bulk_generate_doc — HONEST DEFERRED STUB (D4, Phase 3).
     *
     * Field-scoped per-registration deliverable export is explicitly Phase 3; no
     * clean per-row generator exists today. As with message, each per-row result
     * is an explicit not_available WP_Error — never a silent success — and the M2
     * gate runs FIRST.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkGenerateDoc(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny;
        }

        return $this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            return new WP_Error('not_available', __('Documentgeneratie volgt in een latere fase.', 'stride'));
        });
    }
}
