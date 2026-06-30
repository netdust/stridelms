<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Invoicing\Helpers\QuoteCalculator;
use Stride\Modules\Invoicing\QuoteCPT;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Invoicing\VoucherService;
use WP_Post;

/**
 * Quote Admin Controller.
 *
 * Orchestrates admin interface for quotes:
 * - Registers metaboxes
 * - Enqueues admin assets
 * - Handles save operations
 * - AJAX endpoints for user data
 *
 * Plain class — owned by QuoteService.
 */
final class QuoteAdminController
{
    public function __construct(
        private readonly QuoteService $quoteService,
        private readonly QuoteRepository $repository,
        private readonly VoucherService $voucherService,
        private readonly EditionRepository $editionRepository,
    ) {
        $this->init();
    }

    protected function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . QuoteCPT::POST_TYPE, [$this, 'handleSave'], 10, 2);
        add_action('admin_notices', [$this, 'showAdminNotices']);
        add_filter('post_updated_messages', [$this, 'customizeUpdateMessages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_stride_get_user_data', [$this, 'ajaxGetUserData']);

        // Admin list columns
        add_filter('manage_' . QuoteCPT::POST_TYPE . '_posts_columns', [$this, 'defineListColumns']);
        add_action('manage_' . QuoteCPT::POST_TYPE . '_posts_custom_column', [$this, 'renderListColumn'], 10, 2);
        add_filter('manage_edit-' . QuoteCPT::POST_TYPE . '_sortable_columns', [$this, 'defineSortableColumns']);
        add_action('pre_get_posts', [$this, 'handleColumnSorting']);
    }

    public function registerMetaboxes(): void
    {
        // Remove default editor
        remove_post_type_support(QuoteCPT::POST_TYPE, 'editor');

        // Main quote overview
        add_meta_box(
            'stride_quote_overview',
            __('Offerte', 'stride'),
            [$this, 'renderOverviewMetabox'],
            QuoteCPT::POST_TYPE,
            'normal',
            'high',
        );

        // Notes metabox
        add_meta_box(
            'stride_quote_notes',
            __('Notities', 'stride'),
            [$this, 'renderNotesMetabox'],
            QuoteCPT::POST_TYPE,
            'normal',
            'default',
        );

        // Status & actions sidebar
        add_meta_box(
            'stride_quote_status',
            __('Status & Acties', 'stride'),
            [$this, 'renderActionsMetabox'],
            QuoteCPT::POST_TYPE,
            'side',
            'high',
        );
    }

    public function enqueueAssets(string $hook): void
    {
        global $post_type;

        if ($post_type !== QuoteCPT::POST_TYPE) {
            return;
        }

        // Select2 from CDN
        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0',
        );
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0',
            true,
        );

        // Quote admin styles (from stride-core mu-plugin)
        $basePath = dirname(__DIR__, 3);
        $cssFile = $basePath . '/assets/css/admin/quote-admin.css';
        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-quote-admin',
                plugins_url('assets/css/admin/quote-admin.css', $basePath . '/stride-core.php'),
                [],
                filemtime($cssFile),
            );
        }

        // Quote admin scripts (from stride-core mu-plugin)
        $jsFile = $basePath . '/assets/js/admin/quote-admin.js';
        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-quote-admin',
                plugins_url('assets/js/admin/quote-admin.js', $basePath . '/stride-core.php'),
                ['jquery', 'select2'],
                filemtime($jsFile),
                true,
            );

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
                    'enterEmail' => __('Vul een e-mailadres in.', 'stride'),
                    'enterVoucher' => __('Vul een vouchercode in.', 'stride'),
                    'enterDiscount' => __('Vul een kortingsbedrag in.', 'stride'),
                    'confirmRemoveDiscount' => __('Korting verwijderen?', 'stride'),
                    'enterNote' => __('Vul een notitie in.', 'stride'),
                    'noNotes' => __('Nog geen notities toegevoegd.', 'stride'),
                    'customer' => __('Klant', 'stride'),
                    'internal' => __('Intern', 'stride'),
                    'confirmDelete' => __('Notitie verwijderen?', 'stride'),
                ],
            ]);
        }
    }

    public function renderOverviewMetabox(WP_Post $post): void
    {
        $metabox = new QuoteOverviewMetabox($this->quoteService);
        $metabox->render($post);
    }

    public function renderActionsMetabox(WP_Post $post): void
    {
        $metabox = new QuoteActionsMetabox($this->quoteService);
        $metabox->render($post);
    }

    public function renderNotesMetabox(WP_Post $post): void
    {
        $quote = $this->quoteService->getQuote($post->ID);

        // For new quotes, show placeholder
        if (is_wp_error($quote) || empty($quote['quote_number'])) {
            echo '<p class="description">' . esc_html__('Sla de offerte eerst op om notities toe te voegen.', 'stride') . '</p>';
            return;
        }

        $notes = $quote['notes'] ?? [];
        if (is_string($notes)) {
            $notes = json_decode($notes, true) ?: [];
        }
        ?>
        <!-- Notes Timeline -->
        <div id="stride-notes-list" class="stride-notes-timeline">
            <?php if (empty($notes)): ?>
                <div class="stride-empty-notes">
                    <?php esc_html_e('Nog geen notities toegevoegd.', 'stride'); ?>
                </div>
            <?php else: ?>
                <?php foreach ($notes as $index => $note): ?>
                    <?php if (!empty($note['_deleted'])) {
                        continue;
                    } ?>
                    <?php
                    $isCustomer = ($note['type'] ?? 'admin') === 'customer';
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
                                <span class="author"><?php echo esc_html($note['author'] ?? __('Onbekend', 'stride')); ?></span>
                                <span class="type-badge <?php echo esc_attr($typeClass); ?>"><?php echo esc_html($typeLabel); ?></span>
                                <span class="date"><?php echo esc_html($note['date'] ?? ''); ?></span>
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
            <textarea id="stride-note-content" placeholder="<?php esc_attr_e('Schrijf een notitie...', 'stride'); ?>"></textarea>
            <div class="form-row">
                <div class="type-selector">
                    <label>
                        <input type="radio" name="stride_note_type" value="admin" checked>
                        <span class="type-icon admin"><span class="dashicons dashicons-shield"></span></span>
                        <?php esc_html_e('Intern', 'stride'); ?>
                    </label>
                    <label>
                        <input type="radio" name="stride_note_type" value="customer">
                        <span class="type-icon customer"><span class="dashicons dashicons-format-quote"></span></span>
                        <?php esc_html_e('Klant', 'stride'); ?>
                    </label>
                </div>
                <button type="button" class="button" id="stride-add-note">
                    <?php esc_html_e('Notitie toevoegen', 'stride'); ?>
                </button>
            </div>
        </div>

        <input type="hidden" id="stride_notes_data" name="ntdst_fields[notes]" value="<?php echo esc_attr(json_encode($notes)); ?>">
        <?php
    }

    public function handleSave(int $postId, WP_Post $post): void
    {
        // Verify nonce
        if (!isset($_POST['stride_quote_nonce'])
            || !wp_verify_nonce($_POST['stride_quote_nonce'], 'stride_save_quote')) {
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
        $isNew = is_wp_error($quote) || empty($quote['quote_number']);

        if ($isNew) {
            $this->handleNewQuoteCreation($postId);
            return;
        }

        $isLocked = (bool) ($quote['locked'] ?? false);
        $isEditable = !$isLocked;
        $updateData = [];

        // Process billing data
        if ($isEditable && isset($_POST['billing']) && is_array($_POST['billing'])) {
            $updateData['billing'] = $this->processBillingData($_POST['billing']);
        }

        // Process items data
        if ($isEditable && isset($_POST['items']) && is_array($_POST['items'])) {
            $itemsResult = $this->processItemsData($_POST['items']);
            $updateData['items'] = $itemsResult['items'];
            $updateData['subtotal'] = $itemsResult['subtotal'];
            $updateData['tax'] = $itemsResult['tax'];
            $updateData['total'] = $itemsResult['total'];
        }

        // Handle lock/unlock action
        if (!empty($_POST['stride_lock_action'])) {
            $action = sanitize_text_field($_POST['stride_lock_action']);
            if ($action === 'lock') {
                $updateData['locked'] = true;
            } elseif ($action === 'unlock') {
                $updateData['locked'] = false;
            }
        }

        // Handle status change
        if (!empty($_POST['stride_change_status'])) {
            $newStatus = sanitize_text_field($_POST['stride_change_status']);
            $validStatuses = ['draft', 'sent', 'exported', 'cancelled'];
            if (in_array($newStatus, $validStatuses, true) && $newStatus !== ($quote['status'] ?? '')) {
                $updateData['status'] = $newStatus;

                // Set timestamps for status transitions
                if ($newStatus === 'sent' && empty($quote['sent_at'])) {
                    $updateData['sent_at'] = current_time('mysql');
                } elseif ($newStatus === 'exported') {
                    if (empty($quote['exported_at'])) {
                        $updateData['exported_at'] = current_time('mysql');
                    }
                    $updateData['locked'] = true; // Auto-lock on export
                } elseif ($newStatus === 'cancelled') {
                    $updateData['cancelled_at'] = current_time('mysql');
                }
            }
        }

        // Handle valid_until update
        if (!empty($_POST['ntdst_fields']['valid_until'])) {
            $updateData['valid_until'] = sanitize_text_field($_POST['ntdst_fields']['valid_until']);
        }

        // Handle notes update
        if (isset($_POST['ntdst_fields']['notes'])) {
            $notesRaw = wp_unslash($_POST['ntdst_fields']['notes']);
            $notes = is_string($notesRaw) ? json_decode($notesRaw, true) : $notesRaw;
            if (is_array($notes)) {
                $sanitized = [];
                foreach ($notes as $note) {
                    if (!is_array($note) || !empty($note['_deleted'])) {
                        continue;
                    }
                    $sanitized[] = [
                        'content' => sanitize_textarea_field($note['content'] ?? ''),
                        'type'    => in_array($note['type'] ?? '', ['admin', 'customer'], true) ? $note['type'] : 'admin',
                        'author'  => sanitize_text_field($note['author'] ?? ''),
                        'date'    => sanitize_text_field($note['date'] ?? ''),
                    ];
                }
                $updateData['notes'] = $sanitized;
            }
        }

        // Update if we have data
        if (!empty($updateData)) {
            $this->repository->updateMeta($postId, $updateData);
        }

        // Handle quote cancellation with optional registration cancellation
        if (($updateData['status'] ?? '') === 'cancelled' && !empty($_POST['stride_cancel_registration'])) {
            // Fire event to cancel registration (which revokes course access)
            do_action('stride/quote/cancelled', ['quote_id' => $postId]);
        }

        // Handle send quote action
        if (!empty($_POST['stride_send_quote'])) {
            $sendTo = sanitize_email($_POST['stride_send_to'] ?? '');
            $sendCc = sanitize_email($_POST['stride_send_cc'] ?? '');
            if ($sendTo) {
                do_action('stride/quote/send_email', $postId, $sendTo, $sendCc);
                $this->setAdminNotice('success', sprintf(
                    __('Offerte verzonden naar %s.', 'stride'),
                    $sendTo,
                ));
                $this->suppressDefaultNotice();
            }
        }

        // Handle PDF regeneration
        if (!empty($_POST['stride_regenerate_pdf'])) {
            do_action('stride/quote/regenerate_pdf', $postId);
            $pdfPath = ntdst_data()->get('vad_quote')->getMeta($postId, 'pdf_path');
            if ($pdfPath) {
                $this->setAdminNotice('success', __('PDF is opnieuw gegenereerd.', 'stride'));
            } else {
                $this->setAdminNotice('error', __('PDF genereren mislukt.', 'stride'));
            }
            $this->suppressDefaultNotice();
        }

        // Handle voucher/discount actions
        $this->handleVoucherActions($postId);
    }

    /**
     * Suppress WP's default "Post updated" notice by stripping the message query arg.
     */
    private function suppressDefaultNotice(): void
    {
        add_filter('redirect_post_location', function (string $location): string {
            return remove_query_arg('message', $location);
        });
    }

    /**
     * Set an admin notice to show after redirect.
     */
    private function setAdminNotice(string $type, string $message): void
    {
        set_transient(
            'stride_quote_notice_' . get_current_user_id(),
            ['type' => $type, 'message' => $message],
            30,
        );
    }

    /**
     * Replace default "Post updated" with quote-specific messages.
     */
    public function customizeUpdateMessages(array $messages): array
    {
        $messages[QuoteCPT::POST_TYPE] = [
            0  => '',
            1  => __('Offerte opgeslagen.', 'stride'),
            2  => __('Offerte opgeslagen.', 'stride'),
            3  => __('Offerte opgeslagen.', 'stride'),
            4  => __('Offerte opgeslagen.', 'stride'),
            5  => __('Offerte opgeslagen.', 'stride'),
            6  => __('Offerte opgeslagen.', 'stride'),
            7  => __('Offerte opgeslagen.', 'stride'),
            8  => __('Offerte opgeslagen.', 'stride'),
            9  => __('Offerte opgeslagen.', 'stride'),
            10 => __('Offerte opgeslagen.', 'stride'),
        ];

        return $messages;
    }

    /**
     * Show admin notices set during save.
     */
    public function showAdminNotices(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== QuoteCPT::POST_TYPE) {
            return;
        }

        $notice = get_transient('stride_quote_notice_' . get_current_user_id());
        if (!$notice || !is_array($notice)) {
            return;
        }

        delete_transient('stride_quote_notice_' . get_current_user_id());

        $type = $notice['type'] === 'error' ? 'error' : 'success';
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($notice['message']),
        );
    }

    private function processBillingData(array $input): array
    {
        $billing = [];
        $fields = ['company', 'email', 'address', 'postal_code', 'city', 'vat_number', 'gln_number'];

        foreach ($fields as $field) {
            if (isset($input[$field])) {
                $billing[$field] = sanitize_text_field($input[$field]);
            }
        }

        return $billing;
    }

    private function processItemsData(array $items): array
    {
        $processedItems = [];
        $subtotal = 0;

        foreach ($items as $item) {
            if (empty($item['title'])) {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $unitPrice = (int) round(((float) ($item['unit_price'] ?? 0)) * 100); // Convert to cents
            $total = $quantity * $unitPrice;

            $processedItems[] = [
                'id' => (int) ($item['id'] ?? 0),
                'type' => sanitize_text_field($item['type'] ?? 'custom'),
                'title' => sanitize_text_field($item['title']),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total' => $total,
            ];

            $subtotal += $total;
        }

        $totals = QuoteCalculator::deriveTotalsFromCents($subtotal);

        return [
            'items' => $processedItems,
            'subtotal' => $totals['subtotal'],
            'tax' => $totals['tax'],
            'total' => $totals['total'],
        ];
    }

    private function handleVoucherActions(int $postId): void
    {
        // Apply voucher
        if (!empty($_POST['stride_apply_voucher'])) {
            $voucherCode = sanitize_text_field($_POST['stride_apply_voucher']);
            $this->quoteService->applyVoucher($postId, $voucherCode);
        }

        // Apply manual discount
        if (!empty($_POST['stride_apply_discount'])) {
            $amount = (float) $_POST['stride_apply_discount'];
            if ($amount > 0) {
                $this->applyManualDiscount($postId, $amount);
            }
        }

        // Remove discount
        if (!empty($_POST['stride_remove_voucher'])) {
            $this->removeDiscount($postId);
        }
    }

    private function applyManualDiscount(int $postId, float $amount): void
    {
        $quote = $this->quoteService->getQuote($postId, true);
        if (is_wp_error($quote)) {
            ntdst_log('invoicing')->warning('Manual discount skipped: quote lookup failed', [
                'quote_id' => $postId,
                'error'    => $quote->get_error_code() . ': ' . $quote->get_error_message(),
            ]);

            return;
        }

        $subtotal = (int) ($quote['subtotal'] ?? 0);
        // Discount is clamped to the subtotal by the derivation
        $totals = QuoteCalculator::deriveTotalsFromCents($subtotal, (int) round($amount * 100));

        $this->repository->updateMeta($postId, [
            'voucher_code' => '',
            'discount' => $totals['discount'],
            'tax' => $totals['tax'],
            'total' => $totals['total'],
        ]);
    }

    private function removeDiscount(int $postId): void
    {
        $quote = $this->quoteService->getQuote($postId, true);
        if (is_wp_error($quote)) {
            ntdst_log('invoicing')->warning('Discount removal skipped: quote lookup failed', [
                'quote_id' => $postId,
                'error'    => $quote->get_error_code() . ': ' . $quote->get_error_message(),
            ]);

            return;
        }

        $subtotal = (int) ($quote['subtotal'] ?? 0);
        $totals = QuoteCalculator::deriveTotalsFromCents($subtotal);

        $this->repository->updateMeta($postId, [
            'voucher_code' => '',
            'discount' => 0,
            'tax' => $totals['tax'],
            'total' => $totals['total'],
        ]);
    }

    private function handleNewQuoteCreation(int $postId): void
    {
        $fields = $_POST['ntdst_fields'] ?? [];
        $userId = absint($fields['user_id'] ?? 0);
        $editionId = absint($fields['edition_id'] ?? 0);

        if (!$userId || !$editionId) {
            return;
        }

        // Get edition details for pricing
        $edition = get_post($editionId);
        if (!$edition) {
            return;
        }

        $price = (int) $this->editionRepository->getField($editionId, 'price', 0);
        $priceNonMember = (int) $this->editionRepository->getField($editionId, 'price_non_member', 0);

        // Use member price, or non-member if no member price. The stored edition
        // price field is canonical CENTS already — do NOT ×100 (double-convert).
        $unitPriceCents = $price > 0 ? $price : ($priceNonMember > 0 ? $priceNonMember : 0);

        // Create item
        $items = [[
            'id' => $editionId,
            'type' => 'edition',
            'title' => $edition->post_title,
            'quantity' => 1,
            'unit_price' => $unitPriceCents,
            'total' => $unitPriceCents,
        ]];

        // Calculate totals
        $totals = QuoteCalculator::deriveTotalsFromCents($unitPriceCents);

        // Generate quote number
        $quoteNumber = $this->repository->generateQuoteNumber();

        // Get user billing data
        $user = get_userdata($userId);
        $billing = [
            'email' => $user ? $user->user_email : '',
            'organisation' => '',
            'address' => '',
            'postal_code' => '',
            'city' => '',
            'vat_number' => '',
            'gln_number' => '',
        ];

        // Calculate valid until (30 days)
        $validUntil = date('Y-m-d', strtotime('+30 days'));

        // Update post with quote data
        $this->repository->updateMeta($postId, [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'quote_number' => $quoteNumber,
            'status' => 'draft',
            'items' => $items,
            'subtotal' => $totals['subtotal'],
            'discount' => 0,
            'tax' => $totals['tax'],
            'total' => $totals['total'],
            'billing' => $billing,
            'voucher_code' => '',
            'valid_until' => $validUntil,
        ]);

        // Update post title
        wp_update_post([
            'ID' => $postId,
            'post_title' => $quoteNumber,
        ]);
    }

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

        // Return basic user data
        wp_send_json_success([
            'email' => $user->user_email,
            'organisation' => get_user_meta($userId, 'organisation', true) ?: '',
            'company' => get_user_meta($userId, 'billing_company', true) ?: '',
            'address' => get_user_meta($userId, 'billing_address_1', true) ?: '',
            'postal_code' => get_user_meta($userId, 'billing_postcode', true) ?: '',
            'city' => get_user_meta($userId, 'billing_city', true) ?: '',
            'vat_number' => get_user_meta($userId, 'billing_vat', true) ?: '',
            'gln_number' => get_user_meta($userId, 'gln_number', true) ?: '',
        ]);
    }

    // =========================================================================
    // Admin List Columns
    // =========================================================================

    /**
     * Define admin list columns.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function defineListColumns(array $columns): array
    {
        $newColumns = [];
        $newColumns['cb'] = $columns['cb'] ?? '<input type="checkbox" />';
        $newColumns['quote_number'] = __('Offerte Nr.', 'stride');
        $newColumns['customer'] = __('Klant', 'stride');
        $newColumns['total'] = __('Totaal', 'stride');
        $newColumns['status'] = __('Status', 'stride');
        $newColumns['valid_until'] = __('Geldig tot', 'stride');
        $newColumns['date'] = __('Datum', 'stride');

        return $newColumns;
    }

    /**
     * Render admin list column content.
     */
    public function renderListColumn(string $column, int $postId): void
    {
        switch ($column) {
            case 'quote_number':
                $quoteNumber = $this->repository->getField($postId, 'quote_number', '');
                $isLocked = (bool) $this->repository->getField($postId, 'locked', false);
                if ($quoteNumber) {
                    echo '<strong>' . esc_html($quoteNumber) . '</strong>';
                    if ($isLocked) {
                        echo ' <span class="dashicons dashicons-lock" style="color:#d63638;font-size:14px;" title="' . esc_attr__('Vergrendeld', 'stride') . '"></span>';
                    }
                } else {
                    echo '<span style="color:#999;">' . __('Nieuw', 'stride') . '</span>';
                }
                break;

            case 'customer':
                $userId = (int) $this->repository->getField($postId, 'user_id', 0);
                if ($userId) {
                    $user = get_userdata($userId);
                    if ($user) {
                        $editUrl = get_edit_user_link($userId);
                        if ($editUrl) {
                            echo '<a href="' . esc_url($editUrl) . '">' . esc_html($user->display_name) . '</a>';
                        } else {
                            echo esc_html($user->display_name);
                        }
                        echo '<br><span style="color:#666;font-size:12px;">' . esc_html($user->user_email) . '</span>';
                    } else {
                        echo '<span style="color:#999;">' . __('Verwijderd', 'stride') . '</span>';
                    }
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;

            case 'total':
                $total = (int) $this->repository->getField($postId, 'total', 0);
                $discount = (int) $this->repository->getField($postId, 'discount', 0);
                echo '<strong>' . esc_html(Money::cents($total)->format()) . '</strong>';
                if ($discount > 0) {
                    echo '<br><span style="color:#00a32a;font-size:12px;">-' . esc_html(Money::cents($discount)->format()) . '</span>';
                }
                break;

            case 'status':
                $status = $this->repository->getField($postId, 'status', 'draft');
                $statusEnum = QuoteStatus::tryFrom($status) ?? QuoteStatus::Draft;
                $config = $this->getStatusConfig($statusEnum);
                echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . $config['bg'] . ';color:' . $config['color'] . ';font-size:12px;">';
                echo esc_html($statusEnum->label());
                echo '</span>';
                break;

            case 'valid_until':
                $validUntil = $this->repository->getField($postId, 'valid_until', '');
                if ($validUntil) {
                    $isExpired = strtotime($validUntil) < time();
                    $style = $isExpired ? 'color:#d63638;' : '';
                    echo '<span style="' . $style . '">' . esc_html(date_i18n('j M Y', strtotime($validUntil))) . '</span>';
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;
        }
    }

    /**
     * Get status display configuration.
     *
     * @return array{color: string, bg: string}
     */
    private function getStatusConfig(QuoteStatus $status): array
    {
        return match ($status) {
            QuoteStatus::Draft => ['color' => '#787c82', 'bg' => '#f0f0f1'],
            QuoteStatus::Sent => ['color' => '#2271b1', 'bg' => '#e5f0f8'],
            QuoteStatus::Exported => ['color' => '#00a32a', 'bg' => '#e6f4ea'],
            QuoteStatus::Cancelled => ['color' => '#b32d2e', 'bg' => '#fcf0f1'],
        };
    }

    /**
     * Define sortable columns.
     *
     * @param array<string, string> $columns
     * @return array<string, string>
     */
    public function defineSortableColumns(array $columns): array
    {
        $columns['quote_number'] = 'quote_number';
        $columns['valid_until'] = 'valid_until';
        $columns['date'] = 'date';
        return $columns;
    }

    /**
     * Handle sorting by custom meta columns.
     */
    public function handleColumnSorting(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== QuoteCPT::POST_TYPE) {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === 'quote_number') {
            $query->set('meta_key', 'quote_number');
            $query->set('orderby', 'meta_value');
        } elseif ($orderby === 'valid_until') {
            $query->set('meta_key', 'valid_until');
            $query->set('orderby', 'meta_value');
        }
    }
}
