# Handoff — Admin Rebuild (Phase 1 done, Phase 2 ready to build)

**Written:** 2026-06-24, end of session. **For:** the next session picking up the admin rebuild.

---

## TL;DR / where to start

- **Phase 1 (admin BACKEND cleanup) is DONE, fully gated, and MERGED to local `main`.** Nothing to do there.
- **Phase 2 (admin FRONTEND rebuild from the wireframes) is PLANNED and APPROVED-IN-PRINCIPLE but NOT started.** Plan: `docs/plans/2026-06-24-admin-frontend-rebuild.md`.
- **The user paused at the Phase-2 go/no-go to clarify something — they had NOT yet answered "approve, start cluster A."** Do not assume approval. Re-surface the Phase-2 plan, answer their clarification, get an explicit go before dispatching the first implementer.
- **Next concrete action:** read the Phase-2 plan + the two memory files below, then ask the user the open clarification (see "Open question" at the bottom) and start cluster A only on their go.

---

## Git state (exact)

- Active branch: **`feat/admin-frontend-rebuild`** @ `a70cec89` (== `main`; created off clean main for Phase 2, NO commits yet).
- **`main` @ `a70cec89`** — contains Phase 1 backend cleanup + Phase 2a cohort-lens (merged this session). 99 ahead of `origin/main` (nothing pushed; local-main workflow).
- `admin-backend-cleanup` @ `a70cec89` — the merged Phase-1 branch (now == main).
- **`feat/admin-shell-rehouse` — ABANDONED. Do NOT resume it.** (The half-migration mess; see lesson below.)
- Suites baseline: **Unit 1109 / Integration 648**, 2 pre-existing fails (`AdminTrajectoryOptionsEndpointTest::returnsLightweightOptionsForAdmin` + `::scopeActiveExcludesTerminalStatus`) — proven benign (seed-accumulation pollution, untouched path). PHPStan + Pint clean.

## Read these first (memory)

`~/.claude/projects/-home-ntdst-Sites-stride/memory/`:
- **`lesson_admin_rehouse_reset.md`** — WHY we rebuild instead of patch, and the verification lesson (per-acceptance-row gates MISSED the half-empty Vandaag → the gate MUST be screenshot-vs-wireframe on COLD-LANDING).
- **`project_admin_backend_cleanup_done.md`** — exactly what Phase 1 delivered (the clean backend contract Phase 2 builds against).
- `project_admin_shell_rehouse.md` (mostly superseded), `project_admin_workspace_*` (the spec/phasing history).

---

## What Phase 1 delivered (the clean backend — Phase 2 wires INTO this, does NOT change it)

16 commits `b6936b06..a70cec89`. AdminAPIController **2,877 → 2,526**; read-models in 5 services (INV-3): `AdminQuoteService`, `AdminExportService`, `AdminActivityService`, `EditionAdminMapper`, + repo methods. The frontend-facing contract is now CONSISTENT:
- **ONE error shape:** every admin endpoint → `WP_Error` → JSON `{code, message, data:{status}}`. The new JS `api()` helper should read `.message` (the old `{error:msg}` shape is GONE — Phase 1 fixed a latent bug where the helper couldn't read it).
- **Effective status everywhere (INV-7):** edition grid/agenda/detail/typeahead all emit EFFECTIVE status consistently. The frontend renders `status.value`/`status.label` AS RECEIVED — never re-derive past/terminal client-side.
- Endpoints (gated + secure, /security-review PASS): `GET /admin/registrations`, `/admin/stats` (has `worklistQueues` built for Vandaag), `/admin/action-queue`, `/admin/editions`+`/{id}`+`/options`, `/admin/trajectories`+`/{id}`+`/options`, `/admin/users/{id}/detail`+`/{id}/trajectories`+`/users/search`, `/admin/editions/{id}/roster` (cohort), `ntdst/v1/action` (bulk), `/admin/quotes`, exports.
- Security hardened: S1 partner trajectory-child company-scope, S2 impersonate route → `manage_options`, N1 PII-reveal rate-limit (20/min/user).

**Deferred Phase-1 follow-ups (documented, NOT blocking Phase 2):** `getPendingApprovals`→AdminApprovalQueueService + `getEditions`/`getEditionsAgendaView`→AdminEditionListService (~15% drain); `AdminQuoteService` zero-user-search returns `{data}` not `{items}` envelope (normalize when the grid consumes it); the 2 pre-existing trajectory test fails (seed pollution — worth a real fix sometime). See `docs/plans/2026-06-24-admin-backend-cleanup.md` §"Deferred follow-up".

---

## Phase 2 — the plan (`docs/plans/2026-06-24-admin-frontend-rebuild.md`)

**Goal:** rebuild the admin FRONTEND from the wireframes (`docs/mockups/admin-workspace/*.html`) WHOLESALE, on the clean backend. The wireframes are a complete, coherent design system (one `ws-*` system; per-page `x-data` factories — i.e. PER-SURFACE components, the right architecture; 2 CSS `workspace.css`+`grid.css` ~1,200 lines; 2 JS `data.js`+`grid.js`).

**Shell/asset strategy (decided):** DELETE the 2,275-line `admin-dashboard.js` god-component + `dashboard.php` + the 3,917-line franken-CSS (incl. the `--ws-→--sd-` bridge). Adopt `workspace.css`+`grid.css` as the only design system; lift each wireframe's `x-data` factory into its own file under `assets/js/admin/`. KEEP the WP `add_menu_page` registration + `injectStyles`/`injectScripts` pattern + `StrideConfig`/`X-WP-Nonce`/`ntdstAPI` nonce plumbing. Fonts self-hosted (Space Grotesk/Inter Tight/JetBrains Mono — no Google Fonts link in wp-admin). Hardest shell risk: the wireframe CSS owns `<body>` for file://; re-point the WP-chrome-hiding CSS at the new `ws-shell` host (the abandoned attempt's "leftover WP Dashboard sidebar" bug).

