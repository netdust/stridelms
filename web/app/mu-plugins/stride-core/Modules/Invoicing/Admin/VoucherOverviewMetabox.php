<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

use Stride\Domain\DiscountType;
use Stride\Domain\VoucherStatus;
use Stride\Modules\Invoicing\VoucherRepository;
use Stride\Modules\Invoicing\VoucherService;
use Stride\Modules\Edition\EditionCPT;
use WP_Post;

/**
 * Voucher Overview Metabox.
 *
 * Renders the main voucher form.
 */
final class VoucherOverviewMetabox
{
    public function __construct(
        private readonly VoucherService $voucherService,
        private readonly VoucherRepository $repository,
    ) {}

    public function render(WP_Post $post): void
    {
        $voucher = $this->voucherService->getVoucher($post->ID);
        $isNew = !$voucher || empty($voucher['code']);

        // Get values
        $code = $voucher['code'] ?? '';
        $discountType = $voucher['discount_type'] ?? DiscountType::Full->value;
        $discountValue = $voucher['discount_value'] ?? 0;
        $usageLimit = $voucher['usage_limit'] ?? 1;
        $usedCount = $voucher['used_count'] ?? 0;
        $editionId = $voucher['edition_id'] ?? 0;
        $validFrom = $voucher['valid_from'] ?? '';
        $validUntil = $voucher['valid_until'] ?? '';

        // Convert fixed discount from cents to euros for display
        $discountValueDisplay = $discountValue;
        if ($discountType === 'fixed' && $discountValue > 0) {
            $discountValueDisplay = $discountValue / 100;
        }

        // Nonce field
        wp_nonce_field(VoucherAdminController::NONCE_SAVE, VoucherAdminController::NONCE_FIELD);
        ?>
        <div class="stride-voucher-admin">
            <!-- Code Section -->
            <div class="stride-voucher-section">
                <h4><?php esc_html_e('Vouchercode', 'stride'); ?></h4>

                <div class="stride-field-row">
                    <div class="stride-field stride-code-field">
                        <label for="voucher_code"><?php esc_html_e('Code', 'stride'); ?></label>
                        <div class="stride-code-input-wrapper">
                            <input type="text"
                                   id="voucher_code"
                                   name="ntdst_fields[code]"
                                   value="<?php echo esc_attr($code); ?>"
                                   class="regular-text"
                                   style="text-transform: uppercase; font-family: monospace; font-size: 16px;"
                                   <?php echo $isNew ? '' : 'readonly'; ?>
                                   required>
                            <?php if ($isNew): ?>
                                <button type="button" class="button" id="stride-generate-code">
                                    <?php esc_html_e('Genereren', 'stride'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isNew): ?>
                            <p class="description"><?php esc_html_e('De code kan niet worden gewijzigd na aanmaken.', 'stride'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Discount Section -->
            <div class="stride-voucher-section">
                <h4><?php esc_html_e('Korting', 'stride'); ?></h4>

                <div class="stride-field-row two-col">
                    <div class="stride-field">
                        <label for="discount_type"><?php esc_html_e('Type', 'stride'); ?></label>
                        <select id="discount_type" name="ntdst_fields[discount_type]" class="regular-text">
                            <?php foreach (DiscountType::cases() as $type): ?>
                                <option value="<?php echo esc_attr($type->value); ?>" <?php selected($discountType, $type->value); ?>>
                                    <?php echo esc_html($type->label()); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="stride-field" id="discount-value-field" style="<?php echo $discountType === 'full' ? 'display:none;' : ''; ?>">
                        <label for="discount_value">
                            <span class="label-fixed" style="<?php echo $discountType !== 'fixed' ? 'display:none;' : ''; ?>"><?php esc_html_e('Bedrag (EUR)', 'stride'); ?></span>
                            <span class="label-percentage" style="<?php echo $discountType !== 'percentage' ? 'display:none;' : ''; ?>"><?php esc_html_e('Percentage (%)', 'stride'); ?></span>
                        </label>
                        <input type="number"
                               id="discount_value"
                               name="ntdst_fields[discount_value]"
                               value="<?php echo esc_attr($discountValueDisplay); ?>"
                               class="small-text"
                               min="0"
                               step="<?php echo $discountType === 'fixed' ? '0.01' : '1'; ?>"
                               max="<?php echo $discountType === 'percentage' ? '100' : ''; ?>">
                    </div>
                </div>
            </div>

            <!-- Usage Section -->
            <div class="stride-voucher-section">
                <h4><?php esc_html_e('Gebruik', 'stride'); ?></h4>

                <div class="stride-field-row two-col">
                    <div class="stride-field">
                        <label for="usage_limit"><?php esc_html_e('Maximaal aantal keer', 'stride'); ?></label>
                        <input type="number"
                               id="usage_limit"
                               name="ntdst_fields[usage_limit]"
                               value="<?php echo esc_attr($usageLimit); ?>"
                               class="small-text"
                               min="0">
                        <p class="description"><?php esc_html_e('0 = onbeperkt', 'stride'); ?></p>
                    </div>

                    <div class="stride-field">
                        <label><?php esc_html_e('Aantal keer gebruikt', 'stride'); ?></label>
                        <div class="stride-usage-display">
                            <span class="stride-usage-count"><?php echo esc_html($usedCount); ?></span>
                            <?php if ($usageLimit > 0): ?>
                                <span class="stride-usage-separator">/</span>
                                <span class="stride-usage-limit"><?php echo esc_html($usageLimit); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Validity Section -->
            <div class="stride-voucher-section">
                <h4><?php esc_html_e('Geldigheid', 'stride'); ?></h4>

                <div class="stride-field-row two-col">
                    <div class="stride-field">
                        <label for="valid_from"><?php esc_html_e('Geldig vanaf', 'stride'); ?></label>
                        <input type="text"
                               id="valid_from"
                               name="ntdst_fields[valid_from]"
                               value="<?php echo esc_attr($validFrom); ?>"
                               class="stride-datepicker regular-text"
                               placeholder="<?php esc_attr_e('Geen beperking', 'stride'); ?>">
                    </div>

                    <div class="stride-field">
                        <label for="valid_until"><?php esc_html_e('Geldig tot', 'stride'); ?></label>
                        <input type="text"
                               id="valid_until"
                               name="ntdst_fields[valid_until]"
                               value="<?php echo esc_attr($validUntil); ?>"
                               class="stride-datepicker regular-text"
                               placeholder="<?php esc_attr_e('Geen beperking', 'stride'); ?>">
                    </div>
                </div>

                <div class="stride-field-row">
                    <div class="stride-field">
                        <label for="edition_id"><?php esc_html_e('Beperkt tot editie', 'stride'); ?></label>
                        <select id="edition_id" name="ntdst_fields[edition_id]" class="regular-text">
                            <option value="0"><?php esc_html_e('Alle edities', 'stride'); ?></option>
                            <?php
                            $editions = get_posts([
                                'post_type' => EditionCPT::POST_TYPE,
                                'post_status' => 'publish',
                                'posts_per_page' => 100,
                                'orderby' => 'title',
                                'order' => 'ASC',
                            ]);
                            foreach ($editions as $edition):
                                ?>
                                <option value="<?php echo esc_attr($edition->ID); ?>" <?php selected($editionId, $edition->ID); ?>>
                                    <?php echo esc_html($edition->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Laat leeg om de voucher voor alle edities te laten gelden.', 'stride'); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
