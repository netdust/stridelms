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

## Next session — pick from LAUNCH-CHECKLIST in priority order

In §C → §D → §F order, or whichever you want to tackle first:

### §C — Phase 4 VAD voucher rules
Source: `plans/phase-4-voucher-completion.md`
- Voucher category field (5 types: member, action, speaker, day, social)
- Edition `is_multi_year_training` flag
- `VoucherTypeValidator` helper
- Member voucher rules (blocked for multi-year editions)
- Day voucher prorating (1 day = 1/N of edition price)
- Social voucher (50% off)
- Tests

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
