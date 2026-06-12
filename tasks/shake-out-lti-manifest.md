# Shake-Out Manifest: netdust-lti Plugin

**Date:** 2026-03-23
**Branch:** staging
**Plugin:** web/app/plugins/netdust-lti/

---

## Bug List

### BUG-1: PlatformRouter tests reference deleted class name (8 errors)
**Severity:** IMPORTANT
**Area:** Tests
**Symptom:** 8 test errors — `Error: Class "NetdustLTI\Platform\PlatformRouter" not found` + 1 `in_array()` error on `class_implements()` returning false.
**Expected:** Tests should reference the current class name `NetdustLTI\Platform\Router`.
**Root cause (suspected):** The class was renamed from `PlatformRouter` to `Router` but `tests/Unit/PlatformRouterTest.php` was not updated.
**Files:** `tests/Unit/PlatformRouterTest.php`, `src/Platform/Router.php`

### BUG-2: JWTBuilder tests expect stale error messages (3 failures)
**Severity:** IMPORTANT
**Area:** Tests
**Symptom:** 3 test failures — `handleAuthCallback()` now validates required parameters first (nonce, redirect_uri) and throws `'Missing required parameters...'` before reaching the state check. Tests expect `'Invalid state parameter'` and `'Tool not found'`.
**Expected:** Tests should match current validation order and error messages.
**Root cause (suspected):** `handleAuthCallback()` was refactored to validate parameters upfront but tests weren't updated.
**Files:** `tests/Unit/JWTBuilderTest.php`, `src/Platform/JWTBuilder.php`

### BUG-3: Platform REST API exposes data publicly (no auth required for GET)
**Severity:** IMPORTANT
**Area:** Security
**Symptom:** `GET /wp-json/wp/v2/lti-platforms` returns full platform data without authentication, including RSA public keys, client IDs, platform IDs, and all endpoint URLs. Write operations (POST/PUT/DELETE) are properly protected.
**Expected:** Platform data should require `manage_options` capability to read, since it contains integration configuration details.
**Root cause (suspected):** The `lti_platform` CPT REST API uses default WordPress permissions which allow public read for published posts.
**Files:** `src/Shared/LTIDataService.php` (CPT registration), potentially needs `permission_callback` on REST controller

### BUG-4: Dashboard tab doesn't load platforms on tab switch (UX)
**Severity:** MINOR
**Area:** Admin JS
**Symptom:** When page loads on Dashboard tab (default), clicking "Platforms" sidebar button should load platforms list. The `loadPlatforms()` only triggers when navigating via hash on page load. Clicking the sidebar button calls `setTab('platforms')` which should trigger `loadTabData('platforms')` → `loadPlatforms()`, but the platforms array stays empty.
**Expected:** Switching tabs via sidebar should load tab data.
**Actual behavior:** Works when navigating via URL hash (`#platforms`), doesn't load when clicking sidebar buttons after initial page load.
**Root cause (suspected):** Likely a timing issue — the `loadPlatforms` fetch may fail silently if the page was loaded with a stale/invalid nonce (the "Session expired" modal appeared). The error notification may not be visible on the Platforms tab content area.
**Files:** `assets/js/lti-admin.js` (lines 68-83, 147-158)

### BUG-5: Logs directory doesn't exist
**Severity:** MINOR
**Area:** Logging
**Symptom:** `wp-content/logs/` directory does not exist. Log writing will fail when an LTI launch or grade passback occurs.
**Expected:** Directory should be created on plugin activation or on first write.
**Files:** Logging logic (needs investigation), `netdust-lti.php` activation hook

### BUG-6: "Session expired" modal on admin page
**Severity:** MINOR
**Area:** Admin UX
**Symptom:** The WordPress "Session expired" modal (`Sessie verlopen — Meld je opnieuw aan`) appears on the LTI settings page. This is a WordPress core behavior when the auth cookies don't fully match the nonce. It persists across page loads in certain login flows.
**Expected:** No session expired modal on a freshly loaded admin page.
**Root cause (suspected):** The custom login flow (Alpine-based `/login` page) may not set all required WP auth cookies (e.g., `wordpress_sec_*` cookie for admin context), causing a nonce/cookie mismatch.
**Note:** This is NOT an LTI plugin bug — it's a Stride auth integration issue. Logging out.

---

## Clusters

### Cluster A: Test suite drift (BUG-1, BUG-2)
Both bugs are caused by code refactoring without updating tests. Fix: update test files to match current class names and validation behavior.

### Cluster B: REST API security (BUG-3)
Standalone — CPT registration needs `show_in_rest` permissions tightened.

### Cluster C: Admin UX issues (BUG-4, BUG-6)
Potentially related — if the nonce/session issue (BUG-6) causes REST API calls to fail silently, it explains why platforms don't load on tab switch (BUG-4). BUG-6 is out of scope for this plugin.

### Standalone (BUG-5)
Logs directory missing — simple fix.

---

## Priority Order

1. **BUG-1** — IMPORTANT (8 test errors, quick fix)
2. **BUG-2** — IMPORTANT (3 test failures, quick fix)
3. **BUG-3** — IMPORTANT (security: public data exposure)
4. **BUG-4** — MINOR (UX: tab switch doesn't load data)
5. **BUG-5** — MINOR (logs directory missing)
6. ~~BUG-6~~ — OUT OF SCOPE (Stride auth, not LTI plugin)

---

## Status

| Bug | Status | Root Cause | Fix |
|-----|--------|------------|-----|
| BUG-1 | RESOLVED | Class renamed `PlatformRouter` → `Router`, tests not updated | Added `use ... as PlatformRouter` alias + `wp_unslash` stub |
| BUG-2 | RESOLVED | `handleAuthCallback()` validates nonce/redirect_uri before state now | Updated 3 tests to match current validation order |
| BUG-3 | RESOLVED | CPT `show_in_rest` allows public GET by default | Added `rest_pre_dispatch` filter requiring `manage_options` for LTI CPT routes |
| BUG-4 | RESOLVED | `loadTabData` guarded by `platforms.length === 0`, never retries after silent failure | Always reload on tab switch; removed double-load from setTab+hashchange |
| BUG-5 | NOT A BUG | NTDST Logger creates `wp-content/logs/` lazily on first write | No fix needed |
| BUG-6 | DEFERRED | Stride auth, not LTI plugin | — |
