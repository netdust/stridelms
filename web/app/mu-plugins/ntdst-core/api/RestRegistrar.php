<?php

declare(strict_types=1);

/**
 * NTDST REST Registrar — namespaced REST route registration (INV-10).
 *
 * This is the Phase 3 convergence point being built up in stages:
 *  - 3.1 (this file): registration core + required-permission enforcement.
 *  - 3.2: permission_callback memoization (WP-core quirk 2).
 *  - 3.3: request-body size caps.
 *  - 3.4: handler-return normalization + Router::rest() facade + CORS wiring.
 *
 * Route syntax is WP-native regex (D5) — routes/methods are passed straight
 * through to register_rest_route(), never translated or reinterpreted.
 *
 * `permission` is a REQUIRED option with NO default (threat-model mitigation
 * 6). A route queued without a callable `permission` is never registered —
 * register_rest_route() is not called for it at all, _doing_it_wrong() fires,
 * and the failure is logged via ntdst_log('api')->error(). There is no
 * "public route" fallback baked in here: a caller that genuinely wants an
 * unauthenticated route must pass an explicit always-true permission
 * callable, so the intent is visible in the call site rather than implied by
 * a missing option.
 */

defined('ABSPATH') || exit;

final class NTDST_Rest_Registrar
{
    private string $namespace;

    /**
     * Routes queued for registration, drained by flush().
     *
     * @var list<array{route: string, methods: string|list<string>, handler: callable, options: array}>
     */
    private array $queue = [];

    /**
     * Whether this instance has already hooked flush() onto rest_api_init —
     * guards against double add_action() if get/post/etc. are called
     * repeatedly after rest_api_init already fired (each of those calls
     * registers immediately in that case and never needs the hook).
     */
    private bool $hooked = false;

    /**
     * Per-real-request memoization of a wrapped permission_callback's
     * result, keyed by WP_REST_Request object identity (task 3.2 — WP-core
     * quirk 2).
     *
     * WordPress core's own rest_send_allow_header() (hooked on
     * rest_post_dispatch, ground-truthed against
     * web/wp/wp-includes/rest-api.php:854-886) calls a matched route's
     * permission_callback a SECOND time for every dispatched request
     * (success or denial), to compute the response's Allow header — see
     * that file's call_user_func($_handler['permission_callback'],
     * $request) around line 871. WordPress reuses the SAME
     * WP_REST_Request object for both invocations within one real HTTP
     * request (dispatch() and respond_to_request() thread the same
     * $request through), so object identity correctly identifies "the
     * same real request". A permission callable with a side effect (e.g.
     * a rate-limit counter, ported from the reference
     * SubmissionIntakeService::checkWritePermission()) would otherwise
     * double-count on every dispatched request.
     *
     * WeakMap (not a plain array keyed by spl_object_id()) is required
     * here: spl_object_id() is a slot index PHP reuses IMMEDIATELY once an
     * object is garbage-collected — ground-truthed (per the reference
     * implementation's own docblock) with a minimal repro: a tight loop
     * creating/discarding objects yielded ids 1,2,1,2,1,.... A plain-array
     * cache keyed by that id would let a later, unrelated
     * WP_REST_Request (a genuinely different real request) collide with a
     * stale cache entry left by an earlier, already-freed request object
     * that happened to get the same id — silently returning a wrong,
     * stale permission result for a distinct request. WeakMap keys by
     * actual object identity (never collides across distinct objects) and
     * auto-evicts its entry the moment the key object is garbage
     * collected, so the cache can never outlive or misattribute across
     * requests.
     *
     * Rejected alternative: WP_REST_Request::set_attributes()/
     * get_attributes() (the route-match attributes array — methods,
     * callback, args schema — used by sanitize_params()/
     * has_valid_params()) would corrupt the request's own param-validation
     * state if repurposed to stash an unrelated memoized value. Also
     * rejected: ArrayAccess/offsetSet, which maps to set_param() and would
     * pollute the app-facing params namespace / get_json_params() results.
     *
     * Shared per registrar instance (not per route) — every wrapped
     * permission callable produced by this instance's wrapPermission()
     * reads/writes the same map, keyed by the request object each
     * evaluation actually received, so entries for different routes never
     * collide (distinct request objects per dispatched request).
     *
     * @var \WeakMap<WP_REST_Request, bool|WP_Error>
     */
    private \WeakMap $permissionResultCache;

    public function __construct(string $namespace)
    {
        $this->namespace = $namespace;
        $this->permissionResultCache = new \WeakMap();
    }

