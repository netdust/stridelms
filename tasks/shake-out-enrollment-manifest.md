# Shake-out Manifest: Enrollment Module

**Date:** 2026-03-21
**Scope:** Enrollment multi-step form, routing, AJAX handlers, quote creation, email notifications
**Tested as:** seed_student1 (Pieter Janssen), edition 7948 (Keuzecursus: Harm Reduction)

## Summary

| Severity | Count | Resolved |
|----------|-------|----------|
| CRITICAL | 1 | 1 |
| IMPORTANT | 4 | 4 |
| MINOR | 2 | 2 |
| **Total** | **7** | **7** |

**Status: ALL RESOLVED**

---

## Bugs

### BUG-1: Registration quote_id never set after quote creation [IMPORTANT]

**What was tested:** Full enrollment flow → DB verification
**Expected:** `stride_vad_registrations.quote_id` populated with the created quote ID
**Actual:** `quote_id` is always NULL despite quote being created (ID 10657 for registration 2411)
**Severity:** IMPORTANT — data integrity issue, breaks any query relying on registration→quote relationship

**Root cause suspicion:** `EnrollmentQuoteHandler::onRegistrationCreated()` creates the quote but never calls `RegistrationRepository::update()` to set `quote_id` on the registration row. No code path exists to perform this update.

**Files:** `Handlers/EnrollmentQuoteHandler.php:135`
**Resolution:** Added `$registrationRepo->update($registrationId, ['quote_id' => $quoteId])` after quote creation.

---

### BUG-2: Enrollment emails sent twice (duplicate hooks) [CRITICAL]

**What was tested:** Single enrollment → Mailpit inspection
**Expected:** 1 user confirmation + 1 admin notification + 1 quote email = 3 emails
**Actual:** 2x user confirmation + 2x admin notification + 1 quote email = 5 emails
**Severity:** CRITICAL — users receive duplicate emails for every enrollment

**Root cause suspicion:** Both the trigger-based auto-dispatch (`MailService::activateTriggers()` at line 187-190) AND a manual dispatch fire on `stride/registration/created`. The same template fires twice.

**Files:** `Modules/Mail/StrideMailBridge.php`, `netdust-mail/src/MailService.php:176-192`
**Resolution:** Root cause was `do_action('stride/registration/created')` fired in BOTH `RegistrationRepository::create()` AND `EnrollmentService::enroll()`. Removed the event from the repository (data layer should not fire business events). Service layer owns event dispatch.

---

### BUG-3: Admin notification email sent to student instead of admin [IMPORTANT]

**What was tested:** Mailpit email recipients after enrollment
**Expected:** "Nieuwe inschrijving" email sent to admin email
**Actual:** Both "Nieuwe inschrijving" emails sent to `student1@seed.test` (the enrollee)
**Severity:** IMPORTANT — admin never receives enrollment notifications

**Root cause:** `MailService::send()` (line 236-240) falls back to user email when no `to` is specified. The admin template auto-dispatches via trigger without explicit `to` override. Template category `notification` is not used for recipient routing.

**Files:** `netdust-mail/src/MailService.php:236-240`, `Modules/Mail/StrideMailBridge.php:463-471`
**Resolution:** Removed `trigger` from admin template definition (prevents auto-dispatch to wrong recipient). Added explicit `onRegistrationCreatedAdminNotify()` handler in StrideMailBridge that sends with `['to' => adminEmail]`. Also removed trigger from existing DB template.

---

### BUG-4: No already-enrolled guard on enrollment page [IMPORTANT]

**What was tested:** Navigated to enrollment page for edition user is already enrolled in
**Expected:** "Already enrolled" message or redirect
**Actual:** Full 4-step enrollment wizard renders — user can fill the entire form before backend rejects at submission
**Severity:** IMPORTANT — wastes user time, confusing UX

**Root cause:** `EnrollmentRouter::handleCourseEnrollment()` does not check `EnrollmentService::hasActiveRegistration()` before rendering the form. The duplicate check only happens at submission time in `EnrollmentService::enroll()`.

