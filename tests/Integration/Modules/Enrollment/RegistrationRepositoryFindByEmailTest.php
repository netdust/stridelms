<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Enrollment;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

final class RegistrationRepositoryFindByEmailTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;
    private array $createdRegistrations = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    public function tearDown(): void
    {
        global $wpdb;
        foreach ($this->createdRegistrations as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->createdRegistrations = [];
        parent::tearDown();
    }

    public function testFindAnonymousFindsWrappedInterestRow(): void
    {
        $editionId = $this->createTestEdition();

        $id = $this->repo->create([
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

        $this->assertIsInt($id, 'create() should return an integer ID');
        $this->createdRegistrations[] = $id;

        $found = $this->repo->findAnonymousForEmailAndEdition('jan@example.com', $editionId);
        $this->assertNotNull($found);
        $this->assertSame($id, (int) $found->id);
    }

    public function testFindAnonymousFindsWrappedWaitlistRow(): void
    {
        $editionId = $this->createTestEdition();

        $id = $this->repo->create([
            'user_id' => null,
            'edition_id' => $editionId,
            'status' => 'waitlist',
            'enrollment_data' => [
                'waitlist' => RegistrationRepository::wrapStage(
                    ['name' => 'Mia', 'email' => 'mia@example.com'],
                    null,
                    '2026-05-24T12:00:00+00:00'
                ),
            ],
        ]);

        $this->assertIsInt($id, 'create() should return an integer ID');
        $this->createdRegistrations[] = $id;

        $found = $this->repo->findAnonymousForEmailAndEdition('mia@example.com', $editionId);
        $this->assertNotNull($found);
        $this->assertSame($id, (int) $found->id);
    }
}
