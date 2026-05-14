# Stride — Launch Checklist

**Authoritative list of what must be true before Phase 1 production launch.**
**Companion to:** `memory/STATE.md` (current-state snapshot) and `tasks/todo.md` (active sprint scratchpad).

Last updated: 2026-05-14 (post §C voucher scope + apply-mode)

---

## Goals

1. **Production-ready** — ship Phase 1: e-learning, blended learning, enrollment flow, enrollment tasks, completion tasks, attendance, invoicing, user dashboard.
2. **Multi-brand demo** — 2–3 distinct brand scaffolds (BWEEG + 1–2 more) + a swap demo for sales.

**Explicitly out of scope (post-launch):** Trajectories, Partner API, LTI.

---

## Status Legend

- `[ ]` open / not started
- `[~]` in progress
- `[x]` done
- `(P0)` blocks launch
- `(P1)` should fix before launch
- `(P2)` nice-to-have

---

## A. Admin Dashboard — DONE for launch (P0)

**Source:** `tasks/shake-out-dashboard-manifest.md` (re-swept 2026-05-13, all bugs resolved 2026-05-13)
**Why P0:** Admin can't operate the platform without this. Largest single blocker.

**Status (2026-05-14):**
- **A.1 — Functional bugs** — ✅ ALL 23 fixed (2026-05-13, `8a54c475`).
- **A.2 — Visual & UX repair** — ✅ All launch items shipped (`8a54c475`). Enrollment-timeline view deferred post-launch (user decision 2026-05-13). Density modes P1 — nice-to-have, not blocking.

### A.1 — Real bugs to fix (5 verified open)

- [x] BUG-007 (P0) — Settings notification thresholds don't persist after save. **FIXED 2026-05-13** — extracted `ntdstAPI` to shared mu-plugin asset; was a broken nonce fallback that swallowed errors as "saved".
- [x] BUG-009 (P0) — Quote totals stored in cents but API/dashboard treat as euros. **FIXED 2026-05-13** — `AdminAPIController` converts via `Money::cents()->amount()` at API edge; OFF-2026-0161 now renders €272,25.
- [x] BUG-021 (P1) — Activity feed shows raw event strings instead of human Dutch. **FIXED 2026-05-13** — extended `AdminActivityMapper` with all missing event cases + controller resolves `entity_id` to target user name. 11 new unit tests.
- [x] BUG-022 (P2) — Course tag filter dropdown empty. **FIXED 2026-05-13** — replaced single `course_tag` with three filters (theme/format/tag). Theme=17, format=6, tag=0 in BWEEG; tag dropdown auto-hides when empty.
- [x] BUG-023 (P2) — Quote slide-over BTW/subtotal breakdown. **VERIFIED 2026-05-13** — Subtotaal/BTW/Totaal already in template (dashboard.php:535-541). Renders Subtotaal €225,00, BTW €47,25, Totaal €272,25 on OFF-2026-0161.

### A.1b — Verify (verified resolved 2026-05-13)
- [x] BUG-019 (P2) — "Alles bekijken" / "Meer bekijken" dead links. **VERIFIED** — `@click.prevent` handlers work; click test confirmed view switch.
- [x] BUG-020 (P2) — "Bewerk in WP" `post=undefined` in hidden slide-overs. **FIXED 2026-05-13** — replaced inline concat with `selectedX?.editUrl || '#'` for edition/quote/trajectory + conditional href for user slide-over. 0 undefined anchors in DOM.

### Resolved since 2026-03-25 (no action needed — kept for evidence)

18 bugs already fixed in code:
- Cluster A (8): BUG-001, 005, 006, 008, 010, 011, 012, 013 — JS field bridging done
- Cluster C (1): BUG-002 slide-over positioning — fixed overlay confirmed
- Cluster E (2): BUG-015 Geannuleerd in filter, BUG-016 draft value alignment
- BUG-004 trajectory duplicates, BUG-014/017 trajectory labels (RESOLVED but trajectory UI to be hidden anyway), BUG-018 impersonation button label

> **Trajectory bugs (BUG-003, BUG-004, BUG-014, BUG-017):** Trajectory UI **stays visible for v1** (user decision 2026-05-13). BUG-004/014/017 already resolved. BUG-003 (trajectory detail 404 route) still needs fix — re-add to A.1 if reproducible.

### A.2 — Visual & UX repair (P0) — all launch items done

**Status:** 6 of 9 items shipped 2026-05-13 in commit `8a54c475` ("sprint 1 + track 2 — all 23 bugs + neutral UX pass"). Enrollment-timeline view deferred post-launch (user decision 2026-05-13). 1 P1 item still open (density modes) — nice-to-have, not a launch blocker.

**Goal:** dashboard should feel stable, professional, fast. Designed for daily admin tasks — speed up routine work, find things fast, understand what happened and why.

