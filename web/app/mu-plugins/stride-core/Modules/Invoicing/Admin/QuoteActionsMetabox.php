<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Modules\Invoicing\QuoteService;
use WP_Post;

/**
 * Quote Actions Metabox - Sidebar.
 *
 * Renders status, send actions, voucher management, and controls.
 */
final class QuoteActionsMetabox
{
    public function __construct(
        private readonly QuoteService $quoteService,
    ) {}

    public function render(WP_Post $post): void
    {
        $quote = $this->quoteService->getQuote($post->ID);

        // For new quotes, show simple save prompt
        if (is_wp_error($quote) || empty($quote['quote_number'])) {
            ?>
            <div style="text-align: center; padding: 20px 0; color: #646970;">
                <span class="dashicons dashicons-edit" style="font-size: 32px; width: 32px; height: 32px;"></span>
                <p><?php esc_html_e('Selecteer een gebruiker en editie, sla dan op.', 'stride'); ?></p>
            </div>
            <?php
            return;
        }

        $status = $quote['status'] ?? 'draft';
        $statusEnum = QuoteStatus::tryFrom($status) ?? QuoteStatus::Draft;
        $isLocked = (bool) ($quote['locked'] ?? false);
        $isEditable = !$isLocked;
        $userId = (int) ($quote['user_id'] ?? 0);
        $user = $userId ? get_userdata($userId) : null;

        // Handle billing - may be JSON string or array
        $billing = $quote['billing'] ?? [];
        if (is_string($billing)) {
            $billing = json_decode($billing, true) ?: [];
        }

        $defaultEmail = $billing['email'] ?? ($user ? $user->user_email : '');
        $total = Money::cents((int) ($quote['total'] ?? 0));
        $discount = Money::cents((int) ($quote['discount'] ?? 0));

        $statusConfig = $this->getStatusConfig($statusEnum);

        // Dynamic status styles
        ?>
        <style>
            .stride-sidebar-status {
                background: <?php echo esc_attr($statusConfig['bg']); ?>;
                border-bottom: 2px solid <?php echo esc_attr($statusConfig['color']); ?>;
            }
            .stride-sidebar-status .dashicons,
            .stride-sidebar-status .status-label {
                color: <?php echo esc_attr($statusConfig['color']); ?>;
            }
        </style>

        <!-- Status Header -->
        <div class="stride-sidebar-status">
            <span class="dashicons dashicons-<?php echo esc_attr($statusConfig['icon']); ?>"></span>
            <span class="status-label"><?php echo esc_html($statusConfig['label']); ?></span>
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
            <span class="amount"><?php echo esc_html($total->format()); ?></span>
        </div>

        <!-- Meta Info -->
        <?php $this->renderMetaInfo($quote, $isEditable); ?>

        <!-- View Actions -->
        <?php $this->renderViewActions($post, $quote); ?>

        <!-- Send Quote -->
        <?php $this->renderSendSection($defaultEmail); ?>

        <!-- Voucher/Discount Section -->
        <?php $this->renderVoucherSection($quote, $discount, $isEditable); ?>

        <!-- Status Change -->
        <?php $this->renderStatusSection($statusEnum, $isLocked); ?>
        <?php
    }

    private function getStatusConfig(QuoteStatus $status): array
    {
        return match ($status) {
            QuoteStatus::Draft => [
                'label' => __('Concept', 'stride'),
                'color' => '#dba617',
                'bg' => '#fcf9e8',
                'icon' => 'edit',
            ],
            QuoteStatus::Sent => [
                'label' => __('Verzonden', 'stride'),
                'color' => '#0073aa',
                'bg' => '#e5f5fa',
                'icon' => 'email',
            ],
            QuoteStatus::Exported => [
                'label' => __('Geexporteerd', 'stride'),
                'color' => '#46b450',
                'bg' => '#ecf7ed',
                'icon' => 'yes-alt',
            ],
            QuoteStatus::Cancelled => [
                'label' => __('Geannuleerd', 'stride'),
                'color' => '#d63638',
                'bg' => '#fcf0f1',
                'icon' => 'dismiss',
            ],
        };
    }

    private function renderMetaInfo(array $quote, bool $isEditable): void
    {
        ?>
        <ul class="stride-sidebar-meta">
            <li>
                <span class="meta-label"><?php esc_html_e('Aangemaakt', 'stride'); ?></span>
                <span class="meta-value"><?php echo esc_html(!empty($quote['post_date']) ? date_i18n('d M Y', strtotime($quote['post_date'])) : '-'); ?></span>
            </li>
            <li>
                <span class="meta-label"><?php esc_html_e('Geldig tot', 'stride'); ?></span>
                <?php if ($isEditable): ?>
                    <input type="date" name="ntdst_fields[valid_until]" class="stride-date-input"
                           value="<?php echo esc_attr(!empty($quote['valid_until']) ? date('Y-m-d', strtotime($quote['valid_until'])) : ''); ?>"
                           style="width: 100%; margin-top: 4px; padding: 4px 6px; font-size: 12px; border: 1px solid #8c8f94; border-radius: 3px;">
                <?php else: ?>
                    <span class="meta-value"><?php echo esc_html(!empty($quote['valid_until']) ? date_i18n('d M Y', strtotime($quote['valid_until'])) : '-'); ?></span>
                <?php endif; ?>
            </li>
            <?php if (!empty($quote['sent_at'])): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Verzonden', 'stride'); ?></span>
                    <span class="meta-value"><?php echo esc_html(date_i18n('d M Y H:i', strtotime($quote['sent_at']))); ?></span>
                </li>
            <?php endif; ?>
            <?php if (!empty($quote['last_sent_to'])): ?>
                <li>
                    <span class="meta-label"><?php esc_html_e('Verzonden naar', 'stride'); ?></span>
                    <span class="meta-value" style="word-break: break-all; font-size: 11px;"><?php echo esc_html($quote['last_sent_to']); ?></span>
                </li>
            <?php endif; ?>
        </ul>
        <?php
    }

