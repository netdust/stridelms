<?php

$templates = get_posts([
    'post_type' => 'ndmail_template',
    'posts_per_page' => 20,
    'post_status' => 'any',
]);

echo count($templates) . " templates found\n\n";

foreach ($templates as $tpl) {
    $subject = get_post_meta($tpl->ID, '_ndmail_subject', true);
    $status = get_post_meta($tpl->ID, '_ndmail_status', true);
    $trigger = get_post_meta($tpl->ID, '_ndmail_trigger', true);
    $bodyLen = strlen($tpl->post_content);
    echo "ID={$tpl->ID} slug={$tpl->post_name} status={$status}\n";
    echo "  title: {$tpl->post_title}\n";
    echo "  subject: {$subject}\n";
    echo "  trigger: {$trigger}\n";
    echo "  body length: {$bodyLen}\n";
    echo "  body preview: " . substr(strip_tags($tpl->post_content), 0, 80) . "\n\n";
}
