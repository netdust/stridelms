<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\User;

use IntegrationTestCase;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Trajectory\TrajectoryRepository;

/**
 * Integration test (T1): the `profiletype_rules` (json) and
 * `exclude_from_catalog` (bool) fields are REGISTERED on both the
 * vad_edition and vad_trajectory schemas, and each repository exposes typed
 * accessors that round-trip them.
 *
 * Contract (from the plan §1 / §5 Block-2, freshness-corrected):
 *  - profiletype_rules round-trips through update() → getProfiletypeRules().
 *  - Empty / absent / legacy-string → getProfiletypeRules() returns [] (the
 *    erosion-guard case: never null, never a raw string).
 *  - exclude_from_catalog round-trips true; default (never written) → false.
 *  - Both fields persist under the _ntdst_ prefix (meta_prefix on both CPTs),
 *    so the postmeta key is _ntdst_profiletype_rules — proves prefix agreement.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec vendor/bin/phpunit \
 *        -c phpunit-integration.xml.dist --filter ProfiletypeRulesField
 */
final class ProfiletypeRulesFieldTest extends IntegrationTestCase
{
    private EditionRepository $editions;
    private TrajectoryRepository $trajectories;
    private int $editionId;
    private int $trajectoryId;

    /** @var int[] posts created by an individual test (deleted in tearDown) */
    private array $createdPosts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->editions = ntdst_get(EditionRepository::class);
        $this->trajectories = ntdst_get(TrajectoryRepository::class);

        $this->editionId = wp_insert_post([
            'post_type'   => 'vad_edition',
            'post_title'  => 'ProfiletypeRules Edition',
            'post_status' => 'publish',
        ]);
        $this->trajectoryId = wp_insert_post([
            'post_type'   => 'vad_trajectory',
            'post_title'  => 'ProfiletypeRules Trajectory',
            'post_status' => 'publish',
        ]);
        // Deleted per-test (tearDown), NOT at class teardown: a published
        // vad_edition living across the whole class run pollutes the edition
        // counts other suites assert on (AdminExportServiceTest,
        // RegistrationGridQueryTest — see lesson_integration_test_registration_cleanup).
        $this->createdPosts[] = $this->editionId;
        $this->createdPosts[] = $this->trajectoryId;
    }

    protected function tearDown(): void
    {
        // Delete everything this test created BEFORE any other suite runs, so no
        // stray edition/trajectory row survives to skew cross-suite counts.
        foreach ($this->createdPosts as $postId) {
            wp_delete_post($postId, true);
        }
        $this->createdPosts = [];
        parent::tearDown();
    }

    /**
     * A sample nested rules map, shaped per the plan:
     * { "<slug>": { "block": bool, "minimal": bool, "voucher": "<code>|null" } }
     *
     * @return array<string, array<string, mixed>>
     */
    private function sampleRules(): array
    {
        return [
            'zorgverlener' => ['block' => false, 'minimal' => true,  'voucher' => 'WELKOM10'],
            'student'      => ['block' => true,  'minimal' => false, 'voucher' => null],
        ];
    }

    // ---- profiletype_rules round-trip ---------------------------------------

    public function testEditionRulesRoundTripThroughRegisteredField(): void
    {
        $rules = $this->sampleRules();

        $result = $this->editions->update($this->editionId, ['profiletype_rules' => $rules]);
        $this->assertNotFalse($result, 'update() must accept the registered profiletype_rules field');

        $read = $this->editions->getProfiletypeRules($this->editionId);
        $this->assertSame($rules, $read, 'edition rules map must round-trip identically, nested structure intact');
    }

    public function testTrajectoryRulesRoundTripThroughRegisteredField(): void
    {
        $rules = $this->sampleRules();

        $result = $this->trajectories->update($this->trajectoryId, ['profiletype_rules' => $rules]);
        $this->assertNotFalse($result, 'update() must accept the registered profiletype_rules field');

        $read = $this->trajectories->getProfiletypeRules($this->trajectoryId);
        $this->assertSame($rules, $read, 'trajectory rules map must round-trip identically, nested structure intact');
    }

    // ---- erosion guard: empty / absent / legacy string → [] -----------------

    public function testAbsentRulesReturnEmptyArrayNotNull(): void
    {
        // Never written.
        $edition = $this->editions->getProfiletypeRules($this->editionId);
        $trajectory = $this->trajectories->getProfiletypeRules($this->trajectoryId);

        $this->assertSame([], $edition, 'absent edition rules must be [] (not null)');
        $this->assertSame([], $trajectory, 'absent trajectory rules must be [] (not null)');
    }

    public function testLegacyStringRulesCoerceToEmptyArray(): void
    {
        // Simulate a legacy value: a raw non-array string written directly to
        // the prefixed meta key, bypassing the schema writer. The getter MUST
        // defensively coerce non-array to [] — never return a raw string.
        update_post_meta($this->editionId, '_ntdst_profiletype_rules', 'legacy-garbage-string');
        update_post_meta($this->trajectoryId, '_ntdst_profiletype_rules', 'legacy-garbage-string');

        $this->assertSame([], $this->editions->getProfiletypeRules($this->editionId), 'legacy edition string → []');
        $this->assertSame([], $this->trajectories->getProfiletypeRules($this->trajectoryId), 'legacy trajectory string → []');
    }

    // ---- exclude_from_catalog round-trip ------------------------------------

    public function testExcludeFromCatalogRoundTrips(): void
    {
        $this->assertFalse(
            $this->editions->getExcludeFromCatalog($this->editionId),
            'default (never written) exclude_from_catalog must be false',
        );

        $result = $this->editions->update($this->editionId, ['exclude_from_catalog' => true]);
        $this->assertNotFalse($result, 'update() must accept the registered exclude_from_catalog field');
        $this->assertTrue(
            $this->editions->getExcludeFromCatalog($this->editionId),
            'written-true exclude_from_catalog must read back true',
        );

        // Trajectory parity.
        $this->assertFalse($this->trajectories->getExcludeFromCatalog($this->trajectoryId));
        $this->trajectories->update($this->trajectoryId, ['exclude_from_catalog' => true]);
        $this->assertTrue($this->trajectories->getExcludeFromCatalog($this->trajectoryId));
    }

    // ---- prefix agreement (the whole point of the freshness correction) -----

    public function testPersistedPostmetaKeyIsNtdstPrefixed(): void
    {
        $this->editions->update($this->editionId, ['profiletype_rules' => $this->sampleRules()]);

        $raw = get_post_meta($this->editionId, '_ntdst_profiletype_rules', true);
        $this->assertNotEmpty(
            $raw,
            'rules must physically persist under the _ntdst_ prefixed key, proving write/read prefix agreement',
        );
    }
}
