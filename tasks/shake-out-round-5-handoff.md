# Shake-out Round 5 — Handoff

**Status:** Phase 1 (sweep) + Phase 2 (manifest) complete. Phase 3 (fixes) NOT started.
**Date paused:** 2026-05-16
**Resume by:** Reading this file + `tasks/shake-out-manifest-5.md`.

---

## Where we are

- Round 5 scope = public-facing pages (anonymous + authenticated)
- Baseline before sweep: Unit 706, Integration 261, Acceptance 99 — all green
- 14 bugs found across 8 clusters. Manifest at `tasks/shake-out-manifest-5.md`.
- User authorised: **fix all CRITICAL + IMPORTANT, also include the trajectory bugs (B5-005, B5-006).**
- Phase 3 was about to start with B5-001 (three unregistered shortcodes); first investigation step was rejected so the user could break.

---

## What to fix (in this order)

Work one bug at a time. Each one MUST go through `superpowers:systematic-debugging` Phase 1 → 4. No batch fixes. Re-sweep after each CRITICAL fix.

### CRITICAL
1. **B5-001** — Three shortcodes not registered: `stride_quote_detail`, `stride_session_selection`, `stride_trajectory_catalog`. Pages render raw `[stride_quote_detail]` etc.
   - Affected pages: `/offerte/` (2545), `/sessiekeuze/` (1334), `/trajecten-page/` (1330)
   - First investigation step (rejected): `grep -rEln "stride_quote_detail|stride_session_selection|stride_trajectory_catalog" web/app/` to determine if classes exist or need writing from scratch.
2. **B5-002** — Footer pages 14542–14545 (`/agenda/`, `/contact/`, `/faq/`, `/over-ons/`) contain leftover SafeAndSound English content ("Pick a night. Get on the list." etc.).
   - LAUNCH-CHECKLIST §D.3 originally created Dutch placeholders — they were overwritten somewhere. WP admin edit job, no code.
3. **B5-003** — `/login/` renders two `<title>` tags. Likely ntdst-auth template + theme wrapper both printing one.

### IMPORTANT
4. **B5-004** — `/registreren/` + `/wachtwoord-vergeten/` return 404. Decide spec first: ship them or document `/login/` as the single auth route.
5. **B5-005** — `/trajecten-page/` raw shortcode. Same root cause as B5-001 (third shortcode). May resolve naturally with B5-001 OR by unpublishing the page per the trajectory decision (B5-006).
6. **B5-006** — Duplicate `/trajecten/` (CPT archive) and `/trajecten-page/` (WP page). Decide which is canonical; 404 or unpublish the other.
7. **B5-007** — WP admin toolbar visible to logged-in students. `show_admin_bar(false)` for non-admin roles. Check ntdst-auth or theme bootstrap.
8. **B5-008** — Tin-Canny LearnDash JS (`runtime.min.js`, `vendors.min.js`, `wp-h5p-xapi.js`) loads on every public page. Dequeue outside LD context.

---

## Tasks already created (TaskList state)

```
#1 [completed] Phase 1A: run existing test suites
#2 [completed] Phase 1B: smoke test all public routes
#3 [completed] Phase 1C: shortcode rendering & content check
#4 [completed] Phase 1D: browser flow checks (chrome-devtools)
#5 [completed] Phase 2: compile manifest, get sign-off
#6 [in_progress] Phase 3: fix bugs via systematic-debugging  ← resume here
```

Next session: keep Task #6 as the umbrella, optionally split into per-bug subtasks.

---

## Useful artifacts from this session

- `tasks/shake-out-manifest-5.md` — full manifest with severity, root-cause hypotheses, clusters, fix-order table.
- Browser session screenshots/markdown: `/home/ntdst/.cache/superpowers/browser/2026-05-16/session-1778929653106/` (001–007).
- Seed test-login URL (works in DDEV):
  ```
  https://stride.ddev.site/?stride_test_login=1&user_id=3194&test_key=0dab30896202fd5d617be0f9bd6837b9
  ```
  (user_id 3194 = `seed_student1`, key derived from `STRIDE_TEST_LOGIN_SECRET` in `web/app/mu-plugins/test-login-helper.php`)

---

## Out of scope for Round 5 (do not start)

- Multi-brand client mu-plugin visual regression
- LTI integration (post-launch)
- Trajectory module functional testing beyond B5-005/B5-006 routing decision
- MINOR items B5-009 through B5-014 (privacy boilerplate, sitemap, robots, LD course-grid skinning) — defer or roll into a separate polish pass

---

## Resume command

```
Continue shake-out Round 5 Phase 3. Read tasks/shake-out-round-5-handoff.md
and tasks/shake-out-manifest-5.md, then start with B5-001 via
superpowers:systematic-debugging.
```