**Real use cases driving the redesign:**
- "Someone calls in — they subscribed, now they can't find the invoice. They're sure they paid." → admin needs to find a user, see their enrollments + quotes + payment events in one place, fast.
- "Admin is going through enrollments and sees one with missing data. What happened?" → admin needs to see the chronological story of a single enrollment: when created, what changed, who changed it, why.

**Repair items (NOT a full redesign — controlled visual + UX pass):**

- [x] (P0) **Color system** — dropped "Soft Violet"; neutral slate base + single blue accent; all hardcoded violet hex removed. **DONE 2026-05-13** (`8a54c475`).
- [x] (P0) **Layout stability** — buttons normalized (32px height, unified padding via tokens), inputs/selects matched, KPI row CSS grid (5→3→1), card padding tightened, table headers normal-case with subtle alt bg. **DONE 2026-05-13** (`8a54c475`).
- [x] (P0) **Slide-over redesign** — fixed missing `sd-slideout__tab` class on three slide-overs; positioning + content audit complete. **DONE 2026-05-13** (`8a54c475`).
- [x] (P0) **User detail view = the "call center" view** — Inschrijvingen / Aanwezigheid / Offertes tables, per-registration attendance summary, per-quote `sent_at`+`paid_at`, batch queries (no N+1). **DONE 2026-05-13** (`8a54c475`).
- [~] (Deferred — user decision 2026-05-13) **Enrollment detail = the "what happened" view** — timeline view of a single enrollment (created, task X done, missing field Y, status changes). Dropped from v1 scope; revisit post-launch.
- [x] (P0) **Activity feed redesign** — `AdminActivityMapper` extended with all event cases; controller batch-resolves `entity_id` → display name; graceful "(account niet meer beschikbaar)" fallback for deleted users; 11 new unit tests. **DONE 2026-05-13** (`8a54c475`, also resolves BUG-021).
- [x] (P1) **Empty states** — `.sd-empty` pattern (circular icon + title + hint) applied across lists/tables. **DONE 2026-05-13** (`8a54c475`).
- [x] (P1) **Loading / error states** — `.sd-skeleton` with pulse animation in KPI + tables; `.sd-error` blocks with retry buttons; stats default `null` so no flash-of-zero. **DONE 2026-05-13** (`8a54c475`).
- [ ] (P1) **Density modes** — comfortable default, optional compact mode for power users. (Cheap if done at CSS level.)

**Constraints:**
- No new dashboard framework. Existing Alpine.js + template structure stays.
- No new API endpoints unless absolutely required (e.g. the timeline view might need one aggregator endpoint).
- Tokens.css + dashboard.css + dashboard.php are the main edit surface.
- Test on real seeded data, not empty DB.

---

## B. Phase 3 Tail — Quote Locking (P0)

**Source:** `plans/finish-phase-3.md` (simplified per 2026-05-13 decisions)
**Why P0:** Stop customers editing billing details when admin has decided the quote is final.

- [x] Bulk lock/unlock — single toggle button on the edition sidebar; reads current state and inverts. **DONE 2026-05-13** — `QuoteService::bulkSetLockedByEdition()` + AJAX endpoint + `EditionActionsMetabox` UI. Verified on edition 13190.
- [x] Per-quote lock stays as admin escape hatch (already existed before).
- [x] Billing edit restriction — `validateQuoteAccess()` rejects updates/voucher with `WP_Error('locked', …)` when `locked=true`. **DONE 2026-05-13.**
- [x] Tests: 9 new integration tests across bulk-lock + lock-rejection scenarios. Full unit (674) + integration (221) suites pass.

**Out of scope for v1 (post-launch):**
- ~~Auto-lock cron at T-14d~~ — admin-driven instead. Admin decides when to lock the edition's quotes; no cron (decision 2026-05-13).
- ~~OGM payment reference generator~~ — Stride only creates quotes, not invoices. OGM belongs on the actual invoice, generated by Exact Online (decision 2026-05-13).

---

## C. Phase 4 — Voucher Scope + Per-Session Apply Mode (P0) — DONE

**Source:** `plans/phase-4-voucher-scope-and-prorating.md` (supersedes `plans/phase-4-voucher-completion.md`, 2026-05-14 decision)
**Why P0:** Voucher infra is generic; VAD needs (a) the ability to *exclude* certain editions from a voucher (instead of only restricting to one) and (b) prorating for multi-session editions when a voucher should only cover one session. Original 5-category plan dropped — adds density without solving real admin problems.

