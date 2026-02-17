# Quote Admin Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement professional quote admin interface with two-column form, line items, status workflow, vouchers, and sidebar actions.

**Architecture:** Port v4.5 admin classes to stride-core/Modules/Invoicing/Admin/ with fresh namespace. Controller orchestrates metaboxes, each metabox class handles one UI section. CSS and JS already exist in theme.

**Tech Stack:** PHP 8.3, WordPress metabox API, Select2 (CDN), existing CSS/JS assets

---

## Prerequisites

Files already exist:
- CSS: `themes/stride/assets/css/admin/quote-admin.css` (768 lines)
- JS: `themes/stride/assets/js/admin/quote-admin.js` (430 lines)
- Service: `stride-core/Modules/Invoicing/QuoteService.php`
- CPT: `stride-core/Modules/Invoicing/QuoteCPT.php`

---

### Task 1: Create Admin Directory Structure

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/` (directory)

**Step 1: Create the Admin directory**

```bash
mkdir -p web/app/mu-plugins/stride-core/Modules/Invoicing/Admin
```

**Step 2: Verify directory exists**

Run: `ls -la web/app/mu-plugins/stride-core/Modules/Invoicing/`
Expected: `Admin` directory present

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/Admin
git commit --allow-empty -m "chore: create Quote Admin directory structure"
```

---

