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

    /**
     * Assemble the user-detail (Dossier) case-view response.
     *
     * Moved verbatim from AdminAPIController::getUserDetail (behavior-preserving).
     */
    public function getUserDetail(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        $userId = (int) $request->get_param('id');
        $regPage = max(1, (int) $request->get_param('reg_page'));
        $regPerPage = 20;

        // Sensitive fields (phone, audit trail, full quote listing) are only
        // returned to stride_manage. stride_view (read-only Supervisor role)
        // gets the safe subset — without this, a Supervisor can dump the
        // entire user base via /admin/users/{id}/detail.
        $canSeeSensitive = current_user_can('stride_manage');

        // --- User data ---
        $userData = get_userdata($userId);
        if (!$userData) {
            return new WP_REST_Response(['error' => 'User not found'], 404);
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
            $regRows = $wpdb->get_results($wpdb->prepare(
                "SELECT r.id, r.edition_id, r.status, r.enrollment_path, r.registered_at,
                        r.completed_at, r.cancelled_at, p.post_title AS edition_title
                 FROM {$registrationTable} r
                 LEFT JOIN {$wpdb->posts} p ON r.edition_id = p.ID
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

            foreach ($regRows as $row) {
                $editionId = (int) $row->edition_id;
                $att = $attendanceByEdition[$editionId] ?? null;
                $totalSessions = $sessionCountByEdition[$editionId] ?? 0;
                $attendanceSummary = null;

                if ($totalSessions > 0) {
                    $present = $att['present'] ?? 0;
                    $absent = $att['absent'] ?? 0;
                    $excused = $att['excused'] ?? 0;
                    $hours = $att['hours'] ?? 0;
                    $attendanceSummary = [
                        'present' => $present,
                        'absent' => $absent,
                        'excused' => $excused,
                        'total_sessions' => $totalSessions,
                        'hours' => $hours,
                    ];
                }

                $registrations[] = [
                    'id' => (int) $row->id,
                    'edition_id' => $editionId,
                    'edition_title' => $row->edition_title ?: __('Onbekend', 'stride'),
                    'status' => $row->status,
                    'enrollment_path' => $row->enrollment_path,
                    'registered_at' => $row->registered_at,
                    'completed_at' => $row->completed_at,
                    'cancelled_at' => $row->cancelled_at,
                    'has_sessions' => $totalSessions > 0,
                    'attendance' => $attendanceSummary,
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
            'sent_at', 'paid_at', 'valid_until',
        ]);

        $quoteEditionIds = array_values(array_unique(array_filter(array_map(
            static fn($id) => (int) ($quoteMeta[$id]['edition_id'] ?? 0),
            $quoteIds,
        ))));
        $quoteEditions = BatchQueryHelper::batchGetPosts($quoteEditionIds, EditionCPT::POST_TYPE);

        $quotes = [];
        foreach ($quotePosts as $quotePost) {
            $quoteId = (int) $quotePost->ID;
            $meta = $quoteMeta[$quoteId] ?? [];

            $quoteEditionId = (int) ($meta['edition_id'] ?? 0);
            $quoteStatus = (string) ($meta['status'] ?? '');
            $statusEnum = QuoteStatus::tryFrom($quoteStatus);

            $quotes[] = [
                'id' => $quoteId,
                'title' => $quotePost->post_title,
                'number' => (string) ($meta['quote_number'] ?? ''),
                'edition_id' => $quoteEditionId,
                'edition_title' => isset($quoteEditions[$quoteEditionId]) ? $quoteEditions[$quoteEditionId]->post_title : '',
                'status' => $quoteStatus,
                'status_label' => $statusEnum?->label() ?? $quoteStatus,
                'total' => Money::cents((int) ($meta['total'] ?? 0))->amount(),
                'created_at' => $quotePost->post_date,
                'sent_at' => ($meta['sent_at'] ?? '') ?: null,
                'paid_at' => ($meta['paid_at'] ?? '') ?: null,
                'valid_until' => ($meta['valid_until'] ?? '') ?: null,
            ];
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
                }
            }

            // Enrich with total session count + hours per edition
            $summaryEditionIds = array_keys($grouped);
            if (!empty($summaryEditionIds)) {
                $sessionCounts = $this->fetchSessionCountByEdition($summaryEditionIds);
                $hoursByEdition = $this->fetchUserAttendanceByEdition($userId, $summaryEditionIds);
                foreach ($grouped as $editionId => &$row) {
                    $row['total_sessions'] = $sessionCounts[$editionId] ?? 0;
                    $row['hours'] = $hoursByEdition[$editionId]['hours'] ?? 0;
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

            $auditTrailTotal = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$auditTable}
                 WHERE actor_id = %d OR (entity_type = 'user' AND entity_id = %d)",
                $userId,
                $userId,
            ));

            $auditEntries = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$auditTable}
                 WHERE actor_id = %d OR (entity_type = 'user' AND entity_id = %d)
                 ORDER BY created_at DESC
                 LIMIT 50",
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
        }

        return new WP_REST_Response([
            'user' => $user,
            'registrations' => $registrations,
            'registrations_total' => $registrationsTotal,
            'quotes' => $canSeeSensitive ? $quotes : [],
            'attendance' => $attendance,
            'audit_trail' => $canSeeSensitive ? $auditTrail : [],
            'audit_trail_total' => $canSeeSensitive ? $auditTrailTotal : 0,
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS (user-detail-only — verified single-consumer at S2)
    // =========================================================================

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
     * Aggregate attendance for a user across a set of editions.
     *
     * Returns [edition_id => [present, absent, excused, hours]]. Hours assumes
     * 4 hours per "present" session (current convention in the user-detail
     * attendance summary). Editions with no recorded attendance are absent.
     *
     * Moved verbatim from AdminAPIController::fetchUserAttendanceByEdition — its only
     * call sites were inside getUserDetail (S2 hazard analysis).
     *
     * @param array<int> $editionIds
     * @return array<int, array{present:int, absent:int, excused:int, hours:int}>
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
                $map[$editionId] = ['present' => 0, 'absent' => 0, 'excused' => 0, 'hours' => 0];
            }
            if (isset($map[$editionId][$row->status])) {
                $map[$editionId][$row->status] = (int) $row->cnt;
            }
        }

        // Hours = present count × 4 (matches existing convention)
        foreach ($map as &$entry) {
            $entry['hours'] = $entry['present'] * 4;
        }
        unset($entry);

        return $map;
    }
}
