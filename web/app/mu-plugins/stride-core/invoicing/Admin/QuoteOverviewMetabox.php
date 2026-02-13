<?php

namespace ntdst\Stride\invoicing\Admin;

defined('ABSPATH') || exit;

use ntdst\Stride\invoicing\QuoteService;
use ntdst\Stride\invoicing\Support\CurrencyFormatter;

/**
 * Quote Overview Metabox
 *
 * Renders the main quote form with billing details and line items.
 *
 * @package stride\services\invoicing\Admin
 */
class QuoteOverviewMetabox
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

        // Handle new/unsaved quotes
        if (!$quote) {
            $this->renderNewQuoteForm($post);
            return;
        }

        $userId = $quote['user_id'];
        $user = get_userdata($userId);
        $billing = $quote['billing'] ?? [];
        $items = $quote['items'] ?? [];
        $courseId = $quote['course_id'];
        $isLocked = (bool) ($quote['locked'] ?? false);
        $isEditable = !$isLocked;

        // Add default empty item row if no items exist
        if (empty($items)) {
            $items = [
                [
                    'id' => 0,
                    'type' => 'course',
                    'title' => '',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'total' => 0,
                ],
            ];
        }

        // Security nonce
        wp_nonce_field('stride_save_quote', 'stride_quote_nonce');
        ?>
        <div class="stride-quote-admin">
            <!-- Header: Quote Number -->
            <div class="stride-quote-header">
                <div class="stride-quote-number-display">
                    <span class="label"><?php esc_html_e('Offerte', 'stride'); ?></span>
                    <span class="number"><?php echo esc_html($quote['number']); ?></span>
                </div>
                <div class="stride-quote-dates">
                    <span><?php esc_html_e('Aangemaakt:', 'stride'); ?> <?php echo esc_html(date_i18n('j M Y', strtotime($quote['created_at']))); ?></span>
                    <span><?php esc_html_e('Geldig tot:', 'stride'); ?> <?php echo esc_html(date_i18n('j M Y', strtotime($quote['valid_until']))); ?></span>
                </div>
            </div>

            <!-- Two Column: Customer Billing | Quote Details -->
            <div class="stride-quote-columns">
                <!-- Left: Billing Details -->
                <div class="stride-quote-billing">
                    <h4><?php esc_html_e('Facturatiegegevens', 'stride'); ?></h4>

                    <div class="stride-field-row">
                        <div class="stride-field stride-user-field">
                            <label for="quote_user_id"><?php esc_html_e('Klant', 'stride'); ?></label>
                            <?php if ($isEditable): ?>
                                <select id="quote_user_id" name="ntdst_fields[user_id]" class="stride-user-select">
                                    <option value=""><?php esc_html_e('Selecteer klant...', 'stride'); ?></option>
                                    <?php
                                    $users = get_users([
                                        'orderby' => 'display_name',
                                        'order' => 'ASC',
                                        'number' => 200,
                                    ]);
                                    foreach ($users as $u) {
                                        $selected = ($u->ID == $userId) ? 'selected' : '';
                                        $label = $u->display_name;
                                        if ($u->user_email) {
                                            $label .= ' (' . $u->user_email . ')';
                                        }
                                        echo '<option value="' . esc_attr($u->ID) . '" ' . $selected . ' data-email="' . esc_attr($u->user_email) . '">' . esc_html($label) . '</option>';
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

                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="billing_organisation"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                            <input type="text" id="billing_organisation" name="billing[organisation]"
                                   value="<?php echo esc_attr($billing['organisation'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                        <div class="stride-field">
                            <label for="billing_email"><?php esc_html_e('Email', 'stride'); ?></label>
                            <input type="email" id="billing_email" name="billing[email]"
                                   value="<?php echo esc_attr($billing['email'] ?? $user->user_email ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                    </div>

                    <div class="stride-field-row">
                        <div class="stride-field">
                            <label for="billing_address"><?php esc_html_e('Adres', 'stride'); ?></label>
                            <input type="text" id="billing_address" name="billing[address]"
                                   value="<?php echo esc_attr($billing['address'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                    </div>

                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="billing_postal_code"><?php esc_html_e('Postcode', 'stride'); ?></label>
                            <input type="text" id="billing_postal_code" name="billing[postal_code]"
                                   value="<?php echo esc_attr($billing['postal_code'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                        <div class="stride-field">
                            <label for="billing_city"><?php esc_html_e('Stad', 'stride'); ?></label>
                            <input type="text" id="billing_city" name="billing[city]"
                                   value="<?php echo esc_attr($billing['city'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                    </div>

                    <div class="stride-field-row two-col">
                        <div class="stride-field">
                            <label for="billing_vat_number"><?php esc_html_e('BTW Nummer', 'stride'); ?></label>
                            <input type="text" id="billing_vat_number" name="billing[vat_number]"
                                   value="<?php echo esc_attr($billing['vat_number'] ?? ''); ?>"
                                   placeholder="BE0123456789"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                        <div class="stride-field">
                            <label for="billing_gln_number"><?php esc_html_e('GLN Nummer', 'stride'); ?></label>
                            <input type="text" id="billing_gln_number" name="billing[gln_number]"
                                   value="<?php echo esc_attr($billing['gln_number'] ?? ''); ?>"
                                   <?php echo !$isEditable ? 'readonly' : ''; ?>>
                        </div>
                    </div>
                </div>

                <!-- Right: Quote Details -->
                <div class="stride-quote-details">
                    <h4><?php esc_html_e('Offerte details', 'stride'); ?></h4>

                    <div class="stride-field">
                        <label for="quote_order_number"><?php esc_html_e('Bestelnummer (PO)', 'stride'); ?></label>
                        <input type="text" id="quote_order_number" name="ntdst_fields[order_number]"
                               value="<?php echo esc_attr($quote['order_number'] ?? ''); ?>"
                               placeholder="<?php esc_attr_e('Optioneel', 'stride'); ?>"
                               <?php echo !$isEditable ? 'readonly' : ''; ?>>
                    </div>

                    <input type="hidden" name="ntdst_fields[course_id]" value="<?php echo esc_attr($courseId); ?>">
                </div>
            </div>

            <!-- Line Items Table -->
            <?php $this->renderItemsTable($items, $quote, $isEditable); ?>

            <!-- Hidden fields -->
            <input type="hidden" name="ntdst_fields[status]" value="<?php echo esc_attr($quote['status']); ?>">
            <input type="hidden" name="ntdst_fields[quote_number]" value="<?php echo esc_attr($quote['number']); ?>">
            <input type="hidden" name="ntdst_fields[created_at]" value="<?php echo esc_attr($quote['created_at']); ?>">
            <input type="hidden" name="ntdst_fields[subtotal]" id="quote_subtotal" value="<?php echo esc_attr($quote['subtotal'] ?? 0); ?>">
            <input type="hidden" name="ntdst_fields[tax]" id="quote_tax" value="<?php echo esc_attr($quote['tax'] ?? 0); ?>">
            <input type="hidden" name="ntdst_fields[total]" id="quote_total" value="<?php echo esc_attr($quote['total'] ?? 0); ?>">
            <input type="hidden" name="ntdst_fields[discount]" id="quote_discount" value="<?php echo esc_attr($quote['discount'] ?? 0); ?>">
            <input type="hidden" name="ntdst_fields[voucher_code]" value="<?php echo esc_attr($quote['voucher_code'] ?? ''); ?>">
        </div>
        <?php
    }

    /**
     * Render line items table
     */
    private function renderItemsTable(array $items, array $quote, bool $isEditable): void
    {
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
                        <?php if ($isEditable): ?>
                            <th class="actions"></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="stride-quote-items-body">
                    <?php foreach ($items as $index => $item): ?>
                        <tr class="item-row <?php echo ($item['type'] ?? '') === 'discount' ? 'discount-row' : ''; ?>" data-index="<?php echo esc_attr($index); ?>">
                            <td class="description">
                                <?php if ($isEditable): ?>
                                    <input type="text" name="items[<?php echo $index; ?>][title]"
                                           value="<?php echo esc_attr($item['title'] ?? ''); ?>" class="item-title">
                                    <input type="hidden" name="items[<?php echo $index; ?>][type]"
                                           value="<?php echo esc_attr($item['type'] ?? 'course'); ?>">
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
                                           value="<?php echo esc_attr($item['unit_price'] ?? 0); ?>"
                                           min="0" step="0.01" class="item-price">
                                <?php else: ?>
                                    <?php echo CurrencyFormatter::format((float)($item['unit_price'] ?? 0)); ?>
                                <?php endif; ?>
                            </td>
                            <td class="total">
                                <?php echo CurrencyFormatter::format((float)($item['total'] ?? 0)); ?>
                            </td>
                            <?php if ($isEditable): ?>
                                <td class="actions">
                                    <button type="button" class="button-link stride-remove-item" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="subtotal">
                        <td colspan="<?php echo $isEditable ? 3 : 3; ?>"><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                        <td class="amount"><?php echo CurrencyFormatter::format($quote['subtotal'] ?? 0); ?></td>
                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                    </tr>
                    <?php if (($quote['discount'] ?? 0) > 0): ?>
                        <tr class="discount">
                            <td colspan="<?php echo $isEditable ? 3 : 3; ?>"><?php esc_html_e('Korting', 'stride'); ?></td>
                            <td class="amount">- <?php echo CurrencyFormatter::format($quote['discount']); ?></td>
                            <?php if ($isEditable): ?><td></td><?php endif; ?>
                        </tr>
                    <?php endif; ?>
                    <tr class="tax">
                        <td colspan="<?php echo $isEditable ? 3 : 3; ?>"><?php esc_html_e('BTW 21%', 'stride'); ?></td>
                        <td class="amount"><?php echo CurrencyFormatter::format($quote['tax'] ?? 0); ?></td>
                        <?php if ($isEditable): ?><td></td><?php endif; ?>
                    </tr>
                    <tr class="grand-total">
                        <td colspan="<?php echo $isEditable ? 3 : 3; ?>"><?php esc_html_e('Totaal', 'stride'); ?></td>
                        <td class="amount"><?php echo CurrencyFormatter::format($quote['total'] ?? 0); ?></td>
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

    /**
     * Render form for new quotes
     */
    private function renderNewQuoteForm(\WP_Post $post): void
    {
        wp_nonce_field('stride_save_quote', 'stride_quote_nonce');
        ?>
        <div class="stride-new-quote-form">
            <div class="stride-new-quote-info">
                <p><strong><?php esc_html_e('Nieuwe offerte', 'stride'); ?></strong></p>
                <p><?php esc_html_e('Offertes worden normaal automatisch aangemaakt bij inschrijving. U kunt hier ook handmatig een offerte aanmaken.', 'stride'); ?></p>
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
                <label for="quote_course_id"><?php esc_html_e('Cursus', 'stride'); ?></label>
                <select name="ntdst_fields[course_id]" id="quote_course_id">
                    <option value=""><?php esc_html_e('Selecteer cursus...', 'stride'); ?></option>
                    <?php
                    $courses = get_posts([
                        'post_type' => 'sfwd-courses',
                        'posts_per_page' => -1,
                        'orderby' => 'title',
                        'order' => 'ASC',
                        'post_status' => 'publish',
                    ]);
                    foreach ($courses as $course) {
                        echo '<option value="' . esc_attr($course->ID) . '">' . esc_html($course->post_title) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <input type="hidden" name="ntdst_fields[status]" value="<?php echo esc_attr(QuoteService::STATUS_DRAFT); ?>">
            <input type="hidden" name="ntdst_fields[created_at]" value="<?php echo esc_attr(current_time('mysql')); ?>">

            <p class="description">
                <?php esc_html_e('Na opslaan worden de offerte details automatisch berekend op basis van de cursus prijs.', 'stride'); ?>
            </p>
        </div>
        <?php
    }
}
