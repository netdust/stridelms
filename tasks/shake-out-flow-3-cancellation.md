# Shake-out Flow #3: Cancellation cascade

**Date:** 2026-05-18
**Scope:** Every side-effect that fires when a registration or quote is cancelled. Includes idempotency, anonymous rows, loop safety, audit recording.
**Status:** COMPLETE — 11 scenarios, **0 code bugs found** (1 test-setup bug fixed). 867 unit tests + all flows green.

---

## Cascade map (verified)

### Registration cancel → these listeners fire (priority 10, in registration order)

| Listener | Module | Side effect |
|---|---|---|
| `EnrollmentService::cancel()` (the caller) | Enrollment | Writes `status=cancelled`, `cancelled_at`, `completion_tasks=NULL`. Revokes LMS access via `LMSAdapterInterface::revokeAccess()`. Fires the event. |
| `AuditBridge::onRegistrationCancelled` | Audit | Inserts `audit_log` row (`entity_type=registration`, `action=registration.cancelled`). Actor falls back to `get_current_user_id()`. |
| `QuoteService::onRegistrationCancelled` | Invoicing | Cancels the linked quote via `repository->updateStatus(Cancelled)` (no event dispatch — avoids loop). Skips if quote already Cancelled or Exported. |
| `EditionService::onRegistrationCancelled` | Edition | Invalidates seat-count cache. If edition was Full and a seat is now free, flips it back to Open. |
| Mail trigger `stride/registration/cancelled` | Mail | Dispatches `stride-enrollment-cancelled` template to the user. |
| `AdminDashboardService` invalidateQueue | Admin | Cache invalidation for dashboard. |

### Quote cancel (admin-side) → cascades through:

| Trigger | Listener | Side effect |
|---|---|---|
| `QuoteAdminController` line 362 fires `stride/quote/cancelled` | `EnrollmentService::onQuoteCancelled` | Calls `EnrollmentService::cancel(reg)` which triggers the full registration-cancel cascade above. |

### Loop safety
The reverse path (registration cancel → quote cancel) uses raw `QuoteRepository::updateStatus()` which does NOT dispatch `stride/quote/cancelled`. So canceling a registration fires `registration/cancelled` exactly once and `quote/cancelled` zero times. Verified in CC-10.

---

## Test scenarios — all passing

| # | Scenario | Result |
|---|---|---|
| CC-1 | Cancel confirmed reg → status + cancelled_at + LMS revoke + quote cancel + audit + event fires once + tasks cleared | ✅ |
| CC-2 | Cancel reg on Full edition → edition auto-flips Full→Open | ✅ |
| CC-3 | Cancel pending reg with tasks → `completion_tasks` cleared to NULL (BUG-ET-1 regression) | ✅ |
| CC-4 | Cancel interest row (no quote, no LMS) → graceful, no crash | ✅ |
| CC-5 | Cancel waitlist row → graceful | ✅ |
| CC-6 | Cancel anonymous row (user_id=NULL) — listeners don't crash on missing user | ✅ |
| CC-7 | Cancel already-cancelled → `already_cancelled` error (E7 regression) | ✅ |
| CC-8 | Cancel completed → `already_completed` error (E7 regression) | ✅ |
| CC-9 | Admin cancels quote → cascades to register cancel | ✅ |
| CC-10 | No infinite event loop between reg cancel ↔ quote cancel | ✅ |
| CC-11 | Audit row records actor via `get_current_user_id()` fallback | ✅ |

---

## What was almost a bug (test-setup issue, not code)

**Initial CC-2 run reported FAIL** because edition 13240 already had 8 confirmed seed registrations in the DB. My test added 8 more filler rows, then cancelled one — total still 15 confirmed against capacity 8, so the edition correctly stayed Full. The auto-flip listener was working as designed; my test fixture was contaminated.

**Fix:** moved CC-2 to edition 13257 which has no pre-existing registrations.

**Lesson:** test scripts that rely on capacity math need to either (a) wipe ALL rows for the edition first, or (b) calculate the dynamic threshold (capacity - existing_confirmed) to figure out how many filler rows to add.

---

## Findings worth recording (not bugs)

### Mail to anonymous cancelled rows
When an anonymous interest/waitlist row gets cancelled, the `stride/registration/cancelled` event fires and `StrideMailBridge` dispatches `stride-enrollment-cancelled`. That template uses `{{user.first_name|klant}}` as fallback when there's no user — the dispatch will resolve to "klant" and try to send. The `to` address comes from the standard mail dispatch which expects a registered user. For anonymous rows that resolution likely produces an empty `to` and the mail fails silently. Not a bug today (silent failure isn't user-facing), but if you want anonymous cancel confirmation mails to actually send, the trigger-based dispatch needs to learn the `enrollment_data.{status}.email` fallback that `getEditionRegistrations` now uses.

### Audit context shape
`AuditBridge::onRegistrationCancelled` records `{user_id, edition_id}` in context. It does NOT record the prior status (the reg was confirmed/pending/interest/waitlist before cancel). If you want a forensic record of "what state was lost", consider passing prior_status in the event payload or recording it in the audit context.

### Quote auto-cancel skips Exported quotes
`QuoteService::onRegistrationCancelled` line 108: if quote is `Exported`, it logs a warning but does NOT cancel the quote. The registration still cancels. Means: admin who exported a quote then cancels the underlying registration ends up with a mismatched state (registration=cancelled, quote=exported, possibly already billed to Exact Online). The warning log is the only signal. Worth noting for future "what to do when quote was exported" decision.

---

## Cumulative bug count this session

| Source | Bugs found | Fixed |
|---|---|---|
| Phase A-H lifecycle shakeout | 6 (RL-1..6) | 6 |
| D4/E7/Withdrawn cleanup | 0 bugs, 3 product changes | 3 |
| Flow #1 (Interest/Waitlist surface) | 4 (IW-1..4) | 4 |
| Flow #2 (Enrollment tasks) | 1 (ET-1) | 1 |
| Flow #3 (Cancellation cascade) | 0 | n/a |
| **Total** | **11 bugs + 3 changes** | **all** |

---

## Files changed in Flow #3

- `tests/manual/shake-flow-cancellation.php` — 11 scenarios for regression

No production code changes — the cascade is solid.

## Open items for next flows

- Flow #4: Post-course completion + certificate flow (the part that flips `confirmed` → `completed`)
- Flow #5: Data exportability sweep
