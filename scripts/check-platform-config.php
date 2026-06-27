<?php

/**
 * Check platform configuration for grade passback
 */

// Classes should be autoloaded
use NetdustLTI\ToolProvider\WPDataConnector;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\Tool;

echo "=== Platform Configuration Check ===\n\n";

$dataConnector = new WPDataConnector();
$platform = Platform::fromRecordId(5023, $dataConnector);

if (!$platform) {
    echo "Platform 5023 not found!\n";
    exit(1);
}

echo "Platform found:\n";
echo "- Record ID: " . $platform->getRecordId() . "\n";
echo "- platformId: " . ($platform->platformId ?? "NOT SET") . "\n";
echo "- clientId: " . ($platform->clientId ?? "NOT SET") . "\n";
echo "- accessTokenUrl: " . ($platform->accessTokenUrl ?? "NOT SET") . "\n";
echo "- authenticationUrl: " . ($platform->authenticationUrl ?? "NOT SET") . "\n";
echo "- rsaKey (length): " . strlen($platform->rsaKey ?? "") . "\n";
echo "- jku: " . ($platform->jku ?? "NOT SET") . "\n";
echo "- signatureMethod: " . ($platform->signatureMethod ?? "NOT SET") . "\n";
echo "- kid: " . ($platform->kid ?? "NOT SET") . "\n";

// Check tool configuration in wp_options
echo "\n=== Tool Keys in wp_options ===\n";
$privateKey = get_option('netdust_lti_tool_private_key', '');
$publicKey = get_option('netdust_lti_tool_public_key', '');
$kid = get_option('netdust_lti_tool_kid', '');

echo "- Private key (length): " . strlen($privateKey) . "\n";
echo "- Public key (length): " . strlen($publicKey) . "\n";
echo "- Kid: " . ($kid ?: 'NOT SET') . "\n";

// Check if keys look valid
if ($privateKey) {
    echo "- Private key starts with: " . substr($privateKey, 0, 30) . "...\n";
}
if ($publicKey) {
    echo "- Public key starts with: " . substr($publicKey, 0, 30) . "...\n";
}

// Check the Score service requirements
echo "\n=== Score Service Debug ===\n";
$scoreEndpoint = 'https://vad-vormingen.ddev.site/lti/platform/grades';

// Try to create a Score service and see what happens
try {
    $scoreService = new \ceLTIc\LTI\Service\Score($platform, $scoreEndpoint);

    // Build outcome
    $outcome = new \ceLTIc\LTI\Outcome(1, 1, 'Completed', 'FullyGraded');

    // Build user
    $ltiUser = new \ceLTIc\LTI\User();
    $ltiUser->ltiUserId = '1';

    echo "Score service created successfully\n";
    echo "About to submit score...\n";

    // Try to submit (this should show us the error)
    $success = $scoreService->submit($outcome, $ltiUser);

    $http = $scoreService->getHttpMessage();
    echo "Result: " . ($success ? 'SUCCESS' : 'FAILED') . "\n";
    echo "HTTP Status: " . ($http?->status ?? 'unknown') . "\n";
    echo "HTTP Error: " . ($http?->error ?? 'none') . "\n";
    echo "HTTP Response: " . substr($http?->response ?? '', 0, 500) . "\n";

    // Check if there's a request that was made
    echo "HTTP Request URL: " . ($http?->url ?? 'none') . "\n";

} catch (\Throwable $e) {
    echo "Exception: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\nDone.\n";
