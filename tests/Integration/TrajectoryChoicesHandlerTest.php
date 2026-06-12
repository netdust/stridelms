<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Integration tests for the stride_save_trajectory_choices API action
 * (EnrollmentFormHandler::handleSaveTrajectoryChoices).
 *
 * Threat-model coverage (plan 2026-06-12-trajectory-wiring):
 * - mitigation 1: ownership at entry — foreign registration refused with the
 *   SAME error as not-found (no existence oracle)
 * - mitigation 2: NOT a public action (anonymous wire calls refused by the
 *   API layer)
 * - INV-4: service WP_Error propagates
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter TrajectoryChoicesHandlerTest
 */
final class TrajectoryChoicesHandlerTest extends IntegrationTestCase
{
    private int $trajectoryId;
    private int $ownerId;
    private int $strangerId;
    private int $registrationId;

    /** @var array<int> */
    private array $createdRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->trajectoryId = wp_insert_post([
            'post_type' => 'vad_trajectory',
            'post_title' => 'Choices Handler Trajectory',
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $this->trajectoryId;
        // Open choice window, no electives (count validation passes trivially).
        update_post_meta($this->trajectoryId, '_ntdst_choice_available_date', date('Y-m-d', strtotime('-1 day')));
        update_post_meta($this->trajectoryId, '_ntdst_choice_deadline', date('Y-m-d', strtotime('+7 days')));
        update_post_meta($this->trajectoryId, '_ntdst_status', 'open');

        $stamp = uniqid();
        $this->ownerId = wp_create_user('choices_owner_' . $stamp, 'pass12345', 'choices_owner_' . $stamp . '@test.local');
        $this->strangerId = wp_create_user('choices_stranger_' . $stamp, 'pass12345', 'choices_stranger_' . $stamp . '@test.local');

        $regId = ntdst_get(RegistrationRepository::class)->create([
            'user_id' => $this->ownerId,
            'trajectory_id' => $this->trajectoryId,
            'status' => 'confirmed',
            'enrollment_path' => 'trajectory',
        ]);
        $this->assertIsInt($regId);
        $this->registrationId = $regId;
        $this->createdRegistrationIds[] = $regId;
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->createdRegistrationIds as $id) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $id]);
        }
        $this->createdRegistrationIds = [];

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($this->ownerId);
        wp_delete_user($this->strangerId);
        wp_set_current_user(0);

        parent::tearDown();
    }

    private function call(array $params): mixed
    {
        return apply_filters('ntdst/api_data/stride_save_trajectory_choices', null, $params);
    }

    public function testActionIsNotPublic(): void
    {
        $publicActions = apply_filters('ntdst/api/public_actions', []);
        $this->assertNotContains(
            'stride_save_trajectory_choices',
            $publicActions,
            'choices action must require an authenticated session (mitigation 2)'
        );
    }

    public function testLoggedOutCallIsRefused(): void
    {
        wp_set_current_user(0);

        $result = $this->call(['registration_id' => $this->registrationId, 'selections' => []]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testForeignRegistrationRefusedSameAsNotFound(): void
    {
        wp_set_current_user($this->strangerId);
        $foreign = $this->call(['registration_id' => $this->registrationId, 'selections' => []]);

        $this->assertInstanceOf(\WP_Error::class, $foreign);

        $missing = $this->call(['registration_id' => 999999999, 'selections' => []]);
        $this->assertInstanceOf(\WP_Error::class, $missing);

        // No existence oracle: identical code + message for foreign and missing.
        $this->assertSame($missing->get_error_code(), $foreign->get_error_code());
        $this->assertSame($missing->get_error_message(), $foreign->get_error_message());
    }

    public function testOwnerCallPersistsAndReturnsSuccess(): void
    {
        wp_set_current_user($this->ownerId);

        $result = $this->call(['registration_id' => $this->registrationId, 'selections' => []]);

        $this->assertIsArray($result, 'owner call must succeed: ' . (is_wp_error($result) ? $result->get_error_message() : ''));
        $this->assertTrue($result['success']);

        $reg = ntdst_get(RegistrationRepository::class)->find($this->registrationId);
        $this->assertSame([], $reg->selections ?? null, 'empty elective slate persists as empty selections');
    }

    public function testServiceErrorPropagates(): void
    {
        // Close the window — the service guard must surface as WP_Error.
        update_post_meta($this->trajectoryId, '_ntdst_choice_deadline', date('Y-m-d', strtotime('-1 day')));

        wp_set_current_user($this->ownerId);
        $result = $this->call(['registration_id' => $this->registrationId, 'selections' => []]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('choice_window_closed', $result->get_error_code());
    }
}
