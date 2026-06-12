# Voucher scope + apply-mode — Shake-out Manifest

**Date:** 2026-05-14
**Commits in scope:** `ae970344` (feat), `95065b4f` (docs), `4709fef3` (CSS fix)
**Surfaces tested:** voucher list, voucher edit form (3 scope modes + apply_mode), redemption math at 3 call sites, admin list "Editie" column, legacy back-compat, browser-driven save round-trip
**Test data:** 50 legacy vouchers + 83 editions + 71 sessions in dev DB; 4 fresh vouchers created and deleted during shake-out

---

## Summary

| Severity | Open | Notes |
|---|---|---|
| CRITICAL | 0 | — |
| IMPORTANT | 0 | — |
| MINOR | 1 | Cosmetic UI polish in edition pickers |

**1 finding, MINOR.** Voucher scope + apply-mode work correctly end-to-end. Math is exact, persistence is symmetric, legacy back-compat works, JS toggle works, no JS errors, no PHP warnings.

---

## Findings

### FINDING #M1 (MINOR) — Edition pickers show blank entries for editions with empty `post_title`

**Where:** `VoucherAdminController::renderVoucherMetabox()` — both the `scope=only` single-select and the `scope=except` multi-select.

**Symptom:** A published `vad_edition` post (ID 5088) has `post_title = ""`. It appears in both edition pickers as a blank option, confusing admins trying to pick or exclude an edition by name.

**Reproduction:**
```bash
ddev wp post get 5088 --field=post_title  # returns empty
# Open any voucher edit screen; scroll to scope picker; first option is blank.
```

**Why it slipped past the sweep:** the existing `'Beperkt tot editie'` dropdown had the same issue before §C — this is pre-existing UX debt that became more visible because the new multi-select displays more options at once.

**Suggested fix:** Filter `get_posts()` in `renderVoucherMetabox()` to skip empty-title editions, OR render a placeholder (`(geen titel)`) so blank rows aren't silently selectable. The single-select already had this gap pre-§C — fix would benefit both pickers.

**Not a launch blocker.** Admin can still type to filter by edition title; the blank entry is harmless if not picked.

---

## Sweep checklist — final state

### Track A — Automated

- [x] Existing AdminVoucherCest (8 tests) passes
- [x] Smoke test: admin reachable, CPT registered, new helper classes resolve
- [x] Pre-sweep debug.log baseline cleared
- [x] Voucher list page renders with new "Editie" column for all 3 scope modes + legacy
- [x] Truncation "+N meer" works for excluded lists > 3 editions
- [x] Voucher edit form renders correctly for `scope=only` (browser visual check)
- [x] Voucher edit form renders correctly for `scope=except` (browser visual check)
- [x] Voucher edit form renders correctly for `scope=all` (browser visual check)
- [x] Legacy voucher (no `scope_mode`, `edition_id > 0`) renders as "only" mode in form
- [x] Scope radio JS toggle hides/shows correct sub-panels (all 3 transitions)
- [x] No JS errors over 3 radio cycles
- [x] CSS fix: radios render as 16×16 px circles (not stretched lines)
- [x] Save handler persists scope=all correctly
- [x] Save handler persists scope=only + edition_id (clears excluded list)
- [x] Save handler persists scope=except + excluded_edition_ids (clears edition_id)
- [x] Save handler persists apply_mode
- [x] Browser-driven save round-trip preserves all state
- [x] Reload after save shows correct sub-panel + selection
- [x] Redemption math: Full + single_session on 4-session edition = €45 (price/4)
- [x] Redemption math: Percentage 50% + single_session on 4-session edition = €22.50
- [x] Redemption math: Fixed €50 + single_session on 4-session €180 edition = capped at €45
- [x] Redemption math: any mode + 0-session edition silently uses divisor=1
- [x] Redemption math: trajectory path (editionId=null) skips prorating
- [x] Validation: scope=only rejects wrong edition with `wrong_edition` code
- [x] Validation: scope=only accepts correct edition
- [x] Validation: scope=except rejects excluded edition
- [x] Validation: scope=except accepts non-excluded edition
- [x] Validation: scope=any + null editionId (trajectory) always accepts
- [x] Validation: legacy voucher (empty scope_mode + edition_id>0) acts as "only"
- [x] EnrollmentFormHandler::handleValidateVoucher trajectory + edition paths
- [x] Full unit (674) + integration (227) test suites all green
- [x] AdminVoucherCest acceptance (8 tests) all green
- [x] debug.log scan: no voucher-related warnings/notices/fatals during sweep

### Track B — Manual (human verification)

If you want to spot-check the UI yourself before signoff:

1. [ ] Open https://stride.ddev.site/wp/wp-admin/edit.php?post_type=vad_voucher and confirm the "Editie" column shows the scope summary correctly for the 50 existing vouchers
2. [ ] Open one voucher edit screen, click between the 3 scope radios, confirm the right sub-panel appears each time
3. [ ] Change a voucher from scope=all to scope=except, pick 1-2 editions, save, reload — confirm the state persists
4. [ ] Open the multi-select for `scope=except` and check: is the blank-title edition #5088 confusing in practice, or fine?

---

## Recommended actions

1. Acknowledge M1 as known-and-tracked, **not a launch blocker.** Either:
   - Defer to a separate UX-cleanup task in §A or §D, OR
   - Fix during this shake-out as a one-line filter in the edition picker queries

2. Mark §C voucher scope + apply-mode work as **shake-out passed** in `docs/LAUNCH-CHECKLIST.md`.

3. Move on to §D (11 deferred launch-module bugs).

---

## Test artifacts

- New PHPUnit integration tests: 6 added in commit `ae970344` (validationRejectsExcludedEdition, validationAcceptsNonExcludedEdition, validationLegacyEditionIdActsAsOnlyMode, calculatesProratedDiscountForSingleSessionMode, calculatesProratedPercentageDiscount, prorateFallsBackForZeroSessionEdition).
- No Codeception acceptance tests added during shake-out — existing 8 cover the generic CRUD paths; the scope+apply-mode flow is now thoroughly covered by integration tests + manual browser verification documented above.
