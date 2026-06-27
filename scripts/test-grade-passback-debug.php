<?php

/**
 * Detailed debug test for grade passback
 */

use NetdustLTI\ToolProvider\WPDataConnector;
use ceLTIc\LTI\Platform;
use ceLTIc\LTI\Tool;
use ceLTIc\LTI\Outcome;
use ceLTIc\LTI\User;
use ceLTIc\LTI\AccessToken;
use ceLTIc\LTI\Service\Score;

echo "=== Grade Passback Debug Test ===\n\n";

// Step 1: Check tool private key
echo "Step 1: Check tool private key\n";
$privateKey = get_option('netdust_lti_private_key', '');
$kid = get_option('netdust_lti_kid', '');
echo "Private key length: " . strlen($privateKey) . "\n";
echo "Kid: " . ($kid ?: 'NOT SET') . "\n";

if (empty($privateKey)) {
    echo "ERROR: No private key found!\n";
    exit(1);
}

// Step 2: Configure Tool::$defaultTool
echo "\nStep 2: Configure Tool::$defaultTool\n";
$dataConnector = new WPDataConnector();
$tool = new Tool($dataConnector);
$tool->rsaKey = $privateKey;
$tool->kid = $kid;
$tool->requiredScopes = [
    'https://purl.imsglobal.org/spec/lti-ags/scope/score',
];
Tool::$defaultTool = $tool;
echo "Tool::$defaultTool configured\n";
echo "Tool rsaKey length: " . strlen(Tool::$defaultTool->rsaKey ?? '') . "\n";

// Step 3: Load platform
echo "\nStep 3: Load platform\n";
$platform = Platform::fromRecordId(5023, $dataConnector);
if (!$platform) {
    echo "ERROR: Platform 5023 not found!\n";
    exit(1);
}
echo "Platform loaded: " . $platform->platformId . "\n";
echo "Access Token URL: " . ($platform->accessTokenUrl ?? 'NOT SET') . "\n";
echo "Client ID: " . ($platform->clientId ?? 'NOT SET') . "\n";

// Step 4: Set platform on tool
echo "\nStep 4: Set platform on tool\n";
Tool::$defaultTool->platform = $platform;
echo "Platform set on tool\n";

// Step 5: Try to get an access token
echo "\nStep 5: Try to get access token\n";
$accessToken = new AccessToken($platform);
$scope = 'https://purl.imsglobal.org/spec/lti-ags/scope/score';

echo "Requesting access token for scope: {$scope}\n";
$accessToken->get($scope);

echo "Access token result:\n";
echo "- Token: " . ($accessToken->token ? substr($accessToken->token, 0, 50) . '...' : 'NONE') . "\n";
echo "- Expires: " . ($accessToken->expires ? date('Y-m-d H:i:s', $accessToken->expires) : 'NONE') . "\n";
echo "- Scopes: " . implode(', ', $accessToken->scopes) . "\n";

if (empty($accessToken->token)) {
    echo "\nERROR: Failed to obtain access token!\n";

    // Try manual token request to see what happens
    echo "\nStep 5b: Manual token request debug\n";
    $tokenUrl = $platform->accessTokenUrl;
    echo "Token URL: {$tokenUrl}\n";

    // Check if we can reach the URL
    echo "\nTrying curl to {$tokenUrl}...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    echo "HTTP Code: {$httpCode}\n";
    echo "Error: " . ($error ?: 'none') . "\n";
    echo "Response: " . substr($response, 0, 500) . "\n";

    exit(1);
}

// Step 6: Try to submit a score
echo "\nStep 6: Submit score\n";
$scoreEndpoint = 'https://vad-vormingen.ddev.site/lti/platform/grades';
$scoreService = new Score($platform, $scoreEndpoint);

$outcome = new Outcome(1, 1, 'Completed', 'FullyGraded');

$ltiUser = new User();
$ltiUser->ltiUserId = '1';

echo "Submitting score...\n";
$success = $scoreService->submit($outcome, $ltiUser);

$http = $scoreService->getHttpMessage();
echo "Result: " . ($success ? 'SUCCESS' : 'FAILED') . "\n";
echo "HTTP Status: " . ($http?->status ?? 'unknown') . "\n";
echo "HTTP Error: " . ($http?->error ?? 'none') . "\n";
echo "HTTP URL: " . ($http?->url ?? 'none') . "\n";
echo "HTTP Response: " . substr($http?->response ?? '', 0, 500) . "\n";

echo "\nDone.\n";
