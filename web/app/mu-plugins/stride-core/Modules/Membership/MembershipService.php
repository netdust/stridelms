<?php

declare(strict_types=1);

namespace Stride\Modules\Membership;

/**
 * Membership Service — single source of truth for "is user a member?"
 *
 * v1 has no membership UI, no way to onboard members, no pricing tier.
 * `isMember()` therefore always returns false unless a host theme/plugin
 * hooks into `stride/membership/is_member` to override.
 *
 * When a real member feature is built, this service grows three things:
 *   - a real `setMember(int $userId, bool $isMember)` write path
 *   - admin UI (separate plugin recommended — see Mail/Audit bridge pattern)
 *   - a membership filter on price routing
 *
 * Until then, this service centralises the read path so callers stop
 * looking up `is_vad_member` user meta directly.
 */
final class MembershipService implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name'        => 'Membership Service',
            'description' => 'Single source of truth for membership state',
            'priority'    => 4, // before EditionService (priority 5)
        ];
    }

    public function __construct()
    {
        // No init hooks — pure service.
    }

    /**
     * Is the given user a member?
     *
     * v1: always false. Override via `stride/membership/is_member` filter.
     */
    public function isMember(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return (bool) apply_filters('stride/membership/is_member', false, $userId);
    }
}
