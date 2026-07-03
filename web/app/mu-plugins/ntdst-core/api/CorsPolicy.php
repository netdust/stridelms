<?php

declare(strict_types=1);

/**
 * NTDST CORS Policy — the CORS decision surface.
 *
 * Threat-model attack 8 / mitigation 8: exact string identity via
 * in_array($origin, $list, true) against full scheme://host[:port] strings.
 * The literal 'null' and '' are rejected BEFORE any list/callable consult,
 * so a misconfigured allow-list (or an overly permissive callable resolver)
 * can never be tricked into matching an opaque/sandboxed-iframe origin.
 * '*' is refused at construction — this class never emits a wildcard.
 */

defined('ABSPATH') || exit;

final class NTDST_Cors_Policy
{
    /** @var list<string>|callable */
    private $origins;

    /**
     * Whether $origins is a resolver callable (decided ONCE, at construction,
     * where the array-before-is_callable ordering lives). allowsOrigin() must
     * branch on THIS flag, never re-run is_callable() on the stored value — a
     * callable-shaped two-string list (e.g. ['DateTime', 'createFromFormat'])
     * would otherwise be invoked as a resolver at call time even though the
     * constructor correctly classified it as an exact-match allow-list.
     */
    private bool $originsIsCallable;

    /** @var list<string> */
    private array $methods;

    /** @var list<string> */
    private array $headers;

    private ?int $max_age;

    /**
     * Route prefixes this policy's header emission is scoped to (appended by
     * register(); a policy may guard several prefixes).
     *
     * @var list<string>
     */
    private array $routePrefixes = [];

    /**
     * Injectable header sender/remover for testability — real PHP
     * header()/header_remove() by default. Native header() calls can't be
     * observed in the CLI SAPI (headers_list() never reflects them there),
     * so applyCorsHeaders() routes every header mutation through these
     * seams instead of calling the global functions directly, letting
     * tests capture emitted/removed headers without relying on a real
     * HTTP response. Ported from the proven pattern in
     * todai-client-form-intake's SubmissionIntakeService.
     *
     * @var callable(string):void
     */
    private $headerSender;

    /**
     * @var callable(string):void
     */
    private $headerRemover;

    /**
     * @param array{
     *     origins: list<string>|callable,
     *     methods?: list<string>,
     *     headers?: list<string>,
     *     max_age?: int|null,
     * } $config
     */
    public function __construct(array $config)
    {
        if (!array_key_exists('origins', $config)) {
            throw new InvalidArgumentException('NTDST_Cors_Policy requires an "origins" config key.');
        }

        $origins = $config['origins'];

        // Array checked BEFORE is_callable(): a plain list of two strings
        // (e.g. ['SomeClass', 'someMethod']) is technically PHP-callable and
        // must never be silently reinterpreted as a resolver instead of an
        // exact-match allow-list.
        if (is_array($origins)) {
            if (!array_is_list($origins)) {
                throw new InvalidArgumentException('NTDST_Cors_Policy "origins" must be a list of strings or a callable.');
            }
            foreach ($origins as $origin) {
                if (!is_string($origin)) {
                    throw new InvalidArgumentException('NTDST_Cors_Policy origins list must contain only strings.');
                }
                if ($origin === '*') {
                    throw new InvalidArgumentException('NTDST_Cors_Policy does not allow "*" as an origin — this class never emits a wildcard.');
                }
            }
            $this->origins = $origins;
            $this->originsIsCallable = false;
        } elseif (is_callable($origins)) {
            $this->origins = $origins;
            $this->originsIsCallable = true;
        } else {
            throw new InvalidArgumentException('NTDST_Cors_Policy "origins" must be a list of strings or a callable.');
        }

        $this->methods = $config['methods'] ?? ['GET', 'POST', 'OPTIONS'];
        $this->headers = $config['headers'] ?? ['Content-Type'];
        $this->max_age = $config['max_age'] ?? null;

        $this->headerSender = static function (string $header): void {
            header($header);
        };
        $this->headerRemover = static function (string $name): void {
            header_remove($name);
        };
    }

    /**
     * Test-only seam — swap the header sender for a capturing closure.
     *
     * @internal Not a consumer API.
     */
    public function setHeaderSender(callable $sender): void
    {
        $this->headerSender = $sender;
    }

    /**
     * Test-only seam — swap the header remover for a capturing closure.
     *
     * @internal Not a consumer API.
     */
    public function setHeaderRemover(callable $remover): void
    {
        $this->headerRemover = $remover;
    }

