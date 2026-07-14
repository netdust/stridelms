<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Domain\RegistrationStatus;
use WP_Error;

/**
 * Repository for registration data access — the ONLY $wpdb caller for
 * wp_vad_registrations (INV-3). Need a query it doesn't expose? Add a method
 * HERE; never reach around it.
 *
 * ═══ READ docs/DATA-MODEL-REGISTRATIONS.md BEFORE ADDING QUERIES. ═══
 * It documents the three row kinds (edition row / trajectory PARENT /
 * cascade child — the edition_id IS NULL signature), the status lifecycle
 * and its enum helpers, the M5 JSON rule, the five lead-identity invariants,
 * the advisory-lock concurrency contract (no UNIQUE key — deliberately), and
 * the applyScopePins scope contract every grid-family read must honor.
 */
final class RegistrationRepository
{
    public const PATH_INDIVIDUAL = 'individual';
    public const PATH_COLLEAGUE = 'colleague';
    public const PATH_TRAJECTORY = 'trajectory';
    public const PATH_PARTNER = 'partner';

    /**
     * Dutch display label for an enrollment_path value — the SINGLE label
     * source (dossier read-model + exporters), next to the slugs it names.
     */
    public static function pathLabel(string $path): string
    {
        return match ($path) {
            self::PATH_INDIVIDUAL => __('Individueel', 'stride'),
            self::PATH_COLLEAGUE  => __('Via collega', 'stride'),
            self::PATH_TRAJECTORY => __('Via traject', 'stride'),
            self::PATH_PARTNER    => __('Via partner', 'stride'),
            default => $path !== '' ? $path : '—',
        };
    }

    /**
     * Allowlisted values for the group_by filter in admin grid queries (M4).
     * Shared between queryForGrid, queryForGridGrouped, and service-layer guards.
     * NEVER add user-supplied values here.
     */
    public const GROUP_BY_ALLOWLIST = ['edition_id', 'status', 'company_id'];

    /**
     * Max composed child rows returned PER GROUP by queryForGridGrouped (the
     * accordion body). The cap bounds the grouped response; the offerte tally is
     * computed separately in SQL (offerteVerdelingByGroup, FIX-10) so it is never
     * desynced from the aggregate count without materialising ids. The "toon alle
     * N" affordance surfaces the full set via the flat, paginated grid.
     */
    public const GROUP_ROW_CAP = 8;

    /** @var array<string, array<object>> Per-request cache for findByUser results */
    private array $findByUserCache = [];

    /**
     * Stage keys (6) hold wrapped questionnaire payloads.
     */
    private const STAGE_KEYS = [
        'interest', 'waitlist', 'enrollment_personal',
        'enrollment_billing', 'intake', 'evaluation',
    ];

    /**
     * Allowlist of top-level keys inside `enrollment_data`.
     *
     * Stage keys + `initial_selection` (append-only phase log of user selections).
     */
    private const ALLOWED_ROOT_KEYS = [
        'interest', 'waitlist', 'enrollment_personal',
        'enrollment_billing', 'intake', 'evaluation',
        'initial_selection',
    ];

    /**
     * Wrap form payload in the canonical stage envelope.
     *
     * Every stage entry inside `enrollment_data` follows this shape:
     * `{ submitted_at, submitted_by, data }`.
     *
     * @param array<string, mixed> $data        Form payload (questionnaire answers etc.)
     * @param int|null             $submittedBy Actor WP user ID. `null` for
     *                              anonymous (interest/waitlist pre-account).
     *                              Defaults to `get_current_user_id() ?: null`.
     *                              Pass an explicit ID to override (e.g. colleague
     *                              enrolment — actor is the enroller, not the
     *                              participant).
     * @param string|null          $submittedAt ISO-8601 UTC. Defaults to `gmdate('c')`.
     * @return array{submitted_at: string, submitted_by: int|null, data: array<string, mixed>}
     */
    /**
     * Extract the anonymous submitter's identity from an enrollment_data array.
     *
     * THE single definition of where a lead's name/email live (F-G3):
     * enrollment_data[interest|waitlist]['data']['name'|'email']. Consumed by
     * the write paths (create/reactivate/update stamp the denormalized
     * lead_name/lead_email columns), the v5 migration backfill, and any read
     * that would otherwise re-derive the JSON path. Empty strings when absent.
     *
     * @param  array<string,mixed> $enrollmentData Decoded enrollment_data.
     * @return array{name:string, email:string}
     */
    public static function extractLeadIdentity(array $enrollmentData): array
    {
        foreach (['interest', 'waitlist'] as $stage) {
            $data = $enrollmentData[$stage]['data'] ?? null;
            if (is_array($data) && (!empty($data['name']) || !empty($data['email']))) {
                return [
                    'name' => mb_substr(sanitize_text_field((string) ($data['name'] ?? '')), 0, 191),
                    'email' => mb_substr(sanitize_email((string) ($data['email'] ?? '')), 0, 191),
                ];
            }
        }

        return ['name' => '', 'email' => ''];
    }

    /**
     * Present a row's denormalized lead identity for an admin surface — THE
     * single home of the '(anoniem)' fallback rule. Both admin readers (the
     * grid's AdminRegistrationQueryService::resolveAnonymousIdentity and the
     * edition roster in AdminAPIController) call this, so the same anonymous
     * row can never render two different identities across surfaces (INV-3).
     *
     * @param  object $row Row carrying ->lead_name / ->lead_email (v5 columns).
     * @return array{name:string, email:string}
     */
    public static function presentLeadIdentity(object $row): array
    {
        $name  = (string) ($row->lead_name ?? '');
        $email = (string) ($row->lead_email ?? '');

        return [
            'name'  => $name !== '' ? $name : __('(anoniem)', 'stride'),
            'email' => $email,
        ];
    }

    public static function wrapStage(array $data, ?int $submittedBy = null, ?string $submittedAt = null): array
    {
        if ($submittedBy === null) {
            $current = function_exists('get_current_user_id') ? get_current_user_id() : 0;
            $submittedBy = $current > 0 ? $current : null;
        }

        return [
            'submitted_at' => $submittedAt ?? gmdate('c'),
            'submitted_by' => $submittedBy,
            'data' => $data,
        ];
    }

    /**
     * Normalize an `enrollment_data` array against the canonical shape.
     *
     * - Drops unknown root-level keys (logs each drop as a warning).
     * - Enforces the 3-key `{ submitted_at, submitted_by, data }` envelope on each
     *   stage, filling missing meta with defaults and dropping unknown inner keys.
     * - Passes `initial_selection` through structurally; deep validation lives in
     *   `appendInitialSelectionPhase()` which is the only writer.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalizeEnrollmentData(array $data): array
    {
        $normalized = [];
        $logger = function_exists('ntdst_log') ? ntdst_log('enrollment') : null;

        foreach ($data as $key => $value) {
            if (!in_array($key, self::ALLOWED_ROOT_KEYS, true)) {
                if ($logger) {
                    $logger->warning('enrollment_data: dropped unknown root key', ['key' => $key]);
                }
                continue;
            }

            if ($key === 'initial_selection') {
                if (is_array($value)) {
                    $normalized[$key] = $value;
                }
                continue;
            }

            // Stage key — must be wrapped.
            if (!is_array($value)) {
                if ($logger) {
                    $logger->warning('enrollment_data: dropped non-array stage value', ['stage' => $key]);
                }
                continue;
            }

            $stageData = isset($value['data']) && is_array($value['data']) ? $value['data'] : [];
            $submittedAt = isset($value['submitted_at']) && is_string($value['submitted_at']) && $value['submitted_at'] !== ''
                ? $value['submitted_at']
                : null;
            $submittedBy = array_key_exists('submitted_by', $value) ? $value['submitted_by'] : null;
            $submittedBy = is_int($submittedBy) ? $submittedBy : null;

            if ($submittedAt === null && $logger) {
                $logger->warning('enrollment_data: stage missing submitted_at, defaulting', ['stage' => $key]);
            }

            $normalized[$key] = [
                'submitted_at' => $submittedAt ?? gmdate('c'),
                'submitted_by' => $submittedBy,
                'data' => $stageData,
            ];

            // Log unknown inner keys (everything beyond the 3-key envelope is dropped).
            $extraKeys = array_diff(array_keys($value), ['submitted_at', 'submitted_by', 'data']);
            if (!empty($extraKeys) && $logger) {
                $logger->warning('enrollment_data: dropped unknown inner keys', [
                    'stage' => $key,
                    'keys' => array_values($extraKeys),
                ]);
            }
        }

        return $normalized;
    }

    /**
     * Append a phase entry to `enrollment_data.initial_selection.phases[]`.
     *
     * Append-only: existing entries are never mutated. The first call initializes
     * the `initial_selection` structure with the given `$type`; subsequent calls
     * ignore the `$type` argument (the type is set once at creation).
     *
     * `captured_at` and `captured_by` are enriched if not already present on
     * `$phase`. Caller can override `captured_by` to record an actor distinct
     * from the registration's `user_id` (e.g. colleague enrolment).
     *
     * @param int                  $registrationId
     * @param array<string, mixed> $phase Required: `phase` (string). Optional:
     *                              `session_ids` or `edition_ids` (int[]),
     *                              `captured_at` (ISO-8601), `captured_by` (int|null).
     * @param string               $type One of: 'edition', 'trajectory', 'none'.
     */
    public function appendInitialSelectionPhase(int $registrationId, array $phase, string $type): bool
    {
        $row = $this->find($registrationId);
        if (!$row) {
            if (function_exists('ntdst_log')) {
                ntdst_log('enrollment')->warning('appendInitialSelectionPhase: row not found', [
                    'registration_id' => $registrationId,
                ]);
            }
            return false;
        }

        $data = is_array($row->enrollment_data ?? null) ? $row->enrollment_data : [];

        if (!isset($data['initial_selection']) || !is_array($data['initial_selection'])) {
            $data['initial_selection'] = [
                'type'   => $type,
                'phases' => [],
            ];
        }

        if (!array_key_exists('captured_at', $phase)) {
            $phase['captured_at'] = gmdate('c');
        }
        if (!array_key_exists('captured_by', $phase)) {
            $current = function_exists('get_current_user_id') ? get_current_user_id() : 0;
            $phase['captured_by'] = $current > 0 ? $current : null;
        }

        $data['initial_selection']['phases'][] = $phase;

        return $this->update($registrationId, ['enrollment_data' => $data]);
    }

    private function table(): string
    {
        return RegistrationTable::getTableName();
    }

    // === Create ===

