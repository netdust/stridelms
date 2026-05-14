# Active Sprint — todo

Working scratchpad. Authoritative launch list lives in `docs/LAUNCH-CHECKLIST.md`.

---

## Sprint 1 — Admin Dashboard ✅ DONE (2026-05-13)

- Track 1 — all 23 bugs verified resolved (5 fixed, 18 already in code)
- Track 2 — neutral UX pass, user-detail rework, empty/loading/error states
- 31 previously-unstyled classes filled in
- Commit `8a54c475`

## Phase 3 tail ✅ DONE (2026-05-13)

- Bulk lock/unlock from edition (single toggle button)
- Customer-facing edit restriction when `locked=true`
- OGM dropped from v1 (Exact's job)
- Auto-lock cron dropped from v1 (admin-driven instead)
- Commit `01b9a346`

## Performance baseline ✅ DONE (2026-05-13)

- `scripts/perf-benchmark.php` — 13 queries / 5 ms for `getUserDetail` at 50 enrollments
- No N+1 anywhere
- Commit `7c5f04f5`

---

## §C — Voucher scope + apply-mode ✅ DONE (2026-05-14)

Supersedes the original 5-category plan. Smaller, more flexible model.
- 3-way `scope_mode` radio: alle/alleen/behalve (replaces single edition_id dropdown)
- `apply_mode` dropdown: volledige editie / één sessie (pro rata)
- `VoucherScopeValidator` + `VoucherProrater` helpers (plain classes, NTDST DI)
- Legacy back-compat for existing vouchers
- 6 new integration tests, 8 acceptance tests still green, full suites green
- Plan: `plans/phase-4-voucher-scope-and-prorating.md`
- Commits `ae970344` + `95065b4f` + `4709fef3` (CSS fix)
- Shake-out: `tasks/shake-out-voucher-manifest.md` — 0 CRITICAL, 0 IMPORTANT, 1 MINOR (deferred)

## Deferred polish (post-launch nice-to-haves, not blocking)

- **M1 (from voucher shake-out)** — edition pickers render blank entry for `vad_edition #5088` (empty `post_title`). Cosmetic; pre-existing data quality issue made more visible by the new multi-select. Fix candidates: skip empty-title editions in `get_posts()`, or render `(geen titel)` placeholder. Affects both single + multi pickers in `VoucherAdminController::renderVoucherMetabox()`.

---

## Next session — pick from LAUNCH-CHECKLIST in priority order

In §D → §F order, or whichever you want to tackle first:

### §D — 11 deferred bugs in launch modules
- **Completion (5)**: LD course_completed sync, deprecated current_time, cache clear on task update, Withdrawn enum, DI coupling
- **Attendance (3)**: cascade delete, orphan session_registrations, semantic count
- **Theme (3)**: 7 footer pages 404, LD ProPanel notice, 11 shortcodes

### §F — Multi-brand demo
- Brand scaffold #2 (corporate training or university CPD)
- Brand scaffold #3 (wellness or public sector)
- Swap mechanic doc
- Side-by-side screenshots

### Pre-launch cleanup
- Stash uncommitted LTI work on `staging`
- Drop stray PNGs (`bento-section`, `debug-outlines`, `stridelms-fullpage`)
- Add `tests/_output/` to `.gitignore`
- Decide stale design drafts (session-price-modifiers, stride-mail-integration, roles-capabilities)
