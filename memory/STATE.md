# Stride — Project State

Current state of the project for session continuity. Updated after meaningful work.
**For "what's left to launch" see `docs/LAUNCH-CHECKLIST.md` (single source of truth).**

Last refresh: 2026-05-13

---

## Current Phase: Phase 1 finishing work (mid-flight)

Two parallel goals:

1. **Production-ready Phase 1** — e-learning, blended learning, enrollment flow, enrollment tasks, completion tasks, attendance, invoicing, user dashboard.
2. **Multi-brand demo** — 2–3 distinct brand scaffolds + swap demo for sales.

**Post-launch (do NOT block launch):** Trajectories, Partner API, LTI.

---

## What shipped 2026-05-13

Three commits this session:
- `8a54c475` Sprint 1 + Track 2 — all 23 dashboard bugs resolved + neutral UX pass + user-detail rework (enrollment / attendance / invoice tables) + empty/loading/error states
- `01b9a346` Phase 3 tail — bulk lock/unlock quotes from edition + customer-facing edit restriction
- `7c5f04f5` Perf benchmark script — confirms `getUserDetail` at 50 enrollments is 13 queries / 5 ms (no N+1)

Plus `ba09bec6` docs commit with the new `docs/LAUNCH-CHECKLIST.md` as the single source of truth.

**Tests:** 674 unit + 221 integration green.

---

## Biggest Open Work (per LAUNCH-CHECKLIST)

| Area | Item | Status |
|------|------|--------|
| §A Admin Dashboard | All 23 bugs + visual/UX repair | ✅ DONE |
| §B Phase 3 tail | Bulk lock/unlock + edit restriction | ✅ DONE (OGM + cron dropped from v1) |
| §C Phase 4 vouchers | 5 VAD-specific rules (categories, member, prorating, social) | OPEN |
| §D Deferred bugs in launch modules | 11 bugs across Completion, Attendance, Theme | OPEN |
| §F Multi-brand demo | 2 more brand scaffolds + swap doc | OPEN |
| Pre-launch cleanup | stash LTI WIP, drop stray PNGs, gitignore tests/_output | OPEN |

---

## Decisions this session

- **OGM payment reference** — dropped from v1. Stride creates quotes, not invoices; OGM belongs on the Exact-generated invoice.
- **Auto-lock cron** — dropped. Admin decides when to lock (single toggle on edition sidebar).
- **Trajectory UI** — stays visible for v1 (user decision after Track 1 sweep).
- **Activity feed grouping** — skipped per user. Current flat list is good enough for launch.
- **Per-enrollment timeline view** — skipped per user.

---

## Shake-out Status

All major modules done. Admin dashboard fully resolved (was the last blocker).

**Remaining deferred bugs in launch modules (per §D):**
- Completion (5) — LD course_completed sync, deprecated current_time, cache, Withdrawn enum, DI coupling
- Attendance (3) — cascade delete, orphan session_registrations, semantic count
- Theme (3) — 7 footer 404s, LD ProPanel notice, 11 shortcodes

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
