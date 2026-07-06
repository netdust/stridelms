# Phase 2 — Admin Frontend Rebuild from the Wireframes

**Branch:** `feat/admin-frontend-rebuild` (off clean `main`; Phase 1 backend cleanup merged)
**Work class:** A (new multi-task feature — wholesale rebuild of a presentation layer)
**Author:** planner persona, harnessed-development Stage 1
**Date:** 2026-06-24
**Status:** PLAN — not started. No code in this document.

---

## 0. Why this is a rebuild, not a patch (read first)

The admin frontend was previously attempted as an **incremental `sd-*` → `ws-*` morph** and abandoned 2026-06-24: two complete design systems coexisting (216 `sd-*` + 167 `ws-*` classes in `dashboard.php`; a 2,474-line `strideApp()` god-component; a half-empty Vandaag that showed empty skeletons on cold-landing). See `memory/lesson_admin_rehouse_reset.md`.

**The decision (Stefan):** rebuild the presentation layer from `docs/mockups/admin-workspace/` **wholesale** — adopt the `ws-*` HTML/CSS + per-surface Alpine components, delete the `sd-*` chrome + bridge CSS + god-component, wire the new surfaces onto the **unchanged** Phase-1 backend. Look-and-feel is copied now, adjusted later.

**The lesson that shapes this plan's VERIFICATION (non-negotiable):** the previous attempt's per-acceptance-row gates ALL reported PASS while the page was visibly broken — because they checked isolated assertions (a selector exists, nav switches) and authenticated + navigated before screenshotting, so they never saw the **cold-landing state** (fresh load → default view, nothing pre-loaded). The half-empty Vandaag passed every gate.

> **THE GATE FOR EVERY CLUSTER AND FOR SPEC-CLOSE: screenshot-vs-wireframe on the REAL COLD-LANDING state of each surface, compared holistically to the mockup — NOT isolated acceptance rows.** A surface is not "done" until a fresh browser load (no prior nav, no warm cache) renders the populated surface and that screenshot reads as the same finished page as the mockup. This is baked into the `## Acceptance flows` matrix (§7) and the spec-close gate (§9).

---

## 1. Work classification & gate decisions (the planner's explicit calls)

**Class A.** Full sequence: this Stage-1 plan → spec-analysis manual checklist → execute per-cluster → shake-out + finish.

### Gates that FIRE

