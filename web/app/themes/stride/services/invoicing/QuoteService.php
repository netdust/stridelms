<?php

namespace stride\services\invoicing;

defined('ABSPATH') || exit;

use stride\services\core\CourseService;
use stride\services\core\SubscriberService;
use stride\services\voucher\VoucherService;
use WP_Error;

/**
 * Quote Service
 *
 * Main orchestrator for quote creation and management.
 * Uses NTDST Data Manager for all database operations.
 *
 * Available hooks:
 * - stride/quote/created (action) - After quote creation
 * - stride/quote/updated (action) - After quote update
 * - stride/quote/sent (action) - After quote marked as sent
 * - stride/quote/exported (action) - After quote marked as exported
 *
 * API Endpoints (via ntdst/api_data):
 * - stride_quote_get - Get quote by ID
 * - stride_quote_update - Update quote billing/order data
 * - stride_quote_list - List user's quotes
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

    // Field names (used in DataManager schema)
    public const FIELD_USER_ID = 'user_id';
    public const FIELD_COURSE_ID = 'course_id';
    public const FIELD_STATUS = 'status';
    public const FIELD_QUOTE_NUMBER = 'quote_number';
    public const FIELD_ITEMS = 'items';
    public const FIELD_SUBTOTAL = 'subtotal';
    public const FIELD_TAX = 'tax';
    public const FIELD_TOTAL = 'total';
    public const FIELD_VALID_UNTIL = 'valid_until';
    public const FIELD_BILLING = 'billing';
    public const FIELD_ORDER_NUMBER = 'order_number';
    public const FIELD_VOUCHER_CODE = 'voucher_code';
    public const FIELD_PDF_PATH = 'pdf_path';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_SENT_AT = 'sent_at';
    public const FIELD_EXPORTED_AT = 'exported_at';
    public const FIELD_DISCOUNT = 'discount';

    private ?CourseService $courseService;
    private ?SubscriberService $subscriberService;
    private ?VATValidator $vatValidator;
    private ?VoucherService $voucherService;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Quote Service',
            'description' => 'Quote CPT, CRUD, and status management via NTDST DataManager',
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
        ?VATValidator $vatValidator = null,
        ?VoucherService $voucherService = null
    ) {
        $this->courseService = $courseService ?? $this->resolveService(CourseService::class);
        $this->subscriberService = $subscriberService ?? $this->resolveService(SubscriberService::class);
        $this->vatValidator = $vatValidator ?? new VATValidator();
        $this->voucherService = $voucherService ?? $this->resolveService(VoucherService::class);

        // Register CPT via DataManager
        add_action('init', [$this, 'registerModel'], 5);

        // Register API endpoints
        add_action('init', [$this, 'registerApiEndpoints'], 10);

        // Hook into enrollment completion
        add_action('stride/enrollment/completed', [$this, 'handleEnrollmentCompleted'], 10, 3);
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
     * Register vad_quote model via NTDST DataManager
     */
    public function registerModel(): void
    {
        if (!function_exists('ntdst_data')) {
            // Fallback to raw CPT registration if DataManager not available
            $this->registerPostTypeFallback();
            return;
        }

        ntdst_data()->register(self::POST_TYPE, [
            'label' => __('Offertes', 'stride'),
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
            'menu_icon' => 'dashicons-media-text',

            // Field schema with types and validation
            'fields' => [
                self::FIELD_USER_ID => [
                    'type' => 'integer',
                    'required' => true,
                    'label' => __('Gebruiker ID', 'stride'),
                ],
                self::FIELD_COURSE_ID => [
                    'type' => 'integer',
                    'required' => true,
                    'label' => __('Cursus ID', 'stride'),
                ],
                self::FIELD_STATUS => [
                    'type' => 'select',
                    'options' => [
                        self::STATUS_DRAFT => __('Concept', 'stride'),
                        self::STATUS_SENT => __('Verzonden', 'stride'),
                        self::STATUS_EXPORTED => __('Geëxporteerd', 'stride'),
                    ],
                    'default' => self::STATUS_DRAFT,
                    'label' => __('Status', 'stride'),
                ],
                self::FIELD_QUOTE_NUMBER => [
                    'type' => 'text',
                    'required' => true,
                    'label' => __('Offertenummer', 'stride'),
                ],
                self::FIELD_ITEMS => [
                    'type' => 'json',
                    'label' => __('Regelitems', 'stride'),
                ],
                self::FIELD_SUBTOTAL => [
                    'type' => 'float',
                    'min' => 0,
                    'label' => __('Subtotaal', 'stride'),
                ],
                self::FIELD_TAX => [
                    'type' => 'float',
                    'min' => 0,
                    'label' => __('BTW', 'stride'),
                ],
                self::FIELD_TOTAL => [
                    'type' => 'float',
                    'min' => 0,
                    'label' => __('Totaal', 'stride'),
                ],
                self::FIELD_VALID_UNTIL => [
                    'type' => 'text',
                    'label' => __('Geldig tot', 'stride'),
                ],
                self::FIELD_BILLING => [
                    'type' => 'json',
                    'label' => __('Factuurgegevens', 'stride'),
                ],
                self::FIELD_ORDER_NUMBER => [
                    'type' => 'text',
                    'label' => __('Bestelnummer', 'stride'),
                ],
                self::FIELD_VOUCHER_CODE => [
                    'type' => 'text',
                    'label' => __('Vouchercode', 'stride'),
                ],
                self::FIELD_PDF_PATH => [
                    'type' => 'text',
                    'label' => __('PDF pad', 'stride'),
                ],
                self::FIELD_CREATED_AT => [
                    'type' => 'text',
                    'label' => __('Aangemaakt op', 'stride'),
                ],
                self::FIELD_SENT_AT => [
                    'type' => 'text',
                    'label' => __('Verzonden op', 'stride'),
                ],
                self::FIELD_EXPORTED_AT => [
                    'type' => 'text',
                    'label' => __('Geëxporteerd op', 'stride'),
                ],
                self::FIELD_DISCOUNT => [
                    'type' => 'float',
                    'min' => 0,
                    'label' => __('Korting', 'stride'),
                ],
            ],

            // Tabbed metabox for admin
            'field_groups' => [
                'general' => [
                    'title' => __('Algemeen', 'stride'),
                    'fields' => [self::FIELD_QUOTE_NUMBER, self::FIELD_STATUS, self::FIELD_USER_ID, self::FIELD_COURSE_ID],
                ],
                'amounts' => [
                    'title' => __('Bedragen', 'stride'),
                    'fields' => [self::FIELD_SUBTOTAL, self::FIELD_DISCOUNT, self::FIELD_TAX, self::FIELD_TOTAL, self::FIELD_ITEMS],
                ],
                'billing' => [
                    'title' => __('Facturatie', 'stride'),
                    'fields' => [self::FIELD_BILLING, self::FIELD_ORDER_NUMBER, self::FIELD_VOUCHER_CODE],
                ],
                'dates' => [
                    'title' => __('Datums', 'stride'),
                    'fields' => [self::FIELD_VALID_UNTIL, self::FIELD_CREATED_AT, self::FIELD_SENT_AT, self::FIELD_EXPORTED_AT],
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
                'name' => __('Offertes', 'stride'),
                'singular_name' => __('Offerte', 'stride'),
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
        // Get single quote
        add_filter('ntdst/api_data/stride_quote_get', [$this, 'apiGetQuote'], 10, 2);

        // Update quote
        add_filter('ntdst/api_data/stride_quote_update', [$this, 'apiUpdateQuote'], 10, 2);

        // List user's quotes
        add_filter('ntdst/api_data/stride_quote_list', [$this, 'apiListQuotes'], 10, 2);
    }

    /**
     * Get the Data Model for quotes
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
     * API: Get quote by ID
     */
    public function apiGetQuote($data, $params): array|WP_Error
    {
        $quoteId = absint($params['id'] ?? 0);

        if (!$quoteId) {
            return new WP_Error('invalid_input', __('Offerte ID is vereist.', 'stride'), ['status' => 400]);
        }

        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('not_found', __('Offerte niet gevonden.', 'stride'), ['status' => 404]);
        }

        // Check permission: owner or admin
        $userId = get_current_user_id();
        if (!current_user_can('manage_options') && $userId !== $quote['user_id']) {
            return new WP_Error('forbidden', __('Geen toegang tot deze offerte.', 'stride'), ['status' => 403]);
        }

        return [
            'success' => true,
            'quote' => $quote,
        ];
    }

    /**
     * API: Update quote
     */
    public function apiUpdateQuote($data, $params): array|WP_Error
    {
        $quoteId = absint($params['id'] ?? 0);

        if (!$quoteId) {
            return new WP_Error('invalid_input', __('Offerte ID is vereist.', 'stride'), ['status' => 400]);
        }

        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('not_found', __('Offerte niet gevonden.', 'stride'), ['status' => 404]);
        }

        // Check permission: owner or admin
        $userId = get_current_user_id();
        if (!current_user_can('manage_options') && $userId !== $quote['user_id']) {
            return new WP_Error('forbidden', __('Geen toegang tot deze offerte.', 'stride'), ['status' => 403]);
        }

        // Sanitize update data
        $updateData = [];
        if (isset($params['company'])) {
            $updateData['company'] = sanitize_text_field($params['company']);
        }
        if (isset($params['address'])) {
            $updateData['address'] = sanitize_text_field($params['address']);
        }
        if (isset($params['city'])) {
            $updateData['city'] = sanitize_text_field($params['city']);
        }
        if (isset($params['postal_code'])) {
            $updateData['postal_code'] = sanitize_text_field($params['postal_code']);
        }
        if (isset($params['vat_number'])) {
            $updateData['vat_number'] = sanitize_text_field($params['vat_number']);
        }
        if (isset($params['gln_number'])) {
            $updateData['gln_number'] = sanitize_text_field($params['gln_number']);
        }
        if (isset($params['order_number'])) {
            $updateData['order_number'] = sanitize_text_field($params['order_number']);
        }

        $result = $this->updateQuote($quoteId, $updateData);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'success' => true,
            'message' => __('Offerte bijgewerkt.', 'stride'),
            'quote' => $this->getQuote($quoteId),
        ];
    }

    /**
     * API: List user's quotes
     */
    public function apiListQuotes($data, $params): array|WP_Error
    {
        $userId = get_current_user_id();

        if (!$userId) {
            return new WP_Error('unauthorized', __('Niet ingelogd.', 'stride'), ['status' => 401]);
        }

        $status = isset($params['status']) ? sanitize_text_field($params['status']) : null;
        $quotes = $this->getUserQuotes($userId, $status);

        return [
            'success' => true,
            'quotes' => $quotes,
            'count' => count($quotes),
        ];
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

        // Validate course via CourseService
        $courseValidation = $this->courseService->validateCourse($courseId);
        if (is_wp_error($courseValidation)) {
            return $courseValidation;
        }

        $courseTitle = $this->courseService->getCourseTitle($courseId);

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

        // Apply voucher discount if provided
        $discount = 0.0;
        $voucherCode = sanitize_text_field($data['voucher_code'] ?? '');
        if (!empty($voucherCode)) {
            $discount = $this->calculateVoucherDiscount($voucherCode, $courseId);
        }

        $discountedSubtotal = max(0, $subtotal - $discount);
        $tax = round($discountedSubtotal * ($taxRate / 100), 2);
        $total = $discountedSubtotal + $tax;

        // Build items array
        $items = [
            [
                'id' => $courseId,
                'type' => 'course',
                'title' => $courseTitle,
                'quantity' => 1,
                'unit_price' => $coursePrice,
                'total' => $coursePrice,
            ],
        ];

        // Add discount line if applicable
        if ($discount > 0) {
            $items[] = [
                'id' => 0,
                'type' => 'discount',
                'title' => sprintf(__('Korting (voucher: %s)', 'stride'), $voucherCode),
                'quantity' => 1,
                'unit_price' => -$discount,
                'total' => -$discount,
            ];
        }

        // Calculate valid until date
        $validDays = $this->getConfig('valid_days', 30);
        $validUntil = date('Y-m-d', strtotime("+{$validDays} days"));

        // Use DataManager to create quote
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $result = $model->create([
            'title' => $quoteNumber,
            'status' => 'publish',
            self::FIELD_USER_ID => $userId,
            self::FIELD_COURSE_ID => $courseId,
            self::FIELD_STATUS => self::STATUS_DRAFT,
            self::FIELD_QUOTE_NUMBER => $quoteNumber,
            self::FIELD_ITEMS => $items,
            self::FIELD_SUBTOTAL => $subtotal,
            self::FIELD_DISCOUNT => $discount,
            self::FIELD_TAX => $tax,
            self::FIELD_TOTAL => $total,
            self::FIELD_VALID_UNTIL => $validUntil,
            self::FIELD_BILLING => $billing,
            self::FIELD_ORDER_NUMBER => sanitize_text_field($data['order_number'] ?? ''),
            self::FIELD_VOUCHER_CODE => $voucherCode,
            self::FIELD_CREATED_AT => current_time('mysql'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        $quoteId = $result->ID;

        // Create CRM note
        $this->subscriberService->createNote(
            $userId,
            sprintf(__('Offerte %s aangemaakt voor: %s', 'stride'), $quoteNumber, $courseTitle)
        );

        // Fire hook
        do_action('stride/quote/created', $quoteId, $userId, $courseId);

        return $quoteId;
    }

    /**
     * Get quote data as array
     *
     * @param int $quoteId Quote post ID
     * @return array|null Quote data or null if not found
     */
    public function getQuote(int $quoteId): ?array
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $post = $model->find($quoteId);
        if (is_wp_error($post) || !$post) {
            return null;
        }

        // Convert WP_Post with meta to our array format
        return [
            'id' => $post->ID,
            'number' => $post->fields[self::FIELD_QUOTE_NUMBER] ?? '',
            'status' => $post->fields[self::FIELD_STATUS] ?? self::STATUS_DRAFT,
            'user_id' => (int) ($post->fields[self::FIELD_USER_ID] ?? 0),
            'course_id' => (int) ($post->fields[self::FIELD_COURSE_ID] ?? 0),
            'items' => $post->fields[self::FIELD_ITEMS] ?? [],
            'subtotal' => (float) ($post->fields[self::FIELD_SUBTOTAL] ?? 0),
            'discount' => (float) ($post->fields[self::FIELD_DISCOUNT] ?? 0),
            'tax' => (float) ($post->fields[self::FIELD_TAX] ?? 0),
            'total' => (float) ($post->fields[self::FIELD_TOTAL] ?? 0),
            'valid_until' => $post->fields[self::FIELD_VALID_UNTIL] ?? '',
            'billing' => $post->fields[self::FIELD_BILLING] ?? [],
            'order_number' => $post->fields[self::FIELD_ORDER_NUMBER] ?? '',
            'voucher_code' => $post->fields[self::FIELD_VOUCHER_CODE] ?? '',
            'pdf_path' => $post->fields[self::FIELD_PDF_PATH] ?? '',
            'created_at' => $post->fields[self::FIELD_CREATED_AT] ?? '',
            'sent_at' => $post->fields[self::FIELD_SENT_AT] ?? '',
            'exported_at' => $post->fields[self::FIELD_EXPORTED_AT] ?? '',
        ];
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

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $updateData = [];

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

            $updateData[self::FIELD_BILLING] = $billing;
        }

        // Update order number
        if (isset($data['order_number'])) {
            $updateData[self::FIELD_ORDER_NUMBER] = sanitize_text_field($data['order_number']);
        }

        // Update voucher code
        if (isset($data['voucher_code'])) {
            $updateData[self::FIELD_VOUCHER_CODE] = sanitize_text_field($data['voucher_code']);
        }

        if (empty($updateData)) {
            return true;
        }

        $result = $model->update($quoteId, $updateData);

        if (is_wp_error($result)) {
            return $result;
        }

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

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $result = $model->update($quoteId, [
            self::FIELD_STATUS => self::STATUS_SENT,
            self::FIELD_SENT_AT => current_time('mysql'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

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

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $result = $model->update($quoteId, [
            self::FIELD_STATUS => self::STATUS_EXPORTED,
            self::FIELD_EXPORTED_AT => current_time('mysql'),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fire hook
        do_action('stride/quote/exported', $quoteId, $quote);

        return true;
    }

    /**
     * Update PDF path for quote
     *
     * @param int $quoteId Quote post ID
     * @param string $path PDF file path
     * @return true|WP_Error
     */
    public function setPdfPath(int $quoteId, string $path): true|WP_Error
    {
        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $result = $model->update($quoteId, [
            self::FIELD_PDF_PATH => $path,
        ]);

        return is_wp_error($result) ? $result : true;
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
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        $quote = $model
            ->where(self::FIELD_USER_ID, $userId)
            ->where(self::FIELD_COURSE_ID, $courseId)
            ->limit(1)
            ->first();

        return $quote ? (int) $quote->id : null;
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
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $query = $model
            ->where(self::FIELD_USER_ID, $userId)
            ->orderBy('date', 'DESC')
            ->withMeta();

        if ($status) {
            $query = $query->where(self::FIELD_STATUS, $status);
        }

        $posts = $query->limit(100)->get();

        return array_map(fn($post) => $this->getQuote((int) $post['id']), $posts);
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
        $model = $this->getModel();
        if (!$model) {
            return [];
        }

        $posts = $model
            ->where(self::FIELD_STATUS, $status)
            ->orderBy('date', 'ASC')
            ->withMeta()
            ->limit($limit)
            ->get();

        return array_map(fn($post) => $this->getQuote((int) $post['id']), $posts);
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
        $emailDomain = $this->subscriberService->getUserEmailDomain($userId);
        if (!$emailDomain) {
            return false;
        }

        $skipDomains = $this->getConfig('skip_domains', ['vad.be', 'druglijn.be']);
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
     * EXCEPTION: Uses raw $wpdb for atomic MySQL transaction.
     * DataManager doesn't support MySQL transactions for counter increment.
     * This prevents race conditions in concurrent quote number generation.
     *
     * @return string Quote number (VADQ-YYYY-NNNNN)
     */
    private function generateQuoteNumber(): string
    {
        global $wpdb;

        $prefix = $this->getConfig('quote_prefix', 'VADQ');
        $year = date('Y');
        $optionName = "stride_quote_last_{$year}";

        try {
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

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('Stride: Quote number generation failed: ' . $e->getMessage());
            // Fallback to timestamp-based number to prevent blocking
            return sprintf('%s-%s-%s', $prefix, $year, strtoupper(substr(md5(microtime(true)), 0, 5)));
        }
    }

    /**
     * Get course price via CourseService
     *
     * @param int $courseId LearnDash course ID
     * @return float Price or 0
     */
    private function getCoursePrice(int $courseId): float
    {
        $price = $this->courseService->getCoursePrice($courseId);
        return $price ?? 0.0;
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
     * Calculate voucher discount amount
     *
     * @param string $voucherCode Voucher code
     * @param int $courseId Course ID
     * @return float Discount amount (0 if invalid)
     */
    private function calculateVoucherDiscount(string $voucherCode, int $courseId): float
    {
        if (!$this->voucherService) {
            return 0.0;
        }

        $voucher = $this->voucherService->validateVoucher($voucherCode, $courseId);
        if (is_wp_error($voucher)) {
            return 0.0;
        }

        return $this->voucherService->calculateDiscount($voucher, $courseId);
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