    private function renderViewActions(WP_Post $post, array $quote): void
    {
        // Removed: PDF view + Formulier buttons — PDF is accessed via regenerate button
    }

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

    private function renderVoucherSection(array $quote, Money $discount, bool $isEditable): void
    {
        $currentVoucher = $quote['voucher_code'] ?? '';
        $hasDiscount = $discount->inCents() > 0;
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Korting', 'stride'); ?></h4>

            <?php if ($currentVoucher): ?>
                <div class="stride-voucher-applied">
                    <div class="voucher-info">
                        <span class="dashicons dashicons-yes"></span>
                        <code><?php echo esc_html($currentVoucher); ?></code>
                    </div>
                    <span class="voucher-amount">-<?php echo esc_html($discount->format()); ?></span>
                    <?php if ($isEditable): ?>
                        <button type="button" class="button-link stride-remove-voucher" id="stride-remove-voucher" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                            <span class="dashicons dashicons-no"></span>
                        </button>
                    <?php endif; ?>
                </div>
            <?php elseif ($hasDiscount): ?>
                <div class="stride-voucher-applied">
                    <div class="voucher-info">
                        <span class="dashicons dashicons-yes"></span>
                        <span><?php esc_html_e('Handmatige korting', 'stride'); ?></span>
                    </div>
                    <span class="voucher-amount">-<?php echo esc_html($discount->format()); ?></span>
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

    private function renderStatusSection(QuoteStatus $status, bool $isLocked): void
    {
        $isCancelled = $status === QuoteStatus::Cancelled;
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Status', 'stride'); ?></h4>
            <select name="stride_change_status" id="stride_change_status" class="stride-status-select" <?php echo $isCancelled ? 'disabled' : ''; ?>>
                <option value="draft" <?php echo $status === QuoteStatus::Draft ? 'selected' : ''; ?>>
                    <?php esc_html_e('Concept', 'stride'); ?>
                </option>
                <option value="sent" <?php echo $status === QuoteStatus::Sent ? 'selected' : ''; ?>>
                    <?php esc_html_e('Verzonden', 'stride'); ?>
                </option>
                <option value="exported" <?php echo $status === QuoteStatus::Exported ? 'selected' : ''; ?>>
                    <?php esc_html_e('Geëxporteerd', 'stride'); ?>
                </option>
                <option value="cancelled" <?php echo $isCancelled ? 'selected' : ''; ?>>
                    <?php esc_html_e('Geannuleerd', 'stride'); ?>
                </option>
            </select>

            <!-- Cancel options (shown when cancelled is selected) -->
            <div id="stride-cancel-options" style="display: none; margin-top: 10px; padding: 10px; background: #fcf0f1; border-radius: 4px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                    <input type="checkbox" name="stride_cancel_registration" id="stride_cancel_registration" value="1">
                    <span><?php esc_html_e('Ook inschrijving annuleren en cursustoegang intrekken', 'stride'); ?></span>
                </label>
            </div>

            <script>
            jQuery(function($) {
                var $select = $('#stride_change_status');
                var $options = $('#stride-cancel-options');

                function toggleCancelOptions() {
                    if ($select.val() === 'cancelled') {
                        $options.slideDown(200);
                    } else {
                        $options.slideUp(200);
                        $('#stride_cancel_registration').prop('checked', false);
                    }
                }

                $select.on('change', toggleCancelOptions);
                toggleCancelOptions();
            });
            </script>

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

                    <?php
                    $pdfPath = $quote['pdf_path'] ?? '';
                    if (!empty($pdfPath)): ?>
                        <a href="<?php echo esc_url(content_url($pdfPath)); ?>" class="button" target="_blank" title="<?php esc_attr_e('PDF bekijken', 'stride'); ?>">
                            <span class="dashicons dashicons-pdf"></span>
                            <?php esc_html_e('PDF', 'stride'); ?>
                        </a>
                    <?php endif; ?>
                    <button type="button" class="button" id="stride-regenerate-pdf-btn" title="<?php esc_attr_e('PDF opnieuw genereren', 'stride'); ?>">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Genereer', 'stride'); ?>
                    </button>
                </div>
            </div>

            <input type="hidden" name="stride_lock_action" id="stride_lock_action" value="">
            <input type="hidden" name="stride_regenerate_pdf" id="stride_regenerate_pdf" value="">
        </div>
        <?php
    }
}
