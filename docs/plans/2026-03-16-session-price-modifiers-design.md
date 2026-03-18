# Session Price Modifiers — Design Spec

**Date:** 2026-03-16
**Status:** Draft
**Scope:** Sessions only (no product add-ons)

---

## Problem

When users enroll and must choose between sessions (session selection completion task), their choice can affect pricing. Examples:

- Session A is classroom-only (base price), Session B includes a book (+€ 45)
- Session A is paid, Session B is free (-€ 150 = full discount)
- Session A is the standard option, Session B is a cheaper alternative (-€ 20)

Currently there is no way to associate a price impact with individual sessions.

## Solution

Add a `price_modifier` field to individual sessions (`vad_session`). When a user selects sessions during the completion task, any non-zero modifiers on sessions that belong to a slot are added as line items on the existing quote. The quote totals are recalculated.

---

## Data Model

### Session field

- **Field:** `price_modifier` on `vad_session` (via SessionCPT)
- **Type:** `int` (stored in cents, admin input in euros — converted on save/display)
- **Values:** positive (+€ 45,00 = 4500 cents surcharge), negative (-€ 20,00 = -2000 cents discount), zero/empty (no effect)
- **Guard rule:** The modifier only takes effect when the session has a non-empty `slot` value. No slot = no price impact, regardless of modifier value.

### Quote line items

Quotes already support a JSON `items` array. Session modifier items use type `session_modifier`:

```php
[
    'id'         => int,       // Session ID
    'type'       => 'session_modifier',
    'title'      => 'Sessie: Dag 1 - Voormiddag',
    'quantity'   => 1,
    'unit_price' => 4500,      // cents (can be negative)
    'total'      => 4500,      // cents (can be negative)
]
```

One line item per selected session that has a modifier. If `pick_count > 1` and the user selects multiple sessions with modifiers from the same slot, each gets its own line item.

No new tables or CPTs needed.

---

## Integration Point

The quote update is triggered by a new hook listener in `QuoteService`, registered on `stride/enrollment/task_completed`. This is consistent with how `onRegistrationCancelled` already hooks into the event system.

```php
// In QuoteService::init()
add_action('stride/enrollment/task_completed', [$this, 'onSessionSelectionCompleted']);
```

The listener checks if the completed task is `session_selection`, then reads session IDs from `$data['tasks']['session_selection']['data']['session_ids']`.

---

## Quote Update Flow

### Steps

1. **Check task type** — only proceed if the completed task is `session_selection`.
2. **Get registration** — from `$data['registration_id']`.
3. **Find quote** — look up the existing quote for this registration via `QuoteRepository::findByRegistration()`.
4. **No quote?** — log a warning via `ntdst_log('invoicing')` and return early. This is a valid edge case (e.g., free edition with no quote).
5. **Check quote state:**
   - **Draft:** proceed to update.
   - **Sent / Exported / Locked:** fire `stride/quote/session_modifier_blocked` hook. Return early.
   - **Cancelled:** no-op, return early (no hook, no error — cancelled quotes have no business impact).
6. **Collect modifiers** — for each selected session ID, fetch the session. Only include sessions that have a non-empty `slot` AND a non-zero `price_modifier`.
7. **Validate sessions** — verify each session belongs to the registration's edition. Skip any that don't (prevents cross-edition modifier injection).
8. **Replace modifier items** — get current quote items, strip any existing `session_modifier` items, append new ones.
9. **Recalculate** — call `QuoteCalculator::calculateTotals($updatedItems, $existingDiscount)`, preserving any previously applied voucher discount. Update quote meta: `items`, `subtotal`, `tax`, `total`.
10. **Log** — `ntdst_log('invoicing')->info('Session modifiers applied', [...])`.
11. **Dispatch event** — `do_action('stride/quote/modifiers_applied', [...])` for audit log and mail triggers.

### Hook: `stride/quote/session_modifier_blocked`

Fired when session selection would modify a quote but the quote is not editable.

