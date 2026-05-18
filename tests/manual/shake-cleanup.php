<?php
/**
 * Cleanup helper — wipes all shake-out test data between runs.
 */
require __DIR__ . '/shake-helpers.php';
global $wpdb;

// Wipe all test rows by test-user-id range
$wpdb->query("DELETE FROM stride_vad_registrations WHERE user_id BETWEEN 7781 AND 7790");
// Wipe filler rows from B5
$wpdb->query("DELETE FROM stride_vad_registrations WHERE user_id BETWEEN 7100 AND 7199");
// Wipe smoke-test anonymous rows
$wpdb->query("DELETE FROM stride_vad_registrations WHERE enrollment_data LIKE '%smoke.test%' OR enrollment_data LIKE '%shake.test%' OR enrollment_data LIKE '%landing.test%' OR enrollment_data LIKE '%flow.test%'");
// Wipe test attendance + audit rows for our test users
$wpdb->query("DELETE FROM stride_vad_attendance WHERE user_id BETWEEN 7781 AND 7790 OR user_id BETWEEN 7100 AND 7199");
$wpdb->query("DELETE FROM stride_audit_log WHERE actor_id BETWEEN 7781 AND 7790");

// Wipe test quotes
$wpdb->query("DELETE pm FROM stride_postmeta pm INNER JOIN stride_posts p ON p.ID = pm.post_id WHERE p.post_type = 'vad_quote' AND p.post_author BETWEEN 7781 AND 7790");
$wpdb->query("DELETE FROM stride_posts WHERE post_type = 'vad_quote' AND post_author BETWEEN 7781 AND 7790");

// Reset editions
shake_reset_editions();

echo "cleanup done\n";
echo "rows on test editions:\n";
foreach ([13222, 13224, 13230, 13234, 13240, 13265, 13311] as $id) {
    $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM stride_vad_registrations WHERE edition_id = %d", $id));
    echo "  ed=$id count=$count\n";
}
