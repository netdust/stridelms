<?php

/**
 * Migration: normalise `_ntdst_session_slots` storage.
 *
 * Fixes B-001 + B-002:
 *   1. JSON-string-stored entries → PHP arrays (B-002)
 *   2. `pick_count` slot keys → `max_selections` (B-001)
 *
 * Idempotent: re-runs are no-ops on already-migrated rows.
 *
 * Usage:
 *   ddev exec wp eval-file scripts/migrate-session-slot-keys.php
 *   ddev exec wp eval-file scripts/migrate-session-slot-keys.php -- --dry-run
 *
 * Once all environments are migrated, this script can be deleted.
 */

global $wpdb;

// Set DRY_RUN=1 env var (or define constant) to preview without writing.
$dryRun = (bool) (getenv('DRY_RUN') ?: (defined('STRIDE_MIGRATE_DRY_RUN') && STRIDE_MIGRATE_DRY_RUN));

$rows = $wpdb->get_results(
    "SELECT post_id, meta_value
     FROM {$wpdb->postmeta}
     WHERE meta_key = '_ntdst_session_slots'
       AND meta_value <> ''
       AND meta_value <> '[]'
       AND meta_value <> 'a:0:{}'",
);

$changed = 0;
$skipped = 0;

foreach ($rows as $row) {
    $editionId = (int) $row->post_id;
    $raw = $row->meta_value;

    // 1) Decode whatever shape we have
    $slots = maybe_unserialize($raw);
    if (is_string($slots)) {
        $decoded = json_decode($slots, true);
        if (!is_array($decoded)) {
            WP_CLI::warning("Edition {$editionId}: meta_value is unparseable string, skipping");
            $skipped++;
            continue;
        }
        $slots = $decoded;
    }

    if (!is_array($slots)) {
        WP_CLI::warning("Edition {$editionId}: meta_value is not array after decode, skipping");
        $skipped++;
        continue;
    }

    // 2) Rename pick_count → max_selections per slot
    $needsWrite = false;
    foreach ($slots as &$slot) {
        if (!is_array($slot)) {
            continue;
        }
        if (isset($slot['pick_count']) && !isset($slot['max_selections'])) {
            $slot['max_selections'] = (int) $slot['pick_count'];
            unset($slot['pick_count']);
            $needsWrite = true;
        }
    }
    unset($slot);

    // 3) Also write if shape changed (JSON-string → array)
    $reSerialised = maybe_serialize($slots);
    if ($reSerialised !== $raw) {
        $needsWrite = true;
    }

    if ($needsWrite) {
        if ($dryRun) {
            WP_CLI::log("[dry-run] Would migrate edition {$editionId}");
            WP_CLI::log("  Old: " . substr($raw, 0, 120));
            WP_CLI::log("  New: " . substr($reSerialised, 0, 120));
        } else {
            update_post_meta($editionId, '_ntdst_session_slots', $slots);
            WP_CLI::log("Migrated edition {$editionId}");
        }
        $changed++;
    } else {
        $skipped++;
    }
}

WP_CLI::success(sprintf(
    '%s%d migrated, %d already-canonical/skipped.',
    $dryRun ? '[dry-run] ' : '',
    $changed,
    $skipped,
));
