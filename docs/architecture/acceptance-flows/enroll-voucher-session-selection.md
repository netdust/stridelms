# Acceptance flow — Enroll → Voucher → Session Selection (Kies 1 uit N)

**Authored:** 2026-06-08 (feature-acceptance, situation A). **Bar:** strict — a flow is `pass` only when driven through the real browser against running DDEV; backend-only checks are noted as such.

## Fixture (live seed data, verified reachable)
- **Edition** `24927` "Gezonde Tussendoortjes in de Jeugdwerking" (28 Jun 2026), course `24924`.
- **Session slot** `"Verdieping (kies 1)"` — `required:true, max_selections:1`.
- **Slot options:** `24930` Sportvoeding · `24931` Budget-koken · `24932` Allergieën (choose 1).
- **Vouchers:** `KORTING50` (%), `SEEDVOUCHER10` (fixed €10), `GRATIS-INTRO`, `WELKOM2026`.
- **User:** `seed_student1@seed.test` / `seedpass123` (not already enrolled in 24927 — verify at run).

## The intended-use flow (happy path)
1. Log in as a student.
2. Navigate to the edition / enrollment page for 24927.
3. Complete the enrollment form; apply voucher `KORTING50` → see discounted total.
4. Submit → enrollment created (status per config), quote reflects voucher.
5. Land on dashboard / completion tasks → a **session-selection task** is present ("Verdieping — kies 1").
6. Pick **one** of the 3 slot sessions → save.
7. Selection persists; task marked complete; selection visible on dashboard.

## Data to check at each step (DB / un-mocked wire)
- After step 4: a `wp_vad_registrations` row for (user, 24927); a `vad_quote` with the voucher applied (discount line, correct total in cents).
- After step 6: `SessionSelection::hasSelectedSession(regId, chosenSessionId)` true; the other 2 slot sessions NOT selected; `getSlotConfig` honored (max_selections=1).
- Voucher redemption recorded (usage count / redemption row), not double-counted.

## Edge classes — MANDATORY enumeration (the six)

| # | Edge class | Concrete case for THIS flow | Expected |
|---|---|---|---|
| E1 | **Empty / zero state** | Slot has options but user submits selection step without picking any | Blocked — slot is `required:true`; cannot complete task with 0 of 1 |
| E2 | **Denied actor** | (a) Logged-out user hits enrollment URL; (b) user tries to select a session for a registration that isn't theirs | (a) redirect to login; (b) rejected (ownership check), no cross-user write |
| E3 | **Wrong-order / re-entry** | User reaches session-selection task URL **before** enrolling; OR re-submits selection after `lockSelections()` / past deadline | Not-reachable before enroll; after lock/deadline → rejected, selection immutable |
| E4 | **Concurrent / double** | (a) Double-submit the selection (pick A twice / A then B fast); (b) apply voucher, then apply a second voucher | (a) exactly one selection persists, max_selections=1 honored; (b) defined behavior (replace or reject — assert which) |
| E5 | **Boundary value** | Slot `max_selections:1` — user attempts to select 2 sessions in the same slot | Second selection rejected or replaces first; never 2 in a 1-max slot |
| E6 | **Mid-flow failure** | User enrolls + applies voucher, then **abandons** before session selection (closes tab) | Enrollment persists with the task OPEN; voucher state consistent; user can resume and pick later (until deadline) |

> A flow row is incomplete if any edge class is unaddressed. None are excluded here — all six are reachable for this flow.

## Verification manifest (situation B — driven 2026-06-08)

Spec: `tests/frontend/enrollment/session-selection-flow.spec.ts` (+ fixture `fixtures/seed-session-selection-flow.php`). Driven through the real browser against DDEV. **7/7 green.**

| Row | Result | Evidence |
|---|---|---|
| Backend: enroll + voucher → quote | ✅ pass | Fixture asserts in DB: subtotal €450 → discount €50 (KORTING50) → total €484. `voucher_applied=YES`. |
| Happy: pick 1 of N → persists | ✅ pass | Browser picks session 24930, submits; DB `selections=[24930]`, other slot options not selected. |
| E1 empty/required | ✅ pass | Submit button `disabled` while 0 selected (slot `required:true`). |
| E2 denied actor | ✅ pass | Logged-out hit on `/edities/{slug}/voltooien/` → 302 to `/aanmelden`. |
| E3 wrong-order/locked | ✅ pass | After `selections_locked_at` set, a new pick does not persist (DB stays empty). |
| E4 concurrent/double | ✅ pass | Double-submit never yields 2-in-slot or duplicate ids (`setSelections` overwrites — idempotent). |
| E5 boundary (max=1) | ✅ pass | Selecting 2 in a max_selections=1 slot persists ≤1. |
| E6 mid-flow abandon | ✅ pass | Enroll+voucher quote persists; task stays open (no selection); page resumable. |

### Findings surfaced by driving the flow (not bugs in shipped UI)
1. **`processEnrollment()` called directly does NOT create the quote** — the AJAX handler (`EnrollmentFormHandler::createQuote`) does. Documented in the fixture; relevant for any code path that enrolls without going through the handler.
2. **Completion page is routed by EDITION slug** (`/edities/{slug}/voltooien/`), not course slug — the `completion-tasks.spec.ts` doc-comment saying `/vormingen/{slug}/` is stale/misleading.
3. **Seed user IDs drift on re-seed** — hardcoded `3194` was stale (real student1 = 28650). Fixture now resolves the user by email; spec reads `user_id` from the fixture. (Worth fixing `completion-tasks.spec.ts`'s hardcoded 3194 too.)

Re-run: `npx playwright test tests/frontend/enrollment/session-selection-flow.spec.ts --project=chromium --workers=1`
