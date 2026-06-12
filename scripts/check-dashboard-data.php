<?php
// Check all available data
global $wpdb;
$table = $wpdb->prefix . 'vad_registrations';
$total = $wpdb->get_var("SELECT COUNT(*) FROM $table");
echo "Total registrations: $total\n";

$editions = get_posts(['post_type' => 'vad_edition', 'posts_per_page' => 5, 'post_status' => 'publish']);
echo "Published editions: " . count($editions) . "\n";
foreach ($editions as $e) {
    $courseId = get_post_meta($e->ID, '_vad_course_id', true);
    $thumb = has_post_thumbnail($courseId ?: $e->ID) ? 'YES' : 'no';
    echo "  ID:{$e->ID} '{$e->post_title}' course:{$courseId} thumb:{$thumb}\n";
}

// Check admin user
$admin = get_user_by('login', 'admin');
$adminRegs = $wpdb->get_results($wpdb->prepare(
    "SELECT id, edition_id, status FROM $table WHERE user_id = %d", $admin->ID
));
echo "\nAdmin (ID:{$admin->ID}) registrations: " . count($adminRegs) . "\n";
foreach ($adminRegs as $r) {
    echo "  Edition:{$r->edition_id} Status:{$r->status}\n";
}

// Admin LD courses
if (function_exists('learndash_user_get_enrolled_courses')) {
    $courses = learndash_user_get_enrolled_courses($admin->ID);
    echo "Admin LD Courses: " . count($courses) . "\n";
    foreach ($courses as $c) {
        $thumb = has_post_thumbnail($c) ? 'YES' : 'no';
        echo "  ID:{$c} '{$c}' " . get_the_title($c) . " thumb:{$thumb}\n";
    }
}
