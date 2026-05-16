# Shake-out Manifest — 2026-05-15

Scope: enrollment flow, voucher logic, user dashboard, course/edition settings, session selection (keuzecursus).
User scope: `seed_student1` (member, 3194), `seed_student3` (non-member, 3196), `seed_admin` (3191).

**Status: PHASE 3 COMPLETE.** 6 fixed, 3 deferred per user decision. Regression suite green:
- Unit 694/694, Integration 246/246, Acceptance 106/106 ✅

| ID | Severity | Status |
|----|----------|--------|
| B-001 | CRITICAL | ✅ Fixed — readers accept `max_selections` (canonical) + `pick_count` (legacy fallback). DB migrated. +4 unit regression tests. |
| B-002 | CRITICAL | ✅ Fixed — same migration normalises JSON-string slot rows to PHP arrays. Seed script writes arrays going forward. |
| B-003 | IMPORTANT | ✅ Fixed — `inschrijvingen` + `meldingen` page titles added to `$page_titles`; duplicate `<h2>` removed from `tab-meldingen.php`. |
| B-004 | IMPORTANT | ⏸ DEFERRED post-launch per user decision (voucher member-only enforcement). |
| B-005 | IMPORTANT | ✅ Fixed — `AdminEditionCest::_after()` now strips empty-title `vad_session` rows. 54 historical residue rows removed in first run. |
| B-006 | MINOR | ✅ Fixed — `MetaboxGenerator` only enqueues `theme-services.js` when the theme ships it (defensive `file_exists`). |
| B-007 | MINOR | ⏸ DEFERRED — test-design note (not a production bug). |
| B-008 | MINOR | ⏸ DEFERRED post-launch — `prive` enrollment server-side semantics. |
| B-009 | MINOR | ✅ Fixed — 133 test residue posts deleted via `scripts/cleanup-test-residue.php` (86 editions + 47 vouchers). |

---

## Bugs found

### CRITICAL

#### B-001 — Session slot key inconsistency (`pick_count` vs `max_selections`)

**Symptom:** Admin sets "Max selecties = 2" in EditionSessionsMetabox. Validation logic reads `pick_count` (default 1). User can only pick 1 session, ignoring admin setting.

**Cluster — same root cause:**
- `EditionSessionsMetabox.php:368` writes `max_selections` (form input name)
- `EditionAdminController.php:438` saves as `max_selections`
- `SessionSelection.php:215` reads `pick_count`
- `task-session_selection.php:167` reads `pick_count`
- `single-vad_edition.php:278` reads `max_selections`
- `TrajectorySelection.php:207` reads `pick_count` (probably trajectory-elective context, different)

**Impact:** Keuzecursus editions where "kies N uit M" with N > 1 are broken — user limited to 1 even when admin set higher. Hits 13247, 13311 (currently both N=1, accidentally works). Direct production blocker for any keuzecursus with N>1.

**Root cause hypothesis:** Admin UI was renamed from `pick_count` to `max_selections` (more accurate name) but business logic + completion UI weren't migrated. Mixed legacy.

**Fix proposal:** Standardise on **one** key. `max_selections` is more accurate (could exceed 1). Migrate all readers to `max_selections`, with `pick_count` fallback for in-DB legacy data. Plus DB migration to normalise existing records.

**Files to touch:**
- `Modules/Edition/SessionSelection.php` (line 215)
- `themes/stridence/templates/forms/completion/task-session_selection.php` (line 167)
- migration: scan `_ntdst_session_slots` records, rename key per slot

---

#### B-002 — `_ntdst_session_slots` storage format inconsistency

**Symptom:** Edition 13247 stores slot config as raw **JSON string**. Edition 13311 stores it as **PHP-serialized array** (WP default).

**Impact:** Code reading via `get_post_meta()` returns a string for 13247 (unparsed), array for 13311. `SessionSelection::getSlotConfig` has `json_decode` fallback so it works at run-time, but other readers may not handle string. Latent break.

**Root cause hypothesis:** Two write paths exist — one going through admin (PHP serialize), one going through REST/JS (`wp_json_encode` then stored as string). 13247 was likely created or edited via the JS path.

