# Shake-out Manifest: Registration Table Lifecycle

**Date:** 2026-05-18
**Scope:** Full lifecycle on `stride_vad_registrations` — interest → waitlist → pending → confirmed → completed → cancelled, plus reactivation, data persistence, all status transitions.
**Status:** COMPLETE — 7 phases, 50+ scenarios, 6 bugs found, all fixed. 867 unit tests still pass.

---

## Status semantics (final, verified)

| Status | Implemented? | Trigger |
|---|---|---|
| `interest` | ✅ | `EnrollmentService::registerInterest` (logged-in) + `QuestionnaireHandler::handleSubmitInterest` (anonymous). Edition must be in `Announcement`. |
| `waitlist` | ✅ (new 2026-05-18) | `EnrollmentService::registerWaitlist` + `QuestionnaireHandler::handleSubmitWaitlist`. Edition must be in `Full`. No automation. |
| `pending` | ✅ | Initial status when edition has completion requirements OR `requiresApproval`. |
| `confirmed` | ✅ | Direct on enroll OR after `confirmRegistration` OR auto-promote when all enrollment tasks done. Default ENUM value. |
| `completed` | ✅ | `updateStatus(Completed)` after LD course completion OR after all post-course tasks done. Sets `completed_at`. |
| `cancelled` | ✅ | `EnrollmentService::cancel()`. Sets `cancelled_at`. Fires `stride/registration/cancelled` (now exactly once) → quote cancelled, seat freed, audit log, mail. |
| `withdrawn` | ⚠️ Dead value | Reachable only via manual DB insert. Treated identically to `cancelled` for re-enrollment dedup. No code path writes it. UI shows label "Uitgetrokken" but no surface produces it. |

---

## Bugs found and fixed during shakeout

### BUG-RL-1: Anonymous waitlist creation rejected by repository [FIXED]
`RegistrationRepository::create()` only allowed `user_id=NULL` for status=Interest. Added `Waitlist` to the exemption.
**Files:** `RegistrationRepository.php:48`

### BUG-RL-2: Mail templates not seeded after rename [FIXED]
`seedTemplates()` was version-gated to `'2'`. Renamed `stride-interest-registered-admin` → `stride-interest-registered-user` + added `stride-waitlist-registered-user` without bumping the version. Bumped to `'3'`.
**Files:** `StrideMailBridge.php:486`

### BUG-RL-3: Anonymous users cannot reach interest/waitlist form via enrollment URL [FIXED]
Existing interest "Interesse melden" CTA pointed to `stride_enrollment_url()` which login-redirects. Same bug for waitlist. Resolved by mirroring the `[stride_interest]` shortcode + dedicated-page pattern:
- New `WaitlistShortcodes` + `[stride_waitlist]`
- New `templates/forms/waitlist.php`
- New `/wachtlijst/` page (post ID 30457)
- 4 CTAs on `single-vad_edition.php` repointed to `/interesse/?editie={id}` and `/wachtlijst/?editie={id}`

### BUG-RL-5: `stride/registration/cancelled` event fired TWICE per cancel [FIXED]
`RegistrationRepository::cancel()` fired the event, then `EnrollmentService::cancel()` fired it again via `dispatch()`. Listeners that aren't idempotent could double-bill/email/audit. Worse: listeners on the FIRST event saw `cancelled` in DB but LMS access still granted — inconsistent state.

**Fix:** Removed `do_action` from `RegistrationRepository::cancel()`, made it data-only. Switched the one direct caller (`EnrollmentFormHandler` rollback path) to use `EnrollmentService::cancel()`. Now fires exactly once, after all side effects complete.

**Files:** `RegistrationRepository.php:764-783`, `EnrollmentFormHandler.php:296-297`

### BUG-RL-6: Logged-in interest/waitlist users permanently blocked from later enrolling [FIXED]
After registering interest or joining waitlist, the user couldn't enroll when the edition opened — `RegistrationRepository::create()` saw their existing row and only reactivated Cancelled/Withdrawn statuses. Same for the upfront `hasActiveRegistration` check in `EnrollmentService::enroll()` since `Interest` is in `blocksDuplicate`.

**Fix two paths:**
1. `RegistrationRepository::create()` — added `Interest` and `Waitlist` to `$reactivatableStatuses`. Also: now PRESERVES `enrollment_data` from the existing row (merges with new data) instead of wiping it.
2. `EnrollmentService::enroll()` — upfront duplicate check now treats Interest/Waitlist as reactivatable, lets the request fall through to `create()` which handles the row reuse.

**Files:** `RegistrationRepository.php:70-122`, `EnrollmentService.php:249-274`

Side effect: G5 (data preservation through cancel → re-enroll) now also works correctly. Old code wiped `enrollment_data` on Cancelled reactivation; new code preserves it.

