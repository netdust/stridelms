<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Enrollment;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

final class RegistrationRepositoryInitialSelectionTest extends IntegrationTestCase
{
    private RegistrationRepository $repo;
    private array $createdRegistrations = [];
    private array $createdUsers = [];
    private array $createdPosts = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->repo = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        global $wpdb;

        foreach ($this->createdRegistrations as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->createdRegistrations = [];

        foreach ($this->createdPosts as $postId) {
            wp_delete_post($postId, true);
        }
        $this->createdPosts = [];

        foreach ($this->createdUsers as $userId) {
            wp_delete_user($userId);
        }
        $this->createdUsers = [];

        wp_set_current_user(0);

        parent::tearDown();
    }

    private function createUser(): int
    {
        $username = 'isel_test_' . uniqid();
        $userId = wp_create_user($username, 'testpass', $username . '@test.local');
        $this->assertTrue(is_int($userId), 'Failed to create test user');
        $this->createdUsers[] = $userId;
        return $userId;
    }

    private function createEdition(): int
    {
        $postId = wp_insert_post([
            'post_title'  => 'Test Edition ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        $this->assertIsInt($postId);
        $this->createdPosts[] = $postId;
        return $postId;
    }

    private function createRegistration(int $userId, int $editionId): int
    {
        $id = $this->repo->create(['user_id' => $userId, 'edition_id' => $editionId]);
        $this->assertIsInt($id, 'Failed to create test registration');
        $this->createdRegistrations[] = $id;
        return $id;
    }

    public function testAppendInitializesStructureOnFirstCall(): void
    {
        $userId    = $this->createUser();
        $editionId = $this->createEdition();
        $id        = $this->createRegistration($userId, $editionId);

        wp_set_current_user($userId);

        $ok = $this->repo->appendInitialSelectionPhase($id, [
            'phase'       => 'enrollment',
            'session_ids' => [10, 20],
        ], 'edition');

        $this->assertTrue($ok);
        $row = $this->repo->find($id);
        $this->assertSame('edition', $row->enrollment_data['initial_selection']['type']);
        $this->assertCount(1, $row->enrollment_data['initial_selection']['phases']);
        $phase = $row->enrollment_data['initial_selection']['phases'][0];
        $this->assertSame('enrollment', $phase['phase']);
        $this->assertSame([10, 20], $phase['session_ids']);
        $this->assertSame($userId, $phase['captured_by']);
        $this->assertArrayHasKey('captured_at', $phase);
    }

    public function testAppendSecondPhaseDoesNotMutateFirst(): void
    {
        $userId    = $this->createUser();
        $editionId = $this->createEdition();
        $id        = $this->createRegistration($userId, $editionId);

        wp_set_current_user($userId);

        $this->repo->appendInitialSelectionPhase($id, [
            'phase'       => 'enrollment',
            'edition_ids' => [100],
        ], 'trajectory');

        $this->repo->appendInitialSelectionPhase($id, [
            'phase'       => 'phase_1',
            'edition_ids' => [200, 201],
        ], 'trajectory');

        $row    = $this->repo->find($id);
        $phases = $row->enrollment_data['initial_selection']['phases'];
        $this->assertCount(2, $phases);
        $this->assertSame([100], $phases[0]['edition_ids']);
        $this->assertSame('enrollment', $phases[0]['phase']);
        $this->assertSame([200, 201], $phases[1]['edition_ids']);
        $this->assertSame('phase_1', $phases[1]['phase']);
    }

    public function testAppendReturnsFalseForMissingRow(): void
    {
        $this->assertFalse($this->repo->appendInitialSelectionPhase(99999999, ['phase' => 'enrollment'], 'edition'));
    }

    public function testAppendAcceptsExplicitCapturedBy(): void
    {
        $userId    = $this->createUser();
        $actorId   = $this->createUser();
        $editionId = $this->createEdition();
        $id        = $this->createRegistration($userId, $editionId);

        wp_set_current_user($userId);

        $this->repo->appendInitialSelectionPhase($id, [
            'phase'       => 'enrollment',
            'session_ids' => [1],
            'captured_by' => $actorId,
        ], 'edition');

        $row = $this->repo->find($id);
        $this->assertSame($actorId, $row->enrollment_data['initial_selection']['phases'][0]['captured_by']);
    }
}
