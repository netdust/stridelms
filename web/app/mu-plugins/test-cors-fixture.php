<?php

/**
 * Test CORS Fixture Route
 *
 * Registers a single fixture REST route guarded by a fresh NTDST_Cors_Policy,
 * for CorsPolicyIntegrationTest (task 4.2) to dispatch over REAL loopback HTTP
 * (wp_remote_post()/wp_remote_request()) and observe genuine, wire-level
 * response headers.
 *
 * WHY THIS FILE HAS TO EXIST: a route registered from inside a PHPUnit test
 * method only lives in THAT PHP process's memory. wp_remote_post()/
 * wp_remote_request() make a genuine separate HTTP request, served by a
 * fresh PHP-FPM process that never saw the in-test registration — so a
 * fixture route conjured inside the test always 404s under real loopback
 * HTTP, and the test would silently observe WP core's UNMODIFIED defaults
 * instead of our policy's. Registering here, from an mu-plugin that loads on
 * every bootstrap (including the loopback process's), is the only way a real
 * HTTP round-trip can reach a fixture route at all. Same reasoning as
 * test-login-helper.php's backdoor-login route in this same directory.
 *
 * Namespace `ntdst-cors-test/v1` is intentionally distinct from the
 * concurrent Task 4.1 RestRegistrarIntegrationTest's `ntdst-test/v1`, to
 * avoid any fixture collision between the two.
 *
 * Inert unless ALL of these hold (mirrors test-login-helper.php's gate):
 *  1. WP_ENV is not 'production'
 *  2. a test environment is detected (CODECEPTION_TEST or a DDEV project
 *     whose name starts with "stride" — this worktree's isolated DDEV
 *     instance is named "stride-reshape", not "stride")
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

    ntdst_router()->rest('ntdst-cors-test/v1')->post(
        '/thing',
        static fn(WP_REST_Request $request): array => ['ok' => true],
        [
            'permission' => '__return_true',
            'cors' => $policy,
        ],
    );
}, 1);
