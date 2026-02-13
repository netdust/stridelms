<?php

namespace ntdst\Stride\core;

/**
 * Historical Data Service
 *
 * Provides read-only access to the legacy VAD v3 database for:
 * - Past enrollments
 * - Past invoices/quotes
 * - Past certificates
 * - Past vouchers
 *
 * This service is used during the transition period to show users
 * their historical data while new data is stored in the Stride system.
 *
 * @package stride
 */
class HistoricalDataService
{
    private ?\wpdb $legacyDb = null;
    private string $prefix;
    private bool $connected = false;

    /**
     * Cache for expensive queries
     */
    private array $cache = [];

    /**
     * Cache TTL in seconds (15 minutes)
     */
    private const CACHE_TTL = 900;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Historical Data',
            'description' => 'Read-only access to legacy VAD v3 database',
            'priority' => 5, // Load early as other services may depend on it
        ];
    }

    public function __construct()
    {
        $this->prefix = $this->getEnv('LEGACY_DB_PREFIX', 'wp_');
    }

    /**
     * Get environment variable (compatible with Bedrock)
     */
    private function getEnv(string $key, mixed $default = null): mixed
    {
        // Try Bedrock's env() first
        if (function_exists('env')) {
            return env($key) ?: $default;
        }
        // Fallback to getenv
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }

    /**
     * Get the legacy database connection
     *
     * @return \wpdb|null
     */
    private function getConnection(): ?\wpdb
    {
        if ($this->legacyDb !== null) {
            return $this->connected ? $this->legacyDb : null;
        }

        $host = $this->getEnv('LEGACY_DB_HOST');
        $name = $this->getEnv('LEGACY_DB_NAME');
        $user = $this->getEnv('LEGACY_DB_USER');
        $pass = $this->getEnv('LEGACY_DB_PASSWORD');

        if (!$host || !$name) {
            ntdst_log()->debug('HistoricalDataService: Legacy DB not configured');
            $this->connected = false;
            return null;
        }

        // Test connection with mysqli first to avoid wpdb's wp_die behavior
        $mysqli = @new \mysqli($host, $user, $pass, $name);

        if ($mysqli->connect_error) {
            ntdst_log()->debug('HistoricalDataService: Connection failed - ' . $mysqli->connect_error);
            $this->connected = false;
            return null;
        }

        $mysqli->close();

        try {
            // Now safe to create wpdb instance
            $this->legacyDb = new \wpdb($user, $pass, $name, $host);
            $this->legacyDb->suppress_errors(true);
            $this->legacyDb->show_errors(false);

            $this->connected = true;
            ntdst_log()->debug('HistoricalDataService: Connected to legacy database');

        } catch (\Exception $e) {
            ntdst_log()->debug('HistoricalDataService: Exception - ' . $e->getMessage());
            $this->connected = false;
            $this->legacyDb = null;
            return null;
        }

        return $this->legacyDb;
    }

    /**
     * Check if legacy database is available
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->getConnection() !== null;
    }

    // ========================================
    // ENROLLMENTS
    // ========================================

    /**
     * Get user's historical course enrollments
     *
     * @param int $userId WordPress user ID
     * @param int $limit Max results
     * @return array
     */
    public function getUserEnrollments(int $userId, int $limit = 50): array
    {
        $cacheKey = "enrollments_{$userId}_{$limit}";
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $db = $this->getConnection();
        if (!$db) {
            return [];
        }

        // LearnDash stores enrollments in usermeta with key 'course_{course_id}_access_from'
        $results = $db->get_results($db->prepare("
            SELECT
                um.meta_key,
                um.meta_value as enrolled_date,
                p.ID as course_id,
                p.post_title as course_title
            FROM {$this->prefix}usermeta um
            JOIN {$this->prefix}posts p ON p.ID = SUBSTRING(um.meta_key, 8, LENGTH(um.meta_key) - 19)
            WHERE um.user_id = %d
            AND um.meta_key LIKE 'course_%%_access_from'
            AND p.post_type = 'sfwd-courses'
            AND p.post_status = 'publish'
            ORDER BY um.meta_value DESC
            LIMIT %d
        ", $userId, $limit), ARRAY_A);

        $enrollments = array_map(function ($row) {
            return [
                'course_id' => (int) $row['course_id'],
                'course_title' => $row['course_title'],
                'enrolled_date' => $row['enrolled_date'] ? date('Y-m-d', (int) $row['enrolled_date']) : null,
                'source' => 'legacy',
            ];
        }, $results ?: []);

        $this->setCache($cacheKey, $enrollments);
        return $enrollments;
    }

    /**
     * Check if user was enrolled in a course in the legacy system
     *
     * @param int $userId
     * @param int $courseId
     * @return bool
     */
    public function wasUserEnrolled(int $userId, int $courseId): bool
    {
        $db = $this->getConnection();
        if (!$db) {
            return false;
        }

        $result = $db->get_var($db->prepare("
            SELECT meta_value
            FROM {$this->prefix}usermeta
            WHERE user_id = %d
            AND meta_key = %s
        ", $userId, "course_{$courseId}_access_from"));

        return !empty($result);
    }

    // ========================================
    // INVOICES / QUOTES
    // ========================================

    /**
     * Get user's historical invoices
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUserInvoices(int $userId, int $limit = 50): array
    {
        $cacheKey = "invoices_{$userId}_{$limit}";
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $db = $this->getConnection();
        if (!$db) {
            return [];
        }

        // GetPaid/WPInvoicing stores invoices as 'wpi_invoice' post type
        $results = $db->get_results($db->prepare("
            SELECT
                p.ID,
                p.post_title as number,
                p.post_date as created_date,
                p.post_status as status,
                pm_total.meta_value as total,
                pm_currency.meta_value as currency
            FROM {$this->prefix}posts p
            LEFT JOIN {$this->prefix}postmeta pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_wpinv_total'
            LEFT JOIN {$this->prefix}postmeta pm_currency ON p.ID = pm_currency.post_id AND pm_currency.meta_key = '_wpinv_currency'
            WHERE p.post_type = 'wpi_invoice'
            AND p.post_author = %d
            ORDER BY p.post_date DESC
            LIMIT %d
        ", $userId, $limit), ARRAY_A);

        $invoices = array_map(function ($row) {
            return [
                'id' => (int) $row['ID'],
                'number' => $row['number'],
                'created_date' => $row['created_date'],
                'status' => $this->mapInvoiceStatus($row['status']),
                'total' => (float) ($row['total'] ?? 0),
                'currency' => $row['currency'] ?: 'EUR',
                'source' => 'legacy',
            ];
        }, $results ?: []);

        $this->setCache($cacheKey, $invoices);
        return $invoices;
    }

    /**
     * Get a specific historical invoice
     *
     * @param int $invoiceId
     * @return array|null
     */
    public function getInvoice(int $invoiceId): ?array
    {
        $db = $this->getConnection();
        if (!$db) {
            return null;
        }

        $result = $db->get_row($db->prepare("
            SELECT
                p.ID,
                p.post_title as number,
                p.post_date as created_date,
                p.post_status as status,
                p.post_author as user_id
            FROM {$this->prefix}posts p
            WHERE p.ID = %d
            AND p.post_type = 'wpi_invoice'
        ", $invoiceId), ARRAY_A);

        if (!$result) {
            return null;
        }

        // Get all meta
        $meta = $db->get_results($db->prepare("
            SELECT meta_key, meta_value
            FROM {$this->prefix}postmeta
            WHERE post_id = %d
        ", $invoiceId), ARRAY_A);

        $metaArray = [];
        foreach ($meta as $m) {
            $metaArray[$m['meta_key']] = $m['meta_value'];
        }

        return [
            'id' => (int) $result['ID'],
            'number' => $result['number'],
            'created_date' => $result['created_date'],
            'status' => $this->mapInvoiceStatus($result['status']),
            'user_id' => (int) $result['user_id'],
            'total' => (float) ($metaArray['_wpinv_total'] ?? 0),
            'subtotal' => (float) ($metaArray['_wpinv_subtotal'] ?? 0),
            'tax' => (float) ($metaArray['_wpinv_tax'] ?? 0),
            'discount' => (float) ($metaArray['_wpinv_discount'] ?? 0),
            'currency' => $metaArray['_wpinv_currency'] ?? 'EUR',
            'payment_reference' => $metaArray['_wpinv_ogm'] ?? null,
            'source' => 'legacy',
        ];
    }

    /**
     * Map legacy invoice status to readable status
     */
    private function mapInvoiceStatus(string $status): string
    {
        $map = [
            'wpi-pending' => 'pending',
            'wpi-processing' => 'processing',
            'wpi-onhold' => 'on_hold',
            'wpi-cancelled' => 'cancelled',
            'wpi-refunded' => 'refunded',
            'wpi-failed' => 'failed',
            'publish' => 'paid',
            'wpi-renewal' => 'renewal',
            'wpi-quote' => 'quote',
        ];

        return $map[$status] ?? $status;
    }

    // ========================================
    // CERTIFICATES
    // ========================================

    /**
     * Get user's historical certificates
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUserCertificates(int $userId, int $limit = 50): array
    {
        $cacheKey = "certificates_{$userId}_{$limit}";
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $db = $this->getConnection();
        if (!$db) {
            return [];
        }

        // LearnDash certificates are stored as usermeta
        $results = $db->get_results($db->prepare("
            SELECT
                um.meta_key,
                um.meta_value as certificate_link,
                p.ID as course_id,
                p.post_title as course_title
            FROM {$this->prefix}usermeta um
            JOIN {$this->prefix}posts p ON p.ID = SUBSTRING(um.meta_key, 14, LENGTH(um.meta_key) - 25)
            WHERE um.user_id = %d
            AND um.meta_key LIKE 'course_completed_%%'
            AND p.post_type = 'sfwd-courses'
            ORDER BY um.umeta_id DESC
            LIMIT %d
        ", $userId, $limit), ARRAY_A);

        $certificates = [];
        foreach ($results ?: [] as $row) {
            // Check if course has certificate enabled
            $hasCert = $db->get_var($db->prepare("
                SELECT meta_value
                FROM {$this->prefix}postmeta
                WHERE post_id = %d
                AND meta_key = '_sfwd-courses'
            ", $row['course_id']));

            if ($hasCert) {
                $courseSettings = maybe_unserialize($hasCert);
                if (!empty($courseSettings['sfwd-courses_certificate'])) {
                    $certificates[] = [
                        'course_id' => (int) $row['course_id'],
                        'course_title' => $row['course_title'],
                        'certificate_id' => (int) $courseSettings['sfwd-courses_certificate'],
                        'source' => 'legacy',
                    ];
                }
            }
        }

        $this->setCache($cacheKey, $certificates);
        return $certificates;
    }

    // ========================================
    // VOUCHERS
    // ========================================

    /**
     * Get user's historical vouchers
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUserVouchers(int $userId, int $limit = 50): array
    {
        $cacheKey = "vouchers_{$userId}_{$limit}";
        if ($cached = $this->getFromCache($cacheKey)) {
            return $cached;
        }

        $db = $this->getConnection();
        if (!$db) {
            return [];
        }

        // GetPaid discounts/vouchers
        $results = $db->get_results($db->prepare("
            SELECT
                p.ID,
                p.post_title as code,
                p.post_status as status,
                pm_type.meta_value as discount_type,
                pm_amount.meta_value as discount_amount,
                pm_expiry.meta_value as expiry_date
            FROM {$this->prefix}posts p
            LEFT JOIN {$this->prefix}postmeta pm_type ON p.ID = pm_type.post_id AND pm_type.meta_key = '_wpi_discount_type'
            LEFT JOIN {$this->prefix}postmeta pm_amount ON p.ID = pm_amount.post_id AND pm_amount.meta_key = '_wpi_discount_amount'
            LEFT JOIN {$this->prefix}postmeta pm_expiry ON p.ID = pm_expiry.post_id AND pm_expiry.meta_key = '_wpi_discount_expiry'
            LEFT JOIN {$this->prefix}postmeta pm_owner ON p.ID = pm_owner.post_id AND pm_owner.meta_key = '_wpi_discount_owner'
            WHERE p.post_type = 'wpi_discount'
            AND pm_owner.meta_value = %d
            ORDER BY p.post_date DESC
            LIMIT %d
        ", $userId, $limit), ARRAY_A);

        $vouchers = array_map(function ($row) {
            return [
                'id' => (int) $row['ID'],
                'code' => $row['code'],
                'status' => $row['status'] === 'publish' ? 'active' : 'inactive',
                'discount_type' => $row['discount_type'] ?: 'flat',
                'discount_amount' => (float) ($row['discount_amount'] ?? 0),
                'expiry_date' => $row['expiry_date'] ?: null,
                'source' => 'legacy',
            ];
        }, $results ?: []);

        $this->setCache($cacheKey, $vouchers);
        return $vouchers;
    }

    // ========================================
    // COURSE DATA
    // ========================================

    /**
     * Get historical course data
     *
     * @param int $courseId
     * @return array|null
     */
    public function getCourse(int $courseId): ?array
    {
        $db = $this->getConnection();
        if (!$db) {
            return null;
        }

        $result = $db->get_row($db->prepare("
            SELECT
                p.ID,
                p.post_title as title,
                p.post_content as content,
                p.post_excerpt as excerpt,
                p.post_date as created_date
            FROM {$this->prefix}posts p
            WHERE p.ID = %d
            AND p.post_type = 'sfwd-courses'
        ", $courseId), ARRAY_A);

        if (!$result) {
            return null;
        }

        return [
            'id' => (int) $result['ID'],
            'title' => $result['title'],
            'excerpt' => $result['excerpt'],
            'created_date' => $result['created_date'],
            'source' => 'legacy',
        ];
    }

    // ========================================
    // CACHE HELPERS
    // ========================================

    /**
     * Get from transient cache
     */
    private function getFromCache(string $key): mixed
    {
        $transientKey = 'stride_legacy_' . md5($key);
        $cached = get_transient($transientKey);
        return $cached !== false ? $cached : null;
    }

    /**
     * Set transient cache
     */
    private function setCache(string $key, mixed $value): void
    {
        $transientKey = 'stride_legacy_' . md5($key);
        set_transient($transientKey, $value, self::CACHE_TTL);
    }

    /**
     * Clear all historical data cache for a user
     */
    public function clearUserCache(int $userId): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_stride_legacy_' . md5("enrollments_{$userId}") . '%'
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_stride_legacy_' . md5("invoices_{$userId}") . '%'
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_stride_legacy_' . md5("certificates_{$userId}") . '%'
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_stride_legacy_' . md5("vouchers_{$userId}") . '%'
        ));
    }
}