    /**
     * Wire this policy's header emission into WP's REST-serve pipeline,
     * scoped to ONLY $routePrefix — never a global CORS filter (Threat
     * model mitigation 5: never a global wildcard).
     *
     * Priority is deliberately 20, NOT the default 10. Ground-truthed
     * against web/wp/wp-includes/rest-api.php: WP core registers its OWN
     * rest_send_cors_headers() on this same filter, at the default
     * priority (10), on the rest_api_init action itself. That callback
     * reflects ANY Origin header unconditionally and sets
     * Access-Control-Allow-Credentials: true — exactly the
     * reflection/wildcard anti-pattern this policy exists to override
     * (threat-model attacks 1, 2, 7 / mitigations 1, 2, 7). Since PHP's
     * header() replaces a same-name header by default, and WP_Hook runs
     * equal-priority callbacks in registration order, running at a later
     * priority than core's default guarantees this policy's explicit
     * exact-origin decision is the one that wins on the actual HTTP
     * response, for this route only.
     *
     * Adapted from todai-client-form-intake's
     * SubmissionIntakeService::init() (same ground-truthed reasoning),
     * generalized to an injectable $routePrefix instead of a
     * class-constant namespace.
     *
     * May be called more than once: each call APPENDS its prefix (idempotent
     * for a repeated identical prefix), so one policy can guard several route
     * namespaces. Overwriting instead of appending would silently leave every
     * earlier prefix fail-open on core's reflect+credentials default.
     */
    public function register(string $routePrefix): void
    {
        if ($routePrefix === '') {
            // str_starts_with($route, '') is always true — an empty
            // prefix would apply this policy's headers (and the
            // credentials strip) to EVERY REST route, including
            // /wp/v2/*. That directly contradicts this class's "never a
            // global filter" invariant (threat-model mitigation 5), so
            // it must be refused here rather than silently accepted.
            throw new InvalidArgumentException(
                'NTDST_Cors_Policy::register() route prefix must not be empty — an empty prefix would match every REST route and turn this into a global CORS filter.',
            );
        }

        if (!str_starts_with($routePrefix, '/')) {
            // WP_REST_Request::get_route() always returns a leading-slash
            // form, so a prefix like 'ntdst/v1' (missing the slash) can
            // NEVER match in applyCorsHeaders()'s str_starts_with() check
            // — register() would appear to succeed while the policy
            // silently never fires. Same trap family as the empty-string
            // case above, just the opposite failure mode.
            throw new InvalidArgumentException(
                'NTDST_Cors_Policy::register() route prefix must start with "/" — WP_REST_Request::get_route() always returns a leading-slash form, so a relative prefix would never match and this policy would silently never fire.',
            );
        }

        if (preg_match('#^/[^/]#', $routePrefix) !== 1) {
            // '/' (and '//…') passes both checks above, but a bare root
            // prefix matches EVERY REST route — the same global-filter trap
            // as the empty string, just spelled differently. The prefix must
            // be a leading slash followed by a real first segment character.
            throw new InvalidArgumentException(
                'NTDST_Cors_Policy::register() route prefix must be "/" followed by a route segment (e.g. "/stride/v1") — a bare "/" would match every REST route and turn this into a global CORS filter.',
            );
        }

        if (!in_array($routePrefix, $this->routePrefixes, true)) {
            $this->routePrefixes[] = $routePrefix;
        }

        // The trailing 4 is rest_pre_serve_request's filter arity ($served,
        // $result, $request, $server) — trimming it breaks the callback
        // signature (WP would invoke applyCorsHeaders() with fewer args than
        // its required parameters). Real WP dedupes this identical callback
        // across repeated register() calls, so the filter is added once.
        add_filter('rest_pre_serve_request', [$this, 'applyCorsHeaders'], 20, 4);
    }

    /**
     * Decide whether $origin is allowed.
     *
     * The '' and 'null' guard runs BEFORE any list/callable consult — a
     * configured resolver that matches everything (or a list that
     * literally contains 'null'/'') can never approve an opaque origin.
     */
    public function allowsOrigin(string $origin, WP_REST_Request $request): bool
    {
        if ($origin === '' || $origin === 'null') {
            return false;
        }

        // Branch on the construction-time classification, NOT is_callable():
        // a callable-shaped two-string allow-list must stay a list here too.
        if ($this->originsIsCallable) {
            return (bool) ($this->origins)($origin, $request);
        }

        // Strict in_array means a list-approved $origin is byte-identical to
        // a server-side config string — safe to echo by construction.
        return in_array($origin, $this->origins, true);
    }

