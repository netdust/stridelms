<?php
/**
 * Stride LMS - Development Seed Cleanup Script
 *
 * Run with: ddev exec wp eval-file scripts/unseed.php --force
 *
 * Removes all data created by the seed script:
 * - Users marked with _stride_seed_data meta
 * - Courses, lessons, groups marked with seed meta
 * - Editions and sessions marked with seed meta
 * - Registrations in wp_vad_registrations table
 * - Vouchers and quotes marked with seed meta
 * - Course enrollments for seed users
 */

if (!defined('ABSPATH')) {
    echo "This script must be run via WP-CLI: ddev exec wp eval-file scripts/unseed.php --force\n";
    exit(1);
}

// Prevent accidental runs in production
if (defined('WP_ENV') && WP_ENV === 'production') {
    echo "ERROR: Cannot run unseed script in production!\n";
    exit(1);
}

use ntdst\Stride\core\RegistrationRepository;

/**
 * Seed Data Cleaner
 */
class StrideSeedCleaner {

    private const SEED_META_KEY = '_stride_seed_data';

    private array $removed = [
        'users' => 0,
        'courses' => 0,
        'lessons' => 0,
        'editions' => 0,
        'sessions' => 0,
        'registrations' => 0,
        'groups' => 0,
        'vouchers' => 0,
        'quotes' => 0,
    ];

    private ?RegistrationRepository $regRepo = null;

    public function run(bool $force = false): void {
        echo "\n=== Stride LMS Seed Data Cleanup ===\n\n";

        // Initialize services
        if (function_exists('ntdst_get')) {
            $this->regRepo = ntdst_get(RegistrationRepository::class);
        }

        // Check for manifest
        $manifest = get_option('stride_seed_manifest');
        $timestamp = get_option('stride_seed_timestamp');

        if ($manifest) {
            echo "Found seed manifest from: {$timestamp}\n";
            echo "Manifest contains:\n";
            foreach ($manifest as $type => $ids) {
                echo "  - {$type}: " . count($ids) . " items\n";
            }
            echo "\n";
        }

        if (!$force) {
            echo "This will remove ALL seed data. Run with --force to confirm.\n";
            echo "Command: ddev exec wp eval-file scripts/unseed.php -- --force\n\n";

            // For WP-CLI, check for force flag
            if (defined('WP_CLI') && WP_CLI) {
                $force = in_array('--force', $GLOBALS['argv'] ?? [], true);
            }

            if (!$force) {
                echo "Aborting. Add --force to proceed.\n";
                return;
            }
        }

        echo "Starting cleanup...\n\n";

        $this->removeQuotes();
        $this->removeVouchers();
        $this->removeRegistrations();
        $this->removeEnrollments();
        $this->removeSessions();
        $this->removeEditions();
        $this->removeLessons();
        $this->removeCourses();
        $this->removeGroups();
        $this->removeUsers();
        $this->cleanupOptions();

        $this->printSummary();
    }

