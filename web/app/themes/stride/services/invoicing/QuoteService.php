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
    public const FIELD_NOTES = 'notes';
    public const FIELD_LOCKED = 'locked';
    public const FIELD_LAST_SENT_TO = 'last_sent_to';

    // Note types
    public const NOTE_TYPE_ADMIN = 'admin';
    public const NOTE_TYPE_CUSTOMER = 'customer';

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

        // Custom admin metaboxes for quote overview
        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);

        // Save metabox data
        add_action('save_post_' . self::POST_TYPE, [$this, 'saveQuoteMetabox'], 10, 2);

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

            // Field schema for ORM - metabox removed via registerMetaboxes()
            'fields' => [
                self::FIELD_USER_ID => ['type' => 'integer', 'required' => true],
                self::FIELD_COURSE_ID => ['type' => 'integer', 'required' => true],
                self::FIELD_STATUS => [
                    'type' => 'select',
                    'options' => [
                        self::STATUS_DRAFT => __('Concept', 'stride'),
                        self::STATUS_SENT => __('Verzonden', 'stride'),
                        self::STATUS_EXPORTED => __('Geëxporteerd', 'stride'),
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
     * Register custom metaboxes for quote overview
     */
    public function registerMetaboxes(): void
    {
        // Remove default editor
        remove_post_type_support(self::POST_TYPE, 'editor');

        // Main quote overview - invoice style
        add_meta_box(
            'stride_quote_overview',
            __('Offerte', 'stride'),
            [$this, 'renderOverviewMetabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        // Status & actions sidebar
        add_meta_box(
            'stride_quote_status',
            __('Status & Acties', 'stride'),
            [$this, 'renderActionsMetabox'],
            self::POST_TYPE,
            'side',
            'high'
        );

        // Notes metabox (admin & customer notes)
        add_meta_box(
            'stride_quote_notes',
            __('Notities', 'stride'),
            [$this, 'renderNotesMetabox'],
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    /**
     * Save quote metabox data (billing, items, etc.)
     *
     * @param int $postId Post ID
     * @param \WP_Post $post Post object
     */
    public function saveQuoteMetabox(int $postId, \WP_Post $post): void
    {
        // Verify nonce
        if (!isset($_POST['stride_quote_nonce']) ||
            !wp_verify_nonce($_POST['stride_quote_nonce'], 'stride_save_quote')) {
            return;
        }

        // Check autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        // Get current quote to check status
        $quote = $this->getQuote($postId);

        // Handle new quote creation (from manual form)
        if (!$quote) {
            $this->handleNewQuoteCreation($postId, $post);
            return;
        }

        $model = $this->getModel();
        if (!$model) {
            return;
        }

        $updateData = [];

        // Check if quote is editable for billing/items changes
        // Only locked status blocks editing - not the quote status
        $isLocked = (bool) ($quote['locked'] ?? false);
        $isEditable = !$isLocked;

        // Process billing data (only if editable)
        if ($isEditable && isset($_POST['billing']) && is_array($_POST['billing'])) {
            $billing = $quote['billing'];
            $billingFields = ['organisation', 'email', 'address', 'postal_code', 'city', 'vat_number', 'gln_number'];

            foreach ($billingFields as $field) {
                if (isset($_POST['billing'][$field])) {
                    $billing[$field] = sanitize_text_field($_POST['billing'][$field]);
                }
            }

            // Re-validate VAT if changed
            $newVat = $billing['vat_number'] ?? '';
            $oldVat = $quote['billing']['vat_number'] ?? '';
            if (!empty($newVat) && $newVat !== $oldVat) {
                $vatResult = $this->vatValidator->validate($newVat);
                $billing['vat_validated'] = $vatResult['valid'];
                $billing['vat_source'] = $vatResult['source'] ?? 'unknown';
                if ($vatResult['valid'] && !empty($vatResult['name']) && empty($billing['organisation'])) {
                    $billing['organisation'] = $vatResult['name'];
                }
            }

            $updateData[self::FIELD_BILLING] = $billing;
        }

        // Process items data (only if editable)
        if ($isEditable && isset($_POST['items']) && is_array($_POST['items'])) {
            $items = [];
            $subtotal = 0.0;

            foreach ($_POST['items'] as $index => $itemData) {
                // Skip empty rows (removed items)
                if (empty($itemData['title']) && empty($itemData['unit_price'])) {
                    continue;
                }

                $quantity = max(1, (int) ($itemData['quantity'] ?? 1));
                $unitPrice = (float) ($itemData['unit_price'] ?? 0);
                $itemTotal = $quantity * $unitPrice;
                $type = sanitize_text_field($itemData['type'] ?? 'course');

                $items[] = [
                    'id' => (int) ($itemData['id'] ?? 0),
                    'type' => $type,
                    'title' => sanitize_text_field($itemData['title'] ?? ''),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total' => $itemTotal,
                ];

                // Calculate subtotal (discount items have negative prices)
                $subtotal += $itemTotal;
            }

            // Extract discount from items
            $discount = 0.0;
            foreach ($items as $item) {
                if ($item['type'] === 'discount') {
                    $discount += abs($item['total']);
                }
            }

            // Calculate totals
            $subtotalBeforeDiscount = $subtotal + $discount; // Add back the discount amount that was subtracted
            $discountedSubtotal = max(0, $subtotal); // subtotal already has discount subtracted
            $taxRate = $this->getTaxRate();
            $tax = round($discountedSubtotal * ($taxRate / 100), 2);
            $total = $discountedSubtotal + $tax;

            $updateData[self::FIELD_ITEMS] = $items;
            $updateData[self::FIELD_SUBTOTAL] = $subtotalBeforeDiscount;
            $updateData[self::FIELD_DISCOUNT] = $discount;
            $updateData[self::FIELD_TAX] = $tax;
            $updateData[self::FIELD_TOTAL] = $total;
        }

        // Process notes data
        if (isset($_POST['stride_notes_data'])) {
            $notesJson = wp_unslash($_POST['stride_notes_data']);
            $notes = json_decode($notesJson, true);

            if (is_array($notes)) {
                // Filter out deleted notes and sanitize
                $cleanNotes = [];
                foreach ($notes as $note) {
                    if (!empty($note['_deleted'])) {
                        continue;
                    }
                    $cleanNotes[] = [
                        'type' => sanitize_text_field($note['type'] ?? self::NOTE_TYPE_ADMIN),
                        'content' => sanitize_textarea_field($note['content'] ?? ''),
                        'author' => sanitize_text_field($note['author'] ?? ''),
                        'date' => sanitize_text_field($note['date'] ?? current_time('mysql')),
                    ];
                }
                $updateData[self::FIELD_NOTES] = $cleanNotes;
            }
        }

        // Handle lock/unlock action
        if (!empty($_POST['stride_lock_action'])) {
            $lockAction = sanitize_text_field($_POST['stride_lock_action']);
            if ($lockAction === 'lock') {
                $updateData[self::FIELD_LOCKED] = true;
                $this->addAuditNote($postId, __('Offerte vergrendeld', 'stride'));
            } elseif ($lockAction === 'unlock') {
                $updateData[self::FIELD_LOCKED] = false;
                $this->addAuditNote($postId, __('Offerte ontgrendeld', 'stride'));
            }
        }

        // Handle status change
        if (!empty($_POST['stride_change_status'])) {
            $newStatus = sanitize_text_field($_POST['stride_change_status']);
            $validStatuses = [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_EXPORTED];

            if (in_array($newStatus, $validStatuses, true) && $newStatus !== $quote['status']) {
                $updateData[self::FIELD_STATUS] = $newStatus;

                // Set timestamp for status transitions
                if ($newStatus === self::STATUS_SENT && empty($quote['sent_at'])) {
                    $updateData[self::FIELD_SENT_AT] = current_time('mysql');
                } elseif ($newStatus === self::STATUS_EXPORTED) {
                    if (empty($quote['exported_at'])) {
                        $updateData[self::FIELD_EXPORTED_AT] = current_time('mysql');
                    }
                    // Auto-lock on export
                    $updateData[self::FIELD_LOCKED] = true;
                }

                $this->addAuditNote($postId, sprintf(
                    __('Status gewijzigd naar: %s', 'stride'),
                    $newStatus
                ));
            }
        }

        // Update if we have data
        if (!empty($updateData)) {
            $model->update($postId, $updateData);

            // Fire update hook
            do_action('stride/quote/updated', $postId, $updateData);
        }

        // Handle send quote action (after all updates)
        if (!empty($_POST['stride_send_quote'])) {
            $sendTo = sanitize_email($_POST['stride_send_to'] ?? '');
            $sendCc = sanitize_email($_POST['stride_send_cc'] ?? '');

            if ($sendTo) {
                $this->sendQuoteEmail($postId, $sendTo, $sendCc);
            }
        }

        // Handle PDF regeneration
        if (!empty($_POST['stride_regenerate_pdf'])) {
            do_action('stride/quote/regenerate_pdf', $postId);
            $this->addAuditNote($postId, __('PDF opnieuw gegenereerd', 'stride'));
        }

        // Handle voucher application
        if (!empty($_POST['stride_apply_voucher'])) {
            $voucherCode = sanitize_text_field($_POST['stride_apply_voucher']);
            $this->applyVoucherToQuote($postId, $voucherCode);
        }

        // Handle manual discount
        if (!empty($_POST['stride_apply_discount'])) {
            $discountAmount = (float) $_POST['stride_apply_discount'];
            if ($discountAmount > 0) {
                $this->applyManualDiscount($postId, $discountAmount);
            }
        }

        // Handle voucher removal
        if (!empty($_POST['stride_remove_voucher'])) {
            $this->removeVoucherFromQuote($postId);
        }
    }

    /**
     * Apply a voucher to a quote
     *
     * @param int $postId Quote post ID
     * @param string $voucherCode Voucher code
     */
    private function applyVoucherToQuote(int $postId, string $voucherCode): void
    {
        $quote = $this->getQuote($postId);
        if (!$quote) {
            return;
        }

        // Validate voucher via VoucherService
        if ($this->voucherService) {
            $validation = $this->voucherService->validateVoucher($voucherCode, $quote['course_id']);

            if (is_wp_error($validation)) {
                // Store error for admin notice
                set_transient('stride_quote_voucher_error_' . $postId, $validation->get_error_message(), 30);
                return;
            }

            // Calculate discount
            $discountAmount = 0;
            $subtotal = $quote['subtotal'] ?? 0;

            if ($validation['discount_type'] === 'percentage') {
                $discountAmount = round($subtotal * ($validation['discount_value'] / 100), 2);
            } elseif ($validation['discount_type'] === 'fixed') {
                $discountAmount = min($validation['discount_value'], $subtotal);
            } elseif ($validation['discount_type'] === 'full') {
                $discountAmount = $subtotal;
            }

            // Add discount as line item
            $items = $quote['items'] ?? [];
            $items[] = [
                'id' => 0,
                'type' => 'discount',
                'title' => sprintf(__('Korting: %s', 'stride'), $voucherCode),
                'quantity' => 1,
                'unit_price' => -$discountAmount,
                'total' => -$discountAmount,
            ];

            // Recalculate totals
            $taxRate = $this->getTaxRate();
            $discountedSubtotal = max(0, $subtotal - $discountAmount);
            $tax = round($discountedSubtotal * ($taxRate / 100), 2);
            $total = $discountedSubtotal + $tax;

            $model = $this->getModel();
            if ($model) {
                $model->update($postId, [
                    self::FIELD_ITEMS => $items,
                    self::FIELD_VOUCHER_CODE => $voucherCode,
                    self::FIELD_DISCOUNT => $discountAmount,
                    self::FIELD_TAX => $tax,
                    self::FIELD_TOTAL => $total,
                ]);
            }

            $this->addAuditNote($postId, sprintf(
                __('Voucher toegepast: %s (-%s)', 'stride'),
                $voucherCode,
                $this->formatCurrency($discountAmount)
            ));
        }
    }

    /**
     * Apply a manual discount to a quote
     *
     * @param int $postId Quote post ID
     * @param float $amount Discount amount
     */
    private function applyManualDiscount(int $postId, float $amount): void
    {
        $quote = $this->getQuote($postId);
        if (!$quote) {
            return;
        }

        $subtotal = $quote['subtotal'] ?? 0;
        $discountAmount = min($amount, $subtotal);

        // Add discount as line item
        $items = $quote['items'] ?? [];

        // Remove existing manual discount items
        $items = array_filter($items, fn($item) =>
            !(($item['type'] ?? '') === 'discount' && empty($item['voucher']))
        );
        $items = array_values($items);

        $items[] = [
            'id' => 0,
            'type' => 'discount',
            'title' => __('Handmatige korting', 'stride'),
            'quantity' => 1,
            'unit_price' => -$discountAmount,
            'total' => -$discountAmount,
        ];

        // Recalculate totals
        $taxRate = $this->getTaxRate();
        $discountedSubtotal = max(0, $subtotal - $discountAmount);
        $tax = round($discountedSubtotal * ($taxRate / 100), 2);
        $total = $discountedSubtotal + $tax;

        $model = $this->getModel();
        if ($model) {
            $model->update($postId, [
                self::FIELD_ITEMS => $items,
                self::FIELD_DISCOUNT => $discountAmount,
                self::FIELD_TAX => $tax,
                self::FIELD_TOTAL => $total,
            ]);
        }

        $this->addAuditNote($postId, sprintf(
            __('Handmatige korting toegepast: -%s', 'stride'),
            $this->formatCurrency($discountAmount)
        ));
    }

    /**
     * Remove voucher/discount from a quote
     *
     * @param int $postId Quote post ID
     */
    private function removeVoucherFromQuote(int $postId): void
    {
        $quote = $this->getQuote($postId);
        if (!$quote) {
            return;
        }

        // Remove all discount items
        $items = $quote['items'] ?? [];
        $items = array_filter($items, fn($item) => ($item['type'] ?? '') !== 'discount');
        $items = array_values($items);

        // Recalculate totals without discount
        $subtotal = $quote['subtotal'] ?? 0;
        $taxRate = $this->getTaxRate();
        $tax = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $tax;

        $model = $this->getModel();
        if ($model) {
            $model->update($postId, [
                self::FIELD_ITEMS => $items,
                self::FIELD_VOUCHER_CODE => '',
                self::FIELD_DISCOUNT => 0,
                self::FIELD_TAX => $tax,
                self::FIELD_TOTAL => $total,
            ]);
        }

        $this->addAuditNote($postId, __('Korting verwijderd', 'stride'));
    }

    /**
     * Add an audit note to the quote
     *
     * @param int $postId Quote post ID
     * @param string $message Note message
     */
    private function addAuditNote(int $postId, string $message): void
    {
        $model = $this->getModel();
        if (!$model) {
            return;
        }

        $quote = $this->getQuote($postId);
        $notes = $quote['notes'] ?? [];

        $currentUser = wp_get_current_user();

        $notes[] = [
            'type' => self::NOTE_TYPE_ADMIN,
            'content' => $message,
            'author' => $currentUser->display_name ?: 'System',
            'date' => current_time('mysql'),
        ];

        $model->update($postId, [
            self::FIELD_NOTES => $notes,
        ]);
    }

    /**
     * Send quote email to customer
     *
     * @param int $postId Quote post ID
     * @param string $to Recipient email
     * @param string $cc CC email (optional)
     */
    private function sendQuoteEmail(int $postId, string $to, string $cc = ''): void
    {
        $quote = $this->getQuote($postId);
        if (!$quote) {
            return;
        }

        // Store recipients for reference
        $sentTo = $to;
        if ($cc) {
            $sentTo .= ', ' . $cc;
        }

        $model = $this->getModel();
        if ($model) {
            $updateData = [
                self::FIELD_LAST_SENT_TO => $sentTo,
            ];

            // Update status to sent if still draft
            if ($quote['status'] === self::STATUS_DRAFT) {
                $updateData[self::FIELD_STATUS] = self::STATUS_SENT;
                $updateData[self::FIELD_SENT_AT] = current_time('mysql');
            }

            $model->update($postId, $updateData);
        }

        // Add audit note
        $this->addAuditNote($postId, sprintf(
            __('Offerte verzonden naar: %s', 'stride'),
            $sentTo
        ));

        // Fire hook for email sending (handled by separate service)
        do_action('stride/quote/send_email', $postId, $to, $cc, $quote);
    }

    /**
     * Handle creation of a new quote from manual admin form
     *
     * @param int $postId Post ID
     * @param \WP_Post $post Post object
     */
    private function handleNewQuoteCreation(int $postId, \WP_Post $post): void
    {
        // Check for required fields from ntdst_fields
        $fields = $_POST['ntdst_fields'] ?? [];
        $userId = absint($fields['user_id'] ?? 0);
        $courseId = absint($fields['course_id'] ?? 0);

        if (!$userId || !$courseId) {
            return;
        }

        // Generate quote number
        $quoteNumber = $this->generateQuoteNumber();

        // Get course details
        $courseTitle = $this->courseService->getCourseTitle($courseId);
        $coursePrice = $this->getCoursePrice($courseId);

        // Get billing data from subscriber
        $billing = $this->subscriberService->getBillingData($userId);
        if (is_wp_error($billing)) {
            $billing = [];
        }

        // Calculate totals
        $taxRate = $this->getTaxRate();
        $subtotal = $coursePrice;
        $tax = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $tax;

        // Build items
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

        // Calculate valid until
        $validDays = $this->getConfig('valid_days', 30);
        $validUntil = date('Y-m-d', strtotime("+{$validDays} days"));

        $model = $this->getModel();
        if (!$model) {
            return;
        }

        // Update the post with generated data
        $model->update($postId, [
            self::FIELD_USER_ID => $userId,
            self::FIELD_COURSE_ID => $courseId,
            self::FIELD_STATUS => self::STATUS_DRAFT,
            self::FIELD_QUOTE_NUMBER => $quoteNumber,
            self::FIELD_ITEMS => $items,
            self::FIELD_SUBTOTAL => $subtotal,
            self::FIELD_DISCOUNT => 0,
            self::FIELD_TAX => $tax,
            self::FIELD_TOTAL => $total,
            self::FIELD_VALID_UNTIL => $validUntil,
            self::FIELD_BILLING => $billing,
            self::FIELD_CREATED_AT => current_time('mysql'),
        ]);

        // Update post title to quote number
        wp_update_post([
            'ID' => $postId,
            'post_title' => $quoteNumber,
        ]);

        // Fire created hook
        do_action('stride/quote/created', $postId, $userId, $courseId);
    }

    /**
     * Render main quote overview metabox - Editable invoice style layout
     */
    public function renderOverviewMetabox(\WP_Post $post): void
    {
        $quote = $this->getQuote($post->ID);

        // Handle new/unsaved quotes
        if (!$quote) {
            $this->renderNewQuoteForm($post);
            return;
        }

        $userId = $quote['user_id'];
        $user = get_userdata($userId);
        $billing = $quote['billing'] ?? [];
        $items = $quote['items'] ?? [];
        $courseId = $quote['course_id'];
        $isLocked = (bool) ($quote['locked'] ?? false);
        // Editable unless explicitly locked - status doesn't block editing

        // Add default empty item row if no items exist (for new quotes)
        if (empty($items)) {
            $items = [
                [
                    'id' => 0,
                    'type' => 'course',
                    'title' => '',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'total' => 0,
                ],
            ];
        }
        $isEditable = !$isLocked;

        // Security nonce
        wp_nonce_field('stride_save_quote', 'stride_quote_nonce');

        $this->renderQuoteStyles();
        ?>

        <div class="stride-quote-admin">
            <!-- Header: Quote Number -->
            <div class="stride-quote-header">
                <div class="stride-quote-number-display">
                    <span class="label"><?php esc_html_e('Offerte', 'stride'); ?></span>
                    <span class="number"><?php echo esc_html($quote['number']); ?></span>
                </div>
                <div class="stride-quote-dates">
                    <span><?php esc_html_e('Aangemaakt:', 'stride'); ?> <?php echo esc_html(date_i18n('j M Y', strtotime($quote['created_at']))); ?></span>
                    <span><?php esc_html_e('Geldig tot:', 'stride'); ?> <?php echo esc_html(date_i18n('j M Y', strtotime($quote['valid_until']))); ?></span>
                </div>
            </div>

            <!-- Two Column: Customer Billing | Quote Details -->
            <div class="stride-quote-columns">
                <!-- Left: Billing Details (Editable) -->
                <div class="stride-quote-billing">
                    <h4><?php esc_html_e('Facturatiegegevens', 'stride'); ?></h4>

                    <div class="stride-field-row">
                        <div class="stride-field stride-user-field">
                            <label for="quote_user_id"><?php esc_html_e('Klant', 'stride'); ?></label>
                            <?php if ($isEditable): ?>
                                <select id="quote_user_id" name="ntdst_fields[user_id]" class="stride-user-select">
                                    <option value=""><?php esc_html_e('Selecteer klant...', 'stride'); ?></option>
                                    <?php
                                    // Get users with subscriber role or higher
                                    $users = get_users([
                                        'orderby' => 'display_name',
                                        'order' => 'ASC',
                                        'number' => 200,
                                    ]);
                                    foreach ($users as $u) {
                                        $selected = ($u->ID == $userId) ? 'selected' : '';
                                        $label = $u->display_name;
                                        if ($u->user_email) {
                                            $label .= ' (' . $u->user_email . ')';
                                        }
                                        echo '<option value="' . esc_attr($u->ID) . '" ' . $selected . ' data-email="' . esc_attr($u->user_email) . '">' . esc_html($label) . '</option>';
                                    }
                                    ?>
                                </select>
                                <?php if ($user): ?>
                                    <a href="<?php echo esc_url(get_edit_user_link($userId)); ?>" class="stride-user-link" target="_blank">
                                        <span class="dashicons dashicons-external"></span>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($user): ?>
                                    <div class="stride-user-display">
                                        <a href="<?php echo esc_url(get_edit_user_link($userId)); ?>">
                                            <?php echo esc_html($user->display_name); ?>
                                        </a>
                                        <span class="email">(<?php echo esc_html($user->user_email); ?>)</span>
                                    </div>
                                <?php else: ?>
                                    <span class="no-user"><?php esc_html_e('Geen gebruiker', 'stride'); ?></span>
                                <?php endif; ?>
                                <input type="hidden" name="ntdst_fields[user_id]" value="<?php echo esc_attr($userId); ?>">
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="billing_organisation"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                            <input type="text" id="billing_organisation" name="billing[organisation]"
                                   value="<?php echo esc_attr($billing['organisation'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                        <div class="stride-field">
                            <label for="billing_email"><?php esc_html_e('Email', 'stride'); ?></label>
                            <input type="email" id="billing_email" name="billing[email]"
                                   value="<?php echo esc_attr($billing['email'] ?? $user->user_email ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="billing_address"><?php esc_html_e('Adres', 'stride'); ?></label>
                            <input type="text" id="billing_address" name="billing[address]"
                                   value="<?php echo esc_attr($billing['address'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                    </div>

                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="billing_postal_code"><?php esc_html_e('Postcode', 'stride'); ?></label>
                            <input type="text" id="billing_postal_code" name="billing[postal_code]"
                                   value="<?php echo esc_attr($billing['postal_code'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                        <div class="stride-field">
                            <label for="billing_city"><?php esc_html_e('Stad', 'stride'); ?></label>
                            <input type="text" id="billing_city" name="billing[city]"
                                   value="<?php echo esc_attr($billing['city'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                    </div>

                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="billing_vat_number"><?php esc_html_e('BTW Nummer', 'stride'); ?></label>
                            <input type="text" id="billing_vat_number" name="billing[vat_number]"
                                   value="<?php echo esc_attr($billing['vat_number'] ?? ''); ?>"
                                   placeholder="BE0123456789"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                        <div class="stride-field">
                            <label for="billing_gln_number"><?php esc_html_e('GLN Nummer', 'stride'); ?></label>
                            <input type="text" id="billing_gln_number" name="billing[gln_number]"
                                   value="<?php echo esc_attr($billing['gln_number'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                </div>

                <!-- Right: Quote Details -->
                <div class="stride-quote-details">
                    <h4><?php esc_html_e('Offerte details', 'stride'); ?></h4>

                    <div class="stride-field">
                        <label for="quote_order_number"><?php esc_html_e('Bestelnummer (PO)', 'stride'); ?></label>
                        <input type="text" id="quote_order_number" name="ntdst_fields[order_number]"
                               value="<?php echo esc_attr($quote['order_number'] ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Optioneel', 'stride'); ?>"
                               <?php echo !$isEditable ? 'readonly' : ''; ?>>
                    </div>

                    <!-- Hidden fields for reference -->
                    <input type="hidden" name="ntdst_fields[course_id]" value="<?php echo esc_attr($courseId); ?>">
                </div>
            </div>

            <!-- Line Items Table -->
            <div class="stride-quote-items-section">
                <h4><?php esc_html_e('Offerte items', 'stride'); ?></h4>

                <table class="stride-quote-items widefat">
                    <thead>
                        <tr>
                            <th class="description"><?php esc_html_e('Omschrijving', 'stride'); ?></th>
                            <th class="qty"><?php esc_html_e('Aantal', 'stride'); ?></th>
                            <th class="price"><?php esc_html_e('Prijs', 'stride'); ?></th>
                            <th class="total"><?php esc_html_e('Bedrag', 'stride'); ?></th>
                            <?php if ($isEditable): ?>
                                <th class="actions"></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="stride-quote-items-body">
                        <?php foreach ($items as $index => $item): ?>
                            <tr class="item-row <?php echo ($item['type'] ?? '') === 'discount' ? 'discount-row' : ''; ?>" data-index="<?php echo esc_attr($index); ?>">
                                <td class="description">
                                    <?php if ($isEditable): ?>
                                        <input type="text" name="items[<?php echo $index; ?>][title]"
                                               value="<?php echo esc_attr($item['title'] ?? ''); ?>" class="item-title">
                                        <input type="hidden" name="items[<?php echo $index; ?>][type]"
                                               value="<?php echo esc_attr($item['type'] ?? 'course'); ?>">
                                    <?php else: ?>
                                        <?php echo esc_html($item['title'] ?? '-'); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="qty">
                                    <?php if ($isEditable): ?>
                                        <input type="number" name="items[<?php echo $index; ?>][quantity]"
                                               value="<?php echo esc_attr($item['quantity'] ?? 1); ?>"
                                               min="1" step="1" class="item-qty">
                                    <?php else: ?>
                                        <?php echo esc_html($item['quantity'] ?? 1); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="price">
                                    <?php if ($isEditable): ?>
                                        <input type="number" name="items[<?php echo $index; ?>][unit_price]"
                                               value="<?php echo esc_attr($item['unit_price'] ?? 0); ?>"
                                               min="0" step="0.01" class="item-price">
                                    <?php else: ?>
                                        <?php echo $this->formatCurrency((float)($item['unit_price'] ?? 0)); ?>
                                    <?php endif; ?>
                                </td>
                                <td class="total">
                                    <?php echo $this->formatCurrency((float)($item['total'] ?? 0)); ?>
                                </td>
                                <?php if ($isEditable): ?>
                                    <td class="actions">
                                        <button type="button" class="button-link stride-remove-item" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                        </button>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="subtotal">
                            <td colspan="<?php echo $isEditable ? 3 : 3; ?>"><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                            <td class="amount"><?php echo $this->formatCurrency($quote['subtotal'] ?? 0); ?></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                        <?php if (($quote['discount'] ?? 0) > 0): ?>
                            <tr class="discount">
                                <td colspan="<?php echo $isEditable ? 3 : 3; ?>"><?php esc_html_e('Korting', 'stride'); ?></td>
                                <td class="amount">- <?php echo $this->formatCurrency($quote['discount']); ?></td>
                                <?php if ($isEditable): ?><td></td><?php endif; ?>
                            </tr>
                        <?php endif; ?>
                        <tr class="tax">
                            <td colspan="<?php echo $isEditable ? 3 : 3; ?>"><?php esc_html_e('BTW 21%', 'stride'); ?></td>
                            <td class="amount"><?php echo $this->formatCurrency($quote['tax'] ?? 0); ?></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                        <tr class="grand-total">
                            <td colspan="<?php echo $isEditable ? 3 : 3; ?>"><?php esc_html_e('Totaal', 'stride'); ?></td>
                            <td class="amount"><?php echo $this->formatCurrency($quote['total'] ?? 0); ?></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>

                <?php if ($isEditable): ?>
                    <div class="stride-quote-actions">
                        <button type="button" class="button" id="stride-add-item">
                            <span class="dashicons dashicons-plus-alt2"></span>
                            <?php esc_html_e('Item toevoegen', 'stride'); ?>
                        </button>
                        <button type="button" class="button" id="stride-recalculate">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e('Herbereken totalen', 'stride'); ?>
                        </button>
                    </div>
                <?php else: ?>
                    <p class="stride-readonly-notice">
                        <span class="dashicons dashicons-lock"></span>
                        <?php esc_html_e('Deze offerte is vergrendeld. Ontgrendel via de zijbalk om te bewerken.', 'stride'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Hidden fields for stored values -->
            <input type="hidden" name="ntdst_fields[status]" value="<?php echo esc_attr($quote['status']); ?>">
            <input type="hidden" name="ntdst_fields[quote_number]" value="<?php echo esc_attr($quote['number']); ?>">
            <input type="hidden" name="ntdst_fields[created_at]" value="<?php echo esc_attr($quote['created_at']); ?>">
            <input type="hidden" name="ntdst_fields[subtotal]" id="quote_subtotal" value="<?php echo esc_attr($quote['subtotal'] ?? 0); ?>">
            <input type="hidden" name="ntdst_fields[tax]" id="quote_tax" value="<?php echo esc_attr($quote['tax'] ?? 0); ?>">
            <input type="hidden" name="ntdst_fields[total]" id="quote_total" value="<?php echo esc_attr($quote['total'] ?? 0); ?>">
            <input type="hidden" name="ntdst_fields[discount]" id="quote_discount" value="<?php echo esc_attr($quote['discount'] ?? 0); ?>">
            <input type="hidden" name="ntdst_fields[voucher_code]" value="<?php echo esc_attr($quote['voucher_code'] ?? ''); ?>">
        </div>
        <?php
    }

    /**
     * Output quote admin styles
     */
    private function renderQuoteStyles(): void
    {
        ?>
        <style>
            /* Quote Admin Container */
            .stride-quote-admin {
                background: #fff;
                max-width: 900px;
            }

            /* Header */
            .stride-quote-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding-bottom: 15px;
                margin-bottom: 20px;
                border-bottom: 3px solid #2271b1;
            }

            .stride-quote-number-display .label {
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 1px;
                color: #666;
            }

            .stride-quote-number-display .number {
                font-size: 22px;
                font-weight: 600;
                color: #2271b1;
                margin-left: 8px;
            }

            .stride-quote-dates {
                text-align: right;
                font-size: 13px;
                color: #666;
            }

            .stride-quote-dates span {
                display: block;
            }

            /* Two Column Layout */
            .stride-quote-columns {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 30px;
                margin-bottom: 25px;
            }

            .stride-quote-billing h4,
            .stride-quote-details h4,
            .stride-quote-items-section h4 {
                margin: 0 0 15px 0;
                padding: 0 0 8px 0;
                border-bottom: 1px solid #ddd;
                font-size: 13px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #1d2327;
                font-weight: 600;
            }

            /* Form Fields */
            .stride-field-row {
                margin-bottom: 12px;
            }

            .stride-field-row.two-col {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }

            .stride-field label {
                display: block;
                font-weight: 600;
                font-size: 12px;
                color: #1d2327;
                margin-bottom: 4px;
            }

            .stride-field input[type="text"],
            .stride-field input[type="email"],
            .stride-field input[type="number"],
            .stride-field select {
                width: 100%;
                padding: 6px 8px;
                border: 1px solid #8c8f94;
                border-radius: 3px;
                font-size: 13px;
            }

            .stride-field input[readonly],
            .stride-field select[disabled] {
                background: #f6f7f7;
                color: #646970;
            }

            .stride-user-display {
                padding: 6px 0;
                font-size: 13px;
            }

            .stride-user-display a {
                font-weight: 600;
                color: #2271b1;
                text-decoration: none;
            }

            .stride-user-display .email {
                color: #646970;
            }

            .stride-voucher-display code {
                background: #dff0d8;
                color: #3c763d;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 13px;
            }

            /* Items Section */
            .stride-quote-items-section {
                margin-top: 20px;
            }

            /* Line Items Table */
            .stride-quote-items {
                margin-top: 0 !important;
                border: 1px solid #c3c4c7;
            }

            .stride-quote-items th {
                background: #f6f7f7;
                font-weight: 600;
                font-size: 12px;
            }

            .stride-quote-items th,
            .stride-quote-items td {
                padding: 10px 12px;
            }

            .stride-quote-items .description { width: 45%; }
            .stride-quote-items .qty { width: 10%; text-align: center; }
            .stride-quote-items .price { width: 15%; text-align: right; }
            .stride-quote-items .total { width: 15%; text-align: right; }
            .stride-quote-items .actions { width: 5%; text-align: center; }

            /* Editable inputs in items table */
            .stride-quote-items tbody input.item-title {
                width: 100%;
                padding: 4px 6px;
            }

            .stride-quote-items tbody input.item-qty {
                width: 60px;
                text-align: center;
                padding: 4px 6px;
            }

            .stride-quote-items tbody input.item-price {
                width: 80px;
                text-align: right;
                padding: 4px 6px;
            }

            .stride-quote-items tbody td.qty { text-align: center; }
            .stride-quote-items tbody td.price,
            .stride-quote-items tbody td.total { text-align: right; font-family: monospace; }

            .stride-quote-items .discount-row td { color: #d63638; }

            .stride-quote-items .actions .button-link {
                color: #a00;
                padding: 0;
            }

            .stride-quote-items .actions .button-link:hover {
                color: #dc3232;
            }

            .stride-quote-items .actions .dashicons {
                font-size: 18px;
                width: 18px;
                height: 18px;
            }

            /* Totals */
            .stride-quote-items tfoot td {
                text-align: right;
                font-family: monospace;
                padding: 8px 12px;
                background: #f9f9f9;
            }

            .stride-quote-items tfoot tr.subtotal td {
                border-top: 1px solid #ddd;
                padding-top: 12px;
            }

            .stride-quote-items tfoot tr.discount td {
                color: #d63638;
            }

            .stride-quote-items tfoot tr.grand-total td {
                border-top: 2px solid #1d2327;
                font-size: 15px;
                font-weight: 600;
                padding-top: 10px;
                padding-bottom: 10px;
                background: #f0f6fc;
            }

            /* Actions */
            .stride-quote-actions {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #ddd;
            }

            .stride-quote-actions .button {
                margin-right: 8px;
            }

            .stride-quote-actions .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
                vertical-align: text-top;
                margin-right: 4px;
            }

            .stride-readonly-notice {
                margin-top: 15px;
                padding: 10px 12px;
                background: #f6f7f7;
                border-left: 4px solid #dba617;
                color: #646970;
                font-size: 13px;
            }

            .stride-readonly-notice .dashicons {
                color: #dba617;
                margin-right: 6px;
            }

            /* Responsive */
            @media (max-width: 782px) {
                .stride-quote-columns {
                    grid-template-columns: 1fr;
                    gap: 20px;
                }

                .stride-field-row.two-col {
                    grid-template-columns: 1fr;
                    gap: 12px;
                }
            }
        </style>

        <script>
        jQuery(function($) {
            var itemIndex = $('#stride-quote-items-body tr').length;
            var taxRate = 0.21; // 21% BTW

            // Add new item row
            $('#stride-add-item').on('click', function(e) {
                e.preventDefault();

                var newRow = '<tr class="item-row" data-index="' + itemIndex + '">' +
                    '<td class="description">' +
                        '<input type="text" name="items[' + itemIndex + '][title]" value="" class="item-title" placeholder="<?php esc_attr_e('Omschrijving', 'stride'); ?>">' +
                        '<input type="hidden" name="items[' + itemIndex + '][type]" value="custom">' +
                    '</td>' +
                    '<td class="qty">' +
                        '<input type="number" name="items[' + itemIndex + '][quantity]" value="1" min="1" step="1" class="item-qty">' +
                    '</td>' +
                    '<td class="price">' +
                        '<input type="number" name="items[' + itemIndex + '][unit_price]" value="0" min="0" step="0.01" class="item-price">' +
                    '</td>' +
                    '<td class="total">€ 0,00</td>' +
                    '<td class="actions">' +
                        '<button type="button" class="button-link stride-remove-item" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">' +
                            '<span class="dashicons dashicons-trash"></span>' +
                        '</button>' +
                    '</td>' +
                '</tr>';

                $('#stride-quote-items-body').append(newRow);
                itemIndex++;
            });

            // Remove item row
            $(document).on('click', '.stride-remove-item', function(e) {
                e.preventDefault();
                $(this).closest('tr').remove();
                recalculateTotals();
            });

            // Recalculate on input change
            $(document).on('input change', '.item-qty, .item-price', function() {
                var row = $(this).closest('tr');
                var qty = parseFloat(row.find('.item-qty').val()) || 0;
                var price = parseFloat(row.find('.item-price').val()) || 0;
                var total = qty * price;
                row.find('td.total').text(formatCurrency(total));
            });

            // Recalculate button
            $('#stride-recalculate').on('click', function(e) {
                e.preventDefault();
                recalculateTotals();
            });

            function recalculateTotals() {
                var subtotal = 0;
                var discount = 0;

                $('#stride-quote-items-body tr').each(function() {
                    var qty = parseFloat($(this).find('.item-qty').val()) || 0;
                    var price = parseFloat($(this).find('.item-price').val()) || 0;
                    var type = $(this).find('input[name*="[type]"]').val() || 'course';
                    var total = qty * price;

                    $(this).find('td.total').text(formatCurrency(total));

                    if (type === 'discount') {
                        discount += Math.abs(total);
                    }
                    subtotal += total;
                });

                // subtotal already has discount subtracted (discount items are negative)
                var subtotalBeforeDiscount = subtotal + discount;
                var discountedSubtotal = Math.max(0, subtotal);
                var tax = discountedSubtotal * taxRate;
                var total = discountedSubtotal + tax;

                // Update displayed totals
                $('.stride-quote-items tfoot tr.subtotal td.amount').text(formatCurrency(subtotalBeforeDiscount));
                $('.stride-quote-items tfoot tr.tax td.amount').text(formatCurrency(tax));
                $('.stride-quote-items tfoot tr.grand-total td.amount').text(formatCurrency(total));

                // Update discount row if present
                if (discount > 0) {
                    $('.stride-quote-items tfoot tr.discount td.amount').text('- ' + formatCurrency(discount));
                }

                // Update hidden fields
                $('#quote_subtotal').val(subtotalBeforeDiscount.toFixed(2));
                $('#quote_tax').val(tax.toFixed(2));
                $('#quote_total').val(total.toFixed(2));
                $('#quote_discount').val(discount.toFixed(2));
            }

            function formatCurrency(amount) {
                return '€ ' + amount.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            }
        });
        </script>
        <?php
    }

    /**
     * Render form for new quotes (manual creation)
     */
    private function renderNewQuoteForm(\WP_Post $post): void
    {
        // Security nonce
        wp_nonce_field('stride_save_quote', 'stride_quote_nonce');
        ?>
        <div class="stride-new-quote-form">
            <style>
                .stride-new-quote-form {
                    max-width: 600px;
                }
                .stride-new-quote-info {
                    background: #f0f6fc;
                    border-left: 4px solid #2271b1;
                    padding: 12px 16px;
                    margin-bottom: 20px;
                }
                .stride-new-quote-form .form-field {
                    margin-bottom: 15px;
                }
                .stride-new-quote-form .form-field label {
                    display: block;
                    font-weight: 600;
                    margin-bottom: 5px;
                }
                .stride-new-quote-form .form-field input,
                .stride-new-quote-form .form-field select {
                    width: 100%;
                    max-width: 400px;
                }
                .stride-new-quote-form .form-field .description {
                    font-size: 12px;
                    color: #646970;
                    margin-top: 5px;
                }
            </style>

            <div class="stride-new-quote-info">
                <p><strong><?php esc_html_e('Nieuwe offerte', 'stride'); ?></strong></p>
                <p><?php esc_html_e('Offertes worden normaal automatisch aangemaakt bij inschrijving. U kunt hier ook handmatig een offerte aanmaken.', 'stride'); ?></p>
            </div>

            <div class="form-field">
                <label for="quote_user_id"><?php esc_html_e('Gebruiker', 'stride'); ?></label>
                <?php
                wp_dropdown_users([
                    'name' => 'ntdst_fields[user_id]',
                    'id' => 'quote_user_id',
                    'show_option_none' => __('Selecteer gebruiker...', 'stride'),
                    'option_none_value' => '',
                ]);
                ?>
            </div>

            <div class="form-field">
                <label for="quote_course_id"><?php esc_html_e('Cursus', 'stride'); ?></label>
                <select name="ntdst_fields[course_id]" id="quote_course_id">
                    <option value=""><?php esc_html_e('Selecteer cursus...', 'stride'); ?></option>
                    <?php
                    $courses = get_posts([
                        'post_type' => 'sfwd-courses',
                        'posts_per_page' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC',
                        'post_status' => 'publish',
                    ]);
                    foreach ($courses as $course) {
                        echo '<option value="' . esc_attr($course->ID) . '">' . esc_html($course->post_title) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <input type="hidden" name="ntdst_fields[status]" value="<?php echo esc_attr(self::STATUS_DRAFT); ?>">
            <input type="hidden" name="ntdst_fields[created_at]" value="<?php echo esc_attr(current_time('mysql')); ?>">

            <p class="description">
                <?php esc_html_e('Na opslaan worden de offerte details automatisch berekend op basis van de cursus prijs.', 'stride'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Format currency value
     */
    private function formatCurrency(float $amount): string
    {
        return '€ ' . esc_html(number_format($amount, 2, ',', '.'));
    }

    /**
     * Render actions sidebar metabox
     */
    public function renderActionsMetabox(\WP_Post $post): void
    {
        $quote = $this->getQuote($post->ID);

        // For new quotes, show simple save prompt
        if (!$quote) {
            ?>
            <div style="text-align: center; padding: 20px 0; color: #646970;">
                <span class="dashicons dashicons-edit" style="font-size: 32px; width: 32px; height: 32px; color: #ddd;"></span>
                <p><?php esc_html_e('Vul de gegevens in en sla op om een offerte aan te maken.', 'stride'); ?></p>
            </div>
            <?php
            return;
        }

        $status = $quote['status'];
        $isLocked = (bool) ($quote['locked'] ?? false);
        $isEditable = !$isLocked;
        $userId = $quote['user_id'];
        $user = get_userdata($userId);
        $defaultEmail = $quote['billing']['email'] ?? ($user ? $user->user_email : '');
        $lastSentTo = $quote['last_sent_to'] ?? '';

        $statusConfig = [
            self::STATUS_DRAFT => [
                'label' => __('Concept', 'stride'),
                'color' => '#dba617',
                'bg' => '#fcf9e8',
                'icon' => 'edit',
            ],
            self::STATUS_SENT => [
                'label' => __('Verzonden', 'stride'),
                'color' => '#0073aa',
                'bg' => '#e5f5fa',
                'icon' => 'email',
            ],
            self::STATUS_EXPORTED => [
                'label' => __('Geëxporteerd', 'stride'),
                'color' => '#46b450',
                'bg' => '#ecf7ed',
                'icon' => 'yes-alt',
            ],
        ];

        $config = $statusConfig[$status] ?? $statusConfig[self::STATUS_DRAFT];
        ?>
        <style>
            .stride-sidebar-status {
                text-align: center;
                padding: 15px;
                margin: -6px -12px 15px -12px;
                background: <?php echo esc_attr($config['bg']); ?>;
                border-bottom: 2px solid <?php echo esc_attr($config['color']); ?>;
            }
            .stride-sidebar-status .dashicons {
                font-size: 24px;
                width: 24px;
                height: 24px;
                color: <?php echo esc_attr($config['color']); ?>;
            }
            .stride-sidebar-status .status-label {
                display: block;
                font-size: 14px;
                font-weight: 600;
                color: <?php echo esc_attr($config['color']); ?>;
                margin-top: 5px;
            }
            .stride-sidebar-status .lock-badge {
                display: inline-block;
                margin-top: 8px;
                padding: 2px 8px;
                background: #d63638;
                color: #fff;
                font-size: 11px;
                border-radius: 3px;
            }
            .stride-sidebar-total {
                background: #f6f7f7;
                padding: 12px;
                margin: 0 -12px 15px -12px;
                text-align: center;
            }
            .stride-sidebar-total .amount {
                font-size: 24px;
                font-weight: 600;
                color: #1d2327;
            }
            .stride-sidebar-total .currency {
                font-size: 14px;
                color: #646970;
            }
            .stride-sidebar-meta {
                margin: 0 0 15px 0;
                padding: 0;
                list-style: none;
            }
            .stride-sidebar-meta li {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #f0f0f1;
                font-size: 13px;
            }
            .stride-sidebar-meta li:last-child {
                border-bottom: none;
            }
            .stride-sidebar-meta .meta-label {
                color: #646970;
            }
            .stride-sidebar-meta .meta-value {
                font-weight: 500;
                color: #1d2327;
            }
            .stride-sidebar-section {
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 1px solid #f0f0f1;
            }
            .stride-sidebar-section:last-child {
                margin-bottom: 0;
                padding-bottom: 0;
                border-bottom: none;
            }
            .stride-sidebar-section h4 {
                margin: 0 0 10px 0;
                padding: 0;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #646970;
            }
            .stride-sidebar-actions {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .stride-sidebar-actions .button {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 5px;
            }
            .stride-sidebar-actions .button .dashicons {
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .stride-action-row {
                display: flex;
                gap: 8px;
            }
            .stride-action-row .button {
                flex: 1;
            }
            .stride-send-form {
                background: #f6f7f7;
                padding: 12px;
                margin: 10px -12px;
                border-top: 1px solid #ddd;
                border-bottom: 1px solid #ddd;
            }
            .stride-send-form label {
                display: block;
                font-size: 12px;
                font-weight: 600;
                margin-bottom: 4px;
                color: #1d2327;
            }
            .stride-send-form input[type="email"] {
                width: 100%;
                margin-bottom: 8px;
            }
            .stride-send-form .help-text {
                font-size: 11px;
                color: #646970;
                margin-bottom: 8px;
            }
            .stride-status-select {
                width: 100%;
                margin-bottom: 8px;
            }

            /* Voucher/Discount */
            .stride-voucher-applied {
                display: flex;
                align-items: center;
                gap: 8px;
                background: #ecf7ed;
                border: 1px solid #00a32a;
                padding: 8px 10px;
                border-radius: 3px;
            }
            .stride-voucher-applied .voucher-info {
                display: flex;
                align-items: center;
                gap: 4px;
                flex: 1;
            }
            .stride-voucher-applied .voucher-info .dashicons {
                color: #00a32a;
                font-size: 16px;
                width: 16px;
                height: 16px;
            }
            .stride-voucher-applied code {
                background: transparent;
                font-size: 12px;
                color: #1d2327;
            }
            .stride-voucher-applied .voucher-amount {
                font-weight: 600;
                color: #00a32a;
                font-size: 13px;
            }
            .stride-voucher-applied .stride-remove-voucher {
                color: #a00;
                padding: 0;
            }
            .stride-voucher-applied .stride-remove-voucher:hover {
                color: #dc3232;
            }
            .stride-voucher-form .voucher-input-row,
            .stride-voucher-form .discount-input-row {
                display: flex;
                gap: 6px;
            }
            .stride-voucher-form .discount-divider {
                text-align: center;
                font-size: 11px;
                color: #646970;
                margin: 8px 0;
                text-transform: uppercase;
            }
            .stride-voucher-form input[type="text"],
            .stride-voucher-form input[type="number"] {
                min-width: 0;
            }
        </style>

        <!-- Status Header -->
        <div class="stride-sidebar-status">
            <span class="dashicons dashicons-<?php echo esc_attr($config['icon']); ?>"></span>
            <span class="status-label"><?php echo esc_html($config['label']); ?></span>
            <?php if ($isLocked): ?>
                <span class="lock-badge">
                    <span class="dashicons dashicons-lock" style="font-size: 12px; width: 12px; height: 12px; vertical-align: middle;"></span>
                    <?php esc_html_e('Vergrendeld', 'stride'); ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- Total -->
        <div class="stride-sidebar-total">
            <span class="currency"><?php esc_html_e('Totaal', 'stride'); ?></span><br>
            <span class="amount"><?php echo $this->formatCurrency($quote['total']); ?></span>
        </div>

        <!-- Meta Info -->
        <ul class="stride-sidebar-meta">
            <li>
                <span class="meta-label"><?php esc_html_e('Aangemaakt', 'stride'); ?></span>
                <span class="meta-value"><?php echo esc_html($quote['created_at'] ? date_i18n('d M Y', strtotime($quote['created_at'])) : '-'); ?></span>
            </li>
            <li>
                <span class="meta-label"><?php esc_html_e('Geldig tot', 'stride'); ?></span>
                <?php if ($isEditable): ?>
                    <input type="date" name="ntdst_fields[valid_until]" class="stride-date-input"
                           value="<?php echo esc_attr($quote['valid_until'] ? date('Y-m-d', strtotime($quote['valid_until'])) : ''); ?>"
                           style="width: 100%; margin-top: 4px; padding: 4px 6px; font-size: 12px; border: 1px solid #8c8f94; border-radius: 3px;">
                <?php else: ?>
                    <span class="meta-value"><?php echo esc_html($quote['valid_until'] ? date_i18n('d M Y', strtotime($quote['valid_until'])) : '-'); ?></span>
                <?php endif; ?>
            </li>
            <?php if ($quote['sent_at']): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Verzonden', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y H:i', strtotime($quote['sent_at']))); ?></span>
                </li>
            <?php endif; ?>
            <?php if ($lastSentTo): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Verzonden naar', 'stride'); ?></span>
                    <span class="meta-value" style="word-break: break-all; font-size: 11px;"><?php echo esc_html($lastSentTo); ?></span>
                </li>
            <?php endif; ?>
            <?php if ($quote['exported_at']): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Geëxporteerd', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($quote['exported_at']))); ?></span>
                </li>
            <?php endif; ?>
        </ul>

        <!-- View Actions -->
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Bekijken', 'stride'); ?></h4>
            <div class="stride-sidebar-actions">
                <div class="stride-action-row">
                    <?php if (!empty($quote['pdf_path'])): ?>
                        <a href="<?php echo esc_url($this->getQuoteUrl($post->ID)); ?>" class="button" target="_blank">
                            <span class="dashicons dashicons-pdf"></span>
                            <?php esc_html_e('PDF', 'stride'); ?>
                        </a>
                    <?php else: ?>
                        <button type="button" class="button" disabled title="<?php esc_attr_e('PDF nog niet gegenereerd', 'stride'); ?>">
                            <span class="dashicons dashicons-pdf"></span>
                            <?php esc_html_e('PDF', 'stride'); ?>
                        </button>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($this->getQuoteFormUrl($post->ID)); ?>" class="button" target="_blank">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php esc_html_e('Formulier', 'stride'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Send Quote -->
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Verzenden', 'stride'); ?></h4>

            <div class="stride-send-form" id="stride-send-form">
                <label for="stride_send_to"><?php esc_html_e('Naar', 'stride'); ?></label>
                <input type="email" id="stride_send_to" name="stride_send_to"
                       value="<?php echo esc_attr($defaultEmail); ?>"
                       placeholder="klant@email.com">

                <label for="stride_send_cc"><?php esc_html_e('CC (optioneel)', 'stride'); ?></label>
                <input type="email" id="stride_send_cc" name="stride_send_cc"
                       value=""
                       placeholder="kopie@email.com">

                <p class="help-text"><?php esc_html_e('De offerte PDF wordt als bijlage verzonden.', 'stride'); ?></p>

                <button type="button" class="button button-primary" id="stride-send-quote-btn" style="width: 100%;">
                    <span class="dashicons dashicons-email"></span>
                    <?php esc_html_e('Verzenden', 'stride'); ?>
                </button>
            </div>

            <input type="hidden" name="stride_send_quote" id="stride_send_quote" value="">
        </div>

        <!-- Discount / Voucher -->
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Korting', 'stride'); ?></h4>

            <?php
            $currentVoucher = $quote['voucher_code'] ?? '';
            $currentDiscount = $quote['discount'] ?? 0;
            ?>

            <?php if ($currentVoucher): ?>
                <div class="stride-voucher-applied">
                    <div class="voucher-info">
                        <span class="dashicons dashicons-tag"></span>
                        <code><?php echo esc_html($currentVoucher); ?></code>
                    </div>
                    <?php if ($currentDiscount > 0): ?>
                        <div class="voucher-amount">- <?php echo $this->formatCurrency($currentDiscount); ?></div>
                    <?php endif; ?>
                    <?php if ($isEditable): ?>
                        <button type="button" class="button-link stride-remove-voucher" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($isEditable): ?>
                <div class="stride-voucher-form" <?php echo $currentVoucher ? 'style="display:none;"' : ''; ?>>
                    <div class="voucher-input-row">
                        <input type="text" id="stride_voucher_code" name="stride_voucher_code"
                               placeholder="<?php esc_attr_e('Vouchercode', 'stride'); ?>"
                               style="flex: 1;">
                        <button type="button" class="button" id="stride-apply-voucher">
                            <?php esc_html_e('Toepassen', 'stride'); ?>
                        </button>
                    </div>

                    <div class="discount-divider"><?php esc_html_e('of', 'stride'); ?></div>

                    <div class="discount-input-row">
                        <input type="number" id="stride_manual_discount" name="stride_manual_discount"
                               placeholder="<?php esc_attr_e('Bedrag', 'stride'); ?>"
                               min="0" step="0.01" style="flex: 1;">
                        <button type="button" class="button" id="stride-apply-discount">
                            <?php esc_html_e('Korting', 'stride'); ?>
                        </button>
                    </div>
                </div>
            <?php elseif (!$currentVoucher && $currentDiscount <= 0): ?>
                <p class="description" style="margin: 0; color: #646970;">
                    <?php esc_html_e('Geen korting toegepast.', 'stride'); ?>
                </p>
            <?php endif; ?>

            <input type="hidden" name="stride_remove_voucher" id="stride_remove_voucher" value="">
            <input type="hidden" name="stride_apply_voucher" id="stride_apply_voucher_action" value="">
            <input type="hidden" name="stride_apply_discount" id="stride_apply_discount_action" value="">
        </div>

        <!-- Status Change -->
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Status wijzigen', 'stride'); ?></h4>
            <select name="stride_change_status" id="stride_change_status" class="stride-status-select">
                <option value=""><?php esc_html_e('— Geen wijziging —', 'stride'); ?></option>
                <option value="<?php echo esc_attr(self::STATUS_DRAFT); ?>" <?php selected($status, self::STATUS_DRAFT); ?>>
                    <?php esc_html_e('Concept', 'stride'); ?>
                </option>
                <option value="<?php echo esc_attr(self::STATUS_SENT); ?>" <?php selected($status, self::STATUS_SENT); ?>>
                    <?php esc_html_e('Verzonden', 'stride'); ?>
                </option>
                <option value="<?php echo esc_attr(self::STATUS_EXPORTED); ?>" <?php selected($status, self::STATUS_EXPORTED); ?>>
                    <?php esc_html_e('Geëxporteerd', 'stride'); ?>
                </option>
            </select>

            <div class="stride-sidebar-actions">
                <div class="stride-action-row">
                    <?php if ($isLocked): ?>
                        <button type="button" class="button" id="stride-unlock-btn">
                            <span class="dashicons dashicons-unlock"></span>
                            <?php esc_html_e('Ontgrendelen', 'stride'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="button" id="stride-lock-btn">
                            <span class="dashicons dashicons-lock"></span>
                            <?php esc_html_e('Vergrendelen', 'stride'); ?>
                        </button>
                    <?php endif; ?>

                    <button type="button" class="button" id="stride-regenerate-pdf-btn" title="<?php esc_attr_e('PDF opnieuw genereren', 'stride'); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('PDF', 'stride'); ?>
                    </button>
                </div>
            </div>

            <input type="hidden" name="stride_lock_action" id="stride_lock_action" value="">
            <input type="hidden" name="stride_regenerate_pdf" id="stride_regenerate_pdf" value="">
        </div>

        <script>
        jQuery(function($) {
            // Send quote button
            $('#stride-send-quote-btn').on('click', function(e) {
                e.preventDefault();
                var sendTo = $('#stride_send_to').val();
                if (!sendTo) {
                    alert('<?php esc_attr_e('Vul een e-mailadres in.', 'stride'); ?>');
                    return;
                }
                $('#stride_send_quote').val('1');
                $('#publish').click();
            });

            // Lock/Unlock buttons
            $('#stride-lock-btn, #stride-unlock-btn').on('click', function(e) {
                e.preventDefault();
                var action = $(this).attr('id') === 'stride-lock-btn' ? 'lock' : 'unlock';
                $('#stride_lock_action').val(action);
                $('#publish').click();
            });

            // Regenerate PDF button
            $('#stride-regenerate-pdf-btn').on('click', function(e) {
                e.preventDefault();
                $('#stride_regenerate_pdf').val('1');
                $('#publish').click();
            });

            // Apply voucher
            $('#stride-apply-voucher').on('click', function(e) {
                e.preventDefault();
                var code = $('#stride_voucher_code').val().trim();
                if (!code) {
                    alert('<?php esc_attr_e('Vul een vouchercode in.', 'stride'); ?>');
                    return;
                }
                $('#stride_apply_voucher_action').val(code);
                $('#publish').click();
            });

            // Apply manual discount
            $('#stride-apply-discount').on('click', function(e) {
                e.preventDefault();
                var amount = parseFloat($('#stride_manual_discount').val()) || 0;
                if (amount <= 0) {
                    alert('<?php esc_attr_e('Vul een kortingsbedrag in.', 'stride'); ?>');
                    return;
                }
                $('#stride_apply_discount_action').val(amount);
                $('#publish').click();
            });

            // Remove voucher
            $('.stride-remove-voucher').on('click', function(e) {
                e.preventDefault();
                if (!confirm('<?php esc_attr_e('Voucher verwijderen?', 'stride'); ?>')) return;
                $('#stride_remove_voucher').val('1');
                $('#publish').click();
            });
        });
        </script>
        <?php
    }

    /**
     * Get public URL for quote PDF
     */
    public function getQuoteUrl(int $quoteId): string
    {
        $quote = $this->getQuote($quoteId);
        if (!$quote || empty($quote['pdf_path'])) {
            return '';
        }

        // Return URL to protected PDF download endpoint
        return add_query_arg([
            'stride_quote' => $quoteId,
            'action' => 'download_pdf',
        ], home_url('/'));
    }

    /**
     * Get public URL for quote form (customer view)
     */
    public function getQuoteFormUrl(int $quoteId): string
    {
        return add_query_arg([
            'stride_quote' => $quoteId,
            'action' => 'view',
        ], home_url('/offerte/'));
    }

    /**
     * Render notes metabox - unified timeline with type selector
     */
    public function renderNotesMetabox(\WP_Post $post): void
    {
        $quote = $this->getQuote($post->ID);

        if (!$quote) {
            echo '<p class="description">' . esc_html__('Sla de offerte eerst op om notities toe te voegen.', 'stride') . '</p>';
            return;
        }

        $notes = $quote['notes'] ?? [];
        $currentUser = wp_get_current_user();

        // Sort notes by date descending (newest first)
        usort($notes, fn($a, $b) => strtotime($b['date'] ?? 0) - strtotime($a['date'] ?? 0));
        ?>
        <style>
            .stride-notes-timeline {
                max-height: 350px;
                overflow-y: auto;
                margin-bottom: 15px;
                padding-right: 5px;
            }

            .stride-note-item {
                display: flex;
                gap: 10px;
                padding: 10px 0;
                border-bottom: 1px solid #f0f0f1;
                position: relative;
            }

            .stride-note-item:last-child {
                border-bottom: none;
            }

            .stride-note-icon {
                flex-shrink: 0;
                width: 28px;
                height: 28px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-size: 14px;
            }

            .stride-note-icon.admin {
                background: #2271b1;
            }

            .stride-note-icon.customer {
                background: #00a32a;
            }

            .stride-note-body {
                flex: 1;
                min-width: 0;
            }

            .stride-note-meta {
                font-size: 11px;
                color: #646970;
                margin-bottom: 3px;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .stride-note-meta .author {
                font-weight: 600;
                color: #1d2327;
            }

            .stride-note-meta .type-badge {
                font-size: 10px;
                padding: 1px 6px;
                border-radius: 3px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }

            .stride-note-meta .type-badge.admin {
                background: #e5f5fa;
                color: #2271b1;
            }

            .stride-note-meta .type-badge.customer {
                background: #ecf7ed;
                color: #00a32a;
            }

            .stride-note-content {
                font-size: 13px;
                color: #1d2327;
                white-space: pre-wrap;
                word-break: break-word;
            }

            .stride-note-delete {
                position: absolute;
                top: 10px;
                right: 0;
                color: #a00;
                cursor: pointer;
                opacity: 0;
                transition: opacity 0.2s;
            }

            .stride-note-item:hover .stride-note-delete {
                opacity: 1;
            }

            .stride-note-delete:hover {
                color: #dc3232;
            }

            .stride-empty-notes {
                color: #646970;
                font-style: italic;
                padding: 20px;
                text-align: center;
                background: #f9f9f9;
                border: 1px dashed #ddd;
            }

            .stride-add-note-form {
                background: #f6f7f7;
                padding: 12px;
                border: 1px solid #ddd;
            }

            .stride-add-note-form textarea {
                width: 100%;
                height: 60px;
                margin-bottom: 10px;
                resize: vertical;
            }

            .stride-add-note-form .form-row {
                display: flex;
                align-items: center;
                gap: 15px;
            }

            .stride-add-note-form .type-selector {
                display: flex;
                gap: 12px;
            }

            .stride-add-note-form .type-selector label {
                display: flex;
                align-items: center;
                gap: 4px;
                font-size: 13px;
                cursor: pointer;
            }

            .stride-add-note-form .type-selector input[type="radio"] {
                margin: 0;
            }

            .stride-add-note-form .type-icon {
                width: 16px;
                height: 16px;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-size: 10px;
            }

            .stride-add-note-form .type-icon.admin {
                background: #2271b1;
            }

            .stride-add-note-form .type-icon.customer {
                background: #00a32a;
            }

            .stride-add-note-form .button {
                margin-left: auto;
            }
        </style>

        <!-- Notes Timeline -->
        <div class="stride-notes-timeline" id="stride-notes-list">
            <?php if (empty($notes)): ?>
                <div class="stride-empty-notes">
                    <?php esc_html_e('Nog geen notities toegevoegd.', 'stride'); ?>
                </div>
            <?php else: ?>
                <?php foreach ($notes as $index => $note):
                    $isCustomer = ($note['type'] ?? '') === self::NOTE_TYPE_CUSTOMER;
                    $typeClass = $isCustomer ? 'customer' : 'admin';
                    $typeLabel = $isCustomer ? __('Klant', 'stride') : __('Intern', 'stride');
                    $icon = $isCustomer ? 'format-quote' : 'shield';
                ?>
                    <div class="stride-note-item" data-index="<?php echo esc_attr($index); ?>">
                        <div class="stride-note-icon <?php echo esc_attr($typeClass); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span>
                        </div>
                        <div class="stride-note-body">
                            <div class="stride-note-meta">
                                <span class="author"><?php echo esc_html($note['author'] ?? 'Onbekend'); ?></span>
                                <span class="type-badge <?php echo esc_attr($typeClass); ?>"><?php echo esc_html($typeLabel); ?></span>
                                <span class="date"><?php echo esc_html(date_i18n('d M Y H:i', strtotime($note['date'] ?? ''))); ?></span>
                            </div>
                            <div class="stride-note-content"><?php echo esc_html($note['content'] ?? ''); ?></div>
                        </div>
                        <span class="stride-note-delete dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Add Note Form -->
        <div class="stride-add-note-form">
            <textarea id="stride-new-note" placeholder="<?php esc_attr_e('Schrijf een notitie...', 'stride'); ?>"></textarea>
            <div class="form-row">
                <div class="type-selector">
                    <label>
                        <input type="radio" name="stride_note_type" value="<?php echo esc_attr(self::NOTE_TYPE_ADMIN); ?>" checked>
                        <span class="type-icon admin"><span class="dashicons dashicons-shield"></span></span>
                        <?php esc_html_e('Intern', 'stride'); ?>
                    </label>
                    <label>
                        <input type="radio" name="stride_note_type" value="<?php echo esc_attr(self::NOTE_TYPE_CUSTOMER); ?>">
                        <span class="type-icon customer"><span class="dashicons dashicons-format-quote"></span></span>
                        <?php esc_html_e('Klant (op offerte)', 'stride'); ?>
                    </label>
                </div>
                <button type="button" class="button" id="stride-add-note-btn">
                    <span class="dashicons dashicons-plus-alt2" style="margin-top: 3px;"></span>
                    <?php esc_html_e('Toevoegen', 'stride'); ?>
                </button>
            </div>
        </div>

        <!-- Hidden field to store notes JSON -->
        <input type="hidden" name="stride_notes_data" id="stride_notes_data" value="<?php echo esc_attr(wp_json_encode($notes)); ?>">

        <script>
        jQuery(function($) {
            var notesData = <?php echo wp_json_encode($notes ?: []); ?>;
            var currentUser = '<?php echo esc_js($currentUser->display_name); ?>';

            function updateNotesField() {
                $('#stride_notes_data').val(JSON.stringify(notesData));
            }

            function escapeHtml(text) {
                var div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            function renderNote(note, index) {
                var isCustomer = note.type === '<?php echo self::NOTE_TYPE_CUSTOMER; ?>';
                var typeClass = isCustomer ? 'customer' : 'admin';
                var typeLabel = isCustomer ? '<?php esc_html_e('Klant', 'stride'); ?>' : '<?php esc_html_e('Intern', 'stride'); ?>';
                var icon = isCustomer ? 'format-quote' : 'shield';

                var html = '<div class="stride-note-item" data-index="' + index + '">' +
                    '<div class="stride-note-icon ' + typeClass + '">' +
                        '<span class="dashicons dashicons-' + icon + '"></span>' +
                    '</div>' +
                    '<div class="stride-note-body">' +
                        '<div class="stride-note-meta">' +
                            '<span class="author">' + escapeHtml(note.author) + '</span>' +
                            '<span class="type-badge ' + typeClass + '">' + typeLabel + '</span>' +
                            '<span class="date">' + note.date_formatted + '</span>' +
                        '</div>' +
                        '<div class="stride-note-content">' + escapeHtml(note.content) + '</div>' +
                    '</div>' +
                    '<span class="stride-note-delete dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>' +
                '</div>';

                // Remove empty state and prepend new note (newest first)
                $('#stride-notes-list .stride-empty-notes').remove();
                $('#stride-notes-list').prepend(html);
            }

            // Add note
            $('#stride-add-note-btn').on('click', function() {
                var content = $('#stride-new-note').val().trim();
                if (!content) return;

                var noteType = $('input[name="stride_note_type"]:checked').val();

                var note = {
                    type: noteType,
                    content: content,
                    author: currentUser,
                    date: new Date().toISOString(),
                    date_formatted: new Date().toLocaleString('nl-BE', {day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'})
                };

                notesData.unshift(note); // Add to beginning (newest first)
                updateNotesField();
                renderNote(note, 0);
                $('#stride-new-note').val('');

                // Re-index existing notes
                $('#stride-notes-list .stride-note-item').each(function(i) {
                    $(this).data('index', i);
                });
            });

            // Delete note
            $(document).on('click', '.stride-note-delete', function() {
                if (!confirm('<?php esc_attr_e('Notitie verwijderen?', 'stride'); ?>')) return;

                var $item = $(this).closest('.stride-note-item');
                var index = parseInt($item.data('index'), 10);

                // Mark as deleted
                if (notesData[index]) {
                    notesData[index]._deleted = true;
                }

                updateNotesField();
                $item.fadeOut(200, function() {
                    $(this).remove();

                    // Show empty state if no visible notes left
                    if ($('#stride-notes-list .stride-note-item').length === 0) {
                        $('#stride-notes-list').html('<div class="stride-empty-notes"><?php esc_html_e('Nog geen notities toegevoegd.', 'stride'); ?></div>');
                    }
                });
            });
        });
        </script>
        <?php
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
            $discount = $this->calculateVoucherDiscount($voucherCode, $courseId, $coursePrice);
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
     * @param float $coursePrice Pre-fetched course price (avoids duplicate query)
     * @return float Discount amount (0 if invalid)
     */
    private function calculateVoucherDiscount(string $voucherCode, int $courseId, float $coursePrice): float
    {
        if (!$this->voucherService) {
            return 0.0;
        }

        $voucher = $this->voucherService->validateVoucher($voucherCode, $courseId);
        if (is_wp_error($voucher)) {
            return 0.0;
        }

        // Pass course price to avoid refetching
        return $this->voucherService->calculateDiscount($voucher, $courseId, $coursePrice);
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
