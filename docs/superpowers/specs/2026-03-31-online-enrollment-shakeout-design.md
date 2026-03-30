# Online Enrollment Flow — Shake-out Design

**Date:** 2026-03-31
**Status:** Approved
**Type:** QA / Shake-out testing

## Goal

Systematically test all online enrollment code paths. Manual smoke test first to find obvious breaks, then acceptance tests to lock down critical flows.

## Seed Data Extensions

Add 3 missing scenarios to `scripts/seed.php`:

| # | Scenario | Format Term | LD Access Mode | Form Type | Already Seeded? |
|---|----------|-------------|----------------|-----------|-----------------|
| 1 | Open e-learning (pure LD) | `e-learning` | `open` | none (no edition) | Yes |
| 2 | Closed e-learning + default form | `e-learning` | `closed` | `default` | Yes |
| 3 | Closed e-learning + minimal form | `e-learning` | `closed` | `minimal` | No — add |
| 4 | Closed e-learning + direct enrollment | `e-learning` | `closed` | `direct` | No — add |
| 5 | Webinar with default form | `webinar` | `closed` | `default` | No — add |

New seed courses should follow existing seed patterns: 3-5 lessons each, member/non-member pricing, reasonable capacity.

## Manual Smoke Test Matrix

Test each scenario as `seed_student1` (not enrolled). Document all failures in bug manifest.

### A. Course Page CTA

| Scenario | Expected Sidebar | Expected CTA | Mobile CTA |
|----------|-----------------|--------------|------------|
| 1 (open, no edition) | `sidebar-online` | "Start cursus" (direct LD access) | Same |
| 2 (closed + default form) | `sidebar-online` | "Inschrijven" → enrollment form | Same |
| 3 (closed + minimal form) | `sidebar-online` | "Inschrijven" → enrollment form | Same |
| 4 (closed + direct) | `sidebar-online` | "Inschrijven" → direct redirect | Same |
| 5 (webinar + default form) | `sidebar-online` | "Inschrijven" → enrollment form | Same |

Verify:
- No `sidebar-edition` rendered for any online course
- LD payment buttons shown only when closed + no Stride form
- Format badge shows correct label (E-learning / Webinar)

### B. Enrollment Flow

| Scenario | Expected Flow |
|----------|--------------|
| 1 | No Stride form. LD grants access on click. No registration in `wp_vad_registrations`. |
| 2 | Default form: 2-step (personal → confirm). No type step, no billing. |
| 3 | Minimal form: 2-step (personal → confirm). Same as online default. |
| 4 | Direct: immediate `enroll()` → redirect with `?enrolled=1`. No form shown. |
| 5 | Webinar: 2-step form, same as scenario 2. Format label "Webinar". |

Verify for scenarios 2-5:
- Registration created in `wp_vad_registrations` with correct `edition_id`
- LD access granted (`learndash_user_get_enrolled_courses` includes course)
- `enrollment_type` = `self` for all online enrollments
- No quote created (billing step skipped)
- Questionnaire fields render if configured on edition

### C. Post-Enrollment Dashboard

| Check | Expected |
|-------|----------|
| Online tab | Enrolled course appears with progress bar |
| Format label | "E-learning" or "Webinar" per course format |
| Progress | 0% immediately after enrollment |
| Resume URL | Links to first lesson |
| Days remaining | Shows if course has expiration |
| Completed tab | Course moves here after all lessons done |
| Certificate | Available in completed tab if configured |

### D. Admin UI

| Check | Expected |
|-------|----------|
| Edition edit (online course linked) | Sessions metabox hidden |
| Edition edit (online course linked) | Attendance tab hidden |
| Edition edit (online course linked) | Venue/date fields hidden |
| Edition edit (online course linked) | "Cursusinstellingen" tab visible |
| Edition edit (switch course) | Metaboxes toggle when course dropdown changes |
| Registration metabox (form-based) | Shows registrations with status |
| Registration metabox (direct) | Shows "X deelnemers direct ingeschreven via LearnDash" |
| Edition list | Format filter works (Online / Klassikaal) |

### E. Edge Cases

