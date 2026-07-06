<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Trajectory\TrajectoryRepository;

/**
 * Enroll-time profile-type policy (plain DI collaborator — NOT an NTDST service:
 * inert constructor, no boot hooks). Resolved on demand via the container
 * (registered alongside the repositories in stride-core.php's ntdst/core_ready
 * block), never eagerly booted.
 *
 * INV-12 convergence point: the single place "for this user's profile type +
 * this enrollable, does enrollment block / use the minimal form / auto-apply a
 * voucher?" is decided. Consulted at the enroll seams (T4 enroll-time denial,
 * T7–T9 form resolver / voucher application). No surface re-derives this from
 * raw meta — they all route through this class.
 *
 * Rules map shape (per enrollable, keyed on profile-type slug):
 *   { "<slug>": { "block": bool, "minimal": bool, "voucher": "<code>|null" }, ... }
 * Resolution keys on the USER'S STORED type slug (via currentUserType), and the
 * $postType routes which repository supplies the rules map.
 *
 * Fail-open is deliberate everywhere: no rule for the user's type / no user /
 * deleted type / unknown postType / corrupt (non-array) rule row ⇒ not blocked,
 * full form, no voucher. Enrollment is never denied by missing or malformed
 * policy data — only by an explicit `block: true` on the user's resolved type.
 */
final class ProfileTypePolicy
{
    public function __construct(
        private readonly ProfileTypeService $profileTypes,
        private readonly EditionRepository $editions,
        private readonly TrajectoryRepository $trajectories,
    ) {}

    /**
     * Fail-open: no rule for the user's type ⇒ false.
     * $postType routes the rules read: 'vad_edition' | 'vad_trajectory'.
     */
    public function blocksEnrollment(?int $userId, int $enrollableId, string $postType): bool
    {
        return (bool) ($this->resolveRule($userId, $enrollableId, $postType)['block'] ?? false);
    }

    /** No rule ⇒ false (full form). */
    public function usesMinimalForm(?int $userId, int $enrollableId, string $postType): bool
    {
        return (bool) ($this->resolveRule($userId, $enrollableId, $postType)['minimal'] ?? false);
    }

    /** No rule ⇒ null (no auto-voucher). Empty voucher string ⇒ null. */
    public function autoVoucherCode(?int $userId, int $enrollableId, string $postType): ?string
    {
        $code = $this->resolveRule($userId, $enrollableId, $postType)['voucher'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /** Slug of the user's stored primary profile type, or null (no/deleted type). */
    public function currentUserType(?int $userId): ?string
    {
        // Short-circuit: getUserType() takes a non-nullable int; never call getUserType(0).
        if ($userId === null || $userId === 0) {
            return null;
        }

        $type = $this->profileTypes->getUserType($userId);

        return $type['slug'] ?? null;
    }

    /**
     * Resolve the single rule row for this user's stored type against this enrollable.
     * Returns the `{block, minimal, voucher}` entry, or an empty array when the user
     * has no/deleted type OR the postType-routed rules map has no row for that slug.
     *
     * @return array{block?: bool, minimal?: bool, voucher?: string|null}
     */
    private function resolveRule(?int $userId, int $enrollableId, string $postType): array
    {
        $slug = $this->currentUserType($userId);

        if ($slug === null) {
            return [];
        }

        // Explicit postType routing. The default arm is an INTENTIONAL fail-open
        // (empty rules ⇒ not blocked / full form / no voucher) for any postType
        // that is not an enrollable — never a silent fall-through to the edition
        // repo. NOTE: resolveRule is called once per public method; the enforcing
        // callers (T4/T7) resolve once and reuse — no memoization needed here.
        $rules = match ($postType) {
            'vad_trajectory' => $this->trajectories->getProfiletypeRules($enrollableId),
            'vad_edition'    => $this->editions->getProfiletypeRules($enrollableId),
            default          => [],
        };

        $row = $rules[$slug] ?? [];

        // Corrupt data fail-open: a rule row that is not the expected
        // {block, minimal, voucher} array (e.g. a scalar from malformed stored
        // JSON) must not fatal or be truthily coerced — treat it as "no rule".
        return is_array($row) ? $row : [];
    }
}
