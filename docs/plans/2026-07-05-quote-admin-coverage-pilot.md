# Quote Admin Write-Path Coverage — Pilot Plan

**Date:** 2026-07-05
**Goal:** Put a green, trustworthy safety net under the **Quote admin write path** (`QuoteAdminController::handleSave` + admin AJAX), the single largest untested admin surface of the 5 CPTs. This is the pilot; if the shape works, replicate for the other 4 CPTs.
**Precondition:** the net must be green first — Integration CORS fix (commit `872ae50f`) confirmed green in CI. Do NOT start writing tests until then.
**Scope:** tests only. No product code changes unless a test surfaces a real bug (then it's a separate fix, flagged first).

---

## What's already covered (do NOT re-test)
- Totals math (`QuoteCalculatorTest`, `QuoteTotalsCharacterizationTest`), session modifiers (`QuoteServiceModifierTest`), PDF enrichment (`QuotePDFGeneratorTest`), the **frontend/API** handlers (`QuoteUpdateHandlerIntegrationTest` — auth, owner, locked, bulk-lock), list service (`AdminQuoteServiceTest`), locked-quote GDPR edge (`DashboardQuoteGdprEdgeCest`), admin render (`AdminQuoteCest`).
- The **service methods** (`applyVoucher`, `applyManualDiscount`, `removeDiscount`, `setLocked`, `markAsSent`) are covered via the frontend handler + service tests.

## The gap this pilot closes
The **admin metabox save handler** (`QuoteAdminController::handleSave`, :256-395) and `ajaxGetUserData` (:663) — the branching, side-effecting admin write path — has **no assertion-level test**. Ground-truthed against source:

| Behavior | Source | Testable contract |
|---|---|---|
| Nonce / autosave / cap guard | :259-272 | denial: bad nonce → no write; missing `edit_post` → no write |
| New-quote branch | :277-280, `handleNewQuoteCreation` :586 | picks member-else-nonmember price, seeds billing from user, generates number, `valid_until`=+30d, title=number, status=draft |
| Status→sent sets `sent_at` once | :318-319 | idempotent: second →sent doesn't overwrite |
| **Status→exported auto-locks** | :320-324 | **invariant: exported ⇒ locked=true even with no lock action** |
| Status→exported sets `exported_at` once | :321-323 | idempotent |
| Status→cancelled sets `cancelled_at` | :325-326 | set on transition |
| Invalid/unchanged status = no-op | :314 | denial: `status=bogus` or same → no status write |
| Locked quote freezes billing/items | :282-298 | denial: locked ⇒ billing/items in POST ignored… |
| …but status/lock still act on locked | :300-329 | boundary: locked quote can still be unlocked / status-changed |
| Cancel-with-registration cascade | :363-366 | fires `stride/quote/cancelled` **only** when status=cancelled AND checkbox set (2 denial paths) |
| Send fires event only with valid email | :369-380 | denial: empty/invalid `stride_send_to` ⇒ no `stride/quote/send_email` |
| Regenerate PDF fires event + notice | :383-392 | fires `stride/quote/regenerate_pdf`; notice reflects pdf_path presence |
| Voucher/discount/remove dispatch | :394, `handleVoucherActions` :520-536 | wiring seam: POST key ⇒ correct service call (the untested seam) |
| Notes sanitize + `_deleted` drop | :337-355 | round-trip: `_deleted` notes dropped, type clamped to admin/customer |
| `ajaxGetUserData` | :663 | nonce+cap; returns billing shape for user; denial without cap |

---

## Test tiers (per netdust testing-workflow)

All of these are **Tier A** (branching logic, security guards, event-firing side effects, idempotency) → RED-first behavioral tests, including denial paths. `handleSave` reads `$_POST` and fires `do_action` / writes via repository — an **integration** test (real WP, real repository, captured actions) is the faithful layer; a pure unit test would mock away the very seams we care about.

**Test type:** Integration (`tests/Integration/`), real DB, using the existing seeded-quote fixtures + `IntegrationTestCase`. Capture fired actions with `add_action` spies. This mirrors `QuoteUpdateHandlerIntegrationTest`'s style but targets the **admin** handler.

---

## Tasks (RED-first; each `Test-author: solo` — one integration test file, behavioral)

Ordered by risk (highest first, per the gap report):

- **Q-T1 — Status transitions + auto-lock.** `QuoteAdminHandleSaveStatusTest`.
  Asserts: →sent sets `sent_at` (once, idempotent); →exported sets `exported_at` (once) AND `locked=true` (the auto-lock invariant) even with no lock action; →cancelled sets `cancelled_at`; invalid status → no-op; unchanged status → no-op. Denial paths included.
- **Q-T2 — New-quote creation.** `QuoteAdminNewQuoteCreationTest`.
  Asserts: member-else-nonmember price into line item; billing seeded from user meta; quote number generated + set as title; `valid_until` = +30d; status=draft.
- **Q-T3 — Send + cancel-with-registration cascade (event firing + denial).** `QuoteAdminHandleSaveEventsTest`.
  Asserts: `stride/quote/send_email` fires only with valid `stride_send_to`, NOT with empty/invalid; `stride/quote/cancelled` fires only when status=cancelled AND `stride_cancel_registration` set (both denial paths); `stride/quote/regenerate_pdf` fires on regen.
- **Q-T4 — Lock/edit boundary + guards.** `QuoteAdminHandleSaveGuardsTest`.
  Asserts: bad nonce / missing `edit_post` ⇒ no write (denial); locked quote ignores billing+items in POST but still honors unlock + status change; notes `_deleted` dropped + type clamped.
- **Q-T5 — Voucher/discount wiring seam + ajaxGetUserData.** `QuoteAdminVoucherWiringTest`.
  Asserts: `stride_apply_voucher` POST ⇒ discount applied (through real service); `stride_apply_discount` ⇒ manual discount; `stride_remove_voucher` ⇒ discount cleared; `ajaxGetUserData` returns billing shape + denies without cap.

Each task: watch it fail RED for the behavioral reason, implement nothing (product code exists), confirm GREEN. If a test won't go green because the **product is wrong** (e.g. auto-lock doesn't fire), STOP — that's a real bug, flag it, don't weaken the test.

---

## Watch-list (possible bugs the tests may surface — flag, don't paper over)
- Auto-lock-on-export (:324) sets `locked=true` in `$updateData` but the export branch runs *before* the `!empty($updateData)` write (:358) — should be fine, but verify the lock actually persists.
- Undeclared meta keys `sent_at`/`exported_at`/`cancelled_at`/`order_number`/`last_sent_to` are written but not in `QuoteCPT::getFields()` — confirm `updateMeta` persists them (bare-key path) as Q-T1/Q-T2 will exercise this.

---

## Definition of done (pilot)
- Q-T1…Q-T5 green in the Integration suite locally (ddev) and in CI.
- No product code weakened; any real bug found is separately flagged + fixed with its own RED test.
- Baseline updated: integration test count rises by the 5 new files; recorded in memory.
- Decision point: if the shape held, replicate for Session, Voucher, Trajectory, Edition (phased, one CPT per branch).
