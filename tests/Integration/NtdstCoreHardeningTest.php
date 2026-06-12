<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Modules\Trajectory\TrajectoryRepository;

/**
 * Regression pins for the three ntdst-core issues closed 2026-06-12:
 *
 * 1. /ntdst/v1/action permission gate — anonymous requests may only dispatch
 *    PUBLIC actions (previously relied indirectly on nonce-minting + each
 *    handler's own login check).
 * 2. Template-path snapshot/negative-cache — a path registered AFTER the
 *    first Response was constructed must still resolve (addPath invalidates
 *    the snapshot; locate() no longer caches misses).
 * 3. Data API valuesMatch scalar comparison — re-saving an int-typed schema
 *    field with its unchanged value (stringified by WP) must NOT roll back
 *    the batch (the 2026-05-20 "save succeeds but nothing persists" bug;
 *    PR #2 was authored from a pre-fix Data.php — this test bites if that
 *    regression ever reapplies).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NtdstCoreHardeningTest
 */
final class NtdstCoreHardeningTest extends IntegrationTestCase
{
    // =========================================================================
    // 1. Action-route auth gate
    // =========================================================================

    private function dispatchAction(string $action): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', '/ntdst/v1/action');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Origin', home_url());
        $request->set_body((string) wp_json_encode(['action' => $action, 'nonce' => 'irrelevant']));

        return rest_do_request($request);
    }

    public function testAnonymousCannotDispatchNonPublicAction(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatchAction('stride_save_trajectory_choices');

        $this->assertContains(
            $response->get_status(),
            [401, 403],
            'anonymous dispatch of a non-public action must be refused at the route'
        );
    }

    public function testAnonymousCanStillReachPublicActions(): void
    {
        wp_set_current_user(0);

        // Public action passes the PERMISSION gate; it then fails on the
        // bogus nonce inside the handler — any status except 401/403 proves
        // the auth gate did not block it.
        $response = $this->dispatchAction('stride_submit_interest');

        $this->assertNotContains(
            $response->get_status(),
            [401, 403],
            'public actions must remain reachable anonymously (interest/waitlist contract)'
        );
    }

    public function testLoggedInUserPassesTheGateForNonPublicActions(): void
    {
        wp_set_current_user(self::$testUserId);

        $response = $this->dispatchAction('stride_save_trajectory_choices');

        $this->assertNotContains(
            $response->get_status(),
            [401, 403],
            'a logged-in user must pass the route gate (nonce validation happens later)'
        );

        wp_set_current_user(0);
    }

    // =========================================================================
    // 2. Late template-path registration
    // =========================================================================

    public function testLateAddPathResolvesAfterEarlierMiss(): void
    {
        $dir = sys_get_temp_dir() . '/ntdst-late-path-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/late-template.php', 'LATE-TEMPLATE-RENDERED');

        try {
            // Miss BEFORE registration (this used to poison the cache)…
            $before = ntdst_response()->html('late-template');
            $this->assertStringContainsString('Template not found', $before);

            // …then register the path late and resolve.
            \NTDST_Template_Loader::addPath($dir);
            $after = ntdst_response()->html('late-template');

            $this->assertSame('LATE-TEMPLATE-RENDERED', $after, 'a late-registered path must resolve despite the earlier miss');
        } finally {
            unlink($dir . '/late-template.php');
            rmdir($dir);
        }
    }

    // =========================================================================
    // 3. valuesMatch scalar comparison (behavioral pin via the Data API)
    // =========================================================================

    public function testResavingUnchangedIntFieldDoesNotRollBackTheBatch(): void
    {
        $trajectoryId = wp_insert_post([
            'post_type' => 'vad_trajectory',
            'post_title' => 'valuesMatch Pin ' . uniqid(),
            'post_status' => 'publish',
        ]);
        self::$testPosts[] = $trajectoryId;

        $repo = ntdst_get(TrajectoryRepository::class);

        // Seed an int-typed schema field; WP stores it stringified.
        $this->assertNotFalse($repo->update($trajectoryId, ['capacity' => 15, 'price' => 100.0]));

        // Update a sibling field while re-sending capacity UNCHANGED. Pre-fix,
        // update_post_meta's no-op false return tripped valuesMatch and rolled
        // the whole batch back ("save succeeds but nothing persists").
        $this->assertNotFalse($repo->update($trajectoryId, ['capacity' => 15, 'price' => 250.0]));

        $this->assertSame(250.0, (float) $repo->getField($trajectoryId, 'price'), 'the changed sibling field must persist');
        $this->assertSame(15, (int) $repo->getField($trajectoryId, 'capacity'));
    }
}
