# Bug Manifest — ntdst-assistant Phase 2

**Generated:** 2026-03-21
**Plan:** `docs/superpowers/plans/2026-03-20-assistant-phase2.md`
**Build status:** Unit tests pass (600), all tasks 8-16 complete
**Sweep status:** Automated [14 checks] + Manual [pending]

---

## Summary

3 issues found: 1 critical, 1 important, 1 minor

---

## Root Cause Clusters

### Standalone
- BUG-001 — Download endpoint auth fundamentally broken
- BUG-002 — Core abilities trigger confirmation flow
- BUG-003 — Cron event not scheduled

---

## Bug List

### BUG-001 [CRITICAL] — Download endpoint returns 401 from browser

- **Found by:** Automated (code analysis + user report)
- **What happened:** Clicking "Downloaden" on a download card opens a new tab that returns `{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":401}}`
- **Expected:** CSV file download
- **Where:** `ChatController::handleDownload()` + `ChatController::checkDownloadPermission()`
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:** WordPress REST API deliberately ignores cookie auth without `X-WP-Nonce` header (CSRF protection). `window.open(dl.url)` sends cookies but no nonce header. The `is_user_logged_in()` permission callback returns false because WP sets current_user to 0 without nonce validation. The HMAC-signed URL already provides security (user-bound, time-limited), so the endpoint should use `__return_true` and rely solely on the signed token.
- **Fix:**

### BUG-002 [IMPORTANT] — Core WP abilities treated as write operations

- **Found by:** Automated (WP-CLI ability inspection)
- **What happened:** `core/get-site-info`, `core/get-user-info`, `core/get-environment-info` have `readonly` at `meta.annotations.readonly` but NOT at `meta.readonly`. AbilityBridge checks `get_meta_item('readonly')` which returns false for these. If Claude calls a core ability, it triggers the confirmation dialog for a read-only operation.
- **Expected:** Core abilities should execute without confirmation
- **Where:** `AbilityBridge::execute()` line 94 — `$ability->get_meta_item('readonly', false)`
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:** WP 6.9 Abilities API stores `readonly` in `annotations.readonly`, not at top level. Stride abilities set both (belt-and-suspenders), but core abilities only set annotations. The check needs to fall back to `annotations.readonly`.
- **Fix:**

### BUG-003 [MINOR] — Export cleanup cron event not scheduled

- **Found by:** Automated (WP-CLI cron list)
- **What happened:** `ntdst_assistant_cleanup_exports` hook handler is registered but no cron event is scheduled. The `register_activation_hook` only fires on plugin activation — since the cron code was added after initial activation, it never ran.
- **Expected:** Hourly cron event cleaning up expired CSV files
- **Where:** `ntdst-assistant.php` — activation hook
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:** `register_activation_hook` only fires on plugin activation, not on code deploy. Need to also schedule on init if not already scheduled.
- **Fix:**

---

## Fix Log

| Bug | Attempts | Root Cause | Fix | Re-sweep |
|-----|----------|-----------|-----|----------|
| BUG-001 | 1 | WP REST ignores cookies without nonce | `__return_true` + uid in signed URL (ac7b6979) | PASS — CSV downloads via curl with signed URL |
| BUG-002 | 1 | Core abilities use `annotations.readonly`, not top-level | Fall back to `annotations.readonly` in AbilityBridge (48c86b25) | PASS — `core/get-site-info` detects as readonly |
| BUG-003 | 1 | `register_activation_hook` only fires on activation | Schedule on `init` instead (300f0c10) | PASS — cron event appears after page load |

---

## Final Status

**Resolved:** 3
**Deferred:** 0
**New bugs found during fix:** 0
**Final sweep:** PASS
