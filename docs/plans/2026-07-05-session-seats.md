# Per-Session Seat Capacity — Simple, Mirrors Edition Capacity

**Date:** 2026-07-05
**Goal (Stefan):** A session can have available seats, much like an edition does. When users select sessions, a full session can't be picked. Keep it SIMPLE, based on what exists. Don't duplicate the edition-seat gate.
**Scope class:** A (new feature) — but deliberately small.

---

## What exists (ground truth)

- **`vad_session` already has a `capacity` field** (`SessionCPT.php:85-89`, int, 0 = unlimited) — but it's ORPHANED: no admin input renders it, and `sanitizeSessionData` (`EditionAdminController.php:1181`) doesn't save it. `SessionService` already READS it into the session array (`:212, :265`), always 0 today.
- **Edition seat gate (the pattern to mirror, NOT duplicate):** `EditionService::hasAvailableSpots($editionId)` (`EditionService.php:88-100`) = `getCapacity` (0 = unlimited) → `getRegisteredCount` (COUNT on registrations by `edition_id`, 60s transient) → `registered < capacity`.
- **Trajectory has the SAME shape:** `TrajectorySelection::hasCapacity` (`:87-103`) — capacity 0 = unlimited → count → compare. So there are already 2 copies of this gate logic; adding a session copy would make 3.
- **Selection submit has TWO paths** (the correctness trap): `CompletionTaskHandler` (`:80-83`) writes selections DIRECTLY via `RegistrationRepository::setSelections`, BYPASSING `SessionSelection::setSelections`; the API path (`EnrollmentFormHandler:549`) goes THROUGH `SessionSelection::setSelections`. A gate in only one place misses the other.
- **Selections storage:** JSON array of session ids in `{prefix}vad_registrations.selections` (`RegistrationTable.php:67`). No per-session column → counting picks needs `JSON_CONTAINS`.
- **Concurrency:** edition + trajectory capacity gates are NOT transaction-locked (plain read-then-decide, racy on the last seat). Matching that keeps the session gate consistent + simple.

---

## Design — mirror the edition pattern, don't build a parallel system

**D1 — NO shared helper (reassessed with Stefan — avoid over-engineering).** The gate is 3 trivial lines (`capacity 0 = unlimited; count < capacity`). The COUNTS are already necessarily different (edition = `edition_id` column; session = `JSON_CONTAINS`), so a helper would share only the trivial compare — extracting that plus a count-closure adds more indirection than it removes, and retrofitting 2 working gates is risk for tidiness. "Don't duplicate the edition gate" is satisfied the REAL way: session reuses the edition PATTERN and does not build a parallel seat-counting system. `SessionService::hasAvailableSeats` mirrors `EditionService::hasAvailableSpots` inline (~3 lines). Existing edition + trajectory gates untouched.

**D2 — Session count via JSON_CONTAINS, mirroring getRegisteredCount's cache.** New `SessionService::getSelectedCount(int $sessionId)`: count registrations whose `selections` JSON contains the session id, scoped to active statuses (confirmed/completed/pending) and the session's edition. Transient-cached 60s (`stride_session_sel_count_<id>`) exactly like `getRegisteredCount`; invalidate on selection write. `hasAvailableSeats(int $sessionId)` = inline `getCapacity===0 ? true : getSelectedCount < capacity` — the same shape as `EditionService::hasAvailableSpots:88-100`.

**D3 — Gate at the convergence point that BOTH submit paths pass.** Both paths ultimately call `RegistrationRepository::setSelections` — but that's a dumb writer (wrong layer for business rules). Instead: route the completion path through `SessionSelection::setSelections` (so validation is centralized) OR add the seat check to a shared validation both call. Preferred: make `SessionSelection::setSelections` the single write path, add `hasAvailableSeats` there, and change `CompletionTaskHandler:80-83` to call `SessionSelection::setSelections` instead of `$repo->setSelections` directly. This removes the bypass AND centralizes the gate. (If routing the completion path is too invasive, fall back to duplicating the gate call in both — but the routing is the non-duplicative fix.)

