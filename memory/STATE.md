# Stride — Project State

Current state of the project for session continuity. Updated after meaningful work.
**For "what's left to launch" see `docs/LAUNCH-CHECKLIST.md` (single source of truth).**

Last refresh: 2026-05-20

---

## Active work: Trajectory cascade + phased choices (started 2026-05-20)

**Status:** Planning complete, implementation not started.

**Two plans, do in order:**
1. `plans/2026-05-20-trajectory-cascade-enrollment.md` — **prereq**, do first. Adds `parent_registration_id` column + `TrajectoryCascadeService`. Locks the parent→child model.
2. `plans/2026-05-20-trajectory-phased-choices.md` — builds on (1). Per-keuzegroep `opens_at` / `deadline` + lazy task creation. Risk #3 in that plan ("is cascade-enrollment missing?") is YES — that's why (1) exists.

**Decisions locked in (2026-05-20, with Stefan):**
- Parent-child link: new column `parent_registration_id` on `wp_vad_registrations`
- Mandatory editions → child created at parent-creation; electives → child at `setSelections()`
- Pure-LD (no edition) → `grantAccess()` + user-meta `_stride_trajectory_courses`
- Reuse existing `TrajectoryMode` enum (`cohort` vs `self_paced`) — no new field
- Cohort: children inherit parent status (cancel cascades)
- Self-paced: children independent (parent cancel does nothing)
- Capacity check on elective choice → blocks with `WP_Error('edition_full')`
- Children skip per-edition `requires_questionnaire` / `requires_documents` / `requires_approval` (those are satisfied at parent level)
- Children have `quote_id = NULL` UNLESS trajectory = €0 AND child > €0 → cascade auto-generates child quote with parent's billing
- Payment never blocks enrollment; access-denial for unpaid = manual admin task

**Step 1 to execute next session:** Schema migration — add `parent_registration_id` column + `idx_parent` index in `Modules/Enrollment/RegistrationTable.php`. Triggered via `dbDelta`. Verify with `wp db query "DESCRIBE wp_vad_registrations"`.

---

## Current Phase: Phase 1 finishing work (mid-flight)

Two parallel goals:

1. **Production-ready Phase 1** — e-learning, blended learning, enrollment flow, enrollment tasks, completion tasks, attendance, invoicing, user dashboard.
2. **Multi-brand demo** — 2–3 distinct brand scaffolds + swap demo for sales.

**Post-launch (do NOT block launch):** Trajectories, Partner API, LTI.

---

## What shipped 2026-05-13

Three commits:
- `8a54c475` Sprint 1 + Track 2 — all 23 dashboard bugs resolved + neutral UX pass + user-detail rework (enrollment / attendance / invoice tables) + empty/loading/error states
- `01b9a346` Phase 3 tail — bulk lock/unlock quotes from edition + customer-facing edit restriction
- `7c5f04f5` Perf benchmark script — confirms `getUserDetail` at 50 enrollments is 13 queries / 5 ms (no N+1)

Plus `ba09bec6` docs commit with the new `docs/LAUNCH-CHECKLIST.md` as the single source of truth.

**Tests:** 674 unit + 221 integration green.

---

## What happened 2026-05-14 → 2026-05-16

