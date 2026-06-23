<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Support;

use ReflectionClass;
use Stride\Admin\AdminEditionRosterService;
use Stride\Modules\Edition\Admin\EditionRegistrationExporter;
use Stride\Support\EnrollmentDataExtras;
use Stride\Tests\TestCase;

/**
 * The single-source-of-truth contract for "what is a non-PII extra in
 * enrollment_data" (CR-3 + CR-5).
 *
 * CR-5: the roster service and the exporter MUST consume ONE shared definition
 *       of the walked stages + the skipped (PII / already-columned) keys, so the
 *       two surfaces can never drift on which key counts as a logistics extra
 *       vs PII. The pinning tests assert neither consumer keeps a local copy of
 *       the lists.
 * CR-3 (structural part): because both surfaces share the ONE skip list, adding a
 *       PII key to suppress is a single edit both surfaces honour at once.
 *
 * The helper is a pure function over a PARSED enrollment_data array — no DB, no
 * WordPress — so the faithful test layer is a unit test.
 */
final class EnrollmentDataExtrasTest extends TestCase
{
    public function test_extract_walks_stages_skips_pii_and_discovers_logistics_keys(): void
    {
        // Envelope shape is { submitted_at, submitted_by, data } per stage.
        $parsed = [
            'enrollment_personal' => [
                'submitted_at' => '2026-06-23T12:00:00+00:00',
                'submitted_by' => 1,
                'data' => ['profession' => 'doctor', 'name' => 'Jan'], // name = PII skip
            ],
            'intake' => [
                'submitted_at' => '2026-06-23T12:01:00+00:00',
                'submitted_by' => 1,
                'data' => ['dieet' => 'vegetarisch', 'organisation' => 'ACME BV'], // organisation = PII skip
            ],
            // Pre-account stage NOT in EXTRAS_STAGES — must be ignored entirely.
            'interest' => [
                'data' => ['leaked' => 'should-not-appear'],
            ],
        ];

        $extras = EnrollmentDataExtras::extract($parsed);

        // Logistics keys discovered from the data.
        $this->assertArrayHasKey('dieet', $extras);
        $this->assertSame('vegetarisch', $extras['dieet']);
        $this->assertArrayHasKey('profession', $extras);
        $this->assertSame('doctor', $extras['profession']);

        // PII / already-columned keys suppressed (denial path).
        $this->assertArrayNotHasKey('name', $extras);
        $this->assertArrayNotHasKey('organisation', $extras);

        // A stage outside EXTRAS_STAGES is never walked.
        $this->assertArrayNotHasKey('leaked', $extras);
    }

    public function test_extract_returns_empty_for_no_walkable_stages(): void
    {
        $this->assertSame([], EnrollmentDataExtras::extract([]));
        $this->assertSame([], EnrollmentDataExtras::extract(['interest' => ['data' => ['x' => 'y']]]));
    }

    // === CR-5: the two surfaces share ONE definition — no local copy remains ===

    public function test_roster_service_has_no_local_skip_or_stage_list(): void
    {
        $source = $this->sourceOf(AdminEditionRosterService::class);

        // The local list constants are gone — the service delegates to the
        // shared helper instead of carrying its own copy (CR-5). It must also
        // reference the shared contract.
        $this->assertStringNotContainsString('EXTRAS_SKIP_KEYS', $source, 'roster must not keep a local skip list');
        $this->assertStringNotContainsString('EXTRAS_STAGES', $source, 'roster must not keep a local stage list');
        $this->assertStringContainsString('EnrollmentDataExtras', $source, 'roster must consume the shared extras contract');
    }

    public function test_exporter_has_no_local_skip_or_stage_list(): void
    {
        $source = $this->sourceOf(EditionRegistrationExporter::class);

        $this->assertStringNotContainsString('$skipKeys', $source, 'exporter must not keep a local skip list');
        $this->assertStringNotContainsString('$stagesToShow', $source, 'exporter must not keep a local stage list');
        $this->assertStringContainsString('EnrollmentDataExtras', $source, 'exporter must consume the shared extras contract');
    }

    private function sourceOf(string $class): string
    {
        $path = (new ReflectionClass($class))->getFileName();
        $src = file_get_contents($path);
        $this->assertIsString($src);
        return $src;
    }
}
