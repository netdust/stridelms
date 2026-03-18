<?php
/**
 * Test Platform Configuration
 * Usage: ddev exec wp eval-file scripts/test-platform-config.php
 */

// Get the platform ID from the LTI context
global $wpdb;

$results = $wpdb->get_results("
    SELECT user_id, meta_key, meta_value
    FROM {$wpdb->usermeta}
    WHERE meta_key LIKE '_netdust_lti_context_%'
    AND meta_value LIKE '%vad-vormingen%'
    LIMIT 1
");

if (empty($results)) {
    echo "No LTI context with vad-vormingen found\n";
    exit(1);
}

$context = maybe_unserialize($results[0]->meta_value);
$platformId = $context['platform_id'];

echo "=== Platform Configuration ===\n";
echo "Platform ID: {$platformId}\n\n";

// Load platform using ceLTIc
$dataConnector = new \NetdustLTI\ToolProvider\WPDataConnector();
$platform = \ceLTIc\LTI\Platform::fromRecordId($platformId, $dataConnector);

if (!$platform) {
    echo "Platform not found!\n";
    exit(1);
}

echo "Platform ID (LTI): " . $platform->platformId . "\n";
echo "Client ID: " . $platform->clientId . "\n";
echo "Deployment ID: " . $platform->deploymentId . "\n";
echo "Auth Login URL: " . $platform->authorizationServerUrl . "\n";
echo "Token Endpoint: " . $platform->accessTokenUrl . "\n";
echo "JWKS URL: " . $platform->jku . "\n";
echo "Public Key configured: " . ($platform->rsaKey ? 'YES' : 'NO') . "\n\n";

// Check if token endpoint is configured
if (empty($platform->accessTokenUrl)) {
    echo "WARNING: Token endpoint not configured! AGS will fail.\n";
    echo "\nThe platform needs a token endpoint URL for OAuth2 client credentials grant.\n";
    echo "This should be set on vad-vormingen in the LTI tool configuration.\n";
} else {
    echo "Token endpoint configured. Attempting to get access token...\n\n";

    // Try to get an access token
    try {
        $service = new \ceLTIc\LTI\Service\Score($platform, $context['line_item_url']);
        $httpMessage = $service->getHttpMessage();

        echo "Score service created.\n";
        echo "Endpoint: " . $context['line_item_url'] . "\n";

        // Check if platform has stored access token
        $accessToken = new \ceLTIc\LTI\AccessToken($platform, ['https://purl.imsglobal.org/spec/lti-ags/scope/score']);
        $tokenLoaded = $dataConnector->loadAccessToken($accessToken);
        echo "Existing access token: " . ($tokenLoaded ? 'YES (expires: ' . date('c', $accessToken->expires) . ')' : 'NO') . "\n";

    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
