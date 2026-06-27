<?php

global $wpdb;

// Check LD access for Lachgas course
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT u.user_email, um.meta_value FROM {$wpdb->usermeta} um JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE um.meta_key = %s",
        'course_5852_access_from',
    ),
);
echo count($rows) . " users with LD access to Lachgas (5852)\n";
foreach ($rows as $r) {
    echo "  {$r->user_email} — access_from=" . date('Y-m-d H:i', (int) $r->meta_value) . "\n";
}

// Check Stride registrations linked to this course
$editions = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = 'vad_edition' AND post_status = 'publish'");
foreach ($editions as $ed) {
    $course_id = get_post_meta($ed->ID, '_ntdst_course_id', true);
    if ((int) $course_id === 5852) {
        echo "\nEdition {$ed->ID}: {$ed->post_title} → course 5852\n";
        $regs = $wpdb->get_results($wpdb->prepare(
            "SELECT r.user_id, r.status, u.user_email FROM {$wpdb->prefix}vad_registrations r JOIN {$wpdb->users} u ON u.ID = r.user_id WHERE r.edition_id = %d",
            $ed->ID,
        ));
        echo count($regs) . " registrations\n";
        foreach ($regs as $reg) {
            $ld_access = get_user_meta($reg->user_id, 'course_5852_access_from', true);
            echo "  {$reg->user_email} | stride_status={$reg->status} | ld_access=" . ($ld_access ? date('Y-m-d H:i', (int) $ld_access) : 'NONE') . "\n";
        }
    }
}

// LD access mode
echo "\nLD course mode: " . learndash_get_setting(5852, 'course_price_type') . "\n";
echo "sfwd_lms_has_access check:\n";
foreach ($rows as $r) {
    $uid = get_user_by('email', $r->user_email)->ID ?? 0;
    if ($uid) {
        echo "  {$r->user_email}: " . var_export(sfwd_lms_has_access(5852, $uid), true) . "\n";
    }
}