**7 clusters (cluster = surface = review boundary):**
- **A — Shell + asset wholesale-replace + FIX THE STALE PLAYWRIGHT LOGIN FIXTURE (do first).**
- **B — Vandaag · C — Inschrijvingen (grid) · D — Dossier · E — Trajecten** (STANDARD)
- **F — Edities/Offertes/Sessies/Gebruikers** (no wireframe → restyle to `ws-*`)
- **G — Cohort-lens slideover** (Phase-2a logic preserved, re-skin only; INV-6b untouched)

**THE STRUCTURAL FIX (§5) — non-negotiable:** each per-surface Alpine factory OWNS loading ALL its own data in `init()`. "Landed on surface X but its data empty" must be structurally impossible (the half-empty Vandaag's root cause was a shared loader that left stats unloaded). Empty/loading/error states are LOAD-BEARING (they're the feature-acceptance edge classes).

**THE GATE (every cluster + spec-close):** screenshot-vs-wireframe on the REAL COLD-LANDING state (fresh browser load, no prior nav, no warm cache), compared HOLISTICALLY to the mockup — NOT isolated acceptance rows. A surface isn't "done" until its cold-load screenshot reads as the same finished page as the mockup. (This is the anti-regression for the exact failure that sank the last attempt — agents reported PASS on isolated assertions while the real page was broken.)

**BLOCKER scheduled FIRST (cluster A.3):** the Playwright admin login fixture `tests/frontend/admin/fixtures/admin-helpers.ts` is FULLY STALE (MD5 + hardcoded user 3191 + hardcoded secret) vs the live `test-login-helper.php` (HMAC-SHA256 `login:<id>`, secret from `.env`, seed admin id ~13740). Until fixed, every browser gate is FICTIONAL — same class as the abandoned attempt's green-while-broken. The working HMAC login URL pattern (used by the QA agents this session): `GET /?stride_test_login=1&user_id=<id>&test_key=<hash_hmac('sha256','login:'.$id, STRIDE_TEST_LOGIN_SECRET)>&redirect=<url>`. The admin page is `/wp/wp-admin/admin.php?page=stride-dashboard`.

**Gates:** 1g feature-acceptance (the screenshot-vs-wireframe matrix) is the headline. 1a threat-model does NOT fire (pure presentation on already-gated endpoints — no `/security-review` needed). 1b cites INV-5 (audit EVERY `x-html` — must bind a CONSTANT icon name/closed-enum label, NEVER a data field like name/email/notes), INV-6b (cohort/dossier selections server-owned), INV-7 (render status as-received). Tiers: overwhelmingly Tier B (presentational) — real verification is the browser cold-landing gate, NOT jsdom.

**Biggest data-mapping risk (§4/§8):** the Vandaag **"Acties-nodig" panel** — mock `WS.ACTION_QUEUE` expects 3 per-PERSON buckets `{mij,gebruiker,meldingen}`, but real `GET /admin/action-queue` returns a FLAT priority-sorted rule-AGGREGATE list (no per-person rows, no buckets). Plan's recommended resolution (decided at cluster-B dispatch, NO backend change): drive per-person rows from `GET /admin/registrations?status=pending`. **This is exactly the panel the abandoned attempt left empty — its cold-landing screenshot MUST show it populated.** Secondary: dossier mock `timeline` (per-write event log) + `completion` checklist have no clean single backend source — plan hides-when-empty; the user may want them wired or explicitly dropped (see open question). General rule for grid+dossier: real endpoints return NESTED objects (`user:{}`, `edition:{}`, `status:{value,label}`) vs data.js flat scalars → DELETE data.js, bind markup to real nested keys, do NOT build a mock-shape adapter.

---

## OPEN QUESTION for the user (they were mid-clarification when the session ended)

The user invoked "clarify" on the Phase-2 go/no-go instead of answering. Re-ask, offering these likely concerns:
1. **No working admin during the rebuild** — wholesale-replace means the admin is non-functional cluster-by-cluster until each surface lands. Acceptable, or build behind a flag / keep the old one until parity?
2. **Acties-nodig resolution** — per-person rows from `?status=pending` (plan's call) vs show the aggregate alerts as-is vs something else?
3. **Dossier timeline/completion gaps** — wire them (more work, needs a source), or hide-when-empty (plan's call), or drop?
4. **Cluster order** — shell-first (plan), or a specific surface first (e.g. the grid, most-used)?

Get an explicit "go" + resolve at least #1 before dispatching cluster A.

---

## How this session ran (process notes for continuity)

- Harness: `netdust-agent:harnessed-development`. Each cluster = planner→implementer(s)→review-gate(finders + the surface's browser gate), hard STOP between clusters. Phase 1 ran A–G + spec-close exactly this way, all green.
- Dispatch implementers per task with the verbatim netdust addendum (Test-evidence + STATUS blocks). Strangles used characterization-test-first (capture current output, prove byte-identical). Behavior changes used RED-first.
- The harness caught real bugs at the gates this session (the C1 second-site status bypass, the F-1 incomplete cache-bust set, the dropped-predicate blind path) — TRUST the gates, run the finders, don't skip the browser cold-landing check.
- Background agents occasionally stall (watchdog 600s) — resume them with SendMessage; their work is usually intact, just uncommitted.
