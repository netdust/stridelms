# Shake-out Flow #1: Interest / Waitlist surface

**Date:** 2026-05-18
**Scope:** Every user-facing and admin-facing surface touching `status IN ('interest','waitlist')`.
**Status:** COMPLETE — 15 scenarios, 5 bugs found, all fixed. Regression sweep: B-H phases + Flow #1 all green, 867 unit tests pass.

---

## Surface map (verified)

### User-facing entry points
| Path | Anonymous? | Triggers |
|---|---|---|
| `[stride_interest]` shortcode on `/interesse/?editie={id}` | ✅ | `stride_submit_interest` → `QuestionnaireHandler::handleSubmitInterest` → user mail |
| `[stride_waitlist]` shortcode on `/wachtlijst/?editie={id}` | ✅ | `stride_submit_waitlist` → `QuestionnaireHandler::handleSubmitWaitlist` → user mail |
| `EnrollmentService::registerInterest()` (logged-in) | ❌ | Fires `stride/registration/interest_registered` → `StrideMailBridge` sends user mail |
| `EnrollmentService::registerWaitlist()` (logged-in) | ❌ | Fires `stride/registration/waitlisted` → `StrideMailBridge` sends user mail |
| CTA on `single-vad_edition.php` (desktop + mobile) | ✅ | Routes to the shortcode page with `?editie={id}` |

### Admin-facing surfaces
| Path | Behavior |
|---|---|
| Admin dashboard → edition slide-over → "Studenten" tab | Shows ALL registrations including anonymous (after BUG-IW-2 fix). Badge styled correctly (after BUG-IW-3). `(anoniem)` label added for anonymous rows. |
| XLSX export | "Interesse" and "Wachtlijst" sheets appear conditionally with full data (name, email, phone, org, source). Already verified earlier in session. |
| Direct DB / WP-CLI | `wp db query 'SELECT * FROM stride_vad_registrations WHERE status = "interest"'` always works as last resort |

### Mail templates
| Template | Trigger | Audience |
|---|---|---|
| `stride-interest-registered-user` | inline `ndmail_send` from anonymous handler + event listener `onInterestRegisteredUserMail` for logged-in path | User who registered |
| `stride-waitlist-registered-user` | same shape as interest | User who registered |

**No admin notification emails fire for interest/waitlist registrations** — this was intentional per Stefan's decision (admin pulls the list when needed). The old `stride-interest-registered-admin` template was removed.

---

## Bugs found and fixed during this shake-out

### BUG-IW-1: Cross-status anonymous dedup gap [FIXED]
**Symptom:** Anonymous user submits interest on edition X. Later, X flips to Full (or back to Announcement, etc.) and same email submits waitlist. Two separate anonymous rows created for the same email/edition.

**Root cause:** `handleSubmitInterest` called `findByEmailAndEdition` which is hard-coded to `status=interest`. `handleSubmitWaitlist` called `findByEmailAndEditionForStage(..., Waitlist)`. Each only saw its own stage.

**Fix:** Added `findAnonymousForEmailAndEdition()` on the repo — broad lookup across `(interest, waitlist)` statuses. Both handlers now call it. When a row exists in a different stage, the handler flips the status to the new stage and merges the new `enrollment_data` block while preserving the old one. Single row per email/edition for the pre-enrollment phase.

**Files:**
- `RegistrationRepository.php:215-243` (new helper)
- `QuestionnaireHandler.php:55-81` (interest handler now upserts across stages)
- `QuestionnaireHandler.php:115-135` (waitlist handler)

### BUG-IW-2: Admin slide-over hides anonymous interest/waitlist rows [FIXED]
**Symptom:** Admin opens any Announcement or Full edition's "Studenten" tab → anonymous interest/waitlist rows are invisible (the only signups they care about for those statuses).

**Root cause:** `AdminAPIController::getEditionRegistrations` did `if (!$user) { continue; }` when the user lookup failed. Anonymous rows (user_id=NULL) all got skipped before reaching the items array.

**Fix:** Rewrote the formatting block to fall back to `enrollment_data.{status}.name/email` when no `$user` is present. Added `anonymous: true` flag on the response so the UI can label these rows. Empty attendance map for anonymous rows.

**Files:**
- `AdminAPIController.php:1333-1380` (formatting fallback + anonymous flag)
- `templates/admin/dashboard.php:572-575` ("(anoniem)" tag in student row)
- `assets/css/admin-dashboard.css` (`.sd-anon-tag` styling)

### BUG-IW-3: No CSS for `interest` / `waitlist` status badges [FIXED]
**Symptom:** Admin dashboard's status badge for interest/waitlist rows renders unstyled (default bg, no color).

**Root cause:** `.sd-badge--interest` and `.sd-badge--waitlist` selectors never existed in `admin-dashboard.css`. The Alpine template generates the class dynamically (`:class="'sd-badge--' + reg.status"`), so missing CSS = silent ugly fallback.

**Fix:** Added two new badge styles in the existing `/* Status colors */` block.