    /**
     * Remove seed quotes
     */
    private function removeQuotes(): void {
        echo "Removing quotes...\n";

        $quotes = get_posts([
            'post_type' => 'vad_quote',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        foreach ($quotes as $quoteId) {
            wp_delete_post($quoteId, true); // Force delete (bypass trash)
            $this->removed['quotes']++;
        }

        echo "  - Removed {$this->removed['quotes']} quotes\n";
    }

    /**
     * Remove seed vouchers
     */
    private function removeVouchers(): void {
        echo "Removing vouchers...\n";

        $vouchers = get_posts([
            'post_type' => 'vad_voucher',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        foreach ($vouchers as $voucherId) {
            wp_delete_post($voucherId, true);
            $this->removed['vouchers']++;
        }

        echo "  - Removed {$this->removed['vouchers']} vouchers\n";
    }

    /**
     * Remove registrations from wp_vad_registrations table
     */
    private function removeRegistrations(): void {
        echo "Removing registrations...\n";

        // Get manifest to find registration IDs
        $manifest = get_option('stride_seed_manifest', []);
        $registrationIds = $manifest['registrations'] ?? [];

        if (!empty($registrationIds) && $this->regRepo) {
            foreach ($registrationIds as $regId) {
                $this->regRepo->delete($regId);
                $this->removed['registrations']++;
            }
        }

        // Also remove any registrations linked to seed editions
        $seedEditions = get_posts([
            'post_type' => 'vad_edition',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        if (!empty($seedEditions) && $this->regRepo) {
            global $wpdb;
            $table = $wpdb->prefix . 'vad_registrations';
            $placeholders = implode(',', array_fill(0, count($seedEditions), '%d'));

            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $additionalCount = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE edition_id IN ({$placeholders})",
                $seedEditions
            ));

            if ($additionalCount > 0) {
                $this->removed['registrations'] += $additionalCount;
            }
        }

        echo "  - Removed {$this->removed['registrations']} registrations\n";
    }

    /**
     * Remove LearnDash enrollments for seed users
     */
    private function removeEnrollments(): void {
        echo "Removing LearnDash enrollments...\n";

        if (!defined('LEARNDASH_VERSION')) {
            echo "  - LearnDash not active, skipping\n";
            return;
        }

        // Get seed users
        $users = get_users([
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ID',
        ]);

        // Get seed courses
        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        $removed = 0;
        foreach ($users as $userId) {
            foreach ($courses as $courseId) {
                if (function_exists('ld_update_course_access')) {
                    ld_update_course_access($userId, $courseId, true); // true = remove access
                    $removed++;
                }
            }
        }

        echo "  - Removed {$removed} LearnDash enrollments\n";
    }

    /**
     * Remove seed sessions
     */
    private function removeSessions(): void {
        echo "Removing sessions...\n";

        $sessions = get_posts([
            'post_type' => 'vad_session',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        foreach ($sessions as $sessionId) {
            wp_delete_post($sessionId, true);
            $this->removed['sessions']++;
        }

        echo "  - Removed {$this->removed['sessions']} sessions\n";
    }

    /**
     * Remove seed editions
     */
    private function removeEditions(): void {
        echo "Removing editions...\n";

        $editions = get_posts([
            'post_type' => 'vad_edition',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        foreach ($editions as $editionId) {
            wp_delete_post($editionId, true);
            $this->removed['editions']++;
        }

        echo "  - Removed {$this->removed['editions']} editions\n";
    }

    /**
     * Remove seed lessons
     */
    private function removeLessons(): void {
        echo "Removing lessons...\n";

        $lessons = get_posts([
            'post_type' => 'sfwd-lessons',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        foreach ($lessons as $lessonId) {
            wp_delete_post($lessonId, true);
            $this->removed['lessons']++;
        }

        echo "  - Removed {$this->removed['lessons']} lessons\n";
    }

    /**
     * Remove seed courses
     */
    private function removeCourses(): void {
        echo "Removing courses...\n";

        $courses = get_posts([
            'post_type' => 'sfwd-courses',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        foreach ($courses as $courseId) {
            wp_delete_post($courseId, true);
            $this->removed['courses']++;
        }

        echo "  - Removed {$this->removed['courses']} courses\n";
    }

    /**
     * Remove seed groups
     */
    private function removeGroups(): void {
        echo "Removing groups...\n";

        $groups = get_posts([
            'post_type' => 'groups',
            'posts_per_page' => -1,
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ids',
        ]);

        foreach ($groups as $groupId) {
            wp_delete_post($groupId, true);
            $this->removed['groups']++;
        }

        echo "  - Removed {$this->removed['groups']} groups\n";
    }

    /**
     * Remove seed users
     */
    private function removeUsers(): void {
        echo "Removing users...\n";

        $users = get_users([
            'meta_key' => self::SEED_META_KEY,
            'meta_value' => '1',
            'fields' => 'ID',
        ]);

        foreach ($users as $userId) {
            // Reassign posts to admin (user 1)
            wp_delete_user($userId, 1);
            $this->removed['users']++;
        }

        echo "  - Removed {$this->removed['users']} users\n";
    }

    /**
     * Clean up seed options
     */
    private function cleanupOptions(): void {
        echo "Cleaning up options...\n";

        delete_option('stride_seed_manifest');
        delete_option('stride_seed_timestamp');

        echo "  - Removed seed manifest options\n";
    }

    /**
     * Print summary
     */
    private function printSummary(): void {
        $total = array_sum($this->removed);

        echo "\n=== Cleanup Complete ===\n\n";
        echo "Removed:\n";
        echo "  - Users: {$this->removed['users']}\n";
        echo "  - Courses: {$this->removed['courses']}\n";
        echo "  - Lessons: {$this->removed['lessons']}\n";
        echo "  - Editions: {$this->removed['editions']}\n";
        echo "  - Sessions: {$this->removed['sessions']}\n";
        echo "  - Registrations: {$this->removed['registrations']}\n";
        echo "  - Groups: {$this->removed['groups']}\n";
        echo "  - Vouchers: {$this->removed['vouchers']}\n";
        echo "  - Quotes: {$this->removed['quotes']}\n";
        echo "\nTotal items removed: {$total}\n\n";

        if ($total === 0) {
            echo "No seed data found. The database is clean.\n";
        } else {
            echo "Database cleaned of seed data.\n";
            echo "To re-seed: ddev exec wp eval-file scripts/seed.php\n";
        }
    }
}

// Check for --force flag in various ways
$force = false;

// Check WP-CLI args
if (defined('WP_CLI') && WP_CLI) {
    $force = in_array('--force', $GLOBALS['argv'] ?? [], true);
}

// Check PHP argv
if (isset($argv)) {
    $force = $force || in_array('--force', $argv, true);
}

// Check $_SERVER for CLI args
if (isset($_SERVER['argv'])) {
    $force = $force || in_array('--force', $_SERVER['argv'], true);
}

// Allow environment variable override
if (getenv('FORCE_UNSEED') === '1' || getenv('FORCE_UNSEED') === 'true') {
    $force = true;
}

// Run the cleaner
$cleaner = new StrideSeedCleaner();
$cleaner->run($force);
