<?php

namespace stride\services\voucher;

defined('ABSPATH') || exit;

use stride\services\core\CourseService;
use WP_Error;

/**
 * Voucher Service
 *
 * Manages voucher codes for course enrollments.
 * Uses NTDST Data Manager for all database operations.
 *
 * Security features:
 * - Transaction locking for redemption (prevents race conditions)
 * - Rate limiting on validation API (prevents brute force)
 * - Generic error messages (prevents information disclosure)
 * - Capability checks on creation
 *
 * Available hooks:
 * - stride/voucher/created (action) - After voucher creation
 * - stride/voucher/redeemed (action) - After voucher redemption
 * - stride/voucher/batch_created (action) - After batch creation
 *
 * API Endpoints (via ntdst/api_data):
 * - stride_voucher_validate - Validate a voucher code (requires auth)
 * - stride_voucher_redeem - Redeem a voucher (requires auth)
 *
 * @package stride\services\voucher
 */
class VoucherService implements \NTDST_Service_Meta
{
    public const POST_TYPE = 'vad_voucher';

    // Voucher types
    public const TYPE_SINGLE = 'single';
    public const TYPE_MULTI = 'multi';

    // Discount types
    public const DISCOUNT_FULL = 'full';
    public const DISCOUNT_FIXED = 'fixed';
    public const DISCOUNT_PERCENTAGE = 'percentage';

    // Status
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXHAUSTED = 'exhausted';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_DISABLED = 'disabled';

    // Field names
    public const FIELD_CODE = 'code';
    public const FIELD_TYPE = 'type';
    public const FIELD_USAGE_LIMIT = 'usage_limit';
    public const FIELD_USED_COUNT = 'used_count';
    public const FIELD_COURSE_ID = 'course_id';
    public const FIELD_GROUP_ID = 'group_id';
    public const FIELD_DISCOUNT_TYPE = 'discount_type';
    public const FIELD_DISCOUNT_VALUE = 'discount_value';
    public const FIELD_VALID_FROM = 'valid_from';
    public const FIELD_VALID_UNTIL = 'valid_until';
    public const FIELD_STATUS = 'status';
    public const FIELD_BATCH_ID = 'batch_id';
    public const FIELD_CREATED_BY = 'created_by';
    public const FIELD_REDEMPTIONS = 'redemptions';

    // Rate limiting
    private const RATE_LIMIT_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW = 60; // seconds

