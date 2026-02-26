<?php
declare(strict_types=1);

namespace NetdustLTI\Database;

final class Migrations
{
    private const VERSION = '1.0.0';
    private const OPTION_KEY = 'netdust_lti_db_version';

    public static function run(): void
    {
        $currentVersion = get_option(self::OPTION_KEY, '0.0.0');

        if (version_compare($currentVersion, self::VERSION, '<')) {
            self::createTables();
            update_option(self::OPTION_KEY, self::VERSION);
        }
    }

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'netdust_lti_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Platforms table
        $sql = "CREATE TABLE {$prefix}platforms (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            platform_id VARCHAR(255) NOT NULL,
            client_id VARCHAR(255) NOT NULL,
            deployment_id VARCHAR(255) DEFAULT NULL,
            auth_endpoint VARCHAR(512) NOT NULL,
            token_endpoint VARCHAR(512) NOT NULL,
            jwks_endpoint VARCHAR(512) NOT NULL,
            enabled TINYINT(1) DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY platform_client (platform_id, client_id)
        ) {$charset};";
        dbDelta($sql);

        // Contexts table
        $sql = "CREATE TABLE {$prefix}contexts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform_id BIGINT UNSIGNED NOT NULL,
            lti_context_id VARCHAR(255) NOT NULL,
            ld_course_id BIGINT UNSIGNED NOT NULL,
            resource_link_id VARCHAR(255) DEFAULT NULL,
            line_item_url VARCHAR(512) DEFAULT NULL,
            settings JSON DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY context_resource (platform_id, lti_context_id, resource_link_id),
            KEY ld_course (ld_course_id)
        ) {$charset};";
        dbDelta($sql);

        // Nonces table
        $sql = "CREATE TABLE {$prefix}nonces (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform_id BIGINT UNSIGNED NOT NULL,
            nonce VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            UNIQUE KEY platform_nonce (platform_id, nonce),
            KEY expires (expires_at)
        ) {$charset};";
        dbDelta($sql);

        // Access tokens table (for AGS)
        $sql = "CREATE TABLE {$prefix}access_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform_id BIGINT UNSIGNED NOT NULL,
            token TEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            scopes TEXT,
            created_at DATETIME NOT NULL,
            UNIQUE KEY platform_token (platform_id)
        ) {$charset};";
        dbDelta($sql);
    }

    public static function dropTables(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'netdust_lti_';

        $wpdb->query("DROP TABLE IF EXISTS {$prefix}access_tokens");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}nonces");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}contexts");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}platforms");

        delete_option(self::OPTION_KEY);
    }

    /**
     * Drop old platforms and contexts tables after migration to CPTs.
     *
     * Only drops platforms and contexts - keeps nonces and access_tokens
     * tables for performance (high-volume, short-lived data).
     *
     * Run after verifying data integrity post-migration:
     * ddev exec wp eval "\\NetdustLTI\\Database\\Migrations::dropOldTables();"
     */
    public static function dropOldTables(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'netdust_lti_';

        // Only drop platforms and contexts - keep nonces and tokens
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}contexts");
        $wpdb->query("DROP TABLE IF EXISTS {$prefix}platforms");

        delete_option('netdust_lti_migration_map');

        if (class_exists('WP_CLI')) {
            \WP_CLI::success('Dropped old platforms and contexts tables.');
        }
    }
}
