# Stride — Project State

Current state of the project for session continuity. Updated after meaningful work.
**For "what's left to launch" see `docs/LAUNCH-CHECKLIST.md` (single source of truth).**

Last refresh: 2026-06-10 (hardening sprint, phases 0–3 done; audit-remediation handoff written)

---

## NEXT: Audit remediation sprint — handoff ready

Full handoff block at the top of `tasks/todo.md` (§NEXT SPRINT). Source: `docs/architecture/AUDIT-2026-06-10.md` (grade B−). Scope ruled by Stefan 2026-06-10: Milestones 0+1 + perf-critical M2; M3 post-launch; task 1.2 (Makefile/deploy method) parked — Stefan handles it. Execute via harnessed-development → planner (Class-B freshness review), audit's Deps column is authoritative for order. 4 open questions need Stefan first (uploads sensitivity, perf budget, history rewrite, Redis on Ploi) — listed in the todo.md block.

---

## Hardening sprint — 2026-06-10 (phases 0–3 DONE, 4 open)

Plan approved by Stefan (saved at `~/.claude/plans/glowing-roaming-wozniak.md`; tracked in `tasks/todo.md` top block). Decisions: **VAD = launch brand**, Phase-3 test depth = targeted P0 flows. ~16 commits, `7f2ddce9..8c938819`.

**Suites: 924 unit + 369 integration + 121 acceptance — all green** (final clean full-suite run 2026-06-10).

**Phase 3 (targeted P0 edge-testing) DONE:** 13 new edge tests through the real browser — `EnrollmentEdgeCest` (6), `AttendanceCest` (4, was zero-coverage), `DashboardQuoteGdprEdgeCest` (3). Matrix + pass/fail manifest at `docs/architecture/acceptance-flows/p0-hardening-phase3.md`. 6 FEATURE-STATUS rows flipped to ✅ edge-tested (now 7 of 19 clear the strict bar, was 1). No product bugs surfaced — failures hit were test-fixture fidelity (quote meta = bare keys; Data API prefixes on read) + faithful-layer choices. Also deflaked 2 pre-existing tests (canAddSession seed-accretion → dedicated edition; registration retry now clears rate-limits). F5 cert + F6 expired-access left unit/integration-covered per the targeted scope.