    private ?CourseService $courseService;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Voucher Service',
            'description' => 'Voucher CPT, code generation, and redemption tracking',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];
    }

    /**
     * Constructor with optional dependency injection for testing
     */
    public function __construct(?CourseService $courseService = null)
    {
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);

        add_action('init', [$this, 'registerModel'], 5);
        add_action('init', [$this, 'registerApiEndpoints'], 10);
        add_action('add_meta_boxes', [$this, 'registerAuditMetabox']);
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
                // Fall through
            }
        }
        return new $class();
    }

    /**
     * Register vad_voucher model via NTDST DataManager
     */
    public function registerModel(): void
    {
        if (!function_exists('ntdst_data')) {
            $this->registerPostTypeFallback();
            return;
        }

        ntdst_data()->register(self::POST_TYPE, [
            'label' => __('Vouchers', 'stride'),
            'labels' => [
                'name' => __('Vouchers', 'stride'),
                'singular_name' => __('Voucher', 'stride'),
                'menu_name' => __('Vouchers', 'stride'),
                'add_new' => __('Nieuwe voucher', 'stride'),
                'add_new_item' => __('Nieuwe voucher toevoegen', 'stride'),
                'edit_item' => __('Voucher bewerken', 'stride'),
                'view_item' => __('Voucher bekijken', 'stride'),
                'all_items' => __('Alle vouchers', 'stride'),
                'search_items' => __('Vouchers zoeken', 'stride'),
                'not_found' => __('Geen vouchers gevonden', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'has_archive' => false,
            'menu_icon' => 'dashicons-tickets-alt',

            'fields' => [
                self::FIELD_CODE => [
                    'type' => 'text',
                    'required' => true,
                    'label' => __('Vouchercode', 'stride'),
                ],
                self::FIELD_TYPE => [
                    'type' => 'select',
                    'options' => [
                        self::TYPE_SINGLE => __('Eenmalig', 'stride'),
                        self::TYPE_MULTI => __('Meervoudig', 'stride'),
                    ],
                    'default' => self::TYPE_SINGLE,
                    'label' => __('Type', 'stride'),
                ],
                self::FIELD_USAGE_LIMIT => [
                    'type' => 'integer',
                    'min' => 0,
                    'default' => 1,
                    'label' => __('Gebruikslimiet', 'stride'),
                    'description' => __('0 = onbeperkt', 'stride'),
                ],
                self::FIELD_USED_COUNT => [
                    'type' => 'integer',
                    'min' => 0,
                    'default' => 0,
                    'label' => __('Aantal gebruikt', 'stride'),
                ],
                self::FIELD_COURSE_ID => [
                    'type' => 'relation',
                    'post_type' => 'sfwd-courses',
                    'multiple' => false,
                    'label' => __('Beperkt tot cursus', 'stride'),
                    'placeholder' => __('Zoek cursus...', 'stride'),
                    'description' => __('Laat leeg voor alle cursussen', 'stride'),
                ],
                self::FIELD_GROUP_ID => [
                    'type' => 'relation',
                    'post_type' => 'groups',
                    'multiple' => false,
                    'label' => __('Beperkt tot traject', 'stride'),
                    'placeholder' => __('Zoek traject...', 'stride'),
                    'description' => __('Laat leeg voor alle trajecten', 'stride'),
                ],
                self::FIELD_DISCOUNT_TYPE => [
                    'type' => 'select',
                    'options' => [
                        self::DISCOUNT_FULL => __('100% korting', 'stride'),
                        self::DISCOUNT_FIXED => __('Vast bedrag', 'stride'),
                        self::DISCOUNT_PERCENTAGE => __('Percentage', 'stride'),
                    ],
                    'default' => self::DISCOUNT_FULL,
                    'label' => __('Kortingstype', 'stride'),
                ],
                self::FIELD_DISCOUNT_VALUE => [
                    'type' => 'float',
                    'min' => 0,
                    'default' => 0,
                    'label' => __('Kortingswaarde', 'stride'),
                ],
                self::FIELD_VALID_FROM => [
                    'type' => 'date',
                    'label' => __('Geldig vanaf', 'stride'),
                ],
                self::FIELD_VALID_UNTIL => [
                    'type' => 'date',
                    'label' => __('Geldig tot', 'stride'),
                ],
                self::FIELD_STATUS => [
                    'type' => 'select',
                    'options' => [
                        self::STATUS_ACTIVE => __('Actief', 'stride'),
                        self::STATUS_EXHAUSTED => __('Uitgeput', 'stride'),
                        self::STATUS_EXPIRED => __('Verlopen', 'stride'),
                        self::STATUS_DISABLED => __('Uitgeschakeld', 'stride'),
                    ],
                    'default' => self::STATUS_ACTIVE,
                    'label' => __('Status', 'stride'),
                ],
                self::FIELD_BATCH_ID => [
                    'type' => 'text',
                    'label' => __('Batch ID', 'stride'),
                ],
                self::FIELD_CREATED_BY => [
                    'type' => 'integer',
                    'label' => __('Aangemaakt door', 'stride'),
                ],
                self::FIELD_REDEMPTIONS => [
                    'type' => 'json',
                    'label' => __('Verzilveringen', 'stride'),
                    'show_in_metabox' => false, // Rendered in separate audit metabox
                ],
            ],

            'field_groups' => [
                'general' => [
                    'title' => __('Algemeen', 'stride'),
                    'fields' => [self::FIELD_CODE, self::FIELD_TYPE, self::FIELD_STATUS],
                ],
                'usage' => [
                    'title' => __('Gebruik', 'stride'),
                    'fields' => [self::FIELD_USAGE_LIMIT, self::FIELD_USED_COUNT],
                ],
                'scope' => [
                    'title' => __('Scope', 'stride'),
                    'fields' => [self::FIELD_COURSE_ID, self::FIELD_GROUP_ID],
                ],
                'discount' => [
                    'title' => __('Korting', 'stride'),
                    'fields' => [self::FIELD_DISCOUNT_TYPE, self::FIELD_DISCOUNT_VALUE],
                ],
                'validity' => [
                    'title' => __('Geldigheid', 'stride'),
                    'fields' => [self::FIELD_VALID_FROM, self::FIELD_VALID_UNTIL],
                ],
                // Note: FIELD_REDEMPTIONS excluded - rendered in separate metabox
            ],
            'use_tabs' => true,
        ]);
    }

    /**
     * Fallback CPT registration if DataManager not available
     */
    private function registerPostTypeFallback(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Vouchers', 'stride'),
                'singular_name' => __('Voucher', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title'],
        ]);
    }

    /**
     * Register API endpoints via NTDST API system
     */
    public function registerApiEndpoints(): void
    {
        add_filter('ntdst/api_data/stride_voucher_validate', [$this, 'apiValidateVoucher'], 10, 2);
        add_filter('ntdst/api_data/stride_voucher_redeem', [$this, 'apiRedeemVoucher'], 10, 2);
    }

    /**
     * Register separate audit metabox for redemption history
     */
    public function registerAuditMetabox(): void
    {
        add_meta_box(
            'stride_voucher_audit',
            __('Verzilveringshistorie', 'stride'),
            [$this, 'renderAuditMetabox'],
            self::POST_TYPE,
            'normal',
            'low'
        );
    }

    /**
     * Render the audit metabox with redemption history
     */
    public function renderAuditMetabox(\WP_Post $post): void
    {
        $voucher = $this->getVoucher($post->ID);
        $redemptions = $voucher['redemptions'] ?? [];

        if (empty($redemptions)) {
            echo '<p class="description">' . esc_html__('Nog geen verzilveringen.', 'stride') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Gebruiker', 'stride') . '</th>';
        echo '<th>' . esc_html__('Cursus', 'stride') . '</th>';
        echo '<th>' . esc_html__('Korting', 'stride') . '</th>';
        echo '<th>' . esc_html__('Datum', 'stride') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($redemptions as $redemption) {
            $userId = (int) ($redemption['user_id'] ?? 0);
            $courseId = (int) ($redemption['course_id'] ?? 0);
            $discount = (float) ($redemption['discount'] ?? 0);
            $date = $redemption['redeemed_at'] ?? '';

            // Get user display name
            $user = get_userdata($userId);
            $userName = $user ? esc_html($user->display_name) : sprintf(__('Gebruiker #%d', 'stride'), $userId);
            $userLink = $user ? '<a href="' . esc_url(get_edit_user_link($userId)) . '">' . $userName . '</a>' : $userName;

            // Get course title
            $courseTitle = $courseId ? get_the_title($courseId) : '-';
            $courseLink = $courseId ? '<a href="' . esc_url(get_edit_post_link($courseId)) . '">' . esc_html($courseTitle) . '</a>' : '-';

            echo '<tr>';
            echo '<td>' . $userLink . '</td>';
            echo '<td>' . $courseLink . '</td>';
            echo '<td>€ ' . esc_html(number_format($discount, 2, ',', '.')) . '</td>';
            echo '<td>' . esc_html($date) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Get the Data Model for vouchers
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(self::POST_TYPE);
    }

    // ========================================
    // RATE LIMITING
    // ========================================

    /**
     * Check rate limit for voucher validation attempts
     *
     * @return bool True if allowed, false if rate limited
     */
    private function checkRateLimit(): bool
    {
        $ip = $this->getClientIp();
        $key = 'stride_voucher_attempts_' . md5($ip);
        $attempts = (int) get_transient($key);

        return $attempts < self::RATE_LIMIT_ATTEMPTS;
    }

    /**
     * Increment rate limit counter
     */
    private function incrementRateLimit(): void
    {
        $ip = $this->getClientIp();
        $key = 'stride_voucher_attempts_' . md5($ip);
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, self::RATE_LIMIT_WINDOW);
    }

    /**
     * Get client IP address safely
     */
    /**
     * Extract single ID from relation field value
     *
     * Relation fields may return array even with multiple=false
     */
    private function extractSingleId(mixed $value): int
    {
        if (is_array($value)) {
            return (int) ($value[0] ?? 0);
        }
        return (int) $value;
    }

    private function getClientIp(): string
    {
        // Check for proxy headers (only trust if behind known proxy)
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                // Take first IP if comma-separated
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Generic validation error (prevents information disclosure)
     */
    private function validationError(): WP_Error
    {
        return new WP_Error('invalid_voucher', __('Vouchercode ongeldig of verlopen.', 'stride'));
    }

    // ========================================
    // API ENDPOINTS
    // ========================================

    /**
     * API: Validate a voucher code
     *
     * Security: Requires authentication, rate limited
     */
    public function apiValidateVoucher($data, $params): array|WP_Error
    {
        // Require authentication
        if (!is_user_logged_in()) {
            return new WP_Error('unauthorized', __('Niet ingelogd.', 'stride'), ['status' => 401]);
        }

        // Check rate limit
        if (!$this->checkRateLimit()) {
            return new WP_Error('rate_limited', __('Te veel pogingen. Probeer later opnieuw.', 'stride'), ['status' => 429]);
        }

        $code = sanitize_text_field($params['code'] ?? '');
        $courseId = absint($params['course_id'] ?? 0);
        $groupId = absint($params['group_id'] ?? 0);

        if (empty($code)) {
            return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'), ['status' => 400]);
        }

        $validation = $this->validateVoucher($code, $courseId, $groupId);

        // Increment rate limit on any attempt (success or failure)
        $this->incrementRateLimit();

        if (is_wp_error($validation)) {
            // Return generic error to prevent information disclosure
            return $this->validationError();
        }

        // Don't expose full voucher data - only what's needed
        return [
            'success' => true,
            'valid' => true,
            'discount_type' => $validation['discount_type'],
            'discount' => $this->calculateDiscount($validation, $courseId),
        ];
    }

    /**
     * API: Redeem a voucher code
     *
     * Security: Requires authentication
     */
    public function apiRedeemVoucher($data, $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('unauthorized', __('Niet ingelogd.', 'stride'), ['status' => 401]);
        }

        $code = sanitize_text_field($params['code'] ?? '');
        $courseId = absint($params['course_id'] ?? 0);
        $groupId = absint($params['group_id'] ?? 0);

        if (empty($code)) {
            return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'), ['status' => 400]);
        }

        $result = $this->redeemVoucher($code, $userId, $courseId, $groupId);

        if (is_wp_error($result)) {
            // Return generic error for most cases
            $errorCode = $result->get_error_code();
            if ($errorCode === 'already_redeemed') {
                return $result; // This is safe to expose
            }
            return $this->validationError();
        }

        return [
            'success' => true,
            'message' => __('Voucher succesvol verzilverd.', 'stride'),
            'discount' => $result['discount'],
        ];
    }

    // ========================================
    // CRUD METHODS
    // ========================================

    /**
     * Create a new voucher
     *
     * Security: Requires manage_options capability
     */
    public function createVoucher(array $data = []): int|WP_Error
    {
        // Capability check
        if (!current_user_can('manage_options')) {
            return new WP_Error('unauthorized', __('Onvoldoende rechten.', 'stride'));
        }

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        // Generate code if not provided
        $code = !empty($data['code'])
            ? strtoupper(sanitize_text_field($data['code']))
            : $this->generateCode();

        // Check uniqueness
        if ($this->getVoucherByCode($code)) {
            return new WP_Error('code_exists', __('Deze vouchercode bestaat al.', 'stride'));
        }

        $type = $data['type'] ?? self::TYPE_SINGLE;
        $usageLimit = $type === self::TYPE_SINGLE ? 1 : absint($data['usage_limit'] ?? 0);

        $result = $model->create([
            'title' => $code,
            'status' => 'publish',
            self::FIELD_CODE => $code,
            self::FIELD_TYPE => $type,
            self::FIELD_USAGE_LIMIT => $usageLimit,
            self::FIELD_USED_COUNT => 0,
            self::FIELD_COURSE_ID => absint($data['course_id'] ?? 0),
            self::FIELD_GROUP_ID => absint($data['group_id'] ?? 0),
            self::FIELD_DISCOUNT_TYPE => $data['discount_type'] ?? self::DISCOUNT_FULL,
            self::FIELD_DISCOUNT_VALUE => (float) ($data['discount_value'] ?? 0),
            self::FIELD_VALID_FROM => $data['valid_from'] ?? '',
            self::FIELD_VALID_UNTIL => $data['valid_until'] ?? '',
            self::FIELD_STATUS => self::STATUS_ACTIVE,
            self::FIELD_BATCH_ID => $data['batch_id'] ?? '',
            self::FIELD_CREATED_BY => get_current_user_id(),
            self::FIELD_REDEMPTIONS => [],
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $voucherId = $result->ID;

        do_action('stride/voucher/created', $voucherId, $data);

        return $voucherId;
    }

    /**
     * Create a batch of vouchers
     *
     * Performance: Pre-generates codes and bulk-checks uniqueness
     */
    public function createBatch(int $count, array $data = []): array
    {
        if (!current_user_can('manage_options')) {
            return [
                'batch_id' => '',
                'created' => [],
                'errors' => [new WP_Error('unauthorized', __('Onvoldoende rechten.', 'stride'))],
            ];
        }

        if ($count <= 0 || $count > 1000) {
            return [
                'batch_id' => '',
                'created' => [],
                'errors' => [new WP_Error('invalid_count', __('Aantal moet tussen 1 en 1000 zijn.', 'stride'))],
            ];
        }

        $batchId = sprintf('BATCH-%s-%s', date('Ymd-His'), strtoupper(wp_generate_password(4, false)));
        $data['batch_id'] = $batchId;

        // Pre-generate unique codes (memory-efficient)
        $codes = $this->generateUniqueCodes($count);

        $created = [];
        $errors = [];

        foreach ($codes as $code) {
            $voucherData = $data;
            $voucherData['code'] = $code;

            $result = $this->createVoucher($voucherData);

            if (is_wp_error($result)) {
                $errors[] = $result;
            } else {
                $created[] = $result;
            }
        }

        do_action('stride/voucher/batch_created', $batchId, $created, $data);

        return [
            'batch_id' => $batchId,
            'created' => $created,
            'errors' => $errors,
        ];
    }

    /**
     * Get voucher data as array
     */
    public function getVoucher(int $voucherId): ?array
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $post = $model->find($voucherId);
        if (is_wp_error($post) || !$post) {
            return null;
        }

        return $this->formatVoucher($post);
    }

    /**
     * Get voucher by code
     */
    public function getVoucherByCode(string $code): ?array
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $code = strtoupper(trim($code));
        $post = $model
            ->where(self::FIELD_CODE, $code)
            ->withMeta()
            ->limit(1)
            ->first();

        if (!$post) {
            return null;
        }

        return $this->formatVoucherFromQuery($post);
    }

    /**
     * Format voucher post object to array
     */
    private function formatVoucher(object $post): array
    {
        return [
            'id' => (int) $post->ID,
            'code' => $post->fields[self::FIELD_CODE] ?? '',
            'type' => $post->fields[self::FIELD_TYPE] ?? self::TYPE_SINGLE,
            'usage_limit' => (int) ($post->fields[self::FIELD_USAGE_LIMIT] ?? 1),
            'used_count' => (int) ($post->fields[self::FIELD_USED_COUNT] ?? 0),
            'course_id' => $this->extractSingleId($post->fields[self::FIELD_COURSE_ID] ?? 0),
            'group_id' => $this->extractSingleId($post->fields[self::FIELD_GROUP_ID] ?? 0),
            'discount_type' => $post->fields[self::FIELD_DISCOUNT_TYPE] ?? self::DISCOUNT_FULL,
            'discount_value' => (float) ($post->fields[self::FIELD_DISCOUNT_VALUE] ?? 0),
            'valid_from' => $post->fields[self::FIELD_VALID_FROM] ?? '',
            'valid_until' => $post->fields[self::FIELD_VALID_UNTIL] ?? '',
            'status' => $post->fields[self::FIELD_STATUS] ?? self::STATUS_ACTIVE,
            'batch_id' => $post->fields[self::FIELD_BATCH_ID] ?? '',
            'created_by' => (int) ($post->fields[self::FIELD_CREATED_BY] ?? 0),
            'redemptions' => $post->fields[self::FIELD_REDEMPTIONS] ?? [],
        ];
    }

    /**
     * Format voucher from query result array (avoids N+1)
     */
    private function formatVoucherFromQuery(array $post): array
    {
        return [
            'id' => (int) ($post['id'] ?? $post['ID'] ?? 0),
            'code' => $post[self::FIELD_CODE] ?? '',
            'type' => $post[self::FIELD_TYPE] ?? self::TYPE_SINGLE,
            'usage_limit' => (int) ($post[self::FIELD_USAGE_LIMIT] ?? 1),
            'used_count' => (int) ($post[self::FIELD_USED_COUNT] ?? 0),
            'course_id' => $this->extractSingleId($post[self::FIELD_COURSE_ID] ?? 0),
            'group_id' => $this->extractSingleId($post[self::FIELD_GROUP_ID] ?? 0),
            'discount_type' => $post[self::FIELD_DISCOUNT_TYPE] ?? self::DISCOUNT_FULL,
            'discount_value' => (float) ($post[self::FIELD_DISCOUNT_VALUE] ?? 0),
            'valid_from' => $post[self::FIELD_VALID_FROM] ?? '',
            'valid_until' => $post[self::FIELD_VALID_UNTIL] ?? '',
            'status' => $post[self::FIELD_STATUS] ?? self::STATUS_ACTIVE,
            'batch_id' => $post[self::FIELD_BATCH_ID] ?? '',
            'created_by' => (int) ($post[self::FIELD_CREATED_BY] ?? 0),
            'redemptions' => $post[self::FIELD_REDEMPTIONS] ?? [],
        ];
    }

    // ========================================
    // VALIDATION & REDEMPTION
    // ========================================

    /**
     * Validate a voucher code (internal use - returns detailed errors)
     *
     * Note: For API responses, use generic errors to prevent information disclosure
     */
    public function validateVoucher(string $code, int $courseId = 0, int $groupId = 0): array|WP_Error
    {
        $voucher = $this->getVoucherByCode($code);

        if (!$voucher) {
            return new WP_Error('not_found', 'Voucher not found');
        }

        // Check status
        if ($voucher['status'] !== self::STATUS_ACTIVE) {
            return new WP_Error('invalid_status', 'Invalid status: ' . $voucher['status']);
        }

        // Check usage limit
        if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) {
            return new WP_Error('exhausted', 'Usage limit reached');
        }

        // Check validity period
        $now = current_time('Y-m-d');

        if (!empty($voucher['valid_from']) && $now < $voucher['valid_from']) {
            return new WP_Error('not_yet_valid', 'Not yet valid');
        }

        if (!empty($voucher['valid_until']) && $now > $voucher['valid_until']) {
            $this->updateVoucherStatus($voucher['id'], self::STATUS_EXPIRED);
            return new WP_Error('expired', 'Expired');
        }

        // Check scope
        if ($voucher['course_id'] > 0 && $courseId > 0 && $voucher['course_id'] !== $courseId) {
            return new WP_Error('wrong_course', 'Wrong course');
        }

        if ($voucher['group_id'] > 0 && $groupId > 0 && $voucher['group_id'] !== $groupId) {
            return new WP_Error('wrong_group', 'Wrong group');
        }

        return $voucher;
    }

    /**
     * Redeem a voucher with transaction locking (prevents race conditions)
     *
     * EXCEPTION: Uses raw $wpdb for atomic transaction with row locking.
     * This prevents TOCTOU race conditions where multiple concurrent requests
     * could redeem the same voucher past its usage limit.
     */
    public function redeemVoucher(string $code, int $userId, int $courseId = 0, int $groupId = 0): array|WP_Error
    {
        global $wpdb;

        // Get voucher ID first (validation outside transaction)
        $voucher = $this->getVoucherByCode($code);
        if (!$voucher) {
            return new WP_Error('not_found', 'Voucher not found');
        }

        $voucherId = $voucher['id'];

        try {
            $wpdb->query('START TRANSACTION');

            // Lock the voucher row for update
            $lockedPost = $wpdb->get_row($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
                $voucherId
            ));

            if (!$lockedPost) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('not_found', 'Voucher not found');
            }

            // Re-fetch voucher data with lock held
            $voucher = $this->getVoucher($voucherId);
            if (!$voucher) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('not_found', 'Voucher not found');
            }

            // Re-validate with fresh data
            if ($voucher['status'] !== self::STATUS_ACTIVE) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_status', 'Voucher not active');
            }

            if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('exhausted', 'Usage limit reached');
            }

            // Check validity period
            $now = current_time('Y-m-d');
            if (!empty($voucher['valid_until']) && $now > $voucher['valid_until']) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('expired', 'Voucher expired');
            }

            // Check scope
            if ($voucher['course_id'] > 0 && $courseId > 0 && $voucher['course_id'] !== $courseId) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('wrong_course', 'Wrong course');
            }

            // Check if user already redeemed - use indexed lookup
            $redemptions = $voucher['redemptions'] ?? [];
            $redemptionsByUser = array_column($redemptions, null, 'user_id');
            if (isset($redemptionsByUser[$userId])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('already_redeemed', __('Je hebt deze voucher al gebruikt.', 'stride'));
            }

            // Calculate discount
            $discount = $this->calculateDiscount($voucher, $courseId);

            // Add redemption record
            $redemptions[] = [
                'user_id' => $userId,
                'course_id' => $courseId,
                'group_id' => $groupId,
                'discount' => $discount,
                'redeemed_at' => current_time('mysql'),
            ];

            $newUsedCount = $voucher['used_count'] + 1;
            $newStatus = $voucher['status'];

            // Check if exhausted
            if ($voucher['usage_limit'] > 0 && $newUsedCount >= $voucher['usage_limit']) {
                $newStatus = self::STATUS_EXHAUSTED;
            }

            // Update via DataManager (still within transaction)
            $model = $this->getModel();
            if (!$model) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('no_model', 'DataManager not available');
            }

            $result = $model->update($voucherId, [
                self::FIELD_USED_COUNT => $newUsedCount,
                self::FIELD_REDEMPTIONS => $redemptions,
                self::FIELD_STATUS => $newStatus,
            ]);

            if (is_wp_error($result)) {
                $wpdb->query('ROLLBACK');
                return $result;
            }

            $wpdb->query('COMMIT');

            do_action('stride/voucher/redeemed', $voucherId, $userId, $courseId, $discount);

            return [
                'discount' => $discount,
                'voucher_id' => $voucherId,
            ];

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('Stride: Voucher redemption failed: ' . $e->getMessage());
            return new WP_Error('transaction_failed', 'Transaction failed');
        }
    }

    /**
     * Calculate discount amount for a voucher
     *
     * @param array $voucher Voucher data
     * @param int $courseId Course ID for price lookup
     * @param float|null $coursePrice Pre-fetched course price (avoids extra query)
     */
    public function calculateDiscount(array $voucher, int $courseId = 0, ?float $coursePrice = null): float
    {
        if ($coursePrice === null && $courseId > 0) {
            $coursePrice = $this->courseService->getCoursePrice($courseId) ?? 0.0;
        }
        $coursePrice = $coursePrice ?? 0.0;

        return match ($voucher['discount_type']) {
            self::DISCOUNT_FULL => $coursePrice,
            self::DISCOUNT_FIXED => min($voucher['discount_value'], $coursePrice),
            self::DISCOUNT_PERCENTAGE => round($coursePrice * ($voucher['discount_value'] / 100), 2),
            default => 0.0,
        };
    }

    /**
     * Update voucher status
     */
    public function updateVoucherStatus(int $voucherId, string $status): true|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', 'DataManager not available');
        }

        $validStatuses = [self::STATUS_ACTIVE, self::STATUS_EXHAUSTED, self::STATUS_EXPIRED, self::STATUS_DISABLED];
        if (!in_array($status, $validStatuses, true)) {
            return new WP_Error('invalid_status', 'Invalid status');
        }

        $result = $model->update($voucherId, [
            self::FIELD_STATUS => $status,
        ]);

        return is_wp_error($result) ? $result : true;
    }

    // ========================================
    // QUERY METHODS (N+1 optimized)
    // ========================================

    /**
     * Get vouchers by batch ID (N+1 optimized)
     */
    public function getVouchersByBatch(string $batchId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->where(self::FIELD_BATCH_ID, $batchId)
            ->orderBy('date', 'DESC')
            ->withMeta()
            ->limit(1000)
            ->get();

        // Use data from query directly (avoids N+1)
        return array_map(fn($post) => $this->formatVoucherFromQuery($post), $posts);
    }

    /**
     * Get vouchers by status (N+1 optimized)
     */
    public function getVouchersByStatus(string $status, int $limit = 100): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->where(self::FIELD_STATUS, $status)
            ->orderBy('date', 'DESC')
            ->withMeta()
            ->limit($limit)
            ->get();

        return array_map(fn($post) => $this->formatVoucherFromQuery($post), $posts);
    }

    /**
     * Get user's redemption history
     *
     * Note: For high-volume systems, consider a separate redemptions table
     * indexed by user_id for O(1) lookups instead of O(n*m) scan.
     */
    public function getUserRedemptions(int $userId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        // Get vouchers with meta in single query
        $posts = $model
            ->orderBy('date', 'DESC')
            ->withMeta()
            ->limit(500)
            ->get();

        $userRedemptions = [];

        foreach ($posts as $post) {
            $voucher = $this->formatVoucherFromQuery($post);
            $redemptions = $voucher['redemptions'] ?? [];

            // Index redemptions by user_id for O(1) lookup
            $redemptionsByUser = array_column($redemptions, null, 'user_id');

            if (isset($redemptionsByUser[$userId])) {
                $userRedemptions[] = [
                    'voucher' => $voucher,
                    'redemption' => $redemptionsByUser[$userId],
                ];
            }
        }

        return $userRedemptions;
    }

    // ========================================
    // CODE GENERATION (Optimized)
    // ========================================

    /**
     * Generate a unique voucher code
     */
    public function generateCode(string $prefix = 'VAD'): string
    {
        $maxAttempts = 10;
        $attempt = 0;

        do {
            $code = sprintf(
                '%s-%s-%s',
                strtoupper($prefix),
                strtoupper(wp_generate_password(4, false)),
                strtoupper(wp_generate_password(4, false))
            );

            $exists = $this->getVoucherByCode($code);
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);

        return $code;
    }

    /**
     * Generate multiple unique codes with bulk uniqueness check
     *
     * Performance: Single DB query to check all existing codes instead of N queries
     */
    public function generateUniqueCodes(int $count, string $prefix = 'VAD'): array
    {
        $model = $this->getModel();

        // Get all existing codes in one query
        $existingCodes = [];
        if ($model) {
            $posts = $model->withMeta()->limit(100000)->get();
            foreach ($posts as $post) {
                $code = $post[self::FIELD_CODE] ?? '';
                if ($code) {
                    $existingCodes[$code] = true;
                }
            }
        }

        $codes = [];
        $maxAttempts = $count * 10; // Safety limit
        $attempts = 0;

        while (count($codes) < $count && $attempts < $maxAttempts) {
            $code = sprintf(
                '%s-%s-%s',
                strtoupper($prefix),
                strtoupper(wp_generate_password(4, false)),
                strtoupper(wp_generate_password(4, false))
            );

            // Check against both existing DB codes AND newly generated codes
            if (!isset($existingCodes[$code]) && !isset($codes[$code])) {
                $codes[$code] = true;
            }

            $attempts++;
        }

        return array_keys($codes);
    }

    // ========================================
    // MAINTENANCE
    // ========================================

    /**
     * Expire vouchers past their valid_until date (N+1 optimized)
     */
    public function expireVouchers(): int
    {
        $model = $this->getModel();
        if (!$model) {
            return 0;
        }

        $today = current_time('Y-m-d');
        $expired = 0;

        $posts = $model
            ->where(self::FIELD_STATUS, self::STATUS_ACTIVE)
            ->orderBy('date', 'ASC')
            ->withMeta()
            ->limit(500)
            ->get();

        foreach ($posts as $post) {
            $voucher = $this->formatVoucherFromQuery($post);

            if (!empty($voucher['valid_until']) && $voucher['valid_until'] < $today) {
                $result = $this->updateVoucherStatus($voucher['id'], self::STATUS_EXPIRED);
                if (!is_wp_error($result)) {
                    $expired++;
                }
            }
        }

        return $expired;
    }
}
