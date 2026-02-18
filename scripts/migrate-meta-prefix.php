<?php
/**
 * Stride Meta Key Prefix Migration
 *
 * Migrates all Stride CPT meta keys to use `_ntdst_` prefix.
 * This prevents collision with other plugins and follows WordPress conventions.
 *
 * Usage:
 *   Dry run (preview):  ddev exec bash -c 'MIGRATE_MODE=dry wp eval-file scripts/migrate-meta-prefix.php'
 *   Execute migration:  ddev exec bash -c 'MIGRATE_MODE=execute wp eval-file scripts/migrate-meta-prefix.php'
 *   Rollback:           ddev exec bash -c 'MIGRATE_MODE=rollback wp eval-file scripts/migrate-meta-prefix.php'
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/migrate-meta-prefix.php\n";
    exit(1);
}

// Parse mode from environment variable (WP-CLI intercepts CLI flags)
$mode = getenv('MIGRATE_MODE') ?: 'dry';
$execute = $mode === 'execute';
$rollback = $mode === 'rollback';

// Configuration
const META_PREFIX = '_ntdst_';
const STRIDE_POST_TYPES = [
    'vad_edition',
    'vad_session',
    'vad_voucher',
    'vad_quote',
    'vad_trajectory',
];

// Meta keys to exclude (WordPress internal, already prefixed, etc.)
const EXCLUDED_PATTERNS = [
    '/^_/',           // Already prefixed with underscore
    '/^wp_/',         // WordPress internal
    '/^_wp_/',        // WordPress internal
    '/^_edit_/',      // Edit locks
    '/^_oembed_/',    // oEmbed cache
];

global $wpdb;

echo "=== Stride Meta Key Prefix Migration ===\n\n";

if ($rollback) {
    echo "Mode: ROLLBACK (removing _ntdst_ prefix)\n\n";
    runRollback($wpdb);
} elseif ($execute) {
    echo "Mode: EXECUTE (adding _ntdst_ prefix)\n\n";
    runMigration($wpdb, true);
} else {
    echo "Mode: DRY RUN (preview only)\n";
    echo "Use --execute to perform migration, --rollback to undo\n\n";
    runMigration($wpdb, false);
}

/**
 * Run the migration (add prefix)
 */
function runMigration(\wpdb $wpdb, bool $execute): void
{
    $postTypes = "'" . implode("','", STRIDE_POST_TYPES) . "'";

    // Find all meta keys for Stride CPTs that need prefixing
    $query = "
        SELECT DISTINCT pm.meta_key, COUNT(*) as count
        FROM {$wpdb->postmeta} pm
        INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type IN ({$postTypes})
        AND pm.meta_key NOT LIKE '\_%'
        AND pm.meta_key NOT LIKE 'wp_%'
        GROUP BY pm.meta_key
        ORDER BY pm.meta_key
    ";

    $metaKeys = $wpdb->get_results($query);

    if (empty($metaKeys)) {
        echo "No unprefixed meta keys found. Migration may have already been run.\n";
        return;
    }

    echo "Found " . count($metaKeys) . " unique meta keys to migrate:\n\n";

    $totalRows = 0;
    foreach ($metaKeys as $meta) {
        $newKey = META_PREFIX . $meta->meta_key;
        echo sprintf("  %-30s -> %-35s (%d rows)\n", $meta->meta_key, $newKey, $meta->count);
        $totalRows += $meta->count;
    }

    echo "\nTotal rows to update: {$totalRows}\n\n";

    if (!$execute) {
        echo "This is a DRY RUN. No changes were made.\n";
        echo "Run with --execute to perform the migration.\n";
        return;
    }

    // Start transaction
    $wpdb->query('START TRANSACTION');

    try {
        $updated = 0;

        foreach ($metaKeys as $meta) {
            $newKey = META_PREFIX . $meta->meta_key;

            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 SET pm.meta_key = %s
                 WHERE p.post_type IN ({$postTypes})
                 AND pm.meta_key = %s",
                $newKey,
                $meta->meta_key
            ));

            if ($result === false) {
                throw new Exception("Failed to update meta_key: {$meta->meta_key}");
            }

            $updated += $result;
            echo "  Updated: {$meta->meta_key} -> {$newKey} ({$result} rows)\n";
        }

        $wpdb->query('COMMIT');

        echo "\n=== Migration Complete ===\n";
        echo "Total rows updated: {$updated}\n";
        echo "\nIMPORTANT: Update your Stride CPT registrations to use 'meta_prefix' => '_ntdst_'\n";

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        echo "\n=== Migration Failed ===\n";
        echo "Error: " . $e->getMessage() . "\n";
        echo "Transaction rolled back. No changes were made.\n";
    }
}

/**
 * Run rollback (remove prefix)
 */
function runRollback(\wpdb $wpdb): void
{
    $postTypes = "'" . implode("','", STRIDE_POST_TYPES) . "'";
    $prefixLen = strlen(META_PREFIX);

    // Find all meta keys with our prefix
    $query = $wpdb->prepare(
        "SELECT DISTINCT pm.meta_key, COUNT(*) as count
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
         WHERE p.post_type IN ({$postTypes})
         AND pm.meta_key LIKE %s
         GROUP BY pm.meta_key
         ORDER BY pm.meta_key",
        META_PREFIX . '%'
    );

    $metaKeys = $wpdb->get_results($query);

    if (empty($metaKeys)) {
        echo "No prefixed meta keys found. Nothing to rollback.\n";
        return;
    }

    echo "Found " . count($metaKeys) . " prefixed meta keys to rollback:\n\n";

    $totalRows = 0;
    foreach ($metaKeys as $meta) {
        $originalKey = substr($meta->meta_key, $prefixLen);
        echo sprintf("  %-35s -> %-30s (%d rows)\n", $meta->meta_key, $originalKey, $meta->count);
        $totalRows += $meta->count;
    }

    echo "\nTotal rows to update: {$totalRows}\n";
    echo "\nAre you sure you want to rollback? This will remove all _ntdst_ prefixes.\n";
    echo "Press Ctrl+C to cancel, or wait 5 seconds to continue...\n";
    sleep(5);

    // Start transaction
    $wpdb->query('START TRANSACTION');

    try {
        $updated = 0;

        foreach ($metaKeys as $meta) {
            $originalKey = substr($meta->meta_key, $prefixLen);

            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 SET pm.meta_key = %s
                 WHERE p.post_type IN ({$postTypes})
                 AND pm.meta_key = %s",
                $originalKey,
                $meta->meta_key
            ));

            if ($result === false) {
                throw new Exception("Failed to rollback meta_key: {$meta->meta_key}");
            }

            $updated += $result;
            echo "  Rolled back: {$meta->meta_key} -> {$originalKey} ({$result} rows)\n";
        }

        $wpdb->query('COMMIT');

        echo "\n=== Rollback Complete ===\n";
        echo "Total rows updated: {$updated}\n";
        echo "\nIMPORTANT: Remove 'meta_prefix' from your Stride CPT registrations\n";

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        echo "\n=== Rollback Failed ===\n";
        echo "Error: " . $e->getMessage() . "\n";
        echo "Transaction rolled back. No changes were made.\n";
    }
}
