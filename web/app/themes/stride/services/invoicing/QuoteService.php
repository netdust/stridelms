<?php

namespace stride\services\invoicing;

defined('ABSPATH') || exit;

use stride\services\core\CourseService;
use stride\services\core\SubscriberService;
use stride\services\FieldRegistry;
use WP_Error;
use WP_Post;

/**
 * Quote Service
 *
 * Main orchestrator for quote creation and management.
 * Registers vad_quote CPT and handles quote lifecycle.
 *
 * Available hooks:
 * - stride/quote/created (action) - After quote creation
 * - stride/quote/updated (action) - After quote update
 * - stride/quote/sent (action) - After quote marked as sent
 * - stride/quote/exported (action) - After quote marked as exported
 *
 * @package stride\services\invoicing
 */
class QuoteService implements \NTDST_Service_Meta
{
    public const POST_TYPE = 'vad_quote';

    // Simple 3-state workflow
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_EXPORTED = 'exported';

    // Meta keys
    public const META_USER_ID = '_stride_user_id';
    public const META_COURSE_ID = '_stride_course_id';
    public const META_STATUS = '_stride_status';
    public const META_QUOTE_NUMBER = '_stride_quote_number';
    public const META_ITEMS = '_stride_items';
    public const META_SUBTOTAL = '_stride_subtotal';
    public const META_TAX = '_stride_tax';
    public const META_TOTAL = '_stride_total';
    public const META_VALID_UNTIL = '_stride_valid_until';
    public const META_BILLING = '_stride_billing';
    public const META_ORDER_NUMBER = '_stride_order_number';
    public const META_VOUCHER_CODE = '_stride_voucher_code';
    public const META_PDF_PATH = '_stride_pdf_path';
    public const META_CREATED_AT = '_stride_created_at';
    public const META_SENT_AT = '_stride_sent_at';
    public const META_EXPORTED_AT = '_stride_exported_at';

    private ?CourseService $courseService;
    private ?SubscriberService $subscriberService;
    private ?VATValidator $vatValidator;

    /**
     * Request-level cache for quotes to prevent duplicate fetches
     */
    private static array $quoteCache = [];

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Quote Service',
            'description' => 'Quote CPT, CRUD, and status management',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor with optional dependency injection for testing
     */
    public function __construct(
        ?CourseService $courseService = null,
        ?SubscriberService $subscriberService = null,
        ?VATValidator $vatValidator = null
    ) {
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->subscriberService = $subscriberService ?? $this->resolveService(SubscriberService::class);
        $this->vatValidator = $vatValidator ?? new VATValidator();

        // Register CPT
        add_action('init', [$this, 'registerPostType']);

        // Hook into enrollment completion
        add_action('stride/enrollment/completed', [$this, 'handleEnrollmentCompleted'], 10, 3);

        // Ensure database indexes exist (runs once)
        add_action('admin_init', [$this, 'ensureDatabaseIndexes']);
    }

    /**
     * Ensure database indexes exist for quote meta queries
     *
     * Creates composite indexes on postmeta for frequently queried meta keys.
     * Uses transient to only run once per day.
     */
    public function ensureDatabaseIndexes(): void
    {
        // Only run once per day
        $transientKey = 'stride_quote_indexes_checked';
        if (get_transient($transientKey)) {
            return;
        }

        global $wpdb;

        // Check if our custom index exists
        $indexExists = $wpdb->get_var(
            "SELECT COUNT(1) FROM information_schema.STATISTICS
             WHERE table_schema = DATABASE()
             AND table_name = '{$wpdb->postmeta}'
             AND index_name = 'idx_stride_quote_meta'"
        );

        if (!$indexExists) {
            // Create composite index for quote queries
            // Suppressing errors as index may already exist in some form
            $wpdb->suppress_errors(true);
            $wpdb->query(
                "CREATE INDEX idx_stride_quote_meta ON {$wpdb->postmeta} (meta_key(32), meta_value(32))"
            );
            $wpdb->suppress_errors(false);
        }

        set_transient($transientKey, 1, DAY_IN_SECONDS);
    }

    /**
     * Resolve service from DI container or create new instance
     */
    private function resolveService(string $class): object
    {
        if (function_exists('ntdst_get')) {
            try {
                $service = ntdst_get($class);
                if ($service instanceof $class) {
                    return $service;
                }
            } catch (\Exception $e) {
                // Fall through to create new instance
            }
        }
        return new $class();
    }

