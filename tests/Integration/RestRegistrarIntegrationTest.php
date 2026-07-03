<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Integration proof for NTDST_Rest_Registrar (Phase 3 convergence point,
 * INV-10) through the REAL WordPress REST server — no stubs, no mocked
 * dispatch loop. Fixture routes are registered on `ntdst-test/v1` (a
 * namespace unique to this test file) via `ntdst_router()->rest(...)`, the
 * same facade real callers use.
 *
 * Every fixture route uses a UNIQUE path per test (suffixed with the test's
 * own uniqid()) — WordPress core exposes no route-unregistration API, so
 * isolation between tests is achieved by never reusing a route path rather
 * than by tearing one down.
 *
 * Run: STRIDE_TEST_DB_DISPOSABLE=1 ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RestRegistrarIntegrationTest
 */
final class RestRegistrarIntegrationTest extends IntegrationTestCase
{
    private const NAMESPACE = 'ntdst-test/v1';

    /**
     * Apply the full `rest_post_dispatch` cycle on an already-dispatched
     * response, exactly as WP core's own serve_request()/respond_to_request()
     * do (class-wp-rest-server.php:462 and :1866) — this is what invokes
     * rest_send_allow_header() a SECOND time against the matched route's
     * permission_callback (rest-api.php:862, gated on
     * $response->get_matched_route() being set by respond_to_request() at
     * class-wp-rest-server.php:1325). rest_do_request() alone (rest-api.php:
     * 592) calls only dispatch() — it does NOT run this filter — so without
     * this explicit call the quirk-2 double-evaluation this test exists to
     * prove would never actually occur, and the test would falsely pass on
     * a THEORETICAL single evaluation instead of what real HTTP dispatch
     * does.
     */
    private function runFullDispatchCycle(\WP_REST_Request $request): \WP_REST_Response
    {
        $response = rest_do_request($request);

        return apply_filters('rest_post_dispatch', rest_ensure_response($response), rest_get_server(), $request);
    }

    private function uniqueRoute(string $label): string
    {
        return '/echo-' . $label . '-' . str_replace('.', '', uniqid('', true));
    }

    // =========================================================================
    // (a) + (b): allowed request → 200 envelope, permission evaluated exactly
    // once after a FULL dispatch cycle including rest_post_dispatch.
    // =========================================================================

    public function testAllowedRequestReturns200EnvelopeAndPermissionEvaluatedExactlyOnceThroughFullDispatchCycle(): void
    {
        $route = $this->uniqueRoute('allowed');
        $evaluationCount = 0;

        ntdst_router()->rest(self::NAMESPACE)->post(
            $route,
            fn(\WP_REST_Request $request) => ['echoed' => $request->get_param('value')],
            [
                'permission' => function () use (&$evaluationCount): bool {
                    $evaluationCount++;
                    return true;
                },
            ],
        );

        $request = new \WP_REST_Request('POST', '/' . self::NAMESPACE . $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['value' => 'ping']));

        $response = $this->runFullDispatchCycle($request);

        $this->assertSame(200, $response->get_status());
        $this->assertSame(
            ['success' => true, 'data' => ['echoed' => 'ping']],
            $response->get_data(),
            'a bare-array handler return must be wrapped in the {success:true,data:…} envelope on the wire',
        );

