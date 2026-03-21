# Shake-out Manifest: Invoicing Module

**Date:** 2026-03-21
**Scope:** QuoteService, VoucherService, PDF generation, quote frontend, admin UI
**Tested as:** seed_student1 (Pieter Janssen), quote OFF-2026-0151

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| IMPORTANT | 1 |
| MINOR | 2 |
| **Total** | **3** |

---

## Bugs

### BUG-I1: PDF billing address missing company name [IMPORTANT]

**What was tested:** Quote PDF (OFF-2026-0151) content review
**Expected:** Factuuradres shows company name "VAD Vormingen vzw" above personal name
**Actual:** Only shows "Pieter Janssen" — company name missing from billing address
**Severity:** IMPORTANT — invoices look incomplete, company not visible for accounting

**Root cause:** PDF template `quote.php:357` checks for `$billing['organisation']` but the billing data stores the value under key `company`. The field name mismatch causes the company to be silently skipped.

**Files:** `templates/pdf/quote.php:357`, billing data uses `company` key

---

### BUG-I2: All dates display in English instead of Dutch [MINOR]

**What was tested:** PDF dates, quote titles, enrollment confirmation
**Expected:** Dutch dates: "21 maart 2026"
**Actual:** English dates: "21 March 2026" everywhere
**Severity:** MINOR — cosmetic, but inconsistent with Dutch UI language

**Root cause:** WordPress site locale is `en_US` instead of `nl_BE`. All `date_i18n()` calls output English. This is a **site configuration issue**, not a code bug.

**Fix:** `wp option update WPLANG nl_BE` (or set in wp-config). Affects ALL date displays site-wide.

---

### BUG-I3: 58 orphaned auto-draft vouchers in database [MINOR]

**What was tested:** Database integrity check
**Expected:** Clean voucher table
**Actual:** 58 auto-draft voucher posts from test visits to "Add New Voucher"
**Severity:** MINOR — no functional impact, database clutter only

**Fix:** `wp post delete $(wp post list --post_type=vad_voucher --post_status=auto-draft --field=ID --format=csv) --force`

---

## Not Bugs (Verified Working)

- **Voucher validation:** WELKOM2026 validates correctly, NONEXISTENT rejected
- **Discount calculation:** 10% of €245 = €24,50 (correct), Full discount = €245 (correct)
- **Quote creation:** OFF-2026-NNNN sequential numbering, no duplicates
- **Quote service:** getQuote, getUserQuotes, getQuoteByRegistration all work
- **Quote PDF:** Generates successfully (36KB), renders with proper layout
- **Quote frontend:** "Mijn offertes" page renders with correct data, slide-over detail panel works
- **Quote detail:** Shows pricing, billing, line items, voucher input, download/cancel buttons
- **Auto-cancel:** Registration cancel → quote cancel logic correct
- **Status badges:** "In behandeling" (draft), "Verzonden" (sent), "Geannuleerd" (red) display correctly
- **Admin tests:** 5 quote + 8 voucher acceptance tests all pass
- **Admin voucher list:** Custom columns (Code, Korting, Gebruik, Status, Geldigheid, Editie) all render correctly
- **Admin voucher edit:** All metaboxes render (details, redemption history, actions sidebar). Fields editable.
- **Admin quote list:** Custom columns (Offerte Nr., Klant, Totaal, Status, Geldig tot, Datum) render correctly. 160 items paginated.
- **Admin quote edit:** All metaboxes render (overview with billing/items, notes, status & actions sidebar). Send email section, voucher/discount section, status dropdown, lock/PDF buttons all present.
- **Admin quote items table:** Editable inline, subtotal/BTW/total calculated correctly, add/delete/recalculate buttons functional

## Manual Checks Needed

1. [ ] Send a quote via admin → verify email arrives with PDF attachment
2. [ ] Apply voucher code from frontend quote detail panel
3. [ ] Cancel a quote from frontend → verify status updates
4. [ ] Check admin quote edit page with metaboxes
5. [ ] Verify quote PDF renders with company logo when logo is configured
