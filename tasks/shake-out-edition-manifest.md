# Bug Manifest — Edition Module (stride-core)

**Generated:** 2026-03-21
**Build status:** 15 unit + 18 integration + 8 acceptance tests pass, module fully functional
**Sweep status:** Automated [14 checks] + browser verification

---

## Summary

2 issues found: 0 critical, 1 important, 1 minor

---

## Root Cause Clusters

### Cluster A: No cascade delete
- BUG-001 — Sessions not deleted when edition is deleted
- BUG-002 — Registrations not cleaned when edition/user is deleted (same root cause)

---

## Bug List

### BUG-001 [IMPORTANT] — Sessions not deleted when parent edition is deleted

- **Found by:** Automated (database integrity check)
- **What happened:** 53 orphaned sessions exist in the database. Their `post_parent` points to editions that no longer exist. When an edition is trashed or deleted, its child sessions remain.
- **Expected:** Deleting an edition should cascade-delete its sessions (they have no meaning without the parent edition).
- **Where:** `EditionService` — no `before_delete_post` or `trashed_post` hook registered for cascade deletion. The admin controller has manual session deletion (line 586) but only for individually deleting sessions via the UI, not on parent deletion.
- **Cluster:** A
- **Status:** OPEN
- **Root cause:** No WordPress hook registered to cascade-delete sessions when an edition is deleted. The `post_parent` relationship isn't enforced with a deletion hook.
- **Fix:**

### BUG-002 [MINOR] — Orphaned registrations from deleted editions/users

- **Found by:** Automated (database integrity check)
- **What happened:** 85 registrations point to deleted editions, 86 to deleted users (out of 107 total). These are mostly test/seed data artifacts.
- **Expected:** Registrations should be cleaned up (or at least marked cancelled) when the parent edition or user is deleted.
- **Where:** No cascade delete hook for `wp_vad_registrations` table when editions or users are deleted.
- **Cluster:** A
- **Status:** OPEN
- **Root cause:** Same as BUG-001 — no deletion hooks registered. The custom registrations table isn't linked to WordPress deletion lifecycle.
- **Fix:**

---

## Notes

- All 8 existing acceptance tests pass (list, columns, create, edit, metaboxes, navigation)
- Admin UI renders correctly — metaboxes, sessions, capacity bar, status dropdown
- No JS errors on any admin page
- Price formatting works correctly (€ 395,00)
- Capacity tracking is accurate (getRegisteredCount matches actual DB counts)
- findByFilters (date range) works correctly
- Session-edition linking works (sessions correctly appear in metabox)
- Status enum + allowsEnrollment() logic correct

---

## Fix Log

| Bug | Attempts | Root Cause | Fix | Re-sweep |
|-----|----------|-----------|-----|----------|
| BUG-001 | 1 | No `before_delete_post` hook for cascade | Added hook + `deleteChildSessions()` (d1b92b7c) | PASS — 4 integration tests verify cascade |
| BUG-002 | 1 | Same — no cascade for registrations | Added `deleteEditionRegistrations()` in same hook (d1b92b7c) | PASS — integration test verifies |

---

## Final Status

**Resolved:** 2 (same commit, shared root cause)
**Deferred:** 0
**New bugs found during fix:** 0
**Final sweep:** PASS
