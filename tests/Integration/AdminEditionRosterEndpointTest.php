<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Tier-A endpoint contract for GET /admin/editions/{id}/roster (Phase 2a, Task 2a.3).
 *
 * The route wires the existing AdminEditionRosterService::getRosterForEdition read-model
 * (Tasks 2a.1/2a.2) into a REST surface. This file is the ENDPOINT test (the SERVICE
 * contract lives in AdminEditionRosterTest). Load-bearing properties — all asserted by
 * driving the REAL route via rest_do_request (un-mocked route -> permission -> callback
 * -> service -> DB chain):
 *
 *  1. canViewAdmin user -> 200 with the composed roster ({edition_id, rows[], extras_keys[]});
 *     each row carries name/organisation/selections/attendance/extras (the service shape).
 *  2. Unauthenticated request -> DENIED 401/403 (M1 — the route permission_callback is
 *     canViewAdmin, NOT __return_true; this is the RED-first denial path).
 *  3. A non-existent {id} -> 404 (edition-exists guard, INV-4 bubble not swallow).
 *  4. A garbage/non-numeric {id} -> absint'd to 0 -> 404 (CM-5 — never reaches the service
 *     as an un-sanitised string).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionRosterEndpoint
 */
final class AdminEditionRosterEndpointTest extends IntegrationTestCase
{
    private static ?int $coordinatorUserId = null;
    private static ?int $editionId = null;
    private static ?int $userId = null;
    private static ?int $regId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        // Coordinator (stride_coordinator role => stride_view cap) for the authorised dispatch.
        $coordName = 'roster_ep_coord_' . uniqid();
        self::$coordinatorUserId = (int) wp_create_user($coordName, 'pass123', $coordName . '@test.local');
        get_user_by('ID', self::$coordinatorUserId)->set_role('stride_coordinator');