        // The REAL quirk-2 proof: WP core's rest_send_allow_header() (hooked
        // on rest_post_dispatch) calls this route's permission_callback a
        // SECOND time to compute the Allow header. Without the registrar's
        // per-wrapper memoization (task 3.2), $evaluationCount would be 2
        // here — this is provable ONLY by exercising the real
        // rest_post_dispatch chain, not by calling the permission callable
        // directly.
        $this->assertSame(
            1,
            $evaluationCount,
            'permission callable must be evaluated exactly once per real request despite rest_send_allow_header() re-invoking it on rest_post_dispatch',
        );
    }

    // =========================================================================
    // (c): handler returning WP_Error → error status + WP-native error shape.
    // =========================================================================

    public function testHandlerReturningWpErrorProducesWpNativeErrorShapeOnTheWire(): void
    {
        $route = $this->uniqueRoute('error');

        ntdst_router()->rest(self::NAMESPACE)->post(
            $route,
            fn() => new \WP_Error('ntdst_test_denied', 'Denied for test purposes.', ['status' => 409]),
            ['permission' => '__return_true'],
        );

        $request = new \WP_REST_Request('POST', '/' . self::NAMESPACE . $route);
        $response = $this->runFullDispatchCycle($request);

        $this->assertSame(409, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('ntdst_test_denied', $data['code'] ?? null);
        $this->assertSame('Denied for test purposes.', $data['message'] ?? null);
    }

    // =========================================================================
    // (d): oversized body → 413 short-circuit, permission NEVER invoked
    // (adversarial / negative case).
    // =========================================================================

    public function testOversizedBodyShortCircuitsWith413AndPermissionNeverInvoked(): void
    {
        $route = $this->uniqueRoute('capped');
        $permissionInvoked = false;

        ntdst_router()->rest(self::NAMESPACE)->post(
            $route,
            fn(\WP_REST_Request $request) => ['unreachable' => true],
            [
                'permission' => function () use (&$permissionInvoked): bool {
                    $permissionInvoked = true;
                    return true;
                },
                'max_body_bytes' => 10,
            ],
        );

        $request = new \WP_REST_Request('POST', '/' . self::NAMESPACE . $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(str_repeat('x', 500));

        $response = $this->runFullDispatchCycle($request);

        $this->assertSame(413, $response->get_status());
        $this->assertSame('payload_too_large', $response->get_data()['code'] ?? null);
        $this->assertFalse(
            $permissionInvoked,
            'the body cap must short-circuit on rest_pre_dispatch, strictly BEFORE the permission callable ever runs',
        );
    }

    // =========================================================================
    // (e): route registered with no permission → absent from the REST route
    // table + _doing_it_wrong fires.
    // =========================================================================

    public function testRouteWithNoPermissionIsAbsentFromTheRealRouteTableAndTriggersDoingItWrong(): void
    {
        $route = $this->uniqueRoute('unsafe');
        $doingItWrongFired = false;

        add_action('doing_it_wrong_run', function (string $functionName) use (&$doingItWrongFired): void {
            if ($functionName === 'NTDST_Rest_Registrar::route') {
                $doingItWrongFired = true;
            }
        });

        ntdst_router()->rest(self::NAMESPACE)->post(
            $route,
            fn() => ['unreachable' => true],
            // No 'permission' option at all.
        );

        $routes = rest_get_server()->get_routes();
        $fullRoute = '/' . self::NAMESPACE . $route;

        $this->assertArrayNotHasKey(
            $fullRoute,
            $routes,
            'a route with no callable permission must never reach the real REST route table (fail-closed, not fail-open)',
        );
        $this->assertTrue($doingItWrongFired, '_doing_it_wrong() must fire for the missing-permission route');
    }

    // =========================================================================
    // CRITICAL regression proof (3.3 review finding 1): parameterized route +
    // body cap enforces through REAL WP dispatch, where get_route() returns
    // the CONCRETE path, not the registration-time regex.
    // =========================================================================

    public function testParameterizedRouteWithBodyCapEnforces413OnConcretePathThroughRealDispatch(): void
    {
        $base = $this->uniqueRoute('items');
        $pattern = $base . '/(?P<id>\d+)';
        $permissionInvoked = false;

        ntdst_router()->rest(self::NAMESPACE)->post(
            $pattern,
            fn(\WP_REST_Request $request) => ['id' => $request->get_param('id')],
            [
                'permission' => function () use (&$permissionInvoked): bool {
                    $permissionInvoked = true;
                    return true;
                },
                'max_body_bytes' => 10,
            ],
        );

        // Sanity: the parameterized pattern IS registered in the real table.
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/' . self::NAMESPACE . $pattern, $routes, 'the parameterized route must be registered');

        // Dispatch against the CONCRETE path (…/items-…/42), which is what
        // WP_REST_Request::get_route() carries at rest_pre_dispatch time —
        // NOT the registration-time regex. This is the un-mockable proof
        // that capsForConcretePath()'s regex-aware lookup (the 3.3 Critical
        // fix) actually enforces against real WP dispatch, where an exact
        // string match would silently fail open.
        $request = new \WP_REST_Request('POST', '/' . self::NAMESPACE . $base . '/42');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(str_repeat('x', 500));

        $response = $this->runFullDispatchCycle($request);

        $this->assertSame(
            413,
            $response->get_status(),
            'the body cap registered against the parameterized PATTERN must enforce against the CONCRETE dispatch path — this is the real-WP proof of the 3.3 regex-aware fix',
        );
        $this->assertFalse($permissionInvoked, 'the cap must short-circuit before the permission callable runs, even on a parameterized route');
    }

    // =========================================================================
    // Step 2 — Router exit-path assertion (pattern-route callback returning a
    // Response-with-template). Deferred: see inline note.
    // =========================================================================

    /**
     * NOT asserted in-process. A pattern-route Router callback that resolves
     * to a template response calls NTDST_Response::html()->send() (or
     * equivalent), which — per the Router/Response contract carried over
     * from Tasks 0.3/1.3 — ends the request with `echo` + `exit`. `exit`
     * terminates the PHPUnit process itself; there is no in-process way to
     * observe the templated output on the other side of it (a child-process
     * fork would prove nothing about assertions this test could make on the
     * PARENT's response object). This assertion is deferred to Task 4.3's
     * HTTP pass, which drives the route with a real HTTP client and can
     * inspect the actual response body/headers post-exit.
     *
     * Deferred assert: "a pattern-route callback returning a Response with a
     * template renders that template's output on the wire" — verify in Task
     * 4.3 via a real `curl`/HTTP request against a registered
     * `ntdst_router()->when(...)`-style template route, asserting the
     * response body contains the template's rendered markup.
     */
    public function testRouterTemplateExitPathIsDeferredToHttpPass(): void
    {
        $this->markTestSkipped(
            'Deferred to Task 4.3 HTTP pass — a template-response Router callback exits the process (echo + exit), which cannot be asserted in-process. See method docblock for the exact deferred assertion.',
        );
    }
}
