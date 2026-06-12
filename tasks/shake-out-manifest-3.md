# Shake-out Manifest #3 — 2026-05-16

Scope: admin dashboard (Acties nodig panel, REST hot paths), LearnDash content UX, profile management.

**Status: PHASE 3 COMPLETE.** All 5 bugs fixed. Unit 700/700 + Integration 256/256.

| ID | Severity | Status |
|----|----------|--------|
| B3-001 | CRITICAL→IMPORTANT (latent, no UI path triggers it today) | ✅ Fixed — ProfileHandler partial-update via isset() guards + 3 regression tests |
| B3-002 | Same cluster as B3-001 | ✅ Fixed — wp_update_user payload only includes posted keys; display_name recomputed only when names sent |
| B3-003 | IMPORTANT | ✅ Fixed — `max(1, (int) ($param ?: 20))` on 4 sites (getEditions, getCourseTags, getQuotes, getTrajectories) |
| B3-004 | IMPORTANT | ✅ Fixed — `LearnDashHelper::hasAccess` checks `get_post_type === 'sfwd-courses'` first |
| B3-005 | IMPORTANT | ✅ Fixed — `LearnDashService::grantAccess`/`revokeAccess` add same guard + 3 new unit regression tests |

---

## Bugs found

### CRITICAL

#### B3-001 — Inline profile edit wipes every other field

**Severity:** CRITICAL — launch blocker, user data corruption on every inline edit.

**Symptom:** User opens `/mijn-account/?tab=profiel` and inline-edits a single field (phone). Frontend `inlineEdit` Alpine component sends `{form_type: 'personal', phone: '04...'}`. Backend `ProfileHandler::updatePersonal` then:

- Calls `wp_update_user(['first_name' => '', 'last_name' => '', 'display_name' => ' '])` — wipes the user's name and display_name (falls back to login name).
- Writes empty strings to `phone`, `organisation`, `department` meta.

Result: editing one field wipes **all other personal fields** for that user.

**Verified live:**

  ```
  BEFORE: {"first_name":"Test3","last_name":"User","display_name":"Thomas Bakker","phone":"0471234567","organisation":"Original Org","department":""}
  AFTER:  {"first_name":"","last_name":"","display_name":"seed_student3","phone":"0479999999","organisation":""}
  ```

Same bug cluster in **`updateBilling`** — verified: editing `company` alone wipes vat_number, address, postal_code, city, invoice_email, gln_number. Same shape in **`updateNotifications`** and **`updateProfileType`** by code inspection.

**Root cause:** the handler unconditionally writes every field, defaulting missing input to `''`. The inline-edit caller (correctly) only sends the field being edited. There's no "only update what was sent" guard.

**Fix proposal:** in each `update*` method, only update meta keys whose param was actually present in `$params` (`array_key_exists`, not `isset` — empty string is a valid value the user may want to write). Same for `wp_update_user` — only include keys that were posted.

**Files:**
- `web/app/mu-plugins/stride-core/Handlers/ProfileHandler.php` (4 methods)

---

#### B3-002 — `wp_update_user` called with empty strings overwrites name + display_name

Sub-symptom of B3-001 but called out separately because it has a wider blast radius:

**Symptom:** `wp_update_user(['first_name' => '', 'last_name' => '', 'display_name' => ' '])` on every personal-form update. `display_name = ' '` (single space from `trim('' . ' ' . '')`) → WP falls back to user_login. After ONE inline edit, every user is referred to as `seed_student3` instead of `Thomas Bakker` in email, dashboard headings, etc.

**Files:** same — fixed alongside B3-001.

---

### IMPORTANT

#### B3-003 — `getEditions` + `getQuotes` throw `Division by zero` when `per_page` missing

**Symptom:** calling the methods directly (without going through the REST route) throws `Division by zero` at `AdminAPIController.php:1064` and `:1606`. Both compute `ceil($total / $perPage)` where `$perPage = $request->get_param('per_page')` returned `null` → `(int) null = 0`.

**Impact in production:** **low** — `register_rest_route` declares `per_page` default=20 and applies it BEFORE the callback runs. Real REST consumers never hit the bug.

