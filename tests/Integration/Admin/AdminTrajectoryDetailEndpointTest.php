<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;

/**
 * Integration test for GET /stride/v1/admin/trajectories/{id} (the detail
 * endpoint backing the admin trajectory slide-over).
 *
 * Regression guard for the Cluster-4 shake-out finding F1: getTrajectory()
 * reused the LIST endpoint capped at per_page=100 (ID-desc) and linear-scanned
 * for the id, so any trajectory outside the first 100 returned 404 even though
 * it was a valid published vad_trajectory. The fix scopes the reused list query
 * to the target's title, so the trajectory is found regardless of list position.
 *
 * Run: ddev exec bash -c "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminTrajectoryDetailEndpoint"
 */
final class AdminTrajectoryDetailEndpointTest extends IntegrationTestCase
{
    private static ?int $coordinatorUserId = null;
    private static ?int $targetTrajId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        do_action('rest_api_init');

        $coordinatorUsername = 'coord_t4det_' . uniqid();
        self::$coordinatorUserId = wp_create_user(
            $coordinatorUsername,
            'testpass123',
            $coordinatorUsername . '@test.local',
        );
        $coord = get_user_by('ID', self::$coordinatorUserId);
        $coord->set_role('stride_coordinator');

        // The target trajectory — created FIRST, so it has the LOWEST id and
        // (ID/date-desc) sits at the BOTTOM of the list.
        self::$targetTrajId = wp_insert_post([
            'post_title'  => 'Detail Target ' . uniqid(),
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
        ]);

        // Push it off page 1: create 105 NEWER trajectories with distinct titles,
        // so an ID-desc per_page=100 list page can no longer contain the target.
        for ($i = 0; $i < 105; $i++) {
            wp_insert_post([
                'post_title'  => 'Filler Trajectory ' . $i . ' ' . uniqid(),
                'post_type'   => 'vad_trajectory',
                'post_status' => 'publish',
            ]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        // Best-effort cleanup of the filler posts to limit seed accumulation.
        $all = get_posts([
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
            'numberposts' => -1,
            's'           => 'Filler Trajectory',
            'fields'      => 'ids',
        ]);
        foreach ($all as $id) {
            wp_delete_post((int) $id, true);
        }
        if (self::$targetTrajId) {
            wp_delete_post(self::$targetTrajId, true);
        }
        if (self::$coordinatorUserId) {
            wp_delete_user(self::$coordinatorUserId);
        }
        parent::tearDownAfterClass();
    }

    private function dispatch(string $method, string $path, array $params = []): \WP_REST_Response|\WP_Error
    {
        wp_set_current_user(self::$coordinatorUserId);
        $request = new \WP_REST_Request($method, $path);
        foreach ($params as $k => $v) {
            $request->set_param($k, $v);
        }
        return rest_do_request($request);
    }

    /**
     * The load-bearing regression: a valid trajectory that is NOT in the first
     * 100 of the ID-desc list must still resolve via the detail endpoint.
     * RED before the fix (404 — capped-page scan misses it); GREEN after.
     */
    public function testTrajectoryBeyondFirstHundredIsStillFound(): void
    {
        $response = $this->dispatch(
            'GET',
            '/stride/v1/admin/trajectories/' . self::$targetTrajId,
        );

        $this->assertNotInstanceOf(
            \WP_Error::class,
            $response,
            'Detail endpoint returned WP_Error for a valid trajectory beyond page 1',
        );
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(self::$targetTrajId, $data['id']);
    }

    /** A genuinely non-existent id still 404s (the guard is preserved). */
    public function testUnknownTrajectoryStillReturns404(): void
    {
        // rest_do_request wraps the handler's WP_Error into a 404 response.
        $response = $this->dispatch('GET', '/stride/v1/admin/trajectories/99999999');
        $this->assertSame(404, $response->get_status());
    }
}
