<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Modules\Enrollment;

use IntegrationTestCase;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Enrollment\RegistrationRepository;

final class InitialSelectionCaptureTest extends IntegrationTestCase
{
    private EnrollmentService $service;
    private RegistrationRepository $repo;
    private array $testRegistrationIds = [];
    private array $testUserIds = [];
    private array $testSessionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = ntdst_get(EnrollmentService::class);
        $this->repo    = ntdst_get(RegistrationRepository::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->testRegistrationIds as $id) {
            $this->deleteTestRegistration($id);
        }
        $this->testRegistrationIds = [];

        foreach ($this->testSessionIds as $id) {
            wp_delete_post($id, true);
        }
        $this->testSessionIds = [];

        foreach ($this->testUserIds as $id) {
            wp_delete_user($id);
        }
        $this->testUserIds = [];

        wp_set_current_user(0);

        parent::tearDown();
    }

    private function createActor(): int
    {
        $username = 'isc_actor_' . uniqid();
        $userId   = wp_create_user($username, 'testpass', $username . '@test.local');
        $this->assertIsInt($userId, 'Failed to create actor user');
        $this->testUserIds[] = $userId;
        return $userId;
    }

    private function createSession(int $editionId): int
    {
        $id = wp_insert_post([
            'post_type'   => 'vad_session',
            'post_title'  => 'Test Session ' . uniqid(),
            'post_status' => 'publish',
            'meta_input'  => ['_ntdst_edition_id' => $editionId],
        ]);
        $this->assertIsInt($id, 'Failed to create session');
        $this->testSessionIds[] = $id;
        return $id;
    }

    public function testEditionEnrollmentCapturesSessionSelection(): void
    {
        $userId   = $this->createActor();
        $editionId = $this->createTestEdition();
        $sessionA  = $this->createSession($editionId);
        $sessionB  = $this->createSession($editionId);

        wp_set_current_user($userId);

        $result = $this->service->processEnrollment([
            'edition_id'      => $editionId,
            'user_id'         => $userId,
            'enrollment_type' => 'self',
            'first_name'      => 'Jan',
            'last_name'       => 'Janssens',
            'email'           => 'jan_isc_' . uniqid() . '@example.com',
            'terms_accepted'  => true,
            'selected_sessions' => [$sessionA, $sessionB],
        ]);

        $this->assertIsArray($result, 'enrollment should succeed: ' . print_r($result, true));
        $this->testRegistrationIds[] = $result['registration_id'];

        $row     = $this->repo->find((int) $result['registration_id']);
        $initial = $row->enrollment_data['initial_selection'] ?? null;

        $this->assertNotNull($initial, 'initial_selection should be captured');
        $this->assertSame('edition', $initial['type']);
        $this->assertCount(1, $initial['phases']);
        $this->assertSame([$sessionA, $sessionB], $initial['phases'][0]['session_ids']);
        $this->assertSame('enrollment', $initial['phases'][0]['phase']);
        $this->assertSame($userId, $initial['phases'][0]['captured_by']);
    }

    public function testEditionEnrollmentWithoutSessionsCapturesNoneType(): void
    {
        $userId    = $this->createActor();
        $editionId = $this->createTestEdition();

        wp_set_current_user($userId);

        $result = $this->service->processEnrollment([
            'edition_id'      => $editionId,
            'user_id'         => $userId,
            'enrollment_type' => 'self',
            'first_name'      => 'Jan',
            'last_name'       => 'Janssens',
            'email'           => 'jan_isc_nosess_' . uniqid() . '@example.com',
            'terms_accepted'  => true,
        ]);

        $this->assertIsArray($result, 'enrollment should succeed: ' . print_r($result, true));
        $this->testRegistrationIds[] = $result['registration_id'];

        $row     = $this->repo->find((int) $result['registration_id']);
        $initial = $row->enrollment_data['initial_selection'] ?? null;

        $this->assertNotNull($initial, 'initial_selection should still be recorded');
        $this->assertSame('none', $initial['type']);
    }
}
