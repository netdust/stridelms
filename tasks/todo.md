# Active Sprint — todo

Working scratchpad. Authoritative launch list lives in `docs/LAUNCH-CHECKLIST.md`.

---

## Sprint 1 — Admin Dashboard: bugs + visual/UX repair

Two tracks. Do **bugs first**, then **visual** — fixing bugs surfaces what's actually broken vs what's design.

### Track 1 — Functional bugs (5 real, 2 to verify)

**Re-sweep done 2026-05-13. 18 of 23 bugs already resolved in code. Remaining:**

1. [ ] **BUG-009 (P0)** Currency: API returns cents-as-euros. Fix `AdminAPIController::quotes()` to divide subtotal/tax/total by 100 before send. Verify with seeded OFF-2026-0161 → should show €272,25 not €27.225,00.
2. [ ] **BUG-007 (P0)** Settings persistence: save returns 200 but reload reverts. Check `StrideSettingsService.php` option saving + capability check.
3. [ ] **BUG-021 (P1)** Activity feed text: "Pieter Janssen: auth.logout" → human Dutch via `AdminActivityMapper`. Define mappings per event type (auth.logout, user.created, quote.sent, etc).
4. [x] ~~Hide trajectory UI for v1~~ — **decision 2026-05-13: keep visible.** Re-verify BUG-003 (trajectory detail 404) as part of standalone bugs below.
5. [ ] **BUG-022 (P2)** Course tag dropdown empty. Confirm `ld_course_tag` taxonomy and BWEEG seed.
6. [ ] **BUG-023 (P2)** Quote slide-over BTW breakdown. Add subtotal + BTW 21% + total rows to template.
7. [ ] **Verify BUG-019** (dead "Alles bekijken" links) — quick browser test.
8. [ ] **Verify BUG-020** (`post=undefined` in hidden slide-overs) — quick browser test.
9. [ ] Update manifest with fixes + re-sweep result.

### Track 2 — Visual & UX repair (after bugs)
See `docs/LAUNCH-CHECKLIST.md` §A.2 for full scope. Headlines:

9. [ ] Color system — drop purple, pick stable neutral + 1 accent + status colors (tokens.css only)
10. [ ] Layout stability — density, spacing, hierarchy
11. [ ] Slide-over content redesign (after BUG-002 positioning fix lands)
12. [ ] User detail = "call center" view — enrollments + quotes/invoices + payment status + events on one screen
13. [ ] Enrollment detail = "what happened" timeline view
14. [ ] Activity feed redesign — group by entity, human-readable text
15. [ ] Empty / loading / error states across dashboard
16. [ ] (P1) density mode toggle (compact)

### Acceptance for Sprint 1
- All 23 bugs resolved or explicitly deferred with documented reason
- Visual repair items complete or scoped down with user sign-off
- Dashboard shake-out manifest re-run shows green
- "Where's my invoice?" and "What happened to this enrollment?" can be answered from the dashboard in < 30 seconds
- No regressions in existing test suite (611/214/90)

---

## After Sprint 1

In priority order (see `docs/LAUNCH-CHECKLIST.md` for full scope):

1. Phase 3 tail — OGM, auto-lock, billing edit restriction
2. Phase 4 VAD voucher rules
3. Deferred bugs in launch modules — Completion (5) + Attendance (3) + Theme (3)
4. Multi-brand demo — scaffold #2 + #3 + swap doc
5. Pre-launch cleanup — stash LTI, clean repo root, gitignore tests/_output
6. Decide stale design drafts
