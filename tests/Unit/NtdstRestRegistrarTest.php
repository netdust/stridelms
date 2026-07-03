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

        // As of task 3.2, the captured permission_callback is the
        // memoizing wrapper, not the raw callable (see
        // NtdstRestRegistrarTest's Task 3.2 tests for the wrapper's own
        // behavioral contract) — assert behavioral equivalence for a
        // single call instead of raw identity.
        self::assertIsCallable($call['args']['permission_callback']);
        self::assertNotSame($permission, $call['args']['permission_callback']);
        self::assertTrue(($call['args']['permission_callback'])(new \WP_REST_Request('GET', '/widgets')));
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

    /**
     * Task 3.2 — permission_callback memoization (WP-core quirk 2).
     *
     * WordPress core's rest_send_allow_header() (hooked rest_post_dispatch,
     * ground-truthed at web/wp/wp-includes/rest-api.php:854-886) calls the
     * matched route's permission_callback a SECOND time per real request to
     * compute the response's Allow header. A side-effectful permission
     * callable (e.g. a rate-limit counter) must not double-count when
     * invoked twice for the SAME WP_REST_Request object — the wrapped
     * callable captured by register_rest_route() must memoize per request.
     */
    public function testWrappedPermissionCallableInvokedTwiceWithSameRequestEvaluatesOnceAndReplaysTrue(): void
    {
        global $_test_rest_route_calls;

        $calls = 0;
        $permission = function () use (&$calls) {
            $calls++;
            return true;
        };

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/memoized-true', fn() => 'ok', ['permission' => $permission]);

        do_action('rest_api_init');

        $captured = $_test_rest_route_calls[0]['args']['permission_callback'];
        self::assertNotSame($permission, $captured, 'The registered permission_callback must be the wrapped closure, not the raw callable.');

        $request = new \WP_REST_Request('GET', '/memoized-true');

        $first = $captured($request);
        $second = $captured($request);

        self::assertSame(1, $calls, 'The underlying permission callable must be invoked exactly once per request object.');
        self::assertTrue($first);
        self::assertTrue($second);
        self::assertSame($first, $second);
    }

    public function testWrappedPermissionCallableInvokedTwiceWithSameRequestEvaluatesOnceAndReplaysFalse(): void
    {
        global $_test_rest_route_calls;

        $calls = 0;
        $permission = function () use (&$calls) {
            $calls++;
            return false;
        };

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/memoized-false', fn() => 'ok', ['permission' => $permission]);

        do_action('rest_api_init');

        $captured = $_test_rest_route_calls[0]['args']['permission_callback'];
        $request = new \WP_REST_Request('GET', '/memoized-false');

        $first = $captured($request);
        $second = $captured($request);

        self::assertSame(1, $calls);
        self::assertFalse($first);
        self::assertFalse($second);
    }

    public function testWrappedPermissionCallableInvokedTwiceWithSameRequestEvaluatesOnceAndReplaysSameWpErrorInstance(): void
    {
        global $_test_rest_route_calls;

        $calls = 0;
        $error = new \WP_Error('rate_limited', 'Request could not be processed.', ['status' => 429]);
        $permission = function () use (&$calls, $error) {
            $calls++;
            return $error;
        };

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/memoized-error', fn() => 'ok', ['permission' => $permission]);

        do_action('rest_api_init');

        $captured = $_test_rest_route_calls[0]['args']['permission_callback'];
        $request = new \WP_REST_Request('GET', '/memoized-error');

        $first = $captured($request);
        $second = $captured($request);

        self::assertSame(1, $calls, 'A rate-limit-style side effect in the permission callable must not double-count on the WP-core Allow-header re-invocation.');
        self::assertSame($error, $first);
        self::assertSame($error, $second, 'The exact same WP_Error instance must be replayed, not a new equivalent one.');
    }

    public function testTwoDistinctRequestObjectsEachEvaluateIndependently(): void
    {
        global $_test_rest_route_calls;

        $calls = 0;
        $permission = function () use (&$calls) {
            $calls++;
            return true;
        };

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/distinct', fn() => 'ok', ['permission' => $permission]);

        do_action('rest_api_init');

        $captured = $_test_rest_route_calls[0]['args']['permission_callback'];

        $requestA = new \WP_REST_Request('GET', '/distinct');
        $requestB = new \WP_REST_Request('GET', '/distinct');

        $captured($requestA);
        $captured($requestB);

        self::assertSame(2, $calls, 'Two distinct WP_REST_Request objects must each trigger their own evaluation.');
    }

    /**
     * Regression test for the 3.2-review Critical (auth bypass): a
     * memoization map shared across ALL wrappers of a registrar instance,
     * keyed ONLY by the WP_REST_Request object, leaks one route's verdict
     * to every other route on the same path. WP core's
     * rest_send_allow_header() (web/wp/wp-includes/rest-api.php:854-886)
     * calls EVERY handler's permission_callback for the matched path with
     * the SAME request object — so with a shared map, a stricter route
     * silently inherits a laxer route's cached `true` and its own
     * permission callable is NEVER invoked.
     *
     * Contract (threat-model mitigation 3, per-wrapper realization —
     * [PLAN-CORRECTION 2026-07-03]): each wrapper memoizes independently.
     * Same wrapper + same request → exactly one evaluation; different
     * wrappers + same request → fully independent verdicts.
     */
    public function testOpposingPermissionsOnSharedPathDoNotLeakVerdictAcrossWrappers(): void
    {
        global $_test_rest_route_calls;

        $getCalls = 0;
        $getPermission = function () use (&$getCalls) {
            $getCalls++;
            return false; // stricter route — must deny
        };

        $postCalls = 0;
        $postPermission = function () use (&$postCalls) {
            $postCalls++;
            return true; // laxer route — allows
        };

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/shared-path', fn() => 'ok', ['permission' => $getPermission]);
        $registrar->post('/shared-path', fn() => 'ok', ['permission' => $postPermission]);

        do_action('rest_api_init');

        self::assertCount(2, $_test_rest_route_calls);
        $byMethod = [];
        foreach ($_test_rest_route_calls as $call) {
            $byMethod[$call['args']['methods']] = $call['args']['permission_callback'];
        }

        // Mirror rest_send_allow_header(): the SAME request object is
        // driven through every handler's permission_callback for the
        // matched path — laxer (POST) wrapper first, stricter (GET) after.
        $request = new \WP_REST_Request('POST', '/shared-path');

        $postVerdict = ($byMethod['POST'])($request);
        $getVerdict = ($byMethod['GET'])($request);

        self::assertTrue($postVerdict, 'POST wrapper must return its own permission verdict (true).');
        self::assertFalse($getVerdict, 'GET wrapper must return ITS OWN verdict (false) — inheriting POST\'s cached true is an auth bypass.');
        self::assertSame(1, $postCalls, 'POST permission callable must be evaluated exactly once.');
        self::assertSame(1, $getCalls, 'GET permission callable must be evaluated exactly once — 0 invocations means its verdict was never consulted.');
    }

    /**
     * Behavioral proof of WeakMap-not-spl_object_id semantics (per the
     * brief's Step 1c): spl_object_id() is a slot index PHP reuses
     * IMMEDIATELY once an object is garbage-collected, so a plain array
     * keyed by object id would let a freshly-created request inherit a
     * stale cached result left by an earlier, already-freed request that
     * happened to land on the same id. Create-and-release request objects
     * in a loop (forcing id reuse via unset() + gc_collect_cycles()), then
     * evaluate a brand-new object — it must re-evaluate (not replay a
     * stale result), proving the cache is keyed by object identity
     * (WeakMap) and not by a reusable numeric id.
     */
    public function testFreshRequestAfterPriorRequestsAreGarbageCollectedDoesNotInheritStaleResult(): void
    {
        global $_test_rest_route_calls;

        $calls = 0;
        $permission = function () use (&$calls) {
            $calls++;
            // Alternate result so a stale-cache inheritance would be
            // detectable regardless of which parity it collided with.
            return $calls % 2 === 1;
        };

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/gc-loop', fn() => 'ok', ['permission' => $permission]);

        do_action('rest_api_init');

        $captured = $_test_rest_route_calls[0]['args']['permission_callback'];

        // Create and release a batch of requests, forcing spl_object_id
        // reuse for whichever ids the GC frees up.
        for ($i = 0; $i < 50; $i++) {
            $throwaway = new \WP_REST_Request('GET', '/gc-loop');
            $captured($throwaway);
            unset($throwaway);
            gc_collect_cycles();
        }

        $callsAfterLoop = $calls;

        $fresh = new \WP_REST_Request('GET', '/gc-loop');
        $firstOnFresh = $captured($fresh);
        $secondOnFresh = $captured($fresh);

        self::assertSame($callsAfterLoop + 1, $calls, 'A brand-new request object must trigger a fresh evaluation, never replay a stale entry from a GC-reused id.');
        self::assertSame($firstOnFresh, $secondOnFresh, 'The fresh object must still memoize correctly against itself on the second call.');
    }
}
