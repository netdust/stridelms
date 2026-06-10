<?php

declare(strict_types=1);

namespace Stride\Modules\Trajectory;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;
use WP_Error;

/**
 * Cascade-enrollment coordinator for trajectories.
 *
 * Given a parent trajectory-registration, this service materialises the
 * matching child edition-registrations (or pure-LD course grants) and tears
 * them down on cancellation. It is a pure coordinator: no hooks, no boot-time
 * side-effects. Resolved via DI autowiring from callers in
 * `TrajectorySelection` and `EnrollmentService`.
 *
 * Parent row shape: `trajectory_id` set, `edition_id` NULL,
 *   `parent_registration_id` NULL, `enrollment_path = trajectory`.
 * Child row shape:  `trajectory_id` NULL, `edition_id` set,
 *   `parent_registration_id = <parent.id>`, `enrollment_path = trajectory`.
 *
 * Pure-LD courses (no edition) are NOT child rows — they live in user-meta
 * `_stride_trajectory_courses` so LD access can be granted/revoked.
 *
 * Status cascade is mode-aware: `cohort` trajectories propagate parent
 * cancellation + status changes to children; `self_paced` does not.
 *
 * See `plans/2026-05-20-trajectory-cascade-enrollment.md` for the full
 * contract and decision log.
 */
