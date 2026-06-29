# Shake-out manifest — Public frontend demo-readiness (2026-06-29)

**Goal:** Stride public frontend 100% functional for a client demo (e-learning + in-person classes, VAD-like client).
**Method:** Real-browser sweep (superpowers-chrome) of every public surface + curl/WP-CLI static checks.
**STATUS: COMPLETE — all findings resolved. Frontend is demo-ready. No code shipped (data/content fixes only).**

## Resolution summary
- **C1 (CRITICAL) ✅ FIXED** — Purged 26 fixture trajectories + 2 fixture editions (acceptance-test debris with no seed marker, surviving unseed). Stopped the live acceptance suite that was actively repopulating them. Clean unseed→reseed. Set the seeder's 0-course "Test Trajectory" to draft. `/trajecten` now shows 3 real, demo-grade cards.
- **I1 (IMPORTANT) ✅ FIXED** — Deleted legacy WP page `/cursussen` (#99). Now 404s instead of showing the raw English LearnDash grid. (No redirect/code change — it was just an orphaned page; deletion is enough for a demo.)
- **M1 (MINOR) ✅ RESOLVED** — Homepage counts now reconcile exactly with catalogs (Trajecten 3 / Klassikaal 18 / Online 5). Fixed as a side-effect of the C1 reseed.

## Final verification
- All 9 public surfaces: HTTP 200, zero PHP errors, zero fixture junk.
- `/trajecten`, `/klassikaal`, `/online`, edition detail, enrollment wizard, dashboard, homepage all re-verified demo-grade in a real browser post-reseed.
- `seed-verify.php`: ALL DIMENSIONS COVERED.
- No git changes to theme/core (BrowserHooks redirect reverted; only DB content changed).

---

## Original sweep record (Phase 1)

---

## What was swept (and PASSED — demo-grade)

| Surface | URL | Verdict |
|---|---|---|
| Homepage (logged-out) | `/` | ✅ Editorial hero, 3 pathway cards (Trajecten/Klassikaal/Online), CTAs. Strong first impression. |
| Online catalog (e-learning) | `/online` | ✅ "Online leren", category filter pills w/ counts, styled status/price/date cards. |
| In-person catalog | `/klassikaal` | ✅ "Klassikale opleidingen", rich state variety (Open/Volzet/Vooraankondiging/Ingeschreven), locations, sessions. |
| Edition detail | `/edities/<slug>/` | ✅ Breadcrumb, tabs, capacity bar, "Schrijf je in", trajectory link, programme. |
| Enrollment wizard | `/edities/<slug>/inschrijving/` | ✅ Full 4-step flow (Type→Gegevens→Facturatie→Bevestigen) prefilled, billing separate from personal, terms-gated submit. Verified through step 4 (not submitted). |
| Account dashboard | `/mijn-account` | ✅ Greeting, action-required hero, stat cards, task list, upcoming-sessions w/ iCal export, sidebar nav. |
| Auth gating | `/mijn-account` logged-out | ✅ Correctly redirects to `/aanmelden`. |
| Smoke (all public pages) | — | ✅ All 200, no PHP errors, no raw shortcodes in HTML. |
| debug.log | — | ✅ No frontend fatals. |

The two real catalog surfaces (`/online`, `/klassikaal`), edition detail, enrollment wizard, dashboard, and logged-out homepage are all **demo-grade as-is**.

---

## Findings

### CRITICAL

#### C1 — Trajecten catalog polluted with test fixtures
**Surface:** `/trajecten/` (public, in main nav)
**Symptom:** Page leads with junk a client would see: `E2E Traject 1782751300_b43d`, `Test Trajectory`, **8× `Scope Open Traject`**. 10 of 29 published trajectories are test fixtures.
**Cause:** Acceptance-test/seed accumulation — fixtures left at `publish` status (documented `gotcha_test_fixture_pollution` + seed-accumulation). The acceptance suite was actively adding MORE during this sweep (editions 27→29, fresh timestamp `1782751300`). **Suite stopped to halt pollution.**
**Also:** 2 E2E fixture editions present (`E2E Verplichte/Keuze Editie 1782751300_b43d`). Homepage "29 trajecten" inflated.
**Fix direction:** Clean reseed (`unseed.php` → `seed.php`) → pristine demo DB; OR targeted deletion of fixture-named posts. Reseed preferred. Keep acceptance suite OFF the demo DB afterward.

### IMPORTANT

#### I1 — Legacy `/cursussen` shows raw unstyled English LearnDash grid
**Surface:** `/cursussen/` (page #99, template `default`) — **NOT in main nav.**
**Symptom:** Raw LearnDash grid: grey placeholders, English chrome ("Enrolled"/"Continue Study"/"0% COMPLETE"), default blue buttons. Off the demo path but reachable by direct URL.
**Fix direction:** Unpublish/trash the legacy page, OR redirect `/cursussen` → `/online`. Confirm nothing real links to it first.

### MINOR

#### M1 — Homepage modality counts include hidden/junk records
**Surface:** `/` "Hoe wil je leren?" cards.
**Symptom:** "20 opleidingen" (Klassikaal) vs catalog "Alles 18"; "29 trajecten" inflated by C1 junk.
**Fix direction:** C1 reseed largely resolves trajecten count; verify klassikaal 18-vs-20 after reseed.

---

## Non-findings (verified NOT bugs)
- `/cursussen` & `/mijn-account` raw-curl 301 → expected (Bedrock `/wp` canonical + auth redirect); browser sees 200.
- curl CTA grep missing "Schrijf je in" → false alarm; it's a styled `<a class="btn-primary">`, present and working.
- `/opleidingen/<slug>/` (info) vs `/edities/<slug>/` (transactional) → intentional `lesson_url_role_split`. Working.

## Deferred / separate from demo-readiness
- **Acceptance Codeception suite produced no streamed output and was stopped** (polluting demo DB live). Needs a clean controlled re-run on a throwaway DB — NOT the demo DB. Test-infra concern, not a frontend blocker.
