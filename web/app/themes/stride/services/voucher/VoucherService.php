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
 * Available hooks:
 * - stride/voucher/created (action) - After voucher creation
 * - stride/voucher/redeemed (action) - After voucher redemption
 * - stride/voucher/batch_created (action) - After batch creation
 *
 * API Endpoints (via ntdst/api_data):
 * - stride_voucher_validate - Validate a voucher code
 * - stride_voucher_redeem - Redeem a voucher
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

        // Register CPT via DataManager
        add_action('init', [$this, 'registerModel'], 5);

        // Register API endpoints
        add_action('init', [$this, 'registerApiEndpoints'], 10);
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

            // Field schema
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
                    'label' => __('Cursus', 'stride'),
                    'description' => __('Laat leeg voor alle cursussen', 'stride'),
                ],
                self::FIELD_GROUP_ID => [
                    'type' => 'integer',
                    'label' => __('Traject', 'stride'),
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
                    'description' => __('Bedrag of percentage', 'stride'),
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
                ],
            ],

            // Tabbed metabox for admin
            'field_groups' => [
                'general' => [
                    'title' => __('Algemeen', 'stride'),
                    'fields' => [self::FIELD_CODE, self::FIELD_TYPE, self::FIELD_STATUS],
                ],
                'usage' => [
                    'title' => __('Gebruik', 'stride'),
                    'fields' => [self::FIELD_USAGE_LIMIT, self::FIELD_USED_COUNT, self::FIELD_REDEMPTIONS],
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
    // API ENDPOINTS
    // ========================================

    /**
     * API: Validate a voucher code
     */
    public function apiValidateVoucher($data, $params): array|WP_Error
    {
        $code = sanitize_text_field($params['code'] ?? '');
        $courseId = absint($params['course_id'] ?? 0);
        $groupId = absint($params['group_id'] ?? 0);

        if (empty($code)) {
            return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'), ['status' => 400]);
        }

        $validation = $this->validateVoucher($code, $courseId, $groupId);

        if (is_wp_error($validation)) {
            return $validation;
        }

        return [
            'success' => true,
            'valid' => true,
            'voucher' => $validation,
            'discount' => $this->calculateDiscount($validation, $courseId),
        ];
    }

    /**
     * API: Redeem a voucher code
     */
    public function apiRedeemVoucher($data, $params): array|WP_Error
    {
        $code = sanitize_text_field($params['code'] ?? '');
        $courseId = absint($params['course_id'] ?? 0);
        $groupId = absint($params['group_id'] ?? 0);
        $userId = get_current_user_id();

        if (!$userId) {
            return new WP_Error('unauthorized', __('Niet ingelogd.', 'stride'), ['status' => 401]);
        }

        if (empty($code)) {
            return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'), ['status' => 400]);
        }

        $result = $this->redeemVoucher($code, $userId, $courseId, $groupId);

        if (is_wp_error($result)) {
            return $result;
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
     * @param array{
     *   code?: string,
     *   type?: string,
     *   usage_limit?: int,
     *   course_id?: int,
     *   group_id?: int,
     *   discount_type?: string,
     *   discount_value?: float,
     *   valid_from?: string,
     *   valid_until?: string
     * } $data Voucher data
     * @return int|WP_Error Voucher post ID or error
     */
    public function createVoucher(array $data = []): int|WP_Error
    {
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
     * @param int $count Number of vouchers to create
     * @param array $data Base voucher data for all vouchers
     * @return array{batch_id: string, created: int[], errors: WP_Error[]}
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

        $created = [];
        $errors = [];

        for ($i = 0; $i < $count; $i++) {
            // Remove code to generate unique for each
            $voucherData = $data;
            unset($voucherData['code']);

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
     *
     * @param int $voucherId Voucher post ID
     * @return array|null Voucher data or null
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
     *
     * @param string $code Voucher code
     * @return array|null Voucher data or null
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
            ->limit(1)
            ->first();

        if (!$post) {
            return null;
        }

        return $this->formatVoucher($post);
    }

    /**
     * Format voucher post to array
     */
    private function formatVoucher(object $post): array
    {
        return [
            'id' => (int) $post->ID,
            'code' => $post->fields[self::FIELD_CODE] ?? '',
            'type' => $post->fields[self::FIELD_TYPE] ?? self::TYPE_SINGLE,
            'usage_limit' => (int) ($post->fields[self::FIELD_USAGE_LIMIT] ?? 1),
            'used_count' => (int) ($post->fields[self::FIELD_USED_COUNT] ?? 0),
            'course_id' => (int) ($post->fields[self::FIELD_COURSE_ID] ?? 0),
            'group_id' => (int) ($post->fields[self::FIELD_GROUP_ID] ?? 0),
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

    // ========================================
    // VALIDATION & REDEMPTION
    // ========================================

    /**
     * Validate a voucher code
     *
     * @param string $code Voucher code
     * @param int $courseId Course ID (optional, for scope check)
     * @param int $groupId Group ID (optional, for scope check)
     * @return array|WP_Error Voucher data or error
     */
    public function validateVoucher(string $code, int $courseId = 0, int $groupId = 0): array|WP_Error
    {
        $voucher = $this->getVoucherByCode($code);

        if (!$voucher) {
            return new WP_Error('not_found', __('Ongeldige vouchercode.', 'stride'));
        }

        // Check status
        if ($voucher['status'] !== self::STATUS_ACTIVE) {
            $statusMessage = match ($voucher['status']) {
                self::STATUS_EXHAUSTED => __('Deze voucher is opgebruikt.', 'stride'),
                self::STATUS_EXPIRED => __('Deze voucher is verlopen.', 'stride'),
                self::STATUS_DISABLED => __('Deze voucher is uitgeschakeld.', 'stride'),
                default => __('Ongeldige voucher status.', 'stride'),
            };
            return new WP_Error('invalid_status', $statusMessage);
        }

        // Check usage limit
        if ($voucher['usage_limit'] > 0 && $voucher['used_count'] >= $voucher['usage_limit']) {
            return new WP_Error('exhausted', __('Deze voucher is opgebruikt.', 'stride'));
        }

        // Check validity period
        $now = current_time('Y-m-d');

        if (!empty($voucher['valid_from']) && $now < $voucher['valid_from']) {
            return new WP_Error('not_yet_valid', __('Deze voucher is nog niet geldig.', 'stride'));
        }

        if (!empty($voucher['valid_until']) && $now > $voucher['valid_until']) {
            // Auto-update status to expired
            $this->updateVoucherStatus($voucher['id'], self::STATUS_EXPIRED);
            return new WP_Error('expired', __('Deze voucher is verlopen.', 'stride'));
        }

        // Check scope (course/group restriction)
        if ($voucher['course_id'] > 0 && $courseId > 0 && $voucher['course_id'] !== $courseId) {
            return new WP_Error('wrong_course', __('Deze voucher is niet geldig voor deze cursus.', 'stride'));
        }

        if ($voucher['group_id'] > 0 && $groupId > 0 && $voucher['group_id'] !== $groupId) {
            return new WP_Error('wrong_group', __('Deze voucher is niet geldig voor dit traject.', 'stride'));
        }

        return $voucher;
    }

    /**
     * Redeem a voucher
     *
     * @param string $code Voucher code
     * @param int $userId User redeeming
     * @param int $courseId Course ID (optional)
     * @param int $groupId Group ID (optional)
     * @return array{discount: float, voucher_id: int}|WP_Error
     */
    public function redeemVoucher(string $code, int $userId, int $courseId = 0, int $groupId = 0): array|WP_Error
    {
        // Validate first
        $voucher = $this->validateVoucher($code, $courseId, $groupId);
        if (is_wp_error($voucher)) {
            return $voucher;
        }

        // Check if user already redeemed this voucher
        $redemptions = $voucher['redemptions'] ?? [];
        foreach ($redemptions as $redemption) {
            if (($redemption['user_id'] ?? 0) === $userId) {
                return new WP_Error('already_redeemed', __('Je hebt deze voucher al gebruikt.', 'stride'));
            }
        }

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
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

        $result = $model->update($voucher['id'], [
            self::FIELD_USED_COUNT => $newUsedCount,
            self::FIELD_REDEMPTIONS => $redemptions,
            self::FIELD_STATUS => $newStatus,
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('stride/voucher/redeemed', $voucher['id'], $userId, $courseId, $discount);

        return [
            'discount' => $discount,
            'voucher_id' => $voucher['id'],
        ];
    }

    /**
     * Calculate discount amount for a voucher
     *
     * @param array $voucher Voucher data
     * @param int $courseId Course ID for price lookup
     * @return float Discount amount
     */
    public function calculateDiscount(array $voucher, int $courseId = 0): float
    {
        // Get course price if we need it
        $coursePrice = 0.0;
        if ($courseId > 0) {
            $coursePrice = $this->courseService->getCoursePrice($courseId) ?? 0.0;
        }

        return match ($voucher['discount_type']) {
            self::DISCOUNT_FULL => $coursePrice,
            self::DISCOUNT_FIXED => min($voucher['discount_value'], $coursePrice),
            self::DISCOUNT_PERCENTAGE => round($coursePrice * ($voucher['discount_value'] / 100), 2),
            default => 0.0,
        };
    }

    /**
     * Update voucher status
     *
     * @param int $voucherId Voucher ID
     * @param string $status New status
     * @return true|WP_Error
     */
    public function updateVoucherStatus(int $voucherId, string $status): true|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $validStatuses = [self::STATUS_ACTIVE, self::STATUS_EXHAUSTED, self::STATUS_EXPIRED, self::STATUS_DISABLED];
        if (!in_array($status, $validStatuses, true)) {
            return new WP_Error('invalid_status', __('Ongeldige status.', 'stride'));
        }

        $result = $model->update($voucherId, [
            self::FIELD_STATUS => $status,
        ]);

        return is_wp_error($result) ? $result : true;
    }

    // ========================================
    // QUERY METHODS
    // ========================================

    /**
     * Get vouchers by batch ID
     *
     * @param string $batchId Batch ID
     * @return array Array of voucher data
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

        return array_map(fn($post) => $this->getVoucher((int) $post['id']), $posts);
    }

    /**
     * Get vouchers by status
     *
     * @param string $status Voucher status
     * @param int $limit Max results
     * @return array Array of voucher data
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

        return array_map(fn($post) => $this->getVoucher((int) $post['id']), $posts);
    }

    /**
     * Get user's redemption history
     *
     * @param int $userId User ID
     * @return array Array of redemption records with voucher data
     */
    public function getUserRedemptions(int $userId): array
    {
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        // Get all vouchers with redemptions
        // Note: This is not the most efficient query, but redemptions are stored as JSON
        // For high volume, consider a separate redemptions table
        $posts = $model
            ->orderBy('date', 'DESC')
            ->withMeta()
            ->limit(500)
            ->get();

        $userRedemptions = [];

        foreach ($posts as $post) {
            $voucher = $this->getVoucher((int) $post['id']);
            if (!$voucher) {
                continue;
            }

            foreach ($voucher['redemptions'] as $redemption) {
                if (($redemption['user_id'] ?? 0) === $userId) {
                    $userRedemptions[] = [
                        'voucher' => $voucher,
                        'redemption' => $redemption,
                    ];
                }
            }
        }

        return $userRedemptions;
    }

    // ========================================
    // CODE GENERATION
    // ========================================

    /**
     * Generate a unique voucher code
     *
     * Format: VAD-XXXX-XXXX (where X is alphanumeric)
     *
     * @param string $prefix Optional prefix (default: VAD)
     * @return string Unique voucher code
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

            // Check uniqueness
            $exists = $this->getVoucherByCode($code);
            $attempt++;
        } while ($exists && $attempt < $maxAttempts);

        return $code;
    }

    /**
     * Generate multiple unique codes
     *
     * @param int $count Number of codes to generate
     * @param string $prefix Optional prefix
     * @return array Array of unique codes
     */
    public function generateCodes(int $count, string $prefix = 'VAD'): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = $this->generateCode($prefix);
        }

        return $codes;
    }

    // ========================================
    // MAINTENANCE
    // ========================================

    /**
     * Expire vouchers past their valid_until date
     *
     * Run via WP-Cron or Action Scheduler
     *
     * @return int Number of vouchers expired
     */
    public function expireVouchers(): int
    {
        $model = $this->getModel();
        if (!$model) {
            return 0;
        }

        $today = current_time('Y-m-d');
        $expired = 0;

        // Get active vouchers with valid_until set
        $posts = $model
            ->where(self::FIELD_STATUS, self::STATUS_ACTIVE)
            ->orderBy('date', 'ASC')
            ->withMeta()
            ->limit(500)
            ->get();

        foreach ($posts as $post) {
            $voucher = $this->getVoucher((int) $post['id']);
            if (!$voucher) {
                continue;
            }

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
