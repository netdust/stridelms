# Quote Admin Interface Design

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Port the v4.5 quote admin interface to stride-core with clean patterns for future CPT reuse.

**Architecture:** Module-based admin with separate controller and metabox classes. Controller orchestrates registration, save handling, and AJAX. Each metabox class handles one UI section.

**Tech Stack:** PHP 8.3, WordPress metabox API, Select2 for AJAX dropdowns, existing quote-admin.css (768 lines)

---

## Overview

The quote admin interface provides a professional editing experience for quotes with:
- Two-column billing/details form
- Line items table with inline editing
- Status workflow (Draft → Sent → Exported)
- Email sending with PDF attachment
- Voucher/discount management
- Lock/unlock for edit protection

## File Structure

```
stride-core/Modules/Invoicing/Admin/
├── QuoteAdminController.php   # Orchestrator: hooks, metaboxes, save, AJAX
├── QuoteOverviewMetabox.php   # Main area: header, billing, items table
└── QuoteActionsMetabox.php    # Sidebar: status, send, voucher, lock
```

CSS: `themes/stride/assets/css/admin/quote-admin.css` (already exists, 768 lines)

---

## Component 1: QuoteAdminController

**Location:** `stride-core/Modules/Invoicing/Admin/QuoteAdminController.php`

**Responsibilities:**
1. Register 2 metaboxes (overview=normal, actions=side)
2. Remove default editor support from vad_quote
3. Enqueue assets (Select2, quote-admin.css, inline JS)
4. Handle `save_post_vad_quote` with nonce verification
5. AJAX endpoint for user search (`stride_search_users`)
6. Process sidebar actions (send, voucher, lock, status)

**Hooks:**
```php
add_meta_boxes_vad_quote    → registerMetaboxes()
save_post_vad_quote         → handleSave()
wp_ajax_stride_search_users → handleUserSearch()
admin_enqueue_scripts       → enqueueAssets()
```

**Dependencies:**
- `QuoteService` - for quote CRUD and business logic
- `VoucherService` - for voucher validation
- `QuoteOverviewMetabox` - renders main content
- `QuoteActionsMetabox` - renders sidebar

**Service Registration:**
```php
// In plugin-config.php
\ntdst\Stride\Modules\Invoicing\Admin\QuoteAdminController::class,
```

---

## Component 2: QuoteOverviewMetabox

**Location:** `stride-core/Modules/Invoicing/Admin/QuoteOverviewMetabox.php`

**Layout:**
```
┌─────────────────────────────────────────────────────────────┐
│ OFFERTE Q-2024-0001                     Aangemaakt: 15 Jan  │
│                                         Geldig tot: 15 Feb  │
├─────────────────────────────────────────────────────────────┤
│ FACTURATIE              │  OFFERTE DETAILS                  │
│ ┌─────────────────────┐ │  ┌────────────────────────────┐   │
│ │ Klant: [Select2   ▼]│ │  │ PO nummer: [_________]     │   │
│ │ Org:   [_________]  │ │  │                            │   │
│ │ Email: [_________]  │ │  └────────────────────────────┘   │
│ │ Adres: [_________]  │ │                                   │
│ │ BTW:   [_________]  │ │                                   │
│ │ GLN:   [_________]  │ │                                   │
│ └─────────────────────┘ │                                   │
├─────────────────────────────────────────────────────────────┤
│ REGELS                                                      │
│ ┌───────────────────────────────────────────────────────┐   │
│ │ Omschrijving          │ Aantal │ Prijs  │ Totaal  │ X │   │
│ ├───────────────────────┼────────┼────────┼─────────┼───┤   │
│ │ [Cursus editie titel] │  [1]   │ €450   │ €450    │ x │   │
│ ├───────────────────────┴────────┴────────┴─────────┴───┤   │
│ │                              Subtotaal    │ €450.00   │   │
│ │                              Korting      │ -€50.00   │   │
│ │                              BTW 21%      │ €84.00    │   │
│ │                              TOTAAL       │ €484.00   │   │
│ └───────────────────────────────────────────────────────┘   │
│ [+ Regel toevoegen]                                         │
└─────────────────────────────────────────────────────────────┘
```

**Features:**
- Header with quote number (editable for new, readonly for existing)
- Date display (created, valid until)
- Two-column form: billing (left) and details (right)
- Select2 user dropdown with AJAX search
- Auto-populate billing from user meta on selection
- Line items table with inline editing
- Add/remove line items
- Totals footer with live calculation

**Billing Fields:**
- user_id (Select2 dropdown)
- organisation
- email
- address
- vat_number
- gln_number

**Item Fields (JSON array):**
- title (string)
- quantity (int)
- unit_price (int, cents)
- total (calculated)

---

## Component 3: QuoteActionsMetabox

**Location:** `stride-core/Modules/Invoicing/Admin/QuoteActionsMetabox.php`

