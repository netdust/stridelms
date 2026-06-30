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
 * wp_vad_registrations row with user_id = NULL/0 and the submitter's
 * name/email stored inside the enrollment_data JSON column. The grid
 * read-model must fall back to that captured data instead of rendering
 * a blank name/email (the joined wp_users row does not exist).
 *
 * Decode semantics MUST be identical to the per-edition roster path in
 * AdminAPIController (envelope $decoded[$status]['data'][field], same
 * '(anoniem)' / '' defaults) — INV-3: do not drift the two reads.
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
        // resolveAnonymousIdentity is pure decode — it never touches the injected
        // repositories — so a constructor-less instance is faithful (matches the
        // computeAttendancePct reflection test pattern).
        $service = (new \ReflectionClass(AdminRegistrationQueryService::class))
            ->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AdminRegistrationQueryService::class, 'resolveAnonymousIdentity');
        $method->setAccessible(true);

        return $method->invoke($service, $row);
    }

    private function row(string $status, ?string $enrollmentData): object
    {
        return (object) [
            'status'          => $status,
            'enrollment_data' => $enrollmentData,
        ];
    }

    /**
     * @test
     * An anonymous interest row reads name + email from the 'interest' stage.
     */
    public function interestRowReadsCapturedNameAndEmail(): void
    {
        $json = json_encode([
            'interest' => [
                'submitted_at' => '2026-06-30 10:00:00',
                'data'         => ['name' => 'Jan Janssen', 'email' => 'jan@example.test'],
            ],
        ]);

        $result = $this->invoke($this->row('interest', $json));

        $this->assertSame('Jan Janssen', $result['name']);
        $this->assertSame('jan@example.test', $result['email']);
    }

    /**
     * @test
     * An anonymous waitlist row reads from the 'waitlist' stage key.
     */
    public function waitlistRowReadsFromWaitlistStage(): void
    {
        $json = json_encode([
            'waitlist' => [
                'data' => ['name' => 'An Peeters', 'email' => 'an@example.test'],
            ],
        ]);

        $result = $this->invoke($this->row('waitlist', $json));

        $this->assertSame('An Peeters', $result['name']);
        $this->assertSame('an@example.test', $result['email']);
    }

    /**
     * @test
     * Denial/empty path: missing/empty enrollment_data falls back to the
     * '(anoniem)' name and '' email — never a fatal, never a blank name.
     */
    public function emptyEnrollmentDataFallsBackToAnoniem(): void
    {
        $this->assertSame(
            ['name' => '(anoniem)', 'email' => ''],
            $this->invoke($this->row('interest', null)),
        );
        $this->assertSame(
            ['name' => '(anoniem)', 'email' => ''],
            $this->invoke($this->row('interest', '')),
        );
        $this->assertSame(
            ['name' => '(anoniem)', 'email' => ''],
            $this->invoke($this->row('interest', 'not-json{')),
        );
    }

    /**
     * @test
     * Denial path: stage present but name missing → '(anoniem)' / '' for the
     * absent fields, never a PHP notice.
     */
    public function missingNameFieldFallsBack(): void
    {
        $json = json_encode([
            'interest' => ['data' => ['email' => 'partial@example.test']],
        ]);

        $result = $this->invoke($this->row('interest', $json));

        $this->assertSame('(anoniem)', $result['name']);
        $this->assertSame('partial@example.test', $result['email']);
    }

    /**
     * @test
     * Denial path: the stage key for THIS row's status is absent entirely
     * (data only holds a different stage) → fall back, do not leak the wrong stage.
     */
    public function wrongStageKeyFallsBack(): void
    {
        $json = json_encode([
            'interest' => ['data' => ['name' => 'Wrong Stage', 'email' => 'wrong@example.test']],
        ]);

        // Row status is 'waitlist' but only an 'interest' envelope exists.
        $result = $this->invoke($this->row('waitlist', $json));

        $this->assertSame('(anoniem)', $result['name']);
        $this->assertSame('', $result['email']);
    }
}
