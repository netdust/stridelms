<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Modules\User;

use Stride\Modules\User\CompanyAffiliation;
use Stride\Tests\TestCase;

/**
 * BULK-1 / Task 4A.1 — the company-existence reader on the affiliation home.
 *
 * Since there is NO Company CPT, a "company" is just a company_id that at least
 * one user carries in `_stride_company_id` usermeta. CompanyAffiliation OWNS
 * that meta key (INV-3), so it is the convergence home for the question
 * "is this a real company scope" — beside META_KEY, not inline in a handler.
 *
 * companyExists($id) returns true iff $id > 0 AND at least one user carries that
 * value. This is the predicate the bulk set-field write is gated on (mitigation
 * 7 / BULK-1): a value that no user carries is NOT a real scope and must not be
 * writable onto a registration.
 *
 * The $wpdb stub's get_col() resolves the DISTINCT `_stride_company_id` set from
 * $_test_user_meta, so companyExists() is consistent with get_user_meta() here.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Unit --filter CompanyAffiliation
 */
final class CompanyAffiliationTest extends TestCase
{
    /** A company_id carried by at least one user IS a real scope → true. */
    public function test_company_exists_true_for_id_carried_by_a_user(): void
    {
        update_user_meta(501, CompanyAffiliation::META_KEY, 42);

        $this->assertTrue(CompanyAffiliation::companyExists(42));
    }

    /** DENIAL: a company_id carried by NOBODY is not a real scope → false. */
    public function test_company_exists_false_for_id_carried_by_nobody(): void
    {
        update_user_meta(501, CompanyAffiliation::META_KEY, 42);

        // 999 is carried by no user — not a real company scope.
        $this->assertFalse(CompanyAffiliation::companyExists(999));
    }

    /** Boundary: 0 is never a real scope (it means "no company"). */
    public function test_company_exists_false_for_zero(): void
    {
        update_user_meta(501, CompanyAffiliation::META_KEY, 42);

        $this->assertFalse(CompanyAffiliation::companyExists(0));
    }

    /** Boundary: a negative id is never a real scope. */
    public function test_company_exists_false_for_negative(): void
    {
        update_user_meta(501, CompanyAffiliation::META_KEY, 42);

        $this->assertFalse(CompanyAffiliation::companyExists(-7));
    }

    /** Empty affiliation store: no company exists at all. */
    public function test_company_exists_false_when_no_user_carries_any_company(): void
    {
        $this->assertFalse(CompanyAffiliation::companyExists(42));
    }

    /** The known-company set is the DISTINCT non-empty carried values. */
    public function test_known_company_ids_are_distinct_non_empty_values(): void
    {
        update_user_meta(501, CompanyAffiliation::META_KEY, 42);
        update_user_meta(502, CompanyAffiliation::META_KEY, 42); // same company
        update_user_meta(503, CompanyAffiliation::META_KEY, 7);
        update_user_meta(504, CompanyAffiliation::META_KEY, 0);  // "no company" — excluded

        $ids = CompanyAffiliation::getKnownCompanyIds();
        sort($ids);

        $this->assertSame([7, 42], $ids);
    }
}