- [x] **Bidirectional scope** — `scope_mode` radio: *Alle / Alleen / Behalve*. Replaces single `edition_id` dropdown. Existing vouchers (no `scope_mode`) auto-detected as "alleen" via legacy back-compat. **DONE 2026-05-14.**
- [x] **`apply_mode` dropdown** — *Volledige editie / Eén sessie (pro rata)*. When "Eén sessie" + multi-session edition: subtotal divided by session_count before discount applied. 0-session editions silently fall back to full. **DONE 2026-05-14.**
- [x] **`VoucherScopeValidator` helper** — pure class, no hooks. Resolves legacy + handles `only`/`except` branching. **DONE 2026-05-14.**
- [x] **`VoucherProrater` helper** — pure math `Money::cents(subtotal / max(N, 1))`. **DONE 2026-05-14.**
- [x] **`VoucherService::validateVoucher()`** — delegates to scope validator (1 line). Keeps existing `wrong_edition` error code. **DONE 2026-05-14.**
- [x] **`VoucherService::calculateDiscount()`** — optional `?int $editionId` parameter; prorates subtotal when `apply_mode='single_session'`. Backwards-compatible default `null`. **DONE 2026-05-14.**
- [x] **Admin form** — 3-way scope radio with show/hide UI, multi-select for "Behalve", apply-mode dropdown. Vanilla JS toggle (no Alpine import). **DONE 2026-05-14.**
- [x] **Admin list column** — shows "Alleen: X" / "Behalve: A, B +N meer" / "Alle edities" instead of single edition link. **DONE 2026-05-14.**
- [x] **Tests:** 6 new integration tests (excluded-edition rejection, non-excluded acceptance, legacy back-compat, prorate Full, prorate Percentage, 0-session fallback). 26 voucher integration tests + 674 unit + 227 integration all green. **DONE 2026-05-14.**

**Dropped from original plan (no longer in scope):**
- ~~5 voucher categories (member/action/speaker/day/social)~~ — admin uses scope + discount type to express same policies without baking categories into code
- ~~Edition `is_multi_year_training` field~~ — admin adds tweejarige editions to "Behalve" list on member vouchers
- ~~`VoucherTypeValidator` category dispatcher~~ — replaced by scope validator (narrower, simpler)
- ~~Social voucher hardcoded 50%~~ — admin sets `discount_type=Percentage value=50`

**Still deferred to Phase 8 (post-launch):** member voucher auto-generation, voucher reversal on cancellation, annual renewal cron.

---

## D. Deferred Bugs in Launch Modules — 11 bugs (P0–P1)

Per user decision: all 11 in launch-relevant modules are launch blockers.

### Completion module (5)
- [ ] (P0) No LearnDash `course_completed` sync — completion task doesn't propagate to LD
- [ ] (P1) Deprecated `current_time('timestamp')` calls
- [ ] (P1) Cache not cleared on task update
- [ ] (P0) `Withdrawn` enum mismatch
- [ ] (P1) DI coupling — refactor for testability

**Source:** `tasks/shake-out-completion-manifest.md` (superseded by v2 for fixed ones, but these 5 deferred remain)

### Attendance module (3)
- [ ] (P0) Cascade delete missing — orphans on session/edition delete
- [ ] (P1) Orphan `session_registrations` rows
- [ ] (P1) Semantic count inconsistency

**Source:** `tasks/shake-out-attendance-manifest.md`

### Theme (3)
- [ ] (P0) 7 footer pages return 404 — links to nowhere on public site
- [ ] (P1) LearnDash ProPanel script notice (console noise)
- [ ] (P1) 11 shortcodes not yet implemented — list them, decide replace vs stub

**Source:** `tasks/shake-out-theme-manifest.md`

### User lifecycle / GDPR (3)
- [ ] (P0) **Anonymise on user delete, don't `wp_delete_user()`** — registrations/quotes/certificates currently orphan when a user is deleted. New `UserLifecycleService::anonymise($userId)` strips PII (display_name → "Verwijderde gebruiker #N", clear email/login/billing/phone/org/department), keeps the `wp_users` row, adds `_stride_anonymised_at` meta. Hook `delete_user` and prevent actual row deletion. **Why:** GDPR-compliant + preserves historical counts, invoices, certificates.
- [ ] (P0) **EditionRegistrationMetabox renders anonymised users** — replace silent `continue` on missing `$user` (line 151–153) with a faded "verwijderd" row, no actions. Make metabox match exporter's row count so the badge count is honest. Show `_stride_anonymised_at` if present.
- [ ] (P1) **One-shot cleanup CLI** — `wp stride anonymise-orphans` finds registrations where `user_id` doesn't exist in `wp_users` and either anonymises (if the row matters) or deletes (if it's seed garbage). Needed for dev environment now; safety net in prod.

**Why this exists:** discovered on edition 13234 (dev) — 11 registrations, only 1 name visible in metabox, Excel export shows `Gebruiker #2223401` placeholders. Root cause: no `delete_user` hook anywhere in stride-core (verified by grep), so user deletion leaves orphan registrations + stale LearnDash access + unverifiable certificates. GDPR requires anonymisation, not deletion, for training records (BE retention 7–10 years).

