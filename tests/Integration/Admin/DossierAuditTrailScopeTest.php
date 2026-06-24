<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;
use NTDST\Audit\AuditService;
use NTDST\Audit\AuditTable;
use Stride\Admin\AdminUserService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Task D.1 — dossier per-registration timeline (cluster D backend half).
 *
 * The dossier audit_trail (AdminUserService::getUserDetail) historically matched
 * only `actor_id = U OR (entity_type='user' AND entity_id=U)` — so the lifecycle
 * events the AuditBridge records with entity_type='registration' and
 * context.user_id = U (registration.created/confirmed/cancelled/waitlisted,
 * attendance.marked_*) were INVISIBLE on the person's timeline. Stefan authorised
 * widening this ONE query to also match registration-scoped events for the user.
 *
 * The widened predicate keys on the STORED generated column subject_user_id
 * (= context.user_id, schema v2 of the ntdst-audit table) — indexable and an
 * EXACT per-user match, so a registration event whose context.user_id is a
 * DIFFERENT user can never leak onto this user's timeline.
 *
 * Assertions run against the real persisted ntdst-audit table — no mocks.
 *
 *   POSITIVE — a registration.created with entity_type='registration',
 *              context.user_id=U appears in U's audit_trail.
 *   DENIAL  — a registration.created with context.user_id=OTHER does NOT appear
 *             in U's audit_trail (cross-user-leak guard — the load-bearing case).
 *   PII GATE — without stride_manage the audit_trail is empty (Phase-1 N1 holds).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter DossierAuditTrailScopeTest
 */
final class DossierAuditTrailScopeTest extends IntegrationTestCase
{
    private AdminUserService $service;
    private AuditService $audit;

    /** The dossier subject (the user whose timeline we read). */
    private int $subjectId = 0;
    /** A different user whose registration events must never leak. */
    private int $otherId = 0;
    /** The acting admin (carries stride_manage). */
    private int $adminId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = ntdst_get(AdminUserService::class);
        $this->audit = ntdst_get(AuditService::class);

        // Administrator does NOT implicitly carry stride_manage in the test
        // env (see AdminRolesIntegrationTest / BulkCacheBustTest); add it.
        get_role('administrator')?->add_cap('stride_manage');

        $this->adminId = (int) wp_create_user(
            'dossier_admin_' . wp_generate_password(6, false),
            'testpass',
            'da_' . wp_generate_password(6, false) . '@test.local',
        );
        wp_update_user(['ID' => $this->adminId, 'role' => 'administrator']);

        $this->subjectId = (int) wp_create_user(
            'dossier_subject_' . wp_generate_password(6, false),
            'testpass',
            'ds_' . wp_generate_password(6, false) . '@test.local',
        );

        $this->otherId = (int) wp_create_user(
            'dossier_other_' . wp_generate_password(6, false),
            'testpass',
            'do_' . wp_generate_password(6, false) . '@test.local',
        );

