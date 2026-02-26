<?php
/**
 * Migration script: Custom tables to CPTs
 *
 * Run via: ddev exec wp eval-file web/app/plugins/netdust-lti/scripts/migrate-lti-tables.php
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_CLI')) {
    exit('This script must be run via WP-CLI.');
}

global $wpdb;

$oldTable = $wpdb->prefix . 'netdust_lti_platforms';
$contextTable = $wpdb->prefix . 'netdust_lti_contexts';

// Check if old table exists
$tableExists = $wpdb->get_var(
    $wpdb->prepare("SHOW TABLES LIKE %s", $oldTable)
);

if (!$tableExists) {
    WP_CLI::log('No old platforms table found. Nothing to migrate.');
    return;
}

// Get existing platforms
$platforms = $wpdb->get_results("SELECT * FROM {$oldTable}", ARRAY_A);

if (empty($platforms)) {
    WP_CLI::log('No platforms found in old table.');
    return;
}

WP_CLI::log(sprintf('Found %d platforms to migrate.', count($platforms)));

$idMap = []; // old_id => new_post_id

foreach ($platforms as $platform) {
    // Create CPT post
    $postId = wp_insert_post([
        'post_type' => 'lti_platform',
        'post_title' => $platform['name'],
        'post_status' => 'publish',
    ]);

    if (is_wp_error($postId)) {
        WP_CLI::warning(sprintf('Failed to create platform "%s": %s',
            $platform['name'],
            $postId->get_error_message()
        ));
        continue;
    }

    // Map meta fields (with lti_ prefix)
    $metaFields = [
        'platform_id' => $platform['platform_id'],
        'client_id' => $platform['client_id'],
        'deployment_id' => $platform['deployment_id'] ?? '',
        'auth_endpoint' => $platform['auth_endpoint'],
        'token_endpoint' => $platform['token_endpoint'],
        'jwks_endpoint' => $platform['jwks_endpoint'],
        'enabled' => (bool) ($platform['enabled'] ?? true),
    ];

    foreach ($metaFields as $key => $value) {
        update_post_meta($postId, 'lti_' . $key, $value);
    }

    $idMap[$platform['id']] = $postId;
    WP_CLI::log(sprintf('Migrated platform "%s" (old ID: %d -> new ID: %d)',
        $platform['name'],
        $platform['id'],
        $postId
    ));
}

// Migrate contexts to post meta
$contextTableExists = $wpdb->get_var(
    $wpdb->prepare("SHOW TABLES LIKE %s", $contextTable)
);

if (!$contextTableExists) {
    WP_CLI::log('No contexts table found. Skipping context migration.');
} else {
    $contexts = $wpdb->get_results("SELECT * FROM {$contextTable}", ARRAY_A);
    $contextCount = 0;

    foreach ($contexts as $context) {
    $oldPlatformId = $context['platform_id'];

    if (!isset($idMap[$oldPlatformId])) {
        WP_CLI::warning(sprintf('Context references unknown platform ID %d, skipping.', $oldPlatformId));
        continue;
    }

    $newPlatformId = $idMap[$oldPlatformId];

    // Store contexts as serialized meta
    $existingContexts = get_post_meta($newPlatformId, 'lti_contexts', true) ?: [];
    $existingContexts[] = [
        'lti_context_id' => $context['lti_context_id'],
        'ld_course_id' => $context['ld_course_id'],
        'resource_link_id' => $context['resource_link_id'] ?? null,
        'line_item_url' => $context['line_item_url'] ?? null,
        'settings' => json_decode($context['settings'] ?? '{}', true),
    ];

        update_post_meta($newPlatformId, 'lti_contexts', $existingContexts);
        $contextCount++;
    }

    WP_CLI::log(sprintf('Migrated %d contexts.', $contextCount));
}

WP_CLI::success(sprintf('Migration complete. Migrated %d platforms.', count($idMap)));

// Store ID map for reference
update_option('netdust_lti_migration_map', $idMap);

WP_CLI::log('');
WP_CLI::log('IMPORTANT: Verify data integrity, then run:');
WP_CLI::log('  ddev exec wp eval "\\NetdustLTI\\Database\\Migrations::dropOldTables();"');
