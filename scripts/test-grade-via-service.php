<?php

/**
 * Test grade passback using GradePassbackService
 */

use NetdustLTI\ToolProvider\Services\GradePassbackService;

echo "=== Testing GradePassbackService ===\n\n";

// Get the first user that has LTI context
global $wpdb;
$meta_key = $wpdb->get_var("SELECT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '_netdust_lti_context_%' LIMIT 1");

if (!$meta_key) {
    echo "No LTI context found in any user. Make sure you've launched a course via LTI first.\n";
    exit(1);
}

// Extract course ID from meta key
preg_match('/_netdust_lti_context_(\d+)/', $meta_key, $matches);
$courseId = (int) $matches[1];

// Get user ID
$userId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1",
    $meta_key,
));

echo "Found LTI context:\n";
echo "- User ID: {$userId}\n";
echo "- Course ID: {$courseId}\n";

// Show the context data
$context = get_user_meta($userId, $meta_key, true);
echo "- Platform ID: " . ($context['platform_id'] ?? 'N/A') . "\n";
echo "- LTI User ID: " . ($context['lti_user_id'] ?? 'N/A') . "\n";
echo "- Line Item URL: " . ($context['line_item_url'] ?? 'N/A') . "\n";
echo "- Scores URL: " . ($context['scores_url'] ?? 'N/A') . "\n\n";

echo "Testing GradePassbackService->postCompletion()...\n\n";

$service = new GradePassbackService();
$result = $service->postCompletion($userId, $courseId);

if (is_wp_error($result)) {
    echo "FAILED: " . $result->get_error_message() . "\n";
    echo "Error code: " . $result->get_error_code() . "\n";
} else {
    echo "SUCCESS! Grade posted successfully.\n";
}

echo "\nDone.\n";
