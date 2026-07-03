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

    /**
     * Is $companyId a real company scope?
     *
     * There is no Company CPT — a "company" is a company_id that at least one
     * user carries in `_stride_company_id` usermeta. This is the convergence
     * home for that question (BULK-1 / mitigation 7): the bulk set-field write
     * is gated on it so a bad company_id can never move a registration into a
     * partner's `findByCompany` scope (INV-1). `0`/negative is never a scope.
     */
    public static function companyExists(int $companyId): bool
    {
        if ($companyId <= 0) {
            return false;
        }

        return in_array($companyId, self::getKnownCompanyIds(), true);
    }

    /**
     * The DISTINCT set of company ids currently carried by ≥1 user.
     *
     * INV-3: the usermeta read lives HERE (the `_stride_company_id` owner), not
     * inline in a handler. The set is small and rarely changes; a fresh read is
     * cheap and always correct (no cache-invalidation footgun for v1).
     *
     * @return int[]
     */
    public static function getKnownCompanyIds(): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT meta_value FROM {$wpdb->usermeta}
                 WHERE meta_key = %s AND meta_value <> '' AND meta_value > 0",
                self::META_KEY,
            ),
        );

        return array_values(array_filter(array_map('intval', (array) $rows), static fn(int $id): bool => $id > 0));
    }
}
