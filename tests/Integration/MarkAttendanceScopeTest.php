<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Attendance\AttendanceRepository;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Tier-A security contract for POST /admin/attendance (Phase 2a, Task 2a.6 — CM-2).
 *
 * The route is already authz-gated (canManageAdmin / stride_manage, M2). What it did NOT
 * verify is that the user being marked is actually REGISTERED in the edition the session
 * belongs to. AttendanceService::mark resolves the editionId from the session's OWN
 * edition_id (AttendanceService.php:83), so the write always lands on the session's true
 * edition — the residual harm (per the I1 risk restatement) is recording attendance + firing
 * the auto-completion side effects for a user against a session in an edition they are NOT
 * registered in.
 *
 * All assertions drive the REAL route via rest_do_request (un-mocked route -> permission ->
 * callback -> service -> DB chain). Load-bearing properties:
 *
 *  1. CM-2 denial (RED-first): a (session, user) where the user is NOT registered in the
 *     session's edition -> WP_Error('session_edition_mismatch') / 400, and NO attendance row
 *     recorded (so no auto-completion event could have fired).
 *  2. Happy path preserved: a user registered in the session's edition -> marks successfully
 *     (present), and the row persists.
 *  3. D6: clearing attendance still works after the dead-$wpdb removal (mark then clear ->
 *     record gone).
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'MarkAttendance'
 */
final class MarkAttendanceScopeTest extends IntegrationTestCase
{
    private static ?int $managerUserId = null;
    private static ?int $editionA = null;
    private static ?int $editionB = null;
    private static ?int $sessionA = null;
    private static ?int $registeredUserId = null;   // registered in edition A
    private static ?int $foreignUserId = null;       // registered only in edition B
    private static ?int $regAId = null;
    private static ?int $regBId = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        do_action('rest_api_init');

        // Coordinator carries stride_manage (canManageAdmin) — the authorised marker (M2).
        $coordName = 'attn_scope_mgr_' . uniqid();
        self::$managerUserId = (int) wp_create_user($coordName, 'pass123', $coordName . '@test.local');
        get_user_by('ID', self::$managerUserId)->set_role('stride_coordinator');

        self::$editionA = self::makeEdition('Attendance Scope Edition A');
        self::$editionB = self::makeEdition('Attendance Scope Edition B');

        // A session that belongs to edition A.
        self::$sessionA = (int) wp_insert_post([
            'post_title'  => 'Scope Session A ' . uniqid(),
            'post_type'   => 'vad_session',
            'post_status' => 'publish',
        ]);
        update_post_meta(self::$sessionA, '_ntdst_edition_id', self::$editionA);

        $repo = ntdst_get(RegistrationRepository::class);

