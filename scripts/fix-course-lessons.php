<?php

/**
 * Fix LearnDash course-lesson association using LD internal functions
 */

$course_id = 4910;
$lessons = [4911, 4912, 4913, 4914];

echo "Fixing course {$course_id} with lessons: " . implode(', ', $lessons) . "\n";

// Method 1: Use ld_update_course_access if available
foreach ($lessons as $order => $lesson_id) {
    // Ensure lesson meta is correct
    update_post_meta($lesson_id, 'course_id', $course_id);
    update_post_meta($lesson_id, 'ld_course_' . $course_id, $course_id);
    update_post_meta($lesson_id, '_sfwd-lessons', ['sfwd-lessons_course' => $course_id]);

    // Set menu order
    wp_update_post([
        'ID' => $lesson_id,
        'menu_order' => $order + 1,
    ]);

    echo "  Lesson {$lesson_id}: meta updated, menu_order=" . ($order + 1) . "\n";
}

// Method 2: Rebuild course steps using LearnDash builder
if (class_exists('LearnDash_Course_Builder')) {
    echo "Using LearnDash_Course_Builder...\n";
    $builder = new LearnDash_Course_Builder();
    // Trigger rebuild
}

// Method 3: Direct update of ld_course_steps
$steps = [
    'steps' => [
        'h' => [
            'sfwd-lessons' => [],
            'sfwd-quiz' => [],
        ],
    ],
    'course_id' => $course_id,
    'version' => LEARNDASH_VERSION,
    'empty' => false,
    'course_builder_enabled' => true,
    'course_shared_steps_enabled' => true,
    'steps_count' => count($lessons),
];

foreach ($lessons as $lesson_id) {
    $steps['steps']['h']['sfwd-lessons'][$lesson_id] = [];
}

update_post_meta($course_id, 'ld_course_steps', $steps);
update_post_meta($course_id, '_ld_course_steps_count', count($lessons));

// Method 4: Use learndash_update_setting if available
if (function_exists('learndash_update_setting')) {
    foreach ($lessons as $lesson_id) {
        learndash_update_setting($lesson_id, 'course', $course_id);
    }
    echo "Used learndash_update_setting for lessons\n";
}

// Method 5: Mark course dirty and clear all caches
if (function_exists('learndash_course_set_steps_dirty')) {
    learndash_course_set_steps_dirty($course_id);
    echo "Marked course as dirty\n";
}

// Clear all caches
wp_cache_flush();
if (function_exists('learndash_flush_course_steps_cache')) {
    learndash_flush_course_steps_cache($course_id);
    echo "Flushed LD course steps cache\n";
}

// Delete all transients related to course
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%learndash%'");
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%{$course_id}%'");

echo "\nDone! Try refreshing the course page.\n";

// Test output
echo "\nTesting [ld_lesson_list]...\n";
$output = do_shortcode('[ld_lesson_list course_id="' . $course_id . '"]');
if (empty(trim($output))) {
    echo "WARNING: [ld_lesson_list] returned empty\n";
} else {
    echo "OK: [ld_lesson_list] returned content (" . strlen($output) . " bytes)\n";
}

echo "\nTesting [course_content]...\n";
$output2 = do_shortcode('[course_content course_id="' . $course_id . '"]');
if (empty(trim($output2))) {
    echo "WARNING: [course_content] returned empty\n";
} else {
    echo "OK: [course_content] returned content (" . strlen($output2) . " bytes)\n";
}