    public function get(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'GET', $handler, $options);
    }

    public function post(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'POST', $handler, $options);
    }

    public function put(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'PUT', $handler, $options);
    }

    public function patch(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'PATCH', $handler, $options);
    }

    public function delete(string $route, callable $handler, array $options = []): self
    {
        return $this->route($route, 'DELETE', $handler, $options);
    }

    /**
     * @param string|list<string> $methods
     */
    public function route(string $route, string|array $methods, callable $handler, array $options = []): self
    {
        $entry = [
            'route' => $route,
            'methods' => $methods,
            'handler' => $handler,
            'options' => $options,
        ];

        // D1✦ (doubt-pass correction) — the silent-no-op trap: if
        // rest_api_init has ALREADY fired (e.g. this registrar is built
        // lazily, after WP's own bootstrap sequence has passed the hook),
        // queueing the route and waiting for a flush() that add_action()
        // would schedule for a rest_api_init that will never fire again
        // means the route silently never registers. Registering
        // immediately in that case is what makes route()/get()/post()/etc.
        // safe to call at any time in the request lifecycle.
        if (did_action('rest_api_init')) {
            $this->registerOne($entry);
            return $this;
        }

        $this->queue[] = $entry;
        $this->ensureHooked();

        return $this;
    }

    /**
     * Hook flush() onto rest_api_init exactly once per instance.
     */
    private function ensureHooked(): void
    {
        if ($this->hooked) {
            return;
        }

        $this->hooked = true;
        add_action('rest_api_init', [$this, 'flush']);
    }

    /**
     * Drain the queue, registering every route via register_rest_route().
     * Idempotent: once the queue is empty, a repeat call is a no-op — a
     * route is only ever registered once per queue() call, never
     * re-registered by a second flush() (e.g. a defensive direct call, or
     * rest_api_init somehow firing twice).
     */
    public function flush(): void
    {
        $pending = $this->queue;
        $this->queue = [];

        foreach ($pending as $entry) {
            $this->registerOne($entry);
        }
    }

    /**
     * @param array{route: string, methods: string|list<string>, handler: callable, options: array} $entry
     */
    private function registerOne(array $entry): void
    {
        $options = $entry['options'];

        $permission = $options['permission'] ?? null;

        if (!is_callable($permission)) {
            // Mitigation 6: a route with no callable permission is never
            // handed to register_rest_route() at all — fail closed, not
            // open. Surfaced loudly (both to developers via
            // _doing_it_wrong() and to logs) rather than silently skipped,
            // so a missing permission is caught in development, not
            // discovered as a live open route.
            _doing_it_wrong(
                self::class . '::route',
                sprintf(
                    'Route "%s%s" was not registered — "permission" is a required option and must be a callable. Refusing to register a REST route with no permission check.',
                    $this->namespace,
                    $entry['route'],
                ),
                '1.0.0',
            );

            ntdst_log('api')->error(
                sprintf('REST route registration refused — missing/non-callable permission for %s%s', $this->namespace, $entry['route']),
                [
                    'namespace' => $this->namespace,
                    'route' => $entry['route'],
                    'methods' => $entry['methods'],
                ],
            );

            return;
        }

        $args = [
            'methods' => $entry['methods'],
            'callback' => $entry['handler'],
            'permission_callback' => $this->wrapPermission($permission),
        ];

        if (array_key_exists('args', $options)) {
            $args['args'] = $options['args'];
        }

        register_rest_route($this->namespace, $entry['route'], $args);
    }

    /**
     * Wraps a caller-supplied permission callable so it is evaluated
     * EXACTLY ONCE per WP_REST_Request object, replaying the memoized
     * result (bool or WP_Error, by identity for WP_Error) on any
     * subsequent call with the SAME request object — see
     * $permissionResultCache's docblock for the WP-core quirk this exists
     * to neutralize.
     *
     * Split into this thin memoizing wrapper plus the caller's own
     * $permission callable (analogous to the reference
     * checkWritePermission()/evaluateWritePermission() split) so the
     * memoization concern lives in exactly one place regardless of how
     * many return points the wrapped callable has.
     */
    private function wrapPermission(callable $permission): callable
    {
        return function (WP_REST_Request $request) use ($permission): bool|WP_Error {
            if (isset($this->permissionResultCache[$request])) {
                return $this->permissionResultCache[$request];
            }

            return $this->permissionResultCache[$request] = $permission($request);
        };
    }
}