    /** @return list<string> */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /** @return list<string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMaxAge(): ?int
    {
        return $this->max_age;
    }

    /**
     * Scoped CORS header emission — ONLY sets headers for THIS policy's
     * own route (route-prefix check), and ONLY when the request Origin
     * matches per allowsOrigin(). Never a wildcard, never a blind Origin
     * reflection. Threat model mitigation 5.
     *
     * Fires for every method WP's REST server serves, INCLUDING the
     * OPTIONS preflight — rest_pre_serve_request runs for all methods, and
     * WP core's own OPTIONS handling (rest_handle_options_request, on
     * rest_pre_dispatch) returns a schema-description response with no
     * CORS headers of its own, so this is the only place the preflight
     * gets Access-Control-* headers at all (ground-truthed against
     * web/wp/wp-includes/rest-api.php).
     *
     * Adapted from todai-client-form-intake's
     * SubmissionIntakeService::applyCorsHeaders(), generalized to consult
     * THIS policy's allowsOrigin()/getMethods()/getHeaders()/getMaxAge()
     * instead of a form-key registry.
     */
    public function applyCorsHeaders(bool $served, mixed $result, WP_REST_Request $request, mixed $server): bool
    {
        // No register() call, no scope: bail before ANY header side effect.
        // Defends the raw add_filter-without-register() misuse — an unscoped
        // policy must never touch a response.
        if ($this->routePrefixes === []) {
            return $served;
        }

        if (!$this->matchesRegisteredPrefix($request->get_route())) {
            return $served;
        }

        // From here on, header side effects deliberately fire even when
        // $served === false — the headers must reflect this policy regardless
        // of the body-serving outcome, since core's reflection headers (prio
        // 10) are already on the response either way.

        // WP core's own rest_send_cors_headers() already ran at the default
        // priority (10, on rest_api_init) and, whenever an Origin header is
        // present (its `if ($origin)` guard), will have set
        // Access-Control-Allow-Credentials: true — this route must never
        // advertise credentialed CORS unless explicitly configured to (it is
        // not, currently). header() replaces same-name headers, but there is
        // no "Allow-Credentials" header of ours to send in its place, so it
        // must be explicitly removed here rather than left as core's default.
        // Removing unconditionally is a harmless no-op on origin-less
        // requests (core set nothing there). Ground-truthed live against a
        // real request in the reference implementation: without this line,
        // `curl -i` showed `access-control-allow-credentials: true` on the
        // route's response.
        ($this->headerRemover)('Access-Control-Allow-Credentials');

        $origin = (string) $request->get_header('origin');

        if ($this->allowsOrigin($origin, $request) && $this->isEmittableOriginShape($origin)) {
            ($this->headerSender)('Access-Control-Allow-Origin: ' . $origin);
            ($this->headerSender)('Access-Control-Allow-Methods: ' . implode(', ', $this->methods));
            ($this->headerSender)('Access-Control-Allow-Headers: ' . implode(', ', $this->headers));
            ($this->headerSender)('Vary: Origin');

            if ($this->max_age !== null) {
                ($this->headerSender)('Access-Control-Max-Age: ' . $this->max_age);
            }
        } else {
            // Non-matching (or absent) origin — or one that failed the
            // emission shape gate: strip whatever core's reflection already
            // set for Allow-Origin too, so a mismatched or origin-less
            // caller gets no CORS headers at all on this route (mitigation
            // 5's "non-matching origins get no CORS headers" requirement).
            ($this->headerRemover)('Access-Control-Allow-Origin');
        }

        return $served;
    }

    /**
     * Does $route fall under any registered prefix, respecting path-segment
     * boundaries? '/stride/v1' governs '/stride/v1' (exact) and
     * '/stride/v1/sub' (child), but NOT '/stride/v10/x' or
     * '/stride/v1-admin/x' — a plain str_starts_with() would leak this
     * policy's headers (and its credentials strip) into sibling namespaces.
     */
    private function matchesRegisteredPrefix(string $route): bool
    {
        foreach ($this->routePrefixes as $prefix) {
            if ($route === $prefix || str_starts_with($route, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Emission shape gate for the CALLABLE origins path. On the LIST path a
     * true allowsOrigin() means the echoed $origin is byte-identical to a
     * server-side config string — safe by construction. A consumer-supplied
     * resolver has no such guarantee: an over-permissive callable could
     * approve a request-shaped string that must never reach header(). Only
     * scheme://non-whitespace survives — no spaces, CR, or LF possible, so
     * this is a no-op for any legitimate Origin header, and it fires BEFORE
     * header() would (PHP's header() throws on CR/LF; the gate means no
     * exception path exists at all — a malformed origin is simply denied).
     * The /D modifier pins $ to the true end of string, so a trailing "\n"
     * (which an undollared $ would tolerate) also fails the gate.
     */
    private function isEmittableOriginShape(string $origin): bool
    {
        return preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://\S+$#D', $origin) === 1;
    }
}
