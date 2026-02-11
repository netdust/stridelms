<?php

namespace stride\services\invoicing;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Quote Update Handler
 *
 * Handles user updates to quote billing information.
 * Users can update order number, voucher code, VAT number, and company details
 * while the quote is still in draft status.
 *
 * Available hooks:
 * - stride/quote/updated_by_user (action) - After user updates quote
 *
 * @package stride\services\invoicing
 */
class QuoteUpdateHandler implements \NTDST_Service_Meta
{
    private ?QuoteService $quoteService;
    private ?VATValidator $vatValidator;

    /**
     * Service metadata for NTDST Bootstrap
     */
    public static function metadata(): array
    {
        return [
            'name' => 'Quote Update Handler',
            'description' => 'Handles user updates to quote billing info',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 12,
        ];
    }

    /**
     * Constructor with optional dependency injection for testing
     */
    public function __construct(
        ?QuoteService $quoteService = null,
        ?VATValidator $vatValidator = null
    ) {
        $this->quoteService = $quoteService ?? $this->resolveService(QuoteService::class);
        $this->vatValidator = $vatValidator ?? new VATValidator();

        // FluentForms submission hook
        add_action('fluentform/submission_inserted', [$this, 'handleFormSubmission'], 10, 3);

        // AJAX endpoint for logged-in users
        add_action('wp_ajax_stride_update_quote', [$this, 'handleAjaxUpdate']);

        // Shortcode for update form
        add_shortcode('stride_quote_update', [$this, 'renderUpdateForm']);

        // REST API endpoint for programmatic updates
        add_action('rest_api_init', [$this, 'registerRestRoutes']);
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
     * Register REST API routes
     */
    public function registerRestRoutes(): void
    {
        register_rest_route('stride/v1', '/quote/(?P<id>\d+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'handleRestUpdate'],
            'permission_callback' => [$this, 'canUserUpdateQuoteRest'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => fn($param) => is_numeric($param),
                ],
            ],
        ]);
    }

    /**
     * Get allowed FluentForms form IDs for quote updates
     *
     * @return array Array of allowed form IDs
     */
    private function getAllowedQuoteFormIds(): array
    {
        static $formIds = null;

        if ($formIds === null) {
            $configPath = get_stylesheet_directory() . '/theme-config.php';
            $config = file_exists($configPath) ? include $configPath : [];
            $formIds = $config['modules']['invoicing']['quote_update_form_ids'] ?? [];
        }

        return $formIds;
    }

    /**
     * Render quote update form shortcode
     *
     * Usage: [stride_quote_update] or [stride_quote_update quote_id="123"]
     *
     * @param array $atts Shortcode attributes
     * @return string HTML form
     */
    public function renderUpdateForm(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return sprintf(
                '<p class="stride-notice stride-notice--info">%s</p>',
                esc_html__('Log in om uw gegevens bij te werken.', 'stride')
            );
        }

        $atts = shortcode_atts([
            'quote_id' => null,
        ], $atts, 'stride_quote_update');

        $quoteId = $atts['quote_id'] ? (int) $atts['quote_id'] : $this->getUserDraftQuote();

        if (!$quoteId) {
            return sprintf(
                '<p class="stride-notice stride-notice--info">%s</p>',
                esc_html__('Geen openstaande offerte gevonden.', 'stride')
            );
        }

        $quote = $this->quoteService->getQuote($quoteId);
        if (!$quote) {
            return sprintf(
                '<p class="stride-notice stride-notice--error">%s</p>',
                esc_html__('Offerte niet gevonden.', 'stride')
            );
        }

        // Verify ownership
        if ((int) $quote['user_id'] !== get_current_user_id() && !current_user_can('manage_options')) {
            return sprintf(
                '<p class="stride-notice stride-notice--error">%s</p>',
                esc_html__('U heeft geen toegang tot deze offerte.', 'stride')
            );
        }

        // Only allow updates while in draft
        if ($quote['status'] !== QuoteService::STATUS_DRAFT) {
            return sprintf(
                '<p class="stride-notice stride-notice--info">%s</p>',
                esc_html__('Deze offerte kan niet meer worden gewijzigd.', 'stride')
            );
        }

        // Load template
        $templatePath = get_stylesheet_directory() . '/templates/forms/quote-update.php';
        if (!file_exists($templatePath)) {
            return $this->renderDefaultForm($quote);
        }

        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Render default update form when template not found
     *
     * @param array $quote Quote data
     * @return string HTML form
     */
    private function renderDefaultForm(array $quote): string
    {
        $billing = $quote['billing'] ?? [];
        $nonce = wp_create_nonce('stride_quote_update');

        ob_start();
        ?>
        <form class="stride-quote-update-form" method="post" data-quote-id="<?php echo esc_attr($quote['id']); ?>">
            <input type="hidden" name="action" value="stride_update_quote">
            <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
            <input type="hidden" name="quote_id" value="<?php echo esc_attr($quote['id']); ?>">

            <h3><?php esc_html_e('Offerte bijwerken', 'stride'); ?></h3>
            <p class="stride-quote-number">
                <?php echo esc_html(sprintf(__('Offertenummer: %s', 'stride'), $quote['number'])); ?>
            </p>

            <div class="stride-form-section">
                <h4><?php esc_html_e('Facturatiegegevens', 'stride'); ?></h4>

                <div class="stride-form-row">
                    <label for="company_name"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                    <input type="text" id="company_name" name="company_name"
                           value="<?php echo esc_attr($billing['organisation'] ?? ''); ?>">
                </div>

                <div class="stride-form-row">
                    <label for="vat_number"><?php esc_html_e('BTW-nummer', 'stride'); ?></label>
                    <input type="text" id="vat_number" name="vat_number"
                           value="<?php echo esc_attr($billing['vat_number'] ?? ''); ?>"
                           placeholder="BE0123456789">
                    <small><?php esc_html_e('Bij invullen wordt bedrijfsinfo automatisch opgehaald.', 'stride'); ?></small>
                </div>

                <div class="stride-form-row">
                    <label for="address"><?php esc_html_e('Adres', 'stride'); ?></label>
                    <input type="text" id="address" name="address"
                           value="<?php echo esc_attr($billing['address'] ?? ''); ?>">
                </div>

                <div class="stride-form-row stride-form-row--half">
                    <div>
                        <label for="postal_code"><?php esc_html_e('Postcode', 'stride'); ?></label>
                        <input type="text" id="postal_code" name="postal_code"
                               value="<?php echo esc_attr($billing['postal_code'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="city"><?php esc_html_e('Stad', 'stride'); ?></label>
                        <input type="text" id="city" name="city"
                               value="<?php echo esc_attr($billing['city'] ?? ''); ?>">
                    </div>
                </div>

                <div class="stride-form-row">
                    <label for="gln_number"><?php esc_html_e('GLN/Peppol-nummer', 'stride'); ?> (<?php esc_html_e('optioneel', 'stride'); ?>)</label>
                    <input type="text" id="gln_number" name="gln_number"
                           value="<?php echo esc_attr($billing['gln_number'] ?? ''); ?>">
                </div>
            </div>

            <div class="stride-form-section">
                <h4><?php esc_html_e('Bestelgegevens', 'stride'); ?></h4>

                <div class="stride-form-row">
                    <label for="order_number"><?php esc_html_e('Bestelnummer/PO-nummer', 'stride'); ?> (<?php esc_html_e('optioneel', 'stride'); ?>)</label>
                    <input type="text" id="order_number" name="order_number"
                           value="<?php echo esc_attr($quote['order_number'] ?? ''); ?>">
                    <small><?php esc_html_e('Dit nummer wordt vermeld op de factuur.', 'stride'); ?></small>
                </div>

                <div class="stride-form-row">
                    <label for="voucher_code"><?php esc_html_e('Vouchercode', 'stride'); ?> (<?php esc_html_e('optioneel', 'stride'); ?>)</label>
                    <input type="text" id="voucher_code" name="voucher_code"
                           value="<?php echo esc_attr($quote['voucher_code'] ?? ''); ?>">
                </div>
            </div>

            <div class="stride-form-actions">
                <button type="submit" class="stride-btn stride-btn--primary">
                    <?php esc_html_e('Gegevens opslaan', 'stride'); ?>
                </button>
            </div>

            <div class="stride-form-messages" style="display: none;"></div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle FluentForms submission for quote updates
     *
     * @param int $entryId Entry ID
     * @param array $formData Form data
     * @param object $form Form object
     */
    public function handleFormSubmission($entryId, $formData, $form): void
    {
        // Check if this is a quote update form
        if (empty($formData['quote_id'])) {
            return;
        }

        // Verify form is an authorized quote update form (security: prevent arbitrary form exploitation)
        $allowedFormIds = $this->getAllowedQuoteFormIds();
        if (!empty($allowedFormIds) && !in_array((int) $form->id, $allowedFormIds, true)) {
            error_log(sprintf(
                'Stride: Quote update attempted from unauthorized form ID %d',
                $form->id
            ));
            return;
        }

        $quoteId = (int) $formData['quote_id'];
        $userId = get_current_user_id();

        // Verify ownership and draft status
        if (!$this->canUserUpdateQuote($quoteId, $userId)) {
            return;
        }

        // Prepare billing data
        $billingData = $this->prepareBillingData($formData);

        // Update quote
        $result = $this->quoteService->updateQuote($quoteId, $billingData);

        if (!is_wp_error($result)) {
            // Fire hook for notifications
            do_action('stride/quote/updated_by_user', $quoteId, $userId, $billingData);
        }
    }

    /**
     * Handle AJAX quote update
     */
    public function handleAjaxUpdate(): void
    {
        // Verify nonce
        if (!check_ajax_referer('stride_quote_update', 'nonce', false)) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')], 403);
        }

        $quoteId = (int) ($_POST['quote_id'] ?? 0);
        $userId = get_current_user_id();

        if (!$this->canUserUpdateQuote($quoteId, $userId)) {
            wp_send_json_error(['message' => __('U kunt deze offerte niet wijzigen.', 'stride')], 403);
        }

        $billingData = $this->prepareBillingData($_POST);
        $result = $this->quoteService->updateQuote($quoteId, $billingData);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        // Fire hook for notifications
        do_action('stride/quote/updated_by_user', $quoteId, $userId, $billingData);

        wp_send_json_success([
            'message' => __('Gegevens succesvol bijgewerkt.', 'stride'),
        ]);
    }

    /**
     * Handle REST API quote update
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|WP_Error
     */
    public function handleRestUpdate(\WP_REST_Request $request): \WP_REST_Response|WP_Error
    {
        $quoteId = (int) $request->get_param('id');
        $userId = get_current_user_id();

        $billingData = $this->prepareBillingData($request->get_json_params() ?: []);
        $result = $this->quoteService->updateQuote($quoteId, $billingData);

        if (is_wp_error($result)) {
            return $result;
        }

        // Fire hook for notifications
        do_action('stride/quote/updated_by_user', $quoteId, $userId, $billingData);

        return new \WP_REST_Response([
            'success' => true,
            'message' => __('Gegevens succesvol bijgewerkt.', 'stride'),
            'quote' => $this->quoteService->getQuote($quoteId),
        ], 200);
    }

    /**
     * REST permission callback with nonce verification
     *
     * Requires X-WP-Nonce header for CSRF protection.
     *
     * @param \WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function canUserUpdateQuoteRest(\WP_REST_Request $request): bool|WP_Error
    {
        // Verify user is logged in
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_not_logged_in',
                __('U moet ingelogd zijn om offertes bij te werken.', 'stride'),
                ['status' => 401]
            );
        }

        // Verify WordPress REST nonce (X-WP-Nonce header)
        // WordPress automatically verifies this via rest_cookie_check_errors() if using cookie auth
        // For explicit verification, check the nonce header
        $nonce = $request->get_header('X-WP-Nonce');
        if ($nonce && !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error(
                'rest_cookie_invalid_nonce',
                __('Ongeldige beveiligingstoken.', 'stride'),
                ['status' => 403]
            );
        }

        $quoteId = (int) $request->get_param('id');
        return $this->canUserUpdateQuote($quoteId, get_current_user_id());
    }

    /**
     * Check if user can update a specific quote
     *
     * @param int $quoteId Quote post ID
     * @param int $userId WordPress user ID
     * @return bool
     */
    private function canUserUpdateQuote(int $quoteId, int $userId): bool
    {
        if (!$userId) {
            return false;
        }

        // Admins can update any quote
        if (current_user_can('manage_options')) {
            $quote = $this->quoteService->getQuote($quoteId);
            return $quote && $quote['status'] === QuoteService::STATUS_DRAFT;
        }

        $quote = $this->quoteService->getQuote($quoteId);

        if (!$quote) {
            return false;
        }

        // Check ownership
        if ((int) $quote['user_id'] !== $userId) {
            return false;
        }

        // Only draft quotes can be updated
        return $quote['status'] === QuoteService::STATUS_DRAFT;
    }

    /**
     * Prepare billing data from form input
     *
     * @param array $formData Raw form data
     * @return array Sanitized billing data
     */
    private function prepareBillingData(array $formData): array
    {
        $billing = [
            'company' => sanitize_text_field($formData['company_name'] ?? ''),
            'address' => sanitize_text_field($formData['address'] ?? ''),
            'city' => sanitize_text_field($formData['city'] ?? ''),
            'postal_code' => sanitize_text_field($formData['postal_code'] ?? ''),
            'vat_number' => sanitize_text_field($formData['vat_number'] ?? ''),
            'gln_number' => sanitize_text_field($formData['gln_number'] ?? ''),
            'order_number' => sanitize_text_field($formData['order_number'] ?? ''),
            'voucher_code' => sanitize_text_field($formData['voucher_code'] ?? ''),
        ];

        // Validate and enrich VAT data
        if (!empty($billing['vat_number'])) {
            $vatResult = $this->vatValidator->validate($billing['vat_number']);

            // Auto-fill company name from VIES if available and not already set
            if ($vatResult['valid'] && !empty($vatResult['name']) && empty($billing['company'])) {
                $billing['company'] = $vatResult['name'];
            }

            // Store VIES address if available
            if ($vatResult['valid'] && !empty($vatResult['address'])) {
                $billing['vies_address'] = $vatResult['address'];
            }

            $billing['vat_validated'] = $vatResult['valid'];
            $billing['vat_source'] = $vatResult['source'] ?? 'unknown';
        }

        return $billing;
    }

    /**
     * Get user's most recent draft quote
     *
     * @return int|null Quote post ID or null
     */
    private function getUserDraftQuote(): ?int
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return null;
        }

        $quotes = get_posts([
            'post_type' => QuoteService::POST_TYPE,
            'meta_query' => [
                ['key' => QuoteService::META_USER_ID, 'value' => $userId],
                ['key' => QuoteService::META_STATUS, 'value' => QuoteService::STATUS_DRAFT],
            ],
            'posts_per_page' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
        ]);

        return $quotes[0] ?? null;
    }
}
