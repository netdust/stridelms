<?php
/**
 * Test God Class Refactoring Results
 *
 * Usage: ddev exec wp eval-file scripts/test-refactoring.php
 */

if (!defined('ABSPATH')) {
    echo "Run via: ddev exec wp eval-file scripts/test-refactoring.php\n";
    exit(1);
}

$failures = [];

function test_pass(string $test): void {
    echo "✓ {$test}\n";
}

function test_fail(string $test): void {
    global $failures;
    echo "✗ FAILED: {$test}\n";
    $failures[] = $test;
}

echo "=== God Class Refactoring Tests ===\n\n";

// Test 1: All shortcodes registered
echo "--- Shortcode Registration ---\n";
$shortcodes = [
    'stride_dashboard', 'stride_my_courses', 'stride_my_profile', 'stride_my_calendar',
    'stride_course_catalog', 'stride_course_sidebar',
    'stride_my_trajectories', 'stride_trajectory', 'stride_trajectory_catalog',
    'stride_my_quotes', 'stride_quote_update',
    'stride_edition', 'stride_enrollment', 'stride_session_selection'
];

$missing = [];
foreach ($shortcodes as $sc) {
    if (!shortcode_exists($sc)) {
        $missing[] = $sc;
    }
}

if (empty($missing)) {
    test_pass("All 14 shortcodes registered");
} else {
    test_fail("Missing shortcodes: " . implode(', ', $missing));
}

// Test 2: AJAX handlers registered
echo "\n--- AJAX Handlers ---\n";
has_action('wp_ajax_stride_update_profile')
    ? test_pass("ProfileHandler AJAX registered")
    : test_fail("ProfileHandler AJAX not registered");

has_action('wp_ajax_stride_download_ical')
    ? test_pass("ICalHandler AJAX registered")
    : test_fail("ICalHandler AJAX not registered");

// Test 3: Shortcode classes exist
echo "\n--- Shortcode Classes ---\n";
$classes = [
    'stride\services\frontend\shortcodes\CourseShortcodes',
    'stride\services\frontend\shortcodes\TrajectoryShortcodes',
    'stride\services\frontend\shortcodes\QuoteShortcodes',
    'stride\services\frontend\shortcodes\EnrollmentShortcodes',
    'stride\services\frontend\shortcodes\UserDashboardShortcodes',
    'stride\services\frontend\shortcodes\ShortcodeBase',
];

foreach ($classes as $class) {
    $shortName = basename(str_replace('\\', '/', $class));
    if (class_exists($class) || trait_exists($class)) {
        test_pass("{$shortName} exists");
    } else {
        test_fail("{$shortName} not found");
    }
}

// Test 4: Handler classes exist
echo "\n--- Handler Classes ---\n";
$handlers = [
    'Stride\Handlers\ProfileHandler',
    'Stride\Handlers\ICalHandler',
];

foreach ($handlers as $class) {
    $shortName = basename(str_replace('\\', '/', $class));
    if (class_exists($class)) {
        test_pass("{$shortName} exists");
    } else {
        test_fail("{$shortName} not found");
    }
}

// Test 5: External assets exist
echo "\n--- External Assets ---\n";
$assets = [
    'mu-plugins/stride-core/assets/css/admin-dashboard.css',
    'mu-plugins/stride-core/assets/js/admin-dashboard.js',
    'mu-plugins/stride-core/templates/admin/dashboard.php',
];

foreach ($assets as $asset) {
    $path = ABSPATH . 'app/' . $asset;
    $shortName = basename($asset);
    if (file_exists($path)) {
        test_pass("{$shortName} exists");
    } else {
        test_fail("{$shortName} not found at {$path}");
    }
}

// Summary
echo "\n=== Summary ===\n";
if (empty($failures)) {
    echo "All tests passed!\n";
    exit(0);
} else {
    echo count($failures) . " test(s) failed:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}
