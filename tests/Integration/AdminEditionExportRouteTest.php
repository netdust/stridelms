<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Tier-A endpoint contract for GET /admin/editions/{id}/export/{type}
 * (Phase 2a, Task 2a.10, CM-4).
 *
 * The route surfaces the 5 existing Edition exporters as one-click roster
 * downloads. Because each exporter EGRESSES PII (the full, non-field-scoped
 * roster incl. names/email/billing), the route is stricter than the roster
 * READ: it gates on `canManageAdmin` (`stride_manage`), not `canViewAdmin`.
 *
 * Load-bearing properties — asserted by driving the REAL route via
 * rest_do_request (un-mocked route -> permission -> callback chain). We assert
 * the DISPATCH + GUARD, NOT the streamed file bytes (the exporters' existing
 * tested behaviour; a successful dispatch terminates with exit/headers which a
 * test harness cannot cleanly capture):
 *
 *  1. CM-4 — an unknown / class-name {type} is REJECTED (400/404), NEVER
 *     dispatched as a class lookup. The fixed allowlist is
 *     {attendance, registration, namecard, bundle, files}.
 *  2. A `stride_view`-only supervisor -> 403 (export is canManageAdmin, PII
 *     egress — RED-first denial path); an unauthenticated request -> 401/403.
 *  3. {id} absint'd + edition-exists (+ post_status=publish per CR-4): a
 *     missing / trashed / draft edition -> 404.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter AdminEditionExportRoute
 */
final class AdminEditionExportRouteTest extends IntegrationTestCase
{
    private static ?int $managerId = null;     // stride_coordinator -> stride_manage + stride_view
    private static ?int $supervisorId = null;  // stride_supervisor -> stride_view ONLY
    private static ?int $editionId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        $mgr = 'export_mgr_' . uniqid();
        self::$managerId = (int) wp_create_user($mgr, 'pass123', $mgr . '@test.local');
        get_user_by('ID', self::$managerId)->set_role('stride_coordinator');

        $sup = 'export_sup_' . uniqid();
        self::$supervisorId = (int) wp_create_user($sup, 'pass123', $sup . '@test.local');
        get_user_by('ID', self::$supervisorId)->set_role('stride_supervisor');

        self::$editionId = (int) wp_insert_post([
            'post_title'  => 'Export Route Edition ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        update_post_meta(self::$editionId, '_ntdst_status', 'open');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$editionId) {
            wp_delete_post(self::$editionId, true);
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([self::$managerId, self::$supervisorId] as $uid) {
            if ($uid) {
                wp_delete_user($uid);
            }
        }
        parent::tearDownAfterClass();
    }

    private function dispatch(string $id, string $type): \WP_REST_Response|\WP_Error
    {
        return rest_do_request(
            new \WP_REST_Request('GET', '/stride/v1/admin/editions/' . $id . '/export/' . $type),
        );
    }

    private function statusOf(\WP_REST_Response|\WP_Error $response): int
    {
        return $response instanceof \WP_Error
            ? (int) ($response->get_error_data()['status'] ?? 0)
            : $response->get_status();
    }

    // =========================================================================
    // 1. CM-4 — unknown / class-name {type} is rejected, never class-dispatched
    // =========================================================================

    /** @test */
    public function unknownTypeIsRejectedNotDispatched(): void
    {
        $this->actingAs(self::$managerId);

        $response = $this->dispatch((string) self::$editionId, 'totally-bogus');
        $this->assertContains(
            $this->statusOf($response),
            [400, 404],
            'an unknown {type} must be rejected (400/404), never resolved (CM-4)',
        );
    }

    /** @test */
    public function classNameTypeIsRejectedNotDispatched(): void
    {
        $this->actingAs(self::$managerId);

        // A request-supplied class name must hit the allowlist, NOT a class
        // lookup (the CM-4 attack: dispatch by request-controlled class).
        $response = $this->dispatch((string) self::$editionId, 'EditionRegistrationExporter');
        $this->assertContains(
            $this->statusOf($response),
            [400, 404],
            'a class-name {type} must NOT dispatch — the route maps a fixed allowlist server-side (CM-4)',
        );
    }

    // =========================================================================
    // 2. Authz — supervisor (view-only) denied; anon denied
    // =========================================================================

    /** @test */
    public function viewOnlySupervisorIsDenied(): void
    {
        // stride_supervisor has stride_view but NOT stride_manage. The export
        // egresses PII, so it is canManageAdmin — a view-only actor must NOT
        // reach a valid export type (RED-first denial path).
        $this->actingAs(self::$supervisorId);

        $response = $this->dispatch((string) self::$editionId, 'registration');
        $this->assertContains(
            $this->statusOf($response),
            [401, 403],
            'a stride_view-only supervisor must be denied (export is canManageAdmin, PII egress)',
        );
    }

    /** @test */
    public function anonymousRequestIsDenied(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatch((string) self::$editionId, 'registration');
        $this->assertContains(
            $this->statusOf($response),
            [401, 403],
            'an unauthenticated request must be denied by the canManageAdmin permission_callback',
        );
    }

    // =========================================================================
    // 3. {id} absint + edition-exists + publish (CR-4)
    // =========================================================================

    /** @test */
    public function nonExistentEditionReturns404(): void
    {
        $this->actingAs(self::$managerId);

        $response = $this->dispatch('99999999', 'registration');
        $this->assertSame(404, $this->statusOf($response), 'a missing edition id must 404 before any export');
    }

    /** @test */
    public function garbageEditionIdIsAbsintedAndRejected(): void
    {
        $this->actingAs(self::$managerId);

        $response = $this->dispatch('0', 'registration');
        $this->assertSame(404, $this->statusOf($response), 'an id that absints to 0 must 404 (CM-5)');
    }

    /** @test */
    public function trashedEditionExportIsNotReachable(): void
    {
        $this->actingAs(self::$managerId);

        $editionId = (int) wp_insert_post([
            'post_title'  => 'Trashed Export Edition ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        wp_trash_post($editionId);

        try {
            $response = $this->dispatch((string) $editionId, 'registration');
            $this->assertSame(
                404,
                $this->statusOf($response),
                'a trashed edition is no longer published — its PII export must NOT be reachable (CR-4)',
            );
        } finally {
            wp_delete_post($editionId, true);
        }
    }

    /** @test */
    public function draftEditionExportIsNotReachable(): void
    {
        $this->actingAs(self::$managerId);

        $editionId = (int) wp_insert_post([
            'post_title'  => 'Draft Export Edition ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'draft',
        ]);

        try {
            $response = $this->dispatch((string) $editionId, 'registration');
            $this->assertSame(
                404,
                $this->statusOf($response),
                'a draft edition is not published — its PII export must NOT be reachable (CR-4)',
            );
        } finally {
            wp_delete_post($editionId, true);
        }
    }
}
