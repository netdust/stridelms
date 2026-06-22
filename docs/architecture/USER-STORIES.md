# Stride — Canonical User-Story Tracker

**`USER-STORIES.csv` is the single canonical spreadsheet** for the feature-verification goal: one row per user-facing behaviour, derived from the actual code (not from intent docs), with a status column for each phase of the verify→fix→re-verify loop.

It is the granular companion to `FEATURE-STATUS.md` (the 19-row overview matrix). FEATURE-STATUS answers "what's solid"; USER-STORIES.csv answers "does each individual behaviour actually work, and is it tested/fixed".

## Columns

| Column | Meaning |
|---|---|
| `ID` | Stable id, prefixed by area (EN/TR/ED/CA/AT/DB/AD/AU/AX/PA/RP/MB/ML) |
| `Area` | Feature area |
| `Role` | Who performs the behaviour (student, trainer, admin/coordinator, partner, anonymous, system) |
| `User Story` | As a X, I want Y so that Z |
| `Expected Behaviour (code-derived)` | Concrete behaviour read from the code: success path, guards, error messages (Dutch where shown), edge cases |
| `Code Reference` | file:line anchor |
| `P1 Built` | Phase 1 — does the code path exist? (Yes/No) |
| `P2 Test Result` | Phase 2 — PASS / FAIL / NOT-REACHABLE / UNVERIFIED + how driven |
| `Errors Found` | Phase 2 — every logistical/UX error observed |
| `P3 Fix Status` | Phase 3 — FIXED (commit) / DEFERRED / N-A |
| `P4 Re-test` | Phase 4 — PASS after fix |

## ID prefixes

- **EN** Enrollment, interest, waitlist, completion tasks, questionnaire (47)
- **TR** Trajectory / cascade (14)
- **ED** Edition status frontend display (8)
- **CA** Catalog / public pages (9)
- **AT** Attendance (8)
- **DB** Dashboard, profile, invoicing, vouchers, GDPR (30)
- **AD** Admin edition management (10)
- **AU** Auth / login / registration (5)
- **AX** Audit / impersonation (4)
- **PA** Partner API (7)
- **RP** Annual report (3)
- **MB** Multi-brand / client scaffolds (2)
- **ML** Mail / notifications (7)

**Total: 154 stories.**

## The loop (the `/goal`)

1. **Phase 1 — inventory** (DONE): every feature → user story + code-derived expected behaviour, in this CSV.
2. **Phase 2 — test**: drive every story; record PASS/FAIL + errors. Prefer the real wire (acceptance Cest via `codeception.yml`, shake-out walk, manual browser via Selenium) over unit tests — green units ≠ working behaviour.
3. **Phase 3 — fix**: fix every logistical/UX error found.
4. **Phase 4 — re-test**: re-drive every story post-fix; confirm errors closed.

_Generated 2026-06-22 by code fan-out across stride-core + stridence. Baseline at generation: unit suite 1020 tests / 2566 assertions green._
