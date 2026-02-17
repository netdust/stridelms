<?php
if (!defined('ABSPATH')) exit(1);

wp_set_current_user(1);
global $wpdb;

// Get first edition
$editionId = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'vad_edition' AND post_status = 'publish' LIMIT 1");
echo "Testing edition ID: $editionId\n\n";

// Test edition detail endpoint
$request = new WP_REST_Request('GET', "/stride/v1/admin/editions/{$editionId}");
$response = rest_do_request($request);
$data = $response->get_data();

echo "Status: " . $response->get_status() . "\n";
echo "Keys: " . implode(", ", array_keys((array)$data)) . "\n\n";

echo "Sessions: " . count($data['sessions'] ?? []) . "\n";
if (!empty($data['sessions'])) {
    foreach (array_slice($data['sessions'], 0, 2) as $s) {
        echo "  - {$s['date']} {$s['startTime']}-{$s['endTime']}\n";
    }
}

echo "\nRegistrations endpoint:\n";
$request = new WP_REST_Request('GET', "/stride/v1/admin/editions/{$editionId}/registrations");
$response = rest_do_request($request);
$data = $response->get_data();
echo "Status: " . $response->get_status() . "\n";

if (is_array($data) && isset($data['items'])) {
    echo "Sessions: " . count($data['sessions'] ?? []) . "\n";
    echo "Registrations: " . count($data['items']) . "\n";
    foreach (array_slice($data['items'], 0, 3) as $r) {
        $userId = $r['user']['id'] ?? '?';
        $userName = $r['user']['name'] ?? 'Unknown';
        $status = $r['status'] ?? '?';
        echo "  - User {$userId}: {$userName} ({$status})\n";
        // Show attendance if available
        if (!empty($r['attendance'])) {
            $attended = array_filter($r['attendance'], fn($s) => $s !== null);
            echo "    Attendance: " . count($attended) . " sessions marked\n";
        }
    }
} else {
    echo "Error or unexpected format: " . print_r($data, true) . "\n";
}
