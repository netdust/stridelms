<?php

namespace ntdst\Stride\invoicing\Admin;

defined('ABSPATH') || exit;

use ntdst\Stride\invoicing\VoucherService;
use ntdst\Stride\invoicing\Helpers\VoucherCodeGenerator;

/**
 * Voucher Admin Controller
 *
 * Handles admin interface for vouchers:
 * - Registers metaboxes
 * - Renders metabox content
 * - Handles save operations
 *
 * This class is instantiated by VoucherService in admin context.
 * Not a service - just a plain admin handler class.
 *
 * @package stride\services\invoicing\Admin
 */
class VoucherAdminController
{
    private VoucherService $voucherService;

    /**
     * Constructor with optional dependency injection for testing
     *
     * @param VoucherService|null $voucherService Service instance (resolved from DI if null)
     */
    public function __construct(?VoucherService $voucherService = null)
    {
        $this->voucherService = $voucherService ?? $this->resolveService(VoucherService::class);

        // Register hooks
        add_action('add_meta_boxes', [$this, 'registerMetaboxes']);
        add_action('save_post_' . VoucherService::POST_TYPE, [$this, 'saveVoucherMeta'], 10, 2);
    }

    /**
     * Resolve service from DI container
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
     * Register custom metaboxes for voucher admin
     */
    public function registerMetaboxes(): void
    {
        add_meta_box(
            'stride_voucher_details',
            __('Voucher', 'stride'),
            [$this, 'renderVoucherMetabox'],
            VoucherService::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'stride_voucher_audit',
            __('Verzilveringshistorie', 'stride'),
            [$this, 'renderAuditMetabox'],
            VoucherService::POST_TYPE,
            'normal',
            'low'
        );

        add_meta_box(
            'stride_voucher_actions',
            __('Acties', 'stride'),
            [$this, 'renderActionsMetabox'],
            VoucherService::POST_TYPE,
            'side',
            'high'
        );
    }

