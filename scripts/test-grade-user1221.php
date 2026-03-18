<?php
/**
 * Test grade passback for user 1221 (has real LTI context)
 */

use NetdustLTI\ToolProvider\Services\GradePassbackService;
use NetdustLTI\ToolProvider\WPDataConnector;
use ceLTIc\LTI\Platform;

$userId = 1221;
$courseId = 4354;

echo "=== Testing GradePassbackService for User {$userId} / Course {$courseId} ===\n\n";

// Show the context data
$context = get_user_meta($userId, '_netdust_lti_context_' . $courseId, true);
echo "LTI Context:\n";
echo "- Platform ID: " . ($context['platform_id'] ?? 'N/A') . "\n";
echo "- LTI User ID: " . ($context['lti_user_id'] ?? 'N/A') . "\n";
echo "- Line Item URL: " . ($context['line_item_url'] ?? 'N/A') . "\n";
echo "- Scores URL: " . ($context['scores_url'] ?? 'N/A') . "\n\n";

// Check platform config
$platformId = $context['platform_id'] ?? null;
if ($platformId) {
    echo "Platform Config:\n";
    $dataConnector = new WPDataConnector();
    $platform = Platform::fromRecordId($platformId, $dataConnector);
    if ($platform) {
        echo "- Platform ID: " . ($platform->platformId ?? 'N/A') . "\n";
        echo "- Access Token URL: " . ($platform->accessTokenUrl ?? 'NOT SET') . "\n";
        echo "- Client ID: " . ($platform->clientId ?? 'NOT SET') . "\n";
        echo "- Authentication URL: " . ($platform->authenticationUrl ?? 'NOT SET') . "\n";
    } else {
        echo "- Platform record not found!\n";
    }
}

echo "\nTesting GradePassbackService->postCompletion()...\n\n";

$service = new GradePassbackService();
$result = $service->postCompletion($userId, $courseId);

if (is_wp_error($result)) {
    echo "FAILED: " . $result->get_error_message() . "\n";
    echo "Error code: " . $result->get_error_code() . "\n";
} else {
    echo "SUCCESS! Grade posted successfully.\n";
}

echo "\nDone.\n";
