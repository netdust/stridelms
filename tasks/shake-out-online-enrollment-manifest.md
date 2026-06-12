# Online Enrollment Flow — Shake-out Bug Manifest

**Date:** 2026-03-31
**Tester:** Claude (browser automation)
**Scope:** All 5 online enrollment scenarios + edge cases
**Branch:** staging

---

## Summary

| Total | CRITICAL | IMPORTANT | MINOR |
|-------|----------|-----------|-------|
| 6     | 1        | 3         | 2     |

---

## BUG-001: Sidebar shows "Gratis" for closed LD courses
**Severity:** IMPORTANT
**Component:** `web/app/themes/stridence/templates/course/sidebar-online.php`
**Steps:** Visit any closed e-learning course page (e.g., Eetproblemen) as any user
**Expected:** Price shown matches Stride edition price (€75,00 / €95,00)
**Actual:** Sidebar shows "Gratis" — `learndash_get_course_price()` returns null for closed-type courses
**Fix:** Use `$editionService->getPrice($editionId, $userId)` when an edition exists. Fall back to LD price only for pure LD courses (no edition).
**Status:** FIXED

## BUG-002: Mobile CTA shows LD default button instead of Stride enrollment URL
**Severity:** IMPORTANT
**Component:** `web/app/themes/stridence/templates/course/mobile-cta.php`
**Steps:** Visit closed e-learning course page on mobile viewport
**Expected:** Mobile CTA shows "Inschrijven" linking to Stride enrollment form
**Actual:** Shows LearnDash "Enroll in this course" button, bypassing Stride enrollment
**Fix:** Share `$enrollment_url` from `single-sfwd-courses.php` with mobile CTA template
**Status:** FIXED

## BUG-003: Format badge shows "Online cursus" for both e-learning and webinar
**Severity:** MINOR
**Component:** `web/app/themes/stridence/templates/course/header.php`
**Steps:** Visit e-learning and webinar course pages — both show "Online cursus"
**Expected:** E-learning → "E-learning" badge; Webinar → "Webinar" badge
**Actual:** Both show "Online cursus" — only checks `$is_online` boolean, not specific format
**Fix:** Check `stride_format` taxonomy terms: `e-learning` → "E-learning", `webinar` → "Webinar", else → "Online cursus"
**Status:** FIXED

## BUG-004: Webinar seed missing ld_price_type — blocks scenario 5
**Severity:** CRITICAL
**Component:** `scripts/seed.php` (INDEX 14, Webinarreeks)
**Steps:** Visit Webinarreeks course page — shows "Direct starten" instead of Stride enrollment
**Expected:** Course uses Stride enrollment form (closed LD + enrollment_form: default)
**Actual:** Defaults to open LD access — no Stride enrollment possible
**Fix:** Add `'ld_price_type' => 'closed'` to INDEX 14 course definition in seed.php
**Status:** FIXED

## BUG-005: No confirmation shown after direct enrollment (?enrolled=1)
**Severity:** IMPORTANT
**Component:** `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentRouter.php` + edition templates
**Steps:** Complete direct enrollment → redirected to edition page with ?enrolled=1 → no message shown
**Expected:** Success toast/banner: "Je bent succesvol ingeschreven!"
**Actual:** Page renders normally, ?enrolled=1 silently ignored
**Fix:** Handle `$_GET['enrolled']` in edition template — shows inline success notice
**Status:** FIXED

## BUG-006: Seed fake registrations for full edition fail silently
**Severity:** MINOR (seed script only)
**Component:** `scripts/seed.php` (fake registration inserts)
**Steps:** Run seed → Beweegbeleid first edition shows 3/50 instead of 50/50
**Expected:** 50 fake registrations created to simulate full edition
**Actual:** `$wpdb->insert()` fails silently — no error checking on return value
**Fix:** Assign unique fake user IDs (900000+offset) instead of user_id=0, add error checking
**Status:** FIXED

---

## What Passed

| Check | Result |
|-------|--------|
| S1: Open e-learning CTA | PASS — "Direct starten" via LD open course flow |
| S2: Closed e-learning default form (2-step) | PASS — enrollment completes |
| S3: Closed e-learning minimal form (2-step) | PASS — enrollment completes |
| S4: Direct enrollment redirect | PASS — fires immediately, registration created |
| EC1: Already enrolled guard (form) | PASS — "Je bent al ingeschreven" shown |
| EC2: Already enrolled guard (direct) | PASS — "Je bent al ingeschreven" shown |
| EC3: Logged-out redirect | PASS — redirects to /login |
| Dashboard: Online courses section | PASS — format labels + progress correct |
| Admin: Cursusinstellingen tab | PASS — LD settings visible read-only |
| Admin: Sessions metabox hidden | PASS — hidden for online editions |
| Admin: Deelnemers metabox | PASS — lists enrolled students |

## Untested

| Scenario | Reason |
|----------|--------|
| S5: Webinar enrollment form | Blocked by BUG-004 |
| Full edition guard | Blocked by BUG-006 |