**D4 — Concurrency: match editions (no lock).** Editions and trajectories don't lock; the session gate won't either. The last-seat race is a known, shared limitation — document it, don't solve it now (keeps it simple; can add the VoucherService FOR-UPDATE pattern later if oversell becomes real).

**D5 — Retrofit edition + trajectory to the helper? OPTIONAL / deferred.** The feature only needs the helper + session wiring. Retrofitting `EditionService::hasAvailableSpots` and `TrajectorySelection::hasCapacity` to call `CapacityGate::hasRoom` removes the existing duplication (the whole point of "don't duplicate") — do it IF low-risk (both are covered by tests), but it touches working code with many callers (`EditionService.php:262,817,835`, `EnrollmentFormResolver:105`, etc.). Recommend: retrofit in THIS PR since it's the non-duplication payoff, guarded by the existing capacity tests. Confirm with Stefan (Q1).

---

## Decision (Stefan)

- **No shared helper, no retrofit.** Session mirrors the edition gate inline; existing edition/trajectory gates stay as-is (challenged the helper as over-engineering — correct: the counts differ anyway, so a helper shares only a trivial compare). Feature is 5 tasks, not 6.

---

## Tasks

### Task 1 — Session capacity: admin input + save  `Test-author: split`
- Add a "Capaciteit (0 = onbeperkt)" input to the session add/edit form (`EditionSessionsMetabox.php`, the shared fields near date/time — capacity applies to all session types).
- Add `'capacity' => absint($input['capacity'] ?? 0)` to `sanitizeSessionData` (`EditionAdminController.php:1181`).
- Test: extend `SessionAdminAjaxTest` — posting capacity persists; absent → 0.

### Task 2 — getSelectedCount + hasAvailableSeats  `Test-author: split`
- `SessionService::getSelectedCount(int $sessionId): int` — JSON_CONTAINS count over active registrations, 60s transient, invalidated on selection write.
- `SessionService::hasAvailableSeats(int $sessionId): bool` — inline, mirroring `EditionService::hasAvailableSpots:88-100`: `$cap = $this->getCapacity($sessionId); if ($cap === 0) return true; return $this->getSelectedCount($sessionId) < $cap;`.
- Test (integration): seed registrations selecting a capacity-2 session → hasAvailableSeats true at 1, false at 2; capacity 0 → always true.

### Task 3 — Gate the selection write (both paths)  `Test-author: split`
- Add the seat check to `SessionSelection::setSelections` (`:42-83`): for each newly-added session id, reject with `WP_Error('session_full', 'Sessie is vol')` if `!hasAvailableSeats`. Only enforce for NEWLY added picks (re-submitting an existing pick that the user already holds must not be blocked by their own seat).
- Route `CompletionTaskHandler:80-83` through `SessionSelection::setSelections` so the completion path is gated too (removes the bypass).
- Invalidate the session selection-count cache on every selection write.
- Test (integration): a user picking a full session is refused (`session_full`); a user already holding a pick can re-submit; both submit paths are covered.

### Task 4 — Frontend: show seats / disable full  `Test-author: solo`
- `task-session_selection.php` `$renderOption` (`:87-140`) and `partials/session-row.php` (reuse the reserved `not_chosen` seam): show "N plaatsen over" / "Vol", disable the checkbox for a full session the user doesn't already hold.
- Server-render first (no JS business logic). The gate is server-side (Task 4); this is display only.
- Test: covered by the acceptance/browser layer at shake-out; Tier B UI (no bespoke unit test) — assert the "Vol" state renders for a full session via the metabox/template render if cheap.

---

## Verification
- Unit + integration suites green (baseline 1305 / 882, only the known flakes — [[bug_admin_trajectory_options_test_pollution]] + paginationSlicesResults; `gh run rerun --failed` if either is the only CI failure).
- A full session cannot be picked via EITHER submit path.
- Capacity 0 = unlimited (count query short-circuited).
- If D5 retrofit done: existing edition/trajectory capacity tests still green (proves the helper is behavior-preserving).

## Out of scope (keep it simple)
- Concurrency/last-seat locking (matches editions — deferred).
- Waitlist-for-a-full-session (editions have waitlist; sessions just disable — add later if wanted).
- Any change to slot `max_selections` (per-USER pick count — orthogonal, untouched).