**Files:** `Modules/Enrollment/EnrollmentRouter.php`
**Resolution:** Added `hasActiveRegistration()` check in `handleCourseEnrollment()` before rendering form. Shows "Je bent al ingeschreven" empty state with link to dashboard.

---

### BUG-5: Sidebar shows member price, quote charges non-member price [IMPORTANT]

**What was tested:** Self-enrollment as non-member user
**Expected:** Consistent price shown in sidebar and charged on quote
**Actual:** Sidebar shows €195 (member price), quote charges €245 (non-member price)
**Severity:** IMPORTANT — misleading pricing display, user expects to pay €195 but gets quoted €245

**Root cause:** `enrollment.php` line 61 calls `$editionService->getPrice($item_id)` with default `$isMember = true`, always showing member price. The quote handler uses actual member status from `is_member` user meta.

**Files:**
- Sidebar price: `themes/stridence/templates/forms/enrollment.php:61`
- Quote price: `Handlers/EnrollmentQuoteHandler.php:197`
**Resolution:** Sidebar now checks `is_member` user meta and passes it to `getPrice($itemId, $isMember)`.

---

### BUG-6: Type step radio appears selected but isn't checked on page load [MINOR]

**What was tested:** Initial load of enrollment form Step 1
**Expected:** "Mezelf" radio visually selected AND functionally checked, "Volgende" enabled
**Actual:** "Mezelf" has visual blue border but `enrollment_type = ""`, "Volgende" is disabled until user clicks the radio
**Severity:** MINOR — confusing UX, user must click something that looks already selected

**Root cause:** CSS styling gives "Mezelf" a selected appearance (first-child styling) but `x-model` starts as empty string. No `x-init` sets a default value.

**Files:** `themes/stridence/templates/forms/enrollment/step-type.php`, `templates/forms/enrollment.js`
**Resolution:** Default `enrollment_type` changed from `''` to `'werknemer'` for full enrollment mode. "Mezelf" is now selected and "Volgende" enabled on page load.

---

### BUG-7: Confirmation step shows English date, sidebar shows Dutch date [MINOR]

**What was tested:** Step 4 confirmation page
**Expected:** Consistent Dutch date format throughout
**Actual:** Confirmation heading: "19 May 2026" (English), Sidebar: "19 mei 2026" (Dutch)
**Severity:** MINOR — cosmetic inconsistency in a Dutch UI

**Root cause:** The confirmation title uses the edition post_title (which contains English date from `post_title` generation), while the sidebar formats the `start_date` meta through `stride_format_date()`.

**Files:** `templates/forms/enrollment/step-confirm.php`, edition post title generation
**Resolution:** `enrollment.php` now enriches `item_data` with course title, formatted date, and venue from `edition_data`. Confirmation step shows course name + Dutch formatted date separately.

---

## Clusters

### Cluster A: Email System (BUG-2, BUG-3)
Both relate to how `ndmail` auto-dispatch handles enrollment email routing. Fix: ensure admin templates explicitly route to admin email, and prevent duplicate dispatch.

### Cluster B: Price Display (BUG-5)
Single fix: pass `$isMember` based on current user to sidebar price display.

### Cluster C: Data Integrity (BUG-1)
Single fix: update registration row with `quote_id` after quote creation.

### Cluster D: UX Guards (BUG-4, BUG-6)
Both are missing frontend validation/guards. Fix independently.

---

## Not Bugs (Expected Behavior)

- **Empty billing fields:** User has no `billing_*` meta — expected for first enrollment
- **Non-member pricing applied:** User lacks `is_member` meta — correct per business logic
- **Seed email mismatch:** CLAUDE.md says `seed_student1@seed.test`, actual is `student1@seed.test` — documentation issue only

---

## Manual Checks Needed

1. [ ] Open enrollment form on mobile — does the 2-column layout collapse properly?
2. [ ] Complete colleague enrollment — does new user creation work end-to-end?
3. [ ] Test "Particulier" enrollment path — are VAT/GLN fields properly hidden?
4. [ ] Test voucher code flow — enter valid voucher, verify discount appears and persists to quote
5. [ ] Verify quote PDF content — does the PDF show correct pricing and participant details?
