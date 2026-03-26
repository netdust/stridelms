# Stride — Project State

Current state of the project for session continuity. Updated after meaningful work.

---

## Current Phase: Phase 3 — Invoicing/Vouchers (per project plan)

### Production Priorities

**Phase 1 (must-have for launch):**
- E-learning, blended learning, enrollment flow, enrollment tasks, completion tasks, attendance, invoicing, user dashboard

**Deferred (when client needs them):**
- Trajectories, Partner API, LTI integration

---

## Shake-out Status (2026-03-21)

12 components tested, 49 bugs found, 32 fixed, all tests green.

| Component | Bugs | Fixed | Status |
|-----------|------|-------|--------|
| ntdst-assistant | 7 | 7 | DONE |
| ntdst-audit | 4 | 4 | DONE |
| ntdst-auth | 1 | 1 | DONE |
| netdust-mail | 1 | 0 (deferred CDN) | DONE |
| ntdst-core + stride-core | 0 | 0 | DONE — clean |
| Edition module | 2 | 2 | DONE |
| Enrollment module | 7 | 7 | DONE |
| Invoicing module | 3 | 3 | DONE |
| Attendance module | 6 | 3 | DONE (3 deferred) |
| Completion module | 7 | 2 | DONE (5 deferred) |
| Partner API | 7 | 2 | DONE (5 deferred) |
| Stridence theme | 4 | 1 | DONE (3 deferred) |

**Not yet tested:** Trajectory module, Admin dashboard

**Test suite:** 611 unit, 214 integration, 90 acceptance — all green

---

## Completed Features

### Quote PDF Generation (DONE)
- DOMPDF rendering, company settings with logo, email attachment
- Admin buttons (lock/PDF view/regenerate), customer notes on PDF
- Edition "Documenten" tab for course document uploads
- Branch merged to staging

### Client Customization System (DONE, 2026-03-18)
- Per-client mu-plugin (not child themes)
- `stridence_template_part()` with `stridence_template_path` filter for template overrides
- CSS branding via custom property overrides (40+ tokens in `src/css/tokens.css`)
- Reference scaffold: `web/app/mu-plugins/stride-client-example/`

### ntdst-assistant Phase 2 (DONE, 2026-03-21)
- 16 tasks: CSS overhaul, Alpine.js rewrite, template updates
- /clear + /download endpoints, ExportService, cron cleanup
- 3 export abilities, date range filters
- 600 unit tests, 15 acceptance tests

---

## Planned / In Progress

### Assistant Exports (planned)
- CSV/Excel/DOCX export abilities for AI assistant
- Abilities: `stride/export-editions`, `stride/export-enrollments`, `stride/export-attendance`
- Needs PhpSpreadsheet for Excel, `download` response type in transport

### Assistant Evolution (vision)
- Three execution modes: Chat (exists), CLI (to build), Hook (to build)
- Headless ToolExecutor, WP-CLI command, source tracking, audit log table
- Event-triggered AI: error triage, form replies, quote follow-ups, capacity alerts
- Cron-based: weekly digest, pre-edition checklist, monthly partner report

---

## LTI Plugin (in progress on staging)

Currently modified files on staging branch indicate active LTI work:
- `web/app/plugins/netdust-lti/` — Platform/ToolProvider routers, admin settings, WPDataConnector, deep-link picker, SCORM proxy bridge, JWT tests

---

## Deferred Bugs (from shake-out)

**Attendance:** cascade delete, orphan session_registrations, semantic count inconsistency
**Completion:** no LD course_completed sync, deprecated current_time('timestamp'), cache not cleared on task update, Withdrawn enum mismatch, DI coupling
**Partner API:** pagination not sanitized, orphan registrations visible, no args schema, no PATH_PARTNER, hardcoded status
**Theme:** 7 footer pages 404, LearnDash ProPanel script notice, 11 shortcodes not yet implemented
