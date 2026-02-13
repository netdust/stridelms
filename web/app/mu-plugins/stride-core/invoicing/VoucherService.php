<?php

namespace ntdst\Stride\invoicing;

defined('ABSPATH') || exit;

use WP_Error;
use ntdst\Stride\invoicing\Admin\VoucherAdminController;
use ntdst\Stride\invoicing\Helpers\VoucherCodeGenerator;

/**
 * Voucher Service
 *
 * Manages voucher codes for quotes/enrollments.
 * Uses NTDST Data Manager for all database operations.
 *
 * Security features:
 * - Transaction locking for redemption (prevents race conditions)
 * - Rate limiting on validation API (prevents brute force)
 * - Generic error messages (prevents information disclosure)
 * - Capability checks on creation
 *
 * Item type agnostic - works with any item type that provides price via filter.
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
 * @package stride\services\invoicing
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
    public const FIELD_COURSE_ID = 'course_id'; // BC: kept for legacy vouchers
    public const FIELD_ITEM_TYPE = 'item_type'; // New: generic item type restriction
    public const FIELD_ITEM_ID = 'item_id';     // New: generic item ID restriction
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
     * Constructor
     */
    public function __construct()
    {
        add_action('init', [$this, 'registerModel'], 5);
        add_action('init', [$this, 'registerApiEndpoints'], 10);

        // Initialize admin controller in admin context
        if (is_admin()) {
            add_action('init', [$this, 'initAdminController'], 15);
        }
    }

    /**
     * Initialize admin controller
     */
    public function initAdminController(): void
    {
        new VoucherAdminController($this);
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
                    'type' => 'integer',
                    'label' => __('Beperkt tot cursus (legacy)', 'stride'),
                    'description' => __('BC: Use item_type/item_id for new vouchers', 'stride'),
                ],
                self::FIELD_ITEM_TYPE => [
                    'type' => 'text',
                    'label' => __('Item type beperking', 'stride'),
                    'description' => __('Laat leeg voor alle types', 'stride'),
                ],
                self::FIELD_ITEM_ID => [
                    'type' => 'integer',
                    'label' => __('Item ID beperking', 'stride'),
                    'description' => __('0 = alle items van dit type', 'stride'),
                ],
                self::FIELD_GROUP_ID => [
                    'type' => 'integer',
                    'label' => __('Beperkt tot traject', 'stride'),
                    'description' => __('0 = alle trajecten', 'stride'),
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
                    'show_in_metabox' => false,
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
                    'fields' => [self::FIELD_ITEM_TYPE, self::FIELD_ITEM_ID, self::FIELD_GROUP_ID],
                ],
                'discount' => [
                    'title' => __('Korting', 'stride'),
                    'fields' => [self::FIELD_DISCOUNT_TYPE, self::FIELD_DISCOUNT_VALUE],
                ],
                'validity' => [
                    'title' => __('Geldigheid', 'stride'),
                    'fields' => [self::FIELD_VALID_FROM, self::FIELD_VALID_UNTIL],
                ],
            ],
            'use_tabs' => true,
            'auto_metabox' => false,
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

    private function checkRateLimit(): bool
    {
        $ip = $this->getClientIp();
        $key = 'stride_voucher_attempts_' . md5($ip);
        $attempts = (int) get_transient($key);
        return $attempts < self::RATE_LIMIT_ATTEMPTS;
    }

    private function incrementRateLimit(): void
    {
        $ip = $this->getClientIp();
        $key = 'stride_voucher_attempts_' . md5($ip);
        $attempts = (int) get_transient($key);
        set_transient($key, $attempts + 1, self::RATE_LIMIT_WINDOW);
    }

    private function extractSingleId(mixed $value): int
    {
        if (is_array($value)) {
            return (int) ($value[0] ?? 0);
        }
        return (int) $value;
    }

    private function getClientIp(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
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

    private function validationError(): WP_Error
    {
        return new WP_Error('invalid_voucher', __('Vouchercode ongeldig of verlopen.', 'stride'));
    }

    // ========================================
    // API ENDPOINTS
    // ========================================

    public function apiValidateVoucher($data, $params): array|WP_Error
    {
        if (!is_user_logged_in()) {
            return new WP_Error('unauthorized', __('Niet ingelogd.', 'stride'), ['status' => 401]);
        }

        if (!$this->checkRateLimit()) {
            return new WP_Error('rate_limited', __('Te veel pogingen. Probeer later opnieuw.', 'stride'), ['status' => 429]);
        }

        $code = sanitize_text_field($params['code'] ?? '');
        $itemType = sanitize_text_field($params['item_type'] ?? $params['course_id'] ? 'course' : '');
        $itemId = absint($params['item_id'] ?? $params['course_id'] ?? 0);

        if (empty($code)) {
            return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'), ['status' => 400]);
        }

        $validation = $this->validateVoucher($code, $itemId, 0, $itemType);
        $this->incrementRateLimit();

        if (is_wp_error($validation)) {
            return $this->validationError();
        }

        return [
            'success' => true,
            'valid' => true,
            'discount_type' => $validation['discount_type'],
            'discount' => $this->calculateDiscount($validation, $itemType, $itemId),
        ];
    }

    public function apiRedeemVoucher($data, $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('unauthorized', __('Niet ingelogd.', 'stride'), ['status' => 401]);
        }

        $code = sanitize_text_field($params['code'] ?? '');
        $itemType = sanitize_text_field($params['item_type'] ?? 'course');
        $itemId = absint($params['item_id'] ?? $params['course_id'] ?? 0);

        if (empty($code)) {
            return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'), ['status' => 400]);
        }

        $result = $this->redeemVoucher($code, $userId, $itemType, $itemId);

        if (is_wp_error($result)) {
            $errorCode = $result->get_error_code();
            if ($errorCode === 'already_redeemed') {
                return $result;
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

    public function createVoucher(array $data = []): int|WP_Error
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('unauthorized', __('Onvoldoende rechten.', 'stride'));
        }

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $code = !empty($data['code'])
            ? strtoupper(sanitize_text_field($data['code']))
            : VoucherCodeGenerator::generate('VAD', fn($c) => $this->getVoucherByCode($c) !== null);

        if ($this->getVoucherByCode($code)) {
            return new WP_Error('code_exists', __('Deze vouchercode bestaat al.', 'stride'));
        }

        $type = $data['type'] ?? self::TYPE_SINGLE;
        $usageLimit = $type === self::TYPE_SINGLE ? 1 : absint($data['usage_limit'] ?? 0);

        $itemType = $data['item_type'] ?? '';
        $itemId = absint($data['item_id'] ?? 0);

        $result = $model->create([
            'title' => $code,
            'status' => 'publish',
            self::FIELD_CODE => $code,
            self::FIELD_TYPE => $type,
            self::FIELD_USAGE_LIMIT => $usageLimit,
            self::FIELD_USED_COUNT => 0,
            self::FIELD_COURSE_ID => $itemType === 'course' ? $itemId : 0, // BC
            self::FIELD_ITEM_TYPE => $itemType,
            self::FIELD_ITEM_ID => $itemId,
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

        $existingCodes = $this->getAllExistingCodes();
        $codes = VoucherCodeGenerator::generateBatch($count, 'VAD', $existingCodes);
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
     * Get all existing voucher codes for batch generation
     *
     * @return array Existing codes as keys for O(1) lookup
     */
    private function getAllExistingCodes(): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $existingCodes = [];
        $posts = $model->withMeta()->limit(100000)->get();

        foreach ($posts as $post) {
            $code = $post['meta'][self::FIELD_CODE] ?? $post[self::FIELD_CODE] ?? '';
            if ($code) {
                $existingCodes[$code] = true;
            }
        }

        return $existingCodes;
    }

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

        return $this->formatVoucherFromQuery((array) $post);
    }

    private function formatVoucher(object $post): array
    {
        return [
            'id' => (int) $post->ID,
            'code' => $post->fields[self::FIELD_CODE] ?? '',
            'type' => $post->fields[self::FIELD_TYPE] ?? self::TYPE_SINGLE,
            'usage_limit' => (int) ($post->fields[self::FIELD_USAGE_LIMIT] ?? 1),
            'used_count' => (int) ($post->fields[self::FIELD_USED_COUNT] ?? 0),
            'course_id' => $this->extractSingleId($post->fields[self::FIELD_COURSE_ID] ?? 0), // BC
            'item_type' => $post->fields[self::FIELD_ITEM_TYPE] ?? '',
            'item_id' => $this->extractSingleId($post->fields[self::FIELD_ITEM_ID] ?? 0),
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

    private function formatVoucherFromQuery(array $post): array
    {
        $meta = $post['meta'] ?? [];
        return [
            'id' => (int) ($post['id'] ?? $post['ID'] ?? 0),
            'code' => $meta[self::FIELD_CODE] ?? '',
            'type' => $meta[self::FIELD_TYPE] ?? self::TYPE_SINGLE,
            'usage_limit' => (int) ($meta[self::FIELD_USAGE_LIMIT] ?? 1),
            'used_count' => (int) ($meta[self::FIELD_USED_COUNT] ?? 0),
            'course_id' => $this->extractSingleId($meta[self::FIELD_COURSE_ID] ?? 0), // BC
            'item_type' => $meta[self::FIELD_ITEM_TYPE] ?? '',
            'item_id' => $this->extractSingleId($meta[self::FIELD_ITEM_ID] ?? 0),
            'group_id' => $this->extractSingleId($meta[self::FIELD_GROUP_ID] ?? 0),
            'discount_type' => $meta[self::FIELD_DISCOUNT_TYPE] ?? self::DISCOUNT_FULL,
            'discount_value' => (float) ($meta[self::FIELD_DISCOUNT_VALUE] ?? 0),
            'valid_from' => $meta[self::FIELD_VALID_FROM] ?? '',
            'valid_until' => $meta[self::FIELD_VALID_UNTIL] ?? '',
            'status' => $meta[self::FIELD_STATUS] ?? self::STATUS_ACTIVE,
            'batch_id' => $meta[self::FIELD_BATCH_ID] ?? '',
            'created_by' => (int) ($meta[self::FIELD_CREATED_BY] ?? 0),
            'redemptions' => $meta[self::FIELD_REDEMPTIONS] ?? [],
        ];
    }

    // ========================================
    // VALIDATION & REDEMPTION
    // ========================================

    /**
     * Validate a voucher code
     *
     * @param string $code Voucher code
     * @param int $itemId Item ID (BC: can also be course ID)
     * @param int $groupId Group ID (optional)
     * @param string $itemType Item type (default: course for BC)
     * @return array|WP_Error Voucher data or error
     */
    public function validateVoucher(string $code, int $itemId = 0, int $groupId = 0, string $itemType = 'course'): array|WP_Error
    {
        $voucher = $this->getVoucherByCode($code);

        if (!$voucher) {
            return new WP_Error('not_found', 'Voucher not found');
        }

        if ($voucher['status'] !== self::STATUS_ACTIVE) {
            return new WP_Error('invalid_status', 'Invalid status: ' . $voucher['status']);
        }

        if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) {
            return new WP_Error('exhausted', 'Usage limit reached');
        }

        $now = current_time('Y-m-d');

        if (!empty($voucher['valid_from']) && $now < $voucher['valid_from']) {
            return new WP_Error('not_yet_valid', 'Not yet valid');
        }

        if (!empty($voucher['valid_until']) && $now > $voucher['valid_until']) {
            $this->updateVoucherStatus($voucher['id'], self::STATUS_EXPIRED);
            return new WP_Error('expired', 'Expired');
        }

        // Check item type restriction
        $voucherItemType = $voucher['item_type'] ?? '';
        if (!empty($voucherItemType) && !empty($itemType) && $voucherItemType !== $itemType) {
            return new WP_Error('wrong_item_type', 'Wrong item type');
        }

        // Check item ID restriction (supports both new item_id and legacy course_id)
        $voucherItemId = $voucher['item_id'] ?? 0;
        $voucherCourseId = $voucher['course_id'] ?? 0; // BC

        if ($voucherItemId > 0 && $itemId > 0 && $voucherItemId !== $itemId) {
            return new WP_Error('wrong_item', 'Wrong item');
        }

        // BC: Also check legacy course_id for course type
        if ($itemType === 'course' && $voucherCourseId > 0 && $itemId > 0 && $voucherCourseId !== $itemId) {
            return new WP_Error('wrong_course', 'Wrong course');
        }

        if ($voucher['group_id'] > 0 && $groupId > 0 && $voucher['group_id'] !== $groupId) {
            return new WP_Error('wrong_group', 'Wrong group');
        }

        return $voucher;
    }

    /**
     * Redeem a voucher with transaction locking
     *
     * @param string $code Voucher code
     * @param int $userId User ID
     * @param string $itemType Item type
     * @param int $itemId Item ID
     * @param int $groupId Group ID (optional)
     * @return array|WP_Error Redemption result or error
     */
    public function redeemVoucher(string $code, int $userId, string $itemType = 'course', int $itemId = 0, int $groupId = 0): array|WP_Error
    {
        global $wpdb;

        $voucher = $this->getVoucherByCode($code);
        if (!$voucher) {
            return new WP_Error('not_found', 'Voucher not found');
        }

        $voucherId = $voucher['id'];

        try {
            $wpdb->query('START TRANSACTION');

            $lockedPost = $wpdb->get_row($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE",
                $voucherId
            ));

            if (!$lockedPost) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('not_found', 'Voucher not found');
            }

            $voucher = $this->getVoucher($voucherId);
            if (!$voucher) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('not_found', 'Voucher not found');
            }

            if ($voucher['status'] !== self::STATUS_ACTIVE) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_status', 'Voucher not active');
            }

            if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('exhausted', 'Usage limit reached');
            }

            $now = current_time('Y-m-d');
            if (!empty($voucher['valid_until']) && $now > $voucher['valid_until']) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('expired', 'Voucher expired');
            }

            // Check scope restrictions
            $voucherItemType = $voucher['item_type'] ?? '';
            if (!empty($voucherItemType) && !empty($itemType) && $voucherItemType !== $itemType) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('wrong_item_type', 'Wrong item type');
            }

            $voucherItemId = $voucher['item_id'] ?? 0;
            if ($voucherItemId > 0 && $itemId > 0 && $voucherItemId !== $itemId) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('wrong_item', 'Wrong item');
            }

            $redemptions = $voucher['redemptions'] ?? [];
            $redemptionsByUser = array_column($redemptions, null, 'user_id');
            if (isset($redemptionsByUser[$userId])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('already_redeemed', __('Je hebt deze voucher al gebruikt.', 'stride'));
            }

            $discount = $this->calculateDiscount($voucher, $itemType, $itemId);

            $redemptions[] = [
                'user_id' => $userId,
                'item_type' => $itemType,
                'item_id' => $itemId,
                'course_id' => $itemType === 'course' ? $itemId : 0, // BC
                'group_id' => $groupId,
                'discount' => $discount,
                'redeemed_at' => current_time('mysql'),
            ];

            $newUsedCount = $voucher['used_count'] + 1;
            $newStatus = $voucher['status'];

            if ($voucher['usage_limit'] > 0 && $newUsedCount >= $voucher['usage_limit']) {
                $newStatus = self::STATUS_EXHAUSTED;
            }

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

            do_action('stride/voucher/redeemed', $voucherId, $userId, $itemId, $discount);

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
     * @param string $itemType Item type
     * @param int $itemId Item ID for price lookup
     * @param float|null $itemPrice Pre-fetched item price (avoids extra query)
     */
    public function calculateDiscount(array $voucher, string $itemType = 'course', int $itemId = 0, ?float $itemPrice = null): float
    {
        // Resolve price via filter if not provided
        if ($itemPrice === null && $itemId > 0) {
            $itemPrice = (float) apply_filters('stride/quote/resolve_price', 0.0, $itemType, $itemId);
        }
        $itemPrice = $itemPrice ?? 0.0;

        return match ($voucher['discount_type']) {
            self::DISCOUNT_FULL => $itemPrice,
            self::DISCOUNT_FIXED => min($voucher['discount_value'], $itemPrice),
            self::DISCOUNT_PERCENTAGE => round($itemPrice * ($voucher['discount_value'] / 100), 2),
            default => 0.0,
        };
    }

    /**
     * Calculate discount for a course (BC wrapper)
     *
     * @deprecated Use calculateDiscount() with item_type parameter
     */
    public function calculateDiscountForCourse(array $voucher, int $courseId, ?float $coursePrice = null): float
    {
        return $this->calculateDiscount($voucher, 'course', $courseId, $coursePrice);
    }

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
    // QUERY METHODS
    // ========================================

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

        return array_map(fn($post) => $this->formatVoucherFromQuery($post), $posts);
    }

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

    public function getUserRedemptions(int $userId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->orderBy('date', 'DESC')
            ->withMeta()
            ->limit(500)
            ->get();

        $userRedemptions = [];

        foreach ($posts as $post) {
            $voucher = $this->formatVoucherFromQuery($post);
            $redemptions = $voucher['redemptions'] ?? [];
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
    // MAINTENANCE
    // ========================================

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
