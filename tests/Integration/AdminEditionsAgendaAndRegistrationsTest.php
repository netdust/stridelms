<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use WP_REST_Request;

/**
 * Characterization tests for two AdminAPIController read paths strangled in
 * Task 2a.5 (cluster 2a-B): getEditionsAgendaView and getEditionRegistrations.
 *
 * These behaviors had only route-registration coverage before the strangle.
 * This test PINS them so the behavior-preserving move of their read SQL into
 * the owning repositories (EditionRepository / RegistrationRepository /
 * SessionRepository) is provably regression-free — green before AND after.
 *
 * Pinned behaviors:
 *  - Agenda view: a session dated within the 2-days-ago default scope appears
 *    as an agenda row carrying both its edition id and its session id.
 *  - getEditionRegistrations: the registration rows for an edition are returned
 *    with their sessions list; the enrollment_data NAME FALLBACK for an
 *    anonymous (user_id = 0) interest/waitlist row is preserved (the behavior
 *    the 2a-A roster deliberately does NOT have — getEditionRegistrations keeps
 *    it).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionsAgendaAndRegistrationsTest
 */
final class AdminEditionsAgendaAndRegistrationsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These methods are admin-scoped; capability is enforced at the route,
        // not inside the method (invoked directly here). Act as administrator.
        $admin = wp_insert_user([
            'user_login' => 'admin_agenda_' . wp_generate_password(6, false),
            'user_pass'  => wp_generate_password(12, false),
            'user_email' => 'admin_agenda_' . wp_generate_password(6, false) . '@example.test',
            'role'       => 'administrator',
        ]);

        if (is_wp_error($admin)) {
            throw new \RuntimeException('Failed to create admin user: ' . $admin->get_error_message());
        }

        $this->actingAs((int) $admin);
    }

    private function controller(): \Stride\Admin\AdminAPIController
    {
        return ntdst_get(\Stride\Admin\AdminAPIController::class);
    }

    /** Create a session post tied to an edition, dated $date. */
    private function makeSession(int $editionId, string $date): int
    {
        $sessionId = wp_insert_post([
            'post_title'  => 'Test Session ' . wp_generate_password(4, false),
            'post_type'   => 'vad_session',
            'post_status' => 'publish',
        ]);

        if (is_wp_error($sessionId)) {
            throw new \RuntimeException('Failed to create test session: ' . $sessionId->get_error_message());
        }

        update_post_meta($sessionId, '_ntdst_edition_id', $editionId);
        update_post_meta($sessionId, '_ntdst_date', $date);
        update_post_meta($sessionId, '_ntdst_start_time', '09:00');

        return (int) $sessionId;
    }

    /**
     * Agenda view: a session dated within the default scope (today) surfaces as
     * an agenda row carrying its edition id AND session id. Pins the agenda
     * SELECT/JOIN being strangled into the repository.
     */
    public function test_agenda_view_lists_session_with_edition_and_session_ids(): void
    {
        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_status'    => 'open',
            '_ntdst_course_id' => 0,
        ]]);
        $today = current_time('Y-m-d');
        $sessionId = $this->makeSession($editionId, $today);

        $req = new WP_REST_Request('GET', '/stride/v1/admin/editions');
        $req->set_param('view', 'agenda');
        $req->set_param('per_page', 200);

        $res = $this->controller()->getEditions($req);
        $data = $res->get_data();

        $this->assertSame('agenda', $data['view'], 'agenda view branch must render agenda payload');

        $matching = array_values(array_filter(
            $data['items'] ?? [],
            static fn($item) => (int) ($item['id'] ?? 0) === $editionId
                && (int) ($item['sessionId'] ?? 0) === $sessionId,
        ));

        $this->assertCount(1, $matching, 'A today-dated session must appear as exactly one agenda row keyed by edition+session id');
        $this->assertSame($today, $matching[0]['date'], 'Agenda row carries the session date verbatim');
    }

    /**
     * getEditionRegistrations: returns registration rows + sessions list, and
     * preserves the enrollment_data NAME FALLBACK for anonymous (user_id = 0)
     * interest/waitlist rows. Pins both behaviors being moved.
     */
    public function test_edition_registrations_preserves_anonymous_enrollment_data_name_fallback(): void
    {
        global $wpdb;

        if (!\Stride\Modules\Enrollment\RegistrationTable::exists()) {
            $this->markTestSkipped('Registration table not present in this DB');
        }

        $editionId = $this->createTestEdition(['meta' => [
            '_ntdst_status'    => 'open',
            '_ntdst_course_id' => 0,
        ]]);
        $this->makeSession($editionId, current_time('Y-m-d'));

        // Anonymous interest row: user_id = 0, name/email live in enrollment_data
        // under the status (stage) key, wrapped as [$status]['data'][...].
        $regTable = \Stride\Modules\Enrollment\RegistrationTable::getTableName();
        $wpdb->insert($regTable, [
            'user_id'         => 0,
            'edition_id'      => $editionId,
            'status'          => 'interest',
            'enrollment_path' => 'individual',
            'registered_at'   => current_time('mysql'),
            'enrollment_data' => wp_json_encode([
                'interest' => ['data' => [
                    'name'  => 'Anoniem Geinteresseerd',
                    'email' => 'anon-interest@example.test',
                ]],
            ]),
        ]);
        $regId = (int) $wpdb->insert_id;

        $req = new WP_REST_Request('GET', '/stride/v1/admin/editions/' . $editionId . '/registrations');
        $req->set_param('id', $editionId);

        $res = $this->controller()->getEditionRegistrations($req);
        $data = $res->get_data();

        $this->assertArrayHasKey('items', $data);
        $this->assertArrayHasKey('sessions', $data, 'Registrations payload carries the sessions list');
        $this->assertNotEmpty($data['sessions'], 'The edition has one session — it must be listed');

        $row = array_values(array_filter(
            $data['items'],
            static fn($item) => (int) ($item['id'] ?? 0) === $regId,
        ));

        $this->assertCount(1, $row, 'The anonymous registration must be returned');
        $this->assertTrue($row[0]['anonymous'], 'A user_id=0 row is flagged anonymous');
        $this->assertSame('Anoniem Geinteresseerd', $row[0]['user']['name'], 'Name falls back to enrollment_data for anon rows (preserved behavior)');
        $this->assertSame('anon-interest@example.test', $row[0]['user']['email'], 'Email falls back to enrollment_data for anon rows (preserved behavior)');

        $wpdb->delete($regTable, ['id' => $regId]);
    }
}