    /**
     * Create a new registration.
     *
     * @param array<string, mixed> $data
     * @return int|WP_Error Registration ID or error
     */
    public function create(array $data): int|WP_Error
    {
        global $wpdb;

        // Must have at least edition_id or trajectory_id
        if (empty($data['edition_id']) && empty($data['trajectory_id'])) {
            return new WP_Error('missing_field', 'Required: edition_id or trajectory_id');
        }

        $status = $data['status'] ?? 'confirmed';
        $anonymousAllowedStatuses = [
            RegistrationStatus::Interest->value,
            RegistrationStatus::Waitlist->value,
        ];
        if (empty($data['user_id']) && !in_array($status, $anonymousAllowedStatuses, true)) {
            return new WP_Error('missing_field', 'Required: user_id (except for interest/waitlist registrations)');
        }

        // Check for duplicate
        $editionId = isset($data['edition_id']) ? absint($data['edition_id']) : null;
        $trajectoryId = isset($data['trajectory_id']) ? absint($data['trajectory_id']) : null;

        // DATA-2 / mitigation 1: serialize the (user,edition) check-and-insert
        // under a MySQL advisory lock so two concurrent create() calls for the
        // same tuple can't both pass the findByUserAndEdition read before either
        // writes. Only the edition path takes the lock — the finding is
        // edition-scoped, and the trajectory/anonymous-interest paths key on
        // different predicates. The lock is released on EVERY exit below.
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        $lockHeld = false;
        if ($userId && $editionId) {
            if (!$this->acquireEnrollLock($userId, $editionId)) {
                return new WP_Error(
                    'lock_timeout',
                    'Kon de inschrijving niet vergrendelen, probeer het opnieuw.',
                );
            }
            $lockHeld = true;
        }

        // try/finally guarantees the advisory lock is released on EVERY exit
        // path below (reactivate return, both duplicate WP_Errors, the two
        // db_error returns, the success return, and any thrown exception).
        try {
            // Check for existing registration (unique constraint on user+edition)
            // Skip duplicate check for anonymous interest registrations (no user_id)
            $existing = null;
            if ($userId && $editionId) {
                $existing = $this->findByUserAndEdition($userId, $editionId);
            } elseif ($userId && $trajectoryId) {
                $existing = $this->findByUserAndTrajectory($userId, $trajectoryId);
            }

            if ($existing) {
                $existingStatus = RegistrationStatus::tryFrom($existing->status);

                // Reactivate-eligible statuses (RegistrationStatus::isReactivatable):
                // - Cancelled: terminal-cancel state, re-enrolling reopens the row.
                // - Interest / Waitlist: pre-enrollment holding states, the user already
                //   expressed intent — enrolling promotes that row instead of blocking.
                // For LEAD interest/waitlist rows, EnrollmentService::enroll() has a
                // separate upgrade path (upgradeFromInterest) that runs BEFORE this
                // method, so we only land here when the existing row already belongs
                // to this user.
                if ($existingStatus && $existingStatus->isReactivatable()) {
                    // Preserve existing enrollment_data (interest/waitlist stage payloads
                    // collected earlier) unless the caller passes new data to merge.
                    $existingData = is_string($existing->enrollment_data ?? null) && $existing->enrollment_data !== ''
                        ? (json_decode($existing->enrollment_data, true) ?: [])
                        : (is_array($existing->enrollment_data ?? null) ? $existing->enrollment_data : []);
                    $newData = is_array($data['enrollment_data'] ?? null) ? $data['enrollment_data'] : [];
                    $mergedData = self::normalizeEnrollmentData(array_merge($existingData, $newData));

                    $reactivate = [
                        'status' => $data['status'] ?? RegistrationStatus::Confirmed->value,
                        'enrollment_path' => $data['enrollment_path'] ?? ($existing->enrollment_path ?? 'individual'),
                        'registered_at' => current_time('mysql'),
                        'cancelled_at' => null,
                        'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
                        'enrollment_data' => $mergedData ? wp_json_encode($mergedData) : null,
                        'quote_id' => isset($data['quote_id']) ? absint($data['quote_id']) : null,
                        'selections' => isset($data['selections']) ? wp_json_encode($data['selections']) : null,
                        'completion_tasks' => null,
                        'completed_at' => null,
                    ];

                    // Partner scoping parity on reactivation: a caller-computed
                    // company_id (enroll(), the public form upsert) must not be
                    // silently dropped — but never overwrite a company already
                    // on the row (admin-set scope wins, same COALESCE semantics
                    // as bindLeadToUser).
                    if (!empty($data['company_id']) && empty($existing->company_id)) {
                        $reactivate['company_id'] = absint($data['company_id']);
                    }

                    // Re-stamp the lead identity when the reactivated row is
                    // still anonymous (F-G3). UNCONDITIONAL: the columns must
                    // mirror the rewritten JSON exactly — an identity scrubbed
                    // from enrollment_data (GDPR/privacy cleanup) must clear
                    // the denormalized copy too, never outlive its source.
                    if (empty($existing->user_id) && $mergedData) {
                        $identity = self::extractLeadIdentity($mergedData);
                        $reactivate['lead_name'] = $identity['name'];
                        $reactivate['lead_email'] = $identity['email'];
                    }

                    $result = $wpdb->update($this->table(), $reactivate, ['id' => (int) $existing->id]);

                    if ($result === false) {
                        return new WP_Error('db_error', 'Failed to reactivate registration');
                    }

                    $this->clearCache();

                    $this->emitRowEvent('row_updated', (int) $existing->id, $reactivate, 'reactivate');

                    return (int) $existing->id;
                }

                // Active registration exists — block duplicate
                if ($editionId) {
                    return new WP_Error('duplicate', 'User already registered for this edition');
                }
                return new WP_Error('duplicate', 'User already enrolled in this trajectory');
            }

            $insert = [
                'user_id' => isset($data['user_id']) ? absint($data['user_id']) : null,
                'edition_id' => $editionId,
                'trajectory_id' => $trajectoryId,
                'parent_registration_id' => isset($data['parent_registration_id']) ? absint($data['parent_registration_id']) : null,
                'company_id' => isset($data['company_id']) ? absint($data['company_id']) : null,
                'status' => $data['status'] ?? RegistrationStatus::Confirmed->value,
                'enrollment_path' => $data['enrollment_path'] ?? self::PATH_INDIVIDUAL,
                'selections' => isset($data['selections']) ? wp_json_encode($data['selections']) : null,
                'quote_id' => isset($data['quote_id']) ? absint($data['quote_id']) : null,
                'enrolled_by' => isset($data['enrolled_by']) ? absint($data['enrolled_by']) : null,
                'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
                'enrollment_data' => isset($data['enrollment_data']) && is_array($data['enrollment_data'])
                    ? wp_json_encode(self::normalizeEnrollmentData($data['enrollment_data']))
                    : null,
            ];

            // Anonymous lead (no account): stamp the denormalized searchable
            // identity columns from the submission payload (F-G3 — the JSON is
            // deliberately never LIKEd, so without these columns a lead is
            // unfindable by the name on their own form).
            if (empty($insert['user_id']) && is_array($data['enrollment_data'] ?? null)) {
                $identity = self::extractLeadIdentity($data['enrollment_data']);
                $insert['lead_name'] = $identity['name'];
                $insert['lead_email'] = $identity['email'];
            }

            $result = $wpdb->insert($this->table(), $insert);

            if ($result === false) {
                return new WP_Error('db_error', 'Failed to create registration');
            }

            $this->clearCache();

            $registrationId = (int) $wpdb->insert_id;

            $this->emitRowEvent('row_created', $registrationId, $insert, 'create');

            return $registrationId;
        } finally {
            if ($lockHeld) {
                $this->releaseEnrollLock($userId, $editionId);
            }
        }
    }

    /**
     * Emit the row-level write event.
     *
     * Every write to the registrations table announces itself here, so
     * cross-cutting consumers (audit, mail, notifications) can hook ANY
     * write path — not only the EnrollmentService lifecycle, which keeps
     * its richer semantic events (`stride/registration/created`,
     * `interest_registered`, …) at the service layer.
     *
     * @param string $event   'row_created' or 'row_updated'
     * @param int    $id      Registration id
     * @param array  $changed Column => value as written
     * @param string $context Write site: 'create', 'reactivate', 'update',
     *                        'upgrade_from_interest', 'cancel_children',
     *                        'set_selections', 'lock_selections',
     *                        'update_completion_tasks'
     */
    private function emitRowEvent(string $event, int $id, array $changed, string $context): void
    {
        do_action('stride/registration/' . $event, $id, $changed, $context);
    }

