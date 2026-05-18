# Shake-out Manifest: Registration Table Lifecycle

**Date:** 2026-05-18
**Scope:** Full lifecycle on `stride_vad_registrations` — interest → waitlist → enrollment → completion → cancellation, including data persistence.
**Status:** Smoke test phase 1 complete (13 scenarios run, 4 bugs found, 2 fixed, 2 documented for decision).

---

## Status semantics (verified from code)

| Status | Implemented? | Notes |
|---|---|---|
| `interest` | ✅ Yes — `EnrollmentService::registerInterest` + `QuestionnaireHandler::handleSubmitInterest` (anonymous). | Requires `OfferingStatus::Announcement`. Dedup by email (anonymous) or by user+edition. |
| `waitlist` | ✅ Yes (added 2026-05-18) — `EnrollmentService::registerWaitlist` + `QuestionnaireHandler::handleSubmitWaitlist`. | Requires `OfferingStatus::Full`. No automation — admin manually mails when seats free up. |
| `pending` | ✅ Yes — initial status when edition has completion requirements OR `requiresApproval`. | |
| `confirmed` | ✅ Yes — direct on enroll OR after `confirmRegistration`. | Default ENUM value. |
| `completed` | ✅ Yes — `updateStatus(Completed)` sets `completed_at`. | |
| `cancelled` | ✅ Yes — `cancel()` writes this. Fires `stride/registration/cancelled` → quote cancelled, seat freed, audit log, notification. | Only terminal-cancel path that exists. |
| `withdrawn` | ❌ **Dead value.** Treated as synonym of cancelled in re-enrollment dedup (`RegistrationRepository.php:73`). Zero writes anywhere. | Document & decide later — either implement user-initiated cancel as withdrawn, or remove from enum. |

**Cancelled vs Withdrawn:** functionally identical. Probable intent ("admin cancelled" vs "user withdrew") is not encoded.

---

## Bugs found during smoke test

### BUG-RL-1: Anonymous waitlist creation rejected by repository [FIXED]

**What was tested:** `QuestionnaireHandler::handleSubmitWaitlist` with `user_id=null`.
**Expected:** Row created with `status=waitlist`, `user_id=NULL`, data in `enrollment_data.waitlist`.
**Actual:** `RegistrationRepository::create()` returned `WP_Error('missing_field', 'Required: user_id (except for interest registrations)')`.

**Root cause:** The user_id exemption was hard-coded to `RegistrationStatus::Interest` only. Waitlist is the other anonymous-allowed status but wasn't whitelisted.

**Files:** `RegistrationRepository.php:48`

**RESOLVED:** Replaced single-status check with `$anonymousAllowedStatuses` array containing both `Interest` and `Waitlist`. Smoke test re-run: anonymous waitlist now creates a row with `user_id=NULL`.

---

### BUG-RL-2: Mail templates never seeded after rename [FIXED]

**What was tested:** `ndmail_send('stride-waitlist-registered-user', ...)` via the handler.
**Expected:** User receives confirmation email.
**Actual:** `ndmail_send` returned `WP_Error('Template "stride-waitlist-registered-user" not found')`.

**Root cause:** `seedTemplates()` is version-gated (`stride_mail_templates_seeded` option). Old template slug `stride-interest-registered-admin` was renamed to `stride-interest-registered-user` + new `stride-waitlist-registered-user` was added, but the version constant wasn't bumped → seeder skipped → DB still has the old orphan + missing new ones.

**Files:** `StrideMailBridge.php:486` (version constant `'2'`)

**RESOLVED:** Bumped version to `'3'`. Manually deleted the orphan `stride-interest-registered-admin` template post + re-ran `seedTemplates()`. Both new templates now present (IDs 30455, 30456). Smoke test re-run: both anonymous and logged-in waitlist/interest submissions trigger user confirmation emails landing in Mailpit.

---

### BUG-RL-3: Anonymous users cannot reach interest/waitlist form via enrollment URL [FIXED]

**What was tested:** `GET /vormingen/{slug}/inschrijving/` while not logged in, for an `Announcement` and a `Full` edition.
**Expected:** Form renders so anonymous user can submit interest / waitlist (handlers are public).
**Actual:** Router immediately redirects to `wp-login.php`.

**Root cause:** `EnrollmentRouter::handleCourseEnrollment()` has an unconditional login redirect. This affected the existing "Interesse melden" CTA too — both were silently broken when the CTA pointed at `stride_enrollment_url()`.

**RESOLVED:** Mirrored the existing `[stride_interest]` shortcode + dedicated-page pattern for waitlist:
- New `WaitlistShortcodes` class registers `[stride_waitlist]` (anonymous-allowed, mirrors `InterestShortcodes`)
- New `templates/forms/waitlist.php` (mirrors `interest.php`)
- New `/wachtlijst/` page (post ID 30457) containing `[stride_waitlist]`
- Desktop + mobile CTAs on `single-vad_edition.php` now point to `/interesse/?editie={id}` and `/wachtlijst/?editie={id}` (was: `stride_enrollment_url()` — login-gated)
- `page.php` full-width body-class detection extended to `stride_waitlist`

