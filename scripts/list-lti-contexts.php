<?php

/**
 * List LTI contexts with their URLs
 */

global $wpdb;
$results = $wpdb->get_results("SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE '_netdust_lti_context_%' ORDER BY umeta_id DESC LIMIT 10");

if (empty($results)) {
    echo "No LTI contexts found.\n";
    exit;
}

foreach ($results as $row) {
    echo "User {$row->user_id} - {$row->meta_key}:\n";
    $ctx = maybe_unserialize($row->meta_value);
    echo "  Platform ID: " . ($ctx['platform_id'] ?? 'N/A') . "\n";
    echo "  Scores URL: " . ($ctx['scores_url'] ?? 'N/A') . "\n";
    echo "  Line Item URL: " . ($ctx['line_item_url'] ?? 'N/A') . "\n";
    echo "  LTI User ID: " . ($ctx['lti_user_id'] ?? 'N/A') . "\n\n";
}
