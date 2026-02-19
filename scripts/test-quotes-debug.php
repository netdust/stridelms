<?php
/**
 * Debug quotes loading
 */

global $wpdb;

$userId = 1;

// Direct DB query
echo "=== Direct Database Query ===" . PHP_EOL;
$quotes = $wpdb->get_results($wpdb->prepare(
    "SELECT p.ID, p.post_title, p.post_status, pm.meta_value as user_id
     FROM {$wpdb->posts} p
     JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'user_id'
     WHERE p.post_type = 'vad_quote' AND pm.meta_value = %d
     ORDER BY p.post_date DESC",
    $userId
));

echo "Found " . count($quotes) . " quotes via direct SQL" . PHP_EOL;
foreach ($quotes as $q) {
    echo "- ID: {$q->ID}, Title: {$q->post_title}, Status: {$q->post_status}" . PHP_EOL;
}

// Try via Data Manager
echo PHP_EOL . "=== Via Data Manager ===" . PHP_EOL;
$model = ntdst_data()->get('vad_quote');
echo "Model class: " . get_class($model) . PHP_EOL;

// Check if where() works
$query = $model->where('user_id', $userId)->where('post_status', 'publish');
echo "Query built, now executing get()..." . PHP_EOL;
$results = $query->withMeta()->get();
echo "Results: " . count($results) . PHP_EOL;

// Try without post_status filter
echo PHP_EOL . "=== Via Data Manager (no post_status filter) ===" . PHP_EOL;
$results2 = $model->where('user_id', $userId)->withMeta()->get();
echo "Results: " . count($results2) . PHP_EOL;
