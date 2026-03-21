# Shake-out Manifest: Completion Module

**Date:** 2026-03-21
**Scope:** EnrollmentCompletion, EditionCompletion, CompletionTaskHandler, LearnDash integration

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| IMPORTANT | 3 |
| MINOR | 4 |
| **Total** | **7** |

---

## Bugs

### BUG-C1: `getTaskSummary()` crashes on null registration [IMPORTANT]

**What was tested:** Code review of completion task summary
**Expected:** Graceful handling of invalid registration ID
**Actual:** `EnrollmentCompletion.php:445` accesses `$registration->completion_tasks` without null check — fatal error if registration not found

**Files:** `EnrollmentCompletion.php:445`

---

### BUG-C2: Upload handler can't complete `post_documents` task [IMPORTANT]

**What was tested:** Code review of document upload flow
**Expected:** Frontend can upload documents for both `documents` and `post_documents` task types
**Actual:** `CompletionTaskHandler.php:186` hardcodes `'documents'` task type — `post_documents` can never be completed via upload endpoint

**Files:** `CompletionTaskHandler.php:186`

---

### BUG-C3: No LearnDash `learndash_course_completed` hook → Stride sync [IMPORTANT]

**What was tested:** Code review of completion flow
**Expected:** LearnDash native completions update Stride registration status
**Actual:** No listener for `learndash_course_completed` — only `stride/attendance/marked` triggers completion checks. Admin-initiated LD completions don't sync.

**Files:** `EditionCompletion.php` (missing hook)

---

### BUG-C4: Deprecated `current_time('timestamp')` [MINOR]

**Files:** `EnrollmentCompletion.php:120,137`

---

### BUG-C5: `updateCompletionTasks()` doesn't clear cache [MINOR]

**Files:** `RegistrationRepository.php:550`

---

### BUG-C6: `Withdrawn` status in DB but not in PHP enum [MINOR]

**Files:** `RegistrationStatus.php`, `RegistrationTable.php:37`

---

### BUG-C7: Implicit DI dependency between completion classes [MINOR]

**Files:** `EditionCompletion.php:126`
