<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests pinning NTDST_Endpoints' current envelope wire
 * shapes (handle_get_nonce / handle_action success + error arrays) before
 * Task 1.2 reroutes the private success()/error() builders through
 * NTDST_Response. These tests PIN existing behavior — they must PASS now;
 * their RED signal is any future shape mutation in the reshape.
 */
final class NtdstEndpointsEnvelopeCharacterizationTest extends TestCase
{
    private NTDST_Endpoints $endpoints;

    protected function setUp(): void
    {
        parent::setUp();
        $this->endpoints = ntdst_endpoints();
    }

    public function testGetNonceSuccessWireShapeIsPinned(): void
    {
        $request = new WP_REST_Request();
        $request->set_param('action', 'search_posts');

        $result = $this->endpoints->handle_get_nonce($request);

        self::assertSame(['success', 'data'], array_keys($result));
        self::assertTrue($result['success']);
        self::assertSame(['nonce'], array_keys($result['data']));
        self::assertIsString($result['data']['nonce']);
    }

    public function testMissingActionErrorWireShapeIsPinned(): void
    {
        $result = $this->endpoints->handle_get_nonce(new WP_REST_Request());

        self::assertSame(
            ['success' => false, 'data' => ['message' => 'No action specified', 'code' => 'missing_action']],
            $result,
        );
    }

    public function testHandleActionMissingParamsErrorWireShapeIsPinned(): void
    {
        $result = $this->endpoints->handle_action(new WP_REST_Request());

        self::assertSame(
            ['success' => false, 'data' => ['message' => 'Missing action or nonce', 'code' => 'missing_params']],
            $result,
        );
    }
}
