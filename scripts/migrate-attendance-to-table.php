<?php
/**
 * Migration Script: Attendance Postmeta to Table
 *
 * Migrates attendance data from session postmeta (JSON array of user IDs)
 * to the dedicated wp_vad_attendance table for better concurrent access
 * and audit trails.
 *
 * Usage:
 *   ddev exec wp eval-file scripts/migrate-attendance-to-table.php
 *   ddev exec wp eval-file scripts/migrate-attendance-to-table.php --dry-run
 *
 * @package Stride
 */

defined('ABSPATH') || exit;

use ntdst\Stride\core\AttendanceRepository;
use ntdst\Stride\core\SessionService;
use ntdst\Stride\FieldRegistry;

class AttendanceMigration
{
    private AttendanceRepository $attendanceRepo;
    private bool $dryRun = false;
    private int $migratedCount = 0;
    private int $skippedCount = 0;
    private int $errorCount = 0;
    private array $errors = [];

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
        $this->attendanceRepo = new AttendanceRepository();
    }

    /**
     * Run the migration
     */
    public function run(): void
    {
        echo "\n";
        echo "========================================\n";
        echo "  Attendance Migration: Postmeta → Table\n";
        echo "========================================\n";
        echo $this->dryRun ? "  MODE: DRY RUN (no changes)\n" : "  MODE: LIVE\n";
        echo "\n";

        // Ensure table exists
        if (!$this->attendanceRepo->tableExists()) {
            echo "[INFO] Creating attendance table...\n";
            if (!$this->dryRun) {
                $this->attendanceRepo->createTable();
            }
        }

        // Get all sessions with attendance data
        $sessions = $this->getSessionsWithAttendance();
        $totalSessions = count($sessions);

        echo "[INFO] Found {$totalSessions} sessions with attendance data\n\n";

        if ($totalSessions === 0) {
            echo "[OK] No sessions to migrate.\n";
            return;
        }

        foreach ($sessions as $index => $session) {
            $num = $index + 1;
            $this->migrateSession($session, $num, $totalSessions);
        }

        $this->printSummary();
    }

    /**
     * Parse attendees from postmeta (handles both JSON and serialized PHP)
     *
     * @param string $data Raw postmeta value
     * @return array|null Array of user IDs or null if invalid
     */
    private function parseAttendees(string $data): ?array
    {
        // Try JSON first
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try PHP serialized
        $unserialized = @unserialize($data);
        if (is_array($unserialized)) {
            return $unserialized;
        }

        return null;
    }

    /**
     * Get all sessions that have attendance postmeta
     */
    private function getSessionsWithAttendance(): array
    {
        global $wpdb;

        $metaKey = FieldRegistry::SESSION_ATTENDEES;

        // Get sessions with non-empty attendees
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID as session_id, p.post_parent as edition_id, pm.meta_value as attendees
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND pm.meta_key = %s
               AND pm.meta_value IS NOT NULL
               AND pm.meta_value != ''
               AND pm.meta_value != '[]'
             ORDER BY p.ID ASC",
            SessionService::POST_TYPE,
            $metaKey
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Migrate a single session's attendance data
     */
    private function migrateSession(array $session, int $num, int $total): void
    {
        $sessionId = (int) $session['session_id'];
        $editionId = (int) $session['edition_id'];
        $attendeesJson = $session['attendees'];

        echo "[{$num}/{$total}] Session #{$sessionId} (Edition #{$editionId})... ";

        // Parse attendees - could be JSON or serialized PHP
        $attendees = $this->parseAttendees($attendeesJson);
        if (!is_array($attendees)) {
            echo "SKIP (invalid data format)\n";
            $this->skippedCount++;
            return;
        }

        // Filter to valid user IDs
        $attendees = array_filter(array_map('absint', $attendees));
        if (empty($attendees)) {
            echo "SKIP (no valid user IDs)\n";
            $this->skippedCount++;
            return;
        }

        $count = count($attendees);

        if ($this->dryRun) {
            echo "WOULD MIGRATE {$count} attendees\n";
            $this->migratedCount += $count;
            return;
        }

        // Migrate each attendee
        $migrated = 0;
        $skipped = 0;

        foreach ($attendees as $userId) {
            $result = $this->migrateAttendee($sessionId, $editionId, $userId);
            if ($result === true) {
                $migrated++;
            } elseif ($result === 'exists') {
                $skipped++;
            } else {
                $this->errors[] = "Session {$sessionId}, User {$userId}: {$result}";
                $this->errorCount++;
            }
        }

        $this->migratedCount += $migrated;
        $this->skippedCount += $skipped;

        echo "MIGRATED {$migrated}";
        if ($skipped > 0) {
            echo ", SKIPPED {$skipped} (already exist)";
        }
        echo "\n";
    }

    /**
     * Migrate a single attendance record
     *
     * @return true|string True on success, 'exists' if already exists, error message on failure
     */
    private function migrateAttendee(int $sessionId, int $editionId, int $userId): true|string
    {
        global $wpdb;

        $table = $this->attendanceRepo->getTableName();

        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE session_id = %d AND user_id = %d",
            $sessionId,
            $userId
        ));

        if ($exists) {
            return 'exists';
        }

        // Insert new record
        $result = $wpdb->insert($table, [
            'edition_id' => $editionId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'status' => AttendanceRepository::STATUS_PRESENT,
            'marked_by' => null, // Legacy data - no audit info
            'marked_at' => current_time('mysql'),
        ], ['%d', '%d', '%d', '%s', '%d', '%s']);

        if ($result === false) {
            return $wpdb->last_error ?: 'Unknown database error';
        }

        return true;
    }

    /**
     * Print migration summary
     */
    private function printSummary(): void
    {
        echo "\n";
        echo "========================================\n";
        echo "  Migration Summary\n";
        echo "========================================\n";
        echo "  Migrated:  {$this->migratedCount}\n";
        echo "  Skipped:   {$this->skippedCount}\n";
        echo "  Errors:    {$this->errorCount}\n";
        echo "========================================\n";

        if (!empty($this->errors)) {
            echo "\nErrors:\n";
            foreach ($this->errors as $error) {
                echo "  - {$error}\n";
            }
        }

        if ($this->dryRun) {
            echo "\n[NOTE] This was a dry run. Run without --dry-run to apply changes.\n";
        } else {
            echo "\n[OK] Migration complete.\n";
            if ($this->migratedCount > 0) {
                echo "[INFO] You can now verify the data in wp_vad_attendance table.\n";
                echo "[INFO] Once verified, you may optionally clean up the postmeta:\n";
                echo "       DELETE pm FROM wp_postmeta pm\n";
                echo "       INNER JOIN wp_posts p ON p.ID = pm.post_id\n";
                echo "       WHERE p.post_type = 'vad_session' AND pm.meta_key = 'attendees';\n";
            }
        }
    }
}

// Detect dry-run from environment variable
// Usage: ddev exec bash -c 'DRY_RUN=1 wp eval-file scripts/migrate-attendance-to-table.php'
$dryRun = !empty($_ENV['DRY_RUN']) || !empty(getenv('DRY_RUN'));

// Run migration
$migration = new AttendanceMigration($dryRun);
$migration->run();
