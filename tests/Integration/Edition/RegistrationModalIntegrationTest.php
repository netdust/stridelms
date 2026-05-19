<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use IntegrationTestCase;
use Stride\Modules\Edition\Admin\RegistrationModalController;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration test for the RegistrationModalController AJAX endpoint payload.
 *
 * Verifies that buildPayload() renders the enrollment and completion modals
 * end-to-end against a real WordPress + DB environment.
 */
class RegistrationModalIntegrationTest extends IntegrationTestCase
{
    private int $editionId;
    private int $userId;
    private array $registrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(self::$testUserId);

        $username = 'reg_modal_user_' . wp_generate_password(6, false);
        $this->userId = wp_create_user(
            $username,
            'testpass123',
            $username . '@test.local',
        );
        if (is_wp_error($this->userId)) {
            throw new \RuntimeException(
                'Failed to create test user: ' . $this->userId->get_error_message(),
            );
        }

        wp_update_user([
            'ID' => $this->userId,
            'display_name' => 'Test User',
        ]);

        $this->editionId = $this->createTestEdition([
            'post_title' => 'Test Edition',
        ]);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->registrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->registrationIds = [];

        if ($this->userId) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($this->userId);
            $this->userId = 0;
        }

        parent::tearDown();
    }

    public function testEnrollmentModalRendersForSeededRegistration(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id'          => $this->userId,
            'edition_id'       => $this->editionId,
            'status'           => 'confirmed',
            'enrollment_data'  => wp_json_encode(['phone_secondary' => '+32 444']),
            'completion_tasks' => wp_json_encode([
                'questionnaire' => [
                    'status' => 'completed',
                    'data'   => ['answers' => ['Q1' => 'A1']],
                ],
            ]),
            'registered_at'    => current_time('mysql'),
        ]);
        $registrationId = (int) $wpdb->insert_id;
        $this->registrationIds[] = $registrationId;

        $controller = new RegistrationModalController(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionSelection::class),
            ntdst_get(RegistrationRepository::class),
        );

        $payload = $controller->buildPayload($registrationId, 'enrollment');

        self::assertIsArray($payload);
        self::assertStringContainsString('Test Edition', $payload['title']);
        self::assertStringContainsString('Test User', $payload['title']);
        self::assertStringContainsString('+32 444', $payload['html']);
        self::assertStringContainsString('Q1', $payload['html']);
        self::assertStringContainsString('A1', $payload['html']);
    }

    public function testCompletionModalRendersForSeededRegistration(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id'          => $this->userId,
            'edition_id'       => $this->editionId,
            'status'           => 'confirmed',
            'enrollment_data'  => '{}',
            'completion_tasks' => wp_json_encode([
                'questionnaire' => [
                    'status'       => 'completed',
                    'completed_at' => '2026-05-01 10:00:00',
                ],
            ]),
            'registered_at'    => current_time('mysql'),
        ]);
        $registrationId = (int) $wpdb->insert_id;
        $this->registrationIds[] = $registrationId;

        $controller = new RegistrationModalController(
            ntdst_get(EditionService::class),
            ntdst_get(EditionRepository::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionSelection::class),
            ntdst_get(RegistrationRepository::class),
        );

        $payload = $controller->buildPayload($registrationId, 'completion');

        self::assertIsArray($payload);
        self::assertStringContainsString('Voltooiing', $payload['title']);
        self::assertStringContainsString('Vragenlijst', $payload['html']);
    }
}
