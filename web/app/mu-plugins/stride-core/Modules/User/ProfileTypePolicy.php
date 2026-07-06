<?php

declare(strict_types=1);

namespace Stride\Modules\User;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Trajectory\TrajectoryRepository;

/**
 * Enroll-time profile-type policy (plain DI class — NOT a service, no boot hooks).
 *
 * Single convergence point for "for this user's profile type + this enrollable,
 * does enrollment block / use minimal form / auto-apply a voucher?" — read at the
 * enroll seam and the form resolver. No surface re-derives it from raw meta.
 *
 * Rules map shape (per enrollable, keyed on profile-type slug):
 *   { "<slug>": { "block": bool, "minimal": bool, "voucher": "<code>|null" }, ... }
 * Resolution keys on the USER'S STORED type slug (via currentUserType). Fail-open:
 * no rule / no user / deleted type ⇒ not blocked, full form, no voucher.
 *
 * ⚠️ SIGNATURE SHELL (test-author, RED phase). The four method bodies are
 * sentinels so the T2 contract test fails BEHAVIORALLY, not "class not found".
 * The implementer (T2-green) fills these bodies with the real resolution logic
 * WITHOUT editing or weakening the handed-over test. Do NOT register in
 * plugin-config.php here — that is the implementer's wiring.
 */
final class ProfileTypePolicy
{
    public function __construct(
        private readonly ProfileTypeService $profileTypes,
        private readonly EditionRepository $editions,
        private readonly TrajectoryRepository $trajectories,
    ) {
    }

    /**
     * Fail-open: no rule for the user's type ⇒ false.
     * $postType routes the rules read: 'vad_edition' | 'vad_trajectory'.
     */
    public function blocksEnrollment(?int $userId, int $enrollableId, string $postType): bool
    {
        throw new \LogicException('ProfileTypePolicy::blocksEnrollment not implemented');
    }

    /** No rule ⇒ false (full form). */
    public function usesMinimalForm(?int $userId, int $enrollableId, string $postType): bool
    {
        throw new \LogicException('ProfileTypePolicy::usesMinimalForm not implemented');
    }

    /** No rule ⇒ null (no auto-voucher). */
    public function autoVoucherCode(?int $userId, int $enrollableId, string $postType): ?string
    {
        throw new \LogicException('ProfileTypePolicy::autoVoucherCode not implemented');
    }

    /** Slug of the user's stored primary profile type, or null (no/deleted type). */
    public function currentUserType(?int $userId): ?string
    {
        throw new \LogicException('ProfileTypePolicy::currentUserType not implemented');
    }
}
