<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

use Stride\Domain\DiscountType;
use Stride\Domain\Money;
use Stride\Domain\VoucherStatus;
use Stride\Modules\Invoicing\VoucherCPT;
use Stride\Modules\Invoicing\VoucherRepository;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Invoicing\Helpers\VoucherCodeGenerator;
use WP_Post;

/**
 * Voucher Admin Controller.
 *
 * Handles admin interface for vouchers:
 * - Registers metaboxes
 * - Renders metabox content inline
 * - Handles save operations
 *
 * Plain class — owned by QuoteService.
 */
final class VoucherAdminController
{
    public const NONCE_SAVE = 'stride_save_voucher';
    public const NONCE_FIELD = 'stride_voucher_nonce';

    public function __construct(
        private readonly VoucherService $voucherService,
        private readonly VoucherRepository $repository,
    ) {
        $this->init();
    }

    protected function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . VoucherCPT::POST_TYPE, [$this, 'handleSave'], 10, 2);

        // Admin list columns
        add_filter('manage_' . VoucherCPT::POST_TYPE . '_posts_columns', [$this, 'defineListColumns']);
        add_action('manage_' . VoucherCPT::POST_TYPE . '_posts_custom_column', [$this, 'renderListColumn'], 10, 2);
        add_filter('manage_edit-' . VoucherCPT::POST_TYPE . '_sortable_columns', [$this, 'defineSortableColumns']);
        add_action('pre_get_posts', [$this, 'handleColumnSorting']);
    }

    public function registerMetaboxes(): void
    {
        // Remove default editor
        remove_post_type_support(VoucherCPT::POST_TYPE, 'editor');

        // Main voucher details
        add_meta_box(
            'stride_voucher_details',
            __('Voucher', 'stride'),
            [$this, 'renderVoucherMetabox'],
            VoucherCPT::POST_TYPE,
            'normal',
            'high',
        );

        // Redemptions history
        add_meta_box(
            'stride_voucher_audit',
            __('Verzilveringshistorie', 'stride'),
            [$this, 'renderAuditMetabox'],
            VoucherCPT::POST_TYPE,
            'normal',
            'low',
        );

        // Status sidebar
        add_meta_box(
            'stride_voucher_actions',
            __('Acties', 'stride'),
            [$this, 'renderActionsMetabox'],
            VoucherCPT::POST_TYPE,
            'side',
            'high',
        );
    }

    /**
     * Render the main voucher metabox.
     */
    public function renderVoucherMetabox(WP_Post $post): void
    {
        $voucher = $this->voucherService->getVoucher($post->ID);
        $isNew = !$voucher || empty($voucher['code']);

        // Default values for new vouchers
        if ($isNew) {
            $voucher = [
                'code' => VoucherCodeGenerator::generate('VAD', fn($c) => $this->repository->findByCode($c) !== null),
                'status' => VoucherStatus::Active->value,
                'usage_limit' => 1,
                'used_count' => 0,
                'discount_type' => DiscountType::Full->value,
                'discount_value' => 0,
                'valid_from' => date('Y-m-d'),
                'valid_until' => date('Y-m-d', strtotime('+1 year')),
                'edition_id' => 0,
                'scope_mode' => 'all',
                'excluded_edition_ids' => [],
                'apply_mode' => 'full',
                'created_by' => get_current_user_id(),
            ];
        }

        // Back-compat: legacy voucher with edition_id > 0 and empty scope_mode = "only" mode
        $scopeMode = $voucher['scope_mode'] ?? '';
        if ($scopeMode === '') {
            $scopeMode = ((int) ($voucher['edition_id'] ?? 0) > 0) ? 'only' : 'all';
        }
        $applyMode = $voucher['apply_mode'] ?? 'full';
        $excludedIds = is_array($voucher['excluded_edition_ids'] ?? null)
            ? array_map('intval', $voucher['excluded_edition_ids'])
            : [];

        wp_nonce_field(self::NONCE_SAVE, self::NONCE_FIELD);

        $status = $voucher['status'] ?? VoucherStatus::Active->value;
        $usedCount = (int) ($voucher['used_count'] ?? 0);
        $usageLimit = (int) ($voucher['usage_limit'] ?? 1);
        $discountType = $voucher['discount_type'] ?? DiscountType::Full->value;
        $discountValue = (int) ($voucher['discount_value'] ?? 0);

        // Convert cents to euros for display
        $discountValueDisplay = ($discountType === DiscountType::Fixed->value && $discountValue > 0)
            ? $discountValue / 100
            : $discountValue;
        ?>
        <div class="stride-voucher-admin">
            <style>
                .stride-voucher-admin { padding: 0; }
                .stride-section { margin-bottom: 20px; }
                .stride-section h4 { margin: 0 0 12px 0; font-size: 13px; color: #1d2327; }
                .stride-field-row { display: flex; gap: 16px; margin-bottom: 12px; }
                .stride-field { flex: 1; }
                .stride-field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1d2327; }
                .stride-field input[type="text"],
                .stride-field input[type="number"],
                .stride-field input[type="date"],
                .stride-field input[type="email"],
                .stride-field select:not([multiple]) { width: 100%; padding: 6px 10px; }
                .stride-field select[multiple] { width: 100%; padding: 4px; }
                .stride-field .description { font-size: 11px; color: #646970; margin-top: 3px; }
                .stride-field .stride-radio { display: flex; align-items: center; gap: 8px; font-weight: 400; font-size: 13px; padding: 4px 0; margin-bottom: 0; cursor: pointer; }
                .stride-field .stride-radio input[type="radio"] { width: auto; margin: 0; flex: 0 0 auto; }
                .stride-field > label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: #1d2327; }
            </style>

            <input type="hidden" name="ntdst_fields[code]" value="<?php echo esc_attr($voucher['code']); ?>">

            <div class="stride-section">
                <h4><?php esc_html_e('Instellingen', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="voucher_status"><?php esc_html_e('Status', 'stride'); ?></label>
                        <select id="voucher_status" name="ntdst_fields[status]">
                            <?php foreach (VoucherStatus::cases() as $statusOption): ?>
                            <option value="<?php echo esc_attr($statusOption->value); ?>" <?php selected($status, $statusOption->value); ?>>
                                <?php echo esc_html($statusOption->label()); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="stride-field">
                        <label for="voucher_usage_limit"><?php esc_html_e('Gebruikslimiet', 'stride'); ?></label>
                        <input type="number" id="voucher_usage_limit" name="ntdst_fields[usage_limit]"
                               value="<?php echo esc_attr($usageLimit); ?>" min="0" step="1">
                        <p class="description"><?php esc_html_e('0 = onbeperkt', 'stride'); ?></p>
                    </div>
                </div>

                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="voucher_valid_from"><?php esc_html_e('Geldig vanaf', 'stride'); ?></label>
                        <input type="date" id="voucher_valid_from" name="ntdst_fields[valid_from]"
                               value="<?php echo esc_attr($voucher['valid_from'] ?? ''); ?>">
                    </div>
                    <div class="stride-field">
                        <label for="voucher_valid_until"><?php esc_html_e('Geldig tot', 'stride'); ?></label>
                        <input type="date" id="voucher_valid_until" name="ntdst_fields[valid_until]"
                               value="<?php echo esc_attr($voucher['valid_until'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="stride-section">
                <h4><?php esc_html_e('Korting', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="voucher_discount_type"><?php esc_html_e('Type', 'stride'); ?></label>
                        <select id="voucher_discount_type" name="ntdst_fields[discount_type]">
                            <?php foreach (DiscountType::cases() as $type): ?>
                            <option value="<?php echo esc_attr($type->value); ?>" <?php selected($discountType, $type->value); ?>>
                                <?php echo esc_html($type->label()); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="stride-field">
                        <label for="voucher_discount_value"><?php esc_html_e('Waarde', 'stride'); ?></label>
                        <input type="number" id="voucher_discount_value" name="ntdst_fields[discount_value]"
                               value="<?php echo esc_attr($discountValueDisplay); ?>" min="0" step="0.01">
                        <p class="description"><?php esc_html_e('€ of % afhankelijk van type', 'stride'); ?></p>
                    </div>
                </div>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="voucher_apply_mode"><?php esc_html_e('Toepassen op', 'stride'); ?></label>
                        <select id="voucher_apply_mode" name="ntdst_fields[apply_mode]">
                            <option value="full" <?php selected($applyMode, 'full'); ?>>
                                <?php esc_html_e('Volledige editie', 'stride'); ?>
                            </option>
                            <option value="single_session" <?php selected($applyMode, 'single_session'); ?>>
                                <?php esc_html_e('Eén sessie (pro rata)', 'stride'); ?>
                            </option>
                        </select>
                        <p class="description"><?php esc_html_e('Bij "één sessie" wordt het kortingsbedrag gedeeld door het aantal sessies van de editie.', 'stride'); ?></p>
                    </div>
                </div>
            </div>

            <div class="stride-section">
                <h4><?php esc_html_e('Beperking', 'stride'); ?></h4>
                <?php
                $editions = get_posts([
                    'post_type' => 'vad_edition',
                    'post_status' => 'publish',
                    'posts_per_page' => 200,
                    'orderby' => 'title',
                    'order' => 'ASC',
                ]);
        ?>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label><?php esc_html_e('Geldig voor', 'stride'); ?></label>
                        <label class="stride-radio">
                            <input type="radio" name="ntdst_fields[scope_mode]" value="all"
                                <?php checked($scopeMode, 'all'); ?>
                                onclick="strideVoucherToggleScope('all')">
                            <?php esc_html_e('Alle edities', 'stride'); ?>
                        </label>
                        <label class="stride-radio">
                            <input type="radio" name="ntdst_fields[scope_mode]" value="only"
                                <?php checked($scopeMode, 'only'); ?>
                                onclick="strideVoucherToggleScope('only')">
                            <?php esc_html_e('Alleen voor één editie', 'stride'); ?>
                        </label>
                        <label class="stride-radio">
                            <input type="radio" name="ntdst_fields[scope_mode]" value="except"
                                <?php checked($scopeMode, 'except'); ?>
                                onclick="strideVoucherToggleScope('except')">
                            <?php esc_html_e('Alle edities behalve…', 'stride'); ?>
                        </label>
                    </div>
                </div>

                <div id="stride-scope-only" class="stride-field-row" style="<?php echo $scopeMode === 'only' ? '' : 'display:none;'; ?>">
                    <div class="stride-field">
                        <label for="voucher_edition_id"><?php esc_html_e('Editie', 'stride'); ?></label>
                        <select id="voucher_edition_id" name="ntdst_fields[edition_id]">
                            <option value="0"><?php esc_html_e('— Kies een editie —', 'stride'); ?></option>
                            <?php foreach ($editions as $edition): ?>
                            <option value="<?php echo esc_attr($edition->ID); ?>" <?php selected((int) ($voucher['edition_id'] ?? 0), $edition->ID); ?>>
                                <?php echo esc_html($edition->post_title); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="stride-scope-except" class="stride-field-row" style="<?php echo $scopeMode === 'except' ? '' : 'display:none;'; ?>">
                    <div class="stride-field">
                        <label for="voucher_excluded_edition_ids"><?php esc_html_e('Uitgesloten edities', 'stride'); ?></label>
                        <select id="voucher_excluded_edition_ids" name="ntdst_fields[excluded_edition_ids][]" multiple size="8" style="height:auto;">
                            <?php foreach ($editions as $edition): ?>
                            <option value="<?php echo esc_attr($edition->ID); ?>" <?php echo in_array($edition->ID, $excludedIds, true) ? 'selected' : ''; ?>>
                                <?php echo esc_html($edition->post_title); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Houd Ctrl/Cmd ingedrukt om meerdere edities te selecteren.', 'stride'); ?></p>
                    </div>
                </div>
            </div>

            <script>
            function strideVoucherToggleScope(mode) {
                document.getElementById('stride-scope-only').style.display = (mode === 'only') ? '' : 'none';
                document.getElementById('stride-scope-except').style.display = (mode === 'except') ? '' : 'none';
            }
            </script>

            <input type="hidden" name="ntdst_fields[used_count]" value="<?php echo esc_attr($usedCount); ?>">
            <input type="hidden" name="ntdst_fields[created_by]" value="<?php echo esc_attr($voucher['created_by'] ?? get_current_user_id()); ?>">
        </div>
        <?php
    }

    /**
     * Render the audit metabox with redemption history.
     */
    public function renderAuditMetabox(WP_Post $post): void
    {
        $voucher = $this->voucherService->getVoucher($post->ID);
        $redemptions = $voucher['redemptions'] ?? [];

        if (empty($redemptions)) {
            echo '<p class="description">' . esc_html__('Nog geen verzilveringen.', 'stride') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Gebruiker', 'stride') . '</th>';
        echo '<th>' . esc_html__('Offerte', 'stride') . '</th>';
        echo '<th>' . esc_html__('Datum', 'stride') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($redemptions as $redemption) {
            $userId = (int) ($redemption['user_id'] ?? 0);
            $quoteId = (int) ($redemption['quote_id'] ?? 0);
            $date = $redemption['redeemed_at'] ?? '';

            // Get user display name
            $user = get_userdata($userId);
            $userName = $user ? esc_html($user->display_name) : sprintf(__('Gebruiker #%d', 'stride'), $userId);
            $userEditUrl = $user ? get_edit_user_link($userId) : null;
            $userLink = $userEditUrl ? '<a href="' . esc_url($userEditUrl) . '">' . $userName . '</a>' : $userName;

            // Get quote link
            $quoteTitle = $quoteId ? get_the_title($quoteId) : '';
            $quoteEditUrl = $quoteId ? get_edit_post_link($quoteId) : null;
            $quoteLink = $quoteEditUrl
                ? '<a href="' . esc_url($quoteEditUrl) . '">' . esc_html($quoteTitle ?: "#{$quoteId}") . '</a>'
                : ($quoteId ? esc_html($quoteTitle ?: "#{$quoteId}") : '-');

            echo '<tr>';
            echo '<td>' . $userLink . '</td>';
            echo '<td>' . $quoteLink . '</td>';
            echo '<td>' . esc_html($date ? date_i18n('d M Y H:i', strtotime($date)) : '-') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render sidebar actions metabox.
     */
    public function renderActionsMetabox(WP_Post $post): void
    {
        $voucher = $this->voucherService->getVoucher($post->ID);

        if (!$voucher || empty($voucher['code'])) {
            echo '<p class="description">' . esc_html__('Sla eerst op om info te zien.', 'stride') . '</p>';
            return;
        }

        $statusEnum = VoucherStatus::tryFrom($voucher['status'] ?? '') ?? VoucherStatus::Active;
        $discountType = DiscountType::tryFrom($voucher['discount_type'] ?? '') ?? DiscountType::Full;
        ?>
        <style>
            .stride-voucher-sidebar .meta-list { margin: 0; padding: 0; list-style: none; }
            .stride-voucher-sidebar .meta-list li { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f1; font-size: 12px; }
            .stride-voucher-sidebar .meta-list li:last-child { border-bottom: none; }
            .stride-voucher-sidebar .meta-label { color: #646970; }
            .stride-voucher-sidebar .meta-value { font-weight: 500; }
            .stride-voucher-sidebar .voucher-code { font-family: monospace; font-size: 14px; padding: 8px; background: #f0f0f1; border-radius: 4px; margin-bottom: 12px; text-align: center; }
        </style>

        <div class="stride-voucher-sidebar">
            <div class="voucher-code"><?php echo esc_html($voucher['code']); ?></div>
            <ul class="meta-list">
                <li>
                    <span class="meta-label"><?php esc_html_e('Status', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html($statusEnum->label()); ?></span>
                </li>
                <li>
                    <span class="meta-label"><?php esc_html_e('Type', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html($discountType->label()); ?></span>
                </li>
                <li>
                    <span class="meta-label"><?php esc_html_e('Gebruikt', 'stride'); ?></span>
                    <span class="meta-value">
                        <?php
                        $usageLimit = (int) ($voucher['usage_limit'] ?? 0);
        $usedCount = (int) ($voucher['used_count'] ?? 0);
        if ($usageLimit > 0) {
            printf('%d / %d', $usedCount, $usageLimit);
        } else {
            printf('%d', $usedCount);
        }
        ?>
                    </span>
                </li>
                <?php if (!empty($voucher['valid_until'])): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Geldig tot', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($voucher['valid_until']))); ?></span>
                </li>
                <?php endif; ?>
                <?php
                $createdBy = (int) ($voucher['created_by'] ?? 0);
        $createdByUser = $createdBy ? get_userdata($createdBy) : null;
        if ($createdByUser):
            ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Aangemaakt door', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html($createdByUser->display_name ?: $createdByUser->user_login); ?></span>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Handle saving voucher data.
     */
    public function handleSave(int $postId, WP_Post $post): void
    {
        // Verify nonce
        if (!isset($_POST[self::NONCE_FIELD])
            || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_SAVE)) {
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

        $fields = $_POST['ntdst_fields'] ?? [];
        if (empty($fields)) {
            return;
        }

        $updateData = [];

        // Process code
        if (isset($fields['code'])) {
            $code = strtoupper(trim(sanitize_text_field($fields['code'])));
            if (!empty($code)) {
                $updateData['code'] = $code;

                // Update post title to match code
                remove_action('save_post_' . VoucherCPT::POST_TYPE, [$this, 'handleSave'], 10);
                wp_update_post([
                    'ID' => $postId,
                    'post_title' => $code,
                ]);
                add_action('save_post_' . VoucherCPT::POST_TYPE, [$this, 'handleSave'], 10, 2);
            }
        }

        // Process status
        if (isset($fields['status'])) {
            $status = sanitize_text_field($fields['status']);
            if (VoucherStatus::tryFrom($status)) {
                $updateData['status'] = $status;
            }
        }

        // Process usage limit
        if (isset($fields['usage_limit'])) {
            $updateData['usage_limit'] = absint($fields['usage_limit']);
        }

        // Process discount type
        if (isset($fields['discount_type'])) {
            $type = sanitize_text_field($fields['discount_type']);
            if (DiscountType::tryFrom($type)) {
                $updateData['discount_type'] = $type;
            }
        }

        // Process discount value (convert euros to cents for fixed amounts)
        if (isset($fields['discount_value'])) {
            $discountType = $updateData['discount_type'] ?? ($fields['discount_type'] ?? 'full');
            $value = (float) $fields['discount_value'];

            if ($discountType === DiscountType::Fixed->value) {
                $updateData['discount_value'] = (int) round($value * 100);
            } else {
                $updateData['discount_value'] = (int) $value;
            }
        }

        // Process validity dates
        if (isset($fields['valid_from'])) {
            $updateData['valid_from'] = sanitize_text_field($fields['valid_from']);
        }
        if (isset($fields['valid_until'])) {
            $updateData['valid_until'] = sanitize_text_field($fields['valid_until']);
        }

        // Process scope_mode (alle / alleen / behalve)
        $scopeMode = 'all';
        if (isset($fields['scope_mode']) && in_array($fields['scope_mode'], ['all', 'only', 'except'], true)) {
            $scopeMode = $fields['scope_mode'];
        }
        $updateData['scope_mode'] = $scopeMode;

        // Process edition_id — only meaningful when scope = "only"
        $editionId = ($scopeMode === 'only' && isset($fields['edition_id']))
            ? absint($fields['edition_id'])
            : 0;
        $updateData['edition_id'] = $editionId;

        // Process excluded_edition_ids — only meaningful when scope = "except"
        $excludedIds = [];
        if ($scopeMode === 'except' && isset($fields['excluded_edition_ids']) && is_array($fields['excluded_edition_ids'])) {
            $excludedIds = array_values(array_filter(array_map('absint', $fields['excluded_edition_ids'])));
        }
        $updateData['excluded_edition_ids'] = $excludedIds;

        // Process apply_mode (full | single_session)
        $applyMode = 'full';
        if (isset($fields['apply_mode']) && in_array($fields['apply_mode'], ['full', 'single_session'], true)) {
            $applyMode = $fields['apply_mode'];
        }
        $updateData['apply_mode'] = $applyMode;

        // Preserve used_count and created_by
        if (isset($fields['used_count'])) {
            $updateData['used_count'] = absint($fields['used_count']);
        }
        if (isset($fields['created_by'])) {
            $updateData['created_by'] = absint($fields['created_by']);
        }

        // For new vouchers, ensure redemptions array exists
        $existingVoucher = $this->voucherService->getVoucher($postId);
        if (!$existingVoucher || empty($existingVoucher['code'])) {
            if (!isset($updateData['redemptions'])) {
                $updateData['redemptions'] = [];
            }
        }

        // Update if we have data
        if (!empty($updateData)) {
            $this->repository->updateMeta($postId, $updateData);
        }
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
        $newColumns['code'] = __('Code', 'stride');
        $newColumns['discount'] = __('Korting', 'stride');
        $newColumns['usage'] = __('Gebruik', 'stride');
        $newColumns['status'] = __('Status', 'stride');
        $newColumns['valid_dates'] = __('Geldigheid', 'stride');
        $newColumns['edition'] = __('Editie', 'stride');

        return $newColumns;
    }

    /**
     * Render admin list column content.
     */
    public function renderListColumn(string $column, int $postId): void
    {
        switch ($column) {
            case 'code':
                $code = $this->repository->getField($postId, 'code', '');
                if ($code) {
                    echo '<code style="font-size:13px;background:#f0f0f1;padding:2px 6px;border-radius:3px;">' . esc_html($code) . '</code>';
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;

            case 'discount':
                $type = $this->repository->getField($postId, 'discount_type', '');
                $value = (int) $this->repository->getField($postId, 'discount_value', 0);
                $typeEnum = DiscountType::tryFrom($type);

                if ($typeEnum) {
                    $label = match ($typeEnum) {
                        DiscountType::Full => __('100% gratis', 'stride'),
                        DiscountType::Fixed => Money::cents($value)->format(),
                        DiscountType::Percentage => $value . '%',
                    };
                    echo '<strong>' . esc_html($label) . '</strong>';
                } else {
                    echo '<span style="color:#999;">—</span>';
                }
                break;

            case 'usage':
                $usedCount = (int) $this->repository->getField($postId, 'used_count', 0);
                $usageLimit = (int) $this->repository->getField($postId, 'usage_limit', 0);

                if ($usageLimit > 0) {
                    $percentage = min(100, round(($usedCount / $usageLimit) * 100));
                    $color = $percentage >= 100 ? '#d63638' : ($percentage >= 80 ? '#dba617' : '#00a32a');
                    echo '<span style="color:' . $color . ';font-weight:500;">' . $usedCount . '/' . $usageLimit . '</span>';
                } else {
                    echo $usedCount . ' <span style="color:#999;">(∞)</span>';
                }
                break;

            case 'status':
                $status = $this->repository->getField($postId, 'status', 'active');
                $statusEnum = VoucherStatus::tryFrom($status) ?? VoucherStatus::Active;
                $config = $this->getVoucherStatusConfig($statusEnum);
                echo '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . $config['bg'] . ';color:' . $config['color'] . ';font-size:12px;">';
                echo esc_html($statusEnum->label());
                echo '</span>';
                break;

            case 'valid_dates':
                $validFrom = $this->repository->getField($postId, 'valid_from', '');
                $validUntil = $this->repository->getField($postId, 'valid_until', '');

                if ($validFrom || $validUntil) {
                    $from = $validFrom ? date_i18n('j M Y', strtotime($validFrom)) : '—';
                    $until = $validUntil ? date_i18n('j M Y', strtotime($validUntil)) : '—';
                    $isExpired = $validUntil && strtotime($validUntil) < time();
                    $style = $isExpired ? 'color:#d63638;' : '';
                    echo '<span style="' . $style . '">' . esc_html($from) . ' – ' . esc_html($until) . '</span>';
                } else {
                    echo '<span style="color:#00a32a;">' . __('Altijd geldig', 'stride') . '</span>';
                }
                break;

            case 'edition':
                $scopeMode = (string) $this->repository->getField($postId, 'scope_mode', '');
                $editionId = (int) $this->repository->getField($postId, 'edition_id', 0);
                $excluded = $this->repository->getField($postId, 'excluded_edition_ids', []);
                if (!is_array($excluded)) {
                    $excluded = [];
                }

                // Back-compat: legacy voucher with empty scope_mode + edition_id > 0 = "only"
                if ($scopeMode === '' && $editionId > 0) {
                    $scopeMode = 'only';
                }

                if ($scopeMode === 'only' && $editionId > 0) {
                    $editionTitle = get_the_title($editionId);
                    $editUrl = get_edit_post_link($editionId);
                    $link = $editUrl
                        ? '<a href="' . esc_url($editUrl) . '">' . esc_html($editionTitle) . '</a>'
                        : esc_html($editionTitle);
                    echo '<span style="color:#646970;">' . esc_html__('Alleen:', 'stride') . '</span> ' . $link;
                } elseif ($scopeMode === 'except' && !empty($excluded)) {
                    $titles = array_filter(array_map(
                        fn($id) => get_the_title((int) $id) ?: null,
                        $excluded,
                    ));
                    if (count($titles) > 3) {
                        $preview = array_slice($titles, 0, 3);
                        $rest = count($titles) - 3;
                        echo '<span style="color:#646970;">' . esc_html__('Behalve:', 'stride') . '</span> '
                            . esc_html(implode(', ', $preview))
                            . ' <span style="color:#999;">' . sprintf(esc_html__('+%d meer', 'stride'), $rest) . '</span>';
                    } else {
                        echo '<span style="color:#646970;">' . esc_html__('Behalve:', 'stride') . '</span> '
                            . esc_html(implode(', ', $titles));
                    }
                } else {
                    echo '<span style="color:#999;">' . esc_html__('Alle edities', 'stride') . '</span>';
                }
                break;
        }
    }

    /**
     * Get voucher status display configuration.
     *
     * @return array{color: string, bg: string}
     */
    private function getVoucherStatusConfig(VoucherStatus $status): array
    {
        return match ($status) {
            VoucherStatus::Active => ['color' => '#00a32a', 'bg' => '#e6f4ea'],
            VoucherStatus::Exhausted => ['color' => '#dba617', 'bg' => '#fcf0e3'],
            VoucherStatus::Expired => ['color' => '#d63638', 'bg' => '#fcf0f1'],
            VoucherStatus::Disabled => ['color' => '#787c82', 'bg' => '#f0f0f1'],
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
        $columns['code'] = 'title';
        $columns['status'] = 'status';
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

        if ($query->get('post_type') !== VoucherCPT::POST_TYPE) {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === 'status') {
            $query->set('meta_key', 'status');
            $query->set('orderby', 'meta_value');
        }
    }
}
