<?php

declare(strict_types=1);

namespace Stride\Modules\User;

/**
 * User → company link.
 *
 * Single home for the `_stride_company_id` user-meta key. Centralizes
 * read/write so renaming or extending the affiliation model (e.g. multi-
 * company users) is a one-file change.
 */
final class CompanyAffiliation
{
    public const META_KEY = '_stride_company_id';

    public static function getCompanyId(int $userId): int
    {
        return (int) get_user_meta($userId, self::META_KEY, true);
    }

    public static function setCompanyId(int $userId, int $companyId): bool
    {
        return (bool) update_user_meta($userId, self::META_KEY, $companyId);
    }
}