        // User registered in edition A (the session's true edition) — happy path.
        $regName = 'attn_scope_reg_' . uniqid();
        self::$registeredUserId = (int) wp_create_user($regName, 'pass123', $regName . '@test.local');
        $regA = $repo->create([
            'user_id'    => self::$registeredUserId,
            'edition_id' => self::$editionA,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        self::$regAId = is_int($regA) ? $regA : 0;

        // User registered ONLY in edition B — NOT in the session's edition (CM-2 target).
        $foreignName = 'attn_scope_foreign_' . uniqid();
        self::$foreignUserId = (int) wp_create_user($foreignName, 'pass123', $foreignName . '@test.local');
        $regB = $repo->create([
            'user_id'    => self::$foreignUserId,
            'edition_id' => self::$editionB,
            'status'     => RegistrationStatus::Confirmed->value,
        ]);
        self::$regBId = is_int($regB) ? $regB : 0;

        if (!self::$regAId || !self::$regBId) {
            throw new \RuntimeException('Failed to seed attendance-scope registrations');
        }
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;

        foreach ([self::$regAId, self::$regBId] as $regId) {
            if ($regId) {
                $wpdb->delete($wpdb->prefix . 'vad_registrations', ['id' => $regId]);
            }
        }
        foreach ([self::$sessionA, self::$editionA, self::$editionB] as $postId) {
            if ($postId) {
                wp_delete_post($postId, true);
            }
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ([self::$registeredUserId, self::$foreignUserId, self::$managerUserId] as $uid) {
            if ($uid) {
                wp_delete_user($uid);
            }
        }

        parent::tearDownAfterClass();
    }

    private static function makeEdition(string $title): int
    {
        $id = (int) wp_insert_post([
            'post_title'  => $title . ' ' . uniqid(),
            'post_type'   => 'vad_edition',
            'post_status' => 'publish',
        ]);
        update_post_meta($id, '_ntdst_status', 'open');
        update_post_meta($id, '_ntdst_capacity', 20);

        return $id;
    }

    private function dispatch(int $sessionId, int $userId, ?string $status): \WP_REST_Response|\WP_Error
    {
        $request = new \WP_REST_Request('POST', '/stride/v1/admin/attendance');
        $request->set_param('session_id', $sessionId);
        $request->set_param('user_id', $userId);
        if ($status !== null) {
            $request->set_param('status', $status);
        }

        return rest_do_request($request);
    }

    private function statusOf(\WP_REST_Response|\WP_Error $response): int
    {
        return $response instanceof \WP_Error
            ? (int) ($response->get_error_data()['status'] ?? 0)
            : $response->get_status();
    }

    /**
     * rest_do_request converts a handler-returned WP_Error into a WP_REST_Response whose
     * body carries {code, message, data}. Normalise to the error code regardless of shape.
     */
    private function errorCodeOf(\WP_REST_Response|\WP_Error $response): ?string
    {
        if ($response instanceof \WP_Error) {
            return $response->get_error_code();
        }
        $data = $response->get_data();

        return is_array($data) ? ($data['code'] ?? null) : null;
    }

    // =========================================================================
    // 1. CM-2 denial (RED-first, load-bearing)
    // =========================================================================

    /**
     * A user registered only in edition B cannot have attendance recorded against a session
     * in edition A. Today the controller validates session-exists + user-exists only and would
     * record the row (and fire the auto-completion event). The guard must reject it with
     * session_edition_mismatch / 400, and crucially must NOT write any attendance row.
     *
     * @test
     */
    public function userNotRegisteredInSessionsEditionIsRejectedAndNothingRecorded(): void
    {
        $this->actingAs(self::$managerUserId);

        $response = $this->dispatch(self::$sessionA, self::$foreignUserId, 'present');

        // rest_do_request normalises a handler-returned WP_Error into a 400 response whose
        // body carries the error code — assert on the normalised shape (CM-2).
        $this->assertSame(
            400,
            $this->statusOf($response),
            'marking a user not registered in the session edition must be rejected 400 (CM-2)',
        );
        $this->assertSame('session_edition_mismatch', $this->errorCodeOf($response));

        // The side-effect proof: no attendance row exists, so no auto-completion event could fire.
        $attendanceRepo = ntdst_get(AttendanceRepository::class);
        $row = $attendanceRepo->findBySessionAndUser(self::$sessionA, self::$foreignUserId);
        $this->assertNull(
            $row,
            'a rejected cross-edition mark must NOT persist an attendance record (no side effects)',
        );
    }

    /**
     * CM-2 lookup-inconsistency guard (RED-first): the controller's session-exists
     * check uses get_post() (WP post-object cache) while the CM-2 scope derives the
     * edition from SessionService::getSession() (the data-layer find()). The two are
     * DIFFERENT lookup paths — getSession() returns null when the data-layer find()
     * yields a WP_Error (cache/lookup edge) even after get_post() resolved the post.
     * Before the fix the controller did getSession($id)['edition_id'] on that ?array,
     * a PHP 8 null-offset fatal/500. The guard must turn that lookup inconsistency
     * into a clean invalid_session / 404 — never a crash.
     *
     * Reproduced by registering a SessionService whose getSession() returns null
     * (the data-layer-find-failed state) while the real vad_session post still
     * resolves via get_post(), exactly the divergence the finding names.
     *
     * @test
     */
    public function sessionThatGetPostResolvesButGetSessionNullsReturnsCleanDenialNotFatal(): void
    {
        $this->actingAs(self::$managerUserId);

        $realSessionService = ntdst_get(SessionService::class);
        // A SessionService double whose getSession() returns null — the exact state
        // the data-layer find() WP_Error produces. Duck-typed: the controller calls
        // ->getSession() on the container result, no instanceof assertion.
        ntdst_set(SessionService::class, new class {
            public function getSession(int $sessionId): ?array
            {
                return null;
            }
        });

        try {
            // get_post(sessionA) still resolves (real published vad_session), so the
            // controller's session-exists check passes and execution reaches the
            // CM-2 edition resolution — which is exactly where the null offset was.
            $response = $this->dispatch(self::$sessionA, self::$registeredUserId, 'present');
        } finally {
            ntdst_set(SessionService::class, $realSessionService);
        }

        $this->assertSame(
            404,
            $this->statusOf($response),
            'a session that get_post-resolves but getSession-nulls must be a clean 404, not a fatal',
        );
        $this->assertSame(
            'invalid_session',
            $this->errorCodeOf($response),
            'a lookup inconsistency is invalid_session (honest), not session_edition_mismatch',
        );

        // No write may have happened on the crash path either.
        $attendanceRepo = ntdst_get(AttendanceRepository::class);
        $this->assertNull(
            $attendanceRepo->findBySessionAndUser(self::$sessionA, self::$registeredUserId),
            'a null-getSession denial must not persist an attendance record',
        );
    }

    // =========================================================================
    // 2. Happy path preserved
    // =========================================================================

    /**
     * A user registered in the session's own edition marks successfully.
     *
     * @test
     */
    public function userRegisteredInSessionsEditionMarksSuccessfully(): void
    {
        $this->actingAs(self::$managerUserId);

        $response = $this->dispatch(self::$sessionA, self::$registeredUserId, 'present');

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertSame('present', $data['status']);

        $attendanceRepo = ntdst_get(AttendanceRepository::class);
        $row = $attendanceRepo->findBySessionAndUser(self::$sessionA, self::$registeredUserId);
        $this->assertNotNull($row, 'a registered user must have an attendance row recorded');
    }

    // =========================================================================
    // 3. D6 — clearing still works after dead-$wpdb removal (behavior-preserving)
    // =========================================================================

    /**
     * Mark then clear (empty status) — the record must be gone. Proves the clear-branch
     * still deletes via the repository after the dead `global $wpdb;` block was removed.
     *
     * @test
     */
    public function clearingAttendanceStillDeletesTheRecord(): void
    {
        $this->actingAs(self::$managerUserId);

        // Arrange: ensure a record exists.
        $markResponse = $this->dispatch(self::$sessionA, self::$registeredUserId, 'present');
        $this->assertInstanceOf(\WP_REST_Response::class, $markResponse);

        $attendanceRepo = ntdst_get(AttendanceRepository::class);
        $this->assertNotNull(
            $attendanceRepo->findBySessionAndUser(self::$sessionA, self::$registeredUserId),
            'precondition: a record must exist before clearing',
        );

        // Act: clear it (empty status).
        $clearResponse = $this->dispatch(self::$sessionA, self::$registeredUserId, '');

        // Assert: cleared, and the record is gone.
        $this->assertInstanceOf(\WP_REST_Response::class, $clearResponse);
        $this->assertSame(200, $clearResponse->get_status());
        $this->assertNull($clearResponse->get_data()['status']);
        $this->assertNull(
            $attendanceRepo->findBySessionAndUser(self::$sessionA, self::$registeredUserId),
            'clearing must delete the attendance record (D6 behavior-preserving)',
        );
    }
}
