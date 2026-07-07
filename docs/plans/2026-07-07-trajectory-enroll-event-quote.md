# Plan: Unify trajectory enrollment onto the event-driven quote path

**Date:** 2026-07-07
**Class:** A (multi-task; money on a new path → threat model gate 1a fired)
**Handoff source:** `docs/plans/2026-07-07-trajectory-enroll-event-quote-handoff.md`
**Branch:** `feat/trajectory-enroll-event-quote` (create off `main`)
**Prereq:** none blocking — profiletype-enroll-gate (`feat/profiletype-enroll-gate`) may merge first.

---

## 1. Problem (one sentence)

Edition enrollment produces a quote on *every* path (event-driven via `stride/registration/created`), but trajectory enrollment produces a quote **only via the web form** (inline in `EnrollmentFormHandler::createTrajectoryQuote`) — so a **Partner-API trajectory enroll creates a registration with no quote at all**, and the profile-type auto-voucher silently never fires for it. The real defect is architectural asymmetry: `enroll → quote` is one system for editions and a different system for trajectories.

## 2. Goal / shape to build

Make trajectory quoting **event-driven, mirroring editions**:

1. `TrajectorySelection::enroll()` dispatches a **new dedicated event** `stride/trajectory/registration/created` (in addition to the existing `stride/trajectory/enrolled` notification, which stays).
2. A new **`TrajectoryQuoteHandler`** listens on that event, builds the quote (logic moved out of `EnrollmentFormHandler::createTrajectoryQuote`), guards idempotency with `getQuoteByRegistration`, and rides the attendee-keyed auto-voucher along.
3. The web-form path (`EnrollmentFormHandler::processTrajectoryEnrollment`) stops building the quote inline and lets the event do it — behavior-preserving for the web path.
4. Partner-API trajectory enroll now gets a quote + auto-voucher for free, because it routes through `enroll()`.

### Decisions resolved (handoff §6)

| # | Decision | Resolution |
|---|---|---|
| 1 | Reuse `stride/registration/created` vs new event? | **New dedicated `stride/trajectory/registration/created`.** Trajectory payload differs (trajectory_id not edition_id, trajectory price, no edition scope). A dedicated event + handler keeps the edition handler untouched and avoids union-typing its payload. Ground-truthed: `EnrollmentQuoteHandler::onRegistrationCreated` reads `edition_id` and calls `EditionRepository::find` — it cannot accept a trajectory payload without branching. |
| 2 | Thin wrapper vs full move of `createTrajectoryQuote`? | **Full move into the handler**, matching the edition path (logic lives *in* the handler). The web form stops calling it inline. Ordering: the web form already persists billing (`updateUserBillingInfo` → `EnrollmentService::updateUserProfile`) and enrolls; the event fires from `enroll()`, so any state the handler reads must be persisted **before** `enroll()` — see §5 ordering note. |
| 3 | Sequencing with `stride/trajectory/enrolled`? | Both fire from `enroll()`. `enrolled` (notification) fires first (existing line), the new quote event fires after. No handler assumes ordering between them (ground-truthed: `stride/trajectory/enrolled` subscribers are notification-only). |

### Decision 2 refinement — billing on the Partner path

The web form passes rich `$billingData` into `createTrajectoryQuote`. The event handler fires from `enroll()` which has **no billing form data** on the Partner path. Resolution: the handler reads billing the same way the **edition** handler does — `getPendingBilling($userId, $trajectoryId)` transient (web form) → fall back to `getUserBilling($userId)` (stored user meta, the Partner path). The web form sets the pending-billing transient **before** calling `enroll()`; the Partner path has none and falls back to stored meta. **This is the load-bearing ordering change on the web form** (§5).

