<?php

declare(strict_types=1);

namespace Stride\Tests\Integration;

use IntegrationTestCase;

/**
 * Integration tests for NTDST_Cors_Policy over the REAL WP REST dispatch
 * path (task 4.2) — the piece the unit suite (NtdstCorsPolicyTest) cannot
 * prove: that this policy's priority-20 registration on
 * `rest_pre_serve_request` actually WINS over WP core's own
 * `rest_send_cors_headers()` (priority 10, added on rest_api_init —
 * web/wp/wp-includes/rest-api.php:252) in a real filter chain, for a real
 * dispatched request, including the OPTIONS preflight.
 *
 * Fixture route: `web/app/mu-plugins/test-cors-fixture.php` registers
 * POST /ntdst-cors-test/v1/thing, wired with
 * `NTDST_Cors_Policy(['origins' => ['https://allowed.test']])` through the
 * REAL registrar facade — `ntdst_router()->rest('ntdst-cors-test/v1')->post(
 * '/thing', $handler, ['permission' => ..., 'cors' => $policy])` — the SAME
 * code path production routes use (RestRegistrar.php:270-286), so the
 * policy's register() scoping is exercised through production wiring, not
 * constructed and registered by hand inside the test.
 *
 * WHY A PERMANENT MU-PLUGIN FIXTURE, NOT AN IN-TEST REGISTRATION: this test
 * observes headers via a REAL loopback HTTP round-trip
 * (wp_remote_post()/wp_remote_request() against rest_url()), the same
 * pattern CatalogEndpointTest's guestCanFetchNonceAndPageThroughRealHttp()
 * uses. A route registered from inside a PHPUnit test method only exists in
 * THAT process's memory — the loopback request is served by a SEPARATE
 * PHP-FPM process that never saw it, so it would 404 and the test would
 * silently observe only WP core's untouched defaults. Ground-truthed live:
 * an in-test registration reproducibly 404'd under wp_remote_post() even
 * though `has_filter('rest_pre_serve_request')` was true in-process.
 * Registering from an mu-plugin that loads on EVERY bootstrap (mirroring
 * test-login-helper.php's backdoor-login route in the same directory) is
 * what makes the fixture visible to the separate loopback process too.
 *
 * A DISTINCT namespace (`ntdst-cors-test/v1`) from the concurrent Task 4.1
 * RestRegistrarIntegrationTest's `ntdst-test/v1` avoids fixture collision.
 *
 * How headers are observed: real loopback HTTP via wp_remote_post() /
 * wp_remote_request(), reading wp_remote_retrieve_headers() — NOT the
 * setHeaderSender()/setHeaderRemover() capture seams the unit suite uses.
 * curl is available in this ddev environment; xdebug is NOT
 * (`php -m | grep xdebug` → not loaded; a direct `header()` + immediate
 * `headers_list()` probe in the CLI SAPI returned an empty array), so
 * xdebug_get_headers() is not an option and native header() calls are
 * genuinely unobservable in-process here. A real loopback HTTP round-trip
 * observes ACTUAL header() output on the wire — the only way in this
 * environment to prove prio 20 beats WP core's prio 10 in practice, since
 * both callbacks fire on the SAME rest_pre_serve_request event for the SAME
 * real dispatched request. This env therefore proves prio-20-beats-10
 * directly; no deferral to a 4.3 curl pass is needed for that specific
 * claim.
 *
 * Run: ddev exec "vendor/bin/phpunit -c phpunit-integration.xml.dist --filter CorsPolicyIntegration"
 */