    /**
     * Per-registration advisory lock for selection writes (MySQL GET_LOCK).
     *
     * Serializes read-modify-write sequences on a registration's selections /
     * enrollment_data so interleaved submissions can't diff against the same
     * pre-state (trajectory choices grant/revoke race, shake-out 2026-06-12).
     */
    public function acquireSelectionLock(int $registrationId, int $timeoutSeconds = 5): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $this->selectionLockName($registrationId),
            $timeoutSeconds,
        )) === 1;
    }

    public function releaseSelectionLock(int $registrationId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)',
            $this->selectionLockName($registrationId),
        ));
    }

    private function selectionLockName(int $registrationId): string
    {
        global $wpdb;

        // Prefix with the table prefix so parallel test/staging DBs on one
        // MySQL server don't contend on the same lock namespace.
        return $wpdb->prefix . 'vad_reg_selections_' . $registrationId;
    }

    /**
     * Per-(user,edition) advisory lock for the enrollment check-and-insert
     * (MySQL GET_LOCK). DATA-2 / mitigation 1.
     *
     * Serializes the duplicate-check-then-insert in create() so two concurrent
     * enrolls for the same (user_id, edition_id) can't both pass the
     * findByUserAndEdition read before either inserts → two confirmed rows +
     * double grantAccess + double capacity count. The lock name is scoped to
     * the tuple so unrelated enrollments never serialize.
     *
     * A plain UNIQUE key on (user_id, edition_id) was tried and DROPPED in
     * June 2026 (gotcha_bad_unique_user_edition_constraint): it broke
     * re-enrollment (Cancelled → re-enroll reactivates the SAME row) and
     * trajectory cascade children (parent_registration_id IS NOT NULL rows
     * share a user+edition shape). This advisory lock is the correct primitive.
     */
    public function acquireEnrollLock(int $userId, int $editionId, int $timeoutSeconds = 5): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $this->enrollLockName($userId, $editionId),
            $timeoutSeconds,
        )) === 1;
    }

    public function releaseEnrollLock(int $userId, int $editionId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)',
            $this->enrollLockName($userId, $editionId),
        ));
    }

    private function enrollLockName(int $userId, int $editionId): string
    {
        global $wpdb;

        // Prefix with the table prefix so parallel test/staging DBs on one
        // MySQL server don't contend on the same lock namespace.
        return $wpdb->prefix . 'stride_reg_' . $userId . '_' . $editionId;
    }

    // === Find by ID ===

    /**
     * Find registration by ID.
     */
    public function find(int $id): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d",
            $id,
        ));

        if ($row && $row->selections) {
            $row->selections = json_decode($row->selections, true);
        }

        if ($row && $row->completion_tasks) {
            $row->completion_tasks = json_decode($row->completion_tasks, true);
        }

        if ($row && isset($row->enrollment_data) && $row->enrollment_data) {
            $row->enrollment_data = json_decode($row->enrollment_data, true);
        }

        return $row;
    }

    // === Edition queries ===

    /**
     * Find registration by user and edition.
     *
     * The table has NO unique key on (user_id, edition_id) — duplicates are
     * reachable via raw $wpdb writes, v3 data ports, or the racy app-level
     * duplicate check. The ORDER BY makes the picked row DETERMINISTIC and
     * aligned with every call site's intent (CR-G4): the row representing
     * the user's CURRENT relationship wins — confirmed first (matching the
     * batch contract in EnrollmentService::getEnrolledEditionIds(), which
     * treats ANY confirmed row as enrolled), then the other active states,
     * cancelled last; latest row breaks ties.
     */
    public function findByUserAndEdition(int $userId, int $editionId): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE user_id = %d AND edition_id = %d
             ORDER BY FIELD(status, %s, %s, %s, %s, %s, %s) DESC, id DESC
             LIMIT 1",
            $userId,
            $editionId,
            // Lowest priority first — FIELD() DESC puts the last arg on top.
            RegistrationStatus::Cancelled->value,
            RegistrationStatus::Waitlist->value,
            RegistrationStatus::Interest->value,
            RegistrationStatus::Pending->value,
            RegistrationStatus::Completed->value,
            RegistrationStatus::Confirmed->value,
        ));

        if ($row && $row->selections) {
            $row->selections = json_decode($row->selections, true);
        }

        if ($row && $row->completion_tasks) {
            $row->completion_tasks = json_decode($row->completion_tasks, true);
        }

        if ($row && isset($row->enrollment_data) && $row->enrollment_data) {
            $row->enrollment_data = json_decode($row->enrollment_data, true);
        }

        return $row;
    }

    /**
     * Most recent live registration this actor created for SOMEONE ELSE on
     * this edition (colleague enrollment). Rule 4 of the form-identity plan:
     * the enrolled_by actor completes the non-personal tasks for the person
     * they enrolled — the completion route resolves through here when the
     * actor has no eligible registration of their own.
     *
     * Excludes the actor's own rows (owner path is findByUserAndEdition) and
     * non-actionable statuses; most recent first because the enroll flow
     * redirects here immediately after enrolling one colleague.
     */
    public function findByEditionAndEnroller(int $enrollerId, int $editionId): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE edition_id = %d
               AND enrolled_by = %d
               AND user_id != %d
               AND status IN (%s, %s)
             ORDER BY id DESC
             LIMIT 1",
            $editionId,
            $enrollerId,
            $enrollerId,
            RegistrationStatus::Pending->value,
            RegistrationStatus::Confirmed->value,
        ));

        if ($row && $row->selections) {
            $row->selections = json_decode($row->selections, true);
        }
        if ($row && $row->completion_tasks) {
            $row->completion_tasks = json_decode($row->completion_tasks, true);
        }
        if ($row && isset($row->enrollment_data) && $row->enrollment_data) {
            $row->enrollment_data = json_decode($row->enrollment_data, true);
        }

        return $row;
    }

    /**
     * Find an interest registration by email and edition.
     *
     * Searches enrollment_data JSON for $.interest.data.email match.
     */
    public function findByEmailAndEdition(string $email, int $editionId): ?object
    {
        return $this->findByEmailAndEditionForStage($email, $editionId, RegistrationStatus::Interest);
    }

    /**
     * Find any anonymous row for this email + edition across interest + waitlist stages.
     *
     * Used to upsert when a user submits interest now and waitlist later (or vice
     * versa) for the same edition — we want a single row, not two, since both stages
     * are pre-enrollment intent on the same offering.
     */
    public function findAnonymousForEmailAndEdition(string $email, int $editionId): ?object
    {
        global $wpdb;
        $table = $this->table();

        // Match on the denormalized lead_email COLUMN, not the JSON: the JSON
        // type forces utf8mb4_bin (MariaDB alias AND MySQL JSON_UNQUOTE), so a
        // JSON_EXTRACT comparison is case-SENSITIVE — the only case-sensitive
        // e-mail predicate in the system, while self-bind (strcasecmp), the
        // grid search and batchGetUsersByEmail all match case-insensitively.
        // A case-differing resubmission then missed its earlier lead row and
        // minted a second one. lead_email is *_ci VARCHAR, stamped on every
        // account-less write + the v5 backfill (invariant 3).
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE edition_id = %d
             AND (user_id IS NULL OR user_id = 0)
             AND status IN (%s, %s)
             AND lead_email = %s
             LIMIT 1",
            $editionId,
            RegistrationStatus::Interest->value,
            RegistrationStatus::Waitlist->value,
            $email,
        ));

        // Decode JSON columns like every other finder — callers merge new
        // stage envelopes into enrollment_data and a raw string here made
        // that merge silently start from [] (dropping the earlier stage).
        if ($row && isset($row->enrollment_data) && $row->enrollment_data) {
            $row->enrollment_data = json_decode($row->enrollment_data, true);
        }

        return $row;
    }

    /**
     * Find a registration by email and edition for a given status/stage.
     *
     * Looks for the email inside enrollment_data.{stage}.data.email, where stage
     * matches the status value (e.g. 'interest' or 'waitlist').
     */
    public function findByEmailAndEditionForStage(string $email, int $editionId, RegistrationStatus $status): ?object
    {
        global $wpdb;

        $table = $this->table();
        $jsonPath = '$.' . $status->value . '.data.email';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE edition_id = %d
             AND status = %s
             AND JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, %s)) = %s
             LIMIT 1",
            $editionId,
            $status->value,
            $jsonPath,
            $email,
        ));
    }

    /**
     * Upgrade a LEAD pre-enrollment row (interest or waitlist) to a full
     * enrollment — the enroll-time sibling of bindLeadToUser() with the same
     * bind contract: guarded on the row still being account-less, clears the
     * denormalized lead columns (once bound, identity lives on the account —
     * lead-identity invariant), and stamps the partner affiliation in the
     * same statement (COALESCE keeps a pre-existing non-zero company_id).
     * Additionally flips status/path and resets registered_at, because
     * enrolling IS the status change.
     *
     * Returns false on 0 affected rows too (concurrent bind won the row) —
     * the caller must fall back to its normal duplicate-check path, never
     * assume the upgrade happened.
     *
     * @param array<string, mixed> $enrollmentData Merged enrollment_data to store
     */
    public function upgradeFromInterest(int $registrationId, int $userId, string $status, string $enrollmentPath, array $enrollmentData, ?int $companyId = null): bool
    {
        global $wpdb;

        $companySql = $companyId
            ? $wpdb->prepare(', company_id = COALESCE(NULLIF(company_id, 0), %d)', $companyId)
            : '';

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()}
             SET user_id = %d, status = %s, enrollment_path = %s,
                 enrollment_data = %s, registered_at = %s,
                 lead_name = '', lead_email = ''{$companySql}
             WHERE id = %d AND (user_id IS NULL OR user_id = 0)",
            $userId,
            $status,
            $enrollmentPath,
            wp_json_encode(self::normalizeEnrollmentData($enrollmentData)),
            current_time('mysql'),
            $registrationId,
        ));

        $this->clearCache();

        if ($result !== false && $result > 0) {
            $this->emitRowEvent('row_updated', $registrationId, [
                'user_id' => $userId,
                'status'  => $status,
            ], 'upgrade_from_interest');
            return true;
        }

        return false;
    }

    /**
     * Check if user is registered for edition.
     */
    public function existsForEdition(int $userId, int $editionId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table()} WHERE user_id = %d AND edition_id = %d LIMIT 1",
            $userId,
            $editionId,
        ));
    }

    /**
     * Get all registrations for an edition.
     *
     * @return array<object>
     */
    public function findByEdition(int $editionId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE edition_id = %d";
        $params = [$editionId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get all registrations for an edition restricted to a set of statuses.
     *
     * Full-row (`SELECT *`) sibling of findByEdition — keeps enrollment_data and
     * selections on each row (unlike the structured-column-only
     * findByEditionsAndStatuses, which intentionally omits them). Used by the
     * cohort roster, which scopes to {confirmed, completed} (CR-1) yet still
     * needs enrollment_data for extras and the reg id for selections.
     *
     * @param  array<string> $statuses  Status values to include.
     * @return array<object>
     */
    public function findByEditionWithStatuses(int $editionId, array $statuses): array
    {
        if (empty($statuses)) {
            return [];
        }

        global $wpdb;

        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = "SELECT * FROM {$this->table()}
                WHERE edition_id = %d
                  AND status IN ({$statusPlaceholders})
                ORDER BY registered_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, $editionId, ...array_values($statuses)));
    }

    /**
     * Confirmed registrations for UPCOMING (or dateless) editions, for the CSV export.
     *
     * Verbatim relocation of the reg-side SELECT from
     * AdminAPIController::exportRegistrations (Task D3, INV-3): the controller now
     * owns only the CSV streaming; this repo owns the $wpdb->prepare execution and
     * AdminExportService owns the read-model assembly.
     *
     * Columns are enumerated (panel perf SF-1): r.* dragged the completion_tasks +
     * enrollment_data JSON blobs into memory per row while the CSV reads five
     * scalars. The `_ntdst_start_date` literal stays inline — this is raw SQL in
     * the repository, the sanctioned home for the meta-prefix literal (no
     * getMetaPrefix() helper exists on this custom-table repo).
     *
     * @param  string $today  Today's date (Y-m-d); rows whose edition start_date is
     *                        on/after this, OR which have no start_date, are returned.
     * @return array<object>  Rows: {id, user_id, status, edition_title, edition_date}.
     */
    public function findForExport(string $today): array
    {
        global $wpdb;
        $table = $this->table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.user_id, r.status,
                    p.post_title as edition_title,
                    pm_date.meta_value as edition_date
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON r.edition_id = p.ID
             LEFT JOIN {$wpdb->postmeta} pm_date ON r.edition_id = pm_date.post_id AND pm_date.meta_key = '_ntdst_start_date'
             WHERE r.status = 'confirmed'
             AND (pm_date.meta_value >= %s OR pm_date.meta_value IS NULL)
             ORDER BY pm_date.meta_value ASC, r.registered_at ASC",
            $today,
        ));
    }

    /**
     * Count confirmed registrations for edition.
     */
    public function countConfirmedForEdition(int $editionId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE edition_id = %d AND status = 'confirmed'",
            $editionId,
        ));
    }

    /**
     * Count confirmed registrations with row-level lock (FOR UPDATE).
     * Must be called within a transaction.
     */
    public function countConfirmedForUpdate(int $editionId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE edition_id = %d AND status = 'confirmed' FOR UPDATE",
            $editionId,
        ));
    }

    // === Trajectory queries ===

    /**
     * Find trajectory enrollment (no edition_id).
     */
    public function findByUserAndTrajectory(int $userId, int $trajectoryId): ?object
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND trajectory_id = %d AND edition_id IS NULL",
            $userId,
            $trajectoryId,
        ));

        if ($row && $row->selections) {
            $row->selections = json_decode($row->selections, true);
        }

        return $row;
    }

    /**
     * Check if user is enrolled in trajectory.
     */
    public function existsForTrajectory(int $userId, int $trajectoryId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$this->table()} WHERE user_id = %d AND trajectory_id = %d AND edition_id IS NULL LIMIT 1",
            $userId,
            $trajectoryId,
        ));
    }

    /**
     * Get all enrollments for a trajectory.
     *
     * @return array<object>
     */
    public function findByTrajectory(int $trajectoryId, ?string $status = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE trajectory_id = %d AND edition_id IS NULL";
        $params = [$trajectoryId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at ASC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get edition registrations linked to a trajectory for a given user.
     *
     * Includes:
     *  - Legacy rows: `trajectory_id = X AND edition_id IS NOT NULL` (the
     *    pre-cascade shape; some older rows may still match).
     *  - Cascade children: rows whose `parent_registration_id` points at the
     *    user's trajectory-parent row for this trajectory (the post-cascade
     *    authoritative shape).
     *
     * After cascade ships, child rows are the source of truth for
     * "is this user actually enrolled in this edition?"; the trajectory's
     * `selections` JSON becomes the historical "what did they pick" record.
     *
     * @return array<object>
     */
    public function findEditionsByTrajectory(int $userId, int $trajectoryId): array
    {
        global $wpdb;
        $table = $this->table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT child.* FROM {$table} child
             LEFT JOIN {$table} parent
                ON parent.id = child.parent_registration_id
                AND parent.user_id = %d
                AND parent.trajectory_id = %d
                AND parent.edition_id IS NULL
             WHERE child.user_id = %d
               AND child.edition_id IS NOT NULL
               AND (
                    child.trajectory_id = %d
                    OR parent.id IS NOT NULL
               )
             ORDER BY child.registered_at ASC",
            $userId,
            $trajectoryId,
            $userId,
            $trajectoryId,
        ));
    }

    /**
     * All child registration ids for a trajectory, spanning ALL users
     * (the MULTI-USER, trajectory-scoped child set).
     *
     * This is the reusable extraction of the inline trajectory parent->child join
     * in buildGridFilters() (the queryForGrid trajectory grid-filter, Phase-1B
     * Task 1.4b). // mirror of the buildGridFilters() trajectory JOIN — the two
     * sites MUST stay equivalent (§676 sibling-audit). The inline copy keeps a
     * load-bearing array_unshift param-ordering quirk (the JOIN's %d precedes the
     * active-scope WHERE %s), so it is intentionally NOT refactored to call this
     * method — see Task 2a.8 sibling note (prefer safety over DRY; the Phase-1B
     * grid trajectory-filter tests stay green untouched). Here we own the whole
     * SQL string, so params are bound in natural source order.
     *
     * Unlike findEditionsByTrajectory(int $userId, int $trajectoryId) — which is
     * per-USER (`WHERE child.user_id = %d`) and therefore CANNOT serve a multi-user
     * bulk scope (the B2 security finding) — this method drops the user constraint:
     * the trajectory roster spans all users. It is the scope set for the
     * trajectory-roster bulk's CM-1 per-row authorization (Task 2a.8).
     *
     * Catches BOTH:
     *  - cascade children: child.parent_registration_id points at a T parent row,
     *  - legacy pre-cascade children: child.trajectory_id = T directly.
     * The base `child.edition_id IS NOT NULL` keeps the trajectory PARENT itself
     * out (parents carry edition_id NULL) and another trajectory's rows out — so
     * this returns ONLY this trajectory's edition-grained child rows.
     *
     * @return array<int> child registration ids (ints), spanning all users.
     */
    public function findChildRegistrationIdsByTrajectory(int $trajectoryId): array
    {
        global $wpdb;
        $table = $this->table();

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT child.id FROM {$table} child
             LEFT JOIN {$table} traj_parent
                ON traj_parent.id = child.parent_registration_id
                AND traj_parent.trajectory_id = %d
                AND traj_parent.edition_id IS NULL
             WHERE child.edition_id IS NOT NULL
               AND (
                    child.trajectory_id = %d
                    OR traj_parent.id IS NOT NULL
               )",
            $trajectoryId,
            $trajectoryId,
        ));

        return array_map('intval', $rows ?? []);
    }

    /**
     * Count enrollments for a trajectory.
     *
     * With no explicit $status, cancelled parents are excluded — the SAME
     * population rule as countByTrajectoryIds, so the CPT list-table column
     * (this method) and the workspace list (the batch method) can never show
     * two different "deelnemers" numbers for one trajectory. An explicit
     * $status (including 'cancelled') counts exactly that status.
     */
    public function countByTrajectory(int $trajectoryId, ?string $status = null): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE trajectory_id = %d AND edition_id IS NULL";
        $params = [$trajectoryId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        } else {
            $sql .= " AND status != %s";
            $params[] = RegistrationStatus::Cancelled->value;
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Batch-count enrollments for multiple trajectories.
     *
     * @param array<int> $trajectoryIds
     * @return array<int, int> Map of trajectory_id => count
     */
    public function countByTrajectoryIds(array $trajectoryIds): array
    {
        if (empty($trajectoryIds)) {
            return [];
        }

        global $wpdb;
        $ids = array_map('intval', $trajectoryIds);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // Cancelled parents are not participants (F-T4: they inflated the
        // Trajecten "deelnemers" count against a roster that reads as live).
        // Same exclusion as findByTrajectoryIds — count and roster stay one
        // population.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT trajectory_id, COUNT(*) AS c FROM {$this->table()}
             WHERE trajectory_id IN ({$placeholders}) AND edition_id IS NULL
               AND status != %s
             GROUP BY trajectory_id",
            ...array_merge($ids, [RegistrationStatus::Cancelled->value]),
        ));

        $out = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $out[(int) $row->trajectory_id] = (int) $row->c;
        }
        return $out;
    }

    /**
     * Batch-count registrations for multiple editions, filtered by status.
     *
     * @param array<int>    $editionIds
     * @param array<string> $statuses   Status values to include (default: live statuses)
     * @return array<int, int> Map of edition_id => count (all input ids present, defaulting to 0)
     */
    public function countByEditions(
        array $editionIds,
        array $statuses = [
            RegistrationStatus::Confirmed->value,
            RegistrationStatus::Completed->value,
            RegistrationStatus::Pending->value,
        ],
    ): array {
        if (empty($editionIds) || empty($statuses)) {
            return array_fill_keys(array_map('intval', $editionIds), 0);
        }

        global $wpdb;
        $ids = array_map('intval', $editionIds);
        $idPlaceholders = implode(',', array_fill(0, count($ids), '%d'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT edition_id, COUNT(*) AS c FROM {$this->table()}
             WHERE edition_id IN ({$idPlaceholders})
               AND status IN ({$statusPlaceholders})
             GROUP BY edition_id",
            ...array_merge($ids, $statuses),
        ));

        $out = array_fill_keys($ids, 0);
        foreach ($rows as $row) {
            $out[(int) $row->edition_id] = (int) $row->c;
        }
        return $out;
    }

    /**
     * Batch-count registrations grouped by status across multiple editions.
     *
     * Returns one entry per status that actually occurs in the set; statuses
     * with zero rows are absent (callers can zero-fill as needed).
     *
     * @param array<int> $editionIds
     * @return array<string, int> Map of status => count
     */
    public function statusBreakdownByEditions(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }

        global $wpdb;
        $ids = array_map('intval', $editionIds);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS c FROM {$this->table()}
             WHERE edition_id IN ({$placeholders})
             GROUP BY status",
            ...$ids,
        ));

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->status] = (int) $row->c;
        }
        return $out;
    }

    /**
     * Batch-fetch the structured worklist columns for registrations on a set of
     * editions, filtered by status. Structured columns only (M5) — never reads
     * enrollment_data/selections/completion_tasks.
     *
     * Feeds the Vandaag worklist queue counts (AdminStatsService): the caller
     * supplies the active-edition ID set (§10 — never a corpus scan) and the
     * statuses it needs, then derives the per-queue counts from the returned
     * rows (waitlist→capacity, completed→cert, interest→age, confirmed→offerte).
     *
     * @param array<int>    $editionIds
     * @param array<string> $statuses    Status values to include.
     * @return array<int,object>  Rows with ->id, ->user_id, ->edition_id,
     *                            ->status, ->registered_at, ->completed_at.
     */
    public function findByEditionsAndStatuses(array $editionIds, array $statuses): array
    {
        if (empty($editionIds) || empty($statuses)) {
            return [];
        }

        global $wpdb;
        $ids                = array_values(array_unique(array_map('intval', $editionIds)));
        $idPlaceholders     = implode(',', array_fill(0, count($ids), '%d'));
        $statusPlaceholders = implode(',', array_fill(0, count($statuses), '%s'));

        // completion_tasks rides along (decoded) for the pending-split
        // readiness rule (WorklistQueueResolver::pendingSplit, decision 7a).
        // Fetched for PENDING rows only — the blob can be KBs per row and no
        // other status reads it; a bare column here dragged + decoded it for
        // every confirmed/completed/interest row of the full-queue resolve.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, edition_id, status, registered_at, completed_at,
                    CASE WHEN status = 'pending' THEN completion_tasks END AS completion_tasks
             FROM {$this->table()}
             WHERE edition_id IN ({$idPlaceholders})
               AND status IN ({$statusPlaceholders})",
            ...array_merge($ids, $statuses),
        ));

        return $this->decodeCompletionTaskRows($rows ?: []);
    }

    /**
     * Batch-find trajectory enrollments grouped by trajectory_id.
     *
     * Returns up to $limitPerTrajectory rows per trajectory, newest first.
     *
     * @param array<int> $trajectoryIds
     * @return array<int, array<object>>
     */
    public function findByTrajectoryIds(array $trajectoryIds, int $limitPerTrajectory = 50): array
    {
        if (empty($trajectoryIds)) {
            return [];
        }

        global $wpdb;
        $ids = array_map('intval', $trajectoryIds);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // lead_name/lead_email ride along for the deleted-account fallback
        // (presentLeadIdentity — one presenter); cancelled parents excluded,
        // matching countByTrajectoryIds (count ≡ roster population, F-T4).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, trajectory_id, user_id, status, registered_at, lead_name, lead_email
             FROM {$this->table()}
             WHERE trajectory_id IN ({$placeholders}) AND edition_id IS NULL
               AND status != %s
             ORDER BY trajectory_id, registered_at DESC",
            ...array_merge($ids, [RegistrationStatus::Cancelled->value]),
        ));

        $grouped = array_fill_keys($ids, []);
        foreach ($rows as $row) {
            $tid = (int) $row->trajectory_id;
            if (count($grouped[$tid]) < $limitPerTrajectory) {
                $grouped[$tid][] = $row;
            }
        }
        return $grouped;
    }

    // === Parent/child queries (trajectory cascade) ===

    /**
     * Find all child registrations of a trajectory parent.
     *
     * Children are edition-level registrations created by the cascade service
     * when a user enrolls in (or makes a selection for) a trajectory. They
     * have `edition_id` set, `trajectory_id` NULL, and `parent_registration_id`
     * pointing at the trajectory parent row.
     *
     * @return array<object>
     */
    public function findByParent(int $parentRegistrationId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE parent_registration_id = %d ORDER BY registered_at ASC",
            $parentRegistrationId,
        ));

        foreach ($rows as $row) {
            if (!empty($row->selections) && is_string($row->selections)) {
                $row->selections = json_decode($row->selections, true);
            }
            if (!empty($row->completion_tasks) && is_string($row->completion_tasks)) {
                $row->completion_tasks = json_decode($row->completion_tasks, true);
            }
            if (!empty($row->enrollment_data) && is_string($row->enrollment_data)) {
                $row->enrollment_data = json_decode($row->enrollment_data, true);
            }
        }

        return $rows;
    }

    /**
     * Batch-find children for many parent registrations in one query.
     *
     * Used by listing endpoints (e.g. PartnerAPI) that need to nest
     * children under their trajectory parents without N+1 lookups.
     *
     * When $companyId is non-null the result is additionally filtered to that
     * company — the partner path passes its resolved company_id so a child row
     * that somehow carries a different company_id (data drift, cascade bug,
     * shared-trajectory edge) is NOT leaked across the tenant boundary. The
     * null default preserves internal/admin callers that legitimately need
     * cross-company children (verified: no internal caller depends on scoping).
     * Threat-model attack #1 — company scoping pushed DOWN into the repository
     * (INV-1), not done inline in the controller.
     *
     * @param array<int> $parentIds
     * @return array<int, array<object>> Map of parent_registration_id => children[]
     */
    public function findByParents(array $parentIds, ?int $companyId = null): array
    {
        if (empty($parentIds)) {
            return [];
        }

        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', $parentIds))));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $args = $ids;
        $companyClause = '';
        if ($companyId !== null) {
            $companyClause = ' AND company_id = %d';
            $args[] = $companyId;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE parent_registration_id IN ({$placeholders}){$companyClause}
             ORDER BY parent_registration_id, registered_at ASC",
            ...$args,
        ));

        $grouped = array_fill_keys($ids, []);
        foreach ($rows as $row) {
            if (!empty($row->selections) && is_string($row->selections)) {
                $row->selections = json_decode($row->selections, true);
            }
            if (!empty($row->completion_tasks) && is_string($row->completion_tasks)) {
                $row->completion_tasks = json_decode($row->completion_tasks, true);
            }
            $grouped[(int) $row->parent_registration_id][] = $row;
        }
        return $grouped;
    }

    /**
     * Bulk-cancel all child registrations of a trajectory parent.
     *
     * Data-only — does NOT fire stride/registration/cancelled. Callers
     * (TrajectoryCascadeService) own lifecycle side-effects: LD revoke,
     * audit, mail, event dispatch. This matches the convention set by
     * `cancel()` above.
     *
     * Idempotent: rows already in `cancelled` status are skipped, so the
     * count returned reflects rows actually transitioned this call.
     *
     * @return int Number of children transitioned to cancelled
     */
    public function cancelChildren(int $parentRegistrationId): int
    {
        global $wpdb;

        // Snapshot the rows the UPDATE below will touch, so the per-row
        // write event can fire for each transitioned child.
        $childIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table()}
             WHERE parent_registration_id = %d
               AND status != %s",
            $parentRegistrationId,
            RegistrationStatus::Cancelled->value,
        )));

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table()}
             SET status = %s, cancelled_at = %s, completion_tasks = NULL
             WHERE parent_registration_id = %d
               AND status != %s",
            RegistrationStatus::Cancelled->value,
            current_time('mysql'),
            $parentRegistrationId,
            RegistrationStatus::Cancelled->value,
        ));

        if ($affected === false) {
            return 0;
        }

        if ($affected > 0) {
            $this->clearCache();
            foreach ($childIds as $childId) {
                $this->emitRowEvent('row_updated', $childId, [
                    'status' => RegistrationStatus::Cancelled->value,
                ], 'cancel_children');
            }
        }

        return (int) $affected;
    }

    // === User queries ===

    /**
     * Get all registrations for a user.
     *
     * @return array<object>
     */
    public function findByUser(int $userId, ?string $status = null): array
    {
        $cacheKey = $userId . ':' . ($status ?? '*');
        if (isset($this->findByUserCache[$cacheKey])) {
            return $this->findByUserCache[$cacheKey];
        }

        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE user_id = %d";
        $params = [$userId];

        if ($status !== null) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY registered_at DESC";

        $results = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        foreach ($results as $row) {
            if (!empty($row->selections) && is_string($row->selections)) {
                $row->selections = json_decode($row->selections, true);
            }
            if (!empty($row->completion_tasks) && is_string($row->completion_tasks)) {
                $row->completion_tasks = json_decode($row->completion_tasks, true);
            }
        }

        $this->findByUserCache[$cacheKey] = $results;

        return $results;
    }

    /**
     * Get user's trajectory enrollments.
     *
     * @return array<object>
     */
    public function findTrajectoryEnrollmentsByUser(int $userId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE user_id = %d AND trajectory_id IS NOT NULL AND edition_id IS NULL AND status != 'cancelled' ORDER BY registered_at DESC",
            $userId,
        ));
    }

    // === Company queries ===

    /**
     * Get enrollments for a company.
     *
     * @param int $companyId Company ID
     * @param array<string, mixed> $filters Optional filters: status, edition_id, user_id, page, per_page
     * @return array{data: array<object>, total: int}
     */
    public function findByCompany(int $companyId, array $filters = []): array
    {
        global $wpdb;

        $status = $filters['status'] ?? null;
        $editionId = isset($filters['edition_id']) ? absint($filters['edition_id']) : null;
        $userId = isset($filters['user_id']) ? absint($filters['user_id']) : null;
        $page = max(1, absint($filters['page'] ?? 1));
        $perPage = min(100, max(1, absint($filters['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;

        // Build WHERE clause. Cascade child rows (those with
        // parent_registration_id set) are excluded by default — they're
        // internal representation, not standalone enrollments from the
        // partner's perspective. Callers that need them (admin tools,
        // audit) pass `include_children=true`.
        $where = ["company_id = %d"];
        $params = [$companyId];

        if (empty($filters['include_children'])) {
            $where[] = 'parent_registration_id IS NULL';
        }

        if ($status !== null) {
            $where[] = "status = %s";
            $params[] = sanitize_text_field($status);
        }

        if ($editionId !== null) {
            $where[] = "edition_id = %d";
            $params[] = $editionId;
        }

        if ($userId !== null) {
            $where[] = "user_id = %d";
            $params[] = $userId;
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countSql = "SELECT COUNT(*) FROM {$this->table()} WHERE {$whereClause}";
        $total = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params));

        // Get data
        $dataSql = "SELECT * FROM {$this->table()} WHERE {$whereClause} ORDER BY registered_at DESC LIMIT %d OFFSET %d";
        $params[] = $perPage;
        $params[] = $offset;

        $data = $wpdb->get_results($wpdb->prepare($dataSql, ...$params));

        foreach ($data as $row) {
            if ($row->selections) {
                $row->selections = json_decode($row->selections, true);
            }
        }

        return ['data' => $data, 'total' => $total];
    }

    // === Selections ===

    /**
     * Set selections (sessions or elective editions).
     *
     * @param array<int> $selections
     */
    public function setSelections(int $registrationId, array $selections): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table(),
            ['selections' => wp_json_encode($selections)],
            ['id' => $registrationId],
        ) !== false;

        if ($result) {
            $this->clearCache();
            $this->emitRowEvent('row_updated', $registrationId, ['selections' => $selections], 'set_selections');
        }

        return $result;
    }

    /**
     * Get selections for a registration.
     *
     * @return array<int>
     */
    public function getSelections(int $registrationId): array
    {
        $registration = $this->find($registrationId);

        if (!$registration) {
            return [];
        }

        return $registration->selections ?? [];
    }

    /**
     * Get selections for many registrations in one query (batched getSelections).
     *
     * The per-row getSelections() calls find() (a SELECT * per id), which is N+1
     * for a roster. This is the same convergence point — the selections column is
     * read and decoded HERE, in the repository, so callers never decode the raw
     * column themselves (INV-6b). Returns [registrationId => array<int>]; ids not
     * found (or with no selection) yield an empty array.
     *
     * @param  array<int> $registrationIds
     * @return array<int, array<int>>
     */
    public function getSelectionsForRegistrations(array $registrationIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $registrationIds))));
        if (empty($ids)) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, selections FROM {$this->table()} WHERE id IN ({$placeholders})",
            ...$ids,
        ));

        // Seed every requested id so callers can index without isset() guards.
        $out = array_fill_keys($ids, []);
        foreach ($rows as $row) {
            $decoded = $row->selections ? json_decode($row->selections, true) : [];
            $out[(int) $row->id] = is_array($decoded)
                ? array_values(array_filter(array_map('intval', $decoded)))
                : [];
        }

        return $out;
    }

    /**
     * Lock selections (prevent further changes).
     */
    public function lockSelections(int $registrationId): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table(),
            ['selections_locked_at' => current_time('mysql')],
            ['id' => $registrationId],
        ) !== false;

        if ($result) {
            // Write path was missing its invalidation (selections_locked_at
            // is part of the rows findByUser caches) — found during Task E1.
            $this->clearCache();
            $this->emitRowEvent('row_updated', $registrationId, ['selections_locked_at' => true], 'lock_selections');
        }

        return $result;
    }

    /**
     * Check if selections are locked.
     */
    public function areSelectionsLocked(int $registrationId): bool
    {
        $registration = $this->find($registrationId);

        return $registration && !empty($registration->selections_locked_at);
    }

    // === Completion tasks ===

    /**
     * Update completion_tasks JSON for a registration.
     */
    public function updateCompletionTasks(int $registrationId, array $tasks): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table(),
            ['completion_tasks' => wp_json_encode($tasks)],
            ['id' => $registrationId],
            ['%s'],
            ['%d'],
        );

        $this->clearCache();

        if ($result !== false) {
            $this->emitRowEvent('row_updated', $registrationId, ['completion_tasks' => $tasks], 'update_completion_tasks');
        }

        return $result !== false;
    }

    /**
     * Work-list for the one-time lead adoption pass (scripts/adopt-leads.php,
     * form-identity plan 2026-07-14): every account-less row that carries a
     * lead e-mail. Minimal columns; the script resolves each e-mail against
     * wp_users and binds matches via bindLeadToUser().
     *
     * @return array<object> {id, edition_id, lead_email} rows.
     */
    public function findLeadRowsWithEmail(): array
    {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT id, edition_id, lead_email FROM {$this->table()}
             WHERE (user_id IS NULL OR user_id = 0)
               AND lead_email IS NOT NULL AND lead_email != ''",
        ) ?: [];
    }

    /**
     * Bind an account-less (lead) row to a WP account — THE one bind write
     * (INV-3, INV-9). Used by every surface that turns a lead into an
     * account-bound registration: waitlist promotion, the self-bind at
     * interest/waitlist submission (form-identity plan 2026-07-14), and the
     * one-time adoption script.
     *
     * Minimal write: sets `user_id` and clears the denormalized lead columns
     * (once bound, identity lives on the account — lead-identity invariant;
     * the captured stage data stays in enrollment_data untouched). Leaves
     * status, registered_at and enrollment_path alone — the promote capacity
     * transaction owns the status flip. The enroll-time adopt
     * (upgradeFromInterest) is the ONE sibling write: same guard, same
     * lead-column clear, but it additionally flips status/path in the same
     * statement because enrolling IS the status change.
     *
     * $companyId travels in the SAME guarded statement so every bind surface
     * stamps the account's partner affiliation exactly once (Partner API
     * parity — a row must never stay invisible to /stride/v1/partner/*
     * purely because it started as a lead). COALESCE keeps a non-zero
     * pre-existing company_id: an admin-set scope is never overwritten.
     *
     * GUARDED on the row still being account-less: a concurrent bind (or a
     * wrong id) can never silently re-home a row that already belongs to an
     * account — 0 affected rows returns false, callers treat it as a failed
     * bind and re-read.
     */
    public function bindLeadToUser(int $registrationId, int $userId, ?int $companyId = null): bool
    {
        global $wpdb;

        if ($companyId) {
            $sql = $wpdb->prepare(
                "UPDATE {$this->table()}
                 SET user_id = %d, lead_name = '', lead_email = '',
                     company_id = COALESCE(NULLIF(company_id, 0), %d)
                 WHERE id = %d AND (user_id IS NULL OR user_id = 0)",
                $userId,
                $companyId,
                $registrationId,
            );
        } else {
            $sql = $wpdb->prepare(
                "UPDATE {$this->table()}
                 SET user_id = %d, lead_name = '', lead_email = ''
                 WHERE id = %d AND (user_id IS NULL OR user_id = 0)",
                $userId,
                $registrationId,
            );
        }

        $result = $wpdb->query($sql);

        $this->clearCache();

        if ($result !== false && $result > 0) {
            $this->emitRowEvent('row_updated', $registrationId, ['user_id' => $userId], 'bind_lead_to_user');
            return true;
        }

        return false;
    }

    /**
     * Migration support (CR-D3 / INV-3): every registration that carries
     * completion_tasks, as minimal {id, completion_tasks} pairs. The table
     * is repository-owned, so table-wide scans read through here — not via
     * raw $wpdb in the caller (CompletionProofStorage::migrate()).
     *
     * @return array<object> rows with ->id (int) and ->completion_tasks
     *                       (array|null — JSON-decoded, repo convention)
     */
    public function idsWithCompletionTasks(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, completion_tasks FROM {$this->table()} WHERE completion_tasks IS NOT NULL",
        );

        return $this->decodeCompletionTaskRows($rows);
    }

    /**
     * Approvals scan, enrollment phase (INV-3, panel drift Important-2):
     * EVERY pending registration in scope, oldest first, up to the scan cap.
     * Deliberately no task-shape SQL pre-filter: the panel's bucket rule is
     * EnrollmentCompletion::awaitsAdmin — "user side done, or no tasks at
     * all" — which SQL cannot express, and every attempt to approximate it
     * (IS NULL / open-$.approval predicates) silently hid a row subset the
     * queue card counted (F-V5 twice). The PHP re-checks are authoritative;
     * pending rows are few by nature and the LIMIT is the caller's scan cap
     * (CR-E2 — a full result set means "maybe clipped").
     *
     * $editionIds scopes the scan to the admin-active edition set (F-V4 —
     * the same corpus the queue cards reason over); rows with NO edition
     * (trajectory parents) always pass — the cards can't show them (they are
     * edition-queues), the panel deliberately can. null = unscoped; an EMPTY
     * array means "no active editions" and matches only edition-less rows —
     * never a silent whole-table fallback (the cards would read 0 while the
     * panel listed closed-edition rows).
     *
     * @param array<int>|null $editionIds
     * @return array<object> rows with ->id (int), ->user_id, ->edition_id,
     *                       ->registered_at and ->completion_tasks
     *                       (array|null — JSON-decoded, repo convention)
     */
    public function findPendingForApprovalScan(int $scanCap, ?array $editionIds = null): array
    {
        global $wpdb;

        [$scopeSql, $scopeArgs] = $this->editionScopeSql($editionIds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, edition_id, registered_at, completion_tasks
             FROM {$this->table()}
             WHERE status = 'pending'
               {$scopeSql}
             ORDER BY registered_at ASC
             LIMIT %d",
            ...array_merge($scopeArgs, [$scanCap]),
        ));

        return $this->decodeCompletionTaskRows($rows);
    }

    /**
     * Admin-active edition scope fragment for the approvals scans, as a
     * prepared [sql, args] pair (the repo's placeholder-throughout property
     * is load-bearing for INV-3 — no interpolated id lists). null = no
     * scope; [] = only edition-less rows (trajectory parents), NEVER an
     * unscoped fallback.
     *
     * @param array<int>|null $editionIds
     * @return array{0: string, 1: list<int>}
     */
    private function editionScopeSql(?array $editionIds): array
    {
        if ($editionIds === null) {
            return ['', []];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $editionIds))));
        if (empty($ids)) {
            return ['AND edition_id IS NULL', []];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        return ["AND (edition_id IS NULL OR edition_id IN ({$placeholders}))", $ids];
    }

    /**
     * Approvals scan, post-course phase (INV-3): confirmed registrations
     * with an open post_approval task whose post-course user tasks (fixed
     * keys) are absent-or-completed. Same over-fetch + COLLATE + scan-cap +
     * edition-scope contract as findPendingForApprovalScan(). Post-course
     * approvals deliberately stay UNSCOPED at the call site (editionIds =
     * null): the work arrives around/after course end, when admins have
     * often already closed the edition — scoping it hid open post_approvals
     * on closed editions from every surface.
     *
     * @param array<int> $editionIds
     * @return array<object> same row shape as findPendingForApprovalScan()
     */
    public function findConfirmedWithOpenPostApproval(int $scanCap, ?array $editionIds = null): array
    {
        global $wpdb;

        [$scopeSql, $scopeArgs] = $this->editionScopeSql($editionIds);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, user_id, edition_id, registered_at, completion_tasks
             FROM {$this->table()}
             WHERE status = 'confirmed'
               {$scopeSql}
               AND completion_tasks IS NOT NULL
               AND JSON_EXTRACT(completion_tasks, '$.post_approval') IS NOT NULL
               AND COALESCE(JSON_VALUE(completion_tasks, '$.post_approval.status'), 'pending') COLLATE utf8mb4_bin <> 'completed'
               AND (JSON_EXTRACT(completion_tasks, '$.post_evaluation') IS NULL
                    OR JSON_VALUE(completion_tasks, '$.post_evaluation.status') COLLATE utf8mb4_bin = 'completed')
               AND (JSON_EXTRACT(completion_tasks, '$.post_documents') IS NULL
                    OR JSON_VALUE(completion_tasks, '$.post_documents.status') COLLATE utf8mb4_bin = 'completed')
             ORDER BY registered_at ASC
             LIMIT %d",
            ...array_merge($scopeArgs, [$scanCap]),
        ));

        return $this->decodeCompletionTaskRows($rows);
    }

    /**
     * Repo row convention for completion-task scans: int id, decoded tasks.
     *
     * @param array<object> $rows
     * @return array<object>
     */
    private function decodeCompletionTaskRows(array $rows): array
    {
        foreach ($rows as $row) {
            $row->id = (int) $row->id;
            $row->completion_tasks = is_string($row->completion_tasks)
                ? json_decode($row->completion_tasks, true)
                : null;
        }

        return $rows;
    }

    // === Status updates ===

    /**
     * Update registration.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $allowed = ['status', 'selections', 'selections_locked_at', 'quote_id', 'company_id', 'completed_at', 'cancelled_at', 'notes', 'completion_tasks', 'enrollment_data', 'parent_registration_id'];
        $update = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (in_array($field, ['selections', 'completion_tasks', 'enrollment_data'], true) && is_array($value)) {
                    if ($field === 'enrollment_data') {
                        $value = self::normalizeEnrollmentData($value);
                        // Keep $data[$field] in sync so the diff comparison below uses normalized shape.
                        $data[$field] = $value;
                    }
                    $value = wp_json_encode($value);
                }
                $update[$field] = $value;
            }
        }

        if (empty($update)) {
            return true;
        }

        // Snapshot before the write so the audit hook can record a field-level diff.
        // Compares against $data (not $update) for JSON fields so the diff captures
        // structural changes, not just "different encoded string."
        $before = $this->find($id);

        // Anonymous lead whose enrollment_data is being (re)written: keep the
        // denormalized searchable identity in sync (F-G3). Not in $allowed —
        // derived by the repo, never caller-settable.
        // UNCONDITIONAL stamp: the columns mirror the rewritten JSON exactly,
        // so an identity scrubbed from enrollment_data (GDPR/privacy cleanup)
        // clears the denormalized copy too — it must never outlive its source
        // in the Naam column or the grid search.
        if (
            array_key_exists('enrollment_data', $update)
            && $before !== null
            && empty($before->user_id)
            && is_array($data['enrollment_data'] ?? null)
        ) {
            $identity = self::extractLeadIdentity($data['enrollment_data']);
            $update['lead_name'] = $identity['name'];
            $update['lead_email'] = $identity['email'];
        }

        $result = $wpdb->update($this->table(), $update, ['id' => $id]) !== false;

        if ($result) {
            $this->clearCache();

            if ($before) {
                $diff = [];
                foreach ($update as $field => $newValue) {
                    $oldValue = $before->$field ?? null;
                    $compareNew = in_array($field, ['selections', 'completion_tasks', 'enrollment_data'], true)
                        ? ($data[$field] ?? null)
                        : $newValue;
                    if ($oldValue != $compareNew) {
                        $diff[$field] = ['old' => $oldValue, 'new' => $compareNew];
                    }
                }

                if ($diff) {
                    do_action('stride/registration/updated', [
                        'registration_id' => $id,
                        'diff' => $diff,
                        'actor_id' => get_current_user_id() ?: null,
                    ]);
                }
            }

            $this->emitRowEvent('row_updated', $id, $update, 'update');
        }

        return $result;
    }

    /**
     * Update registration status.
     *
     * `completed_at` and `cancelled_at` are set on the FIRST transition only.
     * Subsequent calls to updateStatus with the same terminal status are
     * idempotent and preserve the original timestamp.
     */
    public function updateStatus(int $id, RegistrationStatus $status): bool
    {
        $data = ['status' => $status->value];

        // Clear completion_tasks when moving to Cancelled — they're stage-specific
        // and don't apply to a terminated registration. On re-enroll the
        // reactivation path initializes fresh tasks anyway.
        if ($status === RegistrationStatus::Cancelled) {
            $data['completion_tasks'] = null;
        }

        if ($status === RegistrationStatus::Cancelled || $status === RegistrationStatus::Completed) {
            $existing = $this->find($id);
            if ($existing) {
                if ($status === RegistrationStatus::Cancelled && empty($existing->cancelled_at)) {
                    $data['cancelled_at'] = current_time('mysql');
                }
                if ($status === RegistrationStatus::Completed && empty($existing->completed_at)) {
                    $data['completed_at'] = current_time('mysql');
                }
            }
        }

        return $this->update($id, $data);
    }

    /**
     * Cancel a registration (data-only).
     *
     * Does NOT fire stride/registration/cancelled — callers must use
     * EnrollmentService::cancel() for the full lifecycle (LMS revoke, quote
     * cancel, audit, mail). This method is the raw data write; the event is
     * dispatched by the service so listeners see a fully consistent state.
     */
    public function cancel(int $id): bool
    {
        $registration = $this->find($id);
        if (!$registration) {
            return false;
        }

        return $this->updateStatus($id, RegistrationStatus::Cancelled);
    }

    // === Reminder state (Phase 2 Task 2.2) ===

    /**
     * Get the reminder-state ledger for a registration.
     *
     * Shape-agnostic: this method faithfully round-trips whatever array was
     * stored via setReminderState() — it does not validate/interpret the
     * reminder/deadline keys (that logic lives in Phase 4).
     *
     * @return array<string, mixed> Decoded reminder_state, or `[]` when the
     *                               column is NULL/empty or the row doesn't
     *                               exist. Never returns null/false.
     */
    public function getReminderState(int $registrationId): array
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT reminder_state FROM {$this->table()} WHERE id = %d",
            $registrationId,
        ));

        if ($raw === null || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set the reminder-state ledger for a registration.
     *
     * @param array<string, mixed> $state
     * @return bool|WP_Error True on success; WP_Error('db_error', ...) on a
     *                       DB failure (INV-4) — never returns false.
     */
    public function setReminderState(int $registrationId, array $state): bool|WP_Error
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table(),
            ['reminder_state' => wp_json_encode($state)],
            ['id' => $registrationId],
            ['%s'],
            ['%d'],
        );

        if ($result === false) {
            ntdst_log('enrollment')->error('failed to update reminder state', [
                'registration_id' => $registrationId,
                'error' => $wpdb->last_error,
            ]);

            return new WP_Error('db_error', 'Failed to update reminder state');
        }

        $this->clearCache();
        $this->emitRowEvent('row_updated', $registrationId, ['reminder_state' => $state], 'set_reminder_state');

        return true;
    }

    /**
     * Enumeration query for the daily reminder cron (Phase 2 Task 2.3,
     * threat-model A2): registrations whose edition has an active gate
     * deadline (enroll-phase gate_deadline OR post-phase post_gate_deadline)
     * that has NOT yet passed.
     *
     * DATE FLOOR (scalability audit 2026-07-03, finding #7): a row is only
     * enumerated if at least one of its deadlines is >= $today. Confirmed
     * rows only leave the table via completion/cancellation, so without this
     * floor a confirmed-incomplete registration on a long-expired edition was
     * re-scanned every daily cron tick forever (at 25k accumulated rows:
     * ~100k+ queries + 25k GET_LOCKs per tick). The deadline metas are stored
     * as zero-padded ISO date strings ('Y-m-d', from an <input type="date">),
     * so lexicographic `>= $today` is a correct calendar comparison. The floor
     * is $today (not today-minus-grace): the reminder mail always fires before
     * the deadline and the day-before mail fires from deadline-1 onward, so a
     * deadline landing on today stays enumerable while a strictly-past deadline
     * drops out. Coupled with GateReminderDueCalculator (which already returns
     * null for already-sent phases), this converges the corpus to only rows
     * that can still produce a mail.
     *
     * KEYSET PAGINATION (finding #7): keyset (`r.id > $afterId ORDER BY r.id
     * ASC LIMIT`) instead of OFFSET. OFFSET made the cron loop O(n^2) row
     * visits across a run (each chunk re-walks all skipped rows) plus a
     * filesort per chunk; keyset seeks straight to the cursor on the PK. Order
     * is by r.id (the PK) — registered_at is not unique, so it cannot give a
     * stable keyset cursor. The consumer (GateReminderService) processes each
     * row independently by its own deadline, so id-order vs registered_at-order
     * does not change any outcome.
     *
     * Bounded (mitigation 5) — explicit LIMIT, $limit clamped to [1, 1000] so
     * a caller can never request an unbounded scan. Prepared (mitigation 8) —
     * status values + the today floor + limit/cursor all go through
     * $wpdb->prepare; the two meta_key literals are hardcoded constants (not
     * user input), matching the findForExport precedent for inline meta-key
     * literals in this repo. GROUP BY r.id dedupes any fan-out from the two
     * LEFT JOINs against wp_postmeta, so a registration row is never returned
     * twice within a page even if duplicate postmeta rows existed on an edition.
     *
     * @param int $limit   Page size, clamped to [1, 1000].
     * @param int $afterId Keyset cursor — return rows with r.id strictly greater.
     *
     * @return array<object> Full row objects (SELECT *), matching the return
     *                       shape of the other find* methods on this repo.
     */
    public function findWithActiveDeadline(int $limit = 500, int $afterId = 0): array
    {
        global $wpdb;
        $table = $this->table();

        $limit = max(1, min($limit, 1000));
        $afterId = max(0, $afterId);
        $today = current_time('Y-m-d');

        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.* FROM {$table} r
             LEFT JOIN {$wpdb->postmeta} pm_gate ON r.edition_id = pm_gate.post_id AND pm_gate.meta_key = '_ntdst_gate_deadline'
             LEFT JOIN {$wpdb->postmeta} pm_post_gate ON r.edition_id = pm_post_gate.post_id AND pm_post_gate.meta_key = '_ntdst_post_gate_deadline'
             WHERE r.status IN (%s, %s)
               AND r.id > %d
               AND (
                   (pm_gate.meta_value IS NOT NULL AND pm_gate.meta_value >= %s)
                   OR (pm_post_gate.meta_value IS NOT NULL AND pm_post_gate.meta_value >= %s)
               )
             GROUP BY r.id
             ORDER BY r.id ASC
             LIMIT %d",
            RegistrationStatus::Confirmed->value,
            RegistrationStatus::Pending->value,
            $afterId,
            $today,
            $today,
            $limit,
        ));
    }

    // === Cache management ===

    /**
     * Clear per-request memoization cache.
     *
     * Fires `stride/registration/cache_cleared` so downstream per-request
     * memos (e.g. UserDashboardService) invalidate at the same single point
     * every registration write path already funnels through.
     */
    public function clearCache(): void
    {
        $this->findByUserCache = [];

        do_action('stride/registration/cache_cleared');
    }

    // === Admin grid read-model ===

    /**
     * Batched-join read-model for the admin registrations grid.
     *
     * Returns ['rows' => array<object>, 'total' => int, 'page' => int, 'per_page' => int].
     * Structured columns only — NEVER reads enrollment_data/selections/completion_tasks (M5).
     *
     * Security mitigations live here BY CONSTRUCTION:
     *  - M4: every structured param goes through a server-side whitelist.
     *        Unknown sort/group_by → default; unknown status → ignored.
     *        Integers via absint; q bound via $wpdb->prepare %s; per_page capped at 100.
     *  - M5: NO code path puts enrollment_data/selections/completion_tasks into
     *        WHERE/ORDER/GROUP BY.
     *
     * Active-edition scope is an ID-SET the caller passes (active_edition_ids,
     * resolved by AdminRegistrationQueryService from
     * EditionRepository::findAdminActiveIds — status-based, decision
     * 2026-07-14). This repo carries NO scope predicate of its own; the
     * service omits the key for edition_scope='all', an explicit edition_id,
     * or a queue pin (whose ids already encode the scope).
     *
     * @param array $filters Accepts ONLY these keys (everything else ignored):
     *   status (string ∈ RegistrationStatus values),
     *   edition_id (int), company_id (int),
     *   trajectory_id (int — parent→child join, Task 1.4b),
     *   q (string, name search bound via prepare %s),
     *   active_edition_ids (list<int> — the resolved admin-active scope),
     *   queue_ids (list<int> — a WorklistQueueResolver id-set pin),
     *   sort (string ∈ SORT_ALLOWLIST), order ('asc'|'desc'),
     *   group_by (string ∈ GROUP_BY_ALLOWLIST),
     *   page (int ≥1), per_page (int, capped at 100, default 50).
     * @return array{rows: array<object>, total: int, page: int, per_page: int}
     */
    public function queryForGrid(array $filters): array
    {
        global $wpdb;

        // --- M4: Sort allowlist (load-bearing security — do NOT add user input here) ---
        // Keys are the CLIENT sort keys (the grid's column headers); values are
        // SERVER-OWNED SQL expressions — user input only ever selects a key.
        // 'name' and 'edition' sort by what the admin SEES (display name /
        // edition title), not by FK id — the headers used to silently fall
        // back to registered_at, which read as a broken/random sort (F-G1).
        $sortExpressions = [
            'registered_at' => 'r.registered_at',
            'status'        => 'r.status',
            'edition_id'    => 'r.edition_id',
            'company_id'    => 'r.company_id',
            'completed_at'  => 'r.completed_at',
            'cancelled_at'  => 'r.cancelled_at',
            // NULLIF: an anonymous lead has NO users join (display_name NULL) —
            // it must sort by its lead_name (what the Naam column SHOWS), not
            // clump at '' regardless of the rendered name.
            'name'          => "COALESCE(NULLIF(u.display_name, ''), r.lead_name, '')",
            'edition'       => "COALESCE(ep.post_title, '')",
        ];

        // --- Sanitize sort/order/pagination ---
        $sortKey = array_key_exists((string) ($filters['sort'] ?? ''), $sortExpressions)
            ? (string) $filters['sort']
            : 'registered_at';
        $sortExpr = $sortExpressions[$sortKey];

        // Edition-title sort needs the edition post joined for ORDER BY only.
        $sortJoin = $sortKey === 'edition'
            ? "LEFT JOIN {$wpdb->posts} ep ON ep.ID = r.edition_id"
            : '';

        $order = strtolower($filters['order'] ?? '') === 'asc' ? 'ASC' : 'DESC';

        // group_by is intentionally NOT applied here. queryForGrid is the FLAT
        // read-model: one row per registration. Grouping rows by a non-aggregated
        // column (over the 14 selected columns) returns arbitrary rows under
        // MariaDB's relaxed ONLY_FULL_GROUP_BY. Grouped/aggregate reads are owned
        // by queryForGridGrouped (the service routes group_by requests there).

        // --- Build shared WHERE / JOIN (all filters including q) ---
        $built   = $this->buildGridFilters($filters);
        $page    = $built['page'];
        $perPage = $built['per_page'];
        $offset  = ($page - 1) * $perPage;
        $regTable = $this->table();

        $activeJoin  = $built['active_join'];
        $whereClause = $built['where_clause'];
        $params      = $built['params'];

        // --- COUNT total (row count — flat read-model, M5: structured columns only) ---
        $countSql = "SELECT COUNT(*) FROM {$regTable} r
                     LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                     {$activeJoin}
                     {$whereClause}";

        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($countSql, ...$params))
            : $wpdb->get_var($countSql));

        // --- Fetch rows (structured columns only — M5) ---
        // ORDER BY: column name comes from the server-side allowlist (M4), never from user input.
        // lead_name/lead_email are the denormalized anonymous-lead identity (v5) —
        // the grid no longer ships the enrollment_data JSON blob per row just to
        // decode a name out of it.
        // Stable tiebreaker (r.id) so equal sort values can never shuffle rows
        // across page boundaries between requests.
        $dataSql = "SELECT r.id, r.user_id, r.edition_id, r.trajectory_id,
                           r.parent_registration_id, r.status, r.enrollment_path,
                           r.company_id, r.registered_at, r.completed_at,
                           r.cancelled_at, r.quote_id, r.enrolled_by, r.notes,
                           r.lead_name, r.lead_email
                    FROM {$regTable} r
                    LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                    {$activeJoin}
                    {$sortJoin}
                    {$whereClause}
                    ORDER BY {$sortExpr} {$order}, r.id DESC
                    LIMIT %d OFFSET %d";

        $dataParams = array_merge($params, [$perPage, $offset]);
        $rows       = $wpdb->get_results($wpdb->prepare($dataSql, ...$dataParams));

        return [
            'rows'     => $rows ?? [],
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Aggregate read-model for the admin registration grid — grouped path.
     *
     * Applies the SAME filter surface as queryForGrid (same WHERE/JOIN construction,
     * same q predicate, same active-scope logic, same M4/M5 mitigations) and adds
     * a GROUP BY aggregate SELECT on top.
     *
     * Returns a single array holding everything the service layer needs:
     *  - 'agg_rows'   => array<object>  — one row per distinct group_value
     *                                      (cols: group_value, cnt, completed_count)
     *  - 'group_rows' => array<string,array<object>>  — group_value => up to
     *                                      GROUP_ROW_CAP FULL child-row objects
     *                                      (same column set as queryForGrid),
     *                                      ordered registered_at DESC.
     *  - 'total'      => int  — total number of ROWS (not groups) matching filters
     *  - 'page'       => int
     *  - 'per_page'   => int
     *
     * The offerte_verdeling distribution is NOT returned here — it is computed in
     * SQL by offerteVerdelingByGroup (FIX-10), so this method never materialises
     * the full per-group id set.
     *
     * M4 guarantee: $groupBy MUST already be validated against GROUP_BY_ALLOWLIST
     * before calling this method (the caller owns the guard). The column name is
     * never interpolated from user input.
     *
     * @param  array<string,mixed> $filters  Same key-set as queryForGrid.
     * @param  string              $groupBy  Validated allowlisted column name.
     * @return array{agg_rows:array<object>,group_rows:array<string,array<object>>,total:int,page:int,per_page:int}
     */
    public function queryForGridGrouped(array $filters, string $groupBy): array
    {
        global $wpdb;

        $built    = $this->buildGridFilters($filters);
        $page     = $built['page'];
        $perPage  = $built['per_page'];
        $regTable = $this->table();

        $activeJoin  = $built['active_join'];
        $whereClause = $built['where_clause'];
        $params      = $built['params'];

        $groupColSql = "r.{$groupBy}";  // column name from allowlist — never user input

        // --- Count total DISTINCT GROUPS (the page is paginated over groups) ---
        // total = number of group-pages' worth of rows, NOT the matching ROW count.
        // Counting rows here invented phantom pages (ceil(rowTotal/perPage) when the
        // LIMIT/OFFSET is applied to the grouped aggregate). Mirror queryForGrid's
        // group-count subquery wrap.
        $innerCountSql = "SELECT 1 FROM {$regTable} r
                          LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                          {$activeJoin}
                          {$whereClause}
                          GROUP BY {$groupColSql}";
        $countSql = "SELECT COUNT(*) FROM ({$innerCountSql}) AS _grp_count";

        $total = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($countSql, ...$params))
            : $wpdb->get_var($countSql));

        // --- Aggregate SELECT: one row per group, ordered by count DESC ---
        // Deterministic tiebreaker on the group column: tied counts (cnt=1 is
        // common) would otherwise order arbitrarily, so paging could repeat/
        // skip groups between requests AND offerteVerdelingByGroup's mirror of
        // this query could resolve the same page to a DIFFERENT group set —
        // rendering groups whose tally is missing. Same stable-pagination rule
        // as the flat path's r.id tiebreaker.
        $aggSql = "SELECT {$groupColSql} AS group_value,
                          COUNT(*) AS cnt,
                          SUM(CASE WHEN r.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
                   FROM {$regTable} r
                   LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                   {$activeJoin}
                   {$whereClause}
                   GROUP BY {$groupColSql}
                   ORDER BY cnt DESC, {$groupColSql} DESC
                   LIMIT %d OFFSET %d";

        $aggParams = array_merge($params, [$perPage, ($page - 1) * $perPage]);
        $aggRows   = $wpdb->get_results($wpdb->prepare($aggSql, ...$aggParams));

        if (empty($aggRows)) {
            return [
                'agg_rows'   => [],
                'group_rows' => [],
                'total'      => $total,
                'page'       => $page,
                'per_page'   => $perPage,
            ];
        }

        // --- Narrow to the current page's groups (for the capped child rows) ---
        // FIX-10: the offerte_verdeling tally NO LONGER pulls every reg-id of each
        // visible group into PHP — it is computed in SQL by offerteVerdelingByGroup.
        // This group filter now serves ONLY the bounded window query below (the
        // ≤ GROUP_ROW_CAP child rows), which is intrinsically bounded.
        // A NULL group_value (defensive — the edition_id-NULL corpus exclusion in
        // buildGridFilters means this should never occur for edition_id, but other
        // allowlist columns could in principle hold NULL) must route via IS NULL,
        // NEVER `IN ('')` — strval(null)='' would silently miss the IS NULL rows.
        $nonNullGroupValues = [];
        $hasNullGroup       = false;
        foreach ($aggRows as $r) {
            if ($r->group_value === null) {
                $hasNullGroup = true;
            } else {
                $nonNullGroupValues[] = (string) $r->group_value;
            }
        }

        $groupClauses    = [];
        $groupFilterArgs = [];
        if (!empty($nonNullGroupValues)) {
            $groupPlaceholders = implode(',', array_fill(0, count($nonNullGroupValues), '%s'));
            $groupClauses[]    = "r.{$groupBy} IN ({$groupPlaceholders})";
            $groupFilterArgs   = $nonNullGroupValues;
        }
        if ($hasNullGroup) {
            $groupClauses[] = "r.{$groupBy} IS NULL";
        }
        $groupFilter = '(' . implode(' OR ', $groupClauses) . ')';

        $fullWhere = $whereClause
            ? "{$whereClause} AND {$groupFilter}"
            : "WHERE {$groupFilter}";

        // --- Capped per-group FULL-column child rows (the accordion body) ---
        // ONE window-function query (MariaDB 10.11): ROW_NUMBER() partitioned by
        // the group column, ordered registered_at DESC, filtered <= GROUP_ROW_CAP
        // in the outer WHERE — no N+1. Reuses the SAME $activeJoin + $fullWhere
        // (the visible-page group filter) + $params as the aggregate above, so the
        // composed rows are scoped IDENTICALLY (active-scope, company, status,
        // trajectory-parent exclusion). The cap applies ONLY here — the offerte
        // tally is computed separately in SQL (offerteVerdelingByGroup, FIX-10).
        //
        // Column set is byte-identical to queryForGrid's $dataSql (M5: structured
        // columns only; lead_name/lead_email are the denormalized anon-lead
        // identity — no enrollment_data blob shipped per row).
        //
        // PARAM ORDER (M4): the inner subquery text consumes $params + the group
        // filter args (JOIN %d before WHERE params, then the group IN placeholders);
        // the outer `_rn <= %d` placeholder follows all of them, so GROUP_ROW_CAP is
        // appended LAST.
        $rowsSql = "SELECT id, user_id, edition_id, trajectory_id,
                           parent_registration_id, status, enrollment_path,
                           company_id, registered_at, completed_at,
                           cancelled_at, quote_id, enrolled_by, notes,
                           lead_name, lead_email, group_val
                    FROM (
                        SELECT r.id, r.user_id, r.edition_id, r.trajectory_id,
                               r.parent_registration_id, r.status, r.enrollment_path,
                               r.company_id, r.registered_at, r.completed_at,
                               r.cancelled_at, r.quote_id, r.enrolled_by, r.notes,
                               r.lead_name, r.lead_email,
                               r.{$groupBy} AS group_val,
                               ROW_NUMBER() OVER (
                                   PARTITION BY r.{$groupBy}
                                   ORDER BY r.registered_at DESC, r.id DESC
                               ) AS _rn
                        FROM {$regTable} r
                        LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                        {$activeJoin}
                        {$fullWhere}
                    ) AS _ranked
                    WHERE _rn <= %d";

        $rowsParams = array_merge($params, $groupFilterArgs, [self::GROUP_ROW_CAP]);
        $rankedRows = $wpdb->get_results($wpdb->prepare($rowsSql, ...$rowsParams));

        $groupRows = [];
        foreach ($rankedRows as $row) {
            $gv = $row->group_val;
            unset($row->group_val); // keep the shape byte-identical to queryForGrid rows
            if (!isset($groupRows[$gv])) {
                $groupRows[$gv] = [];
            }
            $groupRows[$gv][] = $row;
        }

        return [
            'agg_rows'   => $aggRows,
            'group_rows' => $groupRows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
        ];
    }

    /**
     * Per-group offerte-status tally, computed ENTIRELY in SQL (FIX-10).
     *
     * Replaces the old unbounded path where queryForGridGrouped pulled EVERY
     * registration id of every visible group into PHP (group_reg_ids) purely so
     * the service could tally the offerte-status distribution. At 50k rows that
     * built a 50k-placeholder IN clause and materialised 50k ids → OOM.
     *
     * This method computes the same distribution with a single GROUP BY:
     *   GROUP BY r.{$groupBy}, <quote status of the MIN-post-ID quote>
     * so the tally never leaves the database as a row set.
     *
     * SCOPE PARITY (INV-3): reuses buildGridFilters — the SAME active_join,
     * where_clause and params as queryForGrid/queryForGridGrouped — so the WHERE
     * and JOIN are byte-identical to the flat/grouped paths (trajectory-parent
     * corpus exclusion, edition_id-NOT-NULL base predicate, active-scope, company,
     * status, q). It then narrows to ONLY the visible page's groups (the same
     * IN(nonNullGroupValues) OR IS NULL filter queryForGridGrouped derives from
     * its paginated aggregate rows), so the tally matches the aggregate rows the
     * service renders.
     *
     * QUOTE JOIN semantic — identical to QuoteRepository::findQuoteIdsByRegistrations:
     * a registration may have MULTIPLE published vad_quote posts; the MIN(post ID)
     * quote is THE quote. A derived table picks that MIN quote per registration_id
     * (registration_id meta is a STRING), then its `status` meta is LEFT JOINed.
     * A reg with no published quote — or a quote with null/empty status — falls
     * into the NULL status bucket, returned under the '' key. The SERVICE maps the
     * raw status → QuoteStatus->label() / 'Geen offerte', exactly as
     * resolveOfferteStatuses does (null/'' → 'Geen offerte'; valid enum → label();
     * unknown raw → verbatim), so labels are byte-identical to the old path.
     *
     * @param  array<string,mixed> $filters  Same key-set as queryForGridGrouped.
     * @param  string              $groupBy  Validated allowlisted column (caller owns M4).
     * @return array<string,array<string,int>>  group_value => (raw status | '') => count.
     *         The NULL-group key is '' (empty string); the no-quote/null-status
     *         bucket key is also '' within a group's inner map.
     */
    public function offerteVerdelingByGroup(array $filters, string $groupBy): array
    {
        global $wpdb;

        $built       = $this->buildGridFilters($filters);
        $page        = $built['page'];
        $perPage     = $built['per_page'];
        $activeJoin  = $built['active_join'];
        $whereClause = $built['where_clause'];
        $params      = $built['params'];
        $regTable    = $this->table();

        $groupColSql = "r.{$groupBy}";  // column name from allowlist — never user input

        // --- Resolve the visible page's groups (mirror queryForGridGrouped) ---
        // The tally must cover EXACTLY the groups the aggregate page renders, so
        // we recompute the same paginated group set (same ORDER BY — incl. the
        // deterministic group-column tiebreaker, or tied counts could resolve
        // this page to a different group set than queryForGridGrouped's — and
        // same LIMIT/OFFSET) and narrow to it. A NULL group_value routes via
        // IS NULL, never IN ('') (strval(null)='' would miss the IS NULL rows).
        $aggSql = "SELECT {$groupColSql} AS group_value, COUNT(*) AS cnt
                   FROM {$regTable} r
                   LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                   {$activeJoin}
                   {$whereClause}
                   GROUP BY {$groupColSql}
                   ORDER BY cnt DESC, {$groupColSql} DESC
                   LIMIT %d OFFSET %d";
        $aggParams = array_merge($params, [$perPage, ($page - 1) * $perPage]);
        $aggRows   = $wpdb->get_results($wpdb->prepare($aggSql, ...$aggParams));

        if (empty($aggRows)) {
            return [];
        }

        $nonNullGroupValues = [];
        $hasNullGroup       = false;
        foreach ($aggRows as $r) {
            if ($r->group_value === null) {
                $hasNullGroup = true;
            } else {
                $nonNullGroupValues[] = (string) $r->group_value;
            }
        }

        $groupClauses    = [];
        $groupFilterArgs = [];
        if (!empty($nonNullGroupValues)) {
            $groupPlaceholders = implode(',', array_fill(0, count($nonNullGroupValues), '%s'));
            $groupClauses[]    = "r.{$groupBy} IN ({$groupPlaceholders})";
            $groupFilterArgs   = $nonNullGroupValues;
        }
        if ($hasNullGroup) {
            $groupClauses[] = "r.{$groupBy} IS NULL";
        }
        $groupFilter = '(' . implode(' OR ', $groupClauses) . ')';

        $fullWhere = $whereClause
            ? "{$whereClause} AND {$groupFilter}"
            : "WHERE {$groupFilter}";

        // --- The SQL tally ---
        // Derived table `q` = MIN(post ID) published vad_quote per registration_id
        // (mirrors findQuoteIdsByRegistrations: registration_id meta is a string,
        // GROUP BY meta_value, MIN(p.ID) wins over multiple quotes). Then LEFT JOIN
        // that quote's `status` postmeta. r → q join is on the string reg id.
        // Aggregate: COUNT(*) per (group_value, status), NULL status kept as NULL
        // (mapped to the '' bucket in PHP). No registration id ever leaves SQL.
        $quotePostType = \Stride\Modules\Invoicing\QuoteCPT::POST_TYPE;

        // The quote derived table is joined FIRST (before $activeJoin) so its %s
        // post_type placeholder is the very first bound param — this keeps the
        // param order simple and correct even when $activeJoin itself carries a
        // %d (the trajectory JOIN, whose param buildGridFilters array_unshifts to
        // the FRONT of $params). SQL text placeholder order is therefore:
        //   %s (post_type) → $activeJoin's %d (if any) + $whereClause's params
        //   (both already in $params, active-join param first) → group filter args.
        $sql = "SELECT {$groupColSql} AS group_value, qs.meta_value AS quote_status, COUNT(*) AS cnt
                FROM {$regTable} r
                LEFT JOIN (
                    SELECT pm.meta_value AS reg_id, MIN(p.ID) AS quote_id
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                    WHERE p.post_type = %s AND p.post_status = 'publish'
                      AND pm.meta_key = 'registration_id'
                    GROUP BY pm.meta_value
                ) q ON q.reg_id = CAST(r.id AS CHAR)
                LEFT JOIN {$wpdb->postmeta} qs
                    ON qs.post_id = q.quote_id AND qs.meta_key = 'status'
                LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                {$activeJoin}
                {$fullWhere}
                GROUP BY {$groupColSql}, qs.meta_value";

        $sqlParams = array_merge([$quotePostType], $params, $groupFilterArgs);
        $rows      = $wpdb->get_results($wpdb->prepare($sql, ...$sqlParams));

        $tally = [];
        foreach ($rows as $row) {
            $group  = $row->group_value === null ? '' : (string) $row->group_value;
            // Null/empty quote status → the no-quote bucket, keyed ''. The service
            // maps '' → 'Geen offerte' (identical to resolveOfferteStatuses).
            $status = ($row->quote_status === null || $row->quote_status === '')
                ? ''
                : (string) $row->quote_status;

            if (!isset($tally[$group])) {
                $tally[$group] = [];
            }
            $tally[$group][$status] = ($tally[$group][$status] ?? 0) + (int) $row->cnt;
        }

        return $tally;
    }

    /**
     * Expand an admin-grid filter to its matching registration-id set (Task 4.1).
     *
     * The id-only twin of queryForGrid: it REUSES buildGridFilters (the single
     * WHERE source — same active_join / where_clause / params, same M4/M5
     * structured-only validation, same trajectory parent→child child-row
     * semantics, same `r.edition_id IS NOT NULL` base predicate) so a select-all
     * expansion can never use a forked filter definition. It differs from
     * queryForGrid ONLY in SELECTing r.id and dropping pagination/sort — paging
     * is irrelevant when expanding to the full filtered id-set.
     *
     * Capped by $limit (the caller passes MAX_BATCH + 1) so an over-cap expansion
     * returns MAX_BATCH+1 ids and the bulk handler's EXISTING cap guard rejects it
     * with too_many — this method never truncates silently nor enforces a second
     * cap.
     *
     * SCOPE CONTRACT: like queryForGrid, this method applies NO scope of its
     * own — the caller MUST route client filter input through
     * AdminRegistrationQueryService::applyScopePins() first, so the expansion
     * carries the same queue_ids / active_edition_ids pins the grid READ
     * rendered. A raw client filter here expands over the whole
     * edition-grained table (the 2026-07-14 blast-radius regression).
     *
     * Param order mirrors queryForGrid exactly: buildGridFilters unshifts the
     * trajectory JOIN %d to the FRONT of $params, so array_merge($params, [$limit])
     * keeps the LIMIT %d placeholder last, matching SQL placeholder order.
     *
     * @param  array<string,mixed> $filters Same structured key-set as queryForGrid.
     * @param  int                 $limit   Max ids to return (caller: MAX_BATCH + 1).
     * @return list<int>           Flat list of matching r.id, capped at $limit.
     */
    public function idsForGridFilter(array $filters, int $limit): array
    {
        global $wpdb;

        $built       = $this->buildGridFilters($filters);
        $activeJoin  = $built['active_join'];
        $whereClause = $built['where_clause'];
        $params      = $built['params'];
        $regTable    = $this->table();

        $sql = "SELECT r.id
                FROM {$regTable} r
                LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                {$activeJoin}
                {$whereClause}
                ORDER BY r.id ASC
                LIMIT %d";

        $allParams = array_merge($params, [max(1, $limit)]);
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$allParams));

        return array_map('intval', $ids ?: []);
    }

    /**
     * Build the shared WHERE clause, JOINs, and bound parameters for admin grid queries.
     *
     * This is the single source of filter logic shared between queryForGrid and
     * queryForGridGrouped. Both paths apply exactly the same predicates —
     * active-scope, edition_id, company_id, status, and the q name-search.
     *
     * Returns an array with keys:
     *  - 'active_join'  (string)  — extra LEFT JOINs for the active-scope predicate
     *  - 'where_clause' (string)  — full WHERE … fragment (empty string if no filters)
     *  - 'params'       (array)   — bound parameter values for wpdb->prepare()
     *  - 'page'         (int)
     *  - 'per_page'     (int)
     *
     * M4 guarantee: every param reaches SQL only via $wpdb->prepare() %s/%d placeholders.
     * M5 guarantee: enrollment_data, selections, completion_tasks are never read here.
     *
     * Corpus & scope semantics (by design):
     *  - Base predicate `r.edition_id IS NOT NULL` excludes trajectory PARENT rows
     *    from EVERY scope — they are never an edition-grained grid row.
     *  - The default 'active' scope pins to the caller-resolved id-set
     *    (active_edition_ids — published, admin-not-closed editions). A
     *    registration on a NON-published or closed edition is EXCLUDED from the
     *    default view but remains reachable via an explicit edition_id (the
     *    service omits the scope key then) or edition_scope='all'.
     *
     * @param  array<string,mixed> $filters
     * @return array{active_join:string,where_clause:string,params:array,page:int,per_page:int}
     */
    /**
     * Per-status row counts for the grid pipeline funnel (Task 3.3 Part B).
     *
     * Returns `status => count` for the CURRENT filter set MINUS the status
     * filter itself: the funnel shows "how many of each status match the OTHER
     * active filters (edition/company/trajectory/q/active-scope)", so selecting a
     * status chip never collapses the funnel to a single stage.
     *
     * Built on the SAME buildGridFilters() construction as queryForGrid (one
     * WHERE/JOIN source — no second divergent scoping copy), with the `status`
     * key dropped before building. Structured columns only (M5); the column name
     * `status` is a literal, never user input (M4).
     *
     * @param  array<string,mixed> $filters  Same key-set as queryForGrid.
     * @return array<string,int>  status value => count (only statuses with rows;
     *                            callers zero-fill the full enum as needed).
     */
    public function statusBreakdown(array $filters): array
    {
        global $wpdb;

        // Drop the status filter — the funnel counts every status under the
        // OTHER filters. group_by/sort/pagination are irrelevant to a tally.
        unset($filters['status'], $filters['group_by']);

        $built       = $this->buildGridFilters($filters);
        $activeJoin  = $built['active_join'];
        $whereClause = $built['where_clause'];
        $params      = $built['params'];
        $regTable    = $this->table();

        $sql = "SELECT r.status AS status, COUNT(*) AS c
                FROM {$regTable} r
                LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                {$activeJoin}
                {$whereClause}
                GROUP BY r.status";

        $rows = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params))
            : $wpdb->get_results($sql);

        $out = [];
        foreach ($rows ?? [] as $row) {
            $out[(string) $row->status] = (int) $row->c;
        }
        return $out;
    }

    private function buildGridFilters(array $filters): array
    {
        global $wpdb;

        // Trip-wire (blast-radius class, 2026-07-14): every entry path that
        // starts from CLIENT filter input must route through
        // AdminRegistrationQueryService::applyScopePins first (it stamps this
        // marker). This repo cannot resolve the scope itself (resolver DI
        // would cycle), so an unmarked build — a future export/saved-view/
        // bulk surface calling the grid family directly — logs loudly instead
        // of silently reading the whole edition-grained table. Explicit
        // pinned/scoped keys also count: a caller that resolved its own ids
        // is scoped by definition.
        if (
            empty($filters['scope_pins_applied'])
            && !array_key_exists('active_edition_ids', $filters)
            && !array_key_exists('queue_ids', $filters)
            && empty($filters['edition_id'])
        ) {
            ntdst_log('enrollment')->warning('grid filter built without scope pins — bounded to nothing but the base predicate', [
                'keys' => array_keys($filters),
            ]);
        }

        $page    = max(1, absint($filters['page'] ?? 1));
        $perPage = min(100, max(1, absint($filters['per_page'] ?? 50)));

        $editionId = !empty($filters['edition_id']) ? absint($filters['edition_id']) : null;
        $companyId = !empty($filters['company_id']) ? absint($filters['company_id']) : null;
        $trajectoryId = !empty($filters['trajectory_id']) ? absint($filters['trajectory_id']) : null;

        // Status: validate via enum (M4) — ignore unknown values.
        $statusValue = null;
        if (!empty($filters['status'])) {
            $statusEnum = RegistrationStatus::tryFrom((string) $filters['status']);
            if ($statusEnum !== null) {
                $statusValue = $statusEnum->value;
            }
        }

        // q: name search (M4 — bound via prepare %s, never interpolated).
        $q = !empty($filters['q']) ? (string) $filters['q'] : null;

        // M5: enrollment_data, selections, completion_tasks, offerte_status are
        // explicitly NOT read here. trajectory_id IS now read (task 1.4b) — but
        // only structured FK columns (trajectory_id, parent_registration_id,
        // edition_id) ever reach SQL; the JSON selections column is never read.
        // Only the keys consumed in this method ever reach SQL.

        $where  = [];
        $params = [];

        // Base corpus predicate — the grid is EDITION-GRAINED.
        // Trajectory PARENT rows carry trajectory_id SET + edition_id NULL; they
        // are NEVER a grid row in ANY scope (they live in TRAJ_PARENTS, surfaced
        // by the trajectory layer, not the edition grid). Interest rows — even on
        // dateless editions — ALWAYS carry a real edition_id, so this predicate
        // excludes only trajectory parents and never an interest/enrolment row.
        // (Fixes the leak where edition_id-NULL parents bypassed age/status scope,
        // and the grouped NULL-group `IN ('')` offerte loss — that group can no
        // longer form.)
        $where[] = 'r.edition_id IS NOT NULL';

        // Active-edition scope: the CALLER passes the resolved admin-active
        // edition-id set (EditionRepository::findAdminActiveIds via
        // AdminRegistrationQueryService — status-based, decision 2026-07-14).
        // Pinning to the id-set keeps ONE scope definition; this repo no longer
        // carries its own SQL twin of the predicate (the old start_date join
        // drifted from the counts' rule by construction). An EMPTY set yields
        // zero rows — a scope that resolves to nothing must never mean "all".
        $activeJoin = '';
        if (array_key_exists('active_edition_ids', $filters) && is_array($filters['active_edition_ids'])) {
            $this->pinToIdSet('r.edition_id', $filters['active_edition_ids'], $where, $params);
        }

        // Trajectory scope (task 1.4b) — routes through the verified parent→child
        // join shape (mirror of findEditionsByTrajectory), NOT a bare
        // `WHERE trajectory_id = T` (which misses cascade children AND is a leak
        // risk — spec §669). The grid is trajectory-scoped, NOT user-scoped, so
        // the parent.user_id constraint is dropped: the grid spans all users.
        //
        // Catches BOTH:
        //  - cascade children: r.parent_registration_id points at a T parent row,
        //  - legacy pre-cascade children: r.trajectory_id = T directly.
        // The base `r.edition_id IS NOT NULL` keeps the trajectory PARENT itself
        // out (parents carry edition_id NULL), so this returns edition-grained
        // child rows only — never the parent, never another trajectory's rows.
        //
        // PARAM ORDER (M4): the JOIN's %d is consumed in SQL text BEFORE any
        // WHERE param (joins precede WHERE). The active-scope JOIN has no
        // placeholder (its %s is a WHERE param). So the trajectory JOIN param is
        // unshifted to the FRONT of $params; the WHERE-disjunct param is appended
        // in WHERE order. Both callers concatenate this join string into every
        // SQL statement via {$activeJoin}, so both paths inherit the filter.
        // mirror of findChildRegistrationIdsByTrajectory() — the two trajectory
        // parent->child join sites MUST stay equivalent (§676 sibling-audit). This
        // inline copy is intentionally NOT refactored to call that method: the
        // array_unshift param-ordering below is load-bearing here (the JOIN's %d
        // must precede the active-scope WHERE %s), whereas the standalone method
        // owns its whole SQL and binds in natural order.
        if ($trajectoryId !== null) {
            $activeJoin .= " LEFT JOIN {$wpdb->prefix}vad_registrations traj_parent
                ON traj_parent.id = r.parent_registration_id
                AND traj_parent.trajectory_id = %d
                AND traj_parent.edition_id IS NULL";
            array_unshift($params, $trajectoryId);

            $where[]  = '(r.trajectory_id = %d OR traj_parent.id IS NOT NULL)';
            $params[] = $trajectoryId;
        }

        if ($editionId !== null) {
            $where[]  = 'r.edition_id = %d';
            $params[] = $editionId;
        }

        if ($companyId !== null) {
            $where[]  = 'r.company_id = %d';
            $params[] = $companyId;
        }

        if ($statusValue !== null) {
            $where[]  = 'r.status = %s';
            $params[] = $statusValue;
        }

        if ($q !== null) {
            // Name search — bound as a prepared LIKE, never interpolated (M4).
            // lead_name/lead_email are the DENORMALIZED anonymous-lead identity
            // columns (schema v5) — searching a lead's own name/email used to
            // return "Geen resultaten" because it only lives in enrollment_data
            // JSON, which is deliberately never LIKEd (M5).
            $where[]   = '(u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s'
                . ' OR r.lead_name LIKE %s OR r.lead_email LIKE %s)';
            $likeTerm  = '%' . $wpdb->esc_like($q) . '%';
            $params[]  = $likeTerm;
            $params[]  = $likeTerm;
            $params[]  = $likeTerm;
            $params[]  = $likeTerm;
            $params[]  = $likeTerm;
        }

        // Worklist-queue pin (?queue=): the resolver's id-set IS the queue
        // definition (WorklistQueueResolver — the same set the Vandaag card
        // counted). Ids are server-resolved ints (never client input).
        if (array_key_exists('queue_ids', $filters) && is_array($filters['queue_ids'])) {
            $this->pinToIdSet('r.id', $filters['queue_ids'], $where, $params);
        }

        $whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        return [
            'active_join'  => $activeJoin,
            'where_clause' => $whereClause,
            'params'       => $params,
            'page'         => $page,
            'per_page'     => $perPage,
        ];
    }

    /**
     * Pin a WHERE clause to a server-resolved id-set — the ONE home of the
     * load-bearing invariant every pin shares: an EMPTY resolved set must
     * yield ZERO rows ('1 = 0'), never "no filter" (a scope that resolves to
     * nothing meaning "all" is the leak class both call sites exist to
     * prevent). Ids are always server-resolved ints, bound via %d (M4);
     * $column is a caller-owned literal, never user input.
     *
     * @param string     $column  SQL column literal (e.g. 'r.id').
     * @param array      $ids     Server-resolved id-set.
     * @param list<string> $where  WHERE fragments (appended to).
     * @param list<mixed>  $params Bound params (appended to, in WHERE order).
     */
    private function pinToIdSet(string $column, array $ids, array &$where, array &$params): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            $where[] = '1 = 0';
            return;
        }
        $where[] = "{$column} IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')';
        $params  = array_merge($params, $ids);
    }

    // === Legacy aliases ===

    /** @deprecated Use existsForEdition() */
    public function exists(int $userId, int $editionId): bool
    {
        return $this->existsForEdition($userId, $editionId);
    }

}
