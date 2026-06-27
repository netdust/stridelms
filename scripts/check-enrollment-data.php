<?php

global $wpdb;
$table = $wpdb->prefix . 'vad_registrations';
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, user_id, edition_id, enrollment_data FROM {$table} WHERE edition_id = %d ORDER BY id DESC",
    5782,
));
foreach ($rows as $r) {
    $user = get_userdata((int) $r->user_id);
    $name = $user ? $user->display_name : 'unknown';
    echo "Reg #{$r->id} | user={$r->user_id} ({$name}) | enrollment_data: {$r->enrollment_data}\n";
}