**Verified end-to-end:** Anonymous user hits `/wachtlijst/?editie=13222` → 200 → submits via public REST (`ntdst/v1/get_nonce` then `ntdst/v1/action`) → row created with `user_id=NULL` + correct `enrollment_data.waitlist` → user gets confirmation mail. Same flow for interest.

---

### BUG-RL-4: Capacity propagation question [DOCUMENTATION]

**What was tested:** N/A — observation during smoke test setup.
**Note:** `OfferingStatus::Full` is set automatically by `EditionService::onRegistrationCreated()` when capacity is reached. But the auto-flip Full → Open in `onRegistrationCancelled()` does NOT consider waitlist rows — it just checks `hasAvailableSpots()`. This is correct for our model (no auto-promotion), but worth documenting: when a seat frees up, the edition flips back to `Open`, NEW users can enroll first-come-first-served, and existing waitlist rows stay as `waitlist` until admin manually contacts them. Admin must be aware.

**Status:** Not a bug. Behavior documented.

---

## Scenarios executed in smoke test (Phase A + waitlist)

| # | Status | Scenario | Result |
|---|---|---|---|
| T1 | waitlist | Anonymous submit, `email=anon.waiter@smoke.test` on Full edition 13222 | ✅ Row created, `user_id=NULL`, `enrollment_data.waitlist.email` set, user mail sent |
| T2 | waitlist | Same anonymous email submits again | ✅ Upsert — existing row updated, no duplicate |
| T3 | waitlist | Logged-in user 3195 (`student2@seed.test`) on same edition | ✅ Row created, `user_id=3195`, user mail sent |
| T4 | waitlist | User 3195 attempts second waitlist submit | ✅ Rejected with `already_registered` |
| T5 | waitlist | User 3195 attempts waitlist on Open edition 13230 | ✅ Rejected with `waitlist_closed` |
| T6 | waitlist | User 3195 attempts waitlist on non-existent edition 999999 | ✅ Rejected with `invalid_edition` |
| T7 | waitlist | Anonymous submit with missing email | ✅ Rejected with `validation_error` |
| I1 | interest | Anonymous submit on Announcement edition 13224 | ✅ Row created, mail sent |
| I2 | interest | Same anonymous email resubmits | ✅ Upsert |
| I3 | interest | Logged-in user 3196 (`student3@seed.test`) | ✅ Row created, mail sent |
| I4 | interest | User 3196 attempts second interest submit | ✅ Rejected with `already_registered` |
| I5 | interest | User 3196 attempts interest on Open edition | ✅ Rejected with `interest_closed` |
| I6 | interest | User 3196 attempts interest on Full edition | ✅ Rejected with `interest_closed` (Full doesn't allow interest) |

**Mail verification (Mailpit):**
- ✅ `Bevestiging wachtlijst` mail to `anon.waiter@smoke.test`
- ✅ `Bevestiging wachtlijst` mail to `student2@seed.test`
- ✅ `Bevestiging interesse` mail to `anon.interest@smoke.test` (×2 — dedup correctly resent confirmation)
- ✅ `Bevestiging interesse` mail to `student3@seed.test`
- ✅ **No admin mails sent for interest or waitlist** — as required

**Excel export verification:**
- ✅ Edition 13222 (Full): export has 6 sheets — Overzicht, Deelnemers, Facturatie, Aanwezigheid, Taken, **Wachtlijst** (last 5 only when relevant data present)
- ✅ Edition 13224 (Announcement): export has 5 sheets ending in **Interesse**
- ✅ Wachtlijst sheet contains both rows with correct "Bron" (Anoniem / Account)
- ✅ Deelnemers sheet does NOT contain waitlist/interest rows — partition working

---

## Lifecycle map

```
                                   ┌─────────────────────────────────────┐
                                   │ Edition state: Announcement         │
                                   └──────────────┬──────────────────────┘
                                                  │
                          ┌───────────────────────┴───────────────────────┐
                          │                                               │
              ┌───────────▼───────────┐                          ┌────────▼──────────┐
              │ Anonymous interest    │                          │ Logged-in interest│
              │ (no user_id)          │                          │ user_id set       │
              │ data.interest = {...} │                          │                   │
              └───────────┬───────────┘                          └────────┬──────────┘
                          │                                               │
                          │ Edition opens (Announcement → Open)           │
                          │ User self-enrolls with matching email         │
                          │ → upgradeFromInterest()                       │
                          └───────────────────────┬───────────────────────┘
                                                  │
                                   ┌──────────────▼──────────────────────┐
                                   │ Edition state: Open                 │
                                   │   ↓ regular enrollment              │
                                   │   pending (if requirements/approval)│
                                   │   confirmed (otherwise)             │
                                   └──────────────┬──────────────────────┘
                                                  │
                                   ┌──────────────▼──────────────────────┐
                                   │ Edition state: Full                 │
                                   │   ↓ NEW users get waitlist option   │
                                   │ waitlist rows accumulate            │
                                   │ (admin contacts manually)           │
                                   └──────────────┬──────────────────────┘
                                                  │ if seat freed
                                                  ↓ → flips back to Open
                                          (waitlist users still stuck;
                                           admin must mail them)

Cancelled / Completed are terminal states reached from confirmed.
```

---

## Outstanding test scenarios (not yet executed)

### Phase B: Direct enrollment on Open
- B1 self-enrollment without requirements → `confirmed`
- B2 self-enrollment with requirements → `pending`, completion_tasks set
- B3 with `requiresApproval` → `pending` regardless of requirements
- B4 colleague enrollment → already covered in `shake-out-enrollment-v2-manifest.md`
- B5 concurrent enrollment race for last seat
- B6 already-enrolled guard

### Phase C: Pending → Confirmed
- C1 user completes all tasks → auto-promote? (which service)
- C2 admin clicks "Bevestigen" → `confirmRegistration()`
- C3 admin tries to confirm a non-pending row → error
- C4 precedence when both task-completion and admin-confirm could apply

### Phase D: Completed
- D1 user completes course + post-course tasks
- D2 LearnDash complete but post-course tasks pending
- D3 all tasks done but LearnDash quiz failed

### Phase E: Cancellation
- E1 admin cancels confirmed → quote/seat/audit/mail
- E2 admin cancels pending
- E3 admin cancels interest (does cancel() handle non-terminal statuses gracefully?)
- E4 user self-cancel (is the UI exposed?)
- E5 re-enroll after cancel → reactivation logic at `RegistrationRepository.php:70-93`
- E6 cancel a completed row — allowed?

### Phase F: Interest/Waitlist upgrade paths
- F1 logged-in interest → user later enrolls (Announcement → Open flip) → row promoted
- F2 anonymous interest → user later registers WP account with matching email → enrolls → upgradeFromInterest path security check holds
- F3 **Waitlist promotion** — admin manually contacts waitlist user, user self-enrolls when Edition flips back to Open → does the waitlist row get reused? (Currently: no — a NEW row would be created because `hasActiveRegistration` excludes waitlist? — verify)
- F4 `enrollment_data` round-trips: interest → enrollment_personal → intake → evaluation

### Phase G: Data column edge cases
- G1 Anonymous interest with `user_id=NULL` in all repository read paths (find, findByEmailAndEdition, findByCompany, exports)
- G2 `completion_tasks` merge on partial update
- G3 `selections` lock at edition start
- G4 `company_id` propagation through Partner API

### Phase H: Dead enum value (withdrawn)
- H1 Manually insert `status='withdrawn'` row → confirm UI handles label, no special-case business logic
- H2 Find any UI surface that could write withdrawn

---

## Open product/design decisions

1. **Withdrawn semantics** — Implement user-initiated withdraw distinct from admin-initiated cancel? Or remove withdrawn from the enum entirely?
2. **F3 waitlist upgrade** — When admin invites a waitlisted user and they self-enroll: should the waitlist row be reused/promoted, or a fresh row created (current behavior)?

(BUG-RL-3 resolved 2026-05-18 by adding `[stride_waitlist]` shortcode + `/wachtlijst/` page.)

---

## Files touched during smoke test

- `RegistrationRepository.php` — anonymous-allowed status whitelist (BUG-RL-1)
- `RegistrationRepository.php` — added `findByEmailAndEditionForStage()` helper (waitlist dedup)
- `StrideMailBridge.php` — bumped seed version to '3' (BUG-RL-2)
- `OfferingStatus.php` — added `allowsWaitlist()`
- `EnrollmentService.php` — added `registerWaitlist()`
- `EnrollmentRouter.php` — added waitlist mode to `computeEnrollmentMode()`
- `QuestionnaireHandler.php` — added `handleSubmitWaitlist()` + public action registration
- `QuestionnaireRepository.php` — added `waitlist` to STAGES
- `QuestionnaireSettingsPage.php` — added waitlist stage label + badge color
- `enrollment/form.php` — allow waitlist mode when enrollment is closed
- `enrollment.js` — added waitlist stepConfig + submit label/action
- `step-personal.php` — waitlist gets same UI treatment as interest
- `single-vad_edition.php` — desktop + mobile CTAs for `allowsWaitlist()` editions
- `EditionRegistrationExporter.php` — partition out interest/waitlist + add Interesse/Wachtlijst sheets
- `Tests/Unit/Questionnaire/QuestionnaireRepositoryTest.php` — updated STAGES expected list
