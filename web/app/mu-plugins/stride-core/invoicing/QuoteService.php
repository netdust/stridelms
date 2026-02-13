<?php

namespace ntdst\Stride\invoicing;

defined('ABSPATH') || exit;

use ntdst\Stride\core\SubscriberService;
use ntdst\Stride\invoicing\Support\QuoteConfig;
use ntdst\Stride\invoicing\Support\CurrencyFormatter;
use ntdst\Stride\invoicing\Helpers\QuoteItemFactory;
use ntdst\Stride\invoicing\Helpers\VATValidator;
use ntdst\Stride\invoicing\Helpers\QuotePDFGenerator;
use ntdst\Stride\invoicing\Admin\QuoteAdminController;
use WP_Error;

/**
 * Quote Service
 *
 * Main orchestrator for quote creation and management.
 * Uses NTDST Data Manager for all database operations.
 *
 * This service focuses on core business logic:
 * - CPT registration and configuration
 * - CRUD operations (create, read, update)
 * - Query methods
 * - Status transitions
 * - Enrollment handling
 * - API endpoints
 *
 * Admin UI is handled by Admin\QuoteAdminController.
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

    // Quote status workflow
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_EXPORTED = 'exported';
    public const STATUS_CANCELLED = 'cancelled';

    // Field names (used in DataManager schema)
    public const FIELD_USER_ID = 'user_id';
    public const FIELD_COURSE_ID = 'course_id'; // BC: kept for backwards compatibility
    public const FIELD_ITEM_TYPE = 'item_type'; // New: generic item type
    public const FIELD_ITEM_ID = 'item_id';     // New: generic item ID
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
    public const FIELD_NOTES = 'notes';
    public const FIELD_LOCKED = 'locked';
    public const FIELD_LAST_SENT_TO = 'last_sent_to';

    // Note types (for audit trail)
    public const NOTE_TYPE_ADMIN = 'admin';
    public const NOTE_TYPE_CUSTOMER = 'customer';

    private ?SubscriberService $subscriberService;
    private ?VATValidator $vatValidator = null;
    private ?QuotePDFGenerator $pdfGenerator = null;

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
     *
     * Note: Admin UI (metaboxes, scripts, AJAX) is handled by QuoteAdminController
     * Note: Item/price resolution uses filters - handlers provide course/product specifics
     */
    public function __construct(?SubscriberService $subscriberService = null)
    {
        $this->subscriberService = $subscriberService ?? $this->resolveService(SubscriberService::class);

        // Register CPT via DataManager
        add_action('init', [$this, 'registerModel'], 5);

        // Register API endpoints
        add_action('init', [$this, 'registerApiEndpoints'], 10);

        // Register PDF download route
        add_action('init', [$this, 'registerPdfRoute']);
        add_action('template_redirect', [$this, 'handlePdfDownload']);
        add_filter('query_vars', [$this, 'addPdfQueryVars']);

        // Register VAT revalidation hook (for async validation)
        add_action('stride/vat/revalidate', [$this, 'handleVatRevalidation']);

        // Register PDF regeneration hook
        add_action('stride/quote/regenerate_pdf', [$this, 'regeneratePdf']);

        // Initialize admin controller in admin context
        if (is_admin()) {
            add_action('init', [$this, 'initAdminController'], 15);
        }
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

    // ========================================
    // CPT REGISTRATION
    // ========================================

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

            // Field schema for ORM - metabox removed via registerMetaboxes()
            'fields' => [
                self::FIELD_USER_ID => ['type' => 'integer', 'required' => true],
                self::FIELD_COURSE_ID => ['type' => 'integer', 'required' => false], // BC: kept for legacy quotes
                self::FIELD_ITEM_TYPE => ['type' => 'text', 'default' => 'course'],
                self::FIELD_ITEM_ID => ['type' => 'integer', 'default' => 0],
                self::FIELD_STATUS => [
                    'type' => 'select',
                    'options' => [
                        self::STATUS_DRAFT => __('Concept', 'stride'),
                        self::STATUS_SENT => __('Verzonden', 'stride'),
                        self::STATUS_EXPORTED => __('Geëxporteerd', 'stride'),
                        self::STATUS_CANCELLED => __('Geannuleerd', 'stride'),
                    ],
                    'default' => self::STATUS_DRAFT,
                ],
                self::FIELD_QUOTE_NUMBER => ['type' => 'text', 'required' => true],
                self::FIELD_ITEMS => ['type' => 'json'],
                self::FIELD_SUBTOTAL => ['type' => 'float', 'min' => 0],
                self::FIELD_TAX => ['type' => 'float', 'min' => 0],
                self::FIELD_TOTAL => ['type' => 'float', 'min' => 0],
                self::FIELD_VALID_UNTIL => ['type' => 'text'],
                self::FIELD_BILLING => ['type' => 'json'],
                self::FIELD_ORDER_NUMBER => ['type' => 'text'],
                self::FIELD_VOUCHER_CODE => ['type' => 'text'],
                self::FIELD_PDF_PATH => ['type' => 'text'],
                self::FIELD_CREATED_AT => ['type' => 'text'],
                self::FIELD_SENT_AT => ['type' => 'text'],
                self::FIELD_EXPORTED_AT => ['type' => 'text'],
                self::FIELD_DISCOUNT => ['type' => 'float', 'min' => 0],
                self::FIELD_NOTES => ['type' => 'json'],
                self::FIELD_LOCKED => ['type' => 'boolean', 'default' => false],
                self::FIELD_LAST_SENT_TO => ['type' => 'text'],
            ],

            // Disable auto-generated metabox - we use custom invoice-style layout
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
                'name' => __('Offertes', 'stride'),
                'singular_name' => __('Offerte', 'stride'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'stride-admin',
            'supports' => ['title'],
        ]);
    }

    // ========================================
    // API ENDPOINTS
    // ========================================

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

    // ========================================
    // PDF ROUTES
    // ========================================

    /**
     * Register PDF download rewrite rule
     */
    public function registerPdfRoute(): void
    {
        add_rewrite_rule(
            '^quote-pdf/([0-9]+)/?$',
            'index.php?stride_quote_pdf=$matches[1]',
            'top'
        );
    }

    /**
     * Add PDF query vars
     *
     * @param array $vars Existing query vars
     * @return array Modified query vars
     */
    public function addPdfQueryVars(array $vars): array
    {
        $vars[] = 'stride_quote_pdf';
        return $vars;
    }

    /**
     * Handle PDF download request
     */
    public function handlePdfDownload(): void
    {
        $quoteId = get_query_var('stride_quote_pdf');
        if (!$quoteId) {
            return;
        }

        $this->getPdfGenerator()->servePdf((int) $quoteId);
    }

    /**
     * Regenerate PDF for a quote
     *
     * @param int $quoteId Quote post ID
     */
    public function regeneratePdf(int $quoteId): void
    {
        $this->getPdfGenerator()->generate($quoteId, true);
    }

    /**
     * Get download URL for a quote PDF
     *
     * @param int $quoteId Quote post ID
     * @return string Download URL
     */
    public function getPdfDownloadUrl(int $quoteId): string
    {
        return home_url('/quote-pdf/' . $quoteId . '/');
    }

    /**
     * Get PDF generator instance (lazy-loaded)
     */
    private function getPdfGenerator(): QuotePDFGenerator
    {
        if ($this->pdfGenerator === null) {
            $this->pdfGenerator = new QuotePDFGenerator($this);
        }
        return $this->pdfGenerator;
    }

    // ========================================
    // VAT REVALIDATION
    // ========================================

    /**
     * Handle VAT revalidation (called by Action Scheduler)
     *
     * @param string $vatNumber VAT number to revalidate
     */
    public function handleVatRevalidation(string $vatNumber): void
    {
        $this->getVatValidator()->revalidateAsync($vatNumber);
    }

    /**
     * Get VAT validator instance (lazy-loaded)
     */
    private function getVatValidator(): VATValidator
    {
        if ($this->vatValidator === null) {
            $this->vatValidator = new VATValidator();
        }
        return $this->vatValidator;
    }

    // ========================================
    // ADMIN CONTROLLER
    // ========================================

    /**
     * Initialize admin controller
     */
    public function initAdminController(): void
    {
        new QuoteAdminController($this);
    }

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
     * Create a new quote for a user/course (BC wrapper)
     *
     * @param int $userId WordPress user ID
     * @param int $courseId LearnDash course ID
     * @param array $data Additional quote data
     * @return int|WP_Error Quote post ID or error
     * @deprecated Use createQuoteForItem() for new implementations
     */
    public function createQuote(int $userId, int $courseId, array $data = []): int|WP_Error
    {
        return $this->createQuoteForItem($userId, 'course', $courseId, $data);
    }

    /**
     * Create a new quote for any item type
     *
     * Uses filters for item resolution:
     * - stride/quote/resolve_item: Resolves item details (title, price, valid, meta)
     * - stride/quote/resolve_price: Resolves item price only
     * - stride/quote/calculate_discount: Calculates voucher discount
     *
     * @param int $userId WordPress user ID
     * @param string $itemType Item type (course, product, service, etc.)
     * @param int $itemId Item ID
     * @param array $data Additional quote data
     * @return int|WP_Error Quote post ID or error
     */
    public function createQuoteForItem(int $userId, string $itemType, int $itemId, array $data = []): int|WP_Error
    {
        // Build unique identifier fields for duplicate check
        $existingQuery = [
            self::FIELD_USER_ID => $userId,
            self::FIELD_ITEM_TYPE => $itemType,
            self::FIELD_ITEM_ID => $itemId,
        ];

        // BC: Also check course_id for legacy course quotes
        if ($itemType === 'course') {
            $existingByCourse = $this->findQuote([
                self::FIELD_USER_ID => $userId,
                self::FIELD_COURSE_ID => $itemId,
            ]);
            if ($existingByCourse) {
                return new WP_Error('quote_exists', __('Er bestaat al een offerte voor dit item.', 'stride'));
            }
        }

        // Check for existing quote
        $existing = $this->findQuote($existingQuery);
        if ($existing) {
            return new WP_Error('quote_exists', __('Er bestaat al een offerte voor dit item.', 'stride'));
        }

        // Resolve item details via filter
        $itemResolved = apply_filters('stride/quote/resolve_item', null, $itemType, $itemId);

        if ($itemResolved === null) {
            return new WP_Error('item_not_found', __('Item kon niet worden gevonden.', 'stride'));
        }

        if (isset($itemResolved['valid']) && !$itemResolved['valid']) {
            return new WP_Error('item_invalid', $itemResolved['error'] ?? __('Item is niet geldig voor offerte.', 'stride'));
        }

        $itemTitle = $itemResolved['title'] ?? __('Onbekend item', 'stride');
        $itemPrice = (float) ($itemResolved['price'] ?? 0);

        // Generate quote number
        $quoteNumber = $this->generateQuoteNumber();

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
            $vatResult = $this->getVatValidator()->validate($billing['vat_number']);
            $billing['vat_validated'] = $vatResult['valid'];
            $billing['vat_source'] = $vatResult['source'] ?? 'unknown';
            // Auto-fill company from VIES if available
            if ($vatResult['valid'] && !empty($vatResult['name'])) {
                $billing['organisation'] = $vatResult['name'];
            }
        }

        $taxRate = QuoteConfig::getTaxRate();
        $subtotal = $itemPrice;

        // Apply voucher discount if provided
        $discount = 0.0;
        $voucherCode = sanitize_text_field($data['voucher_code'] ?? '');
        if (!empty($voucherCode)) {
            // Use filter for voucher discount calculation
            $discount = (float) apply_filters(
                'stride/quote/calculate_discount',
                0.0,
                $voucherCode,
                $itemType,
                $itemId,
                $itemPrice
            );
        }

        $discountedSubtotal = max(0, $subtotal - $discount);
        $tax = round($discountedSubtotal * ($taxRate / 100), 2);
        $total = $discountedSubtotal + $tax;

        // Build items array using factory
        $items = [
            QuoteItemFactory::create($itemType, $itemId, $itemTitle, $itemPrice, 1, $itemResolved['meta'] ?? []),
        ];

        // Add discount line if applicable
        if ($discount > 0) {
            $items[] = QuoteItemFactory::createDiscount(
                $discount,
                sprintf(__('Korting (voucher: %s)', 'stride'), $voucherCode),
                ['voucher_code' => $voucherCode]
            );
        }

        // Calculate valid until date
        $validDays = QuoteConfig::getValidDays();
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
            self::FIELD_COURSE_ID => $itemType === 'course' ? $itemId : 0, // BC
            self::FIELD_ITEM_TYPE => $itemType,
            self::FIELD_ITEM_ID => $itemId,
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
            sprintf(__('Offerte %s aangemaakt voor: %s', 'stride'), $quoteNumber, $itemTitle)
        );

        // Fire hook
        do_action('stride/quote/created', $quoteId, $userId, $itemId);

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
            'course_id' => (int) ($post->fields[self::FIELD_COURSE_ID] ?? 0), // BC
            'item_type' => $post->fields[self::FIELD_ITEM_TYPE] ?? 'course',
            'item_id' => (int) ($post->fields[self::FIELD_ITEM_ID] ?? $post->fields[self::FIELD_COURSE_ID] ?? 0),
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
            'notes' => $post->fields[self::FIELD_NOTES] ?? [],
            'locked' => (bool) ($post->fields[self::FIELD_LOCKED] ?? false),
            'last_sent_to' => $post->fields[self::FIELD_LAST_SENT_TO] ?? '',
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

        // Only allow updates in draft status (unless explicitly bypassing)
        if ($quote['status'] !== self::STATUS_DRAFT && !($data['_bypass_status_check'] ?? false)) {
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
                $vatResult = $this->getVatValidator()->validate($data['vat_number']);
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
     * Update quote fields directly (for internal use)
     *
     * @param int $quoteId Quote post ID
     * @param array $fields Field data to update
     * @return bool Success
     */
    public function updateQuoteFields(int $quoteId, array $fields): bool
    {
        $model = $this->getModel();
        if (!$model) {
            return false;
        }

        $result = $model->update($quoteId, $fields);
        return !is_wp_error($result);
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
     * Cancel a quote
     *
     * Can only cancel draft or sent quotes (not exported).
     *
     * @param int $quoteId Quote post ID
     * @param string $reason Optional reason for cancellation
     * @return true|WP_Error
     */
    public function cancelQuote(int $quoteId, string $reason = ''): true|WP_Error
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return new WP_Error('quote_not_found', __('Offerte niet gevonden.', 'stride'));
        }

        // Can't cancel exported quotes (already in accounting)
        if ($quote['status'] === self::STATUS_EXPORTED) {
            return new WP_Error('cannot_cancel', __('Geëxporteerde offertes kunnen niet worden geannuleerd.', 'stride'));
        }

        // Already cancelled
        if ($quote['status'] === self::STATUS_CANCELLED) {
            return new WP_Error('already_cancelled', __('Offerte is al geannuleerd.', 'stride'));
        }

        $model = $this->getModel();
        if (!$model) {
            return new WP_Error('no_model', __('DataManager niet beschikbaar.', 'stride'));
        }

        $updateData = [
            self::FIELD_STATUS => self::STATUS_CANCELLED,
        ];

        // Add cancellation note if reason provided
        if ($reason) {
            $notes = $quote['notes'] ?? [];
            $notes[] = [
                'type' => 'cancellation',
                'message' => $reason,
                'timestamp' => current_time('mysql'),
                'user_id' => get_current_user_id(),
            ];
            $updateData[self::FIELD_NOTES] = $notes;
        }

        $result = $model->update($quoteId, $updateData);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fire hook
        do_action('stride/quote/cancelled', $quoteId, $quote, $reason);

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
     * @param array $where Key-value pairs for query conditions
     * @return int|null Quote post ID or null
     */
    public function findQuote(array $where): ?int
    {
        $model = $this->getModel();
        if (!$model) {
            return null;
        }

        foreach ($where as $field => $value) {
            $model = $model->where($field, $value);
        }

        $quote = $model->limit(1)->first();

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
    // URL HELPERS
    // ========================================

    /**
     * Get PDF URL for a quote
     *
     * @param int $quoteId Quote post ID
     * @return string PDF URL or empty string
     */
    public function getQuoteUrl(int $quoteId): string
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote || empty($quote['pdf_path'])) {
            return '';
        }

        // Convert file path to URL
        $uploadDir = wp_upload_dir();
        return str_replace($uploadDir['basedir'], $uploadDir['baseurl'], $quote['pdf_path']);
    }

    /**
     * Get customer quote form URL
     *
     * @param int $quoteId Quote post ID
     * @return string Form URL
     */
    public function getQuoteFormUrl(int $quoteId): string
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote) {
            return '';
        }

        // Use a secure hash for public access
        $hash = wp_hash($quoteId . $quote['number'] . $quote['user_id']);

        return add_query_arg([
            'stride_quote' => $quoteId,
            'token' => substr($hash, 0, 12),
        ], home_url('/offerte/'));
    }

    // ========================================
    // HELPERS
    // ========================================

    /**
     * Get the Data Model for quotes
     */
    public function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(self::POST_TYPE);
    }

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

        $prefix = QuoteConfig::getQuotePrefix();
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
     * Format amount as currency
     *
     * @param float $amount Amount to format
     * @return string Formatted currency string
     */
    public function formatCurrency(float $amount): string
    {
        return CurrencyFormatter::format($amount);
    }
}
