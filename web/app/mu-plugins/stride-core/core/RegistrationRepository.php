<?php

namespace ntdst\Stride\core;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Registration Repository
 *
 * Manages user-edition registrations in a custom table for performance.
 * High-volume data (~5,000 registrations/year) requires fast indexed queries.
 *
 * Table: wp_vad_registrations
 * - Stores confirmed/cancelled/waitlist/interest registrations
 * - Indexed on user_id, edition_id, status for fast lookups
 * - UNIQUE constraint on (user_id, edition_id) prevents duplicates
 *
 * @package stride\services\core
 */
class RegistrationRepository implements \NTDST_Service_Meta
{
    public const TABLE_NAME = 'vad_registrations';

    // Registration statuses
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_WAITLIST = 'waitlist';
    public const STATUS_INTEREST = 'interest';

    // Enrollment paths
    public const PATH_INDIVIDUAL = 'individual';
    public const PATH_COLLEAGUE = 'colleague';
    public const PATH_TRAJECTORY = 'trajectory';
    public const PATH_INTEREST = 'interest';

    /**
     * Request-level cache for count queries
     * @var array<string, int>
     */
    private static array $countCache = [];

    /**
     * Request-level cache for user+edition lookups
     * @var array<string, array|null>
     */
    private static array $lookupCache = [];

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Registration Repository',
            'description' => 'User-edition registration storage and queries',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 3, // Before EditionService (5)
        ];
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        // Ensure table exists on first use
        add_action('init', [$this, 'maybeCreateTable'], 1);
    }

    /**
     * Create table if it doesn't exist
     */
    public function maybeCreateTable(): void
    {
        global $wpdb;

        $table_name = $this->getTableName();

        // Check if table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name) {
            return;
        }

        self::createTable();
    }

    // ========================================
    // TABLE SETUP
    // ========================================

    /**
     * Create the registrations table
     */
    public static function createTable(): void
    {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            edition_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'confirmed',
            enrollment_path varchar(20) NOT NULL DEFAULT 'individual',
            enrolled_by bigint(20) unsigned DEFAULT NULL,
            voucher_code varchar(50) DEFAULT NULL,
            quote_id bigint(20) unsigned DEFAULT NULL,
            registered_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            cancelled_at datetime DEFAULT NULL,
            notes text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_edition (edition_id),
            KEY idx_status (status),
            KEY idx_edition_status (edition_id, status),
            KEY idx_user_status (user_id, status),
            UNIQUE KEY unique_user_edition (user_id, edition_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get full table name with prefix
     */
    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    // ========================================
    // CRUD OPERATIONS
    // ========================================

    /**
     * Create a new registration
     *
     * @param array{
     *   user_id: int,
     *   edition_id: int,
     *   status?: string,
     *   enrollment_path?: string,
     *   enrolled_by?: int|null,
     *   voucher_code?: string|null,
     *   quote_id?: int|null,
     *   notes?: string|null
     * } $data Registration data
     * @return int|WP_Error Registration ID or error
     */
    public function create(array $data): int|WP_Error
    {
        global $wpdb;

        // Validate required fields
        if (empty($data['user_id']) || empty($data['edition_id'])) {
            return new WP_Error('missing_fields', __('user_id en edition_id zijn verplicht.', 'stride'));
        }

        // Check for existing registration
        $existing = $this->findByUserAndEdition((int) $data['user_id'], (int) $data['edition_id']);
        if ($existing) {
            return new WP_Error('already_exists', __('Registratie bestaat al.', 'stride'), [
                'existing_id' => $existing['id'],
                'existing_status' => $existing['status'],
            ]);
        }

        $table_name = $this->getTableName();

        $insert_data = [
            'user_id' => absint($data['user_id']),
            'edition_id' => absint($data['edition_id']),
            'status' => sanitize_text_field($data['status'] ?? self::STATUS_CONFIRMED),
            'enrollment_path' => sanitize_text_field($data['enrollment_path'] ?? self::PATH_INDIVIDUAL),
            'enrolled_by' => !empty($data['enrolled_by']) ? absint($data['enrolled_by']) : null,
            'voucher_code' => !empty($data['voucher_code']) ? sanitize_text_field($data['voucher_code']) : null,
            'quote_id' => !empty($data['quote_id']) ? absint($data['quote_id']) : null,
            'registered_at' => current_time('mysql'),
            'notes' => !empty($data['notes']) ? sanitize_textarea_field($data['notes']) : null,
        ];

        $result = $wpdb->insert($table_name, $insert_data, [
            '%d', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s'
        ]);

        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error);
        }

        $registration_id = (int) $wpdb->insert_id;

        // Invalidate caches for this edition
        $this->invalidateCache((int) $data['edition_id'], (int) $data['user_id']);

        do_action('stride/registration/created', $registration_id, $insert_data);

        return $registration_id;
    }

    /**
     * Get a registration by ID
     *
     * @param int $registrationId Registration ID
     * @return array|null Registration data or null
     */
    public function get(int $registrationId): ?array
    {
        global $wpdb;

        $table_name = $this->getTableName();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $registrationId),
            ARRAY_A
        );

        return $row ? $this->formatRow($row) : null;
    }

    /**
     * Update a registration
     *
     * @param int $registrationId Registration ID
     * @param array $data Data to update
     * @return true|WP_Error
     */
    public function update(int $registrationId, array $data): true|WP_Error
    {
        global $wpdb;

        // Check exists
        $existing = $this->get($registrationId);
        if (!$existing) {
            return new WP_Error('not_found', __('Registratie niet gevonden.', 'stride'));
        }

        $table_name = $this->getTableName();

        // Build update data
        $update_data = [];
        $format = [];

        if (isset($data['status'])) {
            $update_data['status'] = sanitize_text_field($data['status']);
            $format[] = '%s';
        }

        if (isset($data['enrollment_path'])) {
            $update_data['enrollment_path'] = sanitize_text_field($data['enrollment_path']);
            $format[] = '%s';
        }

        if (array_key_exists('enrolled_by', $data)) {
            $update_data['enrolled_by'] = $data['enrolled_by'] ? absint($data['enrolled_by']) : null;
            $format[] = '%d';
        }

        if (array_key_exists('voucher_code', $data)) {
            $update_data['voucher_code'] = $data['voucher_code'] ? sanitize_text_field($data['voucher_code']) : null;
            $format[] = '%s';
        }

        if (array_key_exists('quote_id', $data)) {
            $update_data['quote_id'] = $data['quote_id'] ? absint($data['quote_id']) : null;
            $format[] = '%d';
        }

        if (array_key_exists('cancelled_at', $data)) {
            $update_data['cancelled_at'] = $data['cancelled_at'];
            $format[] = '%s';
        }

        if (array_key_exists('notes', $data)) {
            $update_data['notes'] = $data['notes'] ? sanitize_textarea_field($data['notes']) : null;
            $format[] = '%s';
        }

        if (empty($update_data)) {
            return true; // Nothing to update
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $registrationId],
            $format,
            ['%d']
        );

        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error);
        }

        // Invalidate caches
        $this->invalidateCache((int) $existing['edition_id'], (int) $existing['user_id']);

        do_action('stride/registration/updated', $registrationId, $update_data);

        return true;
    }

    /**
     * Delete a registration
     *
     * @param int $registrationId Registration ID
     * @return true|WP_Error
     */
    public function delete(int $registrationId): true|WP_Error
    {
        global $wpdb;

        // Get registration data for cache invalidation
        $existing = $this->get($registrationId);

        $table_name = $this->getTableName();

        $result = $wpdb->delete($table_name, ['id' => $registrationId], ['%d']);

        if ($result === false) {
            return new WP_Error('db_error', $wpdb->last_error);
        }

        // Invalidate caches
        if ($existing) {
            $this->invalidateCache((int) $existing['edition_id'], (int) $existing['user_id']);
        }

        do_action('stride/registration/deleted', $registrationId);

        return true;
    }

    // ========================================
    // QUERY METHODS
    // ========================================

    /**
     * Find registration by user and edition
     *
     * Uses request-level caching to avoid repeated queries
     *
     * @param int $userId WordPress user ID
     * @param int $editionId Edition post ID
     * @return array|null Registration data or null
     */
    public function findByUserAndEdition(int $userId, int $editionId): ?array
    {
        $cacheKey = "lookup_{$userId}_{$editionId}";

        if (array_key_exists($cacheKey, self::$lookupCache)) {
            return self::$lookupCache[$cacheKey];
        }

        global $wpdb;

        $table_name = $this->getTableName();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM $table_name WHERE user_id = %d AND edition_id = %d",
                $userId,
                $editionId
            ),
            ARRAY_A
        );

        $result = $row ? $this->formatRow($row) : null;
        self::$lookupCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Get all registrations for an edition
     *
     * @param int $editionId Edition post ID
     * @param string|null $status Filter by status (null = all)
     * @return array Array of registration data
     */
    public function getByEdition(int $editionId, ?string $status = null): array
    {
        global $wpdb;

        $table_name = $this->getTableName();

        if ($status !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE edition_id = %d AND status = %s ORDER BY registered_at ASC",
                    $editionId,
                    $status
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE edition_id = %d ORDER BY registered_at ASC",
                    $editionId
                ),
                ARRAY_A
            );
        }

        return array_map([$this, 'formatRow'], $rows ?: []);
    }

    /**
     * Get all registrations for a user
     *
     * @param int $userId WordPress user ID
     * @param string|null $status Filter by status (null = all)
     * @return array Array of registration data
     */
    public function getByUser(int $userId, ?string $status = null): array
    {
        global $wpdb;

        $table_name = $this->getTableName();

        if ($status !== null) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE user_id = %d AND status = %s ORDER BY registered_at DESC",
                    $userId,
                    $status
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name WHERE user_id = %d ORDER BY registered_at DESC",
                    $userId
                ),
                ARRAY_A
            );
        }

        return array_map([$this, 'formatRow'], $rows ?: []);
    }

    /**
     * Count registrations for an edition
     *
     * Uses request-level caching to avoid repeated queries
     *
     * @param int $editionId Edition post ID
     * @param string|null $status Filter by status (null = all)
     * @return int Count
     */
    public function countByEdition(int $editionId, ?string $status = null): int
    {
        $cacheKey = "count_{$editionId}_" . ($status ?? 'all');

        if (isset(self::$countCache[$cacheKey])) {
            return self::$countCache[$cacheKey];
        }

        global $wpdb;

        $table_name = $this->getTableName();

        if ($status !== null) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE edition_id = %d AND status = %s",
                    $editionId,
                    $status
                )
            );
        } else {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM $table_name WHERE edition_id = %d",
                    $editionId
                )
            );
        }

        self::$countCache[$cacheKey] = (int) $count;

        return self::$countCache[$cacheKey];
    }

    // ========================================
    // STATUS CHANGES
    // ========================================

    /**
     * Confirm a registration
     *
     * @param int $registrationId Registration ID
     * @return true|WP_Error
     */
    public function confirm(int $registrationId): true|WP_Error
    {
        $result = $this->update($registrationId, [
            'status' => self::STATUS_CONFIRMED,
            'cancelled_at' => null,
        ]);

        if ($result === true) {
            do_action('stride/registration/confirmed', $registrationId);
        }

        return $result;
    }

    /**
     * Cancel a registration
     *
     * @param int $registrationId Registration ID
     * @return true|WP_Error
     */
    public function cancel(int $registrationId): true|WP_Error
    {
        $result = $this->update($registrationId, [
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => current_time('mysql'),
        ]);

        if ($result === true) {
            do_action('stride/registration/cancelled', $registrationId);
        }

        return $result;
    }

    /**
     * Move a registration to waitlist
     *
     * @param int $registrationId Registration ID
     * @return true|WP_Error
     */
    public function waitlist(int $registrationId): true|WP_Error
    {
        $result = $this->update($registrationId, [
            'status' => self::STATUS_WAITLIST,
        ]);

        if ($result === true) {
            do_action('stride/registration/waitlisted', $registrationId);
        }

        return $result;
    }

    /**
     * Link a quote to a registration
     *
     * @param int $registrationId Registration ID
     * @param int $quoteId Quote post ID
     * @return true|WP_Error
     */
    public function linkQuote(int $registrationId, int $quoteId): true|WP_Error
    {
        return $this->update($registrationId, ['quote_id' => $quoteId]);
    }

    // ========================================
    // CACHE MANAGEMENT
    // ========================================

    /**
     * Invalidate caches for an edition and user
     *
     * @param int $editionId Edition post ID
     * @param int $userId WordPress user ID
     */
    private function invalidateCache(int $editionId, int $userId): void
    {
        // Clear count caches for this edition
        foreach (array_keys(self::$countCache) as $key) {
            if (str_starts_with($key, "count_{$editionId}_")) {
                unset(self::$countCache[$key]);
            }
        }

        // Clear lookup cache for this user+edition
        $lookupKey = "lookup_{$userId}_{$editionId}";
        unset(self::$lookupCache[$lookupKey]);
    }

    /**
     * Clear all caches (useful for testing)
     */
    public static function clearCache(): void
    {
        self::$countCache = [];
        self::$lookupCache = [];
    }

    // ========================================
    // FORMATTING
    // ========================================

    /**
     * Format database row to clean array
     */
    private function formatRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'edition_id' => (int) $row['edition_id'],
            'status' => $row['status'],
            'enrollment_path' => $row['enrollment_path'],
            'enrolled_by' => $row['enrolled_by'] ? (int) $row['enrolled_by'] : null,
            'voucher_code' => $row['voucher_code'],
            'quote_id' => $row['quote_id'] ? (int) $row['quote_id'] : null,
            'registered_at' => $row['registered_at'],
            'cancelled_at' => $row['cancelled_at'],
            'notes' => $row['notes'],
        ];
    }
}