    /**
     * Register vad_quote custom post type
     */
    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Offertes', 'stride'),
                'singular_name' => __('Offerte', 'stride'),
                'menu_name' => __('Offertes', 'stride'),
                'add_new' => __('Nieuwe offerte', 'stride'),
                'add_new_item' => __('Nieuwe offerte toevoegen', 'stride'),
                'edit_item' => __('Offerte bewerken', 'stride'),
                'view_item' => __('Offerte bekijken', 'stride'),
                'all_items' => __('Alle offertes', 'stride'),
                'search_items' => __('Offertes zoeken', 'stride'),
                'not_found' => __('Geen offertes gevonden', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'rewrite' => false,
        ]);
    }

    // ========================================
    // CRUD METHODS
    // ========================================

    /**
     * Create a new quote for a user/course
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @param array $data Additional quote data
     * @return int|WP_Error Quote post ID or error
     */
    public function createQuote(int $userId, int $courseId, array $data = []): int|WP_Error
    {
        // Check if user already has a quote for this course
        $existing = $this->getUserQuoteForCourse($userId, $courseId);
        if ($existing) {
            return new WP_Error('quote_exists', __('Er bestaat al een offerte voor deze cursus.', 'stride'));
        }

        // Generate quote number
        $quoteNumber = $this->generateQuoteNumber();

        // Get course data
        $course = get_post($courseId);
        if (!$course || $course->post_type !== 'sfwd-courses') {
            return new WP_Error('invalid_course', __('Ongeldige cursus.', 'stride'));
        }

        // Get billing data from subscriber
        $billing = $this->subscriberService->getBillingData($userId);
        if (is_wp_error($billing)) {
            $billing = [];
        }

        // Merge with any provided billing overrides
        $billing = array_merge($billing, array_filter([
            'organisation' => $data['invoice_org_name'] ?? null,
            'address' => $data['invoice_address'] ?? null,
            'city' => $data['invoice_city'] ?? null,
            'postal_code' => $data['invoice_postal_code'] ?? null,
            'vat_number' => $data['invoice_vat'] ?? null,
            'gln_number' => $data['invoice_gln'] ?? null,
            'email' => $data['invoice_email'] ?? null,
        ], fn($v) => $v !== null && $v !== ''));

        // Validate VAT if provided
        if (!empty($billing['vat_number'])) {
            $vatResult = $this->vatValidator->validate($billing['vat_number']);
            $billing['vat_validated'] = $vatResult['valid'];
            $billing['vat_source'] = $vatResult['source'] ?? 'unknown';
            // Auto-fill company from VIES if available
            if ($vatResult['valid'] && !empty($vatResult['name'])) {
                $billing['organisation'] = $vatResult['name'];
            }
        }

        // Get course price
        $coursePrice = $this->getCoursePrice($courseId);
        $taxRate = $this->getTaxRate();
        $subtotal = $coursePrice;
        $tax = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $tax;

        // Build items array
        $items = [
            [
                'id' => $courseId,
                'type' => 'course',
                'title' => $course->post_title,
                'quantity' => 1,
                'unit_price' => $coursePrice,
                'total' => $coursePrice,
            ],
        ];

        // Calculate valid until date
        $validDays = $this->getConfig('valid_days', 30);
        $validUntil = date('Y-m-d', strtotime("+{$validDays} days"));

        // Wrap quote creation in transaction for atomicity
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            // Create quote post
            $quoteId = wp_insert_post([
                'post_type' => self::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $quoteNumber,
                'post_author' => $userId,
            ]);

            if (is_wp_error($quoteId)) {
                $wpdb->query('ROLLBACK');
                return $quoteId;
            }

            // Store meta data
            update_post_meta($quoteId, self::META_USER_ID, $userId);
            update_post_meta($quoteId, self::META_COURSE_ID, $courseId);
            update_post_meta($quoteId, self::META_STATUS, self::STATUS_DRAFT);
            update_post_meta($quoteId, self::META_QUOTE_NUMBER, $quoteNumber);
            update_post_meta($quoteId, self::META_ITEMS, $items);
            update_post_meta($quoteId, self::META_SUBTOTAL, $subtotal);
            update_post_meta($quoteId, self::META_TAX, $tax);
            update_post_meta($quoteId, self::META_TOTAL, $total);
            update_post_meta($quoteId, self::META_VALID_UNTIL, $validUntil);
            update_post_meta($quoteId, self::META_BILLING, $billing);
            update_post_meta($quoteId, self::META_CREATED_AT, current_time('mysql'));

            if (!empty($data['order_number'])) {
                update_post_meta($quoteId, self::META_ORDER_NUMBER, sanitize_text_field($data['order_number']));
            }
            if (!empty($data['voucher_code'])) {
                update_post_meta($quoteId, self::META_VOUCHER_CODE, sanitize_text_field($data['voucher_code']));
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('quote_creation_failed', __('Offerte aanmaken mislukt.', 'stride'));
        }

        // Create CRM note
        $this->subscriberService->createNote(
            $userId,
            sprintf(__('Offerte %s aangemaakt voor: %s', 'stride'), $quoteNumber, $course->post_title)
        );

        // Fire hook
        do_action('stride/quote/created', $quoteId, $userId, $courseId);

        return $quoteId;
    }

    /**
     * Get quote data as array (with request-level caching)
     *
     * @param int $quoteId Quote post ID
     * @param bool $bypassCache Force fresh fetch
     * @return array|null Quote data or null if not found
     */
    public function getQuote(int $quoteId, bool $bypassCache = false): ?array
    {
        // Check request-level cache first
        if (!$bypassCache && isset(self::$quoteCache[$quoteId])) {
            return self::$quoteCache[$quoteId];
        }

        $post = get_post($quoteId);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return null;
        }

        // Fetch all meta in single query (prevents N+1)
        $allMeta = get_post_meta($quoteId);

        $quote = [
            'id' => $quoteId,
            'number' => $allMeta[self::META_QUOTE_NUMBER][0] ?? '',
            'status' => $allMeta[self::META_STATUS][0] ?? self::STATUS_DRAFT,
            'user_id' => (int) ($allMeta[self::META_USER_ID][0] ?? 0),
            'course_id' => (int) ($allMeta[self::META_COURSE_ID][0] ?? 0),
            'items' => isset($allMeta[self::META_ITEMS][0]) ? maybe_unserialize($allMeta[self::META_ITEMS][0]) : [],
            'subtotal' => (float) ($allMeta[self::META_SUBTOTAL][0] ?? 0),
            'tax' => (float) ($allMeta[self::META_TAX][0] ?? 0),
            'total' => (float) ($allMeta[self::META_TOTAL][0] ?? 0),
            'valid_until' => $allMeta[self::META_VALID_UNTIL][0] ?? '',
            'billing' => isset($allMeta[self::META_BILLING][0]) ? maybe_unserialize($allMeta[self::META_BILLING][0]) : [],
            'order_number' => $allMeta[self::META_ORDER_NUMBER][0] ?? '',
            'voucher_code' => $allMeta[self::META_VOUCHER_CODE][0] ?? '',
            'pdf_path' => $allMeta[self::META_PDF_PATH][0] ?? '',
            'created_at' => $allMeta[self::META_CREATED_AT][0] ?? '',
            'sent_at' => $allMeta[self::META_SENT_AT][0] ?? '',
            'exported_at' => $allMeta[self::META_EXPORTED_AT][0] ?? '',
        ];

        // Cache for this request
        self::$quoteCache[$quoteId] = $quote;

        return $quote;
    }

    /**
     * Clear quote from request cache (call after updates)
     */
    public function clearQuoteCache(int $quoteId): void
    {
        unset(self::$quoteCache[$quoteId]);
    }

    /**
     * Update quote data
     *
     * @param int $quoteId Quote post ID
     * @param array $data Data to update
     * @return true|WP_Error
     */
    public function updateQuote(int $quoteId, array $data): true|WP_Error
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        // Only allow updates in draft status
        if ($quote['status'] !== self::STATUS_DRAFT) {
            return new WP_Error('quote_locked', __('Offerte kan niet meer worden gewijzigd.', 'stride'));
        }

        // Update billing data
        if (isset($data['billing']) || isset($data['company']) || isset($data['address'])) {
            $billing = $quote['billing'];

            // Map incoming field names to billing array keys
            $billingMap = [
                'company' => 'organisation',
                'organisation' => 'organisation',
                'address' => 'address',
                'city' => 'city',
                'postal_code' => 'postal_code',
                'vat_number' => 'vat_number',
                'gln_number' => 'gln_number',
                'email' => 'email',
            ];

            foreach ($billingMap as $input => $key) {
                if (isset($data[$input]) && $data[$input] !== '') {
                    $billing[$key] = sanitize_text_field($data[$input]);
                }
            }

            // Re-validate VAT if changed
            if (!empty($data['vat_number']) && $data['vat_number'] !== ($quote['billing']['vat_number'] ?? '')) {
                $vatResult = $this->vatValidator->validate($data['vat_number']);
                $billing['vat_validated'] = $vatResult['valid'];
                $billing['vat_source'] = $vatResult['source'] ?? 'unknown';
                if ($vatResult['valid'] && !empty($vatResult['name'])) {
                    $billing['organisation'] = $vatResult['name'];
                }
            }

            update_post_meta($quoteId, self::META_BILLING, $billing);
        }

        // Update order number
        if (isset($data['order_number'])) {
            update_post_meta($quoteId, self::META_ORDER_NUMBER, sanitize_text_field($data['order_number']));
        }

        // Update voucher code
        if (isset($data['voucher_code'])) {
            update_post_meta($quoteId, self::META_VOUCHER_CODE, sanitize_text_field($data['voucher_code']));
        }

        // Clear cache after update
        $this->clearQuoteCache($quoteId);

        // Fire hook
        do_action('stride/quote/updated', $quoteId, $data);

        return true;
    }

    /**
     * Mark quote as sent
     *
     * @param int $quoteId Quote post ID
     * @return true|WP_Error
     */
    public function sendQuote(int $quoteId): true|WP_Error
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        if ($quote['status'] !== self::STATUS_DRAFT) {
            return new WP_Error('invalid_status', __('Offerte is al verzonden.', 'stride'));
        }

        update_post_meta($quoteId, self::META_STATUS, self::STATUS_SENT);
        update_post_meta($quoteId, self::META_SENT_AT, current_time('mysql'));

        // Clear cache after update
        $this->clearQuoteCache($quoteId);

        // Fire hook (for email notification, PDF generation, etc.)
        do_action('stride/quote/sent', $quoteId, $quote);

        return true;
    }

    /**
     * Mark quote as exported (to Exact Online)
     *
     * @param int $quoteId Quote post ID
     * @return true|WP_Error
     */
    public function exportQuote(int $quoteId): true|WP_Error
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        update_post_meta($quoteId, self::META_STATUS, self::STATUS_EXPORTED);
        update_post_meta($quoteId, self::META_EXPORTED_AT, current_time('mysql'));

        // Clear cache after update
        $this->clearQuoteCache($quoteId);

        // Fire hook
        do_action('stride/quote/exported', $quoteId, $quote);

        return true;
    }

    // ========================================
    // QUERY METHODS
    // ========================================

    /**
     * Get user's quote for a specific course
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return int|null Quote post ID or null
     */
    public function getUserQuoteForCourse(int $userId, int $courseId): ?int
    {
        $quotes = get_posts([
            'post_type' => self::POST_TYPE,
            'meta_query' => [
                ['key' => self::META_USER_ID, 'value' => $userId],
                ['key' => self::META_COURSE_ID, 'value' => $courseId],
            ],
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);

        return $quotes[0] ?? null;
    }

    /**
     * Get all quotes for a user
     *
     * @param int $userId WordPress user ID
     * @param string|null $status Filter by status
     * @return array Array of quote data
     */
    public function getUserQuotes(int $userId, ?string $status = null): array
    {
        $metaQuery = [
            ['key' => self::META_USER_ID, 'value' => $userId],
        ];

        if ($status) {
            $metaQuery[] = ['key' => self::META_STATUS, 'value' => $status];
        }

        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'meta_query' => $metaQuery,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (empty($posts)) {
            return [];
        }

        // Prime meta cache for all posts in single query (prevents N+1)
        $postIds = wp_list_pluck($posts, 'ID');
        update_meta_cache('post', $postIds);

        return array_map(fn($post) => $this->getQuote($post->ID), $posts);
    }

    /**
     * Get quotes by status
     *
     * @param string $status Quote status
     * @param int $limit Max number of quotes
     * @return array Array of quote data
     */
    public function getQuotesByStatus(string $status, int $limit = 100): array
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'meta_query' => [
                ['key' => self::META_STATUS, 'value' => $status],
            ],
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'ASC',
        ]);

        if (empty($posts)) {
            return [];
        }

        // Prime meta cache for all posts in single query (prevents N+1)
        $postIds = wp_list_pluck($posts, 'ID');
        update_meta_cache('post', $postIds);

        return array_map(fn($post) => $this->getQuote($post->ID), $posts);
    }

    // ========================================
    // ENROLLMENT HANDLER
    // ========================================

    /**
     * Handle enrollment completion - create quote if applicable
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @param array $data Enrollment data
     */
    public function handleEnrollmentCompleted(int $userId, int $courseId, array $data): void
    {
        if (!$this->shouldCreateQuote($userId, $courseId)) {
            return;
        }

        $result = $this->createQuote($userId, $courseId, $data);

        if (is_wp_error($result)) {
            // Log error but don't interrupt enrollment
            error_log(sprintf(
                'Stride: Failed to create quote for user %d, course %d: %s',
                $userId,
                $courseId,
                $result->get_error_message()
            ));
        }
    }

    /**
     * Determine if a quote should be created
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @return bool
     */
    private function shouldCreateQuote(int $userId, int $courseId): bool
    {
        // Skip if user is admin
        if (user_can($userId, 'manage_options')) {
            return false;
        }

        // Skip for internal email domains
        $user = get_userdata($userId);
        if (!$user) {
            return false;
        }

        $skipDomains = $this->getConfig('skip_domains', ['vad.be', 'druglijn.be']);
        $emailDomain = substr(strrchr($user->user_email, '@'), 1);
        if (in_array($emailDomain, $skipDomains, true)) {
            return false;
        }

        // Skip if user has "geen-factuur" tag in FluentCRM
        $skipTag = $this->getConfig('skip_tag', 'geen-factuur');
        if ($skipTag && $this->subscriberService->hasTag($userId, $skipTag)) {
            return false;
        }

        // Skip if course has no price
        $price = $this->getCoursePrice($courseId);
        if ($price <= 0) {
            return false;
        }

        // Skip if quote already exists
        if ($this->getUserQuoteForCourse($userId, $courseId)) {
            return false;
        }

        return true;
    }

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Generate unique quote number with atomic increment
     *
     * @return string Quote number (VADQ-YYYY-NNNNN)
     */
    private function generateQuoteNumber(): string
    {
        global $wpdb;

        $prefix = $this->getConfig('quote_prefix', 'VADQ');
        $year = date('Y');
        $optionName = "stride_quote_last_{$year}";

        // Atomic increment to prevent race conditions
        $wpdb->query('START TRANSACTION');

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, 1, 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $optionName
        ));

        $number = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $optionName
        ));

        $wpdb->query('COMMIT');

        return sprintf('%s-%s-%05d', $prefix, $year, $number);
    }

    /**
     * Get course price from LearnDash settings
     *
     * @param int $courseId LearnDash course ID
     * @return float Price or 0
     */
    private function getCoursePrice(int $courseId): float
    {
        // LearnDash stores price in course meta
        $settings = get_post_meta($courseId, '_sfwd-courses', true);
        if (is_array($settings) && isset($settings['sfwd-courses_course_price'])) {
            return (float) $settings['sfwd-courses_course_price'];
        }

        // Fallback to direct meta
        $price = get_post_meta($courseId, 'course_price', true);
        return (float) $price;
    }

    /**
     * Get tax rate from config
     *
     * @return float Tax rate percentage
     */
    private function getTaxRate(): float
    {
        return $this->getConfig('tax_rate', 21.0);
    }

    /**
     * Get config value from theme-config.php
     *
     * @param string $key Config key
     * @param mixed $default Default value
     * @return mixed
     */
    private function getConfig(string $key, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $configPath = get_stylesheet_directory() . '/theme-config.php';
            if (file_exists($configPath)) {
                $config = include $configPath;
            } else {
                $config = [];
            }
        }

        return $config['modules']['invoicing'][$key] ?? $default;
    }
}