**Fix proposal:** Audit all write paths for `_ntdst_session_slots`. Force `array` shape before `update_post_meta`. Add migration to re-store JSON-string entries as PHP arrays.

---

### IMPORTANT

#### B-003 — Dashboard tabs `inschrijvingen` + `meldingen` have no page heading

**Symptom:** Visiting `/mijn-account/?tab=inschrijvingen` or `?tab=meldingen` shows no `<h1>` or `<h2>` page title. Visually unclear where the user is. Other tabs (offertes, downloads, certificaten, profiel) show "Offertes" / "Downloads" / etc as `<h1>`.

**Root cause:**
- `page-mijn-account.php` has `$page_titles` map with both entries explicitly set to empty string
- `tab-meldingen.php` renders its own `<h2>` but only **inside the non-empty notifications branch** (line 38: `<?php if (!empty($notifications)) : ?>`). Empty state → no heading.
- `tab-inschrijvingen.php` has no heading at all.

**Fix proposal:** Add `'inschrijvingen' => __('Opleidingen', 'stridence')` and `'meldingen' => __('Meldingen', 'stridence')` to `$page_titles`. Optionally remove the duplicate `<h2>` in tab-meldingen.

---

#### B-004 — "Member-only" voucher (`BWEEG-MEMBER`) accepts non-members

**Symptom:** Voucher named `BWEEG-MEMBER` (15% discount) validates successfully for non-member user (3196). No member-restriction enforced.

**Root cause:** No `_ntdst_member_only` meta on voucher; no business rule restricting by `is_vad_member`. Memory entry confirms: "5 voucher categories (member/action/speaker/day/social) — DROPPED 2026-05-14 design decision". The naming is misleading but functional behavior is by-design.

**Operational risk for launch:** If VAD admin assumes the **name** enforces membership, they'll print this code in member-only newsletters and non-members will redeem it. Either:

  - **a.** Document clearly that voucher names don't restrict — admin must use `scope_mode='alleen' / 'behalve'` with member-only editions if they want to restrict.
  - **b.** Add a `member_only` flag to voucher (4 lines: meta + validation check).

**Recommendation:** **(a) for v1**. Add a launch-checklist memo to brief VAD admin on voucher scoping. (b) is post-launch enhancement.

---

#### B-005 — Sessions table pollution from acceptance tests bleeds into prorating

**Symptom:** Edition 13247 (Gezonde Tussendoortjes) has **10 `vad_session` posts** in DB, of which 5 are empty-title artefacts from the `AdminEditionCest::canAddSession` test (created 2026-05-15, titles empty). When admin sets voucher `apply_mode=single_session`, prorating divides subtotal by 10 (artefact count) instead of the real 5 sessions, distorting discount math.

**Root cause:** `canAddSession` test creates real DB rows via admin UI and doesn't clean up. The just-completed acceptance suite added 5 sessions during today's runs.

**Impact:**
- **Dev env**: incorrect prorating math during voucher testing — confuses devs
- **Production**: zero — production DB doesn't have these artefacts
- **Test infra**: poor hygiene; will only get worse if more test runs happen

**Fix proposal:**
- Short term: `DELETE FROM stride_vad_session WHERE post_title='' AND post_date > '2026-05-15'` cleanup
- Long term: `AdminEditionCest::canAddSession` needs `_after()` cleanup of created sessions

---

### MINOR

#### B-006 — Console 404 on every wp-admin edition page

**Symptom:** Loading any edition's admin page logs `Failed to load resource: 404 ()` once. No functional impact, no fatal, no visible error.

**Root cause hypothesis:** A static asset (favicon, sprite, CSS map) is missing or wrong path. Need to identify which URL hits 404.

**Fix proposal:** Quick HAR inspection or DevTools Network tab — identify the offending request, fix the path, register the asset, or remove the reference.

---

#### B-007 — Enrollment redirect timing (3s timeout in test, real flow needs 4-5s)

**Symptom:** In sweep, 3 of 5 enrollment submissions appeared to "not redirect". Was actually a 1.5s `setTimeout` in `enrollment.js:180` + page-load time. With 5s wait, all 5 redirect correctly.

**Status:** Not a production bug. Documenting because it's a test-design pitfall — anyone writing an automated submission test will see this.

