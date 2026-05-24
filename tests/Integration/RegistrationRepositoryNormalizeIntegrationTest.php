<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

final class RegistrationRepositoryNormalizeIntegrationTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;
    private array $createdRegistrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'vad_registrations';
        foreach ($this->createdRegistrations as $id) {
            $wpdb->delete($table, ['id' => $id], ['%d']);
        }
        $this->createdRegistrations = [];
        parent::tearDown();
    }

    public function testCreateNormalizesEnrollmentData(): void
    {
        $editionId = $this->createTestEdition();

        $id = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
            'enrollment_data' => [
                'interest' => RegistrationRepository::wrapStage(['name' => 'Jan'], self::$testUserId, '2026-05-24T12:00:00+00:00'),
                'rogue_root' => ['x' => 1],
            ],
        ]);

        $this->assertIsInt($id);
        $this->createdRegistrations[] = $id;

        $row = $this->repo->find($id);
        $this->assertIsArray($row->enrollment_data);
        $this->assertArrayHasKey('interest', $row->enrollment_data);
        $this->assertArrayNotHasKey('rogue_root', $row->enrollment_data);
        $this->assertSame('Jan', $row->enrollment_data['interest']['data']['name']);
    }

    public function testUpdateNormalizesEnrollmentData(): void
    {
        $editionId = $this->createTestEdition();

        $id = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $editionId,
        ]);

        $this->assertIsInt($id);
        $this->createdRegistrations[] = $id;

        $this->repo->update($id, [
            'enrollment_data' => [
                'enrollment_personal' => RegistrationRepository::wrapStage(['phone' => '0123'], self::$testUserId, '2026-05-24T12:00:00+00:00'),
                'something_invalid' => 'dropped',
            ],
        ]);

        $row = $this->repo->find($id);
        $this->assertIsArray($row->enrollment_data);
        $this->assertArrayHasKey('enrollment_personal', $row->enrollment_data);
        $this->assertArrayNotHasKey('something_invalid', $row->enrollment_data);
    }

    public function testUpgradeFromInterestNormalizes(): void
    {
        $editionId = $this->createTestEdition();

        // Create an anonymous interest row directly (no user_id)
        $interestId = $this->repo->create([
            'user_id' => null,
            'edition_id' => $editionId,
            'status' => 'interest',
            'enrollment_data' => [
                'interest' => RegistrationRepository::wrapStage(
                    ['name' => 'Jan', 'email' => 'jan@example.com'],
                    null,
                    '2026-05-24T12:00:00+00:00'
                ),
            ],
        ]);

        $this->assertIsInt($interestId);
        $this->createdRegistrations[] = $interestId;

        $merged = [
            'interest' => RegistrationRepository::wrapStage(
                ['name' => 'Jan', 'email' => 'jan@example.com'],
                null,
                '2026-05-24T12:00:00+00:00'
            ),
            'enrollment_personal' => RegistrationRepository::wrapStage(
                ['phone' => '0123'],
                self::$testUserId,
                '2026-05-24T12:05:00+00:00'
            ),
            'rogue' => ['should be dropped'],
        ];

        $this->repo->upgradeFromInterest($interestId, self::$testUserId, 'confirmed', 'individual', $merged);

        $row = $this->repo->find($interestId);
        $this->assertIsArray($row->enrollment_data);
        $this->assertArrayHasKey('interest', $row->enrollment_data);
        $this->assertArrayHasKey('enrollment_personal', $row->enrollment_data);
        $this->assertArrayNotHasKey('rogue', $row->enrollment_data);
    }
}