| Case | Expected |
|------|----------|
| Already enrolled user visits enrollment form | Redirect or "already enrolled" message |
| Logged-out user visits enrollment URL | Redirect to login |
| Online edition with capacity reached | "Volzet" state, CTA disabled |
| Online edition with `capacity=0` (unlimited) | Always enrollable |
| Course format changed after edition created | Edition behavior updates (no stale cache) |

## Acceptance Tests

Write after manual smoke test. Critical paths only.

### `OnlineEnrollmentFormCest`

Covers scenarios 2 + 3 (form-based online enrollment):

```
SCENARIO: Enroll in online course via default form
  GIVEN: seed_student1 logged in, not enrolled in closed e-learning with default form
  WHEN: visits course page, clicks Inschrijven, fills personal step, confirms
  THEN: registration created, LD access granted, redirect to confirmation

SCENARIO: Enroll in online course via minimal form
  GIVEN: seed_student1 logged in, not enrolled in closed e-learning with minimal form
  WHEN: visits course page, clicks Inschrijven, fills personal step, confirms
  THEN: registration created, LD access granted, redirect to confirmation

SCENARIO: Online form skips billing and type steps
  GIVEN: seed_student1 logged in, on enrollment form for online edition
  WHEN: form loads
  THEN: only 2 steps visible (Gegevens, Bevestigen), no Type, no Facturatie
```

### `OnlineDirectEnrollmentCest`

Covers scenario 4 (direct enrollment):

```
SCENARIO: Direct enroll in online course
  GIVEN: seed_student1 logged in, not enrolled in closed e-learning with direct form
  WHEN: visits enrollment URL
  THEN: immediate redirect with ?enrolled=1, registration created, LD access granted

SCENARIO: Already enrolled user tries direct enrollment
  GIVEN: seed_student1 already enrolled in direct e-learning
  WHEN: visits enrollment URL again
  THEN: redirect to course page (no duplicate registration)
```

### `OnlineCourseCTACest`

Covers all 5 scenarios on course page:

```
SCENARIO: Open e-learning shows start button
  GIVEN: seed_student1 logged in, visits open e-learning course page
  THEN: sidebar-online rendered, "Start cursus" CTA, no enrollment form link

SCENARIO: Closed e-learning with form shows enroll button
  GIVEN: seed_student1 logged in, visits closed e-learning with default form
  THEN: sidebar-online rendered, "Inschrijven" CTA linking to enrollment form

SCENARIO: Webinar shows correct format label
  GIVEN: seed_student1 logged in, visits webinar course page
  THEN: format badge shows "Webinar", sidebar-online rendered
```

### `OnlineDashboardCest`

```
SCENARIO: Online enrollment appears in dashboard
  GIVEN: seed_student1 enrolled in online course
  WHEN: visits dashboard
  THEN: course appears in online tab with 0% progress, format label, resume link

SCENARIO: Completed online course shows certificate
  GIVEN: seed_student1 completed online course (all lessons done)
  WHEN: visits dashboard
  THEN: course in completed tab with certificate link (if configured)
```

### `OnlineAdminCest`

```
SCENARIO: Online edition hides classroom metaboxes
  GIVEN: admin on edition edit page linked to online course
  THEN: sessions metabox hidden, attendance tab hidden, cursusinstellingen tab visible

SCENARIO: Switching course toggles metaboxes
  GIVEN: admin on edition edit page
  WHEN: changes course dropdown from klassikaal to online
  THEN: sessions/attendance hide, cursusinstellingen shows

SCENARIO: Direct enrollment admin view
  GIVEN: admin views online edition with direct enrollment and enrolled users
  THEN: registration metabox shows direct enrollment count
```

## Bug Manifest

All issues go to `tasks/shake-out-online-enrollment-manifest.md`:

```markdown
## BUG-NNN: [Title]
**Severity:** CRITICAL / IMPORTANT / MINOR
**Component:** [file or module]
**Steps to reproduce:** ...
**Expected:** ...
**Actual:** ...
**Fix:** ...
**Status:** OPEN / FIXED
```

## Execution Order

1. Extend seed data (3 new courses)
2. Run `ddev exec wp eval-file scripts/seed.php`
3. Manual smoke test (sections A-E), document all bugs
4. Fix CRITICAL and IMPORTANT bugs
5. Write acceptance tests for critical paths
6. Run full regression: `ddev exec vendor/bin/codecept run`
7. Update bug manifest with final status
