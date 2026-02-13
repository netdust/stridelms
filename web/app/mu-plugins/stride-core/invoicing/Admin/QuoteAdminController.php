<?php

namespace ntdst\Stride\invoicing\Admin;

defined('ABSPATH') || exit;

use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\invoicing\Helpers\QuoteCalculator;
use ntdst\Stride\invoicing\Helpers\QuoteAuditLogger;
use ntdst\Stride\invoicing\Helpers\QuoteItemFactory;
use ntdst\Stride\invoicing\Helpers\VATValidator;
use ntdst\Stride\invoicing\Support\CurrencyFormatter;

/**
 * Quote Admin Controller
 *
 * Handles admin interface for quotes:
 * - Registers metaboxes
 * - Enqueues admin assets
 * - Delegates save operations
 *
 * This class is instantiated by QuoteService in admin context.
 * Not a service - just a plain admin handler class.
 *
 * @package stride\services\invoicing\Admin
 */
class QuoteAdminController
{
    private QuoteService $quoteService;
    private QuoteAuditLogger $auditLogger;
    private ?VATValidator $vatValidator;

    /**
     * Constructor
     */
    public function __construct(
        ?QuoteService $quoteService = null,
        ?QuoteAuditLogger $auditLogger = null,
        ?VATValidator $vatValidator = null
    ) {
        $this->quoteService = $quoteService ?? $this->resolveService(QuoteService::class);
        $this->auditLogger = $auditLogger ?? new QuoteAuditLogger();
        $this->vatValidator = $vatValidator ?? new VATValidator();

        // Register hooks
        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . QuoteService::POST_TYPE, [$this, 'handleSave'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_notices', [$this, 'showVatValidationNotice']);

        // AJAX handler for user data
        add_action('wp_ajax_stride_get_user_data', [$this, 'ajaxGetUserData']);
    }

    /**
     * Register metaboxes
     */
    public function registerMetaboxes(): void
    {
        // Remove default editor
        remove_post_type_support(QuoteService::POST_TYPE, 'editor');

        // Main quote overview
        add_meta_box(
            'stride_quote_overview',
            __('Offerte', 'stride'),
            [$this, 'renderOverviewMetabox'],
            QuoteService::POST_TYPE,
            'normal',
            'high'
        );

        // Status & actions sidebar
        add_meta_box(
            'stride_quote_status',
            __('Status & Acties', 'stride'),
            [$this, 'renderActionsMetabox'],
            QuoteService::POST_TYPE,
            'side',
            'high'
        );

        // Notes metabox
        add_meta_box(
            'stride_quote_notes',
            __('Notities', 'stride'),
            [$this, 'renderNotesMetabox'],
            QuoteService::POST_TYPE,
            'normal',
            'default'
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets(string $hook): void
    {
        global $post_type;

        if ($post_type !== QuoteService::POST_TYPE) {
            return;
        }

        // Select2 from CDN
        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0'
        );
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0',
            true
        );

        // Quote admin styles
        wp_enqueue_style(
            'stride-quote-admin',
            get_stylesheet_directory_uri() . '/assets/css/admin/quote-admin.css',
            [],
            filemtime(get_stylesheet_directory() . '/assets/css/admin/quote-admin.css')
        );

        // Quote admin scripts
        wp_enqueue_script(
            'stride-quote-admin',
            get_stylesheet_directory_uri() . '/assets/js/admin/quote-admin.js',
            ['jquery', 'select2'],
            filemtime(get_stylesheet_directory() . '/assets/js/admin/quote-admin.js'),
            true
        );

        // Localize script
        $currentUser = wp_get_current_user();
        wp_localize_script('stride-quote-admin', 'strideQuoteAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stride_quote_admin'),
            'currentUser' => $currentUser->display_name ?: 'Admin',
            'i18n' => [
                'searchCustomer' => __('Zoek klant...', 'stride'),
                'searchCourse' => __('Zoek cursus...', 'stride'),
                'noResults' => __('Geen resultaten gevonden', 'stride'),
                'searching' => __('Zoeken...', 'stride'),
                'typeToSearch' => __('Typ om te zoeken...', 'stride'),
                'description' => __('Omschrijving', 'stride'),
                'remove' => __('Verwijderen', 'stride'),
                'enterNote' => __('Vul een notitie in.', 'stride'),
                'noNotes' => __('Nog geen notities toegevoegd.', 'stride'),
                'customer' => __('Klant', 'stride'),
                'internal' => __('Intern', 'stride'),
                'confirmDelete' => __('Notitie verwijderen?', 'stride'),
                'enterEmail' => __('Vul een e-mailadres in.', 'stride'),
                'enterVoucher' => __('Vul een vouchercode in.', 'stride'),
                'enterDiscount' => __('Vul een kortingsbedrag in.', 'stride'),
                'confirmRemoveDiscount' => __('Korting verwijderen?', 'stride'),
            ],
        ]);
    }

    /**
     * Render overview metabox
     */
    public function renderOverviewMetabox(\WP_Post $post): void
    {
        $metabox = new QuoteOverviewMetabox($this->quoteService);
        $metabox->render($post);
    }

    /**
     * Render actions metabox
     */
    public function renderActionsMetabox(\WP_Post $post): void
    {
        $metabox = new QuoteActionsMetabox($this->quoteService);
        $metabox->render($post);
    }

    /**
     * Render notes metabox
     */
    public function renderNotesMetabox(\WP_Post $post): void
    {
        $metabox = new QuoteNotesMetabox($this->quoteService);
        $metabox->render($post);
    }

    /**
     * Handle save operations
     */
    public function handleSave(int $postId, \WP_Post $post): void
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

        $quote = $this->quoteService->getQuote($postId);

        // Handle new quote creation
        if (!$quote) {
            $this->handleNewQuoteCreation($postId, $post);
            return;
        }

        $model = $this->getModel();
        if (!$model) {
            return;
        }

        $updateData = [];
        $isLocked = (bool) ($quote['locked'] ?? false);
        $isEditable = !$isLocked;

        // Process billing data
        if ($isEditable && isset($_POST['billing']) && is_array($_POST['billing'])) {
            $billing = $this->processBillingData($quote, $_POST['billing'], $postId);
            $updateData[QuoteService::FIELD_BILLING] = $billing;
        }

        // Process items data
        if ($isEditable && isset($_POST['items']) && is_array($_POST['items'])) {
            $itemsResult = QuoteCalculator::processItemsInput($_POST['items']);
            $updateData[QuoteService::FIELD_ITEMS] = $itemsResult['items'];
            $updateData[QuoteService::FIELD_SUBTOTAL] = $itemsResult['subtotal'];
            $updateData[QuoteService::FIELD_DISCOUNT] = $itemsResult['discount'];
            $updateData[QuoteService::FIELD_TAX] = $itemsResult['tax'];
            $updateData[QuoteService::FIELD_TOTAL] = $itemsResult['total'];
        }

        // Process notes data
        if (isset($_POST['stride_notes_data'])) {
            $notes = $this->processNotesData($_POST['stride_notes_data']);
            $updateData[QuoteService::FIELD_NOTES] = $notes;
        }

        // Handle lock/unlock action
        if (!empty($_POST['stride_lock_action'])) {
            $this->handleLockAction($postId, $_POST['stride_lock_action'], $updateData);
        }

        // Handle status change
        if (!empty($_POST['stride_change_status'])) {
            $this->handleStatusChange($postId, $quote, $_POST['stride_change_status'], $updateData);
        }

        // Update if we have data
        if (!empty($updateData)) {
            $model->update($postId, $updateData);
            do_action('stride/quote/updated', $postId, $updateData);
        }

        // Handle send quote action
        if (!empty($_POST['stride_send_quote'])) {
            $sendTo = sanitize_email($_POST['stride_send_to'] ?? '');
            $sendCc = sanitize_email($_POST['stride_send_cc'] ?? '');
            if ($sendTo) {
                do_action('stride/quote/send_email', $postId, $sendTo, $sendCc, $quote);
            }
        }

        // Handle PDF regeneration
        if (!empty($_POST['stride_regenerate_pdf'])) {
            do_action('stride/quote/regenerate_pdf', $postId);
            $this->auditLogger->logPdfRegenerated($postId);
        }

        // Handle voucher/discount actions
        $this->handleVoucherActions($postId, $quote);
    }

    /**
     * Process billing data with VAT validation
     */
    private function processBillingData(array $quote, array $billingInput, int $postId): array
    {
        $billing = $quote['billing'];
        $billingFields = ['organisation', 'email', 'address', 'postal_code', 'city', 'vat_number', 'gln_number'];

        foreach ($billingFields as $field) {
            if (isset($billingInput[$field])) {
                $billing[$field] = sanitize_text_field($billingInput[$field]);
            }
        }

        // Re-validate VAT if changed
        $newVat = $billing['vat_number'] ?? '';
        $oldVat = $quote['billing']['vat_number'] ?? '';

        if (!empty($newVat) && $newVat !== $oldVat) {
            $vatResult = $this->vatValidator->validate($newVat);
            $billing['vat_validated'] = $vatResult['valid'];
            $billing['vat_source'] = $vatResult['source'] ?? 'unknown';

            // Store validation result for admin notice
            if (!$vatResult['valid'] || $vatResult['source'] === 'format_only') {
                set_transient(
                    'stride_vat_notice_' . get_current_user_id(),
                    [
                        'post_id' => $postId,
                        'vat_number' => $newVat,
                        'source' => $vatResult['source'] ?? 'vies',
                        'error' => $vatResult['error'] ?? null,
                        'vies_error' => $vatResult['vies_error'] ?? null,
                    ],
                    60
                );
            }

            // Auto-fill company from VIES
            if ($vatResult['valid'] && !empty($vatResult['name']) && empty($billing['organisation'])) {
                $billing['organisation'] = $vatResult['name'];
            }
        }

        return $billing;
    }

    /**
     * Process notes data
     */
    private function processNotesData(string $notesJson): array
    {
        $notesJson = wp_unslash($notesJson);
        $notes = json_decode($notesJson, true);

        if (!is_array($notes)) {
            return [];
        }

        $cleanNotes = [];
        foreach ($notes as $note) {
            if (!empty($note['_deleted'])) {
                continue;
            }
            $cleanNotes[] = [
                'type' => sanitize_text_field($note['type'] ?? QuoteService::NOTE_TYPE_ADMIN),
                'content' => sanitize_textarea_field($note['content'] ?? ''),
                'author' => sanitize_text_field($note['author'] ?? ''),
                'date' => sanitize_text_field($note['date'] ?? current_time('mysql')),
            ];
        }

        return $cleanNotes;
    }

    /**
     * Handle lock/unlock action
     */
    private function handleLockAction(int $postId, string $action, array &$updateData): void
    {
        $action = sanitize_text_field($action);

        if ($action === 'lock') {
            $updateData[QuoteService::FIELD_LOCKED] = true;
            $this->auditLogger->logLockAction($postId, true);
        } elseif ($action === 'unlock') {
            $updateData[QuoteService::FIELD_LOCKED] = false;
            $this->auditLogger->logLockAction($postId, false);
        }
    }

    /**
     * Handle status change
     */
    private function handleStatusChange(int $postId, array $quote, string $newStatus, array &$updateData): void
    {
        $newStatus = sanitize_text_field($newStatus);
        $validStatuses = [QuoteService::STATUS_DRAFT, QuoteService::STATUS_SENT, QuoteService::STATUS_EXPORTED];

        if (!in_array($newStatus, $validStatuses, true) || $newStatus === $quote['status']) {
            return;
        }

        $updateData[QuoteService::FIELD_STATUS] = $newStatus;

        // Set timestamp for status transitions
        if ($newStatus === QuoteService::STATUS_SENT && empty($quote['sent_at'])) {
            $updateData[QuoteService::FIELD_SENT_AT] = current_time('mysql');
        } elseif ($newStatus === QuoteService::STATUS_EXPORTED) {
            if (empty($quote['exported_at'])) {
                $updateData[QuoteService::FIELD_EXPORTED_AT] = current_time('mysql');
            }
            // Auto-lock on export
            $updateData[QuoteService::FIELD_LOCKED] = true;
        }

        $this->auditLogger->logStatusChange($postId, $quote['status'], $newStatus);
    }

    /**
     * Handle voucher/discount actions
     */
    private function handleVoucherActions(int $postId, array $quote): void
    {
        // Apply voucher
        if (!empty($_POST['stride_apply_voucher'])) {
            $voucherCode = sanitize_text_field($_POST['stride_apply_voucher']);
            $this->applyVoucherToQuote($postId, $voucherCode);
        }

        // Apply manual discount
        if (!empty($_POST['stride_apply_discount'])) {
            $discountAmount = (float) $_POST['stride_apply_discount'];
            if ($discountAmount > 0) {
                $this->applyManualDiscount($postId, $discountAmount);
            }
        }

        // Remove voucher
        if (!empty($_POST['stride_remove_voucher'])) {
            $this->removeDiscountFromQuote($postId);
        }
    }

    /**
     * Apply voucher to quote
     */
    private function applyVoucherToQuote(int $postId, string $voucherCode): void
    {
        $quote = $this->quoteService->getQuote($postId);
        if (!$quote) {
            return;
        }

        $subtotal = $quote['subtotal'] ?? 0;
        $itemType = $quote['item_type'] ?? 'course';
        $itemId = $quote['item_id'] ?? ($quote['course_id'] ?? 0);
        $validation = QuoteCalculator::validateAndCalculateVoucher($voucherCode, $itemType, $itemId, $subtotal);

        if (!$validation['valid']) {
            set_transient('stride_quote_voucher_error_' . $postId, $validation['error'] ?? __('Ongeldige voucher', 'stride'), 30);
            return;
        }

        $result = QuoteCalculator::applyDiscount(
            $quote['items'] ?? [],
            $subtotal,
            $validation['discount'],
            sprintf(__('Korting: %s', 'stride'), $voucherCode)
        );

        $model = $this->getModel();
        if ($model) {
            $model->update($postId, [
                QuoteService::FIELD_ITEMS => $result['items'],
                QuoteService::FIELD_VOUCHER_CODE => $voucherCode,
                QuoteService::FIELD_DISCOUNT => $result['discount'],
                QuoteService::FIELD_TAX => $result['tax'],
                QuoteService::FIELD_TOTAL => $result['total'],
            ]);
        }

        $this->auditLogger->logVoucherApplied($postId, $voucherCode, $result['discount']);
    }

    /**
     * Apply manual discount to quote
     */
    private function applyManualDiscount(int $postId, float $amount): void
    {
        $quote = $this->quoteService->getQuote($postId);
        if (!$quote) {
            return;
        }

        $subtotal = $quote['subtotal'] ?? 0;
        $result = QuoteCalculator::applyDiscount(
            $quote['items'] ?? [],
            $subtotal,
            $amount,
            __('Handmatige korting', 'stride')
        );

        $model = $this->getModel();
        if ($model) {
            $model->update($postId, [
                QuoteService::FIELD_ITEMS => $result['items'],
                QuoteService::FIELD_DISCOUNT => $result['discount'],
                QuoteService::FIELD_TAX => $result['tax'],
                QuoteService::FIELD_TOTAL => $result['total'],
            ]);
        }

        $this->auditLogger->logManualDiscount($postId, $result['discount']);
    }

    /**
     * Remove discount from quote
     */
    private function removeDiscountFromQuote(int $postId): void
    {
        $quote = $this->quoteService->getQuote($postId);
        if (!$quote) {
            return;
        }

        $result = QuoteCalculator::removeDiscounts(
            $quote['items'] ?? [],
            $quote['subtotal'] ?? 0
        );

        $model = $this->getModel();
        if ($model) {
            $model->update($postId, [
                QuoteService::FIELD_ITEMS => $result['items'],
                QuoteService::FIELD_VOUCHER_CODE => '',
                QuoteService::FIELD_DISCOUNT => 0,
                QuoteService::FIELD_TAX => $result['tax'],
                QuoteService::FIELD_TOTAL => $result['total'],
            ]);
        }

        $this->auditLogger->logDiscountRemoved($postId);
    }

    /**
     * Handle new quote creation - populate an existing empty post with quote data
     */
    private function handleNewQuoteCreation(int $postId, \WP_Post $post): void
    {
        $fields = $_POST['ntdst_fields'] ?? [];
        $userId = absint($fields['user_id'] ?? 0);
        $courseId = absint($fields['course_id'] ?? 0);
        $itemType = sanitize_text_field($fields['item_type'] ?? 'course');
        $itemId = absint($fields['item_id'] ?? $courseId);

        if (!$userId || !$itemId) {
            return;
        }

        $model = $this->getModel();
        if (!$model) {
            return;
        }

        // Resolve item details via filter
        $resolved = apply_filters('stride/quote/resolve_item', null, $itemType, $itemId);

        if (!$resolved || empty($resolved['valid'])) {
            // Fallback: create basic item
            $resolved = [
                'title' => sprintf('%s #%d', ucfirst($itemType), $itemId),
                'unit_price' => 0.0,
                'valid' => true,
            ];
        }

        // Get billing data via SubscriberService
        $subscriberService = $this->resolveService(\stride\services\core\SubscriberService::class);
        $billing = [];
        if ($subscriberService) {
            $billing = $subscriberService->getBillingData($userId);
            if (is_wp_error($billing)) {
                $billing = [];
            }
        }

        // Create item using factory
        $item = QuoteItemFactory::create(
            $itemType,
            $itemId,
            $resolved['title'] ?? '',
            (float) ($resolved['unit_price'] ?? 0),
            1,
            $resolved['meta'] ?? []
        );

        $items = [$item];

        // Calculate totals
        $taxRate = QuoteCalculator::getTaxRate();
        $subtotal = $item['total'];
        $tax = round($subtotal * ($taxRate / 100), 2);
        $total = $subtotal + $tax;

        // Generate quote number
        $quoteNumber = $this->generateQuoteNumber();

        // Calculate valid until
        $validDays = \stride\services\invoicing\Support\QuoteConfig::getValidDays();
        $validUntil = date('Y-m-d', strtotime("+{$validDays} days"));

        // Update the post with generated data
        $model->update($postId, [
            QuoteService::FIELD_USER_ID => $userId,
            QuoteService::FIELD_ITEM_TYPE => $itemType,
            QuoteService::FIELD_ITEM_ID => $itemId,
            QuoteService::FIELD_COURSE_ID => ($itemType === 'course') ? $itemId : 0, // BC
            QuoteService::FIELD_STATUS => QuoteService::STATUS_DRAFT,
            QuoteService::FIELD_QUOTE_NUMBER => $quoteNumber,
            QuoteService::FIELD_ITEMS => $items,
            QuoteService::FIELD_SUBTOTAL => $subtotal,
            QuoteService::FIELD_DISCOUNT => 0,
            QuoteService::FIELD_TAX => $tax,
            QuoteService::FIELD_TOTAL => $total,
            QuoteService::FIELD_VALID_UNTIL => $validUntil,
            QuoteService::FIELD_BILLING => $billing,
            QuoteService::FIELD_CREATED_AT => current_time('mysql'),
        ]);

        // Update post title to quote number
        wp_update_post([
            'ID' => $postId,
            'post_title' => $quoteNumber,
        ]);

        // Fire created hook
        do_action('stride/quote/created', $postId, $userId, $itemType, $itemId);
    }

    /**
     * Generate unique quote number with atomic increment
     */
    private function generateQuoteNumber(): string
    {
        global $wpdb;

        $prefix = \stride\services\invoicing\Support\QuoteConfig::getQuotePrefix();
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
            return sprintf('%s-%s-%s', $prefix, $year, strtoupper(substr(md5(microtime(true)), 0, 5)));
        }
    }

    /**
     * AJAX: Get user data
     */
    public function ajaxGetUserData(): void
    {
        if (!check_ajax_referer('stride_quote_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
        }

        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $userId = absint($_POST['user_id'] ?? 0);
        if (!$userId) {
            wp_send_json_error(['message' => 'Invalid user ID'], 400);
        }

        $user = get_userdata($userId);
        if (!$user) {
            wp_send_json_error(['message' => 'User not found'], 404);
        }

        // Use UserDataSync if available
        $data = $this->getUserBillingData($userId, $user);

        wp_send_json_success($data);
    }

    /**
     * Get user billing data
     */
    private function getUserBillingData(int $userId, \WP_User $user): array
    {
        // Try UserDataSync if available
        if (class_exists('\stride\services\sync\UserDataSync') && class_exists('\stride\services\FieldRegistry')) {
            $userDataSync = $this->resolveService(\stride\services\sync\UserDataSync::class);
            if ($userDataSync) {
                $fields = $userDataSync->getFields($userId, [
                    \stride\services\FieldRegistry::FIELD_EMAIL,
                    \stride\services\FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME,
                    \stride\services\FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS,
                    \stride\services\FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE,
                    \stride\services\FieldRegistry::SUBSCRIBER_INVOICE_CITY,
                    \stride\services\FieldRegistry::SUBSCRIBER_VAT_NUMBER,
                    \stride\services\FieldRegistry::SUBSCRIBER_GLN_NUMBER,
                ]);

                return [
                    'email' => $fields[\stride\services\FieldRegistry::FIELD_EMAIL] ?? $user->user_email,
                    'organisation' => $fields[\stride\services\FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME] ?? '',
                    'address' => $fields[\stride\services\FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS] ?? '',
                    'postal_code' => $fields[\stride\services\FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE] ?? '',
                    'city' => $fields[\stride\services\FieldRegistry::SUBSCRIBER_INVOICE_CITY] ?? '',
                    'vat_number' => $fields[\stride\services\FieldRegistry::SUBSCRIBER_VAT_NUMBER] ?? '',
                    'gln_number' => $fields[\stride\services\FieldRegistry::SUBSCRIBER_GLN_NUMBER] ?? '',
                ];
            }
        }

        // Fallback to basic user data
        return [
            'email' => $user->user_email,
            'organisation' => '',
            'address' => '',
            'postal_code' => '',
            'city' => '',
            'vat_number' => '',
            'gln_number' => '',
        ];
    }

    /**
     * Show VAT validation notice
     */
    public function showVatValidationNotice(): void
    {
        global $post_type, $post;

        if ($post_type !== QuoteService::POST_TYPE || !$post) {
            return;
        }

        $transientKey = 'stride_vat_notice_' . get_current_user_id();
        $notice = get_transient($transientKey);

        if (!$notice || ($notice['post_id'] ?? 0) !== $post->ID) {
            return;
        }

        delete_transient($transientKey);

        $vatNumber = esc_html($notice['vat_number'] ?? '');
        $source = $notice['source'] ?? 'vies';

        if ($source === 'format_only') {
            $message = sprintf(
                __('BTW nummer %s kon niet worden geverifieerd via VIES (service niet beschikbaar). Het nummer is opgeslagen maar wordt later opnieuw gevalideerd.', 'stride'),
                '<code>' . $vatNumber . '</code>'
            );
            $class = 'notice-warning';
        } else {
            $message = sprintf(
                __('BTW nummer %s is ongeldig of komt niet overeen met het opgegeven adres. Controleer het nummer en probeer opnieuw.', 'stride'),
                '<code>' . $vatNumber . '</code>'
            );
            $class = 'notice-error';
        }

        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            esc_attr($class),
            $message
        );
    }

    /**
     * Get DataManager model
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }

        return ntdst_data()->get(QuoteService::POST_TYPE);
    }

    /**
     * Resolve service from DI container
     */
    private function resolveService(string $class): ?object
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

        if (class_exists($class)) {
            return new $class();
        }

        return null;
    }
}
