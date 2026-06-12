<?php

/**
 * Request router for PHP's built-in web server in CI.
 *
 * The Integration workflow has no nginx, but the suite contains real-HTTP
 * seam tests (CatalogEndpointTest's guest wire path,
 * CompletionProofProtectionTest's attachment-permalink denial) that need a
 * listener on WP_HOME. This router makes `php -S` emulate the nginx
 * front-controller rule for Bedrock: existing files are served/executed
 * as-is, everything else falls through to the WordPress front controller
 * (web/index.php).
 *
 * Deliberately NOT emulated: the nginx `deny all` rule for
 * /app/uploads/stride-proofs/. The test asserting that rule skips itself
 * when the responding server is not nginx (see
 * CompletionProofProtectionTest) — it is verified in DDEV and by the
 * post-deploy curl, never faked here.
 *
 * Usage (see .github/workflows/integration.yml):
 *   PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t web scripts/ci/router.php
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2) . '/web';
$path = urldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));

// Real files (static uploads/assets, direct .php endpoints like
// /index.php?rest_route=... or /wp/wp-login.php): let php -S handle them.
if ($path !== '/' && is_file($root . $path)) {
    return false;
}

// Everything else goes through the Bedrock front controller.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $root . '/index.php';
