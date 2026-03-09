<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

use Stride\Domain\Money;
use Stride\Modules\Invoicing\QuoteService;
use WP_Post;

/**
 * Quote Overview Metabox - Main content area.
 *
 * Renders billing form, quote details, and line items table.
 */
final class QuoteOverviewMetabox
{
    public function __construct(
        private readonly QuoteService $quoteService,
    ) {}

    public function render(WP_Post $post): void
    {
        // New posts (auto-draft) don't have a quote number yet
        if ($post->post_status === 'auto-draft') {
            $this->renderNewQuoteForm($post);
            return;
        }

        $quote = $this->quoteService->getQuote($post->ID);

        // Handle failed retrieval or missing quote number
        if (is_wp_error($quote) || empty($quote['quote_number'])) {
            $this->renderNewQuoteForm($post);
            return;
        }

        $this->renderExistingQuote($post, $quote);
    }

    private function renderExistingQuote(WP_Post $post, array $quote): void
    {
        $userId = (int) ($quote['user_id'] ?? 0);
        $user = $userId ? (get_userdata($userId) ?: null) : null;

        // Handle billing - may be JSON string or array
        $billing = $quote['billing'] ?? [];
        if (is_string($billing)) {
            $billing = json_decode($billing, true) ?: [];
        }

        // Handle items - may be JSON string or array
        $items = $quote['items'] ?? [];
        if (is_string($items)) {
            $items = json_decode($items, true) ?: [];
        }

        $isLocked = (bool) ($quote['locked'] ?? false);
        $isEditable = !$isLocked;

        // Ensure at least one item row
        if (empty($items)) {
            $items = [[
                'id' => 0,
                'type' => 'edition',
                'title' => '',
                'quantity' => 1,
                'unit_price' => 0,
                'total' => 0,
            ]];
        }

        // Security nonce
        wp_nonce_field('stride_save_quote', 'stride_quote_nonce');
        ?>
        <div class="stride-quote-admin">
            <?php $this->renderHeader($quote); ?>
            <?php $this->renderColumns($quote, $user, $billing, $isEditable); ?>
            <?php $this->renderItemsTable($items, $quote, $isEditable); ?>
            <?php $this->renderHiddenFields($quote); ?>
        </div>
        <?php
    }