- `2026-05-14` — Pre-deep-testing security + performance audits done. 36 findings (3 CRITICAL security + 4 HIGH perf). Reports in `tasks/audit-2026-05-14-{security,performance}.md`. Stale-database-read sweep + 1 fix (`0f47f48f`).
- `2026-05-15` — Kindred client mu-plugin built (coral palette) from `stridelms brand.zip`. Replaces safeandsound as active client. LD skin work ongoing. Design: `docs/superpowers/specs/2026-05-15-stride-client-kindred-design.md`. Recent commits: `81b6b454` (port LD-skin fixes to safeandsound + carecommunity), `89422849` (kindred body.ld-in-focus-mode bottom canvas), `577497e4` (shake-out manifest), `6720bb46` (4 test suites green), `770b361e` (shake-out session slot keys + dashboard headings + admin asset 404).
- `2026-05-16` (earlier this day) — Brainstormed post-launch ideas: conference subsites (`studiedag.stride.be`) + livestreams. Audited Stride's API surface for headless fit. Wrote design `docs/superpowers/specs/2026-05-16-post-launch-capability-plugins-design.md` proposing core+capability-plugin architecture: extract Partner API to own plugin (~2d, refactor), then build new `stride-conference` plugin (~1-2w) with public `stride/v1/public/*` API + structured editorial content. Committed `77b2744c`. Livestreams left for future design.
- `2026-05-16` (later this day) — **Shake-out Round 5 (public-facing pages) — Phase 3 fixes complete.** 7 bugs fixed across 4 clusters: (1) trashed 3 orphan stub pages with raw-shortcode bodies (B5-001/005/006); (2) rewrote 4 footer pages with neutral Dutch copy + removed stale `safeandsound-page-stub.php` page-template meta (B5-002); (3) removed duplicate `<title>` line from all 4 ntdst-auth templates (B5-003); (4) Dutchified auth routes (`/login`→`/aanmelden`, `/register`→`/registreren`) + added native `wp_lostpassword_url()` link on login (B5-004); (5) hid WP admin bar from frontend for learner roles via `stride_view` capability check in `BrowserHooks` (B5-007); (6) dequeued Tin-Canny LD-reporting JS/CSS (3 JS + 4 CSS handles) from non-LD pages via `LearnDashHooks` priority-999 hook (B5-008). All fixes via `superpowers:systematic-debugging`. Tests: Unit 706/706 + Integration 261/261 green after fixes. Found B5-015 during fixes (ntdst-auth has zero translation files — separate ship-mode-deferable task). Manifest: `tasks/shake-out-manifest-5.md`. NO COMMITS YET.
- `2026-05-16` (continued) — Unified course-card partial shipped. Consolidates 3 duplicate implementations (dashboard home Opleidingen, inschrijvingen tab, trajectory course-groups) into `templates/components/course-card.php` + two builder helpers in `helpers/templates.php` (`stridence_build_course_card_args_from_enrollment` + `stridence_build_course_card_args_from_trajectory_course`). First card in each dashboard list auto-expands. Net: -510 lines across the 3 swap sites (tab-home -127, tab-inschrijvingen -165, course-groups -219), +1 partial (~210 lines), +2 builders (~160 lines + ~70 lines). Tests: 8 new unit tests for builders + 3 new acceptance tests (DashboardCest, TrajectoryCest). All suites green: Unit 714, Integration 261, Acceptance 102. Spec: `docs/superpowers/specs/2026-05-16-unified-course-card-design.md`. Plan: `docs/superpowers/plans/2026-05-16-unified-course-card.md`.

---

## Biggest Open Work (per LAUNCH-CHECKLIST)

Codebase is **feature-complete** (per LAUNCH-CHECKLIST top: "all P0+P1 launch code items shipped"). Next phase: deep testing.

| Area | Item | Status |
|------|------|--------|
| §A Admin Dashboard | All 23 bugs + visual/UX repair | ✅ DONE |
| §B Phase 3 tail | Bulk lock/unlock + edit restriction | ✅ DONE |
| §C Phase 4 vouchers | Scope + per-session apply mode | ✅ DONE 2026-05-14 |
| §D Deferred bugs in launch modules | Refresh found 7 already fixed, 3 dropped, 4 real items | 4 OPEN |
| §F Multi-brand demo | Scaffolds | ✅ DONE for launch |
| Pre-launch cleanup | LTI plugin deactivation at deploy time | 1 OPEN |
| Audit fixes (pre-testing) | 3 CRITICAL sec + 4 HIGH sec + 4 HIGH perf from 2026-05-14 audit | ✅ DONE — verified 2026-05-17 in code (notes in `tasks/audit-2026-05-14-*.md`). MEDIUM + LOW deferred. |

---

## Decisions this session (2026-05-16)

- **Post-launch capability-plugin architecture decided.** Partner API extracts to own plugin (post-launch pathfinder refactor). New `stride-conference` plugin built on the proven pattern. Documented in `docs/superpowers/specs/2026-05-16-post-launch-capability-plugins-design.md`.
- **WP REST API rejected as the public consumption contract.** Default shape leaks internals, every meta becomes public, computed/joined data needs custom controllers anyway. Hand-shape `stride/v1/public/*` instead. Mirrors WooCommerce's `wp/v2/products` vs `wc/v3/products` split.
- **Conference frontend handoff = hard redirect, not embed.** Re-implementing the enrollment wizard on a separate frontend duplicates Stride's most complex part for cosmetic gain.
- **Livestreams = deferred to its own design doc.** Unrelated to the plugin architecture decision.

## Earlier decisions (2026-05-13)

- **OGM payment reference** — dropped from v1. Stride creates quotes, not invoices; OGM belongs on the Exact-generated invoice.
- **Auto-lock cron** — dropped. Admin decides when to lock (single toggle on edition sidebar).
- **Trajectory UI** — stays visible for v1.
- **Activity feed grouping** — skipped. Current flat list is good enough for launch.
- **Per-enrollment timeline view** — skipped.

---

## Shake-out Status

All major modules done. Admin dashboard fully resolved (was the last blocker).

