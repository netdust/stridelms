<?php

/**
 * Migrate Attendance Data to Dedicated Table
 *
 * Migrates attendance from postmeta JSON arrays to the wp_vad_attendance table.
 *
 * Usage:
 *   ddev exec wp eval-file scripts/migrate-attendance.php
 *
 * Options:
 *   --dry-run     Show what would be migrated without making changes
 *   --force       Re-migrate even if records already exist
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI\n";
    exit(1);
}

use ntdst\Stride\core\AttendanceRepository;
use ntdst\Stride\core\SessionService;
use ntdst\Stride\FieldRegistry;

// Parse arguments
$isDryRun = in_array('--dry-run', $argv ?? [], true);
$isForce = in_array('--force', $argv ?? [], true);

echo "=== Attendance Migration Script ===\n\n";

if ($isDryRun) {
    echo "[DRY RUN MODE - No changes will be made]\n\n";
}

// Initialize repository
$repo = new AttendanceRepository();

// Step 1: Ensure table exists
echo "Step 1: Creating attendance table...\n";
if (!$isDryRun) {
    $repo->createTable();
}

if ($repo->tableExists()) {
    echo "  [OK] Table exists: " . $repo->getTableName() . "\n";
} else {
    echo "  [ERROR] Could not create table\n";
    if (!$isDryRun) {
        exit(1);
    }
}

// Step 2: Count sessions with attendance data
echo "\nStep 2: Finding sessions with attendance data...\n";

global $wpdb;

$metaKey = FieldRegistry::SESSION_ATTENDEES;
$chunkSize = 100; // Process in chunks to avoid memory issues

// Get total count first
$sessionCount = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(DISTINCT p.ID)
     FROM {$wpdb->posts} p
     INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
     WHERE p.post_type = %s
     AND pm.meta_key = %s
     AND pm.meta_value IS NOT NULL
     AND pm.meta_value != ''
     AND pm.meta_value != '[]'",
    SessionService::POST_TYPE,
    $metaKey,
));

echo "  Found {$sessionCount} sessions with attendance data\n";

if ($sessionCount === 0) {
    echo "\nNo data to migrate. Done!\n";
    exit(0);
}

// Step 3: Migrate each session (chunked for memory efficiency)
echo "\nStep 3: Migrating attendance records in chunks of {$chunkSize}...\n";

$totalMigrated = 0;
$totalSkipped = 0;
$errors = [];
$offset = 0;

while ($offset < $sessionCount) {
    // Fetch chunk
    $sessions = $wpdb->get_results($wpdb->prepare(
        "SELECT p.ID as session_id, pm.meta_value as attendees
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
         WHERE p.post_type = %s
         AND pm.meta_key = %s
         AND pm.meta_value IS NOT NULL
         AND pm.meta_value != ''
         AND pm.meta_value != '[]'
         ORDER BY p.ID
         LIMIT %d OFFSET %d",
        SessionService::POST_TYPE,
        $metaKey,
        $chunkSize,
        $offset,
    ), ARRAY_A);

    if (empty($sessions)) {
        break;
    }

    $offset += count($sessions);
    echo "  Processing chunk {$offset}/{$sessionCount}...\n";

    foreach ($sessions as $session) {
        $sessionId = (int) $session['session_id'];
        $attendeesRaw = $session['attendees'];

        // Parse attendees
        $attendees = json_decode($attendeesRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $attendees = maybe_unserialize($attendeesRaw);
        }

        if (!is_array($attendees) || empty($attendees)) {
            continue;
        }

        // Get edition ID from session
        $editionId = get_post_meta($sessionId, FieldRegistry::SESSION_EDITION_ID, true);
        if (!$editionId) {
            $errors[] = "Session {$sessionId}: Missing edition_id";
            continue;
        }

        $userCount = count($attendees);
        echo "    Session {$sessionId} (Edition {$editionId}): {$userCount} attendees... ";

        if ($isDryRun) {
            echo "[DRY RUN - would migrate]\n";
            $totalMigrated += $userCount;
            continue;
        }

        // Check if already migrated (unless --force)
        if (!$isForce) {
            $existingCount = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$repo->getTableName()} WHERE session_id = %d",
                $sessionId,
            ));

            if ($existingCount > 0) {
                echo "[SKIPPED - already migrated]\n";
                $totalSkipped += $userCount;
                continue;
            }
        }

        // Migrate using batch insert
        $userStatuses = [];
        foreach ($attendees as $userId) {
            $userStatuses[(int) $userId] = AttendanceRepository::STATUS_PRESENT;
        }

        $migratedCount = $repo->batchMark($sessionId, $userStatuses, 0); // 0 = system migration

        if ($migratedCount > 0) {
            echo "[OK - {$migratedCount} records]\n";
            $totalMigrated += $migratedCount;
        } else {
            echo "[ERROR]\n";
            $errors[] = "Session {$sessionId}: Failed to migrate";
        }
    }
}

// Step 4: Summary
echo "\n=== Migration Summary ===\n";
echo "Sessions processed: {$sessionCount}\n";
echo "Records migrated: {$totalMigrated}\n";
echo "Records skipped: {$totalSkipped}\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

// Step 5: Verify
if (!$isDryRun) {
    echo "\nStep 4: Verifying migration...\n";

    $tableCount = $wpdb->get_var("SELECT COUNT(*) FROM {$repo->getTableName()}");
    echo "  Total records in attendance table: {$tableCount}\n";

    // Verify a sample
    $sample = $wpdb->get_row($wpdb->prepare(
        "SELECT session_id, user_id, status, marked_at FROM {$repo->getTableName()} ORDER BY id DESC LIMIT 1",
    ), ARRAY_A);

    if ($sample) {
        echo "  Sample record:\n";
        echo "    Session: {$sample['session_id']}\n";
        echo "    User: {$sample['user_id']}\n";
        echo "    Status: {$sample['status']}\n";
        echo "    Marked at: {$sample['marked_at']}\n";
    }
}

echo "\n=== Migration Complete ===\n";

// Note about postmeta
if (!$isDryRun && $totalMigrated > 0) {
    echo "\nNote: Original postmeta data has been preserved as backup.\n";
    echo "After verifying the migration, you can remove old postmeta with:\n";
    echo "  wp db query \"DELETE FROM {$wpdb->postmeta} WHERE meta_key = '{$metaKey}'\"\n";
}
