<?php
declare(strict_types=1);

namespace NetdustLTI\Database;

/**
 * Database migrations for LTI plugin.
 *
 * This plugin now uses:
 * - CPTs (lti_platform, lti_tool) for platforms and tools via Data Manager
 * - WordPress transients for nonces and access tokens (auto-expiring)
 * - Post meta for contexts (stored on platform CPT)
 *
 * No custom tables are required.
 */
final class Migrations
{
    private const VERSION = '2.0.0';
    private const OPTION_KEY = 'netdust_lti_db_version';

    public static function run(): void
    {
        $currentVersion = get_option(self::OPTION_KEY, '0.0.0');

        if (version_compare($currentVersion, self::VERSION, '<')) {
            self::migrate($currentVersion);
            update_option(self::OPTION_KEY, self::VERSION);
        }
    }

    /**
     * Run migrations based on current version.
     */
    private static function migrate(string $fromVersion): void
    {
        // Migration from 1.x to 2.0: Drop old custom tables
        if (version_compare($fromVersion, '2.0.0', '<') && $fromVersion !== '0.0.0') {
            self::dropOldTables();
        }
    }

    /**
     * No tables to create - all data stored via CPTs/meta/transients.
     *
     * Kept for backwards compatibility with activation hook.
     */
    public static function createTables(): void
    {
        // No custom tables needed in v2.0
        // Platforms/Tools: CPT via Data Manager
        // Nonces/Tokens: WordPress transients
        // Contexts: Post meta on platform CPT
    }

    /**
     * Drop all old custom tables.
     */
    public static function dropTables(): void
    {
        self::dropOldTables();
        delete_option(self::OPTION_KEY);
    }

    /**
     * Drop old custom tables from v1.x.
     *
     * Run during deactivation or when migrating from v1.x to v2.0.
     */
    public static function dropOldTables(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'netdust_lti_';

        // Suppress errors in case tables don't exist
        $wpdb->suppress_errors(true);

        $wpdb->query("DROP TABLE IF EXISTS {$prefix}access_tokens");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}nonces");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}contexts");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}platforms");

        $wpdb->suppress_errors(false);

        // Clean up old migration tracking options
        delete_option('netdust_lti_migration_map');

        if (class_exists('WP_CLI')) {
            \WP_CLI::success('Dropped old LTI custom tables.');
        }
    }
}
