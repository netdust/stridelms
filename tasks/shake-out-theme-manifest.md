# Shake-out Manifest: Stridence Theme (Frontend)

**Date:** 2026-03-21
**Scope:** Public pages, dashboard, shortcodes, assets

## Summary

| Severity | Count |
|----------|-------|
| CRITICAL | 0 |
| IMPORTANT | 2 |
| MINOR | 2 |
| **Total** | **4** |

---

## Bugs

### BUG-T1: 7 footer/navigation links return 404 [IMPORTANT]

**What was tested:** curl status codes for all linked pages
**Expected:** All pages in footer/navigation exist and return 200
**Actual:** These pages return 404:
- `/agenda/` — 404 (page doesn't exist)
- `/contact/` — 404
- `/faq/` — 404
- `/over-ons/` — 404
- `/privacy/` — 404 (page in draft)
- `/voorwaarden/` — 404
- `/profiel/` — 404

**Severity:** IMPORTANT — broken links visible in every page footer. Users clicking these get a 404.

**Fix:** Create these pages in WordPress (or remove links from footer if content not ready).

---

### BUG-T2: `tab-offertes.php:176` foreach warning on string items [IMPORTANT]

**What was tested:** PHP debug.log review
**Expected:** `$quote['items']` is always an array
**Actual:** Sometimes `$quote['items']` is a serialized string instead of an unserialized array, causing `foreach()` warning

**Files:** `templates/dashboard/tab-offertes.php:176`

---

### BUG-T3: LearnDash ProPanel script dependency notice [MINOR]

**What was tested:** debug.log
**Expected:** No script errors
**Actual:** `ld-propanel-script` enqueued with unregistered dependencies — LearnDash Hub inactive

---

### BUG-T4: 11 shortcodes from architecture spec not registered [MINOR]

**What was tested:** Shortcode registration check
**Expected:** All `stride_*` shortcodes from CLAUDE.md spec registered
**Actual:** Only 5 of 16 registered (`stride_enrollment`, `stride_interest`, `stride_my_courses`, `stride_my_quotes`, `stride_my_activity`). Missing 11 may be planned but not yet implemented.

**Severity:** MINOR — likely expected for current project phase.

---

## Not Bugs (Verified Working)

- All public content pages return 200 (homepage, klassikaal, trajecten, online, opleidingen, cursussen)
- Edition detail pages render correctly
- Login/register pages work
- Mijn Account page properly auth-gated
- No raw shortcodes on any page
- No PHP errors in rendered HTML
- Vite/theme assets loading correctly
- `functions.php` loads without errors