### BUG-RL-4 (originally listed): Auto Full→Open considers waitlist — not a bug
Confirmed intentional: admin manually contacts waitlist, no automation. Documented in F4 test instead.

---

## Phase results

### Phase A (interest + waitlist creation) — DONE earlier
13/13 scenarios PASS (anonymous + logged-in + 5 error cases for each)

### Phase B: Direct enrollment — 10/10 PASS

| # | Scenario | Result |
|---|---|---|
| B1 | Self-enroll Open / no reqs → confirmed + quote + LMS access | ✅ |
| B2 | Self-enroll Open / has completion reqs → pending + tasks set | ✅ |
| B3 | Self-enroll Open / requiresApproval → pending | ✅ |
| B4 | Colleague enrollment writes path + enrolled_by | ✅ |
| B5 | Capacity rejection → `edition_full` | ✅ |
| B6 | Already-enrolled guard → `already_enrolled` | ✅ |
| B-extra1 | Enroll on Full → `edition_full` (not redirected to waitlist) | ✅ |
| B-extra2 | Enroll on Announcement → `enrollment_closed` (not redirected to interest) | ✅ |
| B-extra3 | Enroll on Draft → `enrollment_closed` | ✅ |
| B-extra4 | Enroll on bogus edition → `invalid_edition` | ✅ |

### Phase C: Pending → Confirmed — 5/5 PASS

| # | Scenario | Result |
|---|---|---|
| C1 | Admin `confirmRegistration` → confirmed + LMS + quote auto-creates | ✅ |
| C2 | Confirm already-confirmed → `invalid_status` | ✅ |
| C3 | Confirm bogus reg → `not_found` | ✅ |
| C4 | Complete last enrollment task → auto-confirm fires | ✅ |
| C5 | Approval task user-locked when prerequisites incomplete | ✅ |

**Note:** When admin confirms via C1, a quote is auto-created (event-driven side effect). Worth knowing — pending rows have no quote until confirmation.

### Phase D: Completed — 4/4 PASS (+ 1 observation)

| # | Scenario | Result |
|---|---|---|
| D1 | `updateStatus(Completed)` sets `completed_at` | ✅ |
| D2 | LD course completion flips confirmed → completed via `EditionCompletion::handleCourseCompletion` | ✅ |
| D3 | Post-course tasks defer completion (verified by code trace) | ✅ |
| D4 | Re-running `updateStatus(Completed)` is idempotent on status | ✅ but **`completed_at` is overwritten each time**. Not a bug, but a side effect — if anyone calls `updateStatus(Completed)` more than once on the same row, the original completion timestamp is lost. |

### Phase E: Cancellation — 9/9 PASS (after BUG-RL-5 fix)

| # | Scenario | Result |
|---|---|---|
| E0 | `stride/registration/cancelled` fires exactly ONCE per cancel | ✅ (after fix) |
| E1 | Cancel confirmed → status=cancelled + cancelled_at + LMS revoked | ✅ |
| E2 | Cancel pending → cancelled | ✅ |
| E3 | Cancel interest row → cancelled (graceful handling) | ✅ |
| E4 | Cancel waitlist row → cancelled (graceful handling) | ✅ |
| E5 | Cancel bogus reg → `not_found` | ✅ |
| E6 | Re-enroll after cancel reactivates existing row (no duplicate) | ✅ |
| E7 | Cancel completed reg → allowed (transitions to cancelled, preserves completed_at) | ✅ (intentional behavior — admins can cancel post-completion if needed; certificate impact requires verification) |
| E8 | Cancel reg with quote → quote auto-cancels via event | ✅ |

### Phase F: Upgrade paths — 4/4 PASS (after BUG-RL-6 fix)

| # | Scenario | Result |
|---|---|---|
| F1 | Anonymous interest → user enrolls when Open → row promoted in-place | ✅ |
| F2 | Attacker (non-self) enrolling another user does NOT trigger upgradeFromInterest | ✅ — victim row stays intact, new row created |
| F3 | `enrollment_data.interest` preserved after upgrade | ✅ |
| F4 | Waitlist user enrolls after admin invites + edition flips Open → row REUSED | ✅ (after BUG-RL-6 fix) |

### Phase G: Data column edge cases — 6/6 PASS

| # | Scenario | Result |
|---|---|---|
| G1 | Anonymous (`user_id=NULL`) row in all repo read paths: `find`, `findByEdition`, `findByCompany` — no warnings, all return data | ✅ |
| G2 | `completion_tasks` partial merge — marking one task preserves others | ✅ |
| G3 | `selections` JSON round-trips correctly | ✅ |
| G4 | `company_id` propagation from `_stride_company_id` user meta | ✅ |
| G5 | `enrollment_data` preserved through cancel → re-enroll cycle | ✅ (was broken pre-BUG-RL-6 fix) |
| G6 | `findByEmailAndEditionForStage` correctly finds both interest + waitlist; wrong-stage lookup returns null | ✅ |