    private function renderHeader(array $quote): void
    {
        ?>
        <div class="stride-quote-header">
            <div class="stride-quote-number-display">
                <span class="label"><?php esc_html_e('Offerte', 'stride'); ?></span>
                <span class="number"><?php echo esc_html($quote['quote_number'] ?? ''); ?></span>
            </div>
            <div class="stride-quote-dates">
                <?php if (!empty($quote['post_date'])): ?>
                    <span><?php esc_html_e('Aangemaakt:', 'stride'); ?> <?php echo esc_html(date_i18n('j M Y', strtotime($quote['post_date']))); ?></span>
                <?php endif; ?>
                <?php if (!empty($quote['valid_until'])): ?>
                    <span><?php esc_html_e('Geldig tot:', 'stride'); ?> <?php echo esc_html(date_i18n('j M Y', strtotime($quote['valid_until']))); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function renderColumns(array $quote, ?\WP_User $user, array $billing, bool $isEditable): void
    {
        $userId = (int) ($quote['user_id'] ?? 0);
        ?>
        <div class="stride-quote-columns">
            <div class="stride-quote-billing">
                <h4><?php esc_html_e('Facturatiegegevens', 'stride'); ?></h4>

                <div class="stride-field-row">
                    <div class="stride-field stride-user-field">
                        <label for="quote_user_id"><?php esc_html_e('Klant', 'stride'); ?></label>
                        <?php if ($isEditable): ?>
                            <select id="quote_user_id" name="ntdst_fields[user_id]" class="stride-user-select">
                                <option value=""><?php esc_html_e('Selecteer klant...', 'stride'); ?></option>
                                <?php
                                $users = get_users(['orderby' => 'display_name', 'order' => 'ASC', 'number' => 200]);
                                foreach ($users as $u) {
                                    $selected = ($u->ID === $userId) ? 'selected' : '';
                                    $label = $u->display_name . ($u->user_email ? " ({$u->user_email})" : '');
                                    printf(
                                        '<option value="%d" %s data-email="%s">%s</option>',
                                        $u->ID,
                                        $selected,
                                        esc_attr($u->user_email),
                                        esc_html($label)
                                    );
                                }
                                ?>
                            </select>
                            <?php if ($user): ?>
                                <a href="<?php echo esc_url(get_edit_user_link($userId)); ?>" class="stride-user-link" target="_blank">
                                    <span class="dashicons dashicons-external"></span>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php if ($user): ?>
                                <div class="stride-user-display">
                                    <a href="<?php echo esc_url(get_edit_user_link($userId)); ?>">
                                        <?php echo esc_html($user->display_name); ?>
                                    </a>
                                    <span class="email">(<?php echo esc_html($user->user_email); ?>)</span>
                                </div>
                            <?php else: ?>
                                <span class="no-user"><?php esc_html_e('Geen gebruiker', 'stride'); ?></span>
                            <?php endif; ?>
                            <input type="hidden" name="ntdst_fields[user_id]" value="<?php echo esc_attr($userId); ?>">
                        <?php endif; ?>
                    </div>
                </div>

                <?php $this->renderBillingFields($billing, $user, $isEditable); ?>
            </div>

            <div class="stride-quote-details">
                <h4><?php esc_html_e('Offerte details', 'stride'); ?></h4>
                <div class="stride-field">
                    <label for="quote_order_number"><?php esc_html_e('Bestelnummer (PO)', 'stride'); ?></label>
                    <input type="text" id="quote_order_number" name="ntdst_fields[order_number]"
                           value="<?php echo esc_attr($quote['order_number'] ?? ''); ?>"
                           placeholder="<?php esc_attr_e('Optioneel', 'stride'); ?>"
                           <?php echo !$isEditable ? 'readonly' : ''; ?>>
                </div>
            </div>
        </div>
        <?php
    }

    private function renderBillingFields(array $billing, ?\WP_User $user, bool $isEditable): void
    {
        $readonly = !$isEditable ? 'readonly' : '';
        $defaultEmail = $billing['email'] ?? ($user ? $user->user_email : '');
        ?>
        <div class="stride-field-row two-col">
            <div class="stride-field">
                <label for="billing_company"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                <input type="text" id="billing_company" name="billing[company]"
                       value="<?php echo esc_attr($billing['company'] ?? ''); ?>" <?php echo $readonly; ?>>
            </div>
            <div class="stride-field">
                <label for="billing_email"><?php esc_html_e('Email', 'stride'); ?></label>
                <input type="email" id="billing_email" name="billing[email]"
                       value="<?php echo esc_attr($defaultEmail); ?>" <?php echo $readonly; ?>>
            </div>
        </div>

        <div class="stride-field-row">
            <div class="stride-field">
                <label for="billing_address"><?php esc_html_e('Adres', 'stride'); ?></label>
                <input type="text" id="billing_address" name="billing[address]"
                       value="<?php echo esc_attr($billing['address'] ?? ''); ?>" <?php echo $readonly; ?>>
            </div>
        </div>

        <div class="stride-field-row two-col">
            <div class="stride-field">
                <label for="billing_postal_code"><?php esc_html_e('Postcode', 'stride'); ?></label>
                <input type="text" id="billing_postal_code" name="billing[postal_code]"
                       value="<?php echo esc_attr($billing['postal_code'] ?? ''); ?>" <?php echo $readonly; ?>>
            </div>
            <div class="stride-field">
                <label for="billing_city"><?php esc_html_e('Stad', 'stride'); ?></label>
                <input type="text" id="billing_city" name="billing[city]"
                       value="<?php echo esc_attr($billing['city'] ?? ''); ?>" <?php echo $readonly; ?>>
            </div>
        </div>

        <div class="stride-field-row two-col">
            <div class="stride-field">
                <label for="billing_vat_number"><?php esc_html_e('BTW Nummer', 'stride'); ?></label>
                <input type="text" id="billing_vat_number" name="billing[vat_number]"
                       value="<?php echo esc_attr($billing['vat_number'] ?? ''); ?>"
                       placeholder="BE0123456789" <?php echo $readonly; ?>>
            </div>
            <div class="stride-field">
                <label for="billing_gln_number"><?php esc_html_e('GLN Nummer', 'stride'); ?></label>
                <input type="text" id="billing_gln_number" name="billing[gln_number]"
                       value="<?php echo esc_attr($billing['gln_number'] ?? ''); ?>" <?php echo $readonly; ?>>
            </div>
        </div>
        <?php
    }

    private function renderItemsTable(array $items, array $quote, bool $isEditable): void
    {
        $subtotal = Money::cents((int) ($quote['subtotal'] ?? 0));
        $discount = Money::cents((int) ($quote['discount'] ?? 0));
        $tax = Money::cents((int) ($quote['tax'] ?? 0));
        $total = Money::cents((int) ($quote['total'] ?? 0));
        ?>
        <div class="stride-quote-items-section">
            <h4><?php esc_html_e('Offerte items', 'stride'); ?></h4>

            <table class="stride-quote-items widefat">
                <thead>
                    <tr>
                        <th class="description"><?php esc_html_e('Omschrijving', 'stride'); ?></th>
                        <th class="qty"><?php esc_html_e('Aantal', 'stride'); ?></th>
                        <th class="price"><?php esc_html_e('Prijs', 'stride'); ?></th>
                        <th class="total"><?php esc_html_e('Bedrag', 'stride'); ?></th>
                        <?php if ($isEditable): ?><th class="actions"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="stride-quote-items-body">
                    <?php foreach ($items as $index => $item): ?>
                        <?php $this->renderItemRow($index, $item, $isEditable); ?>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="subtotal">
                        <td colspan="3"><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                        <td class="amount"><?php echo esc_html($subtotal->format()); ?></td>
                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                    </tr>
                    <?php if ($discount->inCents() > 0): ?>
                        <tr class="discount">
                            <td colspan="3"><?php esc_html_e('Korting', 'stride'); ?></td>
                            <td class="amount">- <?php echo esc_html($discount->format()); ?></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                    <?php endif; ?>
                    <tr class="tax">
                        <td colspan="3"><?php esc_html_e('BTW 21%', 'stride'); ?></td>
                        <td class="amount"><?php echo esc_html($tax->format()); ?></td>
                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                    </tr>
                    <tr class="grand-total">
                        <td colspan="3"><?php esc_html_e('Totaal', 'stride'); ?></td>
                        <td class="amount"><?php echo esc_html($total->format()); ?></td>
                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                    </tr>
                </tfoot>
            </table>

            <?php if ($isEditable): ?>
                <div class="stride-quote-actions">
                    <button type="button" class="button" id="stride-add-item">
                        <span class="dashicons dashicons-plus-alt2"></span>
                        <?php esc_html_e('Item toevoegen', 'stride'); ?>
                    </button>
                    <button type="button" class="button" id="stride-recalculate">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Herbereken totalen', 'stride'); ?>
                    </button>
                </div>
            <?php else: ?>
                <p class="stride-readonly-notice">
                    <span class="dashicons dashicons-lock"></span>
                    <?php esc_html_e('Deze offerte is vergrendeld. Ontgrendel via de zijbalk om te bewerken.', 'stride'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function renderItemRow(int $index, array $item, bool $isEditable): void
    {
        $isDiscount = ($item['type'] ?? '') === 'discount';
        $unitPrice = Money::cents((int) ($item['unit_price'] ?? 0));
        $itemTotal = Money::cents((int) ($item['total'] ?? 0));
        ?>
        <tr class="item-row <?php echo $isDiscount ? 'discount-row' : ''; ?>" data-index="<?php echo esc_attr($index); ?>">
            <td class="description">
                <?php if ($isEditable): ?>
                    <input type="text" name="items[<?php echo $index; ?>][title]"
                           value="<?php echo esc_attr($item['title'] ?? ''); ?>" class="item-title">
                    <input type="hidden" name="items[<?php echo $index; ?>][type]"
                           value="<?php echo esc_attr($item['type'] ?? 'edition'); ?>">
                    <input type="hidden" name="items[<?php echo $index; ?>][id]"
                           value="<?php echo esc_attr($item['id'] ?? 0); ?>">
                <?php else: ?>
                    <?php echo esc_html($item['title'] ?? '-'); ?>
                <?php endif; ?>
            </td>
            <td class="qty">
                <?php if ($isEditable): ?>
                    <input type="number" name="items[<?php echo $index; ?>][quantity]"
                           value="<?php echo esc_attr($item['quantity'] ?? 1); ?>"
                           min="1" step="1" class="item-qty">
                <?php else: ?>
                    <?php echo esc_html($item['quantity'] ?? 1); ?>
                <?php endif; ?>
            </td>
            <td class="price">
                <?php if ($isEditable): ?>
                    <input type="number" name="items[<?php echo $index; ?>][unit_price]"
                           value="<?php echo esc_attr(($item['unit_price'] ?? 0) / 100); ?>"
                           min="0" step="0.01" class="item-price">
                <?php else: ?>
                    <?php echo esc_html($unitPrice->format()); ?>
                <?php endif; ?>
            </td>
            <td class="total"><?php echo esc_html($itemTotal->format()); ?></td>
            <?php if ($isEditable): ?>
                <td class="actions">
                    <button type="button" class="button-link stride-remove-item" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                        <span class="dashicons dashicons-trash"></span>
                    </button>
                </td>
            <?php endif; ?>
        </tr>
        <?php
    }

    private function renderHiddenFields(array $quote): void
    {
        ?>
        <input type="hidden" name="ntdst_fields[status]" value="<?php echo esc_attr($quote['status'] ?? 'draft'); ?>">
        <input type="hidden" name="ntdst_fields[quote_number]" value="<?php echo esc_attr($quote['quote_number'] ?? ''); ?>">
        <input type="hidden" name="ntdst_fields[subtotal]" id="quote_subtotal" value="<?php echo esc_attr($quote['subtotal'] ?? 0); ?>">
        <input type="hidden" name="ntdst_fields[tax]" id="quote_tax" value="<?php echo esc_attr($quote['tax'] ?? 0); ?>">
        <input type="hidden" name="ntdst_fields[total]" id="quote_total" value="<?php echo esc_attr($quote['total'] ?? 0); ?>">
        <input type="hidden" name="ntdst_fields[discount]" id="quote_discount" value="<?php echo esc_attr($quote['discount'] ?? 0); ?>">
        <input type="hidden" name="ntdst_fields[voucher_code]" value="<?php echo esc_attr($quote['voucher_code'] ?? ''); ?>">
        <?php
    }

    private function renderNewQuoteForm(WP_Post $post): void
    {
        wp_nonce_field('stride_save_quote', 'stride_quote_nonce');
        ?>
        <div class="stride-new-quote-form">
            <div class="stride-new-quote-info">
                <p><strong><?php esc_html_e('Nieuwe offerte', 'stride'); ?></strong></p>
                <p><?php esc_html_e('Selecteer een gebruiker en editie om een offerte aan te maken.', 'stride'); ?></p>
            </div>

            <div class="form-field">
                <label for="quote_user_id"><?php esc_html_e('Gebruiker', 'stride'); ?></label>
                <?php
                wp_dropdown_users([
                    'name' => 'ntdst_fields[user_id]',
                    'id' => 'quote_user_id',
                    'show_option_none' => __('Selecteer gebruiker...', 'stride'),
                    'option_none_value' => '',
                ]);
                ?>
            </div>

            <div class="form-field">
                <label for="quote_edition_id"><?php esc_html_e('Editie', 'stride'); ?></label>
                <select name="ntdst_fields[edition_id]" id="quote_edition_id">
                    <option value=""><?php esc_html_e('Selecteer editie...', 'stride'); ?></option>
                    <?php
                    $editions = get_posts([
                        'post_type' => 'vad_edition',
                        'posts_per_page' => 200,
                        'orderby' => 'meta_value',
                        'meta_key' => 'start_date',
                        'order' => 'DESC',
                        'post_status' => 'publish',
                    ]);
                    foreach ($editions as $edition) {
                        printf(
                            '<option value="%d">%s</option>',
                            $edition->ID,
                            esc_html($edition->post_title)
                        );
                    }
                    ?>
                </select>
            </div>

            <input type="hidden" name="ntdst_fields[status]" value="draft">

            <p class="description">
                <?php esc_html_e('Na opslaan worden de offerte details automatisch berekend.', 'stride'); ?>
            </p>
        </div>
        <?php
    }
}
