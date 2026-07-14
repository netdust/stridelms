<?php

declare(strict_types=1);

namespace Stride\Admin;

use NTDST\Audit\AuditTable;
use Stride\Admin\Support\AdminBatchHelpers;
use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\BatchQueryHelper;
use Stride\Modules\Attendance\AttendanceTable;
use Stride\Modules\Edition\EditionCPT;
use Stride\Modules\Edition\SessionCPT;
use Stride\Modules\Enrollment\RegistrationTable;
use Stride\Modules\Invoicing\QuoteCPT;
use Stride\Modules\User\ProfileTypeService;
use WP_REST_Request;
use WP_Error;
use WP_REST_Response;

/**
 * Read-model assembly for the admin user-detail (Dossier) case view.
 *
 * Strangled out of AdminAPIController (§12.4 / S2) behavior-preserving: the SQL
 * data-gathering for GET /admin/users/{id}/detail lives here; the controller
 * method is a thin delegator. Returns edition/quote/attendance/audit only —
 * the case-view trajectory section is out of scope (1E / cluster C2).
 *
 * Shared audit-context hydration (enrichAuditContexts + fetchPostTitles) is
 * cross-domain — also consumed by the controller's getActivityFeed /
 * getNotifications — so it comes from the AdminBatchHelpers trait, not a
 * private copy here.
 *
 * Registered in plugin-config.php.
 */
final class AdminUserService
{
    use AdminBatchHelpers;

    /** Dutch labels for the enrollment_path column (raw slugs stay in the payload). */
    private const PATH_LABELS = [
        'individual' => 'Individueel',
        'colleague'  => 'Via collega',
        'trajectory' => 'Via traject',
        'partner'    => 'Via partner',
    ];

    /** @var array<int,string> per-request user display-name cache (stage actors). */
    private array $userNameCache = [];