        $this->cleanAudit();
    }

    protected function tearDown(): void
    {
        $this->cleanAudit();

        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([$this->adminId, $this->subjectId, $this->otherId] as $id) {
            if ($id) {
                wp_delete_user($id);
            }
        }

        parent::tearDown();
    }

    private function cleanAudit(): void
    {
        global $wpdb;
        $table = AuditTable::getTableName();
        $wpdb->query("DELETE FROM {$table}");
    }

    /**
     * Record a registration-scoped lifecycle event the way AuditBridge does:
     * entity_type='registration', context.user_id set (so subject_user_id is
     * populated by the generated column), plus an edition_id for enrichment.
     *
     * The actor is the ADMIN (not the subject) — mirroring an admin-enrolled
     * registration (enrolled_by). This is load-bearing for the RED proof: if
     * the actor were the subject, the OLD narrow `actor_id = U` clause would
     * already match and the positive assertion could not go RED, masking the
     * widening. With the admin as actor the event reaches the subject's
     * timeline ONLY through the widened subject_user_id predicate.
     */
    private function recordRegistrationEvent(int $registrationId, int $forUser, int $editionId): void
    {
        $this->audit->record(
            'registration',
            $registrationId,
            'registration.created',
            $this->adminId, // actor = admin who enrolled the user, NOT the subject
            [
                'user_id' => $forUser,
                'edition_id' => $editionId,
                'enrollment_path' => 'individual',
            ],
        );
    }

    private function fetchDossier(int $userId): WP_REST_Response
    {
        $request = new WP_REST_Request('GET', '/stride/v1/admin/users/' . $userId . '/detail');
        $request->set_param('id', $userId);

        $response = $this->service->getUserDetail($request);
        $this->assertInstanceOf(WP_REST_Response::class, $response, 'dossier should return a response');

        return $response;
    }

    /** @test */
    public function registrationScopedEventForTheUserAppearsInDossierTimeline(): void
    {
        $this->actingAs($this->adminId);
        $this->assertTrue(current_user_can('stride_manage'), 'acting admin must carry stride_manage');

        $editionId = $this->createTestEdition(['post_title' => 'Dossier Editie']);
        $this->recordRegistrationEvent(9001, $this->subjectId, $editionId);

        $data = $this->fetchDossier($this->subjectId)->get_data();

        $trail = $data['audit_trail'] ?? [];
        $this->assertNotEmpty($trail, 'registration-scoped event must surface on the subject timeline');

        // The mapper resolves registration.created to an enrollment line naming
        // the enriched edition title — proves the event flowed through
        // enrichAuditContexts and the mapper unchanged.
        $enrollment = array_values(array_filter(
            $trail,
            static fn($line) => ($line['type'] ?? '') === 'enrollment'
                && str_contains($line['text'] ?? '', 'Dossier Editie'),
        ));
        $this->assertCount(1, $enrollment, 'the registration.created line surfaces, enriched with the edition title');
        $this->assertGreaterThan(0, $data['audit_trail_total'] ?? 0, 'count query widened too');
    }

    /** @test */
    public function registrationEventForAnotherUserDoesNotLeakIntoThisUsersTimeline(): void
    {
        $this->actingAs($this->adminId);

        $editionId = $this->createTestEdition(['post_title' => 'Andere Editie']);

        // A registration event whose context.user_id is OTHER, not the subject.
        $this->recordRegistrationEvent(9002, $this->otherId, $editionId);

        $data = $this->fetchDossier($this->subjectId)->get_data();

        $trail = $data['audit_trail'] ?? [];

        // The cross-user-leak guard: the OTHER user's registration line must
        // NOT appear on the subject's timeline.
        $leaked = array_values(array_filter(
            $trail,
            static fn($line) => str_contains($line['text'] ?? '', 'Andere Editie'),
        ));
        $this->assertSame([], $leaked, 'another user\'s registration event must never leak onto this dossier');
    }

    /** @test */
    public function piiGateStillHidesAuditTrailWithoutManageCap(): void
    {
        // A viewer with neither administrator nor stride_manage.
        $viewerId = (int) wp_create_user(
            'dossier_viewer_' . wp_generate_password(6, false),
            'testpass',
            'dv_' . wp_generate_password(6, false) . '@test.local',
        );
        wp_update_user(['ID' => $viewerId, 'role' => 'subscriber']);
        $this->actingAs($viewerId);
        $this->assertFalse(current_user_can('stride_manage'), 'viewer must NOT carry stride_manage');

        $editionId = $this->createTestEdition(['post_title' => 'Gated Editie']);
        $this->recordRegistrationEvent(9003, $this->subjectId, $editionId);

        $data = $this->fetchDossier($this->subjectId)->get_data();

        $this->assertSame([], $data['audit_trail'] ?? ['x'], 'audit_trail gated off without stride_manage');
        $this->assertSame(0, $data['audit_trail_total'] ?? -1, 'audit_trail_total gated off without stride_manage');

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($viewerId);
    }
}