**Impact in tests / debug / future REST consumers:** **high** — any direct method call without explicit `per_page` crashes. Integration tests that mock requests, healthcheck scripts, secondary REST clients calling the methods through other paths.

**Fix proposal:** defensive `$perPage = max(1, (int) $request->get_param('per_page') ?: 20);` in both methods. Two-line change each.

**Files:**
- `Admin/AdminAPIController.php` lines 717, 725, 1428, 1436 (probably also `getTrajectories` — check it too)

---

#### B3-004 — `LearnDashHelper::hasAccess(nonExistentCourseId, user)` returns true

**Symptom:** calling `hasAccess(99999, $user)` for a course ID that doesn't exist returns `true`. Verified via direct LD call: `sfwd_lms_has_access(99999, 3194) = true`.

**Root cause:** LearnDash core (`sfwd_lms_has_access`) is permissive for non-existent courses. Stride's wrapper passes it through without a `get_post($courseId)->post_type === 'sfwd-courses'` guard.

**Impact:** functions that gate on `hasAccess` will let through deleted-course IDs. Specifically `getCourseAction`, dashboard render of orphaned enrollments. Not a security risk (no actual content to access), but a stale-data hygiene issue.

**Fix proposal:** in `LearnDashHelper::hasAccess`, add `if (get_post_type($courseId) !== 'sfwd-courses') return false;` before the LD call.

**Files:**
- `Integrations/LearnDash/LearnDashHelper.php` line 40 (also: same defensive check in `isComplete`, `getProgress`, `grantAccess` for consistency)

---

#### B3-005 — `LMSAdapter::grantAccess($user, nonExistentCourseId)` returns true

**Symptom:** Grant access to a course that doesn't exist returns `true` and creates orphan user_meta (`course_99999_access_from` + `learndash_course_99999_enrolled_at`).

**Root cause:** `LearnDashService::grantAccess` calls `ld_update_course_access` without first verifying the course exists.

**Impact:** orphan meta accumulates. Dashboard reads `getEnrolledCourses($user)` which uses these meta keys → may include phantom courses.

**Fix proposal:** add `if (get_post_type($courseId) !== 'sfwd-courses') return false;` guard at top of `grantAccess`.

**Files:**
- `Integrations/LearnDash/LearnDashService.php` line 41 (also `revokeAccess` line 52, `isComplete` line 63 for symmetry)

---

## NOT bugs (investigated, false alarms)

- **Admin dashboard render** — Acties nodig panel shows 3 tabs as memory describes, all REST endpoints respond, 0 console errors. Memory's "23 admin bugs gefixt" sprint holds up.
- **`getUserDetail` PII gating (audit H4)** — already fixed: `$canSeeSensitive = current_user_can('stride_manage')` at line 2451. `stride_view` role no longer leaks billing/private fields.
- **`searchUsers q=empty`** — initially flagged as suspicious (returned 10 users). Actually by-design: empty search returns recent users for the "recently viewed" pattern.
- **LearnDash grant/revoke roundtrip** — works correctly, user meta written + read symmetrically.

---

## Summary

| Severity | Count | Phase 1 launch blocker? |
|----------|-------|-------------------------|
| CRITICAL | 2 | **Yes** — B3-001 + B3-002 corrupts user profile on every inline edit |
| IMPORTANT | 3 | B3-003 dev-only, B3-004 + B3-005 stale-data hygiene |

**Phase 3 order:**

1. **B3-001 + B3-002** — ProfileHandler partial-update fix (one commit, both fixed together since same root cause)
2. **B3-003** — per_page defensive default
3. **B3-004 + B3-005** — LD course-exists guards

---

## What worked vs what hit ruis

**Worked:**
- Throwaway PHP shake-out scripts found bugs in minutes that pages of UI testing would have missed (B3-001 was hidden behind inline-edit + REST roundtrip)
- Cross-checking memory's audit findings to confirm fixed status — H4 was already done, didn't re-investigate
- Direct REST method invocation surfaced div-zero that won't fire in production but documents API fragility

**Hit ruis:**
- Initial Playwright admin dashboard sweep showed "everything green" because UI catches REST errors gracefully. Had to drop down to direct method calls to find the real bugs. Lesson: UI sweep is necessary but not sufficient — REST endpoints need their own probe layer.