**Transient key MUST be trajectory-namespaced (adversarial review finding #3).** `vad_edition` and `vad_trajectory` are both `wp_posts` CPTs → their post IDs share one numeric space. The edition transient key `stride_pending_billing_{userId}_{editionId}` (`EnrollmentService.php:1403`) is safe *only because one CPT keys it*. Reusing that exact shape for `{userId}_{trajectoryId}` collides when a user has a live edition-#N and trajectory-#N pending-billing in the same hour window — cross-contaminating `voucher_code` (which travels on the transient, `:1399`) onto the wrong item's quote. **Do NOT "mirror exactly": the trajectory key is `stride_pending_billing_traj_{userId}_{trajectoryId}`.** The handler reads the namespaced key; the web form writes it.

### Decision 4 — atomicity is NOT inherited by mirroring (adversarial review findings #1, #2)

The edition path's safety came from properties the trajectory path does **not** share, and mirroring the handler line-for-line imports neither:

- **`TrajectorySelection::enroll()` has no DB transaction** (ground-truthed: no `START TRANSACTION`/`try`/`catch` in the method). The registration row (`:66`) and cascade child rows (`:74`) are already committed before the new event fires (post-`:87`). WordPress `do_action()` does **not** catch exceptions — so a throwing `TrajectoryQuoteHandler` (e.g. `createQuote` fatal, malformed price) propagates out of `enroll()`, leaving a registration **with LMS access granted but no quote**, and a 500 to the caller. The old inline path caught the `WP_Error` return and cleanly cancelled (`EnrollmentFormHandler:321-331`).
  - **Resolution:** the caller-side rollback stays at the **caller**, not the event. The web form and Partner path each wrap their `enroll()` call so a missing-or-failed quote is handled. Concretely: after `enroll()` returns a registration id, re-read `quote_id`; if the trajectory is **priced** and no quote exists, `cancel()` + surface the error (preserving today's guarantee). The event handler itself must **swallow its own `WP_Error` and never throw** (wrap its body defensively, log via `ntdst_log('invoicing')`) so a handler failure degrades to "no quote_id" the caller can detect — it must never escape `do_action`.
- **Free-trajectory skip must not be read as failure (finding #2b).** "No `quote_id` after enroll" is ambiguous: it means *either* quote-creation failed *or* the trajectory is free and the handler legitimately skipped (F1 lists this as valid). The rollback heuristic must gate on **price > 0**: only a **priced** trajectory with no resulting quote is a failure worth cancelling. A free trajectory with no quote is correct and must NOT be cancelled.

- **Child-quote coupling MUST be named (finding #1).** `TrajectoryCascadeService::maybeCreateChildQuote()` suppresses per-child edition quotes when the trajectory is priced — `if ($trajectoryPrice > 0) return;` (`TrajectoryCascadeService.php:697-701`), "parent trajectory quote covers the child." This runs at `enroll():74`, *before* the parent quote event. So if the parent quote silently fails (handler skip/error), the enrollee has **neither a parent trajectory quote nor child edition quotes — total billing loss.** The priced-trajectory rollback above (cancel on missing parent quote) is what protects this invariant; it is not optional polish. This coupling is a read-dependency in §9.

---

## 3. Golden path: form / write-flow (deviations named)

- [ ] Built to the **event-driven quote** archetype the edition path already embodies (`EnrollmentQuoteHandler` on `stride/registration/created`) — this feature makes the trajectory path a faithful copy of that proven slice, not a new archetype. Read `EnrollmentQuoteHandler.php` before task breakdown (done at plan-time §1c ground-truth).
- [ ] Deviations from the edition slice (each named + justified):
  - **New event name** `stride/trajectory/registration/created` (not reusing `stride/registration/created`) — decision 1 above; trajectory payload is incompatible with the edition handler's `edition_id`/`EditionRepository` reads.
  - **`editionScoped: false`** on `applyVoucher` — the trajectory quote stores `trajectoryId` in its `edition_id` field, which is not a real edition; scope must not apply (parity with the manual trajectory path passing `validateVoucher(code, null)`). Established by the profiletype-enroll-gate branch (`EnrollmentFormHandler:472`).
  - **No LMS grant in the quote handler** — trajectory LD access is granted by `TrajectoryCascadeService::cascadeOnEnrollment` inside `enroll()`, not by the quote path. The quote handler is money-only.
  - **Price source is `trajectory['price']` (cents)**, not `EditionService::getPrice` — `TrajectoryService::getTrajectory(...)['price']` is canonical cents (INV per `bug_trajectory_price_unit_mismatch`). No `×100`.

## 4. Threat model (gate 1a — money on a new path)

**Assets:** trajectory quote money integrity (subtotal/discount/tax/total); voucher `used_count` (a scarce, cross-user resource); the attendee's voucher entitlement.

**Actors:** the enrolling user (web self-enroll = payer == attendee); a Partner user bulk-enrolling colleagues (payer != attendee — the *new* actor this feature admits to trajectory quoting); concurrent/re-entrant requests.

| # | Attack | Mitigation | Proven by |
|---|---|---|---|
| A1 | **Double-quote / double-redeem** — web-form path AND the event both fire for one registration, creating two quotes / redeeming the voucher twice. | Handler calls `getQuoteByRegistration($registrationId)` and **early-returns if a quote exists** — copied verbatim from `EnrollmentQuoteHandler.php:66`. Since the web form stops building inline, there is exactly one creator (the event); the guard is belt-and-braces against a re-fired event. (Ground-truthed: only 2 callers of `enroll()`, both removed-inline; no waitlist/cascade/admin third path.) | T-DOUBLE test (§6): after the web-form path, exactly ONE quote exists; firing the event twice creates ONE quote. |
| A2-CRITICAL | **Total billing loss — parent quote fails after child quotes already suppressed.** `maybeCreateChildQuote` suppresses child edition quotes when the trajectory is priced (`TrajectoryCascadeService.php:697`), running at `enroll():74` — *before* the parent quote event (post-`:87`). If the parent quote then fails/throws, the enrollee has neither parent nor child quotes. | Priced-trajectory caller-side rollback (Decision 4): after `enroll()`, if the trajectory is priced and no `quote_id` exists, `cancel()` + error — never let a priced enroll walk past billing. The handler swallows its own `WP_Error` and never throws, so the caller can detect the missing quote. | T-BILLING-LOSS test (§6): a priced trajectory whose quote creation fails → the registration is cancelled, not left quote-less; a **free** trajectory with no quote is NOT cancelled. |
| A3 | **Handler exception corrupts state** — a throwing handler inside the synchronous `do_action` orphans the committed registration + cascade rows (`enroll()` is not transactional). | Handler body wrapped defensively; all failure paths return/log `WP_Error`, never `throw`. The event never escapes with an exception. | T-NO-THROW test (§6): a handler-internal failure (e.g. unresolvable trajectory) leaves `enroll()` returning the registration id normally, not a fatal. |
| A4 | **Trajectory price integrity regression** — the event handler computes a different price than the inline path did. | Handler uses `(int) $trajectory['price']` (cents) exactly as `createTrajectoryQuote:377` does. Behavior-preserving move. | T-REGRESSION test (§6): web-form quote amount identical before/after the move. |
| A5 | **Auto-voucher on a resolved-but-exhausted code fails the enroll** — a resolved over-cap code fails. | `applyVoucher` failure is logged, NOT fatal — the enrollment + quote STAND without the discount (parity with edition handler `:154-162`). | T-EXHAUSTED test (§6): quote still builds, `used_count` unchanged. |

**Deferrals (explicit):**
- **Cross-attendee redeem theft (payer ≠ attendee) is NOT a live threat for trajectories today.** Ground-truthed: no trajectory `enroll()` caller passes `enrolled_by`; web form is self-enroll (`EnrollmentFormHandler:290`) and Partner passes only `company_id` (`PartnerAPIController:685`), so payer == attendee == `$userId` on **every** trajectory path that exists. We still build the handler attendee-keyed (`applyVoucher(..., redeemAsUserId: $userId)`) as correct future-proofing and to not diverge from the edition handler — but this is a **deferral guarding a future colleague/bulk trajectory path**, not a mitigated live attack. `enrolled_by` plumbing (Task 1) is null-safe scaffolding with no caller; it is not behaviorally testable until a payer≠attendee trajectory path exists. (Closes the DEFERRED note at `EnrollmentFormHandler:463-471`.)
- **Transient key collision** (finding #3) is mitigated at design time by namespacing the trajectory key (`_traj_`), not deferred — see Decision-2 refinement.
- **Concurrency between the web form's inline path and the event** disappears once the inline call is removed — one creator. The idempotency guard is defense-in-depth, not a concurrency lock (matching the edition path).

## 5. WP security requirements (per data-flow)

The feature adds **no new HTTP entry point** — the new event fires from *existing* enroll seams whose entry-point security is already established. Pillars accounted for per flow:

- [ ] **New event `stride/trajectory/registration/created`** (internal `do_action`, not user-reachable): validate — payload is server-minted ints (`registration_id`, `user_id`, `trajectory_id`) from inside `enroll()`, never request input; sanitize — n/a (no external input); escape — n/a (no output); authorize — **inherited from the caller's entry point** (web form: INV-2 nonce via `ntdst/api_data`; Partner API: INV-1 `checkPermission` + company scoping already verified at `PartnerAPIController:663-673`). The event handler MUST NOT trust any client-supplied voucher/price/user — all resolved server-side from the payload ids (the no-client-trust contract).
- [ ] **`TrajectoryQuoteHandler` auto-voucher resolution**: authorize — auto code resolved from the **attendee's stored profile type** via `ProfileTypePolicy::autoVoucherCode($userId, $trajectoryId, 'vad_trajectory')` (INV-12), never from request; the manual voucher (if any) travels on pending-billing/user state, sanitized at the web-form entry point already (`EnrollmentFormHandler::sanitizeBillingFields`).
- [ ] **Web-form path change** (removing the inline quote call): no new input surface; the ordering change (persist pending-billing before `enroll()`) is internal.

> These blocks are the convergence target for `/code-review` and `ntdst-drift-reviewer` at shake-out. Reviewers verify the diff against the named golden-path slice + pillars + categories, not free-form.

## 6. ntdst-core layering requirements

- [ ] **No pass-through** — `TrajectoryQuoteHandler` adds real logic (idempotency guard, price derivation, voucher resolution, auto-voucher redeem), not a wrapper.
- [ ] **Data access via repositories** — trajectory read via `TrajectoryService::getTrajectory` (which uses `TrajectoryRepository`); quote via `QuoteService`; registration link via `RegistrationRepository::update` (INV-3). No raw `ntdst_data()`/`$wpdb`.
- [ ] **No raw `wp_ajax_*`** — the handler subscribes via `add_action('stride/trajectory/registration/created', …)` in its constructor, registered in `stride-core.php`'s `$handlers` list (matching `EnrollmentQuoteHandler`).
- [ ] **No swallowed `WP_Error`** — `applyVoucher` failure logged via `ntdst_log('invoicing')`, quote creation failure logged; the enroll does not silently drop errors (INV-4).
- [ ] **Money via `QuoteCalculator`** — totals derive through `QuoteService::createQuote` → `QuoteCalculator` (INV-8); the handler passes `Money::cents` items, never re-derives VAT.
- [ ] **Profile-type gate via `ProfileTypePolicy`** — auto-voucher through `autoVoucherCode`, never raw `_ntdst_profiletype_rules` (INV-12).
- [ ] **Correct module layering** — handler in `Handlers/` (`Stride\Handlers\TrajectoryQuoteHandler`), sibling to `EnrollmentQuoteHandler`.

## 7. Task breakdown

**Loop budget:** ~6 tasks + 2 review clusters + slack ≈ 8 iterations. No `[HUMAN]` yield points (no destructive migration, no credentials, no deploy).

### Cluster 1 — the event + handler (money boundary; reviews FULL)

**Task 1 — Add `stride/trajectory/registration/created` dispatch to `TrajectorySelection::enroll()`**
- After the existing `stride/trajectory/enrolled` dispatch (`TrajectorySelection.php:87`), add a second `do_action('stride/trajectory/registration/created', [...])` with payload `registration_id`, `user_id`, `trajectory_id`, and `enrolled_by` (from `$options['enrolled_by'] ?? null`). **`enrolled_by` is null-safe scaffolding — NO current caller passes it** (web form and Partner both self-enroll the attendee, ground-truthed §4 deferral). It is not behaviorally testable until a payer≠attendee trajectory path exists; the RED test asserts only that the payload carries the server-minted ids and never a client-supplied voucher/price. Payload mirrors the edition event minus `edition_id` + `status`.
- **Test-tier:** Tier A (money-adjacent event surface). **Test-author: split** — Tier A + 1a-trigger surface (money path).
- **Unit test:** RED-first — assert `enroll()` fires `stride/trajectory/registration/created` with the correct payload ids (spy on the action). Denial-adjacent: assert the payload never contains a client-supplied voucher/price.
- **Acceptance:** `/drift-reviewer TrajectorySelection.php` clean; per-flow security line satisfied.

**Task 2 — Create `TrajectoryQuoteHandler` (move quote logic out of `EnrollmentFormHandler::createTrajectoryQuote`)**
- New `Handlers/TrajectoryQuoteHandler.php`. Constructor subscribes to `stride/trajectory/registration/created`. `onTrajectoryRegistrationCreated(array $data)` — **entire body wrapped so it can NEVER throw out of `do_action` (A3): catch `\Throwable`, log via `ntdst_log('invoicing')->error`, return.** Inside:
  1. Extract + guard `registration_id`/`user_id`/`trajectory_id`.
  2. **Idempotency guard** — `getQuoteByRegistration`, early-return if exists (verbatim from `EnrollmentQuoteHandler:66`).
  3. Load trajectory via `TrajectoryService::getTrajectory`; skip if not found or price zero (log). **A free (price-zero) trajectory legitimately produces no quote — this is a valid skip, not a failure.**
  4. Build items `[[title, quantity:1, unit_price: Money::cents($price)]]`.
  5. Billing: `getPendingBilling($userId, $trajectoryId)` reading the **namespaced** key `stride_pending_billing_traj_{userId}_{trajectoryId}` → fallback `getUserBilling($userId)`.
  6. Manual voucher from pending-billing `voucher_code` (validate `null` scope, calculate discount).
  7. `createQuote(...)`; **on `WP_Error` → log and return (do NOT throw)** — the caller's priced-trajectory rollback (Task 3) detects the missing `quote_id`.
  8. **Auto-voucher** when no manual voucher: `ProfileTypePolicy::autoVoucherCode($userId, $trajectoryId, 'vad_trajectory')` → `applyVoucher($quoteId, $autoCode, redeemAsUserId: $userId, editionScoped: false)`; log-not-fatal on error (A5).
  9. Link `RegistrationRepository::update($registrationId, ['quote_id' => $quoteId])`; clear the namespaced pending-billing transient.
- Register in `stride-core.php` `$handlers` list.
- **Test-tier:** Tier A (money boundary + auto-voucher redemption). **Test-author: split** — Tier A + 1a-trigger (money).
- **Unit/Integration test:** RED-first — the auto-voucher core + denial + exhausted + no-stacking cases, mirrored from `AutoVoucherTrajectoryTest` but driving the **event** (`do_action`) not the reflected private method. Plus **T-NO-THROW**: a handler-internal failure never escapes `do_action`.
- **Acceptance:** `/drift-reviewer Handlers/TrajectoryQuoteHandler.php` clean.

**── REVIEW GATE ── (Cluster 1: Tasks 1–2) — Tier FULL** (money path + new event surface + INV-12/INV-8). `/integration` + `/code-review` + `/security-review` on the cluster diff.

### Cluster 2 — web-form rewire + Partner path + coverage (reviews FULL)

**Task 3 — Rewire `EnrollmentFormHandler::processTrajectoryEnrollment` to rely on the event**
- Remove the inline `createTrajectoryQuote(...)` call (`:305-311`) and delete the now-orphaned `createTrajectoryQuote` private method (`:361-487`) — its logic now lives in the handler.
- **Ordering fix (load-bearing):** BEFORE calling `TrajectorySelection::enroll()`, persist the pending-billing transient under the **namespaced** key `set_transient('stride_pending_billing_traj_' . $userId . '_' . $trajectoryId, [...billing + voucher_code...], HOUR_IN_SECONDS)` so the event handler (firing inside `enroll()`) reads it. Keep the `updateUserBillingInfo` call.
- **Priced-trajectory rollback (A2-CRITICAL, replaces the naïve "no quote → cancel"):** after `enroll()`, re-read the registration's `quote_id`. Compute `$isPriced = (int) $trajectory['price'] > 0`. Cancel + error **only when `$isPriced && no quote_id`** — a free trajectory with no quote is correct and must NOT be cancelled (finding #2b). This preserves today's "never walk past billing" guarantee for priced trajectories while not regressing free ones. The response `quote_id` comes from that re-read.
- **Test-tier:** Tier A (behavior-preserving money-path move + rollback branch). **Test-author: split** — Tier A + money.
- **Test:** RED-first — (a) regression: web-form priced trajectory enroll produces exactly ONE quote with the same amount as before (T-REGRESSION, T-DOUBLE); (b) T-BILLING-LOSS: a priced trajectory whose quote fails → registration cancelled; a **free** trajectory → NOT cancelled, enroll succeeds with no quote.
- **Acceptance:** `/drift-reviewer EnrollmentFormHandler.php` clean; existing `AutoVoucherTrajectoryTest` migrated (see Task 5).

**Task 4 — Verify Partner-API trajectory enroll now produces a quote (integration)**
- No production code change expected (the event fires from `enroll()` which Partner already calls). This task is the **gap-closer proof**: integration test via `PartnerAPIController::createEnrollment` with a `trajectory_id` → assert a quote exists for the registration + auto-voucher applied against the attendee.
- If the test reveals `enroll()`'s `$options` doesn't thread `enrolled_by`/company context into the event, fix that here.
- **Test-tier:** Tier A (the money gap this whole feature closes). **Test-author: split.**
- **Test:** RED-first — Partner trajectory enroll → `getQuoteByRegistration` returns a quote; auto-voucher `used_count` moved against the attendee.

**Task 5 — Migrate `AutoVoucherTrajectoryTest` seam from reflected private method to the event**
- The existing test drives `createTrajectoryQuote` via `ReflectionMethod` (`:414`). That method is deleted in Task 3. Repoint `fireTrajectoryQuote` to `do_action('stride/trajectory/registration/created', [...])` after seeding pending-billing/registration, then read the quote. All 4 existing assertions (core, scope, denial, exhausted, no-stacking) must still pass unchanged — the contract is preserved, only the seam moves.
- **Test-tier:** Tier A (money contract preservation). **Test-author: split.**
- **Test:** the migrated suite is itself the test; assert green with no assertion weakened.

**Task 6 — Add release-symmetry integration test for the trajectory path**
- Mirror `VoucherReleaseIdentityTest` for trajectories: enroll (event) with attendee auto-voucher → cancel the trajectory quote → assert `used_count` reverses and keys on `voucher_redeemed_user_id` (the attendee).
- **Test-tier:** Tier A (money release symmetry). **Test-author: split.**
- **Test:** RED-first — cancel reverses the attendee-keyed redemption.

**── REVIEW GATE ── (Cluster 2: Tasks 3–6) — Tier FULL** (money path, Partner surface, regression preservation). `/integration` + `/code-review` + `/security-review`.

### Integration gate (whole feature)
- Full integration suite green (`STRIDE_TEST_DB_DISPOSABLE=1`).
- `AutoVoucherTrajectoryTest`, the new Partner-trajectory-quote test, the double-create test, and the release-symmetry test all green.
- Shake-out §8 acceptance flows driven.

## 8. Acceptance flows (gate 1g)

| Flow | Steps | Edges enumerated |
|---|---|---|
| **F1 — Web-form trajectory self-enroll → quote** | User submits trajectory enrollment form → registration + exactly one quote → auto-voucher applied if type grants one. | empty: **free trajectory → no quote (legitimate skip), enroll SUCCEEDS, NOT cancelled**; denied: blocked profile type → enroll refused before event (INV-12, unchanged); re-entry: double-submit → one quote (idempotency guard); concurrent: two form posts → one quote; boundary: exhausted auto-voucher → quote without discount; mid-flow: **priced** trajectory quote fails → enroll rolled back (cancel + error); mid-flow: handler throws internally → swallowed, enroll returns normally (A3). |
| **F2 — Partner-API trajectory enroll → quote (the gap)** | Partner POSTs enrollment with `trajectory_id` → registration + quote + attendee-keyed auto-voucher. | empty: no company affiliation → 422 (unchanged); denied: user in another company → 403 (unchanged); boundary: attendee type with no voucher rule → quote, no discount; concurrent: two Partner enrolls same user → `already_enrolled` (existsForTrajectory guard, unchanged). |
| **F3 — Cancel trajectory quote → voucher released** | Cancel a trajectory quote carrying an attendee auto-voucher → `used_count` reverses against the attendee. | mid-flow: release keys on `voucher_redeemed_user_id` not payer. |

## 9. Files touched

| File | Change |
|---|---|
| `Modules/Trajectory/TrajectorySelection.php` | Add `stride/trajectory/registration/created` dispatch in `enroll()`; thread `enrolled_by`. |
| `Handlers/TrajectoryQuoteHandler.php` | **New** — event handler with idempotency guard + attendee auto-voucher. |
| `Handlers/EnrollmentFormHandler.php` | Remove inline `createTrajectoryQuote` call + method; persist pending-billing before `enroll()`. |
| `stride-core.php` | Register `TrajectoryQuoteHandler` in `$handlers`. |
| `tests/Integration/Modules/Invoicing/AutoVoucherTrajectoryTest.php` | Migrate seam to the event. |
| `tests/Integration/…/PartnerTrajectoryQuoteTest.php` | **New** — gap-closer. |
| `tests/Integration/…/TrajectoryVoucherReleaseTest.php` | **New** — release symmetry. |
| `Modules/Trajectory/TrajectoryCascadeService.php` | **Read-dependency only** (no edit) — `maybeCreateChildQuote:697` suppresses child quotes for priced trajectories; the priced-trajectory rollback (Task 3) protects the invariant that "parent quote covers children." Named so the reviewer checks the coupling, not re-discovers it. |

## 10. Convergence contract

The Threat model (§4), WP security requirements (§5), and layering requirements (§6) are the convergence targets for `/code-review`, `/security-review`, and `ntdst-drift-reviewer` at each REVIEW GATE and at shake-out. A gap is a one-line finding keyed to a named item (A1–A4, a named pillar, a named INV), not a re-discovery.
