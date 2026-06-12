<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Focused integration test for RegistrationRepository::idsWithCompletionTasks()
 * (CR-D3 / INV-3): the migration-support read that replaces the raw $wpdb
 * scan CompletionProofStorage::migrate() used to run against the
 * repository-owned registrations table.
 *
 * Contract:
 *  - Returns rows that HAVE completion_tasks, with id cast to int and the
 *    JSON decoded to an array (repo decode convention, cf. findByUser).
 *  - Rows WITHOUT completion_tasks are excluded.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationRepositoryCompletionTasks"
 */
final class RegistrationRepositoryCompletionTasksTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->createdRegistrationIds = [];

        parent::tearDown();
    }

    /** @test */
    public function returnsOnlyRowsCarryingCompletionTasksWithDecodedJson(): void
    {
        $edition = $this->createTestEdition();

        $withTasks = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $edition,
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($withTasks);
        $this->createdRegistrationIds[] = $withTasks;

        $tasks = [
            'documents' => [
                'status' => 'completed',
                'phase' => 'enrollment',
                'data' => ['files' => [4242]],
            ],
        ];
        $this->repo->updateCompletionTasks($withTasks, $tasks);

        $withoutTasks = $this->repo->create([
            'user_id' => self::$testUserId,
            'edition_id' => $this->createTestEdition(),
            'status' => 'confirmed',
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ]);
        $this->assertIsInt($withoutTasks);
        $this->createdRegistrationIds[] = $withoutTasks;

        $rows = $this->repo->idsWithCompletionTasks();

        // The table may carry rows from other fixtures — assert on ours only.
        $byId = [];
        foreach ($rows as $row) {
            $this->assertIsInt($row->id, 'id must be cast to int');
            $byId[$row->id] = $row;
        }

        $this->assertArrayHasKey($withTasks, $byId, 'Row WITH completion_tasks must be returned');
        $this->assertArrayNotHasKey($withoutTasks, $byId, 'Row WITHOUT completion_tasks must be excluded (negative path)');
        $this->assertSame(
            $tasks,
            $byId[$withTasks]->completion_tasks,
            'completion_tasks must come back JSON-decoded to the stored structure',
        );
    }
}
