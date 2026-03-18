<?php
$user = get_user_by('email', 'admin@stride.local');
if ($user) {
    echo 'User ID: ' . $user->ID . PHP_EOL;
    $all = get_user_meta($user->ID);
    foreach ($all as $k => $v) {
        if (stripos($k, 'org') !== false || stripos($k, 'dept') !== false || stripos($k, 'company') !== false || stripos($k, 'afdeling') !== false || stripos($k, 'department') !== false || stripos($k, 'billing') !== false) {
            echo $k . ': ' . var_export($v[0], true) . PHP_EOL;
        }
    }

    echo PHP_EOL . '--- Field Groups ---' . PHP_EOL;
    $groups = get_option('stride_enrollment_field_groups', []);
    echo json_encode($groups, JSON_PRETTY_PRINT) . PHP_EOL;
}
