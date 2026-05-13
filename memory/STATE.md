# Stride — Project State

Current state of the project for session continuity. Updated after meaningful work.
**For "what's left to launch" see `docs/LAUNCH-CHECKLIST.md` (single source of truth).**

Last refresh: 2026-05-13

---

## Current Phase: Pre-launch cleanup → Phase 1 finishing work

Development is restarting after a pause. Two parallel goals:

1. **Production-ready Phase 1** — e-learning, blended learning, enrollment flow, enrollment tasks, completion tasks, attendance, invoicing, user dashboard.
2. **Multi-brand demo** — 2–3 distinct brand scaffolds + swap demo for sales.

**Post-launch (do NOT block launch):** Trajectories, Partner API, LTI.

---

## Biggest Open Work

| Area | Item | Source |
|------|------|--------|
| Admin Dashboard | **23 bugs all OPEN** (the biggest single blocker) | `tasks/shake-out-dashboard-manifest.md` |
| Phase 3 tail | 14-day auto-lock cron, billing edit restriction, OGM payment ref | `plans/finish-phase-3.md` |
| Phase 4 vouchers | 5 VAD-specific rules (categories, member, prorating, social) | `plans/phase-4-voucher-completion.md` |
| Deferred bugs (launch modules) | 11 bugs across Completion, Attendance, Theme | See LAUNCH-CHECKLIST.md §D |
| Multi-brand demo | 2 more brand scaffolds + swap doc | See LAUNCH-CHECKLIST.md §F |

---

## Shake-out Status (latest)

| Component | Status | Notes |
|-----------|--------|-------|
| Edition module | DONE | All resolved |
| Enrollment module | DONE | v2 manifest (2026-03-31) supersedes v1; all 4 resolved |
| Enrollment-completion | DONE | All 2 resolved (2026-03-31) |
| Online enrollment | DONE | All 6 fixed (2026-03-31) |
| Completion module | DONE | v2 manifest (2026-03-31) supersedes v1; all 5 resolved |
| Invoicing module | DONE | Mixed status, mostly fixed |
| Attendance module | DONE | 3 deferred (now P0–P1 per launch checklist) |
| Partner API | DONE | 5 deferred (post-launch) |
| Stridence theme | DONE | 3 deferred (now P0–P1 per launch checklist) |
| ntdst-assistant | DONE | All 7 resolved |
| ntdst-audit | DONE | All 4 resolved |
| ntdst-auth | DONE | All 1 resolved |
| netdust-mail | DONE | 1 deferred (CDN Alpine.js) |
| ntdst-core + stride-core | DONE | Clean |
| Edition | DONE | All 2 resolved |
| Questionnaire | DONE | All 1 resolved |
| LTI plugin | DONE | 5 resolved, 1 deferred — **deferred to post-launch** |
| **Admin dashboard** | **OPEN** | **23 bugs all unfixed** |
| Trajectory | UNTESTED | Deferred to post-launch |

**Superseded manifests** (archived to `tasks/archive/`):
- `shake-out-completion-manifest.md` → see v2
- `shake-out-enrollment-manifest.md` → see v2

**Test suite:** 611 unit, 214 integration, 90 acceptance — all green at last run

---

## Completed Features (recap)

- Quote PDF Generation — DOMPDF, company settings, logo, email attachment, edition documents
- Client Customization System (2026-03-18) — mu-plugin pattern, template overrides, CSS tokens
- ntdst-assistant Phase 2 (2026-03-21) — 16 tasks, CSS overhaul, Alpine rewrite, 3 export abilities
- Editorial rebrand (2026-03-26) — design-system shell via tokens + Tailwind config
- BWEEG demo (2026-03-27) — first branded client scaffold on top of editorial rebrand
- Online enrollment flow shake-out (2026-03-31) — 6 bugs fixed
- Enrollment-completion task fixes (latest commit) — questionnaire stage mapping, select styling, reload on complete

---

## Uncommitted Work on `staging`

Inventory only — left in place per user instruction:

- `web/app/plugins/netdust-lti/` — LTI plugin in progress (deferred to post-launch)
- `web/app/themes/stridence/dist/.vite/manifest.json` + main.* — built theme assets
- `web/app/plugins/ntdst-auth/assets/css/auth.css` — auth styling tweak
- `web/app/themes/stridence/tailwind.config.js` — tailwind tweaks
- Stray PNGs in repo root: `bento-section`, `debug-outlines`, `stridelms-fullpage` — design references, not source
- 40+ untracked screenshots in `tests/_output/` — Playwright artifacts

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