        // A minimal edition with one confirmed registrant so the roster is non-empty.
        self::$editionId = (int) wp_insert_post([
            'post_title'  => 'Roster Endpoint Edition ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        update_post_meta(self::$editionId, '_ntdst_status', 'open');
        update_post_meta(self::$editionId, '_ntdst_capacity', 20);

        $uName = 'roster_ep_user_' . uniqid();
        self::$userId = (int) wp_create_user($uName, 'pass123', $uName . '@test.local');
        update_user_meta(self::$userId, 'first_name', 'Rik');
        update_user_meta(self::$userId, 'last_name', 'Rooster');

        $repo = ntdst_get(RegistrationRepository::class);
        $reg = $repo->create([
            'user_id'    => self::$userId,
            'edition_id' => self::$editionId,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        if (is_wp_error($reg) || !is_int($reg) || $reg <= 0) {
            throw new \RuntimeException('Failed to seed roster registration');
        }
        self::$regId = (int) $reg;
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;

        if (self::$regId) {
            $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => self::$regId]);
        }
        if (self::$editionId) {
            wp_delete_post(self::$editionId, true);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([self::$userId, self::$coordinatorUserId] as $uid) {
            if ($uid) {
                wp_delete_user($uid);
            }
        }

        parent::tearDownAfterClass();
    }

    private function dispatch(string $id): \WP_REST_Response|\WP_Error
    {
        return rest_do_request(new \WP_REST_Request('GET', '/stride/v1/admin/editions/' . $id . '/roster'));
    }

    private function statusOf(\WP_REST_Response|\WP_Error $response): int
    {
        return $response instanceof \WP_Error
            ? (int) ($response->get_error_data()['status'] ?? 0)
            : $response->get_status();
    }

    // =========================================================================
    // 1. Authorised actor -> 200 with the composed roster
    // =========================================================================

    /** @test */
    public function authorisedActorReceivesComposedRoster(): void
    {
        $this->actingAs(self::$coordinatorUserId);

        $response = $this->dispatch((string) self::$editionId);
        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertIsArray($data);
        // The read-model shape from AdminEditionRosterService::getRosterForEdition.
        $this->assertSame((int) self::$editionId, (int) $data['edition_id']);
        $this->assertArrayHasKey('rows', $data);
        $this->assertArrayHasKey('extras_keys', $data);
        $this->assertNotEmpty($data['rows'], 'the seeded registrant must appear in the roster');

        $row = $data['rows'][0];
        // The endpoint returns the service rows verbatim (thin delegator — no reshape).
        $this->assertArrayHasKey('registration_id', $row);
        $this->assertArrayHasKey('name', $row);
        $this->assertArrayHasKey('selections', $row);
        $this->assertArrayHasKey('attendance', $row);
        $this->assertArrayHasKey('extras', $row);
    }

    // =========================================================================
    // 2. Unauthenticated -> denied (M1, the route permission_callback)
    // =========================================================================

    /** @test */
    public function unauthenticatedRequestIsDenied(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatch((string) self::$editionId);
        $this->assertContains(
            $this->statusOf($response),
            [401, 403],
            'anon must be denied by the canViewAdmin permission_callback (M1) — NOT __return_true',
        );
    }

    // =========================================================================
    // 3. Non-existent {id} -> 404 (edition-exists guard, INV-4)
    // =========================================================================

    /** @test */
    public function nonExistentEditionReturns404(): void
    {
        $this->actingAs(self::$coordinatorUserId);

        $response = $this->dispatch('99999999');
        $this->assertSame(404, $this->statusOf($response), 'a missing edition id must bubble a 404 WP_Error');
    }

    // =========================================================================
    // 4. Garbage {id} -> absint -> 0 -> 404 (CM-5)
    // =========================================================================

    /** @test */
    public function garbageEditionIdIsAbsintedAndRejected(): void
    {
        $this->actingAs(self::$coordinatorUserId);

        // The route's \d+ pattern + absint means a garbage id never reaches the service
        // as a string; "0" stands in for the absint(0) outcome (no edition 0 exists).
        $response = $this->dispatch('0');
        $this->assertSame(
            404,
            $this->statusOf($response),
            'an id that absints to 0 must 404, never reach the service as a raw value (CM-5)',
        );
    }

    // =========================================================================
    // 5. Non-published edition -> 404 (CR-4 — trashed/draft PII rosters unreachable)
    // =========================================================================

    /**
     * A trashed edition is a real WP_Post of the right post_type, so a post_type-only
     * guard lets it through and leaks the FULL PII roster for an edition the admin UI
     * no longer lists. The guard must scope post_status = 'publish' to match every
     * sibling DATA query in this controller. 404 (not 403) — do not reveal existence.
     *
     * @test
     */
    public function trashedEditionRosterIsNotReachable(): void
    {
        $this->actingAs(self::$coordinatorUserId);

        $editionId = (int) wp_insert_post([
            'post_title'  => 'Trashed Roster Edition ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        wp_trash_post($editionId);

        try {
            $response = $this->dispatch((string) $editionId);
            $this->assertSame(
                404,
                $this->statusOf($response),
                'a trashed edition is no longer published — its PII roster must NOT be reachable (CR-4)',
            );
        } finally {
            wp_delete_post($editionId, true);
        }
    }

    /**
     * A draft edition was never published; its roster must be unreachable for the same
     * reason — post_status scoping, not just post_type, gates an edition's data.
     *
     * @test
     */
    public function draftEditionRosterIsNotReachable(): void
    {
        $this->actingAs(self::$coordinatorUserId);

        $editionId = (int) wp_insert_post([
            'post_title'  => 'Draft Roster Edition ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'draft',
        ]);

        try {
            $response = $this->dispatch((string) $editionId);
            $this->assertSame(
                404,
                $this->statusOf($response),
                'a draft edition is not published — its PII roster must NOT be reachable (CR-4)',
            );
        } finally {
            wp_delete_post($editionId, true);
        }
    }
}
