<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use Stride\Admin\AdminAPIController;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Anon interest/waitlist rows have no user record. AdminAPIController
 * falls back to enrollment_data[stage].data.{name,email}.
 *
 * Run: ddev exec vendor/bin/phpunit --filter AdminAPIControllerAnonRowReadTest --testsuite Integration
 */
final class AdminAPIControllerAnonRowReadTest extends IntegrationTestCase
{
    private RegistrationRepository $registrations;
    private array $testRegistrationIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrations = ntdst_get(RegistrationRepository::class);

        // Ensure REST routes are registered.
        do_action('rest_api_init');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->testRegistrationIds as $regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
        }
        parent::tearDown();
    }

    public function testAnonInterestRowSurfacesNameFromWrappedStage(): void
    {
        $editionId = $this->createTestEdition();

        // Create anon interest row with wrapped stage shape (Task 6 format).
        $regId = $this->registrations->create([
            'user_id'         => null,
            'edition_id'      => $editionId,
            'status'          => 'interest',
            'enrollment_data' => [
                'interest' => RegistrationRepository::wrapStage(
                    ['name' => 'Anon Jan', 'email' => 'anon@example.com'],
                    null,
                    '2026-05-24T12:00:00+00:00'
                ),
            ],
        ]);

        $this->assertIsInt($regId, 'Failed to create anon interest registration');
        $this->testRegistrationIds[] = $regId;

        // Dispatch as administrator through the REST server.
        $adminId = wp_create_user(
            'admin_anon_test_' . wp_generate_password(4, false),
            'testpass',
            'adminanon_' . wp_generate_password(4, false) . '@test.local'
        );
        wp_update_user(['ID' => $adminId, 'role' => 'administrator']);
        wp_set_current_user($adminId);

        $request = new \WP_REST_Request('GET', '/stride/v1/admin/editions/' . $editionId . '/registrations');
        $request->set_param('id', $editionId);

        $server   = rest_get_server();
        $response = $server->dispatch($request);

        // Clean up admin user.
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($adminId);

        $this->assertNotInstanceOf(
            \WP_Error::class,
            $response,
            'REST dispatch returned WP_Error'
        );
        $this->assertSame(
            200,
            $response->get_status(),
            'Expected 200, got ' . $response->get_status() . ' (body: ' . wp_json_encode($response->get_data()) . ')'
        );

        $data  = $response->get_data();
        $items = $data['items'] ?? (is_array($data) ? $data : []);
        $this->assertNotEmpty($items, 'Expected at least one registration in response');

        // Find the interest row we inserted.
        $found = null;
        foreach ($items as $item) {
            if (($item['status'] ?? null) === 'interest' && ($item['id'] ?? null) === $regId) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'No interest row with id=' . $regId . ' in response');

        // Name must come from enrollment_data.interest.data.name, not the stale flat path.
        $name = $found['user']['name'] ?? null;
        $this->assertSame(
            'Anon Jan',
            $name,
            'Expected "Anon Jan" from wrapped stage; got: ' . var_export($name, true)
        );

        $email = $found['user']['email'] ?? null;
        $this->assertSame(
            'anon@example.com',
            $email,
            'Expected "anon@example.com" from wrapped stage; got: ' . var_export($email, true)
        );
    }

    public function testAnonRowWithMissingDataFallsBackToAnoniem(): void
    {
        $editionId = $this->createTestEdition();

        // Row with no enrollment_data at all.
        $regId = $this->registrations->create([
            'user_id'    => null,
            'edition_id' => $editionId,
            'status'     => 'interest',
        ]);

        $this->assertIsInt($regId);
        $this->testRegistrationIds[] = $regId;

        $adminId = wp_create_user(
            'admin_fallback_test_' . wp_generate_password(4, false),
            'testpass',
            'adminfallback_' . wp_generate_password(4, false) . '@test.local'
        );
        wp_update_user(['ID' => $adminId, 'role' => 'administrator']);
        wp_set_current_user($adminId);

        $request = new \WP_REST_Request('GET', '/stride/v1/admin/editions/' . $editionId . '/registrations');
        $request->set_param('id', $editionId);

        $response = rest_get_server()->dispatch($request);

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($adminId);

        $this->assertSame(200, $response->get_status());

        $data  = $response->get_data();
        $items = $data['items'] ?? [];
        $found = null;
        foreach ($items as $item) {
            if (($item['id'] ?? null) === $regId) {
                $found = $item;
                break;
            }
        }
        $this->assertNotNull($found, 'Anon row not found in response');
        $this->assertSame('(anoniem)', $found['user']['name'] ?? null, 'Fallback should be "(anoniem)"');
    }
}
