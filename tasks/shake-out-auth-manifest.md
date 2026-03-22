# Bug Manifest — ntdst-auth

**Generated:** 2026-03-21
**Build status:** Unit tests pass, 13 acceptance tests pass, plugin active
**Sweep status:** Automated [15 checks] + existing Codeception tests (13 pass)

---

## Summary

1 issue found: 1 critical

---

## Root Cause Clusters

### Cluster A: Rate limit increment missing
- BUG-001 — password login and registration never increment rate limit counters

---

## Bug List

### BUG-001 [CRITICAL] — Password login and registration rate limiting is non-functional

- **Found by:** Automated (code review)
- **What happened:** `AuthService::loginWithPassword()` calls `$this->tokens->isRateLimited('login_ip_...')` but never calls `$this->tokens->incrementRateLimit('login_ip_...')` after a login attempt. Same for `RegistrationService::register()` with `register_ip_`. The rate limit transient is never set, so `isRateLimited()` always returns false.
- **Expected:** After each password login attempt (success or fail), the rate limit counter should increment. After N attempts within the window, further attempts should be blocked. Same for registration.
- **Where:** `AuthService::loginWithPassword()` (line ~240) and `RegistrationService::register()` (line ~85)
- **Cluster:** A
- **Status:** OPEN
- **Root cause:** `incrementRateLimit()` is a private method on TokenHelper. The magic link flow calls it internally within `createMagicLinkToken()`, but the password login and registration flows don't have equivalent increment calls. The method needs to be made accessible, or rate limiting logic needs to be added to those flows.
- **Fix:**

---

## Fix Log

| Bug | Attempts | Root Cause | Fix | Re-sweep |
|-----|----------|-----------|-----|----------|
| BUG-001 | 1 | `incrementRateLimit()` private, never called from login/register | Made public, added calls in `loginWithPassword()` and `register()` (360638da) | PASS — acceptance test confirms transient created |

---

## Final Status

**Resolved:** 1
**Deferred:** 0
**New bugs found during fix:** 0
**Final sweep:** PASS