```php
do_action('stride/quote/session_modifier_blocked', [
    'quote_id'        => $quoteId,
    'registration_id' => $registrationId,
    'user_id'         => $userId,
    'modifiers'       => [
        // All amounts in cents
        ['session_id' => 123, 'title' => 'Dag 1 - Voormiddag', 'amount_cents' => 4500],
    ],
]);
```

`netdust-mail` can listen to this and send admin notification. The `amount_cents` key makes the unit explicit.

### Voucher interaction

When recalculating after modifier changes, the existing voucher discount (stored as cents in quote meta `discount`) is preserved and passed to `QuoteCalculator::calculateTotals()`. The voucher amount stays fixed — it was calculated at enrollment time based on the subtotal then. If the admin wants to adjust, they can do so manually on the quote.

### Re-selection

Users can change their session selection. The session selection task allows re-submission even when already completed — `CompletionTaskHandler` calls the task update regardless of prior completion status, and the `stride/enrollment/task_completed` hook fires each time. On re-submit: old `session_modifier` items are stripped, new ones added, totals recalculated. The locked-quote guard prevents changes to finalized quotes.

---

## User-Facing UI (Session Selection)

In `templates/forms/completion/task-session_selection.php`:

- Each session option with a non-zero `price_modifier` (on a slotted session) shows the impact next to its label:
  - **"Dag 1 - Voormiddag (+€ 45,00)"**
  - **"Dag 2 - Namiddag (-€ 20,00)"**
- Sessions with zero/no modifier show no price annotation.
- Below the selection form, a notice: *"Je offerte wordt automatisch aangepast op basis van je sessiekeuze."*
- Notice only shown when at least one slotted session in the selection has a non-zero modifier.
- If the quote is locked/sent/exported and the user re-selects, show: *"Sessiekeuze opgeslagen. Je offerte kon niet automatisch worden bijgewerkt — de beheerder wordt op de hoogte gebracht."*

The `price_modifier` field must be included in `SessionService::formatSessionArray()` so it's available in the template data.

---

## Admin UI (Session Management)

In `EditionSessionsMetabox`, each session row gets an extra input:

- **Label:** "Prijswijziging (€)"
- **Input:** euro amount field (e.g., `45,00` or `-20,50`), empty means no modifier
- **Always visible** on every session row
- When the session has no slot assigned, show a subtle hint text: *"Alleen actief bij sessiekeuze"*
- Stored via the existing session meta save flow, converted from euros to cents on save

---

## Quote Display

The admin quote metabox (`QuoteOverviewMetabox`) currently sets `min="0"` on the unit_price input for editable items. This must be updated to allow negative values for `session_modifier` type items (remove the `min` constraint or set it to allow negatives).

Otherwise, no structural changes needed — both admin and user-facing quote display already render all line items.

---

## Files Affected

| File | Change |
|------|--------|
| `Modules/Edition/SessionCPT.php` | Add `price_modifier` field definition (type `int`, cents) |
| `Modules/Edition/SessionService.php` | Include `price_modifier` in `formatSessionArray()` and `formatSession()` |
| `Modules/Edition/Admin/EditionSessionsMetabox.php` | Add euro input for price modifier per session row, with slot hint |
| `Modules/Invoicing/QuoteService.php` | Add `onSessionSelectionCompleted()` hook listener, register on `stride/enrollment/task_completed` |
| `Modules/Invoicing/Helpers/QuoteCalculator.php` | No changes needed (already sums all items) |
| `Modules/Invoicing/Admin/QuoteAdminController.php` | Allow negative `unit_price` for `session_modifier` items |
| `templates/forms/completion/task-session_selection.php` | Show price impact per session, add notice text, blocked-quote message |

---

## Out of Scope

- Product add-ons (books, materials) — future feature
- Per-user pricing overrides
- Multiple modifier types on a single session
- Modifier on slots (pricing is per-session, not per-slot)
- "Gratis" label detection (would require knowing base price in template context — keep it simple with the euro amount)
