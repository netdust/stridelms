<?php

/**
 * NTDST REST Acceptance Fixture (Task 4.3)
 *
 * Registers `ntdst-test/v1/echo` — a fixture REST route guarded by a fresh
 * NTDST_Cors_Policy AND body/JSON-depth caps — plus a front-end pattern
 * route rendering a Response-with-template, so Task 4.3 can drive the full
 * Acceptance-flows matrix (Flows 1-3 + the Task-4.1-deferred Router
 * exit-path assertion) over REAL HTTP against a fresh PHP-FPM process.
 *
 * WHY THIS FILE HAS TO EXIST: identical reasoning to test-cors-fixture.php
 * (Task 4.2) — a route registered from inside a PHPUnit test method only
 * lives in THAT PHP process's memory; a real curl round-trip is served by a
 * fresh PHP-FPM process that never saw an in-test registration. Registering
 * here, from an mu-plugin that loads on every bootstrap (including the
 * loopback/live process), is the only way real HTTP can reach a fixture
 * route at all.
 *
 * Namespace `ntdst-test/v1` is DISTINCT from Task 4.2's `ntdst-cors-test/v1`
 * (still installed as web/app/mu-plugins/test-cors-fixture.php) so both
 * fixtures can coexist without route collision — this file additionally
 * covers body/depth caps (Task 4.2's fixture does not) and the front-end
 * template-route exit path, neither of which the 4.2 fixture exposes.
 *
 * Inert unless ALL of these hold (mirrors test-login-helper.php /
 * test-cors-fixture.php's gate):
 *  1. WP_ENV is not 'production'
 *  2. a test environment is detected (CODECEPTION_TEST or a DDEV project
 *     whose name starts with "stride" — this worktree's isolated DDEV
 *     instance is named "stride-reshape", not "stride")
 *
 * Installed ONLY for the duration of this task's HTTP drive
 * (`cp tests/Support/ntdst-rest-acceptance-fixture.php web/app/mu-plugins/`)
 * then removed — NOT committed under web/app/mu-plugins/.
 *
 * @package Stride\Test
 */

if (!defined('ABSPATH')) {
    exit;
}

// Hard production gate — regardless of any other signal.
if (defined('WP_ENV') && WP_ENV === 'production') {
    return;
}

// Only in a recognised test environment.
$isTestEnv = (
    getenv('CODECEPTION_TEST') === 'true'
    || defined('CODECEPTION_TEST')
    || str_starts_with((string) getenv('DDEV_PROJECT'), 'stride')
);
if (!$isTestEnv) {
    return;
}

add_action('init', function (): void {
    $policy = new NTDST_Cors_Policy(['origins' => ['https://allowed.test']]);

    // Flows 1 + 2 + boundary edges: echo route with CorsPolicy + body/depth
    // caps. Handler returns the decoded body wrapped in the success envelope
    // (via the registrar's array-normalization, D6) so a 200 response proves
    // both dispatch AND the {success:true,data:…} envelope shape in one call.
    // An empty body must reach handler-defined validation (not a 500) —
    // WP_REST_Request::get_json_params() returns null for an empty body, so
    // the handler explicitly checks for that and returns a 400 WP_Error
    // rather than letting a null propagate into the array-wrap branch.
    ntdst_router()->rest('ntdst-test/v1')->post(
        '/echo',
        static function (WP_REST_Request $request): array|WP_Error {
            $params = $request->get_json_params();

            if ($params === null) {
                return new WP_Error('empty_body', 'Request body must be valid, non-empty JSON.', ['status' => 400]);
            }

            return $params;
        },
        [
            'permission' => '__return_true',
            'cors' => $policy,
            'max_body_bytes' => 1024,
            'max_json_depth' => 5,
        ],
    );
}, 1);

// Deferred Task-4.1 Router exit-path assertion: a front-end PATTERN route
// (Router::register()/get(), matched via handleTemplateInclude() on
// template_include) whose callback returns an NTDST_Response with a
// template SET. Production contract (Router.php resolveRouteResult()):
// render() -> include $file -> exit — un-assertable in-process (exit kills
// the PHPUnit process), assertable here because curl observes the process
// from OUTSIDE.
//
// The fixture supplies its own template directory via addPath() (an
// instance-private path — never pollutes the shared static template
// cache other Response instances rely on) so it needs no theme file.
//
// NOTE on the path: this file is COPIED alone into web/app/mu-plugins/ for
// the run (see class docblock), so __DIR__ at that location would NOT be
// tests/Support/ — it would resolve inside web/app/mu-plugins/ instead,
// where the sibling router-exit-fixture-templates/ directory does not
// exist. ABSPATH (Bedrock: web/wp/) is two levels below the project root
// (web/wp/ -> web/ -> project root), which is where tests/Support/ lives
// regardless of where this fixture file itself was copied — so the
// template directory is located from ABSPATH, not __DIR__.
add_action('init', function (): void {
    ntdst_router()->get('/ntdst-test-router-exit', function (): NTDST_Response {
        $projectRoot = dirname(ABSPATH, 2);
        $fixtureTemplateDir = $projectRoot . '/tests/Support/router-exit-fixture-templates';

        return ntdst_response()
            ->addPath($fixtureTemplateDir)
            ->template('exit-fixture');
    });
}, 1);
