# Shake-out Manifest: Enrollment Completion Tasks

**Date:** 2026-03-31
**Scope:** Enrollment-phase completion: questionnaire, documents, session selection, admin approval, auto-confirm
**Tested as:** admin (user 1) with 3 pending registrations

## Summary

| Severity | Count | Resolved |
|----------|-------|----------|
| CRITICAL | 0 | 0 |
| IMPORTANT | 1 | 1 |
| MINOR | 1 | 1 |
| **Total** | **2** | **2** |

---

## Bugs

### BUG-ECT-1: Select dropdown icon has no right padding [MINOR]

**What was tested:** Questionnaire with select field on completion page
**Expected:** Select dropdown icon (chevron) has proper spacing from the field border
**Actual:** Icon is flush against the right edge — no padding

**Root cause:** CSS styling for native `<select>` elements in the dynamic field template doesn't account for the browser's native dropdown arrow spacing.

**Impact:** Cosmetic — slightly cramped appearance on select fields.

**Files:** `templates/forms/fields/dynamic-field.php` or theme CSS (`.input-text` for select elements)

**RESOLVED:** Changed `class="input-text"` to `class="input-select"` on the `<select>` element in dynamic-field.php. The `.input-select` class includes `appearance-none`, custom chevron background-image, and `padding-right: 2.5rem`.

---

### BUG-ECT-2: Approval card doesn't unlock dynamically after completing prerequisites [IMPORTANT]

**What was tested:** Complete questionnaire + documents, observe approval card state
**Expected:** After both tasks complete, "Goedkeuring beheerder" card unlocks immediately with "Klaar voor beoordeling"
**Actual:** Card stays locked with "Wacht op vragenlijst en documenten." until page reload. After reload, card correctly shows unlocked state.

**Root cause:** The approval card's locked/unlocked state is server-rendered via PHP (`$isLocked`, `$state`). The Alpine `completionPage` component updates task statuses and progress bar on the client, but doesn't re-evaluate dependent task availability. The card body's `x-show` and `disabled` attributes are hard-coded from the initial PHP render.

**Impact:** UX friction — user completes prerequisites and expects the approval card to update, but it stays locked. They need to reload to see the correct state. Not blocking — the approval card is informational only (user can't action it), and the message "Een beheerder zal je inschrijving beoordelen" is the same regardless.

**Fix direction:** Either (a) add Alpine reactivity to task card state based on `tasks` object changes, or (b) reload the page automatically after the last prerequisite task completes (simpler, less code). Option (b) is arguably better UX anyway — fresh server state.

**Files:** `web/app/themes/stridence/forms/completion.php` (card rendering logic)

**RESOLVED:** After a task completes, if there are still remaining tasks, the page now reloads (`window.location.reload()`) instead of just updating Alpine state. This refreshes server-rendered task availability so approval cards unlock immediately. When all tasks are complete, still redirects to dashboard as before.

---

## What Works Well

| Flow | Result |
|------|--------|
| **Questionnaire (intake)** | Select field renders, value saves, task completes, progress updates. Data persisted: `{ervaring: "bedreven"}` |
| **Document upload** | File validation works (rejected .txt, accepted .pdf). Upload completes, task marked done, files attached as WP media |
| **Document rejection** | Correct error: "ongeldig bestandstype. Toegestaan: PDF, JPG, PNG, DOC, DOCX" |
| **Admin approval** | `completeTask('approval')` marks task done, auto-confirm fires, status → confirmed, LearnDash access granted |
| **Session selection** | Slot grouping renders correctly (Verdieping A/B/C). Selection persists to `selections` column. Auto-confirm fires. Redirects to dashboard |
| **Auto-confirm (no approval)** | Documents-only (reg 3289): completes → auto-confirms immediately |
| **Auto-confirm (with approval)** | Questionnaire+documents+approval (reg 3288): auto-confirms after approval task done |
| **Completed redirect** | Revisiting `/voltooien/` after all tasks done → redirects to edition page (correct) |
| **Deadline display** | Session selection shows "Kies voor 28 apr 2026" deadline |
| **Progress bar** | Updates reactively on task completion (0% → 33% → 67%) |
| **Locked task state** | Approval card locked until prerequisites done, with reason text |

---

## Previously Fixed (this session)

These bugs were found and fixed in the completion shake-out earlier today:
- **CV2-1:** `EnrollmentFieldGroups` class missing → fatal (FIXED: use QuestionnaireRepository)
- **CV2-2:** Document upload hardcodes task key (FIXED: pass task_type)
- **CV2-5:** Scale field `form.extra_fields` missing in completion context (FIXED: provide x-data wrapper)
- **Questionnaire stage mapping:** `questionnaire` → `intake` stage (FIXED: just before this sweep)
- **Edition vs course lookup:** Field groups assigned to editions but looked up via course ID (FIXED: check both)
