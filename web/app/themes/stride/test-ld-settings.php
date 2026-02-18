<?php
/**
 * Check LearnDash course settings structure
 * Run: wp eval-file web/app/themes/stride/test-ld-settings.php
 */

// Get a sample course
$courses = get_posts(['post_type' => 'sfwd-courses', 'posts_per_page' => 1]);

if (empty($courses)) {
    WP_CLI::error('No courses found');
}

$courseId = $courses[0]->ID;
$settings = get_post_meta($courseId, '_sfwd-courses', true);

WP_CLI::log('Course: ' . $courses[0]->post_title . ' (ID: ' . $courseId . ')');
WP_CLI::log('');
WP_CLI::log('LearnDash Settings Keys:');

if (is_array($settings)) {
    foreach ($settings as $key => $value) {
        $displayValue = is_array($value) ? json_encode($value) : (string) $value;
        if (strlen($displayValue) > 50) {
            $displayValue = substr($displayValue, 0, 50) . '...';
        }
        WP_CLI::log("  $key = $displayValue");
    }
} else {
    WP_CLI::log('  (no settings found)');
}

// Check access-related settings specifically
WP_CLI::log('');
WP_CLI::log('Access-related settings:');

$accessKeys = [
    'course_price_type',      // open, closed, free, paynow, subscribe, etc.
    'course_prerequisite',    // prerequisite courses
    'course_prerequisite_enabled',
    'course_points_enabled',
    'course_points',
    'course_points_access',
    'expire_access',          // access expiration
    'expire_access_days',
    'expire_access_delete_progress',
    'course_start_date',
    'course_end_date',
    'course_seats_limit',
];

foreach ($accessKeys as $key) {
    $fullKey = 'sfwd-courses_' . $key;
    $value = $settings[$fullKey] ?? '(not set)';
    if (is_array($value)) {
        $value = json_encode($value);
    }
    WP_CLI::log("  $key = $value");
}
