<?php
/**
 * Test Grade Passback Script
 *
 * Usage: ddev exec wp eval-file scripts/test-grade-passback.php
 */

// Step 1: Find users with LTI context
echo "=== Finding users with LTI context ===\n\n";

global $wpdb;

$results = $wpdb->get_results("
    SELECT user_id, meta_key, meta_value
    FROM {$wpdb->usermeta}
    WHERE meta_key LIKE '_netdust_lti_context_%'
    LIMIT 5
");

if (empty($results)) {
    echo "No LTI contexts found. You need to launch a course via LTI first.\n";
    exit(1);
}

foreach ($results as $r) {
    $context = maybe_unserialize($r->meta_value);
    $courseId = str_replace('_netdust_lti_context_', '', $r->meta_key);

    echo "User ID: {$r->user_id}\n";
    echo "Course ID: {$courseId}\n";
    echo "Context:\n";
    print_r($context);
    echo "\n---\n\n";
}

// Step 2: Test grade passback - find context with real vad-vormingen URL
$testContext = null;
foreach ($results as $r) {
    $context = maybe_unserialize($r->meta_value);
    if (!empty($context['line_item_url']) && str_contains($context['line_item_url'], 'vad-vormingen')) {
        $testContext = $r;
        break;
    }
}

if (!$testContext) {
    // Fall back to first result
    $testContext = $results[0];
}

$userId = (int) $testContext->user_id;
$courseId = (int) str_replace('_netdust_lti_context_', '', $testContext->meta_key);

echo "=== Testing Grade Passback ===\n\n";
echo "User ID: {$userId}\n";
echo "Course ID: {$courseId}\n\n";

// Get the service
if (!class_exists('NetdustLTI\ToolProvider\Services\GradePassbackService')) {
    echo "ERROR: GradePassbackService class not found. Plugin may not be loaded.\n";
    exit(1);
}

$gradeService = new \NetdustLTI\ToolProvider\Services\GradePassbackService();

echo "Posting completion score (100%)...\n";

// Show the context being used
$context = get_user_meta($userId, '_netdust_lti_context_' . $courseId, true);
echo "Line Item URL: " . ($context['line_item_url'] ?? 'not set') . "\n";
echo "Scores URL: " . ($context['scores_url'] ?? 'not set') . "\n";
echo "Platform ID: " . ($context['platform_id'] ?? 'not set') . "\n";
echo "LTI User ID: " . ($context['lti_user_id'] ?? 'not set') . "\n\n";

$result = $gradeService->postCompletion($userId, $courseId);

if (is_wp_error($result)) {
    echo "ERROR: " . $result->get_error_message() . "\n";
    echo "Error code: " . $result->get_error_code() . "\n";

    // Check debug.log for more details
    echo "\nCheck web/app/debug.log for detailed error information.\n";
    exit(1);
}

echo "SUCCESS! Grade passback completed.\n";
echo "\nCheck vad-vormingen user meta '_lti_grades' to verify receipt.\n";