    /**
     * Render the audit metabox with redemption history
     */
    public function renderAuditMetabox(\WP_Post $post): void
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
        echo '<th>' . esc_html__('Item', 'stride') . '</th>';
        echo '<th>' . esc_html__('Korting', 'stride') . '</th>';
        echo '<th>' . esc_html__('Datum', 'stride') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($redemptions as $redemption) {
            $userId = (int) ($redemption['user_id'] ?? 0);
            $itemType = $redemption['item_type'] ?? 'course';
            $itemId = (int) ($redemption['item_id'] ?? $redemption['course_id'] ?? 0);
            $discount = (float) ($redemption['discount'] ?? 0);
            $date = $redemption['redeemed_at'] ?? '';

            // Get user display name
            $user = get_userdata($userId);
            $userName = $user ? esc_html($user->display_name) : sprintf(__('Gebruiker #%d', 'stride'), $userId);
            $userLink = $user ? '<a href="' . esc_url(get_edit_user_link($userId)) . '">' . $userName . '</a>' : $userName;

            // Get item title via filter
            $itemResolved = apply_filters('stride/quote/resolve_item', null, $itemType, $itemId);
            $itemTitle = $itemResolved['title'] ?? sprintf('%s #%d', $itemType, $itemId);
            $itemLink = $itemId ? '<a href="' . esc_url(get_edit_post_link($itemId)) . '">' . esc_html($itemTitle) . '</a>' : esc_html($itemTitle);

            echo '<tr>';
            echo '<td>' . $userLink . '</td>';
            echo '<td>' . $itemLink . '</td>';
            echo '<td>€ ' . esc_html(number_format($discount, 2, ',', '.')) . '</td>';
            echo '<td>' . esc_html($date) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the main voucher metabox
     */
    public function renderVoucherMetabox(\WP_Post $post): void
    {
        $voucher = $this->voucherService->getVoucher($post->ID);
        $isNew = !$voucher;

        // Default values for new vouchers
        if ($isNew) {
            $voucher = [
                'code' => VoucherCodeGenerator::generate('VAD'),
                'type' => VoucherService::TYPE_SINGLE,
                'status' => VoucherService::STATUS_ACTIVE,
                'usage_limit' => 1,
                'used_count' => 0,
                'discount_type' => VoucherService::DISCOUNT_FULL,
                'discount_value' => 0,
                'valid_from' => date('Y-m-d'),
                'valid_until' => date('Y-m-d', strtotime('+1 year')),
                'course_id' => 0,
                'item_type' => '',
                'item_id' => 0,
                'batch_id' => '',
                'created_by' => get_current_user_id(),
            ];
        }

        wp_nonce_field('stride_save_voucher', 'stride_voucher_nonce');

        $status = $voucher['status'];
        $usedCount = $voucher['used_count'];
        $usageLimit = $voucher['usage_limit'];
        $itemType = $voucher['item_type'] ?? '';
        $itemId = $voucher['item_id'] ?? $voucher['course_id'] ?? 0;
        ?>
        <div class="stride-voucher-admin">
            <style>
                .stride-voucher-admin { padding: 0; }
                .stride-section { margin-bottom: 20px; }
                .stride-section h4 { margin: 0 0 12px 0; font-size: 13px; color: #1d2327; }
                .stride-field-row { display: flex; gap: 16px; margin-bottom: 12px; }
                .stride-field { flex: 1; }
                .stride-field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #1d2327; }
                .stride-field input, .stride-field select { width: 100%; padding: 6px 10px; }
                .stride-field .description { font-size: 11px; color: #646970; margin-top: 3px; }
            </style>

            <input type="hidden" name="ntdst_fields[code]" value="<?php echo esc_attr($voucher['code']); ?>">

            <div class="stride-section">
                <h4><?php esc_html_e('Instellingen', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="voucher_type"><?php esc_html_e('Type', 'stride'); ?></label>
                        <select id="voucher_type" name="ntdst_fields[type]">
                            <option value="<?php echo esc_attr(VoucherService::TYPE_SINGLE); ?>" <?php selected($voucher['type'], VoucherService::TYPE_SINGLE); ?>>
                                <?php esc_html_e('Eenmalig', 'stride'); ?>
                            </option>
                            <option value="<?php echo esc_attr(VoucherService::TYPE_MULTI); ?>" <?php selected($voucher['type'], VoucherService::TYPE_MULTI); ?>>
                                <?php esc_html_e('Meervoudig', 'stride'); ?>
                            </option>
                        </select>
                    </div>
                    <div class="stride-field">
                        <label for="voucher_status"><?php esc_html_e('Status', 'stride'); ?></label>
                        <select id="voucher_status" name="ntdst_fields[status]">
                            <option value="<?php echo esc_attr(VoucherService::STATUS_ACTIVE); ?>" <?php selected($status, VoucherService::STATUS_ACTIVE); ?>>
                                <?php esc_html_e('Actief', 'stride'); ?>
                            </option>
                            <option value="<?php echo esc_attr(VoucherService::STATUS_DISABLED); ?>" <?php selected($status, VoucherService::STATUS_DISABLED); ?>>
                                <?php esc_html_e('Uitgeschakeld', 'stride'); ?>
                            </option>
                            <?php if (!$isNew): ?>
                            <option value="<?php echo esc_attr(VoucherService::STATUS_EXHAUSTED); ?>" <?php selected($status, VoucherService::STATUS_EXHAUSTED); ?>>
                                <?php esc_html_e('Uitgeput', 'stride'); ?>
                            </option>
                            <option value="<?php echo esc_attr(VoucherService::STATUS_EXPIRED); ?>" <?php selected($status, VoucherService::STATUS_EXPIRED); ?>>
                                <?php esc_html_e('Verlopen', 'stride'); ?>
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>

                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="voucher_usage_limit"><?php esc_html_e('Gebruikslimiet', 'stride'); ?></label>
                        <input type="number" id="voucher_usage_limit" name="ntdst_fields[usage_limit]"
                               value="<?php echo esc_attr($usageLimit); ?>" min="0" step="1">
                        <p class="description"><?php esc_html_e('0 = onbeperkt', 'stride'); ?></p>
                    </div>
                    <div class="stride-field">
                        <label for="voucher_valid_from"><?php esc_html_e('Geldig vanaf', 'stride'); ?></label>
                        <input type="date" id="voucher_valid_from" name="ntdst_fields[valid_from]"
                               value="<?php echo esc_attr($voucher['valid_from']); ?>">
                    </div>
                    <div class="stride-field">
                        <label for="voucher_valid_until"><?php esc_html_e('Geldig tot', 'stride'); ?></label>
                        <input type="date" id="voucher_valid_until" name="ntdst_fields[valid_until]"
                               value="<?php echo esc_attr($voucher['valid_until']); ?>">
                    </div>
                </div>
            </div>

            <div class="stride-section">
                <h4><?php esc_html_e('Korting', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="voucher_discount_type"><?php esc_html_e('Type', 'stride'); ?></label>
                        <select id="voucher_discount_type" name="ntdst_fields[discount_type]">
                            <option value="<?php echo esc_attr(VoucherService::DISCOUNT_FULL); ?>" <?php selected($voucher['discount_type'], VoucherService::DISCOUNT_FULL); ?>>
                                <?php esc_html_e('100% korting', 'stride'); ?>
                            </option>
                            <option value="<?php echo esc_attr(VoucherService::DISCOUNT_FIXED); ?>" <?php selected($voucher['discount_type'], VoucherService::DISCOUNT_FIXED); ?>>
                                <?php esc_html_e('Vast bedrag', 'stride'); ?>
                            </option>
                            <option value="<?php echo esc_attr(VoucherService::DISCOUNT_PERCENTAGE); ?>" <?php selected($voucher['discount_type'], VoucherService::DISCOUNT_PERCENTAGE); ?>>
                                <?php esc_html_e('Percentage', 'stride'); ?>
                            </option>
                        </select>
                    </div>
                    <div class="stride-field">
                        <label for="voucher_discount_value"><?php esc_html_e('Waarde', 'stride'); ?></label>
                        <input type="number" id="voucher_discount_value" name="ntdst_fields[discount_value]"
                               value="<?php echo esc_attr($voucher['discount_value']); ?>" min="0" step="0.01">
                        <p class="description"><?php esc_html_e('€ of % afhankelijk van type', 'stride'); ?></p>
                    </div>
                </div>
            </div>

            <div class="stride-section">
                <h4><?php esc_html_e('Beperking', 'stride'); ?></h4>
                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="voucher_item_type"><?php esc_html_e('Item type', 'stride'); ?></label>
                        <select id="voucher_item_type" name="ntdst_fields[item_type]">
                            <option value="" <?php selected($itemType, ''); ?>><?php esc_html_e('Alle types', 'stride'); ?></option>
                            <option value="course" <?php selected($itemType, 'course'); ?>><?php esc_html_e('Cursussen', 'stride'); ?></option>
                            <option value="product" <?php selected($itemType, 'product'); ?>><?php esc_html_e('Producten', 'stride'); ?></option>
                            <option value="service" <?php selected($itemType, 'service'); ?>><?php esc_html_e('Diensten', 'stride'); ?></option>
                        </select>
                    </div>
                    <div class="stride-field">
                        <label for="voucher_item_id"><?php esc_html_e('Specifiek item ID', 'stride'); ?></label>
                        <input type="number" id="voucher_item_id" name="ntdst_fields[item_id]"
                               value="<?php echo esc_attr($itemId); ?>" min="0" step="1">
                        <p class="description"><?php esc_html_e('0 = alle items van dit type', 'stride'); ?></p>
                    </div>
                </div>
            </div>

            <input type="hidden" name="ntdst_fields[used_count]" value="<?php echo esc_attr($usedCount); ?>">
            <input type="hidden" name="ntdst_fields[batch_id]" value="<?php echo esc_attr($voucher['batch_id']); ?>">
            <input type="hidden" name="ntdst_fields[created_by]" value="<?php echo esc_attr($voucher['created_by']); ?>">
            <!-- BC: Keep course_id in sync with item_id when item_type is course -->
            <input type="hidden" name="ntdst_fields[course_id]" value="<?php echo esc_attr($itemType === 'course' ? $itemId : 0); ?>">
        </div>
        <?php
    }

    /**
     * Render sidebar actions metabox
     */
    public function renderActionsMetabox(\WP_Post $post): void
    {
        $voucher = $this->voucherService->getVoucher($post->ID);

        if (!$voucher) {
            echo '<p class="description">' . esc_html__('Sla eerst op om info te zien.', 'stride') . '</p>';
            return;
        }

        $statusLabels = [
            VoucherService::STATUS_ACTIVE => __('Actief', 'stride'),
            VoucherService::STATUS_EXHAUSTED => __('Uitgeput', 'stride'),
            VoucherService::STATUS_EXPIRED => __('Verlopen', 'stride'),
            VoucherService::STATUS_DISABLED => __('Uitgeschakeld', 'stride'),
        ];
        ?>
        <style>
            .stride-voucher-sidebar .meta-list { margin: 0; padding: 0; list-style: none; }
            .stride-voucher-sidebar .meta-list li { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f1; font-size: 12px; }
            .stride-voucher-sidebar .meta-list li:last-child { border-bottom: none; }
            .stride-voucher-sidebar .meta-label { color: #646970; }
            .stride-voucher-sidebar .meta-value { font-weight: 500; }
        </style>

        <div class="stride-voucher-sidebar">
            <ul class="meta-list">
                <li>
                    <span class="meta-label"><?php esc_html_e('Status', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html($statusLabels[$voucher['status']] ?? $voucher['status']); ?></span>
                </li>
                <li>
                    <span class="meta-label"><?php esc_html_e('Type', 'stride'); ?></span>
                    <span class="meta-value"><?php echo $voucher['type'] === VoucherService::TYPE_SINGLE ? esc_html__('Eenmalig', 'stride') : esc_html__('Meervoudig', 'stride'); ?></span>
                </li>
                <li>
                    <span class="meta-label"><?php esc_html_e('Gebruikt', 'stride'); ?></span>
                    <span class="meta-value">
                        <?php
                        if ($voucher['usage_limit'] > 0) {
                            printf('%d / %d', $voucher['used_count'], $voucher['usage_limit']);
                        } else {
                            printf('%d', $voucher['used_count']);
                        }
                        ?>
                    </span>
                </li>
                <?php if ($voucher['valid_until']): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Geldig tot', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y', strtotime($voucher['valid_until']))); ?></span>
                </li>
                <?php endif; ?>
                <?php if ($voucher['batch_id']): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Batch', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html($voucher['batch_id']); ?></span>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Save voucher meta on post save
     */
    public function saveVoucherMeta(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['stride_voucher_nonce']) ||
            !wp_verify_nonce($_POST['stride_voucher_nonce'], 'stride_save_voucher')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $model = $this->getModel();
        if (!$model) {
            return;
        }

        $fields = $_POST['ntdst_fields'] ?? [];
        if (empty($fields)) {
            return;
        }

        $updateData = [];

        if (isset($fields['code'])) {
            $updateData[VoucherService::FIELD_CODE] = strtoupper(sanitize_text_field($fields['code']));
        }
        if (isset($fields['type'])) {
            $updateData[VoucherService::FIELD_TYPE] = sanitize_text_field($fields['type']);
        }
        if (isset($fields['status'])) {
            $updateData[VoucherService::FIELD_STATUS] = sanitize_text_field($fields['status']);
        }
        if (isset($fields['usage_limit'])) {
            $updateData[VoucherService::FIELD_USAGE_LIMIT] = absint($fields['usage_limit']);
        }
        if (isset($fields['used_count'])) {
            $updateData[VoucherService::FIELD_USED_COUNT] = absint($fields['used_count']);
        }
        if (isset($fields['discount_type'])) {
            $updateData[VoucherService::FIELD_DISCOUNT_TYPE] = sanitize_text_field($fields['discount_type']);
        }
        if (isset($fields['discount_value'])) {
            $updateData[VoucherService::FIELD_DISCOUNT_VALUE] = (float) $fields['discount_value'];
        }
        if (isset($fields['valid_from'])) {
            $updateData[VoucherService::FIELD_VALID_FROM] = sanitize_text_field($fields['valid_from']);
        }
        if (isset($fields['valid_until'])) {
            $updateData[VoucherService::FIELD_VALID_UNTIL] = sanitize_text_field($fields['valid_until']);
        }
        if (isset($fields['item_type'])) {
            $updateData[VoucherService::FIELD_ITEM_TYPE] = sanitize_text_field($fields['item_type']);
        }
        if (isset($fields['item_id'])) {
            $updateData[VoucherService::FIELD_ITEM_ID] = absint($fields['item_id']);
        }
        if (isset($fields['course_id'])) {
            $updateData[VoucherService::FIELD_COURSE_ID] = absint($fields['course_id']);
        }
        if (isset($fields['batch_id'])) {
            $updateData[VoucherService::FIELD_BATCH_ID] = sanitize_text_field($fields['batch_id']);
        }
        if (isset($fields['created_by'])) {
            $updateData[VoucherService::FIELD_CREATED_BY] = absint($fields['created_by']);
        }

        if (!empty($updateData)) {
            $model->update($postId, $updateData);

            if (isset($updateData[VoucherService::FIELD_CODE])) {
                remove_action('save_post_' . VoucherService::POST_TYPE, [$this, 'saveVoucherMeta'], 10);
                wp_update_post([
                    'ID' => $postId,
                    'post_title' => $updateData[VoucherService::FIELD_CODE],
                ]);
                add_action('save_post_' . VoucherService::POST_TYPE, [$this, 'saveVoucherMeta'], 10, 2);
            }
        }
    }

    /**
     * Get the Data Model for vouchers
     */
    private function getModel(): ?\NTDST_Data_Model
    {
        if (!function_exists('ntdst_data')) {
            return null;
        }
        return ntdst_data()->get(VoucherService::POST_TYPE);
    }
}