### Task 2: Create QuoteOverviewMetabox

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteOverviewMetabox.php`

**Step 1: Create the metabox class**

```php
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
        $quote = $this->quoteService->getQuote($post->ID);

        // Handle new/unsaved quotes
        if (is_wp_error($quote) || empty($quote['quote_number'])) {
            $this->renderNewQuoteForm($post);
            return;
        }

        $this->renderExistingQuote($post, $quote);
    }

    private function renderExistingQuote(WP_Post $post, array $quote): void
    {
        $userId = (int) ($quote['user_id'] ?? 0);
        $user = $userId ? get_userdata($userId) : null;
        $billing = $quote['billing'] ?? [];
        $items = $quote['items'] ?? [];
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
                <label for="billing_organisation"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                <input type="text" id="billing_organisation" name="billing[organisation]"
                       value="<?php echo esc_attr($billing['organisation'] ?? ''); ?>" <?php echo $readonly; ?>>
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
                        'posts_per_page' => 50,
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
```

**Step 2: Verify syntax**

Run: `php -l web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteOverviewMetabox.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteOverviewMetabox.php
git commit -m "feat(quote-admin): add QuoteOverviewMetabox

Renders main quote form with:
- Header with quote number and dates
- Two-column billing/details form
- Line items table with inline editing
- Totals footer with calculations"
```

---

### Task 3: Create QuoteActionsMetabox

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteActionsMetabox.php`

**Step 1: Create the sidebar metabox class**

```php
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
        $billing = $quote['billing'] ?? [];
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
        $pdfPath = $quote['pdf_path'] ?? '';
        $formUrl = home_url('/offerte/' . $post->ID . '/');
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Bekijken', 'stride'); ?></h4>
            <div class="stride-sidebar-actions">
                <div class="stride-action-row">
                    <?php if (!empty($pdfPath)): ?>
                        <a href="<?php echo esc_url(content_url($pdfPath)); ?>" class="button" target="_blank">
                            <span class="dashicons dashicons-pdf"></span>
                            <?php esc_html_e('PDF', 'stride'); ?>
                        </a>
                    <?php else: ?>
                        <button type="button" class="button" disabled title="<?php esc_attr_e('PDF nog niet gegenereerd', 'stride'); ?>">
                            <span class="dashicons dashicons-pdf"></span>
                            <?php esc_html_e('PDF', 'stride'); ?>
                        </button>
                    <?php endif; ?>

                    <a href="<?php echo esc_url($formUrl); ?>" class="button" target="_blank">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php esc_html_e('Formulier', 'stride'); ?>
                    </a>
                </div>
            </div>
        </div>
        <?php
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
        ?>
        <div class="stride-sidebar-section">
            <h4><?php esc_html_e('Status', 'stride'); ?></h4>
            <select name="stride_change_status" id="stride_change_status" class="stride-status-select">
                <option value="draft" <?php selected($status, QuoteStatus::Draft); ?>>
                    <?php esc_html_e('Concept', 'stride'); ?>
                </option>
                <option value="sent" <?php selected($status, QuoteStatus::Sent); ?>>
                    <?php esc_html_e('Verzonden', 'stride'); ?>
                </option>
                <option value="exported" <?php selected($status, QuoteStatus::Exported); ?>>
                    <?php esc_html_e('Geexporteerd', 'stride'); ?>
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
```

**Step 2: Verify syntax**

Run: `php -l web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteActionsMetabox.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteActionsMetabox.php
git commit -m "feat(quote-admin): add QuoteActionsMetabox sidebar

Renders sidebar with:
- Status header with color coding
- Total display
- Meta info (dates, sent to)
- View actions (PDF, Form)
- Send email form
- Voucher/discount section
- Status dropdown with lock/unlock"
```

---

### Task 4: Create QuoteAdminController

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteAdminController.php`

**Step 1: Create the controller class**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Invoicing\Admin;

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
}
```

**Step 2: Verify syntax**

Run: `php -l web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteAdminController.php`
Expected: `No syntax errors detected`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/Admin/QuoteAdminController.php
git commit -m "feat(quote-admin): add QuoteAdminController

Orchestrates quote admin interface:
- Registers overview and actions metaboxes
- Enqueues Select2, CSS, and JS assets
- Handles save with billing, items, status, lock
- Processes voucher/discount actions
- AJAX endpoint for user billing data"
```

---

### Task 5: Register QuoteAdminController as Service

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Read current config**

Run: `cat web/app/mu-plugins/stride-core/plugin-config.php | head -50`

**Step 2: Add QuoteAdminController to services array**

Add this line to the services array:
```php
\Stride\Modules\Invoicing\Admin\QuoteAdminController::class,
```

**Step 3: Verify the config file syntax**

Run: `php -l web/app/mu-plugins/stride-core/plugin-config.php`
Expected: `No syntax errors detected`

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(quote-admin): register QuoteAdminController service"
```

---

### Task 6: Add QuoteStatus Enum (if missing)

**Files:**
- Create: `web/app/mu-plugins/stride-core/Domain/QuoteStatus.php` (if not exists)

**Step 1: Check if QuoteStatus exists**

Run: `ls -la web/app/mu-plugins/stride-core/Domain/QuoteStatus.php 2>/dev/null || echo "NOT FOUND"`

**Step 2: If not found, create it**

```php
<?php

declare(strict_types=1);

namespace Stride\Domain;

enum QuoteStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Exported = 'exported';
    case Cancelled = 'cancelled';
}
```

**Step 3: Verify syntax**

Run: `php -l web/app/mu-plugins/stride-core/Domain/QuoteStatus.php`
Expected: `No syntax errors detected`

**Step 4: Commit (if created)**

```bash
git add web/app/mu-plugins/stride-core/Domain/QuoteStatus.php
git commit -m "feat(domain): add QuoteStatus enum"
```

---

### Task 7: Test Quote Admin Interface

**Step 1: Clear caches**

Run: `ddev exec wp cache flush`

**Step 2: Verify admin loads without errors**

1. Open https://stride.ddev.site/wp/wp-admin/edit.php?post_type=vad_quote
2. Click "Add New" or edit an existing quote
3. Verify:
   - Custom metabox renders (no default editor)
   - Select2 dropdown works
   - CSS styles load
   - JS interactions work

**Step 3: Test new quote creation**

1. Create new quote
2. Select user and edition
3. Save
4. Verify quote number generated and items calculated

**Step 4: Test sidebar actions**

1. Change status
2. Lock/unlock quote
3. Apply voucher code
4. Apply manual discount

**Step 5: Commit test verification**

```bash
git add -A
git commit -m "chore: verify quote admin interface working"
```

---

### Task 8: Update JS AJAX to Include Nonce

**Files:**
- Modify: `web/app/themes/stride/assets/js/admin/quote-admin.js`

**Step 1: Find the AJAX call**

Look for the `stride_get_user_data` AJAX call and ensure it includes the nonce:

```javascript
$.ajax({
    url: ajaxurl,
    type: 'POST',
    data: {
        action: 'stride_get_user_data',
        user_id: userId,
        nonce: strideQuoteAdmin.nonce  // Add this line
    },
    // ...
});
```

**Step 2: Verify the JS file includes nonce in AJAX calls**

Run: `grep -n "nonce" web/app/themes/stride/assets/js/admin/quote-admin.js`

**Step 3: If nonce is missing, add it to the AJAX data object**

The controller expects `nonce` in the POST data for `stride_get_user_data`.

**Step 4: Commit**

```bash
git add web/app/themes/stride/assets/js/admin/quote-admin.js
git commit -m "fix(quote-admin): add nonce to AJAX user data request"
```

---

## Summary

After completing all tasks, you will have:

1. **Admin directory structure** at `stride-core/Modules/Invoicing/Admin/`
2. **QuoteOverviewMetabox** - Main content with billing form and items table
3. **QuoteActionsMetabox** - Sidebar with status, send, voucher, lock controls
4. **QuoteAdminController** - Service that orchestrates everything
5. **Working quote admin** with all features from v4.5

The pattern is now documented for Edition and other CPTs to follow.
