<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Handlers\Support\BulkRunner;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Enrollment\RegistrationTransitions;
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
    // M2 capability gate + B3 batch cap + CR-1 select-all expansion + M3/M9
    // per-row report loop + M10 completion event — the SINGLE shared engine,
    // also used by RosterBulkHandler (Phase 2a). EXPANSION_FETCH_LIMIT (public,
    // for the integration boundary test) + MAX_BATCH live on the trait.
    use BulkRunner;

    /**
     * M7 server-side field allowlist for stride_bulk_set_field.
     *
     * Only dumb, side-effect-free columns the registration table actually stores
     * AND that RegistrationRepository::update() persists. NEVER a lifecycle field
     * (status/completed_at/cancelled_at) — those carry domain effects (seat
     * release, LD access, notifications) and route through the smart bulk actions.
     *
     * NOTE: the spec named `tags`, but there is no `tags` column or meta on the
     * registration table — persisting it would be a silent no-op success. The
     * honest safe set is the columns update() writes: notes + company_id.
     */
    private const SAFE_FIELDS = ['notes', 'company_id'];

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

        // Task 2.3 — generic safe-column setter with a server-side allowlist (M7).
        add_filter('ntdst/api_data/stride_bulk_set_field', [$this, 'handleBulkSetField'], 10, 2);
    }

    /**
     * M10 — quote-action tail. A quote status set via the repo does NOT trip
     * save_post_vad_quote, so the offerte-opvolging queue would go stale without
     * an explicit bust. Fire quote_status_changed once when ≥1 row succeeded,
     * then the shared bulk_completed recount.
     *
     * @param array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}} $report
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}
     */
    private function finishQuoteBatch(array $report): array
    {
        if (($report['summary']['ok'] ?? 0) > 0) {
            do_action('stride/registration/quote_status_changed', ['count' => $report['summary']['ok']]);
        }

        return $this->finishBatch($report);
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

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            // M3 / B5: a row may be approved into the pipe only if the ONE
            // transition map (RegistrationTransitions, V15) permits
            // <status> -> Confirmed. A confirmed/terminal row is rejected HERE,
            // before the domain confirm path is re-entered — this is the gate that
            // prevents a second LD grant (D2). Deriving from the map (not a
            // hard-coded Pending|Interest literal) keeps the handler from drifting
            // if the map changes; the domain confirmRegistration() re-guards.
            $from = RegistrationStatus::tryFrom((string) $reg->status);
            if ($from === null || !RegistrationTransitions::isAllowed($from, RegistrationStatus::Confirmed)) {
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
        }));
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

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            $enrollment = ntdst_get(EnrollmentService::class);
            $result = $enrollment->cancel($id); // bool|WP_Error
            if (is_wp_error($result)) {
                return $result;
            }

            return true;
        }));
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
        // CR-1 DELIBERATE EXCEPTION: pre-resolves before the B2 reg→quote map;
        // runBulk re-resolves idempotently. Task 4.1 — expand select-all HERE
        // (post-deny) so the B2 reg→quote map below and runBulk both read the
        // SAME expanded id set. The quote handlers delegate their authz to this
        // method, so this is their post-deny seam.
        $params = $this->resolveBulkIds($params);

        $quoteRepo = ntdst_get(QuoteRepository::class);

        // B2 (N+1 fix): resolve the reg→quote map ONCE for the whole selection in
        // a single IN query, instead of one postmeta lookup per row. The id set
        // here MUST match what runBulk iterates (it applies the same
        // absint+dedupe), so every row reads its quote id from $map[$id].
        $ids = array_values(array_unique(array_map('absint', (array) ($params['ids'] ?? []))));
        $map = $quoteRepo->findQuoteIdsByRegistrations($ids); // regId => quoteId (V11)

        return $this->runBulk($params, function (int $id, object $reg) use ($quoteRepo, $status, $map): true|WP_Error {
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
        $report = $this->setQuoteStatusForRows($params, QuoteStatus::Sent);

        return is_wp_error($report) ? $report : $this->finishQuoteBatch($report);
    }

    /**
     * stride_bulk_quote_exported — set the linked quote to Exported.
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkQuoteExported(mixed $data, array $params): array|WP_Error
    {
        $report = $this->setQuoteStatusForRows($params, QuoteStatus::Exported);

        return is_wp_error($report) ? $report : $this->finishQuoteBatch($report);
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

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            $enrollment = ntdst_get(EnrollmentService::class);

            return $enrollment->promoteFromWaitlist($id);
        }));
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

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            $completion = ntdst_get(EnrollmentCompletion::class);

            return $completion->completeTask($id, 'post_approval');
        }));
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

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            return new WP_Error('not_available', __('Berichten versturen volgt in een latere fase.', 'stride'));
        }));
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

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg): true|WP_Error {
            return new WP_Error('not_available', __('Documentgeneratie volgt in een latere fase.', 'stride'));
        }));
    }

    /**
     * stride_bulk_set_field — generic safe-column setter across the selection.
     *
     * M7 (THE control): the server enforces a hard field allowlist BEFORE touching
     * any row. A field outside self::SAFE_FIELDS — status, completed_at,
     * cancelled_at, ANY lifecycle field — is refused with a 400 regardless of
     * payload. The UI hiding such fields is cosmetic; THIS rejection is the
     * integrity guard. Lifecycle changes must route through the smart bulk actions
     * so domain effects (seat release, LD access, notifications) fire.
     *
     * The value is sanitized per field type (company_id → absint;
     * notes → sanitize_text_field) and persisted via RegistrationRepository::update
     * (INV-3 — repository, no raw $wpdb). Per-row write failures land in failed[].
     *
     * @param array<string,mixed> $params
     * @return array{total:int, succeeded:array<int,array{id:int}>, failed:array<int,array{id:int,code:string,message:string}>, summary:array{ok:int,error:int}}|WP_Error
     */
    public function handleBulkSetField(mixed $data, array $params): array|WP_Error
    {
        if ($deny = $this->denyIfNotManager()) {
            return $deny; // M2 first
        }

        // M7 ordering preserved: the field allowlist reads $params['field'] (NOT
        // ids), so it still fires before runBulk's loop — field=status is rejected
        // with a 400 BEFORE any row is touched. Select-all expansion (CR-1) now
        // happens inside runBulk, AFTER this gate, which is correct: the
        // allowlist guard never needed the expanded id set.
        $field = sanitize_key((string) ($params['field'] ?? ''));

        // M7: server-side allowlist — reject any non-safe field with a 400, BEFORE
        // resolving or mutating a single row. This is the load-bearing control.
        if (!in_array($field, self::SAFE_FIELDS, true)) {
            return new WP_Error('invalid_field', __('Dit veld kan niet in bulk worden gewijzigd.', 'stride'), ['status' => 400]);
        }

        $value = $field === 'company_id'
            ? absint($params['value'] ?? 0)
            : sanitize_text_field((string) ($params['value'] ?? ''));

        $repo = ntdst_get(RegistrationRepository::class);

        return $this->finishBatch($this->runBulk($params, function (int $id, object $reg) use ($repo, $field, $value): true|WP_Error {
            if (!$repo->update($id, [$field => $value])) {
                return new WP_Error('update_failed', __('Veld kon niet worden bijgewerkt.', 'stride'));
            }

            return true;
        }));
    }
}
