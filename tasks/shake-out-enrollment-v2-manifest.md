# Shake-out Manifest: Enrollment Form v2

**Date:** 2026-03-31
**Scope:** Enrollment form — self, colleague, minimal form, voucher, data persistence
**Tested as:** seed_student1 (user 3194) across 3 edition types

## Summary

| Severity | Count | Resolved |
|----------|-------|----------|
| CRITICAL | 1 | 1 |
| IMPORTANT | 1 | 1 |
| MINOR | 2 | 2 |
| **Total** | **4** | **4** |

---

## Bugs

### BUG-EV2-1: Colleague enrollment saved as self — `enrollment_type` mismatch [CRITICAL]

**What was tested:** Enrolling a colleague (Sofie De Smet / sofie.desmet@bweeg.test) for "Keuzecursus: Groepsdynamiek in Sportteams"
**Expected:** New user created for Sofie, registration with `enrollment_path='colleague'`, `enrolled_by=3194` (current user)
**Actual:** Registration saved with `user_id=3194` (current user), `path='individual'`, `enrolled_by=NULL`. Sofie user never created. The current user's profile (first_name, last_name) was overwritten with Sofie's data.

**Root cause:** Frontend sends `enrollment_type: 'collega'` (Dutch). Backend at `EnrollmentService.php:591` checks `if ($enrollmentType === 'colleague')` (English). The comparison fails, so it falls through to the self-enrollment path.

**Impact:**
- Colleague enrollment is completely broken — always enrolls the current user
- Current user's profile gets overwritten with colleague's name/phone
- No new user is created for the actual participant
- Quote goes to wrong person

**Files:** `EnrollmentService.php:591` — `'colleague'` should be `'collega'`

**RESOLVED:** Changed check to `in_array($enrollmentType, ['colleague', 'collega'], true)`. Verified: Sofie created as new user (ID 3239), registration path=colleague, enrolled_by set, current user's profile preserved.

---

### BUG-EV2-2: Voucher discount shows €0,00 — parameter name mismatch [IMPORTANT]

**What was tested:** Validating voucher WELKOM2026 (10%) on edition 13224 (€295,00)
**Expected:** Discount "- €29,50" shown on billing step and confirmation
**Actual:** Voucher validates as "✓ Geldig" but discount shows "€ 0,00". Backend quote correctly has `discount: 2950`.

**Root cause:** Frontend sends `{ item_id: this.itemId }` (`enrollment.js:142`). Backend reads `$params['edition_id'] ?? $params['trajectory_id']` (`EnrollmentFormHandler.php:414`). Parameter name mismatch → `$itemId = 0` → `getItemPrice('edition', 0)` returns null/zero → discount calculated on zero base = €0,00.

**Impact:** User sees no discount on the form, but the quote IS created with the correct discount. Confusing UX — user thinks voucher doesn't work.

**Note:** The quote created during enrollment correctly applies the voucher (discount=2950 on the quote). The bug is only in the AJAX validation preview.

**Files:** `Handlers/EnrollmentFormHandler.php:414` or `templates/forms/enrollment.js:142`

**RESOLVED:** Added `$params['item_id']` fallback in handler: `absint($params['item_id'] ?? $params['edition_id'] ?? ...)`. Verified: WELKOM2026 now shows "€ 24,50" discount (10% of €245).

---

### BUG-EV2-3: Minimal form sidebar shows placeholder instead of edition info [MINOR]

**What was tested:** Enrollment form for edition 5913 (form_type='minimal')
**Expected:** Sidebar shows edition title, date, venue, price
**Actual:** Sidebar shows "Selecteer een opleiding om in te schrijven." — a placeholder message

**Root cause:** The sidebar template likely doesn't receive edition data for minimal form types, or the condition for showing edition info isn't met.

**Impact:** Minor UX issue — user can't see edition summary in sidebar during minimal form enrollment. Confirmation step does show the correct info.

**Files:** `templates/forms/enrollment.php:64`

**RESOLVED:** Added fallback: `($course_id ? get_the_title($course_id) : '') ?: get_the_title($item_id)`. When linked course doesn't exist, falls back to edition title.

---

### BUG-EV2-4: "Vorige" button visible on first step of minimal form [MINOR]

**What was tested:** Minimal form (2 steps: Gegevens → Bevestigen)
**Expected:** No "Vorige" button on step 1 (first step)
**Actual:** "Vorige" button visible. Clicking it does nothing (stays on same step).

**Root cause:** Step template always renders "Vorige" button without checking if it's the first step in the flow.

**Impact:** Cosmetic — button is non-functional, no crash. Slightly confusing UX.

**Files:** `templates/forms/enrollment/step-personal.php:89-91`

**RESOLVED:** Added `stepIndex > 0` condition to "Vorige" button visibility. Spacer span also updated to show when `stepIndex === 0`.

---

## Clusters

### Cluster A: Colleague enrollment broken (BUG-EV2-1)
Single-word mismatch: `'collega'` vs `'colleague'`. The root blocker for the entire colleague flow. Also causes user profile corruption.

### Cluster B: Voucher display (BUG-EV2-2)
Parameter name mismatch: `item_id` vs `edition_id`. Independent of Cluster A.

### Cluster C: Minimal form UX (BUG-EV2-3, BUG-EV2-4)
Minor UX polish items for the minimal form path.

---

## Fix Order

1. **BUG-EV2-1** (CRITICAL) — Fix colleague enrollment type check
2. **BUG-EV2-2** (IMPORTANT) — Fix voucher validation parameter name
3. **BUG-EV2-3** (MINOR) — Fix sidebar for minimal form
4. **BUG-EV2-4** (MINOR) — Hide "Vorige" button on first step

---

## What Works Well

- Self-enrollment (werknemer): Full 4-step flow works end-to-end
- Data persistence: All user meta saved correctly (phone, org, billing)
- Quote auto-creation with correct voucher discount
- Already-enrolled guard: "Je bent al ingeschreven" message
- Progress bar navigation
- Billing data prefilled from previous enrollment
- Sidebar summary (for standard form)
- Terms acceptance gate
- Redirect to dashboard after success