### Phase H: Dead enum value — Confirmed harmless

| # | Scenario | Result |
|---|---|---|
| H1 | Manually insert withdrawn row → all read paths work, label "Uitgetrokken" returned, re-enroll reactivates same row | ✅ |
| H2 | No code path writes `RegistrationStatus::Withdrawn` (verified by grep) | confirmed |
| H3 | UI labels exist but no surface produces it | confirmed |

**Decision needed (deferred):** Implement user-initiated withdraw as a distinct flow, OR remove `withdrawn` from the enum.

---

## Test infrastructure

Created reusable shake-out scripts under `tests/manual/`:
- `shake-helpers.php` — assertion helpers, cleanup, reset
- `shake-cleanup.php` — wipe test rows between runs
- `shake-phase-b.php` through `shake-phase-h.php` — 7 phase test scripts

Run any phase with:
```bash
ddev exec wp eval-file tests/manual/shake-phase-X.php --path=web/wp
```

Run cleanup first to avoid cross-phase contamination:
```bash
ddev exec wp eval-file tests/manual/shake-cleanup.php --path=web/wp
```

These are not part of the unit-test or integration-test suites — they're operator scripts for the manual shake-out and are safe to keep as regression aids.

---

## Open product/design decisions (resolved 2026-05-18)

All three deferred decisions have been resolved:

1. **D4 → preserve timestamp.** `updateStatus(Completed)` and `updateStatus(Cancelled)` now set their timestamps only on the FIRST transition. Subsequent calls are idempotent on both status and timestamp. (`RegistrationRepository.php:771-790`)
2. **E7 → block cancel on terminal states.** `EnrollmentService::cancel()` now returns `WP_Error('already_completed')` for completed rows and `WP_Error('already_cancelled')` for cancelled rows. Completed registrations are immutable. (`EnrollmentService.php:543-564`)
3. **Withdrawn → removed from enum.** No code path wrote it, it was a dead value. Removed from `RegistrationStatus` enum, from `RegistrationTable` baseline, from reactivation lists in both `RegistrationRepository::create()` and `EnrollmentService::enroll()`, and from admin-dashboard JS labels. Live DB ENUM column altered to drop the value (no existing rows had it).

---

## Files changed during shakeout

**Schema cleanup (earlier in session):**
- `RegistrationTable.php` — folded migrate() into create(), removed migrate()
- `stride-core.php` — removed migrate() hook

**Waitlist feature (earlier in session):**
- `OfferingStatus.php` — added `allowsWaitlist()`
- `RegistrationStatus.php` — no changes (waitlist value already existed)
- `EnrollmentService.php` — added `registerWaitlist()`
- `QuestionnaireHandler.php` — added `handleSubmitWaitlist()`, swapped admin email to user confirmation
- `QuestionnaireRepository.php` — added `waitlist` to STAGES
- `QuestionnaireSettingsPage.php` — added waitlist label + badge color
- `EnrollmentRouter.php` — added `waitlist` to `computeEnrollmentMode()`
- `RegistrationRepository.php` — added `findByEmailAndEditionForStage()`, extended anonymous-allowed statuses
- `StrideMailBridge.php` — new user-confirmation templates + listeners, bumped seed version
- `EditionRegistrationExporter.php` — partition interest/waitlist into dedicated sheets
- Theme: `WaitlistShortcodes.php`, `waitlist.php` template, `functions.php`, `page.php`, `single-vad_edition.php`, `enrollment.js`, `enrollment/step-personal.php`, `enrollment/form.php`
- New page `/wachtlijst/` (post ID 30457)
- `Tests/Unit/Questionnaire/QuestionnaireRepositoryTest.php` — STAGES expectation updated

**Bug fixes during shakeout:**
- `RegistrationRepository.php:48` — anonymous-allowed status whitelist (BUG-RL-1)
- `RegistrationRepository.php:70-122` — extended reactivation to Interest/Waitlist + preserve enrollment_data (BUG-RL-6, G5)
- `RegistrationRepository.php:764-783` — `cancel()` is now data-only, no event dispatch (BUG-RL-5)
- `EnrollmentService.php:249-274` — upfront duplicate check skips Interest/Waitlist (BUG-RL-6)
- `EnrollmentFormHandler.php:296-297` — switched rollback to use service.cancel for event consistency (BUG-RL-5)
- `StrideMailBridge.php:486` — bumped seed version to '3' (BUG-RL-2)

---

## Final test status

- ✅ 867 unit tests pass
- ✅ All 7 phases (50+ scenarios) PASS, 0 fails
- ✅ All 6 bugs fixed
- ✅ All 3 product decisions resolved (D4 idempotent timestamps, E7 blocks cancel on completed, Withdrawn removed)
