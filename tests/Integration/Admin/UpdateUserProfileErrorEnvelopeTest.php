<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Admin;

use IntegrationTestCase;

/**
 * Task C2 (admin-backend-cleanup, Cluster D, INV-4).
 *
 * The admin controller used to hand-roll two error-envelope shapes:
 * 14 sites returned WP_Error, but updateUserProfile + revealSensitiveField
 * (+ AdminUserService) returned `new WP_REST_Response(['error' => $msg], 4xx)`.
 * The Phase-2 frontend must parse ONE shape. C2 converts the ad-hoc envelopes
 * to WP_Error('<code>', $msg, ['status' => 4xx]) — the framework convention.
 *
 * CLIENT-OBSERVABLE behaviour at the HTTP-status level is unchanged: the WP
 * REST stack converts a returned WP_Error to a WP_REST_Response with the same
 * status (error_to_response). The BODY shape changes deliberately:
 *   old: { error: "<message>" }
 *   new: { code: "<slug>", message: "<message>", data: { status: 4xx } }
 *
 * This test drives the updateUserProfile denial paths through the REAL REST
 * server (un-mocked seam) and asserts each now returns the WP_Error body shape
 * with the SAME 4xx status and the SAME Dutch message text preserved verbatim.
 *
 * Run: ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter UpdateUserProfileErrorEnvelopeTest
 */
final class UpdateUserProfileErrorEnvelopeTest extends IntegrationTestCase
{
    private ?int $adminId = null;

    /** A second user whose email we hijack for the "email in use" path. */
    private ?int $otherUserId = null;

    protected function setUp(): void
    {
        parent::setUp();

        do_action('rest_api_init');

        $admin = wp_create_user(
            'admin_c2_test_' . wp_generate_password(6, false),
            'testpass',
            'adminc2_' . wp_generate_password(6, false) . '@test.local'
        );
        $this->assertIsInt($admin, 'Failed to create admin user');
        wp_update_user(['ID' => $admin, 'role' => 'administrator']);
        $this->adminId = $admin;
    }

    protected function tearDown(): void
    {
        require_once ABSPATH . 'wp-admin/includes/user.php';

        if ($this->otherUserId) {
            wp_delete_user($this->otherUserId);
            $this->otherUserId = null;
        }
        if ($this->adminId) {
            wp_delete_user($this->adminId);
            $this->adminId = null;
        }

        delete_user_meta(self::$testUserId, '_stride_anonymised_at');

        parent::tearDown();
    }

    /**
     * A non-existent target user → 404 with the WP_Error body shape
     * ({code, message, data.status}), NOT the old {error: ...} envelope.
     */
    public function testMissingUserReturnsWpErrorShapeWith404(): void
    {
        $response = $this->dispatchUpdate(99999999, ['first_name' => 'X']);

        $this->assertSame(404, $response->get_status());
        $this->assertWpErrorShape($response, 404);
    }

    /**
     * Editing an ANONYMISED user is forbidden → 403 with the WP_Error shape
     * and the Dutch message preserved verbatim. This is the denial path the
     * plan's test contract names.
     */
    public function testAnonymisedUserReturnsWpErrorShapeWith403AndDutchMessage(): void
    {
        update_user_meta(self::$testUserId, '_stride_anonymised_at', time());

        $response = $this->dispatchUpdate(self::$testUserId, ['first_name' => 'X']);

        $this->assertSame(403, $response->get_status());
        $data = $this->assertWpErrorShape($response, 403);
        $this->assertSame('anonymised', $data['code']);
        $this->assertSame(
            'Geanonimiseerde gebruikers kunnen niet bewerkt worden.',
            $data['message'],
            'The Dutch message must be preserved verbatim through the envelope conversion'
        );
    }

    /**
     * An invalid email → 400 WP_Error shape with the Dutch message preserved.
     */
    public function testInvalidEmailReturnsWpErrorShapeWith400AndDutchMessage(): void
    {
        $response = $this->dispatchUpdate(self::$testUserId, ['email' => 'not-an-email']);

        $this->assertSame(400, $response->get_status());
        $data = $this->assertWpErrorShape($response, 400);
        $this->assertSame('invalid_email', $data['code']);
        $this->assertSame('Ongeldig e-mailadres.', $data['message']);
    }

    /**
     * An email already in use by another user → 400 WP_Error shape with the
     * Dutch message preserved.
     */
    public function testEmailInUseReturnsWpErrorShapeWith400AndDutchMessage(): void
    {
        $takenEmail = 'taken_c2_' . wp_generate_password(6, false) . '@test.local';
        $other = wp_create_user('other_c2_' . wp_generate_password(6, false), 'testpass', $takenEmail);
        $this->assertIsInt($other);
        $this->otherUserId = $other;

        $response = $this->dispatchUpdate(self::$testUserId, ['email' => $takenEmail]);

        $this->assertSame(400, $response->get_status());
        $data = $this->assertWpErrorShape($response, 400);
        $this->assertSame('email_in_use', $data['code']);
        $this->assertSame('Dit e-mailadres is al in gebruik.', $data['message']);
    }

    /**
     * Dispatch POST /admin/users/{id}/profile as the admin, through the real
     * REST server. Returns the dispatched WP_REST_Response (a returned
     * WP_Error is converted to a WP_REST_Response by error_to_response).
     */
    private function dispatchUpdate(int $userId, array $body): \WP_REST_Response
    {
        wp_set_current_user((int) $this->adminId);

        $request = new \WP_REST_Request('POST', "/stride/v1/admin/users/{$userId}/profile");
        $request->set_param('id', $userId);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($body));

        $response = rest_get_server()->dispatch($request);

        // WP converts a returned WP_Error to a WP_REST_Response; the route
        // callback never surfaces a raw WP_Error to the caller.
        $this->assertNotInstanceOf(\WP_Error::class, $response, 'REST dispatch returned a raw WP_Error');

        return $response;
    }

    /**
     * Assert the response body is the WP standard error shape produced by
     * a returned WP_Error: { code, message, data: { status } } — NOT the old
     * { error: <message> } envelope.
     *
     * @return array{code:string,message:string,data:array{status:int}}
     */
    private function assertWpErrorShape(\WP_REST_Response $response, int $expectedStatus): array
    {
        $data = (array) $response->get_data();

        $this->assertArrayNotHasKey(
            'error',
            $data,
            'The old hand-rolled {error: ...} envelope must be gone — C2 converts to WP_Error'
        );
        $this->assertArrayHasKey('code', $data, 'WP_Error body must carry a string code');
        $this->assertArrayHasKey('message', $data, 'WP_Error body must carry the message');
        $this->assertArrayHasKey('data', $data, 'WP_Error body must carry a data bag');
        $this->assertIsString($data['code']);
        $this->assertSame(
            $expectedStatus,
            (int) ($data['data']['status'] ?? 0),
            'WP_Error data.status must match the original HTTP status'
        );

        return [
            'code' => (string) $data['code'],
            'message' => (string) $data['message'],
            'data' => ['status' => (int) $data['data']['status']],
        ];
    }
}
