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
 *
 * Task 3.3 additions: rest_pre_dispatch body-size/JSON-depth caps (WP-core
 * quirk 3) — see the dedicated block of tests near the end of this file.
 */
final class NtdstRestRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $_test_rest_routes, $_test_rest_route_calls, $_test_actions;
        global $_test_did_action_counts, $_test_doing_it_wrong_calls, $_test_log_entries;
        global $_test_filters;

        $_test_rest_routes = [];
        $_test_rest_route_calls = [];
        $_test_actions = [];
        $_test_did_action_counts = [];
        $_test_doing_it_wrong_calls = [];
        $_test_log_entries = [];
        $_test_filters = [];
    }

    public function testRouteQueuedPreRestApiInitRegistersOnFlushWithFullShape(): void
    {
        global $_test_rest_route_calls;

        $handler = fn() => ['ok' => true];
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

        // As of task 3.4, the captured callback is the normalizing wrapper,
        // not the raw handler (see the Task 3.4 normalization tests for the
        // wrapper's own contract) — assert behavioral equivalence (a bare
        // array normalizes to the {success:true,data} 200 envelope) instead
        // of raw identity.
        self::assertIsCallable($call['args']['callback']);
        self::assertNotSame($handler, $call['args']['callback']);
        $normalized = ($call['args']['callback'])(new \WP_REST_Request('GET', '/widgets'));
        self::assertInstanceOf(\WP_REST_Response::class, $normalized);
        self::assertSame(['success' => true, 'data' => ['ok' => true]], $normalized->get_data());

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

    /**
     * Task 3.3 — body size / JSON depth caps enforced on rest_pre_dispatch
     * (WP-core quirk 3).
     *
     * Ground-truthed against web/wp/wp-includes/rest-api/class-wp-rest-server.php:
     * dispatch() (line 1062) calls apply_filters('rest_pre_dispatch', null,
     * $this, $request) as the very FIRST thing it does (line 1078) — before
     * match_request_to_handler() (line 1095) and before
     * $request->has_valid_params() (line 1114). has_valid_params()
     * (class-wp-rest-request.php:885) calls parse_json_params() (line 685),
     * which — for a JSON-content-typed body — runs json_decode($body, true)
     * at PHP's default depth (512) at line 702. A non-empty return from a
     * rest_pre_dispatch filter short-circuits dispatch() entirely (the
     * `if ( ! empty( $result ) )` branch at line 1080 returns before
     * match_request_to_handler() or has_valid_params() ever run), so this is
     * the only point our own code can enforce caps before core's own
     * default-depth parse.
     *
     * json_decode depth semantics (empirically verified — depth counts one
     * level PER nesting boundary, not per array/object encountered
     * one-for-one with "levels" of user-visible nesting): for N levels of
     * nesting (`{"a": {"a": ... <scalar> } }`, N `"a"` wrappers), decoding
     * SUCCEEDS at depth >= N+1 and FAILS (json_last_error() ===
     * JSON_ERROR_DEPTH, "Maximum stack depth exceeded") at depth <= N. E.g.
     * a flat array `[1,2,3]` (1 level of nesting) fails at depth=1, passes
     * at depth=2.
     */
    public function testBodyOverMaxBytesReturnsPayloadTooLargeWpError(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/limited', fn() => 'ok', [
            'permission' => fn() => true,
            'max_body_bytes' => 10,
        ]);

        do_action('rest_api_init');

        $request = new \WP_REST_Request('POST', '/stride/v1/limited');
        $request->set_body(str_repeat('x', 11));

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('payload_too_large', $result->get_error_code());
        self::assertSame(413, $result->get_error_data()['status']);
    }

    public function testBodyExactlyAtMaxBytesPassesThrough(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/limited-exact', fn() => 'ok', [
            'permission' => fn() => true,
            'max_body_bytes' => 10,
        ]);

        do_action('rest_api_init');

        $request = new \WP_REST_Request('POST', '/stride/v1/limited-exact');
        $request->set_body(str_repeat('x', 10));

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertNull($result, 'A body exactly at the cap must pass through untouched (null, matching the filter default).');
    }

    public function testJsonDeeperThanMaxDepthReturnsInvalidJsonWpError(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/depth-limited', fn() => 'ok', [
            'permission' => fn() => true,
            'max_json_depth' => 3,
        ]);

        do_action('rest_api_init');

        // 3 levels of nesting — per the empirical depth semantics documented
        // above, this requires depth >= 4 to decode; depth=3 must reject it.
        $body = json_encode(['a' => ['a' => ['a' => 1]]]);

        $request = new \WP_REST_Request('POST', '/stride/v1/depth-limited');
        // A real JSON request carries a JSON Content-Type — core's own
        // parse_json_params() would only decode this body because
        // is_json_content_type() is true (class-wp-rest-request.php:693),
        // and the depth cap is gated the same way (3.3 review, Finding 2).
        $request->set_header('Content-Type', 'application/json');
        $request->set_body($body);

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('invalid_json', $result->get_error_code());
        self::assertSame(400, $result->get_error_data()['status']);
    }

    public function testJsonAtMaxDepthPassesThrough(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/depth-ok', fn() => 'ok', [
            'permission' => fn() => true,
            'max_json_depth' => 4,
        ]);

        do_action('rest_api_init');

        // Same 3-level-nested body — decodes cleanly at depth=4 per the
        // empirical semantics (N=3 levels needs depth >= N+1 = 4).
        $body = json_encode(['a' => ['a' => ['a' => 1]]]);

        $request = new \WP_REST_Request('POST', '/stride/v1/depth-ok');
        // Realistic JSON request — see testJsonDeeperThanMaxDepth… for why
        // the Content-Type now matters (depth cap gated on JSON, Finding 2).
        $request->set_header('Content-Type', 'application/json');
        $request->set_body($body);

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertNull($result, 'JSON at exactly the configured depth must pass through untouched.');
    }

    public function testEmptyBodyPassesThroughRegardlessOfCaps(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/empty-body', fn() => 'ok', [
            'permission' => fn() => true,
            'max_body_bytes' => 5,
            'max_json_depth' => 1,
        ]);

        do_action('rest_api_init');

        // get_body() is nullable in real WP core until set_body() is called
        // (class-wp-rest-request.php: `protected $body = null`) — never
        // call set_body() here, mirroring a request built via params only.
        $request = new \WP_REST_Request('POST', '/stride/v1/empty-body');

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertNull($result, 'An empty/null body must pass through — nothing to size-check or decode.');
    }

    public function testForeignRouteNotInThisRegistrarsTableIsPassedThroughUntouched(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/capped', fn() => 'ok', [
            'permission' => fn() => true,
            'max_body_bytes' => 5,
        ]);

        do_action('rest_api_init');

        // A route this registrar never queued — even though it's oversized,
        // this filter must not touch it (mitigation-7 analog: scoped, not
        // global enforcement). $result starts as some arbitrary sentinel
        // (mimicking an earlier filter on the chain already having produced
        // a value) to prove it is passed through UNCHANGED, not just "null
        // stays null".
        $sentinel = new \WP_REST_Response(['already' => 'handled']);
        $request = new \WP_REST_Request('POST', '/some/other/v1/route');
        $request->set_body(str_repeat('x', 999));

        $result = apply_filters('rest_pre_dispatch', $sentinel, new \stdClass(), $request);

        self::assertSame($sentinel, $result, 'A request for a route not in this registrar\'s table must be passed through untouched.');
    }

    public function testNoRouteConfiguresCapsNeverAddsTheFilterAtAll(): void
    {
        global $_test_filters;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/no-caps', fn() => 'ok', ['permission' => fn() => true]);

        do_action('rest_api_init');

        self::assertArrayNotHasKey(
            'rest_pre_dispatch',
            $_test_filters,
            'rest_pre_dispatch must never be hooked at all when no queued route configures max_body_bytes or max_json_depth.',
        );
    }

    public function testAtLeastOneRouteWithCapsAddsTheFilterLazily(): void
    {
        global $_test_filters;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/still-no-caps', fn() => 'ok', ['permission' => fn() => true]);
        $registrar->post('/has-caps', fn() => 'ok', [
            'permission' => fn() => true,
            'max_body_bytes' => 100,
        ]);

        do_action('rest_api_init');

        self::assertArrayHasKey(
            'rest_pre_dispatch',
            $_test_filters,
            'rest_pre_dispatch must be hooked exactly when at least one queued route configures a cap.',
        );
        self::assertCount(1, $_test_filters['rest_pre_dispatch'], 'The filter must be added exactly once regardless of how many capped routes are queued.');
    }

    public function testWpErrorReturnedFromFilterReachesTheApplyFiltersCaller(): void
    {
        // Filter-return-contract proof: apply_filters() itself returns
        // whatever the last filter in the chain returns — WP core's real
        // dispatch() relies on exactly this to short-circuit at line 1080
        // (`if ( ! empty( $result ) )`). This proves our filter's WP_Error
        // return value actually propagates through the apply_filters() call
        // site, not just that our own method returns it when called directly.
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/short-circuit', fn() => 'ok', [
            'permission' => fn() => true,
            'max_body_bytes' => 1,
        ]);

        do_action('rest_api_init');

        $request = new \WP_REST_Request('POST', '/stride/v1/short-circuit');
        $request->set_body('too big');

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertInstanceOf(\WP_Error::class, $result, 'apply_filters(\'rest_pre_dispatch\', ...) must yield our WP_Error, proving the short-circuit contract at the real filter call site.');
        self::assertTrue(is_wp_error($result));
    }

    /**
     * 3.3-review Finding 1 (CRITICAL, fail-open): the cap lookup was an
     * EXACT string match against the registration-time route string
     * (`/ns/v1/orders/(?P<id>\d+)`), but at rest_pre_dispatch time real
     * WP's WP_REST_Request::get_route() returns the CONCRETE request path
     * (`/ns/v1/orders/42`) — set once in the constructor from the literal
     * URL (class-wp-rest-request.php:127) and never resolved back to the
     * registered regex (core only pattern-matches later, in
     * match_request_to_handler(), which has not run yet at
     * rest_pre_dispatch). So a cap on ANY route with a capture group never
     * matched → $caps === null → the request passed UNCAPPED.
     *
     * Contract: the lookup must be regex-aware, mirroring core's own
     * matching (class-wp-rest-server.php:1171 —
     * `preg_match('@^' . $route . '$@i', $path)`, ordered first-match).
     */
    public function testParameterizedCappedRouteEnforcesBodyCapOnConcretePath(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/orders/(?P<id>\d+)', fn() => 'ok', [
            'permission' => fn() => true,
            'max_body_bytes' => 10,
        ]);

        do_action('rest_api_init');

        // The concrete path a real request carries — NOT the regex the
        // route was registered under.
        $request = new \WP_REST_Request('POST', '/stride/v1/orders/42');
        $request->set_body(str_repeat('x', 11));

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertInstanceOf(\WP_Error::class, $result, 'An over-cap body on a parameterized capped route must be rejected — exact-string lookup fails open here.');
        self::assertSame('payload_too_large', $result->get_error_code());
        self::assertSame(413, $result->get_error_data()['status']);
    }

    public function testConcretePathNotMatchingParameterizedPatternPassesThroughUntouched(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/orders/(?P<id>\d+)', fn() => 'ok', [
            'permission' => fn() => true,
            'max_body_bytes' => 10,
        ]);

        do_action('rest_api_init');

        // Same namespace, different resource — must NOT match the
        // /orders/(?P<id>\d+) pattern, so the oversized body passes through
        // untouched (sentinel preserved, mirroring an earlier filter's value).
        $sentinel = new \WP_REST_Response(['already' => 'handled']);
        $request = new \WP_REST_Request('POST', '/stride/v1/customers/42');
        $request->set_body(str_repeat('x', 999));

        $result = apply_filters('rest_pre_dispatch', $sentinel, new \stdClass(), $request);

        self::assertSame($sentinel, $result, 'A concrete path that does not match any capped pattern must be passed through untouched.');
    }

    /**
     * 3.3-review Finding 2 (IMPORTANT, false positive): the depth check ran
     * json_decode() on ANY non-empty body regardless of Content-Type, so a
     * legitimate form-encoded body (`a=1&b=2&c=3`) — a JSON syntax error —
     * was wrongfully rejected with invalid_json 400. Core only attempts a
     * JSON parse when is_json_content_type() is true
     * (class-wp-rest-request.php:693 gates parse_json_params()), and the
     * depth cap exists precisely to bound THAT parse — so it must be gated
     * the same way. (The body-BYTES cap stays content-agnostic — see
     * testParameterizedCappedRouteEnforcesBodyCapOnConcretePath.)
     */
    public function testFormEncodedBodyOnDepthCappedRoutePassesThrough(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/form-intake', fn() => 'ok', [
            'permission' => fn() => true,
            'max_json_depth' => 3,
        ]);

        do_action('rest_api_init');

        $request = new \WP_REST_Request('POST', '/stride/v1/form-intake');
        $request->set_header('Content-Type', 'application/x-www-form-urlencoded');
        $request->set_body('a=1&b=2&c=3');

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertNull($result, 'A non-JSON body on a depth-capped route must skip the depth check entirely — not be 400ed as invalid_json.');
    }

    public function testMissingContentTypeOnDepthCappedRouteSkipsDepthCheck(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/no-ct', fn() => 'ok', [
            'permission' => fn() => true,
            'max_json_depth' => 1,
        ]);

        do_action('rest_api_init');

        // No Content-Type header at all: core's parse_json_params() would
        // never JSON-decode this body (is_json_content_type() false), so
        // there is no default-depth-512 parse for the cap to pre-empt —
        // skipping mirrors core exactly.
        $request = new \WP_REST_Request('POST', '/stride/v1/no-ct');
        $request->set_body(json_encode(['a' => ['a' => ['a' => 1]]]));

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertNull($result, 'Without a JSON Content-Type, core never JSON-parses the body — the depth cap must not fire either.');
    }

    public function testJsonSuffixContentTypeIsStillDepthChecked(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->post('/ld-json', fn() => 'ok', [
            'permission' => fn() => true,
            'max_json_depth' => 3,
        ]);

        do_action('rest_api_init');

        // application/ld+json is a JSON media type per core's
        // wp_is_json_media_type() (wp-includes/load.php:1962 — the
        // `([\w!#\$&-\^\.\+]+\+)?json` suffix alternative), so the depth cap
        // must still bite — the gate is "JSON media type", not a naive
        // string-equality check against 'application/json'.
        $request = new \WP_REST_Request('POST', '/stride/v1/ld-json');
        $request->set_header('Content-Type', 'application/ld+json');
        $request->set_body(json_encode(['a' => ['a' => ['a' => ['a' => 1]]]]));

        $result = apply_filters('rest_pre_dispatch', null, new \stdClass(), $request);

        self::assertInstanceOf(\WP_Error::class, $result, '+json suffix media types are JSON per core — the depth cap must apply to them.');
        self::assertSame('invalid_json', $result->get_error_code());
        self::assertSame(400, $result->get_error_data()['status']);
    }

    // =====================================================================
    // Task 3.4 — handler-return normalization (D6).
    //
    // Every registered callback is wrapped so a handler's return value is
    // normalized to a WP_REST_Response / WP_Error before it reaches core's
    // dispatch loop, so handlers can return a bare array / NTDST_Response /
    // WP_REST_Response / WP_Error interchangeably. The registered
    // 'callback' captured in $_test_rest_route_calls is therefore the
    // WRAPPER, not the raw handler — driving it with a request exercises
    // normalizeResult().
    // =====================================================================

    public function testHandlerReturningArrayIsWrappedInApiSuccessRestResponse200(): void
    {
        global $_test_rest_route_calls;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/norm-array', fn() => ['id' => 7, 'name' => 'widget'], ['permission' => fn() => true]);

        do_action('rest_api_init');

        $callback = $_test_rest_route_calls[0]['args']['callback'];
        self::assertNotSame(
            fn() => ['id' => 7],
            $callback,
            'The registered callback must be the normalizing wrapper, not the raw handler.',
        );

        $result = $callback(new \WP_REST_Request('GET', '/stride/v1/norm-array'));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(200, $result->get_status());
        self::assertSame(
            ['success' => true, 'data' => ['id' => 7, 'name' => 'widget']],
            $result->get_data(),
            'A bare array must be wrapped as {success:true,data:…} (NOT returned as bare data).',
        );
    }

    public function testHandlerReturningWpRestResponseIsPassedThroughUnchanged(): void
    {
        global $_test_rest_route_calls;

        $response = new \WP_REST_Response(['custom' => 'shape'], 201);

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/norm-response', fn() => $response, ['permission' => fn() => true]);

        do_action('rest_api_init');

        $callback = $_test_rest_route_calls[0]['args']['callback'];
        $result = $callback(new \WP_REST_Request('GET', '/stride/v1/norm-response'));

        self::assertSame($response, $result, 'A WP_REST_Response must be returned as-is — never re-wrapped.');
        self::assertSame(201, $result->get_status());
    }

    public function testHandlerReturningWpErrorIsPassedThroughUnchanged(): void
    {
        global $_test_rest_route_calls;

        $error = new \WP_Error('forbidden', 'Nope.', ['status' => 403]);

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/norm-error', fn() => $error, ['permission' => fn() => true]);

        do_action('rest_api_init');

        $callback = $_test_rest_route_calls[0]['args']['callback'];
        $result = $callback(new \WP_REST_Request('GET', '/stride/v1/norm-error'));

        self::assertSame($error, $result, 'A WP_Error must be returned as-is (WP-native serialization to the wire).');
    }

    public function testHandlerReturningNtdstResponseWithDataIsToRestResponseEnvelope(): void
    {
        global $_test_rest_route_calls;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get(
            '/norm-ntdst-data',
            fn() => ntdst_response()->withData(['orders' => [1, 2, 3]]),
            ['permission' => fn() => true],
        );

        do_action('rest_api_init');

        $callback = $_test_rest_route_calls[0]['args']['callback'];
        $result = $callback(new \WP_REST_Request('GET', '/stride/v1/norm-ntdst-data'));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(200, $result->get_status());
        self::assertSame(
            ['success' => true, 'data' => ['orders' => [1, 2, 3]]],
            $result->get_data(),
            'An NTDST_Response with data must serialize via toRestResponse() — the success envelope.',
        );
    }

    public function testHandlerReturningNtdstResponseErrorCarriesStoredStatusAndErrorEnvelope(): void
    {
        global $_test_rest_route_calls;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get(
            '/norm-ntdst-error',
            fn() => ntdst_response()->error('Not allowed.', 422),
            ['permission' => fn() => true],
        );

        do_action('rest_api_init');

        $callback = $_test_rest_route_calls[0]['args']['callback'];
        $result = $callback(new \WP_REST_Request('GET', '/stride/v1/norm-ntdst-error'));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(422, $result->get_status(), 'toRestResponse() must carry the NTDST_Response stored status, not a fixed 200.');
        self::assertSame(
            ['success' => false, 'error' => 'Not allowed.'],
            $result->get_data(),
            'An NTDST_Response error must serialize via toRestResponse() — the jsonPayload() error shape.',
        );
    }

    public function testHandlerReturningUnexpectedScalarBecomesInvalidHandlerReturn500AndIsLogged(): void
    {
        global $_test_rest_route_calls, $_test_log_entries;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/norm-scalar', fn() => 'a bare string', ['permission' => fn() => true]);

        do_action('rest_api_init');

        $callback = $_test_rest_route_calls[0]['args']['callback'];
        $result = $callback(new \WP_REST_Request('GET', '/stride/v1/norm-scalar'));

        self::assertInstanceOf(\WP_Error::class, $result, 'An unexpected scalar handler return must become a WP_Error, never reach the wire raw.');
        self::assertSame('invalid_handler_return', $result->get_error_code());
        self::assertSame(500, $result->get_error_data()['status']);

        self::assertNotEmpty($_test_log_entries, 'An invalid handler return must be logged.');
        $logged = $_test_log_entries[count($_test_log_entries) - 1];
        self::assertSame('api', $logged['channel']);
        self::assertSame('error', $logged['level']);
    }

    public function testInvalidHandlerReturnMessageIsGenericNotTheRawValue(): void
    {
        global $_test_rest_route_calls;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/norm-secret', fn() => "SENSITIVE_INTERNAL_STATE", ['permission' => fn() => true]);

        do_action('rest_api_init');

        $callback = $_test_rest_route_calls[0]['args']['callback'];
        $result = $callback(new \WP_REST_Request('GET', '/stride/v1/norm-secret'));

        self::assertStringNotContainsString(
            'SENSITIVE_INTERNAL_STATE',
            $result->get_error_message(),
            'The wire-facing invalid_handler_return message must be generic — the raw value must never leak (mitigation 10).',
        );
    }

    // =====================================================================
    // Task 3.4 — 'cors' option wiring.
    //
    // A route carrying a 'cors' => NTDST_Cors_Policy option must register
    // that policy against the CONCRETE route prefix ('/' . namespace .
    // route) at registration time. A route with no 'cors' option must never
    // touch a policy. The prefix passed must not carry a trailing-slash trap
    // (P2 gate rider): a namespace-root route ('/') must still yield the
    // exact-matchable '/stride/v1', not '/stride/v1/'.
    // =====================================================================

    public function testCorsOptionRegistersPolicyAgainstConcreteRoutePrefix(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $policy = new \NTDST_Cors_Policy(['origins' => ['https://app.example.com']]);

        $registrar->get('/widgets', fn() => ['ok' => true], [
            'permission' => fn() => true,
            'cors' => $policy,
        ]);

        do_action('rest_api_init');

        // Prove the policy is now scoped to exactly the route it guards:
        // a request for that route matches, a sibling namespace does not.
        self::assertTrue(
            $this->policyGuardsRoute($policy, '/stride/v1/widgets'),
            'The cors policy must be registered against the concrete route prefix it guards.',
        );
        self::assertFalse(
            $this->policyGuardsRoute($policy, '/stride/v10/widgets'),
            'The registered prefix must respect segment boundaries — a sibling namespace must not be guarded.',
        );
    }

    public function testNoCorsOptionNeverRegistersAnyPolicy(): void
    {
        global $_test_filters;

        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $registrar->get('/no-cors', fn() => ['ok' => true], ['permission' => fn() => true]);

        do_action('rest_api_init');

        // A route with no 'cors' option must not hook the CORS filter at all
        // — the only way NTDST_Cors_Policy::register() reaches the pipeline.
        self::assertArrayNotHasKey(
            'rest_pre_serve_request',
            $_test_filters,
            'A route with no cors option must never register a CORS policy.',
        );
    }

    /**
     * P2 gate rider: a namespace-root cors route ('/') must register the
     * policy against '/stride/v1' — NOT '/stride/v1/'. A trailing-slash
     * prefix is accepted by register() but then never matches the exact
     * concrete route '/stride/v1' (matchesRegisteredPrefix compares
     * $route === $prefix, and '/stride/v1' !== '/stride/v1/'), so the
     * policy would silently never fire on its own namespace root. The
     * registrar must strip the dangling slash before handing the prefix to
     * register().
     */
    public function testCorsWiringForNamespaceRootRouteHasNoTrailingSlashTrap(): void
    {
        $registrar = new \NTDST_Rest_Registrar('stride/v1');
        $policy = new \NTDST_Cors_Policy(['origins' => ['https://app.example.com']]);

        // A route registered at the namespace root ('/') — '/' . 'stride/v1'
        // . '/' would naively construct '/stride/v1/', the trailing-slash
        // trap. get_route() for this route returns the exact '/stride/v1'.
        $registrar->get('/', fn() => ['ok' => true], [
            'permission' => fn() => true,
            'cors' => $policy,
        ]);

        do_action('rest_api_init');

        self::assertTrue(
            $this->policyGuardsRoute($policy, '/stride/v1'),
            'A namespace-root cors route must guard the exact concrete route /stride/v1 — a trailing-slash prefix would silently never match it.',
        );
    }

    /**
     * Drive a policy's own applyCorsHeaders() against a concrete route and
     * report whether it acted on that route (matched its registered prefix)
     * — the un-mocked proof that register() wired the policy to the intended
     * route. A matching, allowed origin makes the policy emit an
     * Access-Control-Allow-Origin header; a non-matching route emits none.
     */
    private function policyGuardsRoute(\NTDST_Cors_Policy $policy, string $route): bool
    {
        $sent = [];
        $policy->setHeaderSender(function (string $header) use (&$sent): void {
            $sent[] = $header;
        });
        $policy->setHeaderRemover(static function (string $name): void {});

        $request = new \WP_REST_Request('GET', $route);
        $request->set_header('Origin', 'https://app.example.com');

        $policy->applyCorsHeaders(true, null, $request, new \stdClass());

        foreach ($sent as $header) {
            if (str_starts_with($header, 'Access-Control-Allow-Origin:')) {
                return true;
            }
        }

        return false;
    }
}
