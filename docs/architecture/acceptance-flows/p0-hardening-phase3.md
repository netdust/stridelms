# Acceptance flows — Hardening sprint Phase 3 (P0 targeted)

Authored 2026-06-10 per `feature-acceptance` (situation A), scoped to the
**targeted P0 depth** Stefan chose: money/access paths only. Verification
(situation B) drives every `[drive]` cell through Codeception/WPWebDriver
(real Chrome via selenium) and records pass/fail/not-reachable in the
manifest at the bottom.

Legend per cell: `✓ Cest::method` (already driven) · `[drive]` (this phase) ·
`defer: reason` (documented, not driven).

---

## F1 — Individual enrollment (edition)

Happy path: ✓ `EnrollmentCest::successfulSelfEnrollment`

| Edge class | Scenario | Status |
|---|---|---|
| Empty/zero | submit with required personal fields empty → error, no row | [drive] |
| Denied actor | anonymous → login redirect | ✓ `EnrollmentCest::anonymousUserIsRedirectedToLogin` |
| Wrong-order/re-entry | already enrolled revisits form → no duplicate possible | ✓ `EnrollmentCest::alreadyEnrolledShowsMessage` + [drive] DB assert |
| Concurrent/double | double submitForm() in one session → exactly 1 registration | [drive] |
| Boundary | capacity 1, 1 confirmed → page offers waitlist, not form; forced submit refused | [drive] |
| Mid-flow failure | navigate away mid-wizard → no row | ✓ `EnrollmentCest::formWithoutSubmitDoesNotCreateRegistration` |

## F2 — Colleague enrollment (PII guard)

| Edge class | Scenario | Status |
|---|---|---|
| Denied actor (the C3 guard) | enroll an EXISTING user as colleague with attacker-supplied billing/personal values → victim's usermeta unchanged; values land per-registration only | [drive] |
| Empty/zero | colleague email empty → validation error | [drive] |
| Wrong-order/re-entry | colleague already enrolled in edition → duplicate refused | defer: same server path as F1 re-entry |
| Concurrent/double | covered by F1 double-submit (same endpoint) | defer: shared path |
| Boundary | covered by F1 capacity (same gate) | defer: shared path |
| Mid-flow failure | covered by F1 | defer: shared path |

## F3 — Voucher during enrollment

| Edge class | Scenario | Status |
|---|---|---|
| Empty/zero | unknown code → `not_found` error rendered, price unchanged | [drive] |
| Boundary | expired voucher (valid_until yesterday) → `expired` error | [drive] |
| Denied scope | scope `alleen` other edition → `wrong_edition` error | [drive] |
| Wrong-order/re-entry | exhausted voucher (used_count ≥ limit) → `exhausted` | [drive] |
| Concurrent/double | double redemption race | defer: transaction-locked (`VoucherService::redeem` row lock + 26 integration tests) — wire covered below the browser |
| Mid-flow failure | voucher applied then form abandoned → used_count unchanged | [drive] (DB assert) |

## F4 — Attendance (admin marks → user sees hours)

Today: **zero acceptance coverage** (FEATURE-STATUS ❌).

| Edge class | Scenario | Status |
|---|---|---|
| Happy | admin marks present on a session → row in vad_attendance; user dashboard reflects | [drive] |
| Empty/zero | edition with no sessions → attendance UI absent/empty state | [drive] |
| Denied actor | non-admin cannot mark (AJAX requires nonce+edit_posts) | [drive] (wire-level assert) |
| Wrong-order/re-entry | re-marking same user/session (present→absent) updates, no dupe row | [drive] |
| Concurrent/double | double-click mark → single row | defer: same upsert path as re-entry |
| Boundary | hours derive from session times | defer: unit-covered (`getHoursAttended`) |
| Mid-flow failure | AJAX failure leaves prior state | defer: dev-env can't fault-inject reliably |

## F5 — Completion → certificate

| Edge class | Scenario | Status |
|---|---|---|
| Happy | LD course complete → dashboard shows certificaat + certificate link | [drive] (seed completed LD state, assert dashboard render) |
| Empty/zero | enrolled, nothing complete → no certificaten nav | covered by F7 nav test |
| Denied actor | other user's certificate not visible | defer: LD-owned URL signing, post-launch audit item |
| Wrong-order | in-person course with required LD lessons can't complete on attendance alone | defer: `lesson_ld_owns_completion` — VAD course-config audit at deploy, not a code path |
| Concurrent/double | n/a (idempotent display) | defer |
| Mid-flow failure | n/a (display-only flow) | defer |

## F6 — Online/self-paced states

Happy paths: ✓ `OnlineEnrollmentCest` (7 tests incl. CTA fix regression)

| Edge class | Scenario | Status |
|---|---|---|
| Boundary | enrolled + LD access window EXPIRED → still renders as enrolled (not "Beschikbaar") — regression net for `1f35717a` | [drive] |
| Others | covered by existing OnlineEnrollmentCest + unit regression tests | ✓ |

## F7 — Dashboard states

| Edge class | Scenario | Status |
|---|---|---|
| Empty/zero | brand-new user: empty hero, no opleidingen/certificaten nav | [drive] |
| Wrong-order/re-entry | nav identical on home AND other tabs (browser regression for the 1B fix) | [drive] |
| Denied actor | anonymous → login | ✓ `ProfileCest::anonymousUserCannotAccessProfile` (same gate) |
| Others | card behavior | ✓ `DashboardCest` ×2, `TrajectoryCascadeCest` ×2 |

## F8 — Quote locking

| Edge class | Scenario | Status |
|---|---|---|
| Happy + denied write | locked quote → customer billing update rejected (`locked` error), data unchanged | [drive] |
| Wrong-order/re-entry | unlocked quote accepts the same edit (control) | [drive] |
| Boundary/admin | bulk lock from edition sidebar | defer: covered by 9 integration tests (`QuoteService::bulkSetLockedByEdition`), admin UI verified 2026-05-13 on edition 13190 |
| Concurrent/double | n/a (idempotent flag) | defer |
| Empty/zero | edition without quotes → bulk lock no-op | defer: integration-covered |
| Mid-flow failure | n/a | defer |

## F9 — Anonymise (GDPR)

| Edge class | Scenario | Status |
|---|---|---|
| Happy | admin row action → PII stripped, registrations intact, faded row in metabox | [drive] |
| Denied actor | non-`stride_manage` blocked | [drive] (wire assert; gate verified at `UserLifecycleService:182,301`) |
| Wrong-order/re-entry | anonymise twice → idempotent, no error | [drive] |
| Empty/zero | user without registrations → still anonymises | defer: integration-covered (9 tests) |
| Concurrent/double | n/a | defer |
| Mid-flow failure | partial strip | defer: single-request meta loop, integration-covered |

---

## Verification manifest (situation B — filled as driven)

| Flow/edge | Result | Evidence |
|---|---|---|
| _(filled during this phase)_ | | |