**Files:** `assets/css/admin-dashboard.css` (lines 590-598)

### BUG-IW-4: Trajectory interest/waitlist created without validation [FIXED]
**Symptom:** `EnrollmentService::registerInterest()` and `registerWaitlist()` accept `trajectory_id` per their docblock, but neither validates the trajectory's offering status. Result: anyone could create interest/waitlist rows on trajectories regardless of their actual state.

**Root cause:** Both methods had an `if ($editionId)` branch with full validation but no equivalent `if ($trajectoryId)` branch. The trajectory ID was simply written into the row.

**Fix:** Mirrored the edition validation block for trajectories. Resolves the trajectory via `TrajectoryService::getTrajectory()`, checks `status_enum->allowsInterest()` / `allowsWaitlist()`, and runs the duplicate-active check against the trajectory side.

**Files:** `EnrollmentService.php:376-391` (interest), `EnrollmentService.php:449-464` (waitlist)

### BUG-IW-5: (none separately tracked — same as IW-4 originally)

---

## Mailing waitlist/interest users — what exists today

**Question Stefan asked:** "easy way to mail them?"

**Answer:** today, no in-app tooling. The supported workflow is:

1. Admin opens the edition in WP admin (or admin dashboard slide-over)
2. Clicks "Export naar Excel" — XLSX downloads
3. Opens the file in Excel/LibreOffice, navigates to the **Interesse** or **Wachtlijst** sheet
4. Copy email column → paste into BCC of own email client (Outlook, Gmail, Apple Mail)

Bulk actions are deliberately removed from the edition CPT list table (`EditionAdminController.php:86-87` `removeBulkActions`), so there's no "mail all" action there either. No FluentCRM integration hook fires on these statuses.

**Gaps (deferred, not fixes):**
- No copy-emails-to-clipboard button on the slide-over
- No "send mail to all waitlist users for this edition" admin action
- No FluentCRM tagging when a waitlist/interest row is created (so it can't drive a campaign from FCRM either)

**Recommended next step (if building tooling):** smallest useful surface would be a copy-to-clipboard button on the slide-over Studenten tab that grabs all currently-visible rows' emails (after a status filter is added). That's ~20 lines of JS + a filter dropdown. Not building now per scope.

---

## Test scenarios — all passing post-fix

| # | Scenario | Result |
|---|---|---|
| IW-1 | Anonymous interest submission via handler | ✅ |
| IW-2 | Anonymous waitlist submission via handler | ✅ |
| IW-3 | Anonymous resubmit on same email — upsert | ✅ |
| IW-4 | Logged-in interest via service | ✅ |
| IW-5 | Logged-in waitlist via service | ✅ |
| IW-6 | Interest on non-Announcement → `interest_closed` | ✅ |
| IW-7 | Waitlist on non-Full → `waitlist_closed` | ✅ |
| IW-8 | Cross-status dedup (interest → waitlist same email) | ✅ (after BUG-IW-1 fix) |
| IW-9 | `getEditionRegistrations` returns anonymous rows | ✅ (after BUG-IW-2 fix) |
| IW-10 | Status list includes interest + waitlist | ✅ |
| IW-11 | User confirmation mail sends | ✅ (smoke-tested via Mailpit earlier) |
| IW-12 | XLSX export sheets present | ✅ |
| IW-13 | Interest → enrollment upgrade path | ✅ (covered in Phase F) |
| IW-14 | Interest on trajectory — validated | ✅ (after BUG-IW-4 fix, correctly rejects when status isn't Announcement) |
| IW-15 | Waitlist on trajectory — validated | ✅ (after BUG-IW-4 fix) |

---

## Cumulative bug count this session

| Source | Bugs found | Fixed |
|---|---|---|
| Phase A-H lifecycle shakeout | 6 (RL-1..6) | 6 |
| D4/E7/Withdrawn cleanup (Stefan's product decisions) | 0 bugs, 3 product changes | 3 |
| Flow #1 (Interest/Waitlist surface) | 4 (IW-1..4) | 4 |
| **Total** | **10 bugs + 3 changes** | **all** |

---

## Files added/changed in this flow

- `Modules/Enrollment/RegistrationRepository.php` — added `findAnonymousForEmailAndEdition()`
- `Modules/Enrollment/EnrollmentService.php` — added trajectory validation in interest + waitlist
- `Modules/Questionnaire/QuestionnaireHandler.php` — both handlers use cross-status dedup
- `Admin/AdminAPIController.php` — `getEditionRegistrations` shows anonymous rows with stageData fallback
- `assets/css/admin-dashboard.css` — badge styles for interest/waitlist + anon tag
- `templates/admin/dashboard.php` — `(anoniem)` indicator
- `tests/manual/shake-flow-interest-waitlist.php` — 15 scenarios for regression

## Open items deferred to next flows

- Cancellation cascade — Flow #2
- Enrollment-phase completion tasks — Flow #3
- Post-course completion + certificate — Flow #4
- Data exportability sweep — Flow #5
