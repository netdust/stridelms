# Shake-out Flow #4: Post-course completion + certificate

**Date:** 2026-05-18
**Scope:** Every path that transitions a registration to `completed`, plus certificate availability.
**Status:** COMPLETE — 12 scenarios, **1 critical bug fixed (CP-1), 1 important architectural finding (CP-2 reverted)**. Full regression: B-H phases + 4 flows green, 867 unit tests pass.

---

## Completion paths (verified)

### Path A: Attendance-driven (in-person courses)

```
1. Admin marks attendance → fires stride/attendance/marked
2. EditionCompletion::onAttendanceMarked checks isComplete()
3. If complete → processCompletion():
     a. If post-course tasks configured: initialise + fire attendance_complete event
     b. Otherwise: call learndash_process_mark_complete (LD enforces its own rules)
        → if LD lessons are open, this returns false silently
        → LD's learndash_course_completed action does NOT fire
        → onLearnDashCourseCompleted listener does NOT run
        → Stride registration STAYS at 'confirmed'
```

### Path B: LD-direct (e-learning)

```
1. User finishes course in LearnDash → learndash_course_completed fires
2. onLearnDashCourseCompleted flips Stride registration to Completed
```

### Path C: Post-course task path
```
1. Path A initialised post-course tasks (deferred)
2. User completes post_evaluation / post_documents / post_approval
3. CompletionTaskHandler::onTaskCompleted detects isFullyComplete
4. Calls processCompletionFinal → marks LD complete + flips Stride registration
```

### Path D: Admin manual flip
```
$regRepo->updateStatus($regId, RegistrationStatus::Completed)
   sets status + completed_at directly (idempotent on re-call after D4 fix).
   This is the escape hatch when the other paths can't complete due to LD content.
```

---

## Bugs found

### BUG-CP-1: `onLearnDashCourseCompleted` crashes on array vs WP_Post mismatch [FIXED]

**Symptom:** When LearnDash fires `learndash_course_completed`, the Stride listener crashes with `TypeError: Argument #2 ($editionId) must be of type int, null given`. Path B was completely broken.

**Root cause:** `EditionRepository::findByCourse()` returns `array<array<string, mixed>>` with key `'id'` (lowercase). Listener iterated with `foreach ($editions as $edition) { ... $edition->ID }` — object access on array yields `null`, then `findByUserAndEdition($userId, null)` throws TypeError.

**Production impact (pre-fix):** Every e-learning course completion would have thrown a fatal in the listener. LD might have swallowed it; even if not, the Stride registration would never have flipped to `completed`. Combined with BUG-CP-2 (below), this means **no in-person attendance OR e-learning completion was actually updating the Stride registration as designed**.

**Fix:** Iterate as arrays, use `(int) ($edition['id'] ?? $edition['ID'] ?? 0)`, skip if zero.

**Files:** `EditionCompletion.php:226-245`

### BUG-CP-2: NOT A BUG — architectural finding [DOCUMENTED]

**Initial finding:** Attendance-driven completion didn't flip the Stride registration to `completed`. After marking 3/3 sessions present, status stayed at `confirmed`.

**My initial fix (REVERTED):** I made `processCompletion` flip the registration directly, bypassing LD.

**Stefan's correction:** "did you check if LD had open lessons or something? you can't complete course if lessons are open." Correct — that's LD enforcing its own progression rules. Bypassing it would let users be marked `completed` without doing required LD content. That's worse than the bug.

**Reverted state:** `processCompletion` calls `learndash_process_mark_complete()` and trusts LD. If LD blocks (open lessons / quizzes / required steps), the Stride registration stays at `confirmed`. The completion event still fires for downstream listeners that want to know "Stride says attendance is done."

**Architectural implication:** For in-person courses, the linked LD course MUST be content-free (no required lessons or quizzes). Otherwise attendance alone won't transition the registration. Every seed edition in this codebase has at least one LD lesson, so the happy path of "attendance alone → completed" is not currently testable with seed data — but the code path is correct.

**Deferred decisions:**
1. Audit which production VAD courses are content-bearing vs attendance-only. The seed data may not reflect production reality.
2. Decide on certificate strategy for in-person courses where LD never marks complete: (a) Stride-native cert generation, or (b) admin-marked manual completion that bypasses LD enforcement intentionally.

