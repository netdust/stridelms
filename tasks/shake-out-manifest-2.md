# Shake-out Manifest #2 — 2026-05-16

Scope: attendance tracking, completion tasks (enrollment + post-course), invoicing (quotes), email notifications.
Status: **PHASE 2 — awaiting human sign-off**. No fixes applied during sweep.

---

## Bugs found

### CRITICAL

#### B2-001 — `getHoursAttended` returns 0 (meta key prefix mismatch)

**Symptom:** `AttendanceService::getHoursAttended($user, $edition)` returns `0.0` for users who attended sessions. Expected: sum of session durations.

**Reproduce:** mark user present for a session 09:00-17:00 → query returns 0 hours.

**Root cause:** `SessionService::getTotalDurationForSessions()` (line 175) queries postmeta with `meta_key = 'start_time'` / `meta_key = 'end_time'` — but Stride session meta is prefixed: `_ntdst_start_time` / `_ntdst_end_time`. The query matches zero rows → 0 hours returned.

**Impact:**
- Anywhere attendance hours are displayed (admin reports, certificates with hour count, user dashboard) shows 0 — visually wrong.
- Doesn't block enrollment or attendance recording itself.
- `getAttendanceRate` works (it uses session count, not hours).
- `countAttended` works.

**Fix proposal:** add `_ntdst_` prefix to both meta keys in the query — 2 character change. Add a unit test that mocks two sessions with known durations and asserts the sum.

**Files:**
- `Modules/Edition/SessionService.php` line 188 + 189

---

### IMPORTANT

#### B2-002 — `stride/completion/attendance_complete` trigger has no template

**Symptom:** the trigger is registered (`StrideMailBridge::registerTriggers`) but no `ndmail_template` post is bound to it. When it fires, nothing happens.

**Impact:** users who complete a course purely via attendance (no post-course tasks) don't get a notification. The "Opleiding voltooid" template (#8042) is bound to `stride/completion/completed` which is the multi-task completion path.

**Decision needed from user:**
- **a.** Bind template #8042 to BOTH triggers (one shared "course done" message)
- **b.** Create a distinct attendance-completion template
- **c.** Drop the trigger if attendance-only completion isn't a real path in v1

**Fix proposal:** depends on (a)/(b)/(c) above. (a) is two minutes.

---

### MINOR

#### B2-003 — Voucher replace semantics undocumented

**Symptom:** applying a second voucher to a quote replaces the first (does NOT stack). Tested:
- KORTING50 (€50 fixed) on €795 → discount €50, total €901.45
- Then WELKOM2026 (10%) on same quote → discount recalculated on original €795 (not €745), total €865.76

**Status:** functionally correct (one voucher per quote = sane default) but the behaviour is implicit. Admin or user pressing "apply" a second time may not realise the first voucher is gone.

**Fix proposal:** none for code. Document in admin/user docs. Optional: UI confirmation "Vervang KORTING50 door WELKOM2026?".

---

## NOT bugs (investigated, false alarms)

- **Admin notification templates with `trigger=(none)`** — initially flagged as broken. Turns out admin notifications are dispatched via direct `ndmail_send('stride-enrollment-created-admin', ...)` calls in `StrideMailBridge`, using template slug, not trigger meta. Mailpit confirmed 3 mails per `stride/registration/created` event.
- **`completeTask` with locked task returns true** — initial concern. Actually returns `WP_Error: task_locked`. Gating is enforced.
- **`createQuote` doesn't generate PDF on create** — flagged as suspicious. By design: PDF render was moved off the request thread (perf fix `8a54c475`, audit H1). PDF is rendered on demand at download time.
- **`re-mark same session`** — initial concern about duplicates. Actually idempotent UPDATE — returns same `attendance_id`, status updates.

---

## Summary

| Severity | Count | Phase 1 launch blocker? |
|----------|-------|-------------------------|
| CRITICAL | 1 | **Yes** — B2-001 hours = 0 affects every certificate / report |
| IMPORTANT | 1 | Decision-dependent (B2-002) |
| MINOR | 1 | No |

**Phase 3 order:**
1. **B2-001** — meta key prefix fix + regression test
2. **B2-002** — get user decision (a/b/c) then 2-min binding fix
3. **B2-003** — doc-only, defer

---

## What worked vs what hit ruis

**Worked:**
- Throwaway PHP scripts in `scripts/_sweep-*.php` (deleted after sweep) for fast probing
- Cross-checking against memory's `audit_2026_05_14_findings` to skip already-tracked bugs
- Mailpit as ground-truth for email side-effects

**Hit ruis:**
- Twice took filter name / meta key from memory instead of grepping for the actual name → false-positive E-001 / E-003 on first run. Lesson: never trust remembered API surface names; grep first, claim later.
