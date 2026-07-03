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

    /** @var list<string> */
    private array $methods;

    /** @var list<string> */
    private array $headers;

    private ?int $max_age;

    /** Route prefix this policy's header emission is scoped to (set by register()). */
    private string $routePrefix = '';

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
        } elseif (is_callable($origins)) {
            $this->origins = $origins;
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
     */
    public function setHeaderSender(callable $sender): void
    {
        $this->headerSender = $sender;
    }

    /**
     * Test-only seam — swap the header remover for a capturing closure.
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
     */
    public function register(string $routePrefix): void
    {
        $this->routePrefix = $routePrefix;

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

        if (is_callable($this->origins)) {
            return (bool) ($this->origins)($origin, $request);
        }

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
        if (!str_starts_with($request->get_route(), $this->routePrefix)) {
            return $served;
        }

        // WP core's own rest_send_cors_headers() already ran at the default
        // priority (10, on rest_api_init) and, for ANY Origin header, will
        // have set Access-Control-Allow-Credentials: true — this route must
        // never advertise credentialed CORS unless explicitly configured to
        // (it is not, currently). header() replaces same-name headers, but
        // there is no "Allow-Credentials" header of ours to send in its
        // place, so it must be explicitly removed here rather than left as
        // core's default. Ground-truthed live against a real request in the
        // reference implementation: without this line, `curl -i` showed
        // `access-control-allow-credentials: true` on the route's response.
        ($this->headerRemover)('Access-Control-Allow-Credentials');

        $origin = (string) $request->get_header('origin');

        if ($this->allowsOrigin($origin, $request)) {
            ($this->headerSender)('Access-Control-Allow-Origin: ' . $origin);
            ($this->headerSender)('Access-Control-Allow-Methods: ' . implode(', ', $this->methods));
            ($this->headerSender)('Access-Control-Allow-Headers: ' . implode(', ', $this->headers));
            ($this->headerSender)('Vary: Origin');

            if ($this->max_age !== null) {
                ($this->headerSender)('Access-Control-Max-Age: ' . $this->max_age);
            }
        } else {
            // Non-matching (or absent) origin: strip whatever core's
            // reflection already set for Allow-Origin too, so a mismatched
            // or origin-less caller gets no CORS headers at all on this
            // route (mitigation 5's "non-matching origins get no CORS
            // headers" requirement).
            ($this->headerRemover)('Access-Control-Allow-Origin');
        }

        return $served;
    }
}