    /**
     * Assemble the user-detail (Dossier) case-view response.
     *
     * Moved verbatim from AdminAPIController::getUserDetail (behavior-preserving).
     */
    public function getUserDetail(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        global $wpdb;

        $userId = (int) $request->get_param('id');
        $regPage = max(1, (int) $request->get_param('reg_page'));
        // Default 20; the client may widen (clamped) so a soft refresh can
        // re-fetch everything it already had loaded in ONE request instead of
        // collapsing back to the first page.
        $regPerPage = min(100, max(1, (int) ($request->get_param('reg_per_page') ?: 20)));

        // Sensitive fields (phone, audit trail, full quote listing) are only
        // returned to stride_manage. stride_view (read-only Supervisor role)
        // gets the safe subset — without this, a Supervisor can dump the
        // entire user base via /admin/users/{id}/detail.
        $canSeeSensitive = current_user_can('stride_manage');

        // --- User data ---
        $userData = get_userdata($userId);
        if (!$userData) {
            return new WP_Error('not_found', 'User not found', ['status' => 404]);
        }

        // Profile type
        $profileType = null;
        $profileService = ntdst_get(ProfileTypeService::class);
        if ($profileService) {
            $type = $profileService->getUserType($userId);
            if ($type) {
                $profileType = [
                    'name' => $type['label'] ?? $type['slug'],
                    'color' => $type['color'] ?? '',
                ];
            }
        }

        $anonymisedAt = (int) get_user_meta($userId, '_stride_anonymised_at', true);
        $isAnonymised = $anonymisedAt > 0;

        $anonymiseUrl = null;
        if (!$isAnonymised && current_user_can('edit_user', $userId) && $userId !== get_current_user_id()) {
            $anonymiseUrl = wp_nonce_url(
                admin_url('admin-post.php?action=stride_anonymise_user&user=' . $userId),
                'stride_anonymise_user_' . $userId,
            );
        }

        $sensitivePlaceholder = '••••••';

        $rawNationalId = get_user_meta($userId, 'national_id', true) ?: '';
        $rawDateOfBirth = get_user_meta($userId, 'date_of_birth', true) ?: '';
        $rawLicense = get_user_meta($userId, 'professional_license_number', true) ?: '';

        $user = [
            'id' => $userId,
            'first_name' => $userData->first_name ?? '',
            'last_name' => $userData->last_name ?? '',
            'display_name' => $userData->display_name,
            'email' => $userData->user_email,
            'phone' => $canSeeSensitive ? (get_user_meta($userId, 'phone', true) ?: '') : '',
            'organisation' => get_user_meta($userId, 'organisation', true) ?: '',
            'department' => get_user_meta($userId, 'department', true) ?: '',

            // Sensitive identity fields — read-only masked for non-managers.
            // Boolean flag tells the UI whether to show a "reveal" affordance.
            'national_id' => $canSeeSensitive && $rawNationalId !== '' ? $sensitivePlaceholder : '',
            'national_id_present' => $rawNationalId !== '',
            'date_of_birth' => $canSeeSensitive && $rawDateOfBirth !== '' ? $sensitivePlaceholder : '',
            'date_of_birth_present' => $rawDateOfBirth !== '',
            'professional_license_number' => $canSeeSensitive && $rawLicense !== '' ? $sensitivePlaceholder : '',
            'professional_license_number_present' => $rawLicense !== '',

            // Billing
            'billing_company' => get_user_meta($userId, 'billing_company', true) ?: '',
            'billing_vat' => get_user_meta($userId, 'billing_vat', true) ?: '',
            'billing_address_1' => get_user_meta($userId, 'billing_address_1', true) ?: '',
            'billing_postcode' => get_user_meta($userId, 'billing_postcode', true) ?: '',
            'billing_city' => get_user_meta($userId, 'billing_city', true) ?: '',
            'invoice_email' => get_user_meta($userId, 'invoice_email', true) ?: '',
            'gln_number' => get_user_meta($userId, 'gln_number', true) ?: '',

            'profile_type' => $profileType,
            'is_anonymised' => $isAnonymised,
            'anonymised_label' => $isAnonymised
                ? sprintf(__('Geanonimiseerd op %s', 'stride'), date_i18n('d M Y', $anonymisedAt))
                : '',
            'anonymise_url' => $anonymiseUrl,
        ];

        // --- Registrations (paginated, with edition title) ---
        $registrations = [];
        $registrationsTotal = 0;
        $registrationTable = RegistrationTable::getTableName();

        if (RegistrationTable::exists()) {
            $registrationsTotal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$registrationTable} WHERE user_id = %d",
                $userId,
            ));

            $regOffset = ($regPage - 1) * $regPerPage;
            // tp join: trajectory-parent rows (edition_id NULL, trajectory_id set —
            // the cascade parent of a trajectory enrollment) previously fell through
            // to "Onbekend" because only the edition title was selected. The
            // trajectory title is the row's real name for those rows.
            $regRows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.id, r.edition_id, r.trajectory_id, r.status, r.enrollment_path,
                        r.registered_at, r.completed_at, r.cancelled_at, r.selections,
                        r.enrollment_data, r.completion_tasks, r.notes, r.quote_id,
                        p.post_title AS edition_title, tp.post_title AS trajectory_title
                 FROM {$registrationTable} r
                 LEFT JOIN {$wpdb->posts} p ON r.edition_id = p.ID
                 LEFT JOIN {$wpdb->posts} tp ON r.trajectory_id = tp.ID
                 WHERE r.user_id = %d
                 ORDER BY r.registered_at DESC
                 LIMIT %d OFFSET %d",
                $userId,
                $regPerPage,
                $regOffset,
            ));

            // Pre-fetch attendance stats + total session counts for all loaded editions,
            // so each row carries actionable info without N+1 queries.
            $editionIds = array_map(static fn($r) => (int) $r->edition_id, $regRows);
            $editionIds = array_values(array_unique(array_filter($editionIds)));

            $attendanceByEdition = $this->fetchUserAttendanceByEdition($userId, $editionIds);
            $sessionCountByEdition = $this->fetchSessionCountByEdition($editionIds);

            // Session details (title/date/times) for the loaded editions — one
            // batched read. Feeds: selection-label resolution, the per-session
            // attendance rows, and the HONEST hours sum (real durations of the
            // sessions marked present — the old "present × 4h" convention
            // fabricated hours for 2-hour or full-day sessions).
            $sessionDetailsById = $this->fetchSessionDetailsByEdition($editionIds);
            $sessionTitlesById = array_map(static fn(array $s) => $s['title'], $sessionDetailsById);
            $sessionStatusByEdition = $this->fetchUserSessionAttendance($userId, $editionIds);

            // Per-registration offerte status (the §0 #5 quote-workflow status, NEVER
            // "paid"). Resolve each reg → its linked quote: the explicit quote_id
            // column wins; otherwise fall back to a user quote on the same edition.
            // Quote statuses come from the already-batched $quoteMeta below — but that
            // is computed after this loop, so capture the linkage here and stamp the
            // status in a second pass once $quoteMeta exists.
            $regQuoteIdByReg = [];
            $regEditionByReg = [];

            // Per-row "waiting on user vs admin" reason for pending registrations.
            // INV-6b: the SERVER owns the completion_tasks read — the client never
            // parses the raw JSON column; it just renders pending_reason.label.
            $completion = ntdst_get(\Stride\Modules\Enrollment\EnrollmentCompletion::class);

            foreach ($regRows as $row) {
                $editionId = (int) $row->edition_id;
                $regQuoteIdByReg[(int) $row->id] = (int) ($row->quote_id ?? 0);
                $regEditionByReg[(int) $row->id] = $editionId;
                $att = $attendanceByEdition[$editionId] ?? null;
                $totalSessions = $sessionCountByEdition[$editionId] ?? 0;
                $attendanceSummary = null;

                if ($totalSessions > 0) {
                    $statusBySession = $sessionStatusByEdition[$editionId] ?? [];
                    $attendanceSummary = [
                        'present' => $att['present'] ?? 0,
                        'absent' => $att['absent'] ?? 0,
                        'excused' => $att['excused'] ?? 0,
                        'total_sessions' => $totalSessions,
                        // Real hours: sum of the present sessions' start→end durations.
                        'hours' => $this->presentHours($editionId, $statusBySession, $sessionDetailsById),
                        // Per-session rows — WHICH day was missed, not just a count.
                        'sessions' => $this->buildSessionRows($editionId, $statusBySession, $sessionDetailsById),
                    ];
                }

                // completion_tasks decoded ONCE per row — feeds the task list, the
                // pending_reason, and the intake-answer fallback below. The Data
                // layer may hand it already-decoded (array) or raw JSON (string).
                $tasks = is_array($row->completion_tasks ?? null)
                    ? $row->completion_tasks
                    : (json_decode((string) ($row->completion_tasks ?? ''), true) ?: []);

                // enrollment_data stages (3-key shape: {submitted_at, submitted_by, data}).
                // Surfaced for the case view's collapsible stage panels — empty stages
                // are filtered CLIENT-side (hidden), so only normalize the shape here.
                $stages = $this->normalizeEnrollmentStages($row->enrollment_data ?? null);

                // Intake answers live in TWO places depending on flow: the stride_intake
                // shortcode writes enrollment_data.intake; the completion-task flow
                // (the primary one) writes completion_tasks.questionnaire.data.answers.
                // The dossier read only the former, so completion-flow intake answers
                // were invisible. Merge the questionnaire answers in as the intake
                // stage when the enrollment_data stage is empty.
                $stages = $this->mergeQuestionnaireStage($stages, $tasks);

                // selections column = session/edition IDs → resolve to titles.
                // INV-6b: the SERVER owns the selection read; the client never parses
                // the raw column. For the edition-centric dossier these are session IDs.
                $selectionLabels = $this->resolveSelectionLabels(
                    $row->selections ?? null,
                    $sessionTitlesById,
                );

                // pending_reason: only meaningful for pending rows.
                $pendingReason = null;
                if ($row->status === 'pending') {
                    $pendingReason = $completion->pendingReason($tasks);
                }

                // Row title: edition title for edition rows; for trajectory-parent
                // rows (edition_id NULL + trajectory_id set) the trajectory title.
                // Only a row whose linked post was DELETED still reads "Onbekend".
                $trajectoryId = (int) ($row->trajectory_id ?? 0);
                $isTrajectory = $editionId === 0 && $trajectoryId > 0;
                $rowTitle = (string) ($row->edition_title ?? '');
                if ($rowTitle === '' && $isTrajectory) {
                    $rowTitle = (string) ($row->trajectory_title ?? '');
                }

                $registrations[] = [
                    'id' => (int) $row->id,
                    'edition_id' => $editionId,
                    'trajectory_id' => $trajectoryId,
                    'is_trajectory' => $isTrajectory,
                    'edition_title' => $rowTitle !== '' ? $rowTitle : __('Onbekend', 'stride'),
                    'status' => $row->status,
                    'enrollment_path' => $row->enrollment_path,
                    'enrollment_path_label' => self::PATH_LABELS[(string) $row->enrollment_path]
                        ?? (string) $row->enrollment_path,
                    'registered_at' => $row->registered_at,
                    'registered_at_display' => $this->formatLocalDate((string) ($row->registered_at ?? '')),
                    'completed_at' => $row->completed_at,
                    'completed_at_display' => $this->formatLocalDate((string) ($row->completed_at ?? '')),
                    'cancelled_at' => $row->cancelled_at,
                    'cancelled_at_display' => $this->formatLocalDate((string) ($row->cancelled_at ?? '')),
                    'has_sessions' => $totalSessions > 0,
                    'attendance' => $attendanceSummary,
                    // The REAL completion tasks (Dutch label + per-task status) —
                    // the dossier renders THIS list, never a client-derived checklist.
                    'tasks' => $this->buildTaskList($tasks),
                    'stages' => $stages,
                    'selections' => $selectionLabels,
                    'notes' => (string) ($row->notes ?? ''),
                    'pending_reason' => $pendingReason,
                    // offerte stamped in the second pass below (needs $quoteMeta).
                    'offerte_status' => '',
                    'offerte_status_label' => '',
                ];
            }
        }

        // --- Quotes (linked to user by user_id meta or billing email) ---
        //
        // WP_Query meta_query OR forces a double LEFT JOIN with no covering
        // index, then the per-row get_post_meta() loop adds 5-7 lookups per
        // quote. Mirror getQuotes() instead: one SELECT with explicit joins,
        // one BatchQueryHelper::batchGetPostMeta() for everything we need.
        $quotePosts = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_title, p.post_date
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm_user
               ON pm_user.post_id = p.ID AND pm_user.meta_key = 'user_id'
             LEFT JOIN {$wpdb->postmeta} pm_email
               ON pm_email.post_id = p.ID AND pm_email.meta_key = 'billing_email'
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND (pm_user.meta_value = %s OR pm_email.meta_value = %s)
             ORDER BY p.post_date DESC
             LIMIT 20",
            QuoteCPT::POST_TYPE,
            (string) $userId,
            $userData->user_email,
        ));

        $quoteIds = array_map(static fn($q) => (int) $q->ID, $quotePosts);
        $quoteMeta = BatchQueryHelper::batchGetPostMeta($quoteIds, [
            'quote_number', 'status', 'total', 'edition_id',
            'sent_at', 'valid_until',
        ]);

        $quoteEditionIds = array_values(array_unique(array_filter(array_map(
            static fn($id) => (int) ($quoteMeta[$id]['edition_id'] ?? 0),
            $quoteIds,
        ))));
        $quoteEditions = BatchQueryHelper::batchGetPosts($quoteEditionIds, EditionCPT::POST_TYPE);

        // Two shapes from the same source: $quotes is the SENSITIVE detail list
        // (totals/dates/numbers — returned only to stride_manage, line ~445);
        // $quoteStatusFor* is the NON-sensitive offerte-workflow status used to
        // stamp each registration's offerte_status (shown to everyone in the
        // grid/dossier). The full detail array is only built when the caller may
        // see it, so sensitive fields are never assembled for a view-only role
        // (CR-4 — gate the assembly, not just the return).
        $quotes = [];
        $quoteStatusById = [];
        $quoteStatusByEdition = [];
        foreach ($quotePosts as $quotePost) {
            $quoteId = (int) $quotePost->ID;
            $meta = $quoteMeta[$quoteId] ?? [];

            $quoteEditionId = (int) ($meta['edition_id'] ?? 0);
            $quoteStatus = (string) ($meta['status'] ?? '');
            $statusEnum = QuoteStatus::tryFrom($quoteStatus);
            $statusLabel = $statusEnum?->label() ?? $quoteStatus;

            // Non-sensitive offerte-status map (workflow value only).
            $quoteStatusById[$quoteId] = ['value' => $quoteStatus, 'label' => $statusLabel];
            if ($quoteEditionId > 0 && !isset($quoteStatusByEdition[$quoteEditionId])) {
                $quoteStatusByEdition[$quoteEditionId] = ['value' => $quoteStatus, 'label' => $statusLabel];
            }

            if (!$canSeeSensitive) {
                continue; // Skip building the sensitive detail row entirely.
            }

            $quoteTotal = Money::cents((int) ($meta['total'] ?? 0));
            $quotes[] = [
                'id' => $quoteId,
                'title' => $quotePost->post_title,
                'number' => (string) ($meta['quote_number'] ?? ''),
                'edition_id' => $quoteEditionId,
                'edition_title' => isset($quoteEditions[$quoteEditionId]) ? $quoteEditions[$quoteEditionId]->post_title : '',
                'status' => $quoteStatus,
                'status_label' => $statusLabel,
                'total' => $quoteTotal->amount(),
                'total_display' => '€ ' . number_format($quoteTotal->amount(), 2, ',', '.'),
                'created_at' => $quotePost->post_date,
                'created_at_display' => $this->formatLocalDate((string) $quotePost->post_date),
                'sent_at' => ($meta['sent_at'] ?? '') ?: null,
                'valid_until' => ($meta['valid_until'] ?? '') ?: null,
                'valid_until_display' => $this->formatLocalDate((string) ($meta['valid_until'] ?? '')),
                'edit_url' => admin_url('post.php?post=' . $quoteId . '&action=edit'),
            ];
        }

        // --- Second pass: stamp each registration's offerte (quote-workflow) status ---
        // Reg → quote linkage: the explicit quote_id column wins; else the user's
        // quote on the same edition. The status is the QuoteStatus workflow value
        // (Draft/Sent/Exported/Cancelled) — NEVER a paid/unpaid flag (Stride does not
        // track payment; gotcha_no_payment_tracking).
        if (!empty($registrations)) {
            // $quoteStatusById / $quoteStatusByEdition were built during the quote
            // loop above (non-sensitive workflow status; populated regardless of
            // $canSeeSensitive so view-only roles still get the offerte stamp).
            foreach ($registrations as &$reg) {
                $regId = (int) $reg['id'];
                $linkedQuoteId = $regQuoteIdByReg[$regId] ?? 0;
                $regEdition = $regEditionByReg[$regId] ?? 0;

                $offerte = null;
                if ($linkedQuoteId > 0 && isset($quoteStatusById[$linkedQuoteId])) {
                    $offerte = $quoteStatusById[$linkedQuoteId];
                } elseif ($regEdition > 0 && isset($quoteStatusByEdition[$regEdition])) {
                    $offerte = $quoteStatusByEdition[$regEdition];
                }

                if ($offerte !== null) {
                    $reg['offerte_status'] = $offerte['value'];
                    $reg['offerte_status_label'] = $offerte['label'];
                }
            }
            unset($reg);
        }

        // --- Attendance summary (grouped by edition) ---
        $attendance = [];
        if (AttendanceTable::exists()) {
            $attendanceTable = AttendanceTable::getTableName();
            $attRows = $wpdb->get_results($wpdb->prepare(
                "SELECT a.edition_id, a.status, COUNT(*) as cnt,
                        p.post_title AS edition_title
                 FROM {$attendanceTable} a
                 LEFT JOIN {$wpdb->posts} p ON a.edition_id = p.ID
                 WHERE a.user_id = %d
                 GROUP BY a.edition_id, a.status
                 ORDER BY a.edition_id DESC",
                $userId,
            ));

            // Group by edition
            $grouped = [];
            foreach ($attRows as $row) {
                $editionId = (int) $row->edition_id;
                if (!isset($grouped[$editionId])) {
                    $grouped[$editionId] = [
                        'edition_id' => $editionId,
                        'edition_title' => $row->edition_title ?: __('Onbekend', 'stride'),
                        'present' => 0,
                        'absent' => 0,
                        'excused' => 0,
                    ];
                }
                $status = $row->status;
                if (isset($grouped[$editionId][$status])) {
                    $grouped[$editionId][$status] = (int) $row->cnt;
                } else {
                    // Fail loud, not silent: a status outside the known enum
                    // (present/absent/excused) would otherwise be dropped from
                    // the summary with no trace (CR-7).
                    ntdst_log('admin')->warning('AdminUserService: unknown attendance status dropped from summary', [
                        'user_id' => $userId,
                        'edition_id' => $editionId,
                        'status' => $status,
                        'count' => (int) $row->cnt,
                    ]);
                }
            }

            // Enrich with total session count + hours per edition
            $summaryEditionIds = array_keys($grouped);
            if (!empty($summaryEditionIds)) {
                $sessionCounts = $this->fetchSessionCountByEdition($summaryEditionIds);
                $summaryDetails = $this->fetchSessionDetailsByEdition($summaryEditionIds);
                $summaryStatuses = $this->fetchUserSessionAttendance($userId, $summaryEditionIds);
                foreach ($grouped as $editionId => &$row) {
                    $row['total_sessions'] = $sessionCounts[$editionId] ?? 0;
                    $row['hours'] = $this->presentHours($editionId, $summaryStatuses[$editionId] ?? [], $summaryDetails);
                }
                unset($row);
            }

            $attendance = array_values($grouped);
        }

        // --- Audit trail (last 50 entries where user is actor or subject) ---
        $auditTrail = [];
        $auditTrailTotal = 0;

        if (AuditTable::exists()) {
            $auditTable = AuditTable::getTableName();

            // Three match patterns for "this person's timeline":
            //   1. actor_id = U                          — things U did
            //   2. entity_type='user' AND entity_id = U  — user.* events about U
            //   3. subject_user_id = U — ANY event whose context.user_id is U.
            //      subject_user_id is the STORED generated column over
            //      context.user_id (ntdst-audit schema v2) — indexable AND an
            //      EXACT per-user match, so an event for a DIFFERENT user can
            //      never leak onto U's timeline (the cross-user-leak guard).
            //      Previously restricted to entity_type='registration', which
            //      silently dropped attendance.marked_* (entity=attendance,
            //      marked BY an admin), quote.created (entity=quote) and
            //      completion events from the dossier — the "quote/attendance
            //      events invisible" half of F-D7. mail.sent deliberately
            //      records no context.user_id (audit H-4), so it stays out.
            $auditTrailTotal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$auditTable}
                 WHERE actor_id = %d
                    OR (entity_type = 'user' AND entity_id = %d)
                    OR subject_user_id = %d",
                $userId,
                $userId,
                $userId,
            ));

            $auditEntries = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$auditTable}
                 WHERE actor_id = %d
                    OR (entity_type = 'user' AND entity_id = %d)
                    OR subject_user_id = %d
                 ORDER BY created_at DESC
                 LIMIT 50",
                $userId,
                $userId,
                $userId,
            ));

            // Collect actor IDs AND target user IDs for batch fetch
            $userIdsToResolve = [];
            foreach ($auditEntries as $entry) {
                if (!empty($entry->actor_id)) {
                    $userIdsToResolve[] = (int) $entry->actor_id;
                }
                if (($entry->entity_type ?? '') === 'user' && !empty($entry->entity_id)) {
                    $userIdsToResolve[] = (int) $entry->entity_id;
                }
            }
            $usersMap = !empty($userIdsToResolve)
                ? BatchQueryHelper::batchGetUsers(array_unique($userIdsToResolve))
                : [];

            $auditEntries = $this->enrichAuditContexts($auditEntries);

            foreach ($auditEntries as $entry) {
                $actorId = (int) ($entry->actor_id ?? 0);
                $actorUser = $usersMap[$actorId] ?? null;
                $actorName = $actorUser ? $actorUser->display_name : __('Systeem', 'stride');

                $targetName = '';
                if (($entry->entity_type ?? '') === 'user' && !empty($entry->entity_id)) {
                    $targetUser = $usersMap[(int) $entry->entity_id] ?? null;
                    if ($targetUser) {
                        $targetName = $targetUser->display_name;
                    }
                }

                $auditTrail[] = AdminActivityMapper::fromAuditEntry($entry, $actorName, $targetName);
            }

            // Collapse bursts of identical lines. usermeta.updated is recorded
            // PER meta key — one profile save yields ~10 identical
            // "Profielgegevens bijgewerkt" lines that pushed real lifecycle
            // events out of the visible window (F-D10). Entries are DESC-
            // ordered; keep the newest of each burst (same text + actor
            // within 5 minutes).
            $collapsed = [];
            $prev = null;
            foreach ($auditTrail as $item) {
                if ($prev !== null
                    && $prev['text'] === $item['text']
                    && $prev['actor_name'] === $item['actor_name']
                    && abs($prev['timestamp'] - $item['timestamp']) <= 300
                ) {
                    $prev = $item;
                    continue;
                }
                $collapsed[] = $item;
                $prev = $item;
            }
            $auditTrail = $collapsed;
        }

        return new WP_REST_Response([
            'user' => $user,
            'registrations' => $registrations,
            'registrations_total' => $registrationsTotal,
            'reg_page' => $regPage,
            'reg_per_page' => $regPerPage,
            'quotes' => $canSeeSensitive ? $quotes : [],
            'attendance' => $attendance,
            // Explicit gate flag: an EMPTY audit trail and a GATED audit trail
            // both serialize as [] — without this the UI could not distinguish
            // "no history yet" from "afgeschermd voor jouw rol" (F-D14).
            'can_see_timeline' => $canSeeSensitive,
            'audit_trail' => $canSeeSensitive ? $auditTrail : [],
            'audit_trail_total' => $canSeeSensitive ? $auditTrailTotal : 0,
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS (user-detail-only — verified single-consumer at S2)
    // =========================================================================

    /**
     * Normalize the raw `enrollment_data` JSON column into the case-view stage
     * map. Each stage follows the canonical 3-key shape
     * `{submitted_at, submitted_by, data}` (RegistrationRepository::STAGE_SHAPE).
     *
     * `initial_selection` is un-wrapped (`{type, phases[]}`) — surfaced as-is under
     * its own key. The client filters EMPTY stages (no data keys) out of the view;
     * we only normalize the shape here so the renderer never sees a half-formed stage.
     *
     * @param  mixed $raw  JSON string or already-decoded array from the column.
     * @return array<string, array{submitted_at:string, submitted_by:string, data:array<string,mixed>}>
     */
    private function normalizeEnrollmentStages(mixed $raw): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (!is_array($decoded)) {
            return [];
        }

        $stages = [];
        foreach ($decoded as $key => $stage) {
            if (!is_array($stage)) {
                continue;
            }
            // initial_selection is un-wrapped (`{type, phases[]}`) — its phases are
            // arrays-of-arrays, which the generic flattener reduced to an empty
            // string (garbage panel). Build proper label→value pairs instead:
            // one row per capture phase, values = resolved post titles.
            if ($key === 'initial_selection') {
                $stages[$key] = $this->initialSelectionStage($stage);
                continue;
            }
            // submitted_at is stored gmdate('c') (UTC ISO) → Dutch site-TZ moment;
            // submitted_by is a raw user ID → display name (was printed as "door 5").
            $stages[$key] = [
                'submitted_at' => $this->formatIsoMoment((string) ($stage['submitted_at'] ?? '')),
                'submitted_by' => $this->resolveUserName($stage['submitted_by'] ?? null),
                'data' => is_array($stage['data'] ?? null) ? $this->flattenStageData($stage['data']) : [],
            ];
        }

        return $stages;
    }

    /**
     * Flatten a stage's data to scalar label→value pairs the case view can render
     * directly (never a raw JSON dump). Nested arrays are comma-joined; booleans
     * become Ja/Nee. Keeps the client renderer a dumb dt/dd loop.
     *
     * @param  array<string,mixed> $data
     * @return array<string,string>
     */
    private function flattenStageData(array $data): array
    {
        $out = [];
        foreach ($data as $label => $value) {
            if (is_bool($value)) {
                $out[(string) $label] = $value ? __('Ja', 'stride') : __('Nee', 'stride');
            } elseif (is_array($value)) {
                $flat = array_filter(array_map(
                    static fn($v) => is_scalar($v) ? (string) $v : '',
                    $value,
                ), static fn($v) => $v !== '');
                $out[(string) $label] = implode(', ', $flat);
            } elseif (is_scalar($value)) {
                $out[(string) $label] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * The registration's completion tasks as a renderable list (Dutch label +
     * per-task status). Insertion order is the creation order (enrollment tasks
     * first, post-course tasks appended at completion) — kept as-is.
     *
     * @param  array<string,mixed> $tasks  Decoded completion_tasks column.
     * @return list<array{type:string, label:string, status:string, completed_at:string, phase:string}>
     */
    private function buildTaskList(array $tasks): array
    {
        $list = [];
        foreach ($tasks as $type => $task) {
            if (!is_array($task)) {
                continue;
            }
            $completedAt = (string) ($task['completed_at'] ?? '');
            $list[] = [
                'type' => (string) $type,
                'label' => \Stride\Modules\Enrollment\EnrollmentCompletion::taskTypeLabel((string) $type),
                'status' => (string) ($task['status'] ?? 'pending'),
                'completed_at' => $completedAt !== ''
                    ? wp_date('d/m/Y', (int) strtotime($completedAt))
                    : '',
                'phase' => (string) ($task['phase'] ?? 'enrollment'),
            ];
        }

        return $list;
    }

    /**
     * Merge completion-flow intake answers into the stage map.
     *
     * The `stride_intake` shortcode flow writes `enrollment_data.intake`; the
     * completion-task flow stores the SAME questionnaire's answers in
     * `completion_tasks.questionnaire.data.answers`. When the enrollment_data
     * stage is empty but questionnaire answers exist, synthesize the intake
     * stage from them so the dossier shows the answers regardless of flow.
     * A populated enrollment_data.intake always wins (never overwritten).
     *
     * @param  array<string,array{submitted_at:string,submitted_by:string,data:array<string,string>}> $stages
     * @param  array<string,mixed> $tasks  Decoded completion_tasks column.
     * @return array<string,array{submitted_at:string,submitted_by:string,data:array<string,string>}>
     */
    private function mergeQuestionnaireStage(array $stages, array $tasks): array
    {
        if (!empty($stages['intake']['data'])) {
            return $stages;
        }

        $answers = $tasks['questionnaire']['data']['answers'] ?? null;
        if (!is_array($answers) || $answers === []) {
            return $stages;
        }

        $completedAt = (string) ($tasks['questionnaire']['completed_at'] ?? '');
        $stages['intake'] = [
            'submitted_at' => $completedAt !== ''
                ? wp_date('d/m/Y H:i', (int) strtotime($completedAt))
                : '',
            // The completion flow records no separate actor — it is always the
            // participant; leave submitted_by empty rather than guessing.
            'submitted_by' => '',
            'data' => $this->flattenStageData($answers),
        ];

        return $stages;
    }

    /**
     * Render the un-wrapped `initial_selection` (`{type, phases[]}`) as a stage.
     *
     * One data row per capture phase: label = phase name + capture moment,
     * value = the chosen sessions'/editions' titles (deleted posts keep their
     * id with a "(verwijderd)" marker — the trail must stay honest). The stage
     * header carries the FIRST capture's moment + actor.
     *
     * @param  array<string,mixed> $initial
     * @return array{submitted_at:string, submitted_by:string, data:array<string,string>}
     */
    private function initialSelectionStage(array $initial): array
    {
        $data = [];
        $headerAt = '';
        $headerBy = '';

        foreach ((array) ($initial['phases'] ?? []) as $phase) {
            if (!is_array($phase)) {
                continue;
            }
            $ids = $phase['session_ids'] ?? $phase['edition_ids'] ?? [];
            if (!is_array($ids)) {
                continue;
            }

            $items = [];
            foreach ($ids as $id) {
                $post = get_post((int) $id);
                $items[] = $post
                    ? $post->post_title
                    : sprintf(__('#%d (verwijderd)', 'stride'), (int) $id);
            }

            $label = match ($phase['phase'] ?? 'enrollment') {
                'enrollment' => __('Bij inschrijving', 'stride'),
                default => ucfirst(str_replace('_', ' ', (string) ($phase['phase'] ?? ''))),
            };

            $capturedAt = (string) ($phase['captured_at'] ?? '');
            if ($capturedAt !== '') {
                $atDisplay = wp_date('d/m/Y H:i', (int) strtotime($capturedAt));
                $label .= ' · ' . $atDisplay;
                if ($headerAt === '') {
                    $headerAt = $atDisplay;
                }
            }
            if ($headerBy === '' && !empty($phase['captured_by'])) {
                $byUser = get_userdata((int) $phase['captured_by']);
                $headerBy = $byUser ? $byUser->display_name : '';
            }

            // Two phases can share a label (same phase, same minute) — suffix
            // instead of silently overwriting the earlier capture.
            $key = $label;
            $n = 2;
            while (isset($data[$key])) {
                $key = $label . ' (' . $n++ . ')';
            }
            $data[$key] = $items !== []
                ? implode(', ', $items)
                : __('Geen keuze', 'stride');
        }

        return [
            'submitted_at' => $headerAt,
            'submitted_by' => $headerBy,
            'data' => $data,
        ];
    }

    /**
     * Resolve the `selections` JSON column (session/edition IDs) to display labels.
     *
     * INV-6b: the server owns the selection read — the client never parses the raw
     * column. IDs that match a loaded session title resolve to it; unknown IDs fall
     * back to "Sessie #<id>" so a stale selection never renders blank.
     *
     * @param  mixed $raw  JSON string or array of IDs.
     * @param  array<int,string> $sessionTitlesById
     * @return array<int,string>
     */
    private function resolveSelectionLabels(mixed $raw, array $sessionTitlesById): array
    {
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
        if (!is_array($decoded)) {
            return [];
        }

        $labels = [];
        foreach ($decoded as $id) {
            if (!is_scalar($id)) {
                continue;
            }
            $sid = (int) $id;
            if ($sid <= 0) {
                continue;
            }
            $labels[] = $sessionTitlesById[$sid] ?? sprintf(__('Sessie #%d', 'stride'), $sid);
        }

        return $labels;
    }

    /**
     * Session details (title / edition / date / times) for a set of editions.
     *
     * One batched query. Feeds selection-label resolution, the per-session
     * attendance rows, and the honest present-hours sum. Sessions are
     * `vad_session` posts linked to an edition via `_ntdst_edition_id`.
     *
     * @param  array<int> $editionIds
     * @return array<int, array{edition_id:int, title:string, date:string, start_time:string, end_time:string}>
     */
    private function fetchSessionDetailsByEdition(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));
        $params = array_merge([SessionCPT::POST_TYPE], $editionIds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value AS edition_id,
                    d.meta_value AS session_date,
                    st.meta_value AS start_time,
                    et.meta_value AS end_time
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ntdst_edition_id'
             LEFT JOIN {$wpdb->postmeta} d  ON p.ID = d.post_id  AND d.meta_key  = '_ntdst_date'
             LEFT JOIN {$wpdb->postmeta} st ON p.ID = st.post_id AND st.meta_key = '_ntdst_start_time'
             LEFT JOIN {$wpdb->postmeta} et ON p.ID = et.post_id AND et.meta_key = '_ntdst_end_time'
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_value IN ({$placeholders})",
            ...$params,
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->ID] = [
                'edition_id' => (int) $row->edition_id,
                'title' => (string) $row->post_title,
                'date' => (string) ($row->session_date ?? ''),
                'start_time' => (string) ($row->start_time ?? ''),
                'end_time' => (string) ($row->end_time ?? ''),
            ];
        }

        return $map;
    }

    /**
     * The user's per-session attendance statuses across a set of editions.
     *
     * @param  array<int> $editionIds
     * @return array<int, array<int, string>>  edition_id => [session_id => status]
     */
    private function fetchUserSessionAttendance(int $userId, array $editionIds): array
    {
        if (empty($editionIds) || !AttendanceTable::exists()) {
            return [];
        }

        global $wpdb;
        $attendanceTable = AttendanceTable::getTableName();
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));
        $params = array_merge([$userId], $editionIds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, session_id, status
             FROM {$attendanceTable}
             WHERE user_id = %d
               AND edition_id IN ({$placeholders})",
            ...$params,
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->edition_id][(int) $row->session_id] = (string) $row->status;
        }

        return $map;
    }

    /**
     * Sum of the real durations (start→end) of the sessions the user was
     * marked PRESENT at, for one edition. Sessions without both times
     * contribute 0 — hours are never fabricated (the previous convention
     * was a hard-coded 4h per present mark). Rounded to 1 decimal.
     *
     * @param array<int,string> $statusBySession  session_id => status
     * @param array<int,array{edition_id:int,title:string,date:string,start_time:string,end_time:string}> $details
     */
    private function presentHours(int $editionId, array $statusBySession, array $details): float
    {
        $hours = 0.0;
        foreach ($statusBySession as $sessionId => $status) {
            if ($status !== 'present') {
                continue;
            }
            $s = $details[$sessionId] ?? null;
            if (!$s || (int) $s['edition_id'] !== $editionId || $s['start_time'] === '' || $s['end_time'] === '') {
                continue;
            }
            $start = strtotime($s['start_time']);
            $end = strtotime($s['end_time']);
            if ($start !== false && $end !== false && $end > $start) {
                $hours += ($end - $start) / 3600;
            }
        }

        return round($hours, 1);
    }

    /**
     * Per-session attendance rows for one edition: every session of the
     * edition (date-ordered) with the user's mark — so the dossier answers
     * "WHICH day was missed", not just how many.
     *
     * @param array<int,string> $statusBySession  session_id => status
     * @param array<int,array{edition_id:int,title:string,date:string,start_time:string,end_time:string}> $details
     * @return list<array{id:int, title:string, date:string, time:string, status:string}>
     */
    private function buildSessionRows(int $editionId, array $statusBySession, array $details): array
    {
        $rows = [];
        foreach ($details as $sessionId => $s) {
            if ((int) $s['edition_id'] !== $editionId) {
                continue;
            }
            $time = $s['start_time'] !== '' && $s['end_time'] !== ''
                ? substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5)
                : '';
            $rows[] = [
                'id' => $sessionId,
                'title' => $s['title'],
                'date' => $s['date'] !== '' ? date_i18n('d/m/Y', (int) strtotime($s['date'])) : '',
                'time' => $time,
                // '' = not (yet) marked; the template renders a muted dash state.
                'status' => $statusBySession[$sessionId] ?? '',
                '_sort' => $s['date'],
            ];
        }
        usort($rows, static fn(array $a, array $b) => strcmp($a['_sort'], $b['_sort']));

        return array_map(static function (array $r): array {
            unset($r['_sort']);
            return $r;
        }, $rows);
    }

    /**
     * Dutch date for a site-local MySQL datetime ('' for empty/zero dates).
     */
    private function formatLocalDate(string $value): string
    {
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return '';
        }
        $ts = strtotime($value);

        return $ts !== false ? date_i18n('d/m/Y', $ts) : '';
    }

    /**
     * Dutch site-TZ moment for a stored UTC ISO-8601 stamp (gmdate('c')).
     */
    private function formatIsoMoment(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $ts = strtotime($value);

        return $ts !== false ? wp_date('d/m/Y H:i', $ts) : $value;
    }

    /**
     * Display name for a stage actor (stored as a raw user ID). Cached
     * per request; a deleted user or empty value yields ''.
     */
    private function resolveUserName(mixed $userId): string
    {
        $id = (int) (is_scalar($userId) ? $userId : 0);
        if ($id <= 0) {
            // Legacy stages may already carry a name string — pass it through.
            return is_string($userId) && !is_numeric($userId) ? $userId : '';
        }
        if (!isset($this->userNameCache[$id])) {
            $user = get_userdata($id);
            $this->userNameCache[$id] = $user ? $user->display_name : '';
        }

        return $this->userNameCache[$id];
    }

    /**
     * Count sessions per edition for a set of edition IDs.
     *
     * Returns [edition_id => session_count]. Editions with no sessions are absent
     * from the map; callers should treat missing keys as 0 (no sessions ⇒ e-learning).
     *
     * Moved verbatim from AdminAPIController::fetchSessionCountByEdition — its only
     * call sites were inside getUserDetail (S2 hazard analysis).
     *
     * @param array<int> $editionIds
     * @return array<int, int>
     */
    private function fetchSessionCountByEdition(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));
        $params = array_merge([SessionCPT::POST_TYPE], $editionIds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS edition_id, COUNT(*) AS cnt
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_ntdst_edition_id'
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_value IN ({$placeholders})
             GROUP BY pm.meta_value",
            ...$params,
        ));

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->edition_id] = (int) $row->cnt;
        }
        return $map;
    }

    /**
     * Aggregate attendance COUNTS for a user across a set of editions.
     *
     * Returns [edition_id => [present, absent, excused]]. Editions with no
     * recorded attendance are absent. (Hours are computed separately from the
     * real session durations — see presentHours(); the old "present × 4h"
     * convention fabricated hours and is gone.)
     *
     * @param array<int> $editionIds
     * @return array<int, array{present:int, absent:int, excused:int}>
     */
    private function fetchUserAttendanceByEdition(int $userId, array $editionIds): array
    {
        if (empty($editionIds) || !AttendanceTable::exists()) {
            return [];
        }

        global $wpdb;
        $attendanceTable = AttendanceTable::getTableName();
        $placeholders = implode(',', array_fill(0, count($editionIds), '%d'));
        $params = array_merge([$userId], $editionIds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, status, COUNT(*) AS cnt
             FROM {$attendanceTable}
             WHERE user_id = %d
               AND edition_id IN ({$placeholders})
             GROUP BY edition_id, status",
            ...$params,
        ));

        $map = [];
        foreach ($rows as $row) {
            $editionId = (int) $row->edition_id;
            if (!isset($map[$editionId])) {
                $map[$editionId] = ['present' => 0, 'absent' => 0, 'excused' => 0];
            }
            if (isset($map[$editionId][$row->status])) {
                $map[$editionId][$row->status] = (int) $row->cnt;
            }
        }

        return $map;
    }
}