### Edition capacity / "Volzet" auto-status (2)

**Status:** auto-promotion to `OfferingStatus::Full` ("Volzet") is wired and works in both directions (`EditionService::onRegistrationCreated` / `onRegistrationCancelled` at lines 266–290). Listens to `stride/registration/created` and `stride/registration/cancelled`. Capacity 0 = unlimited (e-learning). **Not bugs — known edge cases to watch:**

- [ ] (P1) **Pending registrations hold capacity indefinitely** — `getRegisteredCount()` counts `pending` + `confirmed` + `completed` (EditionService.php:98). Abandoned pendings (user starts enrollment, never finishes) will falsely keep editions at `Volzet`. No cleanup cron exists. **Decide:** stale-pending expiry (e.g. 24–48h auto-cancel) or accept and document for admin awareness.
- [ ] (P2) **Status doesn't recompute on capacity edit or bulk import** — if admin lowers capacity below current count, or seed/import inserts registrations without firing `stride/registration/created`, the status won't auto-update until the next real registration event. Add a `EditionService::recomputeStatus($editionId)` and call it from the capacity-changed hook + a `wp stride recompute-edition-status` CLI for imports.

---

## E. Untested Components (P0–P1)

- [ ] (P1) Admin Dashboard shake-out — covered by section A above
- [ ] (P2) Trajectory module shake-out — *deferred (post-launch feature)*

---

## F. Design System — Multi-Brand Demo (P1)

**Goal:** flip a switch and show the same platform looking radically different. Sales demo material.

**Foundation (DONE):**
- [x] Client customization system via mu-plugin pattern
- [x] `stridence_template_part()` + `stridence_template_path` filter
- [x] 40+ CSS tokens in `src/css/tokens.css`
- [x] Reference scaffold: `web/app/mu-plugins/stride-client-example/`
- [x] Editorial rebrand (design shell) — `docs/plans/2026-03-26-editorial-rebrand-design.md`
- [x] BWEEG demo (1st brand) — `docs/superpowers/specs/2026-03-27-bweeg-homepage-content-design.md`

**Open:**
- [ ] (P1) Brand scaffold #2 — corporate training or university CPD (pick one), full tokens + content + hero imagery
- [ ] (P1) Brand scaffold #3 — distinctly different vertical for max contrast (e.g. wellness / public sector)
- [ ] (P1) Swap mechanic doc — how to install a new brand mu-plugin (1-page guide for sales/dev)
- [ ] (P2) Side-by-side comparison screenshots for sales deck

**Note:** Brand scaffolds are mu-plugin scaffolds. No core changes needed — the swap capability is already proven by BWEEG.

---

## G. Design Drafts to Decide (P2)

User decision: keep alive, decide later. Re-review before code freeze.

- [ ] `docs/plans/2026-03-16-session-price-modifiers-design.md` — per-session pricing. Niche; confirm if VAD actually needs it for launch
- [ ] `docs/plans/2026-03-17-stride-mail-integration-design.md` — verify it isn't superseded by the shipped netdust-mail
- [ ] `docs/plans/2026-03-18-roles-capabilities-design.md` — verify how much is already implemented

---

## H. Planned / Vision Work (Post-Launch)

Tracked but NOT in Phase 1 scope. Keep in memory, surface after launch.

- Assistant CSV/Excel/DOCX exports — `memory/project_assistant_exports.md`
- Assistant evolution: headless mode, WP-CLI, event-triggered AI — `memory/project_assistant_vision.md`
- Trajectory module — never shake-out tested
- Partner API — 5 deferred bugs, foundational design done
- LTI plugin — in progress on staging branch, not for launch
- Phase 8 voucher automations — auto-generation, renewal cron, reversal on cancellation

---

## Pre-Launch Cleanup

- [ ] Stash or commit uncommitted LTI work on `staging` (don't lose it; not for launch)
- [ ] Move stray PNGs out of repo root (`bento-section`, `debug-outlines`, `stridelms-fullpage`)
- [ ] Add `tests/_output/` to `.gitignore` (40+ untracked screenshots)
- [ ] Decide: hide Trajectory + Partner API admin UI for v1, or leave visible?

---

## How to Use This File

- **One source of truth** for "what's left before launch." Update it as scope changes, not as a journal.
- For **active sprint work**, use `tasks/todo.md` (small, ephemeral).
- For **current project state** (decisions, risks, recent context), see `memory/STATE.md`.
- For **deep design docs**, see `docs/plans/` and `docs/superpowers/specs/`.
- For **per-module bug history**, see `tasks/shake-out-*.md`.

When an item is done, mark `[x]` and link the PR/commit in a one-line note. Don't delete completed items — they become the launch evidence trail.
