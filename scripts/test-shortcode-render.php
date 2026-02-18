<?php
/**
 * Test Shortcode Rendering
 *
 * Run: ddev exec wp eval-file scripts/test-shortcode-render.php
 */

use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\User\DashboardShortcode;

echo "=== Testing Shortcode Rendering ===\n\n";

// Get test user
$user = get_user_by('email', 'test@stride.test');
if (!$user) {
    echo "Test user not found. Run test-enrollment-flow.php first.\n";
    exit(1);
}

// Simulate logged-in user
wp_set_current_user($user->ID);
echo "Logged in as user {$user->ID}\n\n";

// Check if DashboardShortcode exists in the new module structure
if (class_exists(DashboardShortcode::class)) {
    echo "Found: Stride\\Modules\\User\\DashboardShortcode\n";
    $shortcode = ntdst_get(DashboardShortcode::class);

    if (method_exists($shortcode, 'renderMyCourses')) {
        echo "\n=== Shortcode Output (My Courses) ===\n";
        $output = $shortcode->renderMyCourses([]);
        echo "Output length: " . strlen($output) . " characters\n";
        echo "Stripped: " . substr(strip_tags($output), 0, 500) . "\n";
    } else {
        echo "Method renderMyCourses not found\n";
    }
} else {
    echo "DashboardShortcode class not found in Stride\\Modules\\User\n";
}

// Also test via do_shortcode
echo "\n=== Testing via do_shortcode ===\n";

// Check if shortcode is registered
global $shortcode_tags;
$strideShortcodes = array_filter(array_keys($shortcode_tags), function ($tag) {
    return strpos($tag, 'stride_') === 0;
});

if (empty($strideShortcodes)) {
    echo "No stride_ shortcodes registered.\n";
} else {
    echo "Registered stride shortcodes:\n";
    foreach ($strideShortcodes as $shortcode) {
        echo "  - [{$shortcode}]\n";
    }
}

// Try the dashboard shortcode if registered
if (isset($shortcode_tags['stride_dashboard'])) {
    echo "\n=== [stride_dashboard] Output ===\n";
    $output = do_shortcode('[stride_dashboard]');
    if (empty($output)) {
        echo "Empty output (template may not exist)\n";
    } else {
        echo "Output length: " . strlen($output) . " characters\n";
        $stripped = trim(strip_tags($output));
        echo "Preview: " . substr($stripped, 0, 300) . "...\n";
    }
}

// Check user's enrollments directly
echo "\n=== User Enrollments ===\n";
$enrollmentService = ntdst_get(EnrollmentService::class);
$enrollments = $enrollmentService->getUserEnrollments($user->ID);
echo "User has " . count($enrollments) . " enrollment(s)\n";

foreach ($enrollments as $enrollment) {
    $editionTitle = get_the_title($enrollment->edition_id);
    echo "  - Edition {$enrollment->edition_id}: {$editionTitle} (status: {$enrollment->status})\n";
}

echo "\n=== Test Complete ===\n";