**Fix proposal:** None for production. Acceptance/Playwright tests that submit enrollments must wait ≥ 4 s after click.

---

#### B-008 — `prive` enrollment type not server-side recognised

**Symptom:** Form has 3 type choices (`werknemer`, `collega`, `prive`). Server only handles `colleague`/`collega` explicitly. `prive` falls through to `self` branch — gets `enrollment_path='individual'` and a quote with billing data.

**Root cause:** No semantic difference server-side between "self with employer" (werknemer) and "self private" (prive). The client UX shows different fields (no organisation, no VAT for prive) but persisted data is identical to a `werknemer` flow with those fields empty.

**Impact for launch:** Probably acceptable — the data captured is correct (billing fields included, organisation empty). But if VAD wants different invoicing behaviour for private (e.g. no PO number, no BTW), this is invisible today.

**Recommendation:** Defer to post-launch unless VAD reports an invoicing problem.

---

#### B-009 — Acceptance test artefacts persist in production-shaped DB

**Symptom:** Editions 5048, 5053, 5063, 5078 etc. — names like "E2E Test Editie - <timestamp>". Several dozen `vad_edition`, `vad_voucher`, `vad_session` posts from acceptance and Playwright test runs over the past months. Not cleaned up.

**Impact:** Admin "Edities" listing is cluttered. Performance: 100+ test editions in queries vs real seed of 30.

**Fix proposal:** `DELETE FROM stride_posts WHERE post_title LIKE 'E2E Test%' OR post_title LIKE 'Roundtrip%' OR post_title LIKE 'Test Edition%'` + cascade. Add note to acceptance suite that tests creating posts MUST cleanup in `_after()`.

---

## NOT bugs (investigated, false alarms)

- **Edition 13247 redirected to /login in sweep** — race condition between login cookie set and redirect target. Solo curl works. Test isolation issue.
- **Edition 13255 (cancelled) shows 30s timeout in sweep** — actually renders "Inschrijving niet mogelijk / gesloten" page correctly. Sweep selector hit nothing because there's no form to wait for.
- **Webinarreeks (13284) shows 2-step minimal flow despite default form** — `isOnline=true` → forces minimal flow. By design.
- **`prive` user empty `company` doesn't overwrite stored value** — `if (!empty($data['company']))` guard works correctly. Existing value preserved.
- **Member discount appears on non-member** — non-member sees `_ntdst_price_non_member` (higher) which is correct.

---

## Summary

| Severity | Count | Phase 1 launch blocker? |
|----------|-------|-------------------------|
| CRITICAL | 2 | **Yes** (B-001 — keuzecursus N>1 broken; B-002 — slot data inconsistency) |
| IMPORTANT | 3 | B-003 yes (UX), B-004 doc-only, B-005 dev-only |
| MINOR | 4 | No — defer post-launch |

**Recommended Phase 3 order (CRITICAL first):**

1. **B-001** — `pick_count`/`max_selections` standardisation + migration
2. **B-002** — Slot storage format audit + migration
3. **B-003** — Dashboard tab headings
4. **B-004** — Document voucher scoping (no code change needed)
5. **B-005** — Cleanup test artefacts + tighten AdminEditionCest cleanup
6. **B-006** — Identify + fix the 404 asset
7. **B-009** — One-shot DB cleanup of test residue

**Deferred post-launch:**
- B-007 — Test pattern, not production
- B-008 — `prive` semantics, revisit when VAD reports specific need

---

## Process notes

**What worked:**
- Throwaway sweep scripts in PHP + Playwright for fast pattern-discovery without writing regression tests
- Distinguishing dev-data drift (B-005, B-009) from real bugs early prevented chasing flakes
- Multi-user perspective (member 3194 + non-member 3196 + admin 3191) surfaced B-004 immediately

**Open questions for human:**

1. **B-001** — is keuzecursus N>1 actually used in v1, or are all current keuzecursus offerings "pick 1 of N"? If pick-1-only is the v1 spec, this is downgraded to IMPORTANT (still latent for future settings).
2. **B-004** — is "BWEEG-MEMBER" voucher meant to be enforced by code, or is admin-side discipline enough for v1?
3. **B-009** — am I authorised to delete test-artefact editions from dev DB? Or wait for explicit go-ahead?
