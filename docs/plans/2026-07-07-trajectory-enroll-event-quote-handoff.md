# Handoff: Unify trajectory enrollment onto the event-driven quote path

**Date:** 2026-07-07
**Status:** Backlog — surfaced by the profiletype-enroll-gate whole-branch review (PR #7), deferred by Stefan as its own feature.
**Class:** A (multi-task; money + a new event surface → needs a threat model).
**Prereq:** none blocking; the profiletype-enroll-gate branch can merge first.

---

## 1. The problem (one sentence)

**Edition enrollment produces a quote on *every* path (it's event-driven); trajectory enrollment produces a quote only via the web form (it's inline) — so a Partner-API trajectory enroll creates a registration with *no quote at all*, and the profile-type auto-voucher silently never fires for it.**

The auto-voucher was only the symptom that surfaced this. The real inconsistency is: **`enroll → quote` is one system for editions and a different system for trajectories.**

---

## 2. Current shapes (ground-truthed 2026-07-07)

### Edition (event-driven — the target shape)
- `EnrollmentService::enroll()` creates the registration, then **dispatches `stride/registration/created`** (`EnrollmentService.php:379`).
- `EnrollmentQuoteHandler::onRegistrationCreated()` (`Handlers/EnrollmentQuoteHandler.php:31`) listens, builds the quote, and applies the auto-voucher (`:146`).
- **Idempotency guard:** `getQuoteByRegistration($registrationId)` early-returns if a quote already exists (`:66`) — so re-fired events / double paths never double-create.
- Coverage: web form, direct-URL, Partner API (`PartnerAPIController.php:678`), colleague/bulk — ALL get a quote because they all route through `enroll()`.

### Trajectory (inline — the divergent shape)
- `TrajectorySelection::enroll()` creates the registration, dispatches only **`stride/trajectory/enrolled`** (`TrajectorySelection.php:87`) — a *notification*, NOT a quote trigger.
- The quote is created **inline** by `EnrollmentFormHandler::createTrajectoryQuote()` (`Handlers/EnrollmentFormHandler.php:305`), which is reached **only from the web enrollment form**.
- Partner-API trajectory enroll (`PartnerAPIController.php:685`) calls `TrajectorySelection::enroll()` → registration created → **no quote, no auto-voucher**.

---

## 3. The fix (the shape to build)

Make trajectory quoting event-driven, mirroring editions:

1. **`TrajectorySelection::enroll()` dispatches a quote-triggering event** — either reuse `stride/registration/created` with a trajectory-shaped payload, OR add `stride/trajectory/registration/created`. **Decision point (see §6):** reusing the edition event is simpler for the handler but the payload differs (trajectory_id vs edition_id, no edition price → trajectory price, different item shape). Leaning: a **dedicated `stride/trajectory/registration/created`** event handled by a trajectory-quote handler, so the edition handler stays clean and the two payloads don't have to be union-typed.

2. **Extract `createTrajectoryQuote` into an event handler** — move the quote-building logic out of `EnrollmentFormHandler::createTrajectoryQuote` into a handler on the new event (a `TrajectoryQuoteHandler` sibling to `EnrollmentQuoteHandler`). The web-form path stops calling it inline and instead relies on the event firing from `enroll()`.

3. **Add the idempotency guard** — the new handler MUST call `getQuoteByRegistration($registrationId)` and early-return if a quote exists, exactly like the edition handler (`EnrollmentQuoteHandler.php:66`). This is the load-bearing safety: the web-form path and the event must not double-create. **This is the highest-risk part** — the edition path proves it works; copy that guard verbatim.

4. **Auto-voucher rides along for free** — once the trajectory quote is created in an event handler, add the same auto-voucher block the edition handler has (resolve `ProfileTypePolicy::autoVoucherCode($userId, $trajectoryId, 'vad_trajectory')`, apply via `applyVoucher(..., editionScoped: false)`), so Partner-API trajectory enrolls get their voucher. **Use `$redeemAsUserId: $userId` (the attendee)** — the profiletype-enroll-gate branch established that redeem/release must key on the attendee for bulk enrolls, and persists `voucher_redeemed_user_id` on the quote (see that branch's `QuoteService::applyVoucher`). The trajectory handler must do the same.

---

## 4. Threat model triggers (gate 1a — REQUIRED)

This touches **money on a new path** (Partner-API trajectory quoting that didn't exist before) → the threat model gate fires. Named assets/attacks to cover:
- **Double-quote / double-redeem** — the web-form path + the event both firing for one registration. Mitigation = the `getQuoteByRegistration` idempotency guard (§3.3); the threat model must assert it and a test must prove concurrent/re-entrant enroll doesn't double-create.
- **Auto-voucher on the new Partner path** — a Partner enrolling a colleague into a trajectory now triggers a voucher redemption. Same money-boundary rules as the edition path: redeem against the attendee, `voucher_redeemed_user_id` persisted, release symmetric (the exact bug PR #7 fixed — do NOT reintroduce it on the trajectory path).
- **Trajectory price integrity** — confirm the event handler computes the trajectory price correctly (the inline path used `createTrajectoryQuote`'s pricing; the event handler must match, not regress).

---

## 5. Test coverage the fix needs (don't ship without)

- **Partner-API trajectory enroll → quote created** (the gap this closes). Integration test via `PartnerAPIController::createEnrollment` with a `trajectory_id` → assert a quote exists for the registration.
- **No double-create:** web-form trajectory enroll (which now ALSO fires the event) creates exactly ONE quote, not two. Assert `getQuoteByRegistration` returns a single quote after the web-form path.
- **Auto-voucher on the trajectory event path** — bulk/colleague trajectory enroll with a voucher-granting type → voucher applied + redeemed against the attendee + `used_count` moves per attendee (mirror `AutoVoucherTrajectoryTest`, extend to the Partner/event path).
- **Redeem/release symmetry on the trajectory path** — cancel a trajectory quote with an attendee-redeemed auto-voucher → `used_count` reverses (mirror `VoucherReleaseIdentityTest` for trajectories).
- **Regression:** the existing web-form trajectory quote + voucher behavior is unchanged (the inline→event move must be behavior-preserving for the web path).

---

## 6. Open decisions for whoever picks this up

1. **Reuse `stride/registration/created` vs a new `stride/trajectory/registration/created`?** — leaning new/dedicated (cleaner payloads, no union-typing the edition handler). Decide at plan time.
2. **Keep `createTrajectoryQuote` as a thin wrapper the event handler calls, or fully move the logic into the handler?** — the edition path has the logic *in* the handler; match that. The web-form's `EnrollmentFormHandler` should stop building the quote inline and let the event do it (it already sets up pending-billing/selection state before enroll, which the handler reads — confirm that ordering survives the move: the event fires from `enroll()`, so any pending state the web form sets must be persisted BEFORE `enroll()` is called, same as the edition web-form path already does).
3. **Sequencing with the existing `stride/trajectory/enrolled` event** — that notification event stays; the new quote event is additional. Confirm no handler assumes ordering between them.

---

## 7. Why this is its own feature (not part of profiletype-enroll-gate)

Per Stefan (2026-07-06): profiletype-enroll-gate ships first with the trajectory auto-voucher documented as web-form-only. This unification is larger (a new event surface + moving the quote-creation seam + its own threat model + Partner-API-trajectory-quote coverage) and touches the money path independently of the profile-type feature. Trajectories "don't really need auto-voucher," but the `enroll → quote` asymmetry is worth fixing for consistency — and it's the *right* fix rather than bolting a second inline auto-voucher onto the trajectory web path.

**Related:**
- Memory: `project_trajectory_quoting_not_event_driven.md`
- Edition reference implementation: `EnrollmentQuoteHandler.php` (the handler to mirror), `EnrollmentService.php:379` (the dispatch to mirror).
- Money-identity rules established in PR #7: `QuoteService::applyVoucher` (`$redeemAsUserId` + `voucher_redeemed_user_id`), `VoucherReleaseIdentityTest`.
