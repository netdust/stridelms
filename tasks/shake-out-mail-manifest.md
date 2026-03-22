# Bug Manifest — netdust-mail

**Generated:** 2026-03-21
**Build status:** 190 unit tests + 16 integration tests pass, plugin active, 12 templates seeded
**Sweep status:** Automated [12 checks] + admin page browser check

---

## Summary

1 issue found: 0 critical, 0 important, 1 minor

---

## Root Cause Clusters

### Standalone
- BUG-001 — CDN dependency for Alpine.js

---

## Bug List

### BUG-001 [MINOR] — Alpine.js loaded from CDN

- **Found by:** Automated (network request inspection)
- **What happened:** AdminController loads Alpine.js from `cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js`. Same issue previously fixed in ntdst-audit and avoided in ntdst-assistant.
- **Expected:** Self-hosted Alpine.js from local plugin assets
- **Where:** `AdminController::enqueueAssets()` line 77-78
- **Cluster:** Standalone
- **Status:** OPEN
- **Root cause:** Initial implementation used CDN for convenience. Not yet migrated to self-hosted.
- **Fix:**

---

## Fix Log

| Bug | Attempts | Root Cause | Fix | Re-sweep |
|-----|----------|-----------|-----|----------|
| BUG-001 | | | | |

---

## Final Status

**Resolved:** 0
**Deferred:** 0
**New bugs found during fix:** 0
**Final sweep:** PENDING
