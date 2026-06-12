# Shake-Out Manifest: Questionnaire System

**Date:** 2026-03-22
**Branch:** staging
**Sweep scope:** Questionnaire module — admin builder, interest form, enrollment integration, shortcodes, API actions

---

## Sweep Results Summary

| Area | Status | Notes |
|------|--------|-------|
| Unit tests (633) | PASS | All green |
| WP-CLI smoke test (12 checks) | PASS | All components load and resolve |
| Admin builder page | PASS | Loads, groups add/save/collapse, fields add with type picker |
| Admin builder save + persistence | PASS | Data round-trips to wp_options correctly |
| Interest form (frontend) | PASS | Renders, submits, shows success, no JS errors |
| Interest DB record | BUG | user_id is 0 instead of NULL |
| Admin email notification | PASS | Email sent to admin with correct subject |
| Enrollment form | PASS | Loads with edition, no PHP errors, no JS errors |
| PHP error log | PASS | No debug.log entries |
| Composer autoload | PASS | Fixed during smoke test (require_once added) |

---

## Bugs Found

### BUG-1: Interest registration stores user_id=0 instead of NULL (MINOR)

**Context:** Anonymous interest form submission
**Symptom:** `stride_vad_registrations` row has `user_id = 0` instead of `user_id = NULL`
**Expected:** `user_id` should be NULL for anonymous interest registrations per spec
**Cause:** Likely `RegistrationRepository::create()` passes `0` (from empty/null coercion) instead of explicit NULL, or `$wpdb->insert()` converts NULL to 0
**Impact:** Low — `findByEmailAndEdition()` uses JSON email lookup, not user_id. But semantically wrong and could cause issues with future user_id joins.
**Severity:** MINOR
**Status:** RESOLVED — `absint(null)` returns 0. Fixed to use `isset($data['user_id']) ? absint($data['user_id']) : null`. Also added guard to skip duplicate check when user_id is null. Commit: `4a901ba4`.

---

## Manual Checks Needed

These need human browser testing. Report findings and I'll add to the manifest.

1. [ ] Open admin builder, create a group with all 7 field types — verify each type shows correct config fields
2. [ ] Test drag-to-reorder of groups and field cards — does sorting persist after save?
3. [ ] Test Select2 assignment picker — can you assign to specific editions and to "Alle edities"?
4. [ ] Test interest form on mobile viewport — layout still clean?
5. [ ] Navigate to enrollment form, complete an enrollment — does the flow still work end-to-end?
6. [ ] Check admin builder visual quality — do the card styles, type pills, and stage badges look polished?
