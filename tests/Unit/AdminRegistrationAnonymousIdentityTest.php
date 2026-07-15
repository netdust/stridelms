<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use ReflectionMethod;
use Stride\Admin\AdminRegistrationQueryService;
use Stride\Tests\TestCase;

/**
 * Unit tests for AdminRegistrationQueryService::resolveAnonymousIdentity.
 *
 * Anonymous (not-logged-in) interest/waitlist submissions create a
 * wp_vad_registrations row with user_id = NULL/0. Their identity is read
 * from the DENORMALIZED lead_name/lead_email columns (schema v5) — stamped
 * on every write path and backfilled by the v5 migration via ONE extractor
 * (RegistrationRepository::extractLeadIdentity, pinned by
 * ExtractLeadIdentityTest). The grid therefore renders the SAME identity the
 * grid search matches: the former per-row enrollment_data decode keyed on the
 * row's CURRENT status, so a lead captured at the interest stage then moved
 * to waitlist rendered '(anoniem)' while search found the row by lead_name.
 *
 * Run: ddev exec vendor/bin/phpunit --testsuite Unit --filter AdminRegistrationAnonymousIdentity
 */
final class AdminRegistrationAnonymousIdentityTest extends TestCase
{
    /**
     * @return array{name:string,email:string}
     */
    private function invoke(object $row): array
    {
        // resolveAnonymousIdentity is a pure column read — it never touches the
        // injected repositories — so a constructor-less instance is faithful
        // (matches the computeAttendancePct reflection test pattern).
        $service = (new \ReflectionClass(AdminRegistrationQueryService::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AdminRegistrationQueryService::class, 'resolveAnonymousIdentity');
        $method->setAccessible(true);

        return $method->invoke($service, $row);
    }

    /**
     * @test
     * The stamped lead columns are returned verbatim.
     */
    public function stampedLeadColumnsAreReturned(): void
    {
        $row = (object) ['lead_name' => 'Jan Janssen', 'lead_email' => 'jan@example.test'];

        $this->assertSame(
            ['name' => 'Jan Janssen', 'email' => 'jan@example.test'],
            $this->invoke($row),
        );
    }

    /**
     * @test
     * Denial/empty path: NULL columns (pre-backfill row) or the ''-stamped
     * "checked, nothing extractable" marker fall back to '(anoniem)' / '' —
     * never a fatal, never a blank name.
     */
    public function emptyOrNullColumnsFallBackToAnoniem(): void
    {
        $this->assertSame(
            ['name' => '(anoniem)', 'email' => ''],
            $this->invoke((object) ['lead_name' => null, 'lead_email' => null]),
        );
        $this->assertSame(
            ['name' => '(anoniem)', 'email' => ''],
            $this->invoke((object) ['lead_name' => '', 'lead_email' => '']),
        );
        // Row object without the columns at all (defensive — a caller shipping
        // a truncated row shape must not fatal).
        $this->assertSame(
            ['name' => '(anoniem)', 'email' => ''],
            $this->invoke((object) []),
        );
    }

    /**
     * @test
     * Partial identity: an email-only lead keeps the email while the name
     * falls back — the two fields degrade independently.
     */
    public function partialIdentityDegradesPerField(): void
    {
        $this->assertSame(
            ['name' => '(anoniem)', 'email' => 'partial@example.test'],
            $this->invoke((object) ['lead_name' => '', 'lead_email' => 'partial@example.test']),
        );
        $this->assertSame(
            ['name' => 'Naam Zonder Mail', 'email' => ''],
            $this->invoke((object) ['lead_name' => 'Naam Zonder Mail', 'lead_email' => null]),
        );
    }
}
