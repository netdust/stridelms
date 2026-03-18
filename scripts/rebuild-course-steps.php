<?php
/**
 * Rebuild LearnDash course steps for SCORM test course
 */

$course_id = 4910;
$lessons = [4911, 4912, 4913, 4914];

// Set up steps array in LearnDash format
$steps = [
    'steps' => [
        'h' => [
            'sfwd-lessons' => [],
            'sfwd-quiz' => []
        ]
    ],
    'course_id' => $course_id,
    'version' => defined('LEARNDASH_VERSION') ? LEARNDASH_VERSION : '5.0.1.1',
    'empty' => false,
    'course_builder_enabled' => true,
    'course_shared_steps_enabled' => true,
    'steps_count' => count($lessons)
];

foreach ($lessons as $lesson_id) {
    $steps['steps']['h']['sfwd-lessons'][$lesson_id] = [
        'sfwd-topic' => [],
        'sfwd-quiz' => []
    ];
}

// Clear existing and save new steps
delete_post_meta($course_id, 'ld_course_steps');
update_post_meta($course_id, 'ld_course_steps', $steps);
update_post_meta($course_id, '_ld_course_steps_count', count($lessons));

// Mark course as dirty so LD recalculates
if (function_exists('learndash_course_set_steps_dirty')) {
    learndash_course_set_steps_dirty($course_id);
}

// Clear any transients
delete_transient('learndash_course_' . $course_id . '_steps');
wp_cache_flush();

echo "Course steps rebuilt for course {$course_id} with " . count($lessons) . " lessons\n";

// Verify
$saved_steps = get_post_meta($course_id, 'ld_course_steps', true);
if (!empty($saved_steps['steps']['h']['sfwd-lessons'])) {
    echo "Lessons in course: " . implode(', ', array_keys($saved_steps['steps']['h']['sfwd-lessons'])) . "\n";
} else {
    echo "WARNING: No lessons found in saved steps!\n";
}
