<?php
/**
 * Trigger a WordPress action with JSON context for email testing.
 *
 * Usage: ddev exec wp eval-file scripts/test-helpers/trigger-mail.php -- <action> <json_context>
 *
 * Example:
 *   ddev exec wp eval-file scripts/test-helpers/trigger-mail.php -- \
 *     stride/registration/created '{"user_id":1,"edition_id":7880,"registration_id":2090}'
 */

// WP-CLI passes extra args after -- in $args (args[0] is "--" itself)
$action  = $args[1] ?? '';
$jsonCtx = $args[2] ?? '{}';

if (empty($action)) {
    WP_CLI::error('Usage: wp eval-file trigger-mail.php -- <action> <json_context>');
}

$context = json_decode($jsonCtx, true);
if (!is_array($context)) {
    WP_CLI::error('Invalid JSON context: ' . $jsonCtx);
}

do_action($action, $context);

WP_CLI::success("Fired action: {$action}");