Highlights (full detail in todo.md + FEATURE-STATUS addendum):
- **Acceptance suite was structurally unrunnable since authoring** (hardcoded `stride_` prefixes vs `ckqp_`, `/vormingen/` URLs, nonexistent `admin` login, `$I->fail()`). Repaired prefix-agnostic; 23/108 → 108/108. The "126 tests" previously cited as evidence had never run.
- **Real bugs fixed:** `validateSelections()` rejected every elective selection (4 shape bugs); dashboard nav flicker (single-source derivation); **edition-backed online courses had NO enrollment CTA on /opleidingen/** (sidebar edition branches existed but were never fed); INV-6 write bypass (CourseEnrollHandler).
- **Launch brand was not in git:** `stride-client-vad/` was caught by the mu-plugins ignore rule — tracked now. Kindred's duplicate live loader removed (was active simultaneously with VAD).
- **test-login-helper.php hardened:** WP_ENV production gate, env-only secret (STRIDE_TEST_LOGIN_SECRET in .env), HMAC + hash_equals; now tracked in git.
- **Audit truth-up:** H4/M1/M3/M4/M5/M6 were already fixed in code (report said deferred). Open: M2 (trajectory quote race) + C2/L2 (Partner API) — both post-launch modules. See report's two Status sections.
- **Env gotchas fixed:** stale v3 `upload_path` option in dev DB (broke all uploads — also a migration risk for prod DB ports!); voucher test TZ flake (site clock vs UTC near midnight).

**Open:** Phase 4 (deploy readiness — ⚠ **no Makefile exists** though site.yml declares `make deploy-staging`; prod .env must NOT set STRIDE_TEST_LOGIN_SECRET; standing list = LTI off / SMTP / stride_admin_email / replay footer pages).

---

## Where we are (2026-06-08)

Phase-1 launch **feature-complete**; in a deep-testing cycle before launch. Baseline assessment: `docs/architecture/BASELINE-2026-06-08.md`.

- **Tests: 913 unit + 261 integration green** (913 verified 2026-06-08; was incorrectly recorded as 674/221).
- **Code health:** boots clean; framework engine + domain layer + theme clean; drift concentrated in `Admin/` god class + per-CPT admin controllers (deferred per ship-mode). Impersonation audit-bypass (`AdminAPIController.php`) **fixed 2026-06-08** — now routes through `AuditService::record()`. NetdustMail test isolation bug **fixed 2026-06-08**.
- **Harness:** opted in (CLAUDE.md + `ARCHITECTURE-INVARIANTS.md` exist). Next feature runs through `harnessed-development`; `compounding` will grow `docs/architecture/CODE-MAP.md`.

## Trajectory cascade — DONE 2026-05-20

**Status:** ✅ Shipped. (STATE previously said "not started" — false; it shipped the same day the plan was written.)
- `09c28ab9` (22:41) — `parent_registration_id` schema + child queries (was "step 1 to execute next session" — already done).
- `b712c8c6` (23:52) — cascade-enrollment steps 4–15 (`TrajectoryCascadeService` + cascade on enroll/select/cancel/status-change + PartnerAPI).
- Locked decisions (mandatory@parent-create / electives@setSelections, pure-LD via grantAccess + `_stride_trajectory_courses`, cohort cascades / self-paced independent, capacity `WP_Error('edition_full')`, child quote only when trajectory €0 & child >€0) — all implemented as specified.

## enrollment_data namespacing + initial_selection — DONE 2026-05-24

(Was entirely absent from STATE — 20+ commits, plan `eeba1e95`.) Wrapped stage envelope (submitted_at/submitted_by/submitter_metadata), snapshot of initial selections at enroll/setSelections, admin modal + export integration across enrollment/trajectory/questionnaire services.

## Next up

- **Phased choices — NOT started.** Plan `plans/2026-05-20-trajectory-phased-choices.md`. Was blocked on cascade (now unblocked). Per-keuzegroep `opens_at`/`deadline` + lazy task creation.
- Pre-launch deploy tasks (todo.md ~178–184): deactivate LTI, configure SMTP, recreate footer pages.

> **Note:** lines below ~178 contain 400+ auto-captured "session ended (no significant changes)" entries — noise, not signal. Safe to ignore; collapse on a future curation pass.

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

- **Courses on demand / incompany ("op aanvraag")** — scoped 2026-07-03, real product gap that would benefit clients. Vision: klassikaal/webinar courses discoverable as "op aanvraag"; a third org requests hosting on location; **the request is the payable action** (request → admin approves → quote → org pays → edition created → normal enrollment, free or paid per-seat). Grounded gap analysis (verified against source 2026-07-03):
  - *Exists today:* individual interest flow (dateless klassikaal → `Announcement` → `/interesse/` form → registration row `status=interest`); Partner API (6 routes, `_stride_company_id` usermeta + `company_id` column, single-seat enroll w/ optional `create_user`); quote CPT already has `registration_id`/`edition_id` as *optional* fields (only `user_id` required) so a request-anchored quote is additive.
  - *Missing:* (1) org-level request intake — recommend a `vad_request` CPT with lifecycle `new → approved → quoted → paid → planned → closed/rejected`, quote hangs off it, "paid" = manual admin flip (Exact owns invoicing, no payment tracking by design), then "create edition from request" affordance; (2) **private/company-scoped editions — no visibility/audience concept exists at all**: any published+active edition is public and enrollable by anyone logged in (`canEnroll()` checks only status+capacity); needs edition `visibility`+`company_id` fields, catalog predicates in BOTH `EditionRepository::catalogDateWindowMetaQuery()` and the theme's copy in `helpers/catalog.php` (drift hazard), and an enrollment authz gate → new multi-tenancy boundary → threat model required; (3) partner frontend dashboard — `partner` role is API-only, zero UI; drags in a minimal Company entity (today company = bare int, no name, no admin UI to set affiliation) + employee invite/affiliation flow.
  - *Phasing:* 1. request intake+lifecycle (small, useful standalone; incl. suppress per-seat quote on €0 editions) · 2. private editions (medium, security-sensitive; optional per-request if hosted courses may stay public) · 3. partner dashboard + onboarding (large).
  - *Open decisions:* private-always vs per-request "besloten" toggle; employees self-serve vs partner-enrolls; introduce minimal Company entity (phase 3 forces it).
- **Online payments — gateway wrapper in stride-core (client ask, 2026-07-03).** Buckaroo requested by a client; the linked WooCommerce plugin is unusable (no WooCommerce), but Buckaroo has an official PHP SDK (`buckaroo/sdk`, github.com/buckaroo-it/BuckarooSDK_PHP). Stefan's ruling: build a gateway-agnostic wrapper in stride-core; concrete gateway implementations (Buckaroo, Mollie) live in the client mu-plugin and are billed per client. Design shape agreed:
  - *Contract:* `Stride\Contracts\PaymentGatewayInterface` mirroring `LMSAdapterInterface` — minimal ops: `createPayment()` → redirect URL, `handlePush()` (webhook), `refund()`, `getStatus()`. Both Mollie and Buckaroo share the hosted-redirect flow (create → redirect → server-to-server push → final status), so the common-denominator interface holds.
  - *Core owns:* contract, payment state machine on the registration (replaces the dead `paid_at`), one stable webhook route (gateway resolved via container binding), the enrollment-flow fork (pay-online vs. quote path), admin visibility. Default-off: no binding registered → feature off, base Stride unchanged (FLAG-style).
  - *Client mu-plugin owns:* concrete gateway class + credentials (same home as per-client templates/CSS tokens).
  - *Decide at wrapper level, not per client:* (1) payment attaches per registration, amount from the same source quotes read; which flows can pay online is a core capability the client config toggles; (2) "paid" → confirm registration / grant access transition lives in core, gateway only reports the fact.
  - *Gates:* webhook = untrusted input granting course access → threat model required. Exact-reconciliation question (Buckaroo settlement vs. Exact invoicing) must be answered per client before build.
  - *Pricing note:* first client pays wrapper + webhook surface + state machine (~80% of the work) — price as "payments feature + gateway"; later clients pay gateway-only.
- **New gate types — link-click acknowledgment + video-watched (scoped 2026-07-03).** Gates = completion tasks: string-typed entries in the per-registration `completion_tasks` JSON, enabled per offering via `requires_*` meta, orchestrated by `EnrollmentCompletion` (7 types today; master allowlist `TASK_TYPES` at `EnrollmentCompletion.php:68`). No plugin-style registry — a new type touches ~9 known places: `TASK_TYPES`/`META_KEYS` + availability switch + `taskTypeLabel()` in `EnrollmentCompletion`, `requires_*` field in BOTH `EditionCPT` and `TrajectoryCPT`, shared `OfferingSidebarPartial` checkbox list, `CompletionTaskHandler` (generic `stride_complete_task` already forwards any allowlisted type + `data` payload), theme partial `task-{type}.php` + duplicated label maps in `completion.php` and `completion-checklist.php`, and `GateReminderService::PHASES` if it should be reminded.
  - *Link/acknowledgment gate ("kennisname"):* small (~1 day). Checkbox + per-edition URL field (precedent: documents instruction), frontend shows link + "Gelezen en begrepen" confirm, stores `{url, clicked_at}` via existing endpoint. Pair with explicit confirm-checkbox — click ≠ read. Consent/policy-acceptance variant nearly free once this exists.
  - *Video-watched gate:* medium (few days). Same 9 touchpoints; the work is player tracking. Self-hosted `<video>` (track `timeupdate`, block seek, complete on `ended`) is robust; YT/Vimeo embed APIs report `ended` but are gameable. All client-side signals spoofable — audit-grade proof needs server-side watched-seconds heartbeats. Decide strictness first.
  - *Other candidates:* quiz-with-pass-threshold (proves comprehension), payment/PO-received (admin-closed like `approval`), prerequisite-course gate (auto-completes off LD `isComplete()`).
  - *Refactor trigger:* at the THIRD new type, collapse the label/template/meta-key maps into a single gate-type registry (one class + one partial per gate). Not before.
  - *Sequencing:* build on top of / after `feat/gate-deadlines-reminders` — same files (`EnrollmentCompletion`, `OfferingSidebarPartial`, `EditionCPT`).
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
[2026-05-21] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-05-24] — session ended (no significant changes captured)
[2026-06-05] — session ended (no significant changes captured)
[2026-06-05] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-08] — session ended (no significant changes captured)
[2026-06-09] — session ended (no significant changes captured)
[2026-06-10] — session ended (no significant changes captured)
[2026-06-11] — session ended (no significant changes captured)

---
### 2026-06-11 — tagged capture

**Decisions**
- Helder Tij is the stock theme look; VAD/client plugins keep their own skins via the verified override chain.
[2026-06-12] — session ended (no significant changes captured)
[2026-06-13] — session ended (no significant changes captured)
[2026-06-14] — session ended (no significant changes captured)
[2026-06-16] — session ended (no significant changes captured)

---
### 2026-06-17 — tagged capture

**Decisions**
- now that the root cause is fixed, the test should be **simplified back to just `seedTemplates()`** — that's the honest state (the production seed path now works). But I'll keep a lightweight safety: the reconcile guarded against cross-test pollution too. The cleanest principled choice: revert the test to minimal (just seed), since the repo fix makes it correct, and verify it passes in the fresh-DB simulation. Let me simplify the test setUp.
[2026-06-22] — session ended (no significant changes captured)
[2026-06-23] — session ended (no significant changes captured)

---
### 2026-06-23 — tagged capture

**Decisions**
- **Map reflects server reality.** I'll add `Pending → Cancelled` and `Waitlist → Cancelled` to the map, update the 3 tests to assert the truthful contract (RED→GREEN), and the JS bar + detector then agree. Let me apply the map change.

---
### 2026-06-23 — tagged capture

**Decisions**
- **keep stacking, merge at slice end** — matches the original handoff plan. So:
[2026-06-24] — session ended (no significant changes captured)

---
### 2026-06-24 — tagged capture

**Decisions**
- **Offertes gets Search+Tag+Date** (backend added), **Trajecten stays search-only** (skip tag+date — they don't fit its data model).
[2026-06-25] — session ended (no significant changes captured)
[2026-06-26] — session ended (no significant changes captured)
[2026-06-27] — session ended (no significant changes captured)
[2026-06-29] — session ended (no significant changes captured)
[2026-06-30] — session ended (no significant changes captured)

---
### 2026-06-30 — tagged capture

**Decisions**
- **keep teaser behavior, just de-duplicate.** Task 3.3's scope is now refined — the archive repoint preserves the homepage's distinct active-only/no-dateless behavior, but moves the query code into stride-core so no raw `WP_Query`/`meta_query` lives in the theme. This is a more correct (and safer) version of B3 than the plan assumed.

---
### 2026-06-30 — tagged capture

**Decisions**
- I'll branch the new feature work off the current branch tip (which includes both main's history *and* the disposable-DB CI guard — relevant since the plan's integration tests depend on `STRIDE_TEST_DB_DISPOSABLE`). This keeps the CI guard available without polluting the feature branch with unrelated concerns once `288d099e` merges. Let me create the feature branch.

---
### 2026-06-30 — tagged capture

**Decisions**
- **only e-learning hides sessions.** Now let me implement this through the harness as the global rules require. This is a small, well-scoped bug fix (Class E/C) — change the predicate so the gate keys on `e-learning` only.
[2026-07-02] — session ended (no significant changes captured)
[2026-07-03] — session ended (no significant changes captured)