| Gate | Fires? | Trigger / reason |
|---|---|---|
| **1g feature-acceptance** | **YES — headline** | User-facing surfaces (Vandaag, grid, dossier, trajecten, editions/offertes, cohort slideover). The `## Acceptance flows` matrix in §7 is the convergence target; its cold-landing screenshot-vs-wireframe column is the anti-regression for the abandoned attempt. |
| **1b architecture-invariants** | **YES (cite, don't author)** | Touches **INV-5** (no `x-html` on dynamic/user data), **INV-6b** (cohort/trajectory selections via server-owned `getSelections`/`resolveSelectionLabels`), **INV-7** (effective status — the frontend must render the status the backend already emits, never re-derive). `ARCHITECTURE-INVARIANTS.md` already exists; we CITE, we do not re-author. See §6. |
| **1c spec-premise ground-truth** | **YES — DONE in this plan** | Core premise = "wire the wireframe factories to the existing endpoints." Ground-truthed in §4 against the 6 real services. One MAJOR divergence found (Vandaag action-queue) + several MINOR (flat-vs-nested shapes). See §4 + §8. |
| **wp-plan-requirements (stack override)** | **YES — but lightweight** | WordPress project. No new AJAX/REST handlers, no new CPT, no form processor, no new query — pure presentation consuming **already-gated** endpoints. The WP-security four pillars apply only to the *output-escaping* pillar (the new templates echo server data) and the *nonce* pillar (preserve the existing `X-WP-Nonce` plumbing). Capability + sanitization pillars are unchanged (owned by the untouched backend). See §6. |

### Gates that DO NOT fire (stated explicitly, per the literal trigger list)

| Gate | Fires? | Why not (literal trigger check) |
|---|---|---|
| **1a threat-modeling** | **NO** | Run the trigger list literally: no new user-controlled URL, no new auth/session/token surface, no new untrusted-parsing path, no new BYOK credential, no new multi-tenancy boundary, no new outbound-to-user-URL. This is presentation consuming endpoints whose auth/nonce/capability/scoping were threat-modeled and `/security-review`-passed in Phase 1 (`memory/project_admin_backend_cleanup_done.md` — S1/S2/N1). **One caveat is NOT a new attack surface but IS a 1b/INV item:** the wireframes use `x-html="WS.icon(name)"` to inject SVG. That is a CONSTANT icon-name lookup, not user data — safe — but every `x-html` in the ported markup must be audited to confirm none binds DATA. That audit is INV-5 (§6), not a threat model. **No `## Threat model` section, and `/security-review` is therefore NOT mandatory** (no plan-time threat model exists to trigger it). |
| **1f new convergence point authoring** | **NO** | No new cross-cutting convergence point is created; we consume existing ones. |

### Provisional review tiers (1h) — assigned per cluster, restated at each gate

All clusters touch **multi-file behavior change outside the 1a surfaces** (presentation), so the default is **STANDARD** (2 finders + simplicity + the feature-acceptance browser pass; no security-sentinel). **Exception:** Cluster A (Shell/asset strategy) deletes the god-component + franken-CSF and rewires the WP admin page registration/nonce plumbing — STANDARD, but with a mandatory dead-code sweep. One-way escalation (1h): if any finder surfaces a finding on a 1a surface (e.g. an `x-html` on data, a nonce regression), that cluster promotes to FULL on the spot.

---

## 2. Cluster breakdown (one cluster per surface — the natural review boundary)

Each cluster is ~3–4 tasks (1f sizing). Each ends at a `── REVIEW GATE ──` with its tier. **The per-surface boundary IS the cluster boundary** — a reviewer holds one surface's diff, never a flat multi-surface phase.

| # | Cluster | Surface(s) | Wireframe source | Tier |
|---|---|---|---|---|
| **A** | Shell + asset strategy + fixture fix | `ws-shell`/`ws-rail`/`ws-topbar` chrome; asset pipeline; the stale Playwright login fixture | `workspace.css`, the shared `<aside class="ws-rail">`/`<header class="ws-topbar">` in every page | STANDARD (+ dead-code sweep) |
| **B** | Vandaag | launcher → workbench home | `vandaag.html` | STANDARD |
| **C** | Inschrijvingen (grid) | the registration grid | `inschrijvingen.html` + `grid.css` + `grid.js` | STANDARD |
| **D** | Dossier | one-person full join | `dossier.html` | STANDARD |
| **E** | Trajecten | trajectory list + detail | `trajecten.html` | STANDARD |
| **F** | Edities / Offertes / Sessies / Gebruikers | functional pages with NO wireframe | none — restyle existing markup into `ws-*` | STANDARD |
| **G** | Cohort-lens slideover (re-skin only) | Phase-2a cohort lens | none (existing, tested) | STANDARD |

**Ordering rationale:** A is first because the shell + the Playwright fixture fix are prerequisites for every other cluster's browser gate. B–E follow the wireframe pages. F covers the surfaces the wireframe never drew (keep functional, restyle). G is last and is a re-skin, not a rebuild — its logic is preserved (`memory/project_admin_workspace_1a_done.md` / Phase 2a).

---

## 3. Shell + asset strategy (the cluster-A decision, decided here)

**DECISION: replace wholesale.** Delete `admin-dashboard.js` (the 2,275-line god-component), `templates/admin/dashboard.php`, and the franken-CSS (`admin-dashboard.css` `sd-*` rules + the `--ws-→--sd-` bridge aliases). Adopt the wireframe's structure in its place.

### What is REPLACED
- **Markup:** `renderDashboard()` emits a single `<div class="ws-shell">` host. The shared chrome (`ws-rail` nav + `ws-topbar`) is one template; each surface's `<main>` content is its own template partial (mirrors the per-page `<main>` in each `*.html`).
- **CSS:** ship `workspace.css` (~405 lines) + `grid.css` (~806 lines) as the ONLY admin design system (~1,200 lines vs the current 3,917-line franken-CSS). Copied verbatim from the wireframe, then path/scope-adjusted for the WP admin context (the wireframe assumes `file://` with no `#wpwrap`/`#adminmenu` siblings — see §3 risk).
- **JS:** one small Alpine component **per surface** (`vandaag()`, `grid()`, `dossier()`, `trajecten()`, `editions()`, …), each owning its own state + data-loader. NOT one god-component. The wireframes already prove this architecture — each `*.html` has its factory inline; we lift each factory into its own file under `assets/js/admin/<surface>.js`.

### What is PRESERVED (keep the plumbing, swap the body)
- The WP `add_menu_page`/`add_submenu_page` registration in `AdminDashboardService::registerAdminPage()` (capability `stride_view`, slug `stride-dashboard`).
- The `injectStyles()` / `injectScripts()` / `enqueueAssets()` injection pattern and the `isStridePage()` gate.
- The `StrideConfig` localize block (`apiUrl`, `adminUrl`, `nonce` = `wp_create_nonce('wp_rest')`, `exportNonce`, `user`). **The `X-WP-Nonce` header + `ntdstAPI`/`StrideConfig.nonce` plumbing is the contract every surface's `api()` call uses — preserve it verbatim.** The Phase-1 `api()` helper reads `.message` from the `WP_Error` JSON shape (`{code,message,data:{status}}`) — the new per-surface loaders reuse exactly that helper.
- Alpine + Flatpickr enqueues (already on CDN with SRI/crossorigin).

### Fonts decision
The wireframe look depends on **Space Grotesk / Inter Tight / JetBrains Mono** (Google Fonts `<link>` in every mockup). **DECISION: self-host** via the theme/plugin assets (woff2) and enqueue on the dashboard page only — do NOT add a render-blocking Google Fonts `<link>` in `wp-admin` (privacy + the admin is behind auth, so external font CDN calls are an avoidable third-party dependency and a FOUC source). Falls back to the system stack if a face fails. (This is a presentation choice, not a security one — no threat model implication.)

### The single hardest shell risk
The wireframe CSS was authored for a **standalone `file://` document** — it owns `<body>` and assumes no WordPress admin chrome. In `wp-admin` the `ws-shell` lives INSIDE `#wpcontent` next to `#adminmenu` + the admin bar. **Mitigation (cluster-A task):** the existing admin already hides WP chrome for the dashboard page (`addBodyClasses` + `injectStyles` CSS that collapses `#adminmenuwrap`/`#wpadminbar`). Re-point that same chrome-hiding CSS at the new `ws-shell` host and verify the `ws-rail` is the only visible nav (the abandoned attempt left the old WP Dashboard visible — this is exactly that bug). The cold-landing screenshot gate catches a regression here.

### Cluster A tasks
- **A.1** Asset scaffold: copy `workspace.css` + `grid.css` into `stride-core/assets/css/admin/`; self-host the 3 font families; wire `injectStyles`/`enqueueAssets` to load ONLY the new system; delete the `sd-*` + bridge CSS. `[Tier B: presentational/config — verified by the cold-landing screenshot gate, not jsdom]`
- **A.2** Shell template: `renderDashboard()` → `ws-shell` host + `ws-rail` + `ws-topbar` partials; re-point the WP-chrome-hiding CSS at the new host; delete `dashboard.php` + `admin-dashboard.js` god-component. `[Tier B]`
- **A.3** **Fix the stale Playwright admin login fixture** (`tests/frontend/admin/fixtures/admin-helpers.ts`). It is fully stale and BLOCKS every browser gate: it uses `md5('stride_test_${id}_${secret}')` + hardcoded `SEED_ADMIN_USER_ID = 3191` + a hardcoded secret. The live `test-login-helper.php` now signs `hash_hmac('sha256', 'login:' . $userId, STRIDE_TEST_LOGIN_SECRET)` with the secret from `.env` and **no hardcoded fallback**, and the seeded admin id has moved (→ ~13740). Fix: resolve the seed-admin user id at runtime (e.g. `wp eval` or a fixture lookup by `seed_admin@seed.test`), read `STRIDE_TEST_LOGIN_SECRET` from the env, and compute `hash_hmac('sha256','login:'+id, secret)`. `[Tier A: this is auth-fixture logic — a RED test that asserts the computed key matches the helper's expected key for a known id+secret, and that a wrong key is REJECTED (denial path). Without this the whole browser gate is fictional — same failure class as the abandoned attempt.]`

`── REVIEW GATE ── (tier: STANDARD — shell/asset rewrite + fixture; no 1a surface. Dead-code sweep MANDATORY: zero remaining `sd-*` class refs, zero orphaned CSS rules, god-component file deleted. Escalate to FULL if the fixture fix touches the auth boundary in a way a finder flags.)`

---

## 4. Per-surface data-mapping (Stage 1c ground-truth — DONE, against the 6 real services)

The mock `data.js` shapes (`WS.*`) MUST be MAPPED to the REAL endpoint responses. This mapping is the core wiring work. Ground-truthed below; each surface's risk is stated.

### Shape-class divergence (applies to grid + dossier): **flat scalars → nested objects**
`data.js` rows are flat (`name`, `email`, `edition:'e1'`, `status:'pending'`, `company:'c1'`, `offerte:'draft'`). The real grid item (`AdminRegistrationQueryService::getFlatPage`) is **nested**:
```
{ id, user:{id,name,email}, edition:{id,title}, status:{value,label},
  offerteStatus:'Geen offerte'|<label>, attendancePct, company:{id,name},
  trajectory:{id,title} }
```
→ The grid/dossier factories must consume the nested real shape directly (NOT adapt real→mock). **Rule: the mockup's `data.js` is a layout fixture, not the contract — delete it and bind the markup's `x-text`/`x-for` to the REAL response keys.** Do NOT build a mock-shape adapter; that re-introduces a translation layer that drifts.

### Surface-by-surface mapping table

| Surface | Mock shape (`data.js`) | Real endpoint + shape | Mapping verdict |
|---|---|---|---|
| **Vandaag — stats strip** | `WS.STATS` = 4 cards `{label,num,delta,kind,icon}` | `GET /admin/stats` → `{upcomingEditions, totalRegistrations, pendingQuotes, todaySessions, openTrajectories, …}` | CLEAN. Map 4 cards to 4 keys. `delta`/`kind` (the "+2 deze maand" microcopy) have **no backend source** → render static/derive from `registrationsThisWeek` vs `…LastWeek`, or drop the delta line. MINOR. |
| **Vandaag — 5 queues** | `WS.QUEUES` = 5 `{key,count,…}` | `GET /admin/stats` → `worklistQueues:{pending, waitlist_open, offerte_opvolging, nocert, oldinterest}` | CLEAN. Phase-1 built `worklistQueues` precisely for this. Map key-by-key (note `waitlist`→`waitlist_open`, `offerte`→`offerte_opvolging`, `oldinterest`→`oldinterest`). |
| **Vandaag — Acties-nodig panel** | `WS.ACTION_QUEUE` = `{mij:[…], gebruiker:[…], meldingen:[…]}`, **per-PERSON rows** `{name, meta, age, regId}` | **mij/gebruiker:** `GET /admin/pending-approvals?stale_days=7&per_page=100` → `{items:[{id,type:'approval'|'post_approval'|'stale_user',user_id,user_name,user_email,edition_id,edition_title,registered_at,open_task_label,days_idle}], counts:{approval,post_approval,stale_user}}`. **meldingen:** `GET /admin/action-queue` (aggregate alerts). | **CLEAN — REUSE THE CURRENT DASHBOARD'S WIRING (§8 CORRECTED).** The current `admin-dashboard.js` already renders this exact per-person bucketed panel: `mij` ← `pending-approvals` items filtered `type∈{approval,post_approval}`; `gebruiker` ← `type=stale_user` (+`open_task_label`/`days_idle`); `meldingen` ← `action-queue`. The plan originally mis-cited `action-queue` as the only source — WRONG. Lift the existing endpoint+filter into `vandaag()`. NO new mapping. `pending-approvals` is a Phase-1 deferred-to-service follow-up — consume on the god-class as-is, do NOT drain it here. |
| **Inschrijvingen (grid)** | `WS.REGISTRATIONS` flat rows | `GET /admin/registrations` → nested items (above) + `{total,page,perPage,totalPages,statusCounts}` envelope | Shape-class divergence (flat→nested) but **semantically complete** — every column the grid needs exists. Bind to nested keys. The funnel/stepper uses the real `statusCounts` map. MINOR (mechanical rebind). |
| **Dossier** | `WS.DOSSIER` = `{person, registrations:[{stages,attendance,selections,completion,timeline,quote,…}], trajectories:[…]}` | `GET /admin/users/{id}/detail` → `{registrations:[{id,edition_id,edition_title,status,enrollment_path,stages,attendance:{present,absent,excused,total_sessions,hours},selections,notes,offerte_status,…}], …}` + `GET /admin/users/{id}/trajectories` for the trajectory section | MOSTLY CLEAN — `stages`, `attendance`, `selections` (server-owned via `resolveSelectionLabels`, INV-6b), `offerte_status` all present. **Gaps:** the mock's `timeline:[…]` per-write event log and the `completion:[…]` checklist have **no direct single source** in `getUserDetail` (timeline would need the audit feed; completion is derived). See §8 secondary risk. The trajectory section is a SEPARATE endpoint (`/users/{id}/trajectories`) — the dossier factory loads BOTH. |
| **Trajecten** | `WS.TRAJECTORIES` `{required, electiveGroups:[{name,required:int,courses}], users:[…]}`, `WS.TRAJ_OPTIONS` | `GET /admin/trajectories` (list) + `/{id}` (detail) → `{title,status,mode,capacity, required_courses/required, elective_groups:[{name,required,…}], users}` + `/options` | CLEAN. The Phase-1 trajectory service already returns `required` / `elective_groups` with a `required:int` per group and a `users` roster — the wireframe shape mirrors it (the mock comments even cite `getProgressData()`). MINOR. |

---

## 5. Per-surface data-load ownership (the structural fix for the half-empty Vandaag)

**The abandoned attempt's root failure: `loadVandaag()` populated `worklistCounts` but not stats/pendingApprovals, so the landing view rendered empty skeletons.** A god-component hides this. The fix is structural, not a bug-fix.

**Rule: each per-surface Alpine factory OWNS loading ALL of its own data in an `init()` that fires on component mount.** "Landed on surface X but its data is empty" must be structurally impossible — there is no shared loader another surface could leave half-run.

| Surface | `init()` loads (cold-landing must be fully populated) | Empty / loading / error state (LOAD-BEARING) |
|---|---|---|
| Vandaag | `GET /admin/stats` (→ stat strip + 5 queues, ONE call) **and** `GET /admin/action-queue` (→ Acties-nodig panel) — both awaited before the surface reads "ready" | loading skeletons per panel; per-queue empty ("Niets te doen"); per-action-bucket empty ("Niets in deze wachtrij"); error toast on either call failing |
| Inschrijvingen | `GET /admin/registrations?page=1` (+ any `?queue=` preset from the Vandaag deep-link) | empty grid state; loading rows; error banner; the `?queue=` deep-link must pre-filter on cold-load |
| Dossier | `GET /admin/users/{id}/detail` **and** `GET /admin/users/{id}/trajectories` | "no registrations" empty; per-stage hidden-when-empty (mock Fix 5); loading; 404/error |
| Trajecten | `GET /admin/trajectories` (list) → on row click `GET /admin/trajectories/{id}` | empty list ("geen actieve trajecten"); empty roster (the `t3` 0-enrollment edge); loading; error |
| Edities/Offertes | the existing edition/quote list endpoints | empty/loading/error per list |
| Cohort slideover | existing `GET /admin/editions/{id}/roster` (unchanged) | preserved from Phase 2a |

Each surface's empty/loading/error states are the feature-acceptance edge classes (§7) — building them IS making those flows pass.

---

## 6. Architecture-invariants citations (1b) + WP-security pillars (stack)

**Cited invariants (consume, don't bypass):**
- **INV-5 — no `x-html` on dynamic data.** The ported markup uses `x-html="WS.icon(name)"` (constant icon-name → inline SVG) and, in dossier/grid, potentially `x-html` for status badges. **Per-task audit item (every cluster):** confirm every `x-html` in the cluster's markup binds a CONSTANT/whitelisted value (an icon name from the fixed `ICONS` map, a status label from the closed enum), NEVER a free-text field (`name`, `email`, `notes`, `meta`, enrollment-stage `data` values, quote refs). Any `x-html` on a data field is an INV-5 violation → rewrite as `x-text` → escalate the cluster to FULL (1h one-way). Sibling-site audit: this predicate is checked in EVERY cluster's markup, not just once.
- **INV-6b — server-owned selections.** Dossier `selections` + cohort selections come pre-resolved from `resolveSelectionLabels` / `getSelections` (server). The client renders labels; it NEVER parses the raw `selections` column. Preserve.
- **INV-7 — effective status.** The grid/agenda/detail/typeahead already emit EFFECTIVE status (Phase-1 C1). The frontend renders `status.label`/`status.value` AS RECEIVED — it must NOT re-derive "is this past/terminal" client-side. Preserve.

**WP-security pillars (lightweight — no new handlers):**
- **Escaping (the one active pillar):** new templates echo server data. PHP-rendered shell chrome must `esc_html`/`esc_attr`/`esc_url` any server value it prints; Alpine `x-text` auto-escapes (use it over `x-html` per INV-5).
- **Nonce:** preserve `StrideConfig.nonce` (`wp_rest`) + the `X-WP-Nonce` header on every `api()` call. No new nonce surface.
- **Capability + Sanitization:** unchanged — owned by the untouched backend (`stride_view`/`stride_manage` gates already enforced server-side).

---

## 7. `## Acceptance flows` matrix (gate 1g — the headline)

Driven at shake-out via **real browser** (Playwright spec → else `superpowers-chrome use_browser`) against `https://stride.ddev.site`, authenticated via the **fixed** cluster-A login fixture. **Every row's PASS criterion includes the cold-landing screenshot-vs-wireframe comparison** — fresh load, no prior nav, screenshot the populated surface, compare HOLISTICALLY to the matching `docs/mockups/admin-workspace/*.html`. A green per-row assertion with a broken-looking page is a FAIL (the abandoned-attempt anti-regression).

| # | Flow (intended use) | Cold-landing PASS criterion (screenshot-vs-wireframe) | Interactive behaviors | Edges (mandatory) |
|---|---|---|---|---|
| AF-1 | Land on Vandaag | Fresh load renders stat strip (4 populated cards) + 5 queues (real counts) + Acties-nodig panel — holistically matches `vandaag.html`. NO empty skeletons, NO leftover WP Dashboard. | refresh pulse; queue click → grid deep-link; action-item click → dossier | **empty:** all queues 0 → "Niets te doen" rendered, not blank · **denied:** `stride_view` (read-only) sees no manage-only actions · **error:** `/stats` 500 → error state not white screen · **boundary:** a queue with 1 vs 999 count · **concurrent:** double refresh-click · **mid-flow:** `/action-queue` fails but `/stats` ok → partial render, panel shows error |
| AF-2 | Land on Inschrijvingen | Fresh load renders the grid populated (page 1) + funnel chips with real `statusCounts` — matches `inschrijvingen.html` + `grid.css`. | multi-select; status-aware bulk bar; group-by; server paginate; `?queue=` preset | **empty:** zero registrations → empty grid state · **denied:** view-only role bulk bar hidden · **wrong-order:** group-by then filter · **concurrent:** select rows then paginate · **boundary:** page N of N; 0 vs 1000 rows · **mid-flow:** bulk action partial failure |
| AF-3 | Open a Dossier | Fresh load of `/users/{id}/detail` renders person header + registration cards (stages, attendance, selections) + trajectory section — matches `dossier.html`. | expand/collapse stage panels; switch registration; open trajectory progress | **empty:** user with 0 registrations · **denied:** read-only role gets safe subset (no phone/audit — Phase-1 N1) · **wrong-order:** open dossier of pending reg (hidden-when-empty stages) · **concurrent:** n/a (read) · **boundary:** 1 vs 20+ registrations (reg_page paginate) · **mid-flow:** `/trajectories` fails, detail ok → section error, not blank |
| AF-4 | Land on Trajecten | Fresh load renders active-trajectory list — matches `trajecten.html`; click → detail with required/elective/roster. | row click → detail; scope pill (active/all); jump-to-grid | **empty:** 0 active trajectories → empty state · **denied:** role gate · **wrong-order:** detail of a 0-enrollment trajectory (the `t3` edge) → empty roster, not crash · **concurrent:** n/a · **boundary:** 1 required + 0 electives vs many · **mid-flow:** detail 404 (Phase-1 F1 fix preserved) |
| AF-5 | Edities/Offertes/Sessies/Gebruikers | Each functional page cold-loads populated in `ws-*` styling (no wireframe to match → match the `ws-*` design system look, not a specific mockup). | existing list/filter/CRUD behaviors preserved | **empty/denied/error** per list; **boundary** large list paginate; **mid-flow** save failure |
| AF-6 | Cohort-lens slideover | Open from a grid/edition row → roster + attendance + extras (Phase-2a, preserved). Cold-landing = the slideover renders its data on open. | bulk roster actions; attendance filter | edges preserved from Phase-2a (re-skin only — logic unchanged) |

---

## 8. Acties-nodig panel — RESOLVED: reuse the current dashboard's wiring (was mis-flagged as the biggest risk)

**CORRECTION (2026-06-24, ground-truthed against the CURRENT `admin-dashboard.js` + `dashboard.php`):** the original plan called this "THE BIGGEST DIVERGENCE" because it cited `/admin/action-queue` (a flat aggregate list) as the only source and concluded the per-person bucketed mockup couldn't be fed cleanly. **That was wrong.** The current dashboard ALREADY renders this exact panel — same three buckets, per-person rows, deep-links — using a DIFFERENT endpoint the plan overlooked. There is no divergence. The rebuild LIFTS the existing wiring.

- **Mock expects:** three named sub-queues `{mij, gebruiker, meldingen}`, each a list of **per-person** rows `{name, meta, age, regId}` deep-linking to the dossier.
- **What the current dashboard does (the contract to reuse):**
  - **`mij`** ← `GET /admin/pending-approvals?stale_days=7&per_page=100` → `items` filtered `type ∈ {approval, post_approval}`. Each item is ALREADY per-person: `{user_id, user_name, user_email, edition_id, edition_title, registered_at, type}`. Maps directly to the mockup's `{name, meta, age, regId}` (`name`=`user_name`, `meta`=`edition_title` + status, `regId`=`id`).
  - **`gebruiker`** ← same `pending-approvals` response filtered `type = stale_user`, with `open_task_label` + `days_idle` for the "wacht op gebruiker" microcopy.
  - **`meldingen`** ← `GET /admin/action-queue` (the aggregate rule alerts) — this is the ONLY bucket that is aggregate, and it matches how the current dashboard does meldingen too.
- The `pending-approvals` default-active-tab logic (approval+post_approval > stale_user > notifications) is in the current JS — preserve it.

**Resolution: REUSE.** Lift the current `loadDashboard()` Acties-nodig wiring (the two endpoints + the `type` filter + the tab-default logic) into the new `vandaag()` factory. NO new mapping, NO `/admin/registrations` re-derivation (the original "option 2" is unnecessary — `pending-approvals` already returns the per-person bucketed shape). **`pending-approvals` is a Phase-1 deferred-to-service follow-up: consume it on the god-class as-is; do NOT drain it to a service in Phase 2 (backend untouched).** The cold-landing screenshot gate for Vandaag must still show a POPULATED panel — the exact panel the abandoned attempt left empty.

**Secondary (Dossier) — RESOLVED: wire fully, client-side, no backend change (ground-truthed 2026-06-24).** User decision: wire both blocks, follow the mockup.
- **`completion` checklist** — DERIVE from fields `GET /admin/users/{id}/detail` already returns: `stages[intake].submitted_at`, `stages[evaluation].submitted_at`, `attendance` (present/total → ≥80%), registration `status` (approved). No backend change. Tier A on the derivation helper (it branches: include/exclude per condition, empty-input branch).
- **`timeline` per-registration** — the dossier endpoint ALREADY returns the user's last-50 audit events (`AdminUserService` lines ~403-457), shaped `{id,type,text,target_url,actor_name,timestamp}` covering the mockup's event types (`registration.created`, `attendance.marked_*`, `quote.sent`, `completion.certificate_issued`, …). The dossier factory FILTERS that already-loaded per-user feed by registration context client-side → per-registration timeline, NO new endpoint, NO backend change. INV-compliant (rendering server-emitted events, not parsing raw data). Tier A on the per-registration filter helper. **Caveat surfaced for cluster-D:** an audit event whose context doesn't name a registration id can't be attributed → it falls through to the registration's own lifecycle stamps (`registered_at`/`completed_at`/`cancelled_at` + stage `submitted_at`s). If at build time the audit context proves too sparse to populate the timeline meaningfully, STOP and bring it back (do not silently fake or hide — user chose "wire fully").

---

## 9. Spec-close gate (§ Stage 3) — the holistic version of the cluster gate

After all clusters: re-run unit + integration (baseline unaffected — pure presentation), then **the headline spec-close gate**:

1. **Cold-landing screenshot-vs-wireframe sweep across ALL 5 surfaces**, side-by-side with `docs/mockups/admin-workspace/*.html`, each as a FRESH browser load (clear session, re-login, navigate ONCE to the surface, screenshot before any interaction). The bar is Stefan's: "is this the same FINISHED page?" — not "does a selector exist."
2. **Dead-code sweep:** zero `sd-*` class references anywhere; zero orphaned CSS rules in the new system; the god-component file gone; no unreferenced JS method.
3. **feature-acceptance** drives the §7 matrix (all edges).
4. **Review tier: STANDARD** branch panel (`reviewer` + `invariant-auditor`) — escalate to FULL if any `x-html`-on-data (INV-5) or nonce regression surfaced. No `/security-review` (no plan-time threat model).
5. **finish-branch.**

---

## 10. Per-task test-tier summary (testing-workflow owns the rule)

This work is **overwhelmingly Tier B** (presentational/glue/config) — real verification is the browser screenshot-vs-wireframe gate, NOT jsdom. The tier line per task:
- **Tier B (default for every surface-wiring task):** `no unit test: Tier B, presentational — verified by cold-landing screenshot-vs-wireframe (§7) + the per-surface acceptance edges`.
- **Tier A (the exceptions):**
  - **A.3** the Playwright login-fixture HMAC computation (RED test: correct key matches the helper, wrong key rejected — the denial path).
  - **D (dossier) completion-derivation helper** — branches on `stages`/`attendance`/`status` → RED test incl. the empty/zero-attendance branch.
  - **D (dossier) per-registration timeline filter** — filters the per-user audit feed by registration context → RED test incl. the no-matching-context branch (falls through to lifecycle stamps).
  - Acties-nodig needs NO mapping helper (reuses the existing endpoint shape directly) → Tier B, not A.

Every cluster's `── REVIEW GATE ──` carries an Integration-gate line: "the surface cold-loads populated against the real (seeded) backend in a browser."

---

## 11. Out of scope / preserved

- **Backend untouched.** No endpoint, service, repository, CPT, capability, or nonce surface changes. If a surface genuinely cannot be built without a backend change, STOP and re-plan — do not edit the backend inside a Phase-2 cluster.
- **Phase 2a cohort-lens logic preserved** — re-skin into `ws-*` only (cluster G).
- **INV-6b / INV-7 / Phase-1 F1+N1 fixes preserved.**
- **The Phase-1 deferred follow-ups** (e.g. `AdminQuoteService` zero-search `{data}` vs `{items}` envelope normalization) are noted in `memory/project_admin_backend_cleanup_done.md` — if the offertes surface (cluster F) hits the inconsistent envelope, normalize CLIENT-side (tolerate both shapes) rather than touching the backend.