**Remaining deferred bugs in launch modules (per §D):** Re-audited 2026-05-17 against the 2026-05-14 d-audit + current code. Headline was stale.

- Completion: all 5 originally listed bugs ✅ resolved (D-C1 LD sync, D-C2 current_time, D-C3 cache, D-C4 Withdrawn enum — all fixed; D-C5 DI coupling dropped as architectural debt).
- Attendance: all 3 ✅ resolved (D-A1 cascade delete, D-A2 N/A, D-A3 semantic count).
- Theme: D-T1 footer pages fixed in dev DB; **D-T1 staging/prod replay still needed** (page rows are not in git). D-T2 ProPanel + D-T3 "11 shortcodes" — drop (unreproducible / never enumerated).
- Capacity: **D-Cap1 stale-pending registrations** holding a Volzet seat indefinitely — still open, policy decision pending (auto-expire 24-48h vs accept + document). D-Cap2 recomputeStatus → P2, defer.

Partner API's 5 deferred bugs stay deferred (post-launch).

---

## Performance baseline (2026-05-13)

| Endpoint | 10 regs | 50 regs |
|---|---|---|
| getUserDetail | 39 queries / 13 ms | 13 queries / 5 ms |
| getStats | 17 q / 6 ms | 20 q / 6 ms |
| getEditions (20 rows) | 12 q / 6 ms | 12 q / 6 ms |
| getQuotes (20 rows) | 9 q / 5 ms | 9 q / 5 ms |
| getActivityFeed (10) | 3 q / 1 ms | 3 q / 1 ms |

No N+1. Comfortable for VAD's profile (4000 users, avg 2–3 enrollments). Re-run via `ddev exec bash -c "HEAVY=1 wp eval-file scripts/perf-benchmark.php"`.

---

## Completed Features (recap)

- Quote PDF Generation — DOMPDF, company settings, logo, email attachment, edition documents
- Client Customization System (2026-03-18) — mu-plugin pattern, template overrides, CSS tokens
- ntdst-assistant Phase 2 (2026-03-21) — 16 tasks, CSS overhaul, Alpine rewrite, 3 export abilities
- Editorial rebrand (2026-03-26) — design-system shell via tokens + Tailwind config
- BWEEG demo (2026-03-27) — first branded client scaffold on top of editorial rebrand
- Online enrollment flow shake-out (2026-03-31) — 6 bugs fixed
- Admin dashboard Sprint 1 + Track 2 (2026-05-13) — 23 bugs verified resolved, neutral UX, designed empty/loading/error states
- Quote bulk lock/unlock from edition (2026-05-13) — admin-driven, customer-facing block

---

## Uncommitted Work on `staging`

Inventory only — left in place per user instruction:

- `web/app/plugins/netdust-lti/` — LTI plugin in progress (deferred to post-launch)
- `web/app/themes/stridence/dist/.vite/manifest.json` + main.* — built theme assets
- `web/app/plugins/ntdst-auth/assets/css/auth.css` — auth styling tweak
- `web/app/themes/stridence/tailwind.config.js` — tailwind tweaks
- Stray PNGs in repo root: `bento-section`, `debug-outlines`, `stridelms-fullpage` — design references, not source
- 60+ untracked screenshots in `tests/_output/` — Playwright + dashboard sweep artifacts
- Random third-party plugin modifications (sfwd-lms changes, ntdst-assistant lib/)

**To clean before launch:** see LAUNCH-CHECKLIST.md "Pre-Launch Cleanup".

---

## Open Design Drafts (decide before code freeze)

- `docs/plans/2026-03-16-session-price-modifiers-design.md` — per-session pricing
- `docs/plans/2026-03-17-stride-mail-integration-design.md` — verify vs shipped netdust-mail
- `docs/plans/2026-03-18-roles-capabilities-design.md` — verify what's already implemented

---

## Post-Launch Vision (not for launch)

- Assistant exports — CSV/Excel/DOCX abilities (`memory/project_assistant_exports.md`)
- Assistant evolution — headless mode, WP-CLI, event-triggered AI, audit log table (`memory/project_assistant_vision.md`)
- Phase 8 voucher automations — annual member voucher renewal, reversal on cancellation
- Trajectory module — design done, never shake-out tested
- Partner API — 5 deferred bugs, foundational design done
- LTI — in-progress work parked on `staging` branch
- OGM payment reference — re-add when Stride generates invoices or when Exact integration is built
- Enrollment timeline view — answers "what happened to this enrollment?"
- Activity feed grouping — group by entity, filter chips
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-18] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-19] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-20] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
[2026-05-21] — session ended (no significant changes captured)