**Layout:**
```
┌─────────────────────────┐
│    ✏️ CONCEPT           │  ← Status header (color-coded)
│    🔒 Vergrendeld       │
├─────────────────────────┤
│    Totaal               │
│    €484.00              │
├─────────────────────────┤
│ Aangemaakt   15 Jan     │
│ Geldig tot   [date   ]  │  ← Editable if unlocked
│ Verzonden    17 Jan     │
│ Naar         user@...   │
├─────────────────────────┤
│ BEKIJKEN                │
│ [📄 PDF] [👁 Formulier] │
├─────────────────────────┤
│ VERZENDEN               │
│ Naar: [email@____]      │
│ CC:   [optional___]     │
│ [📧 Verzenden]          │
├─────────────────────────┤
│ KORTING                 │
│ ✓ EARLY25 → -€50.00  x  │  ← Applied voucher
│ -- of --                │
│ [code____] [Toepassen]  │  ← Input voucher
│ [€_____] [Toepassen]    │  ← Manual discount
├─────────────────────────┤
│ STATUS                  │
│ [Concept        ▼]      │
│ [🔓 Ontgrendelen][📄PDF]│
└─────────────────────────┘
```

**Status Colors:**
- Draft (concept): Yellow (#dba617 on #fcf9e8)
- Sent (verzonden): Blue (#0073aa on #e5f5fa)
- Exported (geëxporteerd): Green (#46b450 on #ecf7ed)

**Features:**
- Status header with icon and color
- Lock badge when quote is locked
- Total display (large, prominent)
- Meta info list (created, valid until, sent date, sent to)
- View actions: PDF link, Form preview link
- Send form: email to, CC (optional), send button
- Voucher section: show applied OR input fields
- Status dropdown
- Lock/Unlock button
- Regenerate PDF button

**Hidden Form Fields (for POST handling):**
- stride_send_quote (email target)
- stride_apply_voucher (voucher code)
- stride_apply_discount (manual amount)
- stride_remove_voucher (flag)
- stride_lock_action (lock/unlock)
- stride_regenerate_pdf (flag)
- stride_change_status (new status)

---

## JavaScript Behavior

**User Selection (Select2):**
```javascript
$('#stride_user_id').select2({
    ajax: {
        url: ajaxurl,
        data: (params) => ({ action: 'stride_search_users', search: params.term }),
        processResults: (data) => ({ results: data })
    }
});

// On user change, populate billing fields
$('#stride_user_id').on('change', function() {
    // Fetch user data via AJAX, populate organisation, email, address, etc.
});
```

**Line Items:**
```javascript
// Add row
$('#stride-add-item').on('click', function() {
    // Clone template row, append to table
});

// Remove row
$('.stride-remove-item').on('click', function() {
    // Remove row, recalculate totals
});

// Live total calculation
$('.item-qty, .item-price').on('input', function() {
    // Update row total, recalculate all totals
});
```

**Sidebar Actions:**
```javascript
// Send quote button
$('#stride-send-quote-btn').on('click', function() {
    const email = $('#stride_send_to').val();
    $('#stride_send_quote').val(email);
    // Trigger form submit
});

// Apply voucher
$('#stride-apply-voucher').on('click', function() {
    const code = $('#stride_voucher_code').val();
    $('#stride_apply_voucher_action').val(code);
    // Trigger form submit
});

// Lock/Unlock toggle
$('#stride-lock-btn, #stride-unlock-btn').on('click', function() {
    $('#stride_lock_action').val($(this).data('action'));
    // Trigger form submit
});
```

---

## Integration Points

**QuoteService (existing):**
- `getQuote($postId)` - fetch quote data
- `updateQuote($postId, $data)` - save quote
- `sendQuote($postId, $email, $cc)` - send email with PDF
- `getQuoteUrl($postId)` - PDF download URL
- `getQuoteFormUrl($postId)` - customer form URL
- `regeneratePdf($postId)` - regenerate PDF

**VoucherService (existing):**
- `validateVoucher($code, $context)` - check if voucher is valid
- `applyVoucher($code, $quoteId)` - apply voucher to quote
- `removeVoucher($quoteId)` - remove voucher from quote

**CurrencyFormatter (existing):**
- `format($cents)` - format cents as currency string (€450.00)

---

## Reuse Patterns for Future CPTs

When implementing Edition admin (or others), follow this pattern:

1. **Create Admin folder:** `stride-core/Modules/{Module}/Admin/`
2. **Create Controller:** `{CPT}AdminController.php` with:
   - `registerMetaboxes()` method
   - `handleSave()` method
   - `enqueueAssets()` method
3. **Create Metaboxes:** Separate class per UI section
4. **Reuse CSS classes:** `.stride-field-row`, `.stride-sidebar-*`, `.stride-*-table`
5. **Follow naming:** `stride_{cpt}_*` for actions, `ntdst_fields[*]` for form fields

---

## Testing Checklist

- [ ] New quote: user selection populates billing
- [ ] Edit quote: all fields load correctly
- [ ] Line items: add, edit, remove work
- [ ] Totals: calculate correctly with discount
- [ ] Send: email sends with PDF attachment
- [ ] Voucher: apply, display, remove work
- [ ] Manual discount: apply, display, remove work
- [ ] Lock/unlock: prevents editing when locked
- [ ] Status: changes persist correctly
- [ ] PDF regenerate: creates new PDF