final class CorsPolicyIntegrationTest extends IntegrationTestCase
{
    private const ROUTE = 'ntdst-cors-test/v1/thing';

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        parent::tearDown();
    }

    private function url(): string
    {
        return rest_url(self::ROUTE);
    }

    // -------------------------------------------------------------------
    // (a) Allowed origin, real POST dispatch — exact ACAO + Vary, and
    //     Access-Control-Allow-Credentials REMOVED (mitigation 1 — the
    //     load-bearing one: WP core's rest_send_cors_headers() sets it to
    //     "true" at prio 10 for ANY Origin; our prio-20 override must strip
    //     it on THIS route regardless of allow/deny outcome).
    // -------------------------------------------------------------------

    /** @test */
    public function allowedOriginRealPostGetsExactAcaoAndVaryWithCredentialsStripped(): void
    {
        $response = wp_remote_post($this->url(), [
            'sslverify' => false,
            'headers' => [
                'Origin' => 'https://allowed.test',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([]),
        ]);

        $this->assertFalse(is_wp_error($response), 'Loopback HTTP to own site must succeed for this assertion to mean anything.');

        $headers = wp_remote_retrieve_headers($response);

        $this->assertSame(
            'https://allowed.test',
            $headers['access-control-allow-origin'] ?? null,
            'Prio-20 policy must set the EXACT allowed origin, not a reflection of the incoming Origin.',
        );

        // Vary may legitimately carry more than just "Origin" on this server
        // (nginx appends its own "Accept-Encoding" Vary line independent of
        // WP/PHP entirely — ground-truthed via `curl -i` against the
        // homepage, which also shows it with zero REST/CORS code involved).
        // The CORS-specific assertion is that our policy's own "Origin" Vary
        // token is present, not that the header carries ONLY that token.
        $varyValues = (array) ($headers['vary'] ?? []);
        $this->assertContains('Origin', $varyValues, 'Vary must include Origin (policy header emission).');

        // The anti-pattern guard (item e): credentials header absent for
        // EVERY origin state, including the allowed one. WP core's prio-10
        // rest_send_cors_headers() sets this to "true" whenever an Origin
        // header is present at all — proving it is ABSENT here is the
        // direct proof that prio 20 ran and won over prio 10 on the real
        // response.
        $this->assertArrayNotHasKey(
            'access-control-allow-credentials',
            $headers->getAll(),
            'Access-Control-Allow-Credentials must be stripped by the prio-20 policy even for an allowed origin — proves prio 20 beat core\'s prio 10 default.',
        );
    }

    // -------------------------------------------------------------------
    // (b) OPTIONS preflight, allowed origin — same policy headers present.
    //     Proves the real preflight request also reaches
    //     rest_pre_serve_request (WP core's OPTIONS handling on
    //     rest_pre_dispatch returns a schema response with no CORS headers
    //     of its own — CorsPolicy.php:264-271 docblock).
    // -------------------------------------------------------------------

    /** @test */
    public function optionsPreflightWithAllowedOriginGetsSamePolicyHeaders(): void
    {
        $response = wp_remote_request($this->url(), [
            'method' => 'OPTIONS',
            'sslverify' => false,
            'headers' => [
                'Origin' => 'https://allowed.test',
                'Access-Control-Request-Method' => 'POST',
            ],
        ]);

        $this->assertFalse(is_wp_error($response), 'Loopback OPTIONS to own site must succeed for this assertion to mean anything.');

        $headers = wp_remote_retrieve_headers($response);

        $this->assertSame(
            'https://allowed.test',
            $headers['access-control-allow-origin'] ?? null,
            'A real OPTIONS preflight must reach rest_pre_serve_request and receive the same exact-origin policy headers as a real POST.',
        );
        $varyValues = (array) ($headers['vary'] ?? []);
        $this->assertContains('Origin', $varyValues, 'Vary must include Origin on the preflight response too.');
        $this->assertArrayNotHasKey(
            'access-control-allow-credentials',
            $headers->getAll(),
            'Credentials header must be stripped on the preflight response too.',
        );
    }

    // -------------------------------------------------------------------
    // (c) Disallowed origin — no ACAO, credentials removed too (the
    //     anti-pattern guard applies here as well).
    // -------------------------------------------------------------------

    /** @test */
    public function disallowedOriginGetsNoAcaoAndCredentialsStillStripped(): void
    {
        $response = wp_remote_post($this->url(), [
            'sslverify' => false,
            'headers' => [
                'Origin' => 'https://evil.test',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([]),
        ]);

        $this->assertFalse(is_wp_error($response), 'Loopback HTTP to own site must succeed for this assertion to mean anything.');

        $headers = wp_remote_retrieve_headers($response);
        $all = $headers->getAll();

        $this->assertArrayNotHasKey(
            'access-control-allow-origin',
            $all,
            'A disallowed origin must receive NO Access-Control-Allow-Origin at all — core\'s reflection default must be stripped too.',
        );
        $this->assertArrayNotHasKey(
            'access-control-allow-credentials',
            $all,
            'Credentials header must be stripped even for a disallowed/denied origin (mitigation 1 fires unconditionally).',
        );
    }

    // -------------------------------------------------------------------
    // (d) Foreign route (/wp/v2/types) — policy headers NOT applied,
    //     untouched (mitigation 7 — never a global filter). Also confirms
    //     WP core's OWN prio-10 reflect-any-origin default IS present on a
    //     route our policy does not guard — the contrasting baseline that
    //     proves our policy is scoped, not that CORS headers are globally
    //     absent in this environment.
    // -------------------------------------------------------------------

    /** @test */
    public function foreignRouteNeverReceivesThisPolicysHeaders(): void
    {
        $response = wp_remote_get(rest_url('wp/v2/types'), [
            'sslverify' => false,
            'headers' => [
                'Origin' => 'https://allowed.test',
            ],
        ]);

        $this->assertFalse(is_wp_error($response), 'Loopback HTTP to own site must succeed for this assertion to mean anything.');

        $headers = wp_remote_retrieve_headers($response);

        // Core's own prio-10 default reflects ANY origin verbatim on a route
        // this policy never registered against — proving the ABSENCE on our
        // own route (assertions a/b/c above) is due to OUR override, not an
        // environment-wide quirk suppressing CORS headers everywhere.
        $this->assertSame(
            'https://allowed.test',
            $headers['access-control-allow-origin'] ?? null,
            'Core\'s untouched prio-10 default must still reflect the Origin on a route our policy does not guard.',
        );
        $this->assertSame(
            'true',
            $headers['access-control-allow-credentials'] ?? null,
            'Core\'s untouched prio-10 default must still set Allow-Credentials: true on a foreign route — the exact behavior our policy exists to override, proven present here where it is NOT overridden.',
        );
    }
}
