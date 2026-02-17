<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

use Stride\Domain\VoucherStatus;
use Stride\Modules\Invoicing\VoucherService;
use WP_Post;

/**
 * Voucher Actions Metabox.
 *
 * Renders the sidebar with status and actions.
 */
final class VoucherActionsMetabox
{
    public function __construct(
        private readonly VoucherService $voucherService,
    ) {}

    public function render(WP_Post $post): void
    {
        $voucher = $this->voucherService->getVoucher($post->ID);
        $isNew = !$voucher || empty($voucher['code']);

        $status = VoucherStatus::tryFrom($voucher['status'] ?? '') ?? VoucherStatus::Active;
        $createdBy = $voucher['created_by'] ?? 0;
        $createdByUser = $createdBy ? get_userdata($createdBy) : null;
        ?>
        <div class="stride-voucher-actions">
            <!-- Current Status -->
            <div class="stride-status-section">
                <label><?php esc_html_e('Status', 'stride'); ?></label>
                <div class="stride-status-badge stride-status-<?php echo esc_attr($status->value); ?>">
                    <?php echo esc_html($status->label()); ?>
                </div>
            </div>

            <?php if (!$isNew): ?>
                <!-- Status Change -->
                <div class="stride-status-change">
                    <label for="stride_change_status"><?php esc_html_e('Status wijzigen', 'stride'); ?></label>
                    <select name="stride_change_status" id="stride_change_status" class="widefat">
                        <option value=""><?php esc_html_e('— Niet wijzigen —', 'stride'); ?></option>
                        <?php foreach (VoucherStatus::cases() as $statusOption): ?>
                            <?php if ($statusOption !== $status): ?>
                                <option value="<?php echo esc_attr($statusOption->value); ?>">
                                    <?php echo esc_html($statusOption->label()); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Info -->
                <div class="stride-voucher-info">
                    <?php if ($createdByUser): ?>
                        <div class="info-row">
                            <span class="label"><?php esc_html_e('Aangemaakt door', 'stride'); ?></span>
                            <span class="value"><?php echo esc_html($createdByUser->display_name ?: $createdByUser->user_login); ?></span>
                        </div>
                    <?php endif; ?>

                    <div class="info-row">
                        <span class="label"><?php esc_html_e('Aangemaakt op', 'stride'); ?></span>
                        <span class="value"><?php echo esc_html(get_the_date('d M Y', $post)); ?></span>
                    </div>

                    <div class="info-row">
                        <span class="label"><?php esc_html_e('Laatst gewijzigd', 'stride'); ?></span>
                        <span class="value"><?php echo esc_html(get_the_modified_date('d M Y H:i', $post)); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .stride-voucher-actions {
                padding: 0;
            }

            .stride-status-section {
                margin-bottom: 15px;
            }

            .stride-status-section label {
                display: block;
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #1d2327;
                margin-bottom: 8px;
            }

            .stride-status-badge {
                display: inline-block;
                padding: 6px 12px;
                border-radius: 4px;
                font-weight: 600;
                font-size: 12px;
            }

            .stride-status-active {
                background: #d4edda;
                color: #155724;
            }

            .stride-status-exhausted {
                background: #fff3cd;
                color: #856404;
            }

            .stride-status-expired {
                background: #f8d7da;
                color: #721c24;
            }

            .stride-status-disabled {
                background: #e2e3e5;
                color: #383d41;
            }

            .stride-status-change {
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 1px solid #ddd;
            }

            .stride-status-change label {
                display: block;
                font-weight: 600;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: #1d2327;
                margin-bottom: 5px;
            }

            .stride-voucher-info {
                font-size: 12px;
            }

            .stride-voucher-info .info-row {
                display: flex;
                justify-content: space-between;
                padding: 6px 0;
                border-bottom: 1px solid #f0f0f0;
            }

            .stride-voucher-info .info-row:last-child {
                border-bottom: none;
            }

            .stride-voucher-info .label {
                color: #666;
            }

            .stride-voucher-info .value {
                font-weight: 500;
                color: #1d2327;
            }
        </style>
        <?php
    }
}