final class TrajectoryCascadeService
{
    public const TRAJECTORY_COURSES_META_KEY = '_stride_trajectory_courses';

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly TrajectoryRepository $trajectories,
        private readonly EditionService $editions,
        private readonly LMSAdapterInterface $lms,
        private readonly QuoteService $quotes,
    ) {}

    /**
     * Cascade on initial trajectory enrollment.
     *
     * Walks the trajectory's required courses:
     *  - Course has a linked edition → create a child registration on that edition.
     *  - Course has no edition (pure LD) → grant LD access + append to user-meta.
     *
     * Electives are NOT handled here — see `cascadeOnSelection()`. Idempotent:
     * re-running for the same parent skips rows that already exist.
     */
    public function cascadeOnEnrollment(int $parentRegistrationId): void
    {
        $parent = $this->registrations->find($parentRegistrationId);
        if (!$parent || empty($parent->trajectory_id) || !empty($parent->edition_id)) {
            return;
        }

        $trajectoryId = (int) $parent->trajectory_id;
        $userId = (int) ($parent->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        $existingChildEditionIds = $this->existingChildEditionIds($parentRegistrationId);

        foreach ($this->trajectories->getRequiredCourses($trajectoryId) as $course) {
            $config = $course->trajectory_config ?? [];
            $type = $config['type'] ?? 'online';
            $courseId = (int) ($course->ID ?? $config['course_id'] ?? 0);
            $editionId = (int) ($config['edition_id'] ?? 0);

            // Pure-LD course (no edition) — grant LD access + record in user-meta.
            if ($type !== 'edition' || $editionId <= 0) {
                if ($courseId > 0) {
                    $this->grantPureLdAccess($userId, $courseId, $trajectoryId, $parentRegistrationId);
                }
                continue;
            }

            if (isset($existingChildEditionIds[$editionId])) {
                continue;
            }

            $childId = $this->createChildRegistration($parent, $editionId);
            if ($childId === null) {
                continue;
            }

            $existingChildEditionIds[$editionId] = true;
        }
    }

    /**
     * Cascade on elective-selection change.
     *
     * For each chosen edition: create a child registration if not already
     * present. Children for editions no longer in the selection are
     * cancelled (LD access revoked + any generated child-quote cancelled).
     *
     * Returns `WP_Error('edition_full', ...)` if any selected edition has no
     * remaining capacity — the selections JSON has already been saved at
     * the call site, but no child row is created for that edition; the
     * caller surfaces the error to the user. Children for other (non-full)
     * editions in the same call ARE created — partial-success is the right
     * default since the user picked a multi-course slate and we don't want
     * one full edition to block the rest.
     *
     * @param array<int> $editionIds Currently selected elective edition IDs.
     */
    public function cascadeOnSelection(int $parentRegistrationId, array $editionIds): WP_Error|true
    {
        $parent = $this->registrations->find($parentRegistrationId);
        if (!$parent || empty($parent->trajectory_id) || !empty($parent->edition_id)) {
            return true;
        }

        $userId = (int) ($parent->user_id ?? 0);
        if ($userId <= 0) {
            return true;
        }

        $editionIds = array_values(array_unique(array_map('intval', $editionIds)));

        $existingChildren = $this->registrations->findByParent($parentRegistrationId);

        // 1. Cancel children whose edition is no longer in the new selection.
        foreach ($existingChildren as $child) {
            $childEditionId = (int) ($child->edition_id ?? 0);
            $childStatus = RegistrationStatus::tryFrom((string) $child->status);
            if (
                $childEditionId > 0
                && !in_array($childEditionId, $editionIds, true)
                && $childStatus !== RegistrationStatus::Cancelled
            ) {
                $this->cancelChildSilently((int) $child->id, $childEditionId, $userId, (int) ($child->quote_id ?? 0));
            }
        }

        // 2. Index the active children we already have (after the cancellations
        //    above) so we don't create duplicates for editions kept in the slate.
        $existingActiveChildEditions = [];
        foreach ($this->registrations->findByParent($parentRegistrationId) as $child) {
            $childStatus = RegistrationStatus::tryFrom((string) $child->status);
            if ($childStatus !== RegistrationStatus::Cancelled) {
                $existingActiveChildEditions[(int) $child->edition_id] = true;
            }
        }

        // 3. Add children for newly selected editions, capacity-checked.
        $firstFullError = null;
        foreach ($editionIds as $editionId) {
            if ($editionId <= 0 || isset($existingActiveChildEditions[$editionId])) {
                continue;
            }

            $capacityError = $this->reserveEditionCapacityAndCreateChild($parent, $editionId);
            if (is_wp_error($capacityError) && $firstFullError === null) {
                $firstFullError = $capacityError;
            }
        }

        return $firstFullError ?? true;
    }

    /**
     * Cascade parent cancellation to children.
     *
     * `cohort` trajectories: bulk-cancel all children + revoke LD access for
     * pure-LD grants tied to this parent, then drop those meta entries.
     * `self_paced` trajectories: no-op — children stay enrolled. The user
     * picked these editions individually and may still want to attend them.
     *
     * Data-only on the child rows (no `stride/registration/cancelled` event
     * fired per child — the parent cancellation has already dispatched its
     * own event, and we don't want N child-cancel emails landing in the
     * user's inbox). LD access is revoked inline so the user actually loses
     * access to courses they've lost the row for.
     */
    public function cascadeOnCancellation(int $parentRegistrationId): void
    {
        $parent = $this->registrations->find($parentRegistrationId);
        if (!$parent || empty($parent->trajectory_id) || !empty($parent->edition_id)) {
            return;
        }

        $trajectoryId = (int) $parent->trajectory_id;
        $userId = (int) ($parent->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        if ($this->trajectoryMode($trajectoryId) !== TrajectoryMode::Cohort) {
            // Self-paced: cascade does not propagate parent cancellation.
            return;
        }

        // 1. Snapshot active children BEFORE the bulk update so we still have
        //    their edition_ids for the LD revoke loop below.
        $activeChildren = [];
        foreach ($this->registrations->findByParent($parentRegistrationId) as $child) {
            if (RegistrationStatus::tryFrom((string) $child->status) !== RegistrationStatus::Cancelled) {
                $activeChildren[] = $child;
            }
        }

        // 2. Bulk-cancel all child rows in one query.
        $this->registrations->cancelChildren($parentRegistrationId);

        // 3. Revoke LD access for each child whose edition has a linked course.
        foreach ($activeChildren as $child) {
            $editionId = (int) ($child->edition_id ?? 0);
            if ($editionId <= 0) {
                continue;
            }
            $courseId = $this->editions->getCourseId($editionId);
            if ($courseId > 0) {
                $this->lms->revokeAccess($userId, $courseId);
            }
        }

        // 4. Revoke pure-LD grants tied to this parent + drop those meta entries.
        $this->revokePureLdGrantsForParent($userId, $parentRegistrationId);
    }

    /**
     * Backfill cascade children for a single pre-existing trajectory parent.
     *
     * One-time migration helper: walks a parent's existing `selections` JSON
     * + required courses, and materialises the missing child rows + LD
     * grants. Used by the `wp stride trajectory backfill-cascade` command.
     *
     * Idempotent — calls `cascadeOnEnrollment` (skips already-existing
     * children) and `cascadeOnSelection` (which reactivates cancelled
     * children of this parent rather than duplicating). Safe to re-run.
     *
     * Returns a small report so the CLI can summarise. Capacity errors from
     * elective selections are NOT fatal — the run reports them and moves on.
     *
     * @return array{children_before: int, children_after: int, error: ?string}
     */
    public function backfillParent(int $parentRegistrationId): array
    {
        $parent = $this->registrations->find($parentRegistrationId);
        if (!$parent || empty($parent->trajectory_id) || !empty($parent->edition_id)) {
            return [
                'children_before' => 0,
                'children_after' => 0,
                'error' => 'not_a_trajectory_parent',
            ];
        }

        $before = count($this->registrations->findByParent($parentRegistrationId));

        $this->cascadeOnEnrollment($parentRegistrationId);

        $selections = is_array($parent->selections ?? null) ? $parent->selections : [];
        $selections = array_values(array_filter(array_map('intval', $selections)));
        $cascadeError = null;
        if (!empty($selections)) {
            $result = $this->cascadeOnSelection($parentRegistrationId, $selections);
            if (is_wp_error($result)) {
                $cascadeError = $result->get_error_code() . ': ' . $result->get_error_message();
            }
        }

        $after = count($this->registrations->findByParent($parentRegistrationId));

        return [
            'children_before' => $before,
            'children_after' => $after,
            'error' => $cascadeError,
        ];
    }

    /**
     * Cascade parent enrollment-status change to children.
     *
     * Cohort trajectories: each active child inherits the parent's new
     * enrollment status — same `wp_vad_registrations.status` column. Terminal
     * statuses on a child (`Cancelled`, `Completed`) are skipped: those are
     * sticky outcomes owned by admin or learning events, not by parent
     * state. Cancellation has its own dedicated path; passing `Cancelled`
     * here logs a warning and returns — callers should use
     * `cascadeOnCancellation()` so the pure-LD meta cleanup runs too.
     *
     * Self-paced trajectories: no-op. Children manage their own enrollment
     * state independently.
     *
     * LD access side-effect: when a child transitions INTO an access-bearing
     * status (Confirmed, Completed) the LD grant is issued; when it
     * transitions OUT, access is revoked. Driven by
     * `RegistrationStatus::hasAccess()` so both directions stay symmetric.
     */
    public function cascadeOnStatusChange(int $parentRegistrationId, string $newStatus): void
    {
        $target = RegistrationStatus::tryFrom($newStatus);
        if ($target === null) {
            ntdst_log('enrollment')->warning('Cascade: status change with unknown status', [
                'parent_registration_id' => $parentRegistrationId,
                'new_status' => $newStatus,
            ]);
            return;
        }

        if ($target === RegistrationStatus::Cancelled) {
            ntdst_log('enrollment')->warning('Cascade: status change called with Cancelled — use cascadeOnCancellation() instead', [
                'parent_registration_id' => $parentRegistrationId,
            ]);
            return;
        }

        $parent = $this->registrations->find($parentRegistrationId);
        if (!$parent || empty($parent->trajectory_id) || !empty($parent->edition_id)) {
            return;
        }

        $trajectoryId = (int) $parent->trajectory_id;
        $userId = (int) ($parent->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        if ($this->trajectoryMode($trajectoryId) !== TrajectoryMode::Cohort) {
            return;
        }

        foreach ($this->registrations->findByParent($parentRegistrationId) as $child) {
            $childStatus = RegistrationStatus::tryFrom((string) $child->status);
            if (
                $childStatus === RegistrationStatus::Cancelled
                || $childStatus === RegistrationStatus::Completed
            ) {
                continue;
            }
            if ($childStatus === $target) {
                continue;
            }

            $updated = $this->registrations->updateStatus((int) $child->id, $target);
            if (!$updated) {
                ntdst_log('enrollment')->warning('Cascade: failed to update child status', [
                    'parent_registration_id' => $parentRegistrationId,
                    'child_registration_id' => (int) $child->id,
                    'from' => $childStatus?->value,
                    'to' => $target->value,
                ]);
                continue;
            }

            $hadAccess = $childStatus !== null && $childStatus->hasAccess();
            $hasAccess = $target->hasAccess();
            if ($hadAccess === $hasAccess) {
                continue;
            }

            $courseId = $this->editions->getCourseId((int) $child->edition_id);
            if ($courseId <= 0) {
                continue;
            }
            if ($hasAccess) {
                $this->lms->grantAccess($userId, $courseId);
            } else {
                $this->lms->revokeAccess($userId, $courseId);
            }
        }
    }

    /**
     * Grant LD access for a pure-LD course in a trajectory and record the
     * grant in user-meta so `cascadeOnCancellation` can revoke it later.
     *
     * Idempotent: if a meta entry for this (course, parent) already exists,
     * does nothing. If `grantAccess()` returns false (LMS unavailable or
     * invalid course), logs a warning and does NOT record the meta entry —
     * the meta must reflect successful grants only.
     */
    private function grantPureLdAccess(int $userId, int $courseId, int $trajectoryId, int $parentRegistrationId): void
    {
        $entries = $this->readTrajectoryCoursesMeta($userId);

        foreach ($entries as $entry) {
            if (
                (int) ($entry['course_id'] ?? 0) === $courseId
                && (int) ($entry['parent_registration_id'] ?? 0) === $parentRegistrationId
            ) {
                return;
            }
        }

        if (!$this->lms->grantAccess($userId, $courseId)) {
            ntdst_log('enrollment')->warning('Cascade: pure-LD grantAccess returned false', [
                'parent_registration_id' => $parentRegistrationId,
                'trajectory_id' => $trajectoryId,
                'user_id' => $userId,
                'course_id' => $courseId,
            ]);
            return;
        }

        $entries[] = [
            'course_id' => $courseId,
            'trajectory_id' => $trajectoryId,
            'parent_registration_id' => $parentRegistrationId,
            'granted_at' => current_time('mysql'),
        ];

        update_user_meta($userId, self::TRAJECTORY_COURSES_META_KEY, $entries);
    }

    /**
     * Read the `_stride_trajectory_courses` user-meta as a sane array.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readTrajectoryCoursesMeta(int $userId): array
    {
        $raw = get_user_meta($userId, self::TRAJECTORY_COURSES_META_KEY, true);
        return is_array($raw) ? array_values($raw) : [];
    }

    /**
     * Resolve a trajectory's mode, defaulting to Cohort when the meta is
     * absent or invalid. Cohort is the safer default for cascade — if a
     * trajectory's mode is unset, we assume the admin meant the more
     * restrictive behaviour (cancellations propagate, status changes
     * propagate). Self-paced is an explicit opt-in.
     */
    private function trajectoryMode(int $trajectoryId): TrajectoryMode
    {
        $raw = (string) $this->trajectories->getField($trajectoryId, 'mode', TrajectoryMode::Cohort->value);
        return TrajectoryMode::tryFrom($raw) ?? TrajectoryMode::Cohort;
    }

    /**
     * Revoke LD access for every pure-LD grant recorded under this parent and
     * remove those entries from `_stride_trajectory_courses` user-meta.
     *
     * Other parents' entries (different trajectory enrollment, separately
     * granted) are preserved. If the meta ends up empty, the key is deleted
     * outright rather than left as an empty array.
     */
    private function revokePureLdGrantsForParent(int $userId, int $parentRegistrationId): void
    {
        $entries = $this->readTrajectoryCoursesMeta($userId);
        if (empty($entries)) {
            return;
        }

        $remaining = [];
        foreach ($entries as $entry) {
            if ((int) ($entry['parent_registration_id'] ?? 0) === $parentRegistrationId) {
                $courseId = (int) ($entry['course_id'] ?? 0);
                if ($courseId > 0) {
                    $this->lms->revokeAccess($userId, $courseId);
                }
                continue;
            }
            $remaining[] = $entry;
        }

        if (empty($remaining)) {
            delete_user_meta($userId, self::TRAJECTORY_COURSES_META_KEY);
            return;
        }

        update_user_meta($userId, self::TRAJECTORY_COURSES_META_KEY, $remaining);
    }

    /**
     * Create a cascade child registration on the given edition and grant LD
     * access if the inherited status is Confirmed. Generates an auto-quote
     * when the trajectory is free but the edition is paid.
     *
     * Used by both cascadeOnEnrollment (mandatory editions) and
     * reserveEditionCapacityAndCreateChild (elective selection). Skips when
     * the user already has any registration on this edition from another path.
     *
     * @return int|null Child registration id on success, null otherwise.
     */
    private function createChildRegistration(object $parent, int $editionId): ?int
    {
        $userId = (int) ($parent->user_id ?? 0);
        $parentId = (int) ($parent->id ?? 0);

        $status = (string) ($parent->status ?? RegistrationStatus::Confirmed->value);

        $existing = $this->registrations->findByUserAndEdition($userId, $editionId);
        if ($existing !== null) {
            $existingParent = (int) ($existing->parent_registration_id ?? 0);
            $existingStatus = (string) ($existing->status ?? '');

            // Reactivate a previously cancelled child of THIS parent — happens
            // when the user removed an elective from their selection and then
            // re-added it. New cancelled_at is cleared, status inherits.
            if (
                $existingParent === $parentId
                && $existingStatus === RegistrationStatus::Cancelled->value
            ) {
                $reactivated = $this->registrations->update((int) $existing->id, [
                    'status' => $status,
                    'cancelled_at' => null,
                ]);
                if (!$reactivated) {
                    ntdst_log('enrollment')->warning('Cascade: failed to reactivate cancelled child', [
                        'parent_registration_id' => $parentId,
                        'child_registration_id' => (int) $existing->id,
                    ]);
                    return null;
                }
                if ($status === RegistrationStatus::Confirmed->value) {
                    $courseId = $this->editions->getCourseId($editionId);
                    if ($courseId > 0) {
                        $this->lms->grantAccess($userId, $courseId);
                    }
                }
                $this->maybeCreateChildQuote((int) $existing->id, $parent, $editionId);
                return (int) $existing->id;
            }

            ntdst_log('enrollment')->info('Cascade: user already on edition, skipping child creation', [
                'parent_registration_id' => $parentId,
                'user_id' => $userId,
                'edition_id' => $editionId,
                'existing_registration_id' => (int) $existing->id,
            ]);
            return null;
        }

        $childId = $this->registrations->create([
            'user_id' => $userId,
            'edition_id' => $editionId,
            'parent_registration_id' => $parentId,
            'company_id' => isset($parent->company_id) ? (int) $parent->company_id : null,
            'enrolled_by' => isset($parent->enrolled_by) ? (int) $parent->enrolled_by : null,
            'status' => $status,
            'enrollment_path' => RegistrationRepository::PATH_TRAJECTORY,
        ]);

        if (is_wp_error($childId)) {
            ntdst_log('enrollment')->warning('Cascade: failed to create child registration', [
                'parent_registration_id' => $parentId,
                'edition_id' => $editionId,
                'error' => $childId->get_error_code(),
                'message' => $childId->get_error_message(),
            ]);
            return null;
        }

        if ($status === RegistrationStatus::Confirmed->value) {
            $courseId = $this->editions->getCourseId($editionId);
            if ($courseId > 0) {
                $this->lms->grantAccess($userId, $courseId);
            }
        }

        $this->maybeCreateChildQuote($childId, $parent, $editionId);

        return $childId;
    }

    /**
     * Atomically check edition capacity and create the child row when there's
     * room. Wraps the same FOR UPDATE pattern that EnrollmentService::enroll()
     * uses so two parallel selections can't both grab the last spot.
     *
     * Returns `WP_Error('edition_full', ...)` when the edition is full at
     * lock time; the caller surfaces this to the user. Other failures (DB
     * errors, duplicate row) are logged and reported as `null` to keep
     * cascadeOnSelection's "partial success" semantics — we never abort the
     * whole selection over one bad edition.
     */
    private function reserveEditionCapacityAndCreateChild(object $parent, int $editionId): WP_Error|int|null
    {
        global $wpdb;

        $wpdb->query('START TRANSACTION');

        try {
            $confirmedCount = $this->registrations->countConfirmedForUpdate($editionId);
            $capacity = $this->editions->getCapacity($editionId);

            if ($capacity > 0 && $confirmedCount >= $capacity) {
                $wpdb->query('ROLLBACK');
                ntdst_log('enrollment')->warning('Cascade: edition full, skipping child creation', [
                    'parent_registration_id' => (int) ($parent->id ?? 0),
                    'edition_id' => $editionId,
                    'capacity' => $capacity,
                    'confirmed' => $confirmedCount,
                ]);
                return new WP_Error(
                    'edition_full',
                    sprintf(__('Editie %d is volzet', 'stride'), $editionId),
                    ['edition_id' => $editionId],
                );
            }

            $childId = $this->createChildRegistration($parent, $editionId);
            $wpdb->query('COMMIT');

            return $childId;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Cancel a child registration as part of a selection re-edit. Data-only on
     * the registration row (matches RegistrationRepository::cancel() / cancelChildren())
     * but also revokes LD access and cancels any cascade-generated child quote,
     * since no `stride/registration/cancelled` event is dispatched.
     *
     * Silent — does NOT fire registration/cancelled. The user is mid-edit, not
     * mid-withdrawal, so listeners (mail, audit, FluentCRM) should not run.
     */
    private function cancelChildSilently(int $childRegistrationId, int $editionId, int $userId, int $quoteId): void
    {
        $cancelled = $this->registrations->updateStatus($childRegistrationId, RegistrationStatus::Cancelled);
        if (!$cancelled) {
            ntdst_log('enrollment')->error('Cascade: failed to cancel removed child', [
                'child_registration_id' => $childRegistrationId,
                'edition_id' => $editionId,
            ]);
            return;
        }

        $courseId = $this->editions->getCourseId($editionId);
        if ($courseId > 0) {
            $this->lms->revokeAccess($userId, $courseId);
        }

        if ($quoteId > 0) {
            $this->cancelChildQuote($quoteId);
        }
    }

    /**
     * Auto-generate a child quote when the parent trajectory is free but the
     * child edition is paid.
     *
     * Default behaviour for cascade-created children is `quote_id = NULL` —
     * the parent trajectory's quote is the source of truth for billing.
     * Exception: when trajectory price is €0 AND the child edition price is
     * > €0, we create a per-child quote inheriting the parent's billing
     * info, since there's no parent quote to bill against.
     *
     * Never blocks enrollment — the child row exists regardless of quote
     * outcome. Quote creation failures are logged at warning level.
     */
    private function maybeCreateChildQuote(int $childRegistrationId, object $parent, int $editionId): void
    {
        $trajectoryId = (int) ($parent->trajectory_id ?? 0);
        if ($trajectoryId <= 0) {
            return;
        }

        $trajectoryPrice = (float) $this->trajectories->getField($trajectoryId, 'price', 0);
        if ($trajectoryPrice > 0) {
            // Parent trajectory quote covers the child — no separate billing.
            return;
        }

        $userId = (int) ($parent->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        $editionPrice = $this->editions->getPrice($editionId, $userId);
        if ($editionPrice->isZero()) {
            return;
        }

        $billing = $this->resolveBillingForChildQuote($parent);

        $items = [
            [
                'title' => get_the_title($editionId) ?: sprintf('Editie #%d', $editionId),
                'quantity' => 1,
                'unit_price' => $editionPrice,
                'type' => 'edition',
            ],
        ];

        $quoteId = $this->quotes->createQuote(
            userId: $userId,
            registrationId: $childRegistrationId,
            editionId: $editionId,
            items: $items,
            billing: $billing,
        );

        if (is_wp_error($quoteId)) {
            ntdst_log('enrollment')->warning('Cascade: child quote creation failed', [
                'child_registration_id' => $childRegistrationId,
                'edition_id' => $editionId,
                'error' => $quoteId->get_error_code(),
                'message' => $quoteId->get_error_message(),
            ]);
            return;
        }

        $this->registrations->update($childRegistrationId, ['quote_id' => $quoteId]);
    }

    /**
     * Cancel a cascade-generated child quote when its child row is being
     * cancelled mid-selection-edit. `QuoteService::cancel()` already rejects
     * exported quotes and releases any attached voucher, so we just call it
     * and ignore the WP_Error for terminal-state quotes — those are admin's
     * responsibility, not the cascade's.
     */
    private function cancelChildQuote(int $quoteId): void
    {
        $this->quotes->cancel($quoteId);
    }

    /**
     * Build billing info for a cascade-generated child quote.
     *
     * Preference order:
     *  1. Parent's quote billing snapshot (most accurate at the moment of
     *     trajectory enrollment).
     *  2. The user's billing meta as a fallback (correct in the common case
     *     where the trajectory had no associated quote because it was free).
     *
     * @return array<string, string>
     */
    private function resolveBillingForChildQuote(object $parent): array
    {
        $parentQuoteId = (int) ($parent->quote_id ?? 0);
        if ($parentQuoteId > 0) {
            $quote = $this->quotes->getQuote($parentQuoteId);
            if (!is_wp_error($quote) && !empty($quote['billing']) && is_array($quote['billing'])) {
                return $quote['billing'];
            }
        }

        $userId = (int) ($parent->user_id ?? 0);
        if ($userId <= 0) {
            return [];
        }

        $user = get_userdata($userId);
        if (!$user) {
            return [];
        }

        return [
            'name' => $user->display_name,
            'email' => get_user_meta($userId, 'invoice_email', true) ?: $user->user_email,
            'company' => get_user_meta($userId, 'billing_company', true) ?: '',
            'address' => get_user_meta($userId, 'billing_address_1', true) ?: '',
            'postal_code' => get_user_meta($userId, 'billing_postcode', true) ?: '',
            'city' => get_user_meta($userId, 'billing_city', true) ?: '',
            'vat_number' => get_user_meta($userId, 'billing_vat', true) ?: '',
            'gln_number' => get_user_meta($userId, 'gln_number', true) ?: '',
        ];
    }

    /**
     * Index existing children of a parent by edition_id for fast idempotency
     * checks. Cancelled children are included — re-enrolling a user into the
     * same trajectory after their cohort cancelled them would otherwise
     * double-row. The reactivation path on `RegistrationRepository::create()`
     * handles refresh of cancelled rows when needed.
     *
     * @return array<int, true> Map of edition_id => true
     */
    private function existingChildEditionIds(int $parentRegistrationId): array
    {
        $children = $this->registrations->findByParent($parentRegistrationId);
        $index = [];
        foreach ($children as $child) {
            $editionId = (int) ($child->edition_id ?? 0);
            if ($editionId > 0) {
                $index[$editionId] = true;
            }
        }
        return $index;
    }
}
