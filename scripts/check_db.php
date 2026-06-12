<?php
global $wpdb;

// Check trajectory 4398 directly in database
$result = $wpdb->get_row("SELECT ID, post_name, post_title, post_status, post_type FROM {$wpdb->posts} WHERE ID = 4398");
echo "Trajectory 4398 from database:" . PHP_EOL;
print_r($result);

// Check all trajectories
echo PHP_EOL . "All vad_trajectory posts:" . PHP_EOL;
$all = $wpdb->get_results("SELECT ID, post_name, post_title, post_status FROM {$wpdb->posts} WHERE post_type = 'vad_trajectory' AND post_status = 'publish'");
print_r($all);
