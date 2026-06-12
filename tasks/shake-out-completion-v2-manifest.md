# Shake-out Manifest: Completion Module v2

**Date:** 2026-03-31
**Scope:** Completion page (in-person post-course, enrollment tasks, edge cases)
**Tested as:** seed_student1 (user 3170) with seeded registrations

## Summary

| Severity | Count | Resolved |
|----------|-------|----------|
| CRITICAL | 1 | 1 |
| IMPORTANT | 3 | 3 |
| MINOR | 1 | 1 |
| **Total** | **5** | **5** |

---

## Bugs

### BUG-CV2-1: Completion page fatals on `EnrollmentFieldGroups` — page half-rendered [CRITICAL]

**What was tested:** Navigate to `/vormingen/{slug}/voltooien/` for editions with post-course tasks (12876, 12884)
**Expected:** Full completion page with all task cards, progress bar, Alpine.js interactivity, footer
**Actual:** Page renders header, progress bar ("0 van 2"), and first task card header ("Evaluatie invullen") but then dies. No second task card (Documents), no footer, no `wp_footer()`, no theme JS/Alpine.js bundle.

**Root cause:** `task-questionnaire.php:31` calls `ntdst_get(EnrollmentFieldGroups::class)` — this class **does not exist** anywhere in the codebase. The DI container throws a fatal, killing the PHP output buffer mid-render. Since the fatal occurs INSIDE `get_header()` → template → (fatal) → never reaches `get_footer()`, the `stridence-main` script (enqueued in footer) never loads, so Alpine.js is absent.

**Impact:**
- ALL completion pages are completely non-functional — no task can be completed
- No Alpine.js = no accordion interaction, no form submission, no upload
- Both post-course and enrollment-phase questionnaire tasks are affected
- The "Documenten uploaden" task card is invisible (render dies before reaching it)

**Fix direction:** Replace `EnrollmentFieldGroups` with `QuestionnaireRepository` (which exists and has `getGroupsForPost()`). Match how `stage-form.php` resolves field groups.

**Files:** `web/app/themes/stridence/templates/forms/completion/task-questionnaire.php:20-31`

**RESOLVED:** Replaced `EnrollmentFieldGroups` with `QuestionnaireRepository::getGroupsForStage()`. Stage derived from task type (`post_evaluation` → `evaluation`, `questionnaire` → `questionnaire`). Also fixed `completeTask()` call to use dynamic task type.

---

### BUG-CV2-2: Documents upload template hardcodes `'documents'` task key — `post_documents` never updates correctly [IMPORTANT]

**What was tested:** Code review of `task-documents.php` when used for `post_documents` via `template_map`
**Expected:** When the completion page renders `post_documents`, the frontend JS should update `tasks['post_documents']`
**Actual:** Line 50: `$data.tasks['documents'] = { status: 'completed' }` — hardcodes `'documents'` regardless of actual task type. Also line 41: `formData.append('registration_id', ...)` doesn't include `task_type`, so the backend handler defaults to `'documents'`.

**Root cause:** The template doesn't receive or use the `$args['task_type']` variable passed from `completion.php:176`. Two issues:
1. **JS success handler (line 50):** Should use `$args['task_type']` to update the correct task key
2. **FormData (missing):** Should append `task_type` so the backend handler knows which task to complete

**Impact:** After uploading documents for post-course, the wrong task gets marked complete in the browser state. Backend also completes wrong task type. For editions with BOTH `documents` and `post_documents`, this would corrupt the task state.

**Files:** `web/app/themes/stridence/templates/forms/completion/task-documents.php:41,50`

**RESOLVED:** Template now reads `$args['task_type']`, appends it to FormData, and uses it in the JS success handler.

---

### BUG-CV2-3: Seed data doesn't initialize enrollment-phase completion tasks for students [IMPORTANT]

**What was tested:** Registration 3117 (seed_student1, edition 12901 with `requires_session_selection`)
**Expected:** `completion_tasks` populated with `{"session_selection":{"status":"pending","phase":"enrollment"}}`
**Actual:** `completion_tasks = NULL`. The seed creates the registration as `confirmed` (not `pending`) and the completion task initialization runs for the admin user's enrollments but NOT for the student's random enrollments.

**Root cause:** In `seed.php`, the student enrollment block (around line 1497) calls `buildInitialTasks()` but only for the admin user's first 3 open editions. The student enrollments to editions with requirements (12901) are created without completion tasks because the seed code at line 1513-1524 only runs for admin enrollments, not student enrollments.

