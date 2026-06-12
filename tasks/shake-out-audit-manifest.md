# Bug Manifest — ntdst-audit

**Generated:** 2026-03-21
**Build status:** Unit tests pass (57 tests), plugin active
**Sweep status:** Automated [12 checks]

---

## Summary

4 issues found: 1 critical, 1 important, 2 minor

---

## Root Cause Clusters

### Standalone
- BUG-001 — Action sanitization strips `/` from ability names
- BUG-002 — Orphaned `stride_audit_cleanup` cron event
- BUG-003 — Table existence check runs on every request
- BUG-004 — CDN dependencies for Alpine.js and Flatpickr

---

## Bug List

### BUG-001 [CRITICAL] — Action sanitization strips `/` from assistant ability names

- **Found by:** Automated (database inspection)
- **What happened:** Assistant ability actions stored as `assistant.strideget-editions` instead of `assistant.stride/get-editions`. The `/` separator is silently stripped.
- **Expected:** Action should preserve the `/` in ability names like `stride/get-editions`
- **Where:** `AuditRepository::insert()` line 32 — `preg_replace('/[^a-z0-9._-]/', '', ...)`
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:** The regex `[^a-z0-9._-]` strips all characters except alphanumeric, dots, underscores, and hyphens. The `/` in ability names like `stride/get-editions` is not in the allowed set.
- **Fix:**

### BUG-002 [IMPORTANT] — Orphaned `stride_audit_cleanup` cron event

- **Found by:** Automated (WP-CLI cron inspection)
- **What happened:** Two audit cron events exist: `ntdst_audit_cleanup` (has handler) and `stride_audit_cleanup` (no handler). The orphaned event fires weekly but does nothing.
- **Expected:** Only one cron event with a working handler
- **Where:** WordPress cron table
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:** The cron hook was renamed from `stride_audit_cleanup` to `ntdst_audit_cleanup` at some point, but the old event was never cleared.
- **Fix:**

### BUG-003 [MINOR] — Table existence check on every request

- **Found by:** Automated (code review)
- **What happened:** `AuditService::__construct()` calls `AuditTable::exists()` which runs `SHOW TABLES LIKE ...` on every admin page load. The table won't disappear — this is a wasted query.
- **Expected:** Check once (on activation), not on every request
- **Where:** `AuditService::__construct()` lines 31-33
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:** Defensive coding from initial development. Should use an option flag or remove entirely.
- **Fix:**

### BUG-004 [MINOR] — CDN dependencies for Alpine.js and Flatpickr

- **Found by:** Automated (code review)
- **What happened:** AdminController loads Alpine.js and Flatpickr from `cdn.jsdelivr.net`. This could fail if CDN is blocked by CSP, ad blockers, or network issues.
- **Expected:** Self-hosted assets (as done in ntdst-assistant)
- **Where:** `AdminController::enqueueAssets()` lines 62-72
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:** Initial implementation used CDN for convenience. Not yet migrated to self-hosted.
- **Fix:**

---

## Fix Log

| Bug | Attempts | Root Cause | Fix | Re-sweep |
|-----|----------|-----------|-----|----------|
| BUG-001 | 1 | Regex `[^a-z0-9._-]` strips `/` | Added `/` to allowed chars (d19747c2) | PASS — `stride/verify-fix` stored correctly |
| BUG-002 | 1 | Hook renamed, old cron never cleared | One-time cleanup in init (7d164489) | PASS — only `ntdst_audit_cleanup` remains |
| BUG-003 | 1 | `SHOW TABLES` on every request | Option flag `ntdst_audit_table_created` (7d164489) | PASS — option set, no more per-request query |
| BUG-004 | 1 | CDN dependencies | Self-hosted Alpine.js + Flatpickr (4487ce27) | PASS — page loads, no JS errors |

---

## Final Status

**Resolved:** 4
**Deferred:** 0
**New bugs found during fix:** 0
**Final sweep:** PASS
