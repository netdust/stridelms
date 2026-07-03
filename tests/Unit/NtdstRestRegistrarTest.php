<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NTDST_Rest_Registrar — registration core (Task 3.1).
 *
 * Scope for this task: queue/flush registration timing (incl. the
 * doubt-pass D1 correction — immediate registration when rest_api_init
 * already fired) and the required-permission denial path (mitigation 6).
 * Memoization (3.2), body caps (3.3), and normalization/facade/CORS wiring
 * (3.4) are explicitly out of scope here.
 */
final class NtdstRestRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $_test_rest_routes, $_test_rest_route_calls, $_test_actions;
        global $_test_did_action_counts, $_test_doing_it_wrong_calls, $_test_log_entries;

        $_test_rest_routes = [];
        $_test_rest_route_calls = [];
        $_test_actions = [];
        $_test_did_action_counts = [];
        $_test_doing_it_wrong_calls = [];
        $_test_log_entries = [];
    }

    public function testRouteQueuedPreRestApiInitRegistersOnFlushWithFullShape(): void
    {
        global $_test_rest_route_calls;

        $handler = fn() => 'ok';
        $permission = fn() => true;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/widgets', $handler, ['permission' => $permission]);

        // Not registered yet — still queued pending rest_api_init.
        self::assertCount(0, $_test_rest_route_calls);

        do_action('rest_api_init');

        self::assertCount(1, $_test_rest_route_calls);
        $call = $_test_rest_route_calls[0];

        self::assertSame('stride/v1', $call['namespace']);
        self::assertSame('/widgets', $call['route']);
        self::assertSame('GET', $call['args']['methods']);
        self::assertSame($handler, $call['args']['callback']);
        self::assertSame($permission, $call['args']['permission_callback']);
    }

    public function testRegistersImmediatelyWhenRestApiInitAlreadyFired(): void
    {
        global $_test_rest_route_calls;

        // Simulate rest_api_init already having fired earlier in the
        // request (D1✦ — the silent-no-op trap: a route added AFTER the
        // hook fires must not be silently dropped waiting for a second
        // rest_api_init that will never come).
        do_action('rest_api_init');
        self::assertCount(0, $_test_rest_route_calls);

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/late', fn() => 'ok', ['permission' => fn() => true]);

        // Registered immediately — no second do_action('rest_api_init') needed.
        self::assertCount(1, $_test_rest_route_calls);
        self::assertSame('/late', $_test_rest_route_calls[0]['route']);
    }

    public function testMissingPermissionNeverRegistersAndRecordsDoingItWrongAndLogError(): void
    {
        global $_test_rest_route_calls, $_test_doing_it_wrong_calls, $_test_log_entries;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/unsafe', fn() => 'ok', []); // no 'permission' key at all

        do_action('rest_api_init');

        self::assertCount(0, $_test_rest_route_calls, 'register_rest_route must never be called for a route missing permission.');
        self::assertNotEmpty($_test_doing_it_wrong_calls, '_doing_it_wrong() must be recorded.');
        self::assertNotEmpty($_test_log_entries, 'ntdst_log(api)->error() must be recorded.');

        $logged = $_test_log_entries[count($_test_log_entries) - 1];
        self::assertSame('api', $logged['channel']);
        self::assertSame('error', $logged['level']);
    }

    public function testNonCallablePermissionNeverRegistersAndRecordsDoingItWrongAndLogError(): void
    {
        global $_test_rest_route_calls, $_test_doing_it_wrong_calls, $_test_log_entries;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/unsafe-2', fn() => 'ok', ['permission' => 'not_a_real_function_xyz']);

        do_action('rest_api_init');

        self::assertCount(0, $_test_rest_route_calls, 'register_rest_route must never be called when permission is non-callable.');
        self::assertNotEmpty($_test_doing_it_wrong_calls);
        self::assertNotEmpty($_test_log_entries);
    }

    public function testArgsOptionPassesThroughVerbatim(): void
    {
        global $_test_rest_route_calls;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $customArgs = [
            'id' => [
                'required' => true,
                'validate_callback' => fn($v) => is_numeric($v),
            ],
        ];
        $registrar->get('/widgets/(?P<id>\d+)', fn() => 'ok', [
            'permission' => fn() => true,
            'args' => $customArgs,
        ]);

        do_action('rest_api_init');

        self::assertCount(1, $_test_rest_route_calls);
        self::assertSame($customArgs, $_test_rest_route_calls[0]['args']['args']);
    }

    public function testFlushIsIdempotentAndDoesNotDoubleRegister(): void
    {
        global $_test_rest_route_calls, $_test_actions;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/once', fn() => 'ok', ['permission' => fn() => true]);

        do_action('rest_api_init');
        self::assertCount(1, $_test_rest_route_calls);

        // Call flush() directly a second time (simulating rest_api_init
        // firing twice, or defensive re-invocation) — the queue must have
        // already drained, so nothing double-registers.
        $registrar->flush();

        self::assertCount(1, $_test_rest_route_calls, 'flush() must be idempotent — the queue drains and a second flush must not re-register.');
    }

    public function testHttpMethodShorthandsMapToCorrectRestMethodConstants(): void
    {
        global $_test_rest_route_calls;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $permission = fn() => true;

        $registrar->get('/r-get', fn() => 'ok', ['permission' => $permission]);
        $registrar->post('/r-post', fn() => 'ok', ['permission' => $permission]);
        $registrar->put('/r-put', fn() => 'ok', ['permission' => $permission]);
        $registrar->patch('/r-patch', fn() => 'ok', ['permission' => $permission]);
        $registrar->delete('/r-delete', fn() => 'ok', ['permission' => $permission]);

        do_action('rest_api_init');

        self::assertCount(5, $_test_rest_route_calls);

        $byRoute = [];
        foreach ($_test_rest_route_calls as $call) {
            $byRoute[$call['route']] = $call['args']['methods'];
        }

        self::assertSame('GET', $byRoute['/r-get']);
        self::assertSame('POST', $byRoute['/r-post']);
        self::assertSame('PUT', $byRoute['/r-put']);
        self::assertSame('PATCH', $byRoute['/r-patch']);
        self::assertSame('DELETE', $byRoute['/r-delete']);
    }

    public function testRouteAcceptsStringMethodsArgument(): void
    {
        global $_test_rest_route_calls;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->route('/custom', 'POST', fn() => 'ok', ['permission' => fn() => true]);

        do_action('rest_api_init');

        self::assertCount(1, $_test_rest_route_calls);
        self::assertSame('POST', $_test_rest_route_calls[0]['args']['methods']);
    }

    public function testRouteAcceptsArrayMethodsArgument(): void
    {
        global $_test_rest_route_calls;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->route('/custom-multi', ['GET', 'POST'], fn() => 'ok', ['permission' => fn() => true]);

        do_action('rest_api_init');

        self::assertCount(1, $_test_rest_route_calls);
        self::assertSame(['GET', 'POST'], $_test_rest_route_calls[0]['args']['methods']);
    }
}