**Impact:** Can't test enrollment-phase completion (session selection, questionnaire, documents, approval) as a student because no seed data exists with enrollment-phase tasks. The `/voltooien/` route correctly redirects when `completion_tasks` is empty, so no crash, but no test coverage.

**Files:** `scripts/seed.php` — student enrollment section lacks completion task initialization for enrollment-requirement editions

**RESOLVED:** Student enrollment loop now checks for enrollment requirements, sets `status=pending` for editions with requirements, and calls `buildInitialTasks()` to populate `completion_tasks`.

---

### BUG-CV2-4: Evaluation field group exists but unassigned — questionnaire always shows empty [MINOR]

**What was tested:** QuestionnaireRepository field group resolution for edition 12876
**Expected:** Evaluation form renders with configured fields
**Actual:** `getGroupsForPost(12873)` returns `[]` because the one existing field group ("Test Evaluatie") has empty `assignments`. The form would show "Geen vragenlijst geconfigureerd."

**Root cause:** Seed data creates the field group but doesn't assign it to any course. This is a seed data gap, not a code bug.

**Impact:** Even after fixing BUG-CV2-1, the evaluation task would render an empty "no questionnaire configured" message instead of a form. Admin would need to manually assign field groups in settings.

**Files:** `scripts/seed.php` — field group assignment missing

**RESOLVED:** Seed now creates/finds evaluation field group and assigns to courses linked to post-course editions using flat int ID format (matching admin settings page format).

---

## Clusters

### Cluster A: Completion page non-functional (BUG-CV2-1)
The fatal is the root blocker. Must fix first — everything else is unreachable until the page renders.

### Cluster B: Document task type routing (BUG-CV2-2)
Frontend template doesn't pass task_type. Independent of Cluster A but only testable after A is fixed.

### Cluster C: Seed data gaps (BUG-CV2-3, BUG-CV2-4)
Both are seed-only issues. Fix together to enable full test coverage.

---

## Fix Order

1. **BUG-CV2-1** (CRITICAL) — Fix `task-questionnaire.php` to use `QuestionnaireRepository`
2. **BUG-CV2-2** (IMPORTANT) — Fix `task-documents.php` to pass and use `task_type`
3. **BUG-CV2-3** (IMPORTANT) — Fix seed to initialize enrollment-phase tasks for student
4. **BUG-CV2-4** (MINOR) — Fix seed to assign field group to a course

---

### BUG-CV2-5: Scale field `form.extra_fields` binding broken in completion context [IMPORTANT] (found during re-sweep)

**What was tested:** Submitting evaluation form with scale field on completion page
**Expected:** Scale selection (1-5) saved as answer data
**Actual:** JS error "Uncaught ReferenceError: form is not defined" on every scale click. Form submits with empty answers. Task completes but with no evaluation data.

**Root cause:** `field-scale.php:15` binds to `form.extra_fields['field_name']` — the enrollment form's Alpine data shape. The completion page's Alpine component (`completionPage`) doesn't have a `form` property. The scale buttons fire `form.extra_fields[...] = N` which errors.

**Impact:** Evaluation data is lost — admin gets empty evaluation results. The completion task marks as done but has no answers.

**Fix direction:** The scale field needs a `name` attribute on a hidden input that FormData can collect, OR the completion form needs to provide a `form.extra_fields` context. Simplest fix: add a `<input type="hidden" name="fieldname" :value="...">` that the FormData can read.

**Files:** `web/app/themes/stridence/templates/forms/fields/field-scale.php:15,25,33`

**RESOLVED:** Completion questionnaire form now wraps fields in `x-data="{ form: { extra_fields: {} } }"` context, providing the `form.extra_fields` object all dynamic fields expect. Submit handler passes `form.extra_fields` directly as answers. Scale value "5" confirmed saved to DB as `{"beoordeling_docent": 5}`.

---

## Previous Shake-out Deferred Bugs (from v1, 2026-03-21)

These were deferred and remain unaddressed:
- **BUG-C3:** No `learndash_course_completed` → Stride sync hook
- **BUG-C4:** Deprecated `current_time('timestamp')`
- **BUG-C5:** `updateCompletionTasks()` doesn't clear cache
- **BUG-C6:** `Withdrawn` status not in PHP enum
- **BUG-C7:** Implicit DI dependency between completion classes
