<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryCPT;

/**
 * Integration tests for the documents-instruction wiring in
 * EnrollmentCompletion::getTaskSummary().
 *
 * These cross the un-mocked repo/DB seam: a real registration row resolves its
 * offering (edition OR trajectory), the real repository reads the stored
 * instruction via getField(), and the summary surfaces it under 'descriptions'.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter EnrollmentCompletionSummary
 */
class EnrollmentCompletionSummaryTest extends IntegrationTestCase
{
    private EnrollmentCompletion $completion;
    private RegistrationRepository $repo;
    private array $testRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->completion = ntdst_get(EnrollmentCompletion::class);
        $this->repo = ntdst_get(RegistrationRepository::class);
        $this->actingAs(self::$testUserId);
    }

    protected function tearDown(): void
    {
        foreach ($this->testRegistrationIds as $regId) {
            $this->deleteTestRegistration($regId);
        }
        $this->testRegistrationIds = [];

        parent::tearDown();
    }

    /**
     * Real-chain assertion: an edition with a stored documents_instruction +
     * a registration carrying a 'documents' task → the summary surfaces the
     * stored instruction (edition resolved, repo getField hit, no mock).
     *
     * @test
     */
    public function summaryIncludesAdminDocumentsInstruction(): void
    {
        $instruction = 'Breng je diploma en attest mee naar de eerste sessie.';

        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_requires_documents' => '1',
                '_ntdst_documents_instruction' => $instruction,
            ],
        ]);

        $regId = $this->createRegistrationWithDocumentsTask([
            'edition_id' => $editionId,
        ]);

        $summary = $this->completion->getTaskSummary($regId);

        $this->assertArrayHasKey('descriptions', $summary, 'Summary must carry a descriptions map');
        $this->assertSame(
            $instruction,
            $summary['descriptions']['documents'] ?? null,
            'descriptions.documents must equal the stored edition instruction',
        );
    }

    /**
     * Negative/edge: documents required but the instruction is EMPTY →
     * the accessor returns DEFAULT_DOCUMENTS_INSTRUCTION (schema fields never
     * return getField() defaults, so the empty→default branch is exercised).
     *
     * @test
     */
    public function summaryFallsBackToDefaultInstruction(): void
    {
        $editionId = $this->createTestEdition([
            'meta' => [
                '_ntdst_requires_documents' => '1',
                // no _ntdst_documents_instruction → reads as ''
            ],
        ]);

        $regId = $this->createRegistrationWithDocumentsTask([
            'edition_id' => $editionId,
        ]);

        $summary = $this->completion->getTaskSummary($regId);

        $this->assertSame(
            EnrollmentCompletion::DEFAULT_DOCUMENTS_INSTRUCTION,
            $summary['descriptions']['documents'] ?? null,
            'Empty stored instruction must fall back to the default constant',
        );
    }

    /**
     * Resolution edge (the named, required case): a TRAJECTORY registration
     * (edition_id NULL, trajectory_id set) must resolve via 'vad_trajectory'
     * and return the TRAJECTORY's instruction — NOT an edition lookup.
     *
     * @test
     */
    public function summaryResolvesTrajectoryInstructionViaTrajectoryType(): void
    {
        $instruction = 'Upload je werkgeversattest voor dit traject.';

        $trajectoryId = $this->createTestTrajectory([
            '_ntdst_requires_documents' => '1',
            '_ntdst_documents_instruction' => $instruction,
        ]);

        $regId = $this->createRegistrationWithDocumentsTask([
            'trajectory_id' => $trajectoryId,
        ]);

        $summary = $this->completion->getTaskSummary($regId);

        $this->assertSame(
            $instruction,
            $summary['descriptions']['documents'] ?? null,
            'Trajectory registration must resolve the trajectory instruction via vad_trajectory',
        );
    }

    // === Helpers ===

    /**
     * Create a trajectory CPT with the given meta (already _ntdst_-prefixed).
     *
     * @param array<string, mixed> $meta
     */
    private function createTestTrajectory(array $meta = []): int
    {
        $postId = wp_insert_post([
            'post_title' => 'Test Trajectory ' . wp_generate_password(4, false),
            'post_type' => TrajectoryCPT::POST_TYPE,
            'post_status' => 'publish',
        ]);

        if (is_wp_error($postId)) {
            throw new \RuntimeException('Failed to create test trajectory: ' . $postId->get_error_message());
        }

        self::$testPosts[] = $postId;

        foreach ($meta as $key => $value) {
            update_post_meta($postId, $key, $value);
        }

        return $postId;
    }

    /**
     * Create a confirmed registration (edition OR trajectory) carrying a
     * pending 'documents' completion task.
     *
     * @param array{edition_id?: int, trajectory_id?: int} $offering
     */
    private function createRegistrationWithDocumentsTask(array $offering): int
    {
        $regId = $this->repo->create(array_merge([
            'user_id' => self::$testUserId,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
        ], $offering));

        if (is_wp_error($regId)) {
            throw new \RuntimeException('Failed to create registration: ' . $regId->get_error_message());
        }

        $this->testRegistrationIds[] = $regId;

        $this->repo->updateCompletionTasks($regId, [
            'documents' => ['status' => 'pending', 'phase' => 'enrollment'],
        ]);

        return $regId;
    }
}
