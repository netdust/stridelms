<?php

declare(strict_types=1);

/**
 * NTDST REST Registrar — namespaced REST route registration (INV-10).
 *
 * This is the Phase 3 convergence point being built up in stages:
 *  - 3.1 (this file): registration core + required-permission enforcement.
 *  - 3.2: permission_callback memoization (WP-core quirk 2).
 *  - 3.3 (this file): request-body size / JSON-depth caps (WP-core quirk 3).
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
     * Per-route body-size/JSON-depth caps, keyed by the fully-qualified
     * registration-time route string ('/' . namespace . route). Because
     * route syntax is WP-native regex (D5), each key is itself a regex
     * fragment (e.g. '/stride/v1/orders/(?P<id>\d+)') — NOT necessarily the
     * literal path WP_REST_Request::get_route() carries at dispatch time
     * (that is the CONCRETE request path, e.g. '/stride/v1/orders/42', set
     * once in the request constructor from the URL,
     * class-wp-rest-request.php:127). The lookup in capsForConcretePath()
     * is therefore regex-aware, mirroring core's own matching. Populated by
     * registerOne() for any route that configured 'max_body_bytes' and/or
     * 'max_json_depth', consulted by enforceBodyLimitsBeforeDispatch().
     *
     * @var array<string, array{max_body_bytes: int|null, max_json_depth: int|null}>
     */
    private array $routeCaps = [];

    /**
     * Whether the rest_pre_dispatch filter has already been hooked for this
     * instance — guards against adding it more than once, and is the
     * mechanism behind "lazy": the filter is only ever added the first time
     * a route with at least one cap is registered (task 3.3), never
     * unconditionally in the constructor.
     */
    private bool $bodyLimitFilterHooked = false;

    public function __construct(string $namespace)
    {
        $this->namespace = $namespace;
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

        $maxBodyBytes = $options['max_body_bytes'] ?? null;
        $maxJsonDepth = $options['max_json_depth'] ?? null;

        if ($maxBodyBytes !== null || $maxJsonDepth !== null) {
            $routeKey = '/' . trim($this->namespace, '/') . $entry['route'];
            $this->routeCaps[$routeKey] = [
                'max_body_bytes' => $maxBodyBytes,
                'max_json_depth' => $maxJsonDepth,
            ];

            $this->ensureBodyLimitFilterHooked();
        }

        register_rest_route($this->namespace, $entry['route'], $args);
    }

    /**
     * Lazily hooks enforceBodyLimitsBeforeDispatch() onto rest_pre_dispatch
     * — added ONCE per instance, and ONLY the first time a route with at
     * least one cap ('max_body_bytes' and/or 'max_json_depth') is
     * registered. A registrar whose routes carry no caps at all never adds
     * this filter (task 3.3 Step 1(g)).
     *
     * Priority 5 (earlier than the default 10, and earlier than
     * CorsPolicy's rest_pre_serve_request priority 20 — different hook
     * entirely, noted only for contrast): rest_pre_dispatch fires only
     * once, so priority here just needs to be lower than any OTHER
     * rest_pre_dispatch consumer that might expect to inspect params later
     * in the chain; there is no WP-core default callback on this hook to
     * out-race (unlike rest_pre_serve_request, where core's own
     * rest_send_cors_headers() runs at priority 10 on rest_api_init and
     * CorsPolicy deliberately runs after it).
     */
    private function ensureBodyLimitFilterHooked(): void
    {
        if ($this->bodyLimitFilterHooked) {
            return;
        }

        $this->bodyLimitFilterHooked = true;
        add_filter('rest_pre_dispatch', [$this, 'enforceBodyLimitsBeforeDispatch'], 5, 3);
    }

    /**
     * Runs on rest_pre_dispatch — ground-truthed against
     * web/wp/wp-includes/rest-api/class-wp-rest-server.php: dispatch()
     * (line 1062) calls apply_filters('rest_pre_dispatch', null, $this,
     * $request) as the very FIRST thing it does (line 1078), strictly
     * before match_request_to_handler() (line 1095) and before
     * $request->has_valid_params() (line 1114). has_valid_params()
     * (class-wp-rest-request.php:885) calls parse_json_params() (line 685),
     * which for a JSON-content-typed, non-empty body runs
     * json_decode($body, true) at PHP's DEFAULT depth (512) at line 702. A
     * non-empty return here short-circuits dispatch() entirely — the
     * `if ( ! empty( $result ) )` branch at line 1080 returns before
     * match_request_to_handler() or has_valid_params() ever run for this
     * request — so this is the earliest point this registrar's own code can
     * enforce a cap, strictly before core's own default-depth parse.
     *
     * Scoped to ONLY this registrar's own route table (regex-aware match
     * against $this->routeCaps via capsForConcretePath()) — a request for
     * any route not in that table is passed through UNCHANGED (mitigation-7
     * analog: never a global filter, same posture as
     * NTDST_Cors_Policy::applyCorsHeaders()). Unlike NTDST_Cors_Policy's
     * prefix match (a policy can guard a whole namespace), the caps are
     * configured per individual route — and the match must be REGEX-aware,
     * not exact-string (3.3-review Finding 1, CRITICAL): at
     * rest_pre_dispatch time the request carries the CONCRETE path
     * ('/stride/v1/orders/42') while the table is keyed by the
     * registration-time WP-native regex ('/stride/v1/orders/(?P<id>\d+)');
     * an exact string compare NEVER matches a parameterized route, so its
     * caps silently never applied — fail-open. See capsForConcretePath()
     * for the core-mirrored matching.
     *
     * Ported and generalized from todai-client-form-intake's
     * SubmissionIntakeService::enforceBodyLimitsBeforeDispatch() (same
     * ground-truthed call-order reasoning), generalized from a single
     * hardcoded namespace/route/cap to this registrar's own per-route
     * table.
     *
     * @param mixed $result Passed through unchanged for every route not in
     *                       this registrar's own capped-route table, and for
     *                       any capped route whose body/depth is in bounds.
     */
    public function enforceBodyLimitsBeforeDispatch(mixed $result, mixed $server, WP_REST_Request $request): mixed
    {
        $caps = $this->capsForConcretePath($request->get_route());

        if ($caps === null) {
            return $result;
        }

        // get_body() is nullable in WP core (class-wp-rest-request.php:
        // `protected $body = null`) until set_body() has been called — cast
        // before strlen(), the same way the reference implementation does
        // (SubmissionIntakeService::enforceBodyLimitsBeforeDispatch()).
        $rawBody = (string) $request->get_body();

        if ($caps['max_body_bytes'] !== null && strlen($rawBody) > $caps['max_body_bytes']) {
            return new WP_Error('payload_too_large', 'Request could not be processed.', ['status' => 413]);
        }

        // Depth cap is gated on the request declaring a JSON Content-Type,
        // mirroring WP core's own parse_json_params(): core only attempts a
        // JSON decode at all when is_json_content_type() is true
        // (class-wp-rest-request.php:693 — public method since WP 5.6,
        // delegating to wp_is_json_media_type(), wp-includes/load.php:1962,
        // which also accepts application/*+json suffix types). The depth cap
        // exists precisely to pre-empt THAT default-depth-512 parse, so it
        // applies exactly when that parse would run. A non-JSON body (form-
        // encoded, multipart) is never JSON-decoded by core, so running
        // json_decode() on it here only manufactured wrongful invalid_json
        // 400s on legitimate requests (3.3-review Finding 2 — the earlier
        // "bound every body regardless of Content-Type" stance was wrong:
        // an attacker who LIES about the Content-Type to dodge this check
        // also dodges core's parse, so nothing unbounded ever runs). The
        // body-BYTES cap above stays content-agnostic — bytes are bytes.
        if ($caps['max_json_depth'] !== null && $rawBody !== '' && $request->is_json_content_type()) {
            json_decode($rawBody, true, $caps['max_json_depth']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new WP_Error('invalid_json', 'Request could not be processed.', ['status' => 400]);
            }
        }

        return $result;
    }

    /**
     * Resolves the caps entry for a CONCRETE request path by matching it
     * against this registrar's registration-time route patterns, exactly
     * the way WP core matches a request to a handler —
     * WP_REST_Server::match_request_to_handler()
     * (class-wp-rest-server.php:1171) does
     * `preg_match('@^' . $route . '$@i', $path)`: the stored route string
     * IS the regex ('(?P<id>\d+)' is literal regex, D5), wrapped in '@'
     * delimiters, anchored '^…$', case-insensitive. Same delimiters, same
     * anchors, same flag here.
     *
     * Exact string match is kept as a fast path — a literal route (no
     * capture group) is its own concrete path, and every pre-existing
     * literal-route cap hits it without a preg_match call. It is an
     * optimization only; the regex pass is the correctness requirement
     * (3.3-review Finding 1).
     *
     * Ambiguity (two registered patterns both matching one concrete path)
     * resolves by ORDERED FIRST-MATCH in registration order — mirroring
     * core, whose match_request_to_handler() iterates the routes array
     * (registration-ordered per namespace) and returns on the first
     * pattern that preg_matches. $this->routeCaps insertion order is
     * registration order, so iteration order matches core's.
     *
     * @return array{max_body_bytes: int|null, max_json_depth: int|null}|null
     */
    private function capsForConcretePath(string $path): ?array
    {
        if (isset($this->routeCaps[$path])) {
            return $this->routeCaps[$path];
        }

        foreach ($this->routeCaps as $pattern => $caps) {
            if (preg_match('@^' . $pattern . '$@i', $path) === 1) {
                return $caps;
            }
        }

        return null;
    }

    /**
     * Wraps a caller-supplied permission callable so it is evaluated
     * EXACTLY ONCE per WP_REST_Request object, replaying the memoized
     * result (bool or WP_Error, by identity for WP_Error) on any
     * subsequent call with the SAME request object (task 3.2 — WP-core
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
     * The memoization map is PER WRAPPER — each wrapPermission() call
     * creates its own private WeakMap captured in the returned closure,
     * never shared across wrappers or stored on the registrar instance.
     * This makes cross-route verdict collision structurally impossible:
     * rest_send_allow_header() invokes EVERY handler's permission_callback
     * for the matched path with the SAME request object, so a map shared
     * across wrappers and keyed only by the request would replay one
     * route's cached verdict to every other route on that path — a
     * stricter route silently inheriting a laxer route's `true` (an auth
     * bypass, caught at the 3.2 review; per-wrapper maps are the correct
     * realization of threat-model mitigation 3). Same wrapper + same
     * request → one evaluation; different wrappers → fully independent
     * verdicts.
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
     * Split into this thin memoizing wrapper plus the caller's own
     * $permission callable (analogous to the reference
     * checkWritePermission()/evaluateWritePermission() split) so the
     * memoization concern lives in exactly one place regardless of how
     * many return points the wrapped callable has.
     */
    private function wrapPermission(callable $permission): callable
    {
        /** @var \WeakMap<WP_REST_Request, bool|WP_Error> $cache */
        $cache = new \WeakMap();

        return function (WP_REST_Request $request) use ($permission, $cache): bool|WP_Error {
            if (isset($cache[$request])) {
                return $cache[$request];
            }

            return $cache[$request] = $permission($request);
        };
    }
}
