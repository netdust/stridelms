<?php

namespace ntdst\Stride\invoicing\Admin;

defined('ABSPATH') || exit;

use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\invoicing\Support\CurrencyFormatter;

/**
 * Quote Actions Metabox
 *
 * Renders the sidebar with status, actions, and quick operations.
 *
 * @package stride\services\invoicing\Admin
 */
class QuoteActionsMetabox
{
    private QuoteService $quoteService;

    /**
     * Constructor
     */
    public function __construct(QuoteService $quoteService)
    {
        $this->quoteService = $quoteService;
    }

    /**
     * Render the metabox
     */
    public function render(\WP_Post $post): void
    {
        $quote = $this->quoteService->getQuote($post->ID);

        // For new quotes, show simple save prompt
        if (!$quote) {
            ?>
            <div style="text-align: center; padding: 20px 0; color: #646970;">
                <span class="dashicons dashicons-edit" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <p><?php esc_html_e('Selecteer een gebruiker en cursus, sla dan op.', 'stride'); ?></p>
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

        $statusConfig = $this->getStatusConfig();
        $config = $statusConfig[$status] ?? $statusConfig[QuoteService::STATUS_DRAFT];

        // Dynamic status styles
        ?>
        <style>
            .stride-sidebar-status {
                background: <?php echo esc_attr($config['bg']); ?>;
                border-bottom: 2px solid <?php echo esc_attr($config['color']); ?>;
            }
            .stride-sidebar-status .dashicons,
            .stride-sidebar-status .status-label {
                color: <?php echo esc_attr($config['color']); ?>;
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
            <span class="amount"><?php echo CurrencyFormatter::format($quote['total']); ?></span>
        </div>

        <!-- Meta Info -->
        <?php $this->renderMetaInfo($quote, $isEditable, $lastSentTo); ?>

        <!-- View Actions -->
        <?php $this->renderViewActions($post, $quote); ?>

        <!-- Send Quote -->
        <?php $this->renderSendSection($defaultEmail); ?>

        <!-- Voucher/Discount Section -->
        <?php $this->renderVoucherSection($quote, $isEditable); ?>

        <!-- Status Change -->
        <?php $this->renderStatusSection($status, $isLocked); ?>
        <?php
    }

    /**
     * Get status configuration
     */
    private function getStatusConfig(): array
    {
        return [
            QuoteService::STATUS_DRAFT => [
                'label' => __('Concept', 'stride'),
                'color' => '#dba617',
                'bg' => '#fcf9e8',
                'icon' => 'edit',
            ],
            QuoteService::STATUS_SENT => [
                'label' => __('Verzonden', 'stride'),
                'color' => '#0073aa',
                'bg' => '#e5f5fa',
                'icon' => 'email',
            ],
            QuoteService::STATUS_EXPORTED => [
                'label' => __('Geëxporteerd', 'stride'),
                'color' => '#46b450',
                'bg' => '#ecf7ed',
                'icon' => 'yes-alt',
            ],
        ];
    }

    /**
     * Render meta info section
     */
    private function renderMetaInfo(array $quote, bool $isEditable, string $lastSentTo): void
    {
        ?>
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
        <?php
    }

    /**
     * Render view actions section
     */
    private function renderViewActions(\WP_Post $post, array $quote): void
    {
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Bekijken', 'stride'); ?></h4>
            <div class="stride-sidebar-actions">
                <div class="stride-action-row">
                    <?php if (!empty($quote['pdf_path'])): ?>
                        <a href="<?php echo esc_url($this->quoteService->getQuoteUrl($post->ID)); ?>" class="button" target="_blank">
                            <span class="dashicons dashicons-pdf"></span>
                            <?php esc_html_e('PDF', 'stride'); ?>
                        </a>
                    <?php else: ?>
                        <button type="button" class="button" disabled title="<?php esc_attr_e('PDF nog niet gegenereerd', 'stride'); ?>">
                            <span class="dashicons dashicons-pdf"></span>
                            <?php esc_html_e('PDF', 'stride'); ?>
                        </button>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($this->quoteService->getQuoteFormUrl($post->ID)); ?>" class="button" target="_blank">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php esc_html_e('Formulier', 'stride'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render send section
     */
    private function renderSendSection(string $defaultEmail): void
    {
        ?>
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
        <?php
    }

    /**
     * Render voucher/discount section
     */
    private function renderVoucherSection(array $quote, bool $isEditable): void
    {
        $currentVoucher = $quote['voucher_code'] ?? '';
        $currentDiscount = $quote['discount'] ?? 0;
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Korting', 'stride'); ?></h4>

            <?php if ($currentVoucher): ?>
                <div class="stride-voucher-applied">
                    <div class="voucher-info">
                        <span class="dashicons dashicons-yes"></span>
                        <code><?php echo esc_html($currentVoucher); ?></code>
                    </div>
                    <span class="voucher-amount">-<?php echo CurrencyFormatter::format($currentDiscount); ?></span>
                    <?php if ($isEditable): ?>
                        <button type="button" class="button-link stride-remove-voucher" id="stride-remove-voucher" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php elseif ($currentDiscount > 0): ?>
                <div class="stride-voucher-applied">
                    <div class="voucher-info">
                        <span class="dashicons dashicons-yes"></span>
                        <span><?php esc_html_e('Handmatige korting', 'stride'); ?></span>
                    </div>
                    <span class="voucher-amount">-<?php echo CurrencyFormatter::format($currentDiscount); ?></span>
                    <?php if ($isEditable): ?>
                        <button type="button" class="button-link stride-remove-voucher" id="stride-remove-voucher" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php elseif ($isEditable): ?>
                <div class="stride-voucher-form">
                    <div class="voucher-input-row">
                        <input type="text" id="stride_voucher_code" placeholder="<?php esc_attr_e('Vouchercode', 'stride'); ?>" style="flex: 1;">
                        <button type="button" class="button" id="stride-apply-voucher">
                            <?php esc_html_e('Toepassen', 'stride'); ?>
                        </button>
                    </div>
                    <div class="discount-divider"><?php esc_html_e('of', 'stride'); ?></div>
                    <div class="discount-input-row">
                        <input type="number" id="stride_manual_discount" placeholder="<?php esc_attr_e('Bedrag', 'stride'); ?>" min="0" step="0.01" style="flex: 1;">
                        <button type="button" class="button" id="stride-apply-discount">
                            <?php esc_html_e('Toepassen', 'stride'); ?>
                        </button>
                    </div>
                </div>
            <?php else: ?>
                <p class="description" style="margin: 0; color: #646970;">
                    <?php esc_html_e('Geen korting toegepast.', 'stride'); ?>
                </p>
            <?php endif; ?>

            <input type="hidden" name="stride_remove_voucher" id="stride_remove_voucher" value="">
            <input type="hidden" name="stride_apply_voucher" id="stride_apply_voucher_action" value="">
            <input type="hidden" name="stride_apply_discount" id="stride_apply_discount_action" value="">
        </div>
        <?php
    }

    /**
     * Render status change section
     */
    private function renderStatusSection(string $status, bool $isLocked): void
    {
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Status', 'stride'); ?></h4>
            <select name="stride_change_status" id="stride_change_status" class="stride-status-select">
                <option value="<?php echo esc_attr(QuoteService::STATUS_DRAFT); ?>" <?php selected($status, QuoteService::STATUS_DRAFT); ?>>
                    <?php esc_html_e('Concept', 'stride'); ?>
                </option>
                <option value="<?php echo esc_attr(QuoteService::STATUS_SENT); ?>" <?php selected($status, QuoteService::STATUS_SENT); ?>>
                    <?php esc_html_e('Verzonden', 'stride'); ?>
                </option>
                <option value="<?php echo esc_attr(QuoteService::STATUS_EXPORTED); ?>" <?php selected($status, QuoteService::STATUS_EXPORTED); ?>>
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
        <?php
    }
}