---

## Test scenarios — all passing

| # | Scenario | Result |
|---|---|---|
| CP-1 | `isComplete()` returns false with no attendance | ✅ |
| CP-2 | `getProgress()` returns full shape | ✅ |
| CP-3 | `processCompletion()` returns `not_complete` when threshold not met | ✅ |
| CP-4 | Full attendance fires `stride/completion/completed` event AND respects LD constraints (reg stays `confirmed` when LD has open lessons) | ✅ |
| CP-5 | Direct `updateStatus(Completed)` flips status + sets `completed_at` (admin escape hatch) | ✅ |
| CP-6 | D4 regression — `updateStatus(Completed)` is idempotent on `completed_at` | ✅ |
| CP-7 | E7 regression — cancel completed → `already_completed` | ✅ |
| CP-8 | Post-course tasks configured → `attendance_complete` fires + tasks initialized | ✅ |
| CP-9 | Complete final post-course task → registration flips to `completed` | ✅ |
| CP-10 | `onLearnDashCourseCompleted` listener — flips reg to completed (after BUG-CP-1 fix) | ✅ |
| CP-11 | `getCertificateLink` returns empty for non-complete user | ✅ |
| CP-12 | Re-firing LD-complete on already-completed reg is idempotent | ✅ |

---

## Architectural risks worth flagging

### LD owns "course completion" — Stride defers
The flow as designed makes LD the authoritative source for "is this user done?" Stride layers attendance on top, but only translates attendance into completion via LD's blessing. For VAD's actual production state, this means:

- **E-learning courses** (LD-driven): Works fine. User finishes lessons → LD fires `learndash_course_completed` → Stride flips. Path B + BUG-CP-1 fix.
- **In-person courses with LD lessons**: Stride attendance reaches threshold → tries to mark LD complete → LD refuses if lessons open → Stride stays confirmed. **User never gets the "course completed" mail or certificate.** Need either (a) admin manually flips (Path D), (b) lessons configured as optional, or (c) Stride-native completion bypass for in-person editions.
- **In-person courses with no LD lessons** (content-free LD shell): Works. Attendance threshold → `processCompletion` → LD mark-complete succeeds → reg flips. But none of the seed editions test this — it's the assumed-clean path.

### Certificates have the same constraint
`LearnDashHelper::getCertificateLink` checks LD's `learndash_course_completed()`. For courses where Stride says complete but LD doesn't, **no certificate link**. The user gets the mail (if it fires) but the dashboard certificate section is empty.

### Recommendation
Before launch, audit the VAD course config:
1. Which courses are pure e-learning? (Path B, works)
2. Which are pure in-person with attendance only? (Need content-free LD shell)
3. Which mix attendance + LD lessons? (Currently broken-by-design — pick a model)

This is a product decision, not a code bug.

---

## Cumulative bug count this session

| Source | Bugs | Fixed |
|---|---|---|
| Phase A-H lifecycle | 6 (RL-1..6) | 6 |
| D4/E7/Withdrawn product changes | 0 + 3 changes | 3 |
| Flow #1 (Interest/Waitlist) | 4 (IW-1..4) | 4 |
| Flow #2 (Enrollment tasks) | 1 (ET-1) | 1 |
| Flow #3 (Cancellation cascade) | 0 | n/a |
| Flow #4 (Completion + certificate) | 1 real (CP-1) + 1 architectural finding | 1 |
| **Total** | **12 bugs + 3 changes + 1 architecture finding** | **all bugs fixed** |

---

## Files changed in Flow #4

- `Modules/Edition/EditionCompletion.php` — fixed array vs object access in `onLearnDashCourseCompleted` (BUG-CP-1)
- `tests/manual/shake-helpers.php` — post-course meta reset in `shake_reset_editions`
- `tests/manual/shake-cleanup.php` — attendance + audit cleanup
- `tests/manual/shake-flow-completion.php` — 12 scenarios for regression

## Open architectural questions for Stefan

1. Are VAD's in-person courses configured with required LD lessons, or are they content-free LD shells?
2. If lessons are required: what should the certificate model be when LD won't issue?
3. Should there be an admin "Force complete" button on the registration that bypasses LD enforcement when an admin manually confirms a user attended even though LD content is unfinished?
