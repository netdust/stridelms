<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Infrastructure\AbstractService;
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
 */
final class QuoteAdminController extends AbstractService
{
    public function __construct(
        private readonly QuoteService $quoteService,
        private readonly QuoteRepository $repository,
        private readonly VoucherService $voucherService,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Quote Admin Controller',
            'description' => 'Admin interface for quote management',
            'priority' => 100, // Late priority, after services
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'quote-admin';
    }

    protected function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . QuoteCPT::POST_TYPE, [$this, 'handleSave'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('wp_ajax_stride_get_user_data', [$this, 'ajaxGetUserData']);

        // Admin list columns
        add_filter('manage_' . QuoteCPT::POST_TYPE . '_posts_columns', [$this, 'defineListColumns']);
        add_action('manage_' . QuoteCPT::POST_TYPE . '_posts_custom_column', [$this, 'renderListColumn'], 10, 2);
        add_filter('manage_edit-' . QuoteCPT::POST_TYPE . '_sortable_columns', [$this, 'defineSortableColumns']);
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
            'high'
        );

        // Notes metabox
        add_meta_box(
            'stride_quote_notes',
            __('Notities', 'stride'),
            [$this, 'renderNotesMetabox'],
            QuoteCPT::POST_TYPE,
            'normal',
            'default'
        );

        // Status & actions sidebar
        add_meta_box(
            'stride_quote_status',
            __('Status & Acties', 'stride'),
            [$this, 'renderActionsMetabox'],
            QuoteCPT::POST_TYPE,
            'side',
            'high'
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
        $cssFile = get_stylesheet_directory() . '/assets/css/admin/quote-admin.css';
        if (file_exists($cssFile)) {
            wp_enqueue_style(
                'stride-quote-admin',
                get_stylesheet_directory_uri() . '/assets/css/admin/quote-admin.css',
                [],
                filemtime($cssFile)
            );
        }

        // Quote admin scripts
        $jsFile = get_stylesheet_directory() . '/assets/js/admin/quote-admin.js';
        if (file_exists($jsFile)) {
            wp_enqueue_script(
                'stride-quote-admin',
                get_stylesheet_directory_uri() . '/assets/js/admin/quote-admin.js',
                ['jquery', 'select2'],
                filemtime($jsFile),
                true
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
                    <?php if (!empty($note['_deleted'])) continue; ?>
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
            $validStatuses = ['draft', 'sent', 'exported'];
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
                }
            }
        }

        // Handle valid_until update
        if (!empty($_POST['ntdst_fields']['valid_until'])) {
            $updateData['valid_until'] = sanitize_text_field($_POST['ntdst_fields']['valid_until']);
        }

        // Update if we have data
        if (!empty($updateData)) {
            $this->repository->updateMeta($postId, $updateData);
        }

        // Handle send quote action
        if (!empty($_POST['stride_send_quote'])) {
            $sendTo = sanitize_email($_POST['stride_send_to'] ?? '');
            $sendCc = sanitize_email($_POST['stride_send_cc'] ?? '');
            if ($sendTo) {
                do_action('stride/quote/send_email', $postId, $sendTo, $sendCc);
            }
        }

        // Handle PDF regeneration
        if (!empty($_POST['stride_regenerate_pdf'])) {
            do_action('stride/quote/regenerate_pdf', $postId);
        }

        // Handle voucher/discount actions
        $this->handleVoucherActions($postId);
    }

    private function processBillingData(array $input): array
    {
        $billing = [];
        $fields = ['organisation', 'email', 'address', 'postal_code', 'city', 'vat_number', 'gln_number'];

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

        $tax = (int) round($subtotal * 0.21);
        $total = $subtotal + $tax;

        return [
            'items' => $processedItems,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
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
            return;
        }

        $subtotal = (int) ($quote['subtotal'] ?? 0);
        $discountCents = (int) round($amount * 100);
        $discountCents = min($discountCents, $subtotal); // Can't discount more than subtotal

        $taxableAmount = $subtotal - $discountCents;
        $tax = (int) round($taxableAmount * 0.21);
        $total = $taxableAmount + $tax;

        $this->repository->updateMeta($postId, [
            'voucher_code' => '',
            'discount' => $discountCents,
            'tax' => $tax,
            'total' => $total,
        ]);
    }

    private function removeDiscount(int $postId): void
    {
        $quote = $this->quoteService->getQuote($postId, true);
        if (is_wp_error($quote)) {
            return;
        }

        $subtotal = (int) ($quote['subtotal'] ?? 0);
        $tax = (int) round($subtotal * 0.21);
        $total = $subtotal + $tax;

        $this->repository->updateMeta($postId, [
            'voucher_code' => '',
            'discount' => 0,
            'tax' => $tax,
            'total' => $total,
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

        $price = (int) get_post_meta($editionId, 'price', true);
        $priceNonMember = (int) get_post_meta($editionId, 'price_non_member', true);

        // Use member price, or non-member if no member price
        $unitPrice = $price > 0 ? $price : ($priceNonMember > 0 ? $priceNonMember : 0);
        $unitPriceCents = $unitPrice * 100;

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
        $subtotal = $unitPriceCents;
        $tax = (int) round($subtotal * 0.21);
        $total = $subtotal + $tax;

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
            'subtotal' => $subtotal,
            'discount' => 0,
            'tax' => $tax,
            'total' => $total,
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
            'address' => get_user_meta($userId, 'billing_address', true) ?: '',
            'postal_code' => get_user_meta($userId, 'billing_postal_code', true) ?: '',
            'city' => get_user_meta($userId, 'billing_city', true) ?: '',
            'vat_number' => get_user_meta($userId, 'vat_number', true) ?: '',
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
                $quoteNumber = get_post_meta($postId, 'quote_number', true);
                $isLocked = (bool) get_post_meta($postId, 'locked', true);
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
                $userId = (int) get_post_meta($postId, 'user_id', true);
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
                $total = (int) get_post_meta($postId, 'total', true);
                $discount = (int) get_post_meta($postId, 'discount', true);
                echo '<strong>' . esc_html(Money::cents($total)->format()) . '</strong>';
                if ($discount > 0) {
                    echo '<br><span style="color:#00a32a;font-size:12px;">-' . esc_html(Money::cents($discount)->format()) . '</span>';
                }
                break;

            case 'status':
                $status = get_post_meta($postId, 'status', true) ?: 'draft';
                $statusEnum = QuoteStatus::tryFrom($status) ?? QuoteStatus::Draft;
                $config = $this->getStatusConfig($statusEnum);
                echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . $config['bg'] . ';color:' . $config['color'] . ';font-size:12px;">';
                echo esc_html($statusEnum->label());
                echo '</span>';
                break;

            case 'valid_until':
                $validUntil = get_post_meta($postId, 'valid_until', true);
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
}
