<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Trajectory\TrajectoryRepository;
use Stride\Modules\Trajectory\TrajectoryService;

/**
 * The trajectory descriptive fields (reused verbatim from the edition set)
 * are REGISTERED on the schema and surfaced by getTrajectory(), so the
 * public page can render them instead of hardcoded text.
 *
 * Regression guard for the 2026-06-12 gap: target_audience/duration were
 * registered but unused + admin-uneditable, and the rest of the edition
 * descriptive set was absent.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryDescriptiveFields
 */
final class TrajectoryDescriptiveFieldsTest extends IntegrationTestCase
{
    private const FIELDS = [
        'target_audience'     => 'Zorgcoördinatoren en preventiewerkers',
        'required_experience' => 'Geen voorkennis vereist',
        'included'            => 'Cursusmateriaal, koffie en lunch',
        'price_includes'      => 'incl. cursusmateriaal',
        'cancellation_policy' => 'Kosteloos annuleren tot 14 dagen voor de start',
        'cta_benefits'        => "Erkend certificaat\nBegeleiding door experts",
        'enrollment_info'     => 'Je ontvangt een bevestiging per e-mail.',
        'duration'            => '6 maanden',
    ];

    private TrajectoryRepository $repo;
    private TrajectoryService $service;
    private int $trajectoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(TrajectoryRepository::class);
        $this->service = ntdst_get(TrajectoryService::class);

        $this->trajectoryId = wp_insert_post([
            'post_type'   => 'vad_trajectory',
            'post_title'  => 'Descriptive Fields Test ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $this->trajectoryId;
    }

    public function testFieldsAreRegisteredAndRoundTrip(): void
    {
        $this->assertNotFalse(
            $this->repo->update($this->trajectoryId, self::FIELDS),
            'every descriptive field must be a registered schema field'
        );

        foreach (self::FIELDS as $key => $value) {
            $this->assertSame(
                $value,
                $this->repo->getField($this->trajectoryId, $key),
                "field {$key} must round-trip through the schema"
            );
        }
    }

    public function testGetTrajectorySurfacesEveryDescriptiveField(): void
    {
        $this->repo->update($this->trajectoryId, self::FIELDS);

        $trajectory = $this->service->getTrajectory($this->trajectoryId);
        $this->assertIsArray($trajectory);

        foreach (self::FIELDS as $key => $value) {
            $this->assertArrayHasKey($key, $trajectory, "getTrajectory() must surface {$key}");
            $this->assertSame($value, $trajectory[$key], "getTrajectory()[{$key}] must carry the saved value");
        }
    }

    public function testUnsetDescriptiveFieldsDefaultToEmptyString(): void
    {
        $trajectory = $this->service->getTrajectory($this->trajectoryId);

        foreach (array_keys(self::FIELDS) as $key) {
            $this->assertSame('', $trajectory[$key] ?? null, "unset {$key} must default to '' (hide-when-empty contract)");
        }
    }
}
