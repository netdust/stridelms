<?php

// Cancel student3's registration for edition 5913 so E2E can re-enroll
global $wpdb;
$table = $wpdb->prefix . 'vad_registrations';

// Find student3
$student3 = get_user_by('email', 'seed_student3@seed.test');
if (!$student3) {
    $student3 = get_user_by('email', 'student3@seed.test');
}
if (!$student3) {
    echo "student3 not found\n";
    return;
}

echo "Student3: ID={$student3->ID} ({$student3->display_name})\n";

// Clear their org/dept meta so we can verify it gets set fresh
delete_user_meta($student3->ID, 'organisation');
delete_user_meta($student3->ID, 'department');
echo "Cleared organisation and department user meta\n";

// Cancel their registration
$result = $wpdb->update(
    $table,
    ['status' => 'cancelled', 'cancelled_at' => current_time('mysql')],
    ['user_id' => $student3->ID, 'edition_id' => 5913],
);
echo "Cancelled registration: " . ($result !== false ? "OK ({$result} row)" : "FAILED") . "\n";

// Also revoke LearnDash access so re-enrollment grants it again
$courseId = (int) get_post_meta(5913, '_ntdst_course_id', true);
if ($courseId && function_exists('ld_update_course_access')) {
    ld_update_course_access($student3->ID, $courseId, true);
    echo "Revoked LD access for course {$courseId}\n";
}
