<?php

/**
 * Ensure Stride email templates are seeded.
 *
 * Usage: ddev exec wp eval-file scripts/test-helpers/seed-mail-templates.php
 *
 * Calls StrideMailBridge::seedTemplates() and outputs result.
 */

$bridge = ntdst_get(\Stride\Modules\Mail\StrideMailBridge::class);

if (!$bridge) {
    WP_CLI::error('StrideMailBridge not found in container. Is stride-core active?');
}

$bridge->seedTemplates();
update_option('stride_mail_templates_seeded', '1');

WP_CLI::success('Mail templates seeded.');
