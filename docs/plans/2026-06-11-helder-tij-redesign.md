# Helder Tij Redesign — Stridence Theme Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Every task close follows `netdust-agent:testing-workflow` (tier named, suite green, deferral line recorded).

**Goal:** Adapt the stridence theme to 100% match the "Helder Tij" base design (`docs/stride-base-design/`) — tokens + fonts swap, templates rebuilt from the component sheet, Alpine behavior updates — with zero stride-core (mu-plugin) changes and zero behavior regressions.

**Architecture:** Foundations land first (tokens v2, Tailwind mappings, fonts, component recipes), then shared partials, then screens consume them. All markup specs come from the `.dc.html` mockups + `Componenten.dc.html`; all values flow from the v2 `tokens.css`. Server-render first; Alpine for UI state only. The client-override chain (NTDST_Template_Loader::addPath, `stridence_font_url`, block patterns, `?tab=` URLs) must keep working — stride-client-vad is verified at the end.

**Tech Stack:** Tailwind CSS 3.4 + Alpine.js 3 + Vite 5 (theme), PHPUnit (ddev) for the PHP suite, Vitest (added in Phase 0) for Alpine factory units.

**Branch:** `feature/helder-tij-redesign` off `staging`. The working tree has unrelated modified `web/app/languages/*` files — NEVER stage or commit them.

**Plan-scope sources of truth (read these, not memory):**
- `docs/stride-base-design/tokens.css` — v2 token file
- `docs/stride-base-design/Componenten.dc.html` — component sheet (single source for every component)
- 10 screen mockups in `docs/stride-base-design/` (each task names its mockup)
- IGNORE: `Richtingen - Editie detail.dc.html`, `Helder Tij - Kleurpaletten.dc.html`, `Korenbloem - Oppervlakken.dc.html`, `Index.dc.html`, `support.js` (exploration history)

---

## Classification & gate determinations (harnessed-development Stage 1)

**Work class: A** — new multi-task feature work; intent concrete, Stage 0 skipped.

| Gate | Fired? | One-line reason |
|---|---|---|
| `netdust-wp:wp-plan-requirements` | **YES** | WP plan stage — golden path, security pillars, layering blocks below. |
| 1a `threat-modeling` | **NO** | Trigger list walked literally below — no new/altered input surface. Escalation rule stated. |
| 1b `architecture-invariants` | **YES (cite)** | `ARCHITECTURE-INVARIANTS.md` exists at project root; INV-3/5/6/7 touched — cited below. |
| 1g `feature-acceptance` | **YES** | User-facing screens redesigned — `## Acceptance flows` matrix embedded below. |
| 1d `testing-workflow` | **YES** | Every task carries a tier line; phase integration gates defined. |
| `designing-apis` | NO | No API/boundary designed — templates consume existing services/endpoints unchanged. |
| `doubting-decisions` | NO | No new architectural decision — the design and scope decisions are pre-made by Stefan and locked. |

---

## Golden path: none (no matching archetype — frontend reskin)

- [x] No new Service/Repository/Handler/CPT/AJAX/REST is built. Rendering stays in theme templates fed by **existing** helpers (`stridence_template_part`, `stridence_catalog_render_cards`, `LearnDashHelper`, `stride_format_money`, `stride_format_date`).
- [x] Deviations from any slice: **none** — there is no spine to deviate from; the hard rule is the inverse: **any task that tempts a stride-core change is out of scope** — stub with a placeholder in the template and record the field in the field-inventory doc (deliverable, Task 10.4).

## WP security requirements (per data-flow)

No NEW data flows are introduced. Existing flows are *consumed unchanged*; template work must preserve the four pillars (`netdust-wp:wp-security` is the canonical source) at the output sink:

- [ ] **All new/edited template output**: escape at the sink — `esc_html` (text), `esc_attr` (attributes incl. JSON for `x-data`), `esc_url` (links). `wp_json_encode` output into attributes goes through `esc_attr`. Pillars validate/sanitize/authorize: n/a — no input is read that isn't already read today.
- [ ] **All new/edited UI strings**: Dutch (nl_BE), translatable via `__()`/`esc_html_e()` with the `'stridence'` text domain. Code/comments English.
- [ ] **Catalog paging** (`stride_catalog_page` via `ntdstAPI`): endpoint, nonce handling, and Alpine rollback logic are reused byte-for-byte; only the server-rendered card markup it returns changes (Task 3.1). No endpoint edits.
- [ ] **Dashboard auth gate** (`page-mijn-account.php:17-20` login redirect) and the `$valid_tabs` allow-list (`:25-30`): preserved verbatim in the rebuilt shell (Task 7.1).
- [ ] **Profile save / quote remind / keuze confirm / mark-all-read**: existing `ntdstAPI` actions reused unchanged; only surrounding markup restyled.
- [ ] **Contact form**: rendered via `the_content()` (existing FluentForms/page content) inside the new layout — **no new form processor** (Task 9.2).

## ntdst-core layering requirements

Rows that apply to this feature (canonical definitions: `netdust-wp:ntdst-architecture` / drift-reviewer):

- [ ] Theme templates never call `ntdst_data()` or `$wpdb` — data comes through existing services/helpers already used by the templates being restyled (INV-3).
- [ ] stride-core is NOT edited at all (no Services, no Handlers, no `stride-core/templates/`, no `Support/`). The one allowed non-theme file is the plan's own docs deliverable.
- [ ] No `ob_start()+include` rendering added — nested templates via `stridence_template_part()` / `stridence_template_html()` as today.
- [ ] No swallowed `WP_Error` — any `is_wp_error` branch in touched templates keeps its current handling.
- [ ] Catalog cards render ONLY through `stridence_catalog_render_cards()` + the prefetch pre-pass (INV-7 convergence; see invariants section).

> **Convergence contract:** these blocks + the invariants below are the convergence target for `/code-review` and the `ntdst-drift-reviewer` at shake-out. Reviewers verify the diff against this named list, not free-form — a gap is a one-line finding keyed to a named item.

**Per-task acceptance line (every template-touching task):**
`Acceptance: output escaped at sink; strings i18n'd ('stridence'); no stride-core file touched; no new data flow; drift pre-check clean on the touched path.`

---

## Threat model determination (gate 1a — did not fire)

Trigger list (project CLAUDE.md table) walked literally against this plan:

| Surface | Touched? |
|---|---|
| User-controlled URLs | No — no new outbound/redirect/embed URL handling. |
| AJAX handlers (`wp_ajax_*` / `ntdst/api_data/*`) | No new handler; existing actions consumed unchanged. |
| REST endpoints | None added/edited. |
| Shortcodes | None added; existing shortcode templates restyled only. |
| Settings pages | None. |
| Untrusted parsing | None — no upload/CSV/feed/JSON-from-network parsing added. |
| Capability boundaries | No new `current_user_can` surface; existing login gate preserved verbatim. |
| Multi-tenancy / role isolation | Untouched. |
| File handling | No new upload/download path; existing download actions reused. |
| `$wpdb` with user input | None — theme has no `$wpdb`. |
| BYOK / credentials | None. |
| Partner API | Untouched. |

**Determination: 1a does not fire.** **Escalation rule (binding):** if during execution any task turns out to add or alter an input surface (a new form post target, a new `ntdst/api_data/*` action, new query-param-driven server logic beyond the existing `?tab=`/`?thema=` reads), STOP that task, run `netdust-agent:threat-modeling` for it, and embed the result here before continuing. The cluster containing it is promoted to review tier FULL (one-way escalation).

---

## Architecture invariants touched (gate 1b — cite, doc at `/home/ntdst/Sites/stride/ARCHITECTURE-INVARIANTS.md`)

- **INV-3 (data through repositories; field names live in each CPT's `getFields()`).** The design needs content fields that do NOT exist in `EditionCPT::getFields()` (verified: it has `course_id, start_date, end_date, capacity, price, price_non_member, venue, status, speakers, selection_deadline, session_slots, completion_mode`). This plan does **not** add CPT fields — new content blocks are stubbed with placeholders and recorded in the field-inventory doc. `speakers` (text) is noted there as a partial source for "Lesgever".
- **INV-5 (rendering via template loader; plugin never calls theme; escape at sink).** All work is theme-side, so the dependency arrow is safe by construction — but the inverse must hold too: do NOT move theme helpers into stride-core or call core-private templates. The escaping sub-invariant is the per-task acceptance line above. Client overrides via `NTDST_Template_Loader::addPath()` priority must keep winning — verified in Task 10.4.
- **INV-6 (LearnDash via `LMSAdapterInterface`/`LearnDashHelper` only).** Templates keep reading through `LearnDashHelper`; LD lesson/quiz markup is styled via `src/css/learndash.css` overrides ONLY, never re-implemented (the `Detail - Online opleiding` mockup states this explicitly at its line 102).
- **INV-7 (display status via `getEffectiveStatus()`; catalog-card convergence).** Catalog cards render only through `stridence_catalog_render_cards()` / `stridence_prefetch_*_cards()` (`helpers/catalog.php`); the card partials stay **pure renderers** fed prefetched status/enrolled/spots. The restyle must not add per-card queries or raw stored-status reads. The accepted stored-status fallback in `partials/card-edition.php` stays as-is.

---

## Stage 1c — Premise ground-truth findings (verified against source this session)

| # | Premise | Verdict |
|---|---|---|
| 1 | v2 `tokens.css` is a drop-in for v1 | **Verified, with delta.** Every v1 variable exists in v2 under the same name (incl. all 6 badge pairs, shadows, aliases, layout, radius). v2 ADDS five variables v1 lacks: `--color-accent-hover`, `--color-accent-subtle`, `--color-border-soft`, `--color-text-faint`, `--color-focus`. → `tailwind.config.js` must be EXTENDED (Task 1.2) or new design recipes can't be expressed. |
| 2 | `nav-mobile.php` only included from `page-mijn-account.php` | **Verified** (grep: single include site, `page-mijn-account.php:135`). Safe to replace bottom bar with top bar + chips. |
| 3 | Dashboard tabs render via `stridence_template_html` | **DRIFT.** They render via `stridence_template_part("templates/dashboard/tab-{$current_tab}", …)` (`page-mijn-account.php:114-127`). Tasks reference the real mechanism. |
| 4 | Shared partials live in `templates/partials/` | **DRIFT.** They live at theme root `partials/` (`badge-status.php`, `breadcrumb.php`, `card-course.php`, `card-edition.php`, `card-trajectory.php`, `empty-state.php`, `error-state.php`, `progress-bar.php`, `session-row.php`) — matches Tailwind `content: './partials/**/*.php'`. All task paths corrected. |
| 5 | Footer matches design's 2-group footer | **Verified as a restructure.** Current: 4-column grid on `bg-surface-alt`. Design (Homepage mockup :148-169): white bg, top hairline, brand+contact left, exactly two link groups (Aanbod / Stride). |
| 6 | Fonts swap site is `header.php` only | **DRIFT (sibling found).** The Google Fonts link exists in BOTH `header.php:18` AND `header-dashboard.php` (~:20), each behind `stridence_font_url`. Task 1.3 updates both. |
| 7 | Catalog filters/toon-meer are new behavior | **DRIFT (good news).** `page-klassikaal.php` already implements theme chips (`stride_theme` taxonomy) + counts + "Toon meer" paging via the `stride_catalog_page` endpoint with rollback. Design interaction model matches 1:1 → catalogs are restyles, not rebuilds. |
| 8 | `learndash.css` carries hardcoded v1 hex | **Mostly clean.** Only 2 hex literals, both `var(--ld-focus-header-color, #333)` fallbacks (lines 397, 446). Task 10.3 is a token re-check, not a rewrite. |
| 9 | Theme has a JS unit runner for Tier-A Alpine tests | **NO.** `package.json` has no Vitest → Phase 0 adds a minimal Vitest setup (testing-workflow: no framework → set it up first). |
| 10 | `templates/emails/` exists in the theme | **NO such dir** (CLAUDE.md tree is stale). Email templates are not theme-owned → restyling them would touch stride-core → **out of scope**; recorded as follow-up in the field-inventory doc (Task 10.4). |
| 11 | Dashboard app-shell needs a new header template | **Already exists.** `header-dashboard.php` is a minimal head-only header ("The sidebar IS the navigation") but `page-mijn-account.php:83` calls plain `get_header()`. Task 7.1 switches it. |

---

## Decisions locked (do not re-open during execution)

1. **Base theme only** — Helder Tij becomes stock; `stride-client-*` overrides verified at Task 10.4.
2. **Surfaces not in the design** (enrollment/interest/intake/evaluation/waitlist/completion forms, FAQ, agenda, login, 404) keep current layouts, restyled with new tokens + component recipes. Emails: out of scope (finding #10).
3. **Trajectory**: public detail keeps structure (restyled); enrolled view rebuilt to `Detail - Traject.dc.html` exactly.
4. **Dashboard mobile**: bottom tab bar → sticky top bar + horizontally scrollable nav chips; desktop sidebar gains 240px↔56px rail mode.
5. **Named deviations from mockups** (mockup is demo-state; production keeps real behavior):
   - **`?tab=` URL state stays** on dashboard and trajectory enrolled view (mockups use client-only state). Bookmarkability is a theme rule.
   - **Breakpoint stays Tailwind `lg:` (1024px)** for the dashboard sidebar/mobile split (mockups use 900px/820px resize listeners). One system, no magic numbers.
   - **"Acties nodig" tab labels keep current data semantics** (`Wacht op gebruiker`, not the mockup's sample copy "Wacht op werkgever") — the mockup label is sample data for an offerte item.
   - **Toast keeps the `{ message, type }` dispatch contract** (3 existing dispatch sites); rendering upgraded to the design's card. Optional `sub` added, back-compatible.
   - **Site header**: one shared header for all public pages, sticky + backdrop-blur per Homepage mockup (other mockups show it static — consistency wins; risk: none).
   - **Footer**: design shows only 6 links; the current legal links (Privacybeleid, Algemene voorwaarden) and Agenda/FAQ remain — appended to the two design groups + bottom line, styled per design.
   - **Mockup sample data** (names, dates, "An Vermeulen", counts) is NEVER hardcoded — real data or `__()`-wrapped placeholder copy only.

---

## Sibling-site audit blocks (cross-cutting concerns — each owning task must sweep ALL listed sites)

**SSA-1 — Font link (Task 1.3):** `header.php:15-22` AND `header-dashboard.php` (same filterable pattern). Both swap to the Hanken Grotesk + Newsreader URL. Grep-verify no third site: `grep -rn "fonts.googleapis" web/app/themes/stridence --include="*.php"`.

**SSA-2 — Two card families (Task 3.1):** catalog pure renderers `partials/card-edition.php`, `partials/card-course.php`, `partials/card-trajectory.php` AND dashboard-context `templates/components/course-card.php` (used by `tab-home.php`, `tab-inschrijvingen.php`, `trajectory/course-groups.php`). All four get the design card recipe; the catalog three keep the INV-7 prefetch contract.

**SSA-3 — Toast dispatch sites (Task 3.4):** `src/main.js` (inlineEdit `:369`, inlineEditSection `:427`), `templates/dashboard/tab-offertes.php`, `templates/forms/enrollment.js`. All send `{message, type}` — the new toastStore must keep accepting exactly that.

**SSA-4 — Badge variants (Task 2.4):** every status badge routes through `partials/badge-status.php` where one is rendered server-side; inline badge markup in card partials/templates must use the same 8-variant class recipe (table in Task 2.4). After the task: `grep -rn "rounded-full" web/app/themes/stridence --include="*.php" | grep -i "badge\|status"` and confirm all hits use the recipe.

**SSA-5 — Raw token consumption (Task 10.4):** `grep -rn "var(--color" web/app/themes/stridence --include="*.php" --include="*.css" --include="*.js"` — every hit must reference a variable that exists in v2 tokens.css (all v1 names survive, so hits should be clean; any hit on a non-existent name is a bug).

**SSA-6 — Detail-tabs Alpine factories (Tasks 5.1/6.1):** `courseDetailTabs` / `editionDetailTabs` / `trajectoryDetailTabs` (scroll-spy, `main.js:120-122`). Edition detail and trajectory-enrolled move to the new `contentTabs` factory (real tabs). After Phase 6: grep for remaining `x-data="editionDetailTabs"` / `trajectoryDetailTabs` usages — must be zero; `courseDetailTabs` is removed too if no template still uses it after Phase 5 (delete dead factory, don't keep it "just in case").

---

## Acceptance flows (gate 1g — verified at shake-out via real browser; seed data: `scripts/seed.php`, creds in CLAUDE.md)

Edge classes per row: **E1** empty/zero-state · **E2** denied/logged-out actor · **E3** wrong-order/re-entry · **E4** concurrent/double · **E5** boundary value · **E6** mid-flow failure. Exclusions are named, not silent.

| # | Flow (mockup) | Intended use | Edges |
|---|---|---|---|
| F1 | Homepage (`Homepage.dc.html`) | Visitor lands, sees hero/mode-cards/featured/trust/CTA, clicks through to catalogs | E1: zero upcoming editions → featured band hides or shows empty state. E2: n/a (public). E3: back/forward via bfcache → reload guard (main.js pageshow). E4: excluded — read-only page. E5: long course title in featured card wraps without overflow. E6: excluded — no async on page. |
| F2 | Catalog Klassikaal (`Catalogus - Klassikaal.dc.html`) | Filter by theme chip, Toon meer, open an edition | E1: theme with 0 items → design empty state + "Toon alles" resets. E2: n/a (public; enrolled badge only when logged in — verify both states). E3: re-click active chip = no-op (guard exists). E4: double-click Toon meer while loading → guarded by `this.loading`. E5: exactly per_page items → no Toon meer button. E6: paging endpoint failure → error state + filter rollback (existing S8 behavior preserved). |
| F3 | Catalog Online (`Catalogus - Online.dc.html`) | Same as F2 for online courses | Same six as F2. |
| F4 | Catalog Trajecten (`Catalogus - Trajecten.dc.html`) | Browse trajectory cards w/ progress-dots strip, open one | E1: no trajectories → empty state. E2: n/a public. E3: bfcache return. E4: excluded — no paging interaction if total ≤ per_page. E5: trajectory with 1 part → dots strip renders single node sanely. E6: excluded if no async paging on this archive. |
| F5 | Edition detail (`Detail - Editie.dc.html`) | Read tabs Omschrijving/Programma/Praktisch/Lesgever, sticky CTA, enroll click; mobile bottom CTA | E1: edition w/o sessions → Programma tab shows empty state, not a crash. E2: logged-out enroll click → existing enrollment flow login behavior unchanged. E3: deep-link `#praktisch` hash preselects tab; unknown hash falls back to first tab. E4: excluded — CTA navigates, no mutation here. E5: volzet edition → capacity bar full, CTA switches to existing wachtlijst path; price € 0 renders "Gratis" badge. E6: excluded — server-rendered. |
| F6 | Online course detail (`Detail - Online opleiding.dc.html`) | View LD lesson list (CSS-styled), progress ring, Ga verder | E1: not-enrolled visitor → no progress ring/CTA shows start state. E2: logged-out → start CTA leads to existing login/enroll path. E3: revisit after completing module → ring % updates server-side. E4: excluded. E5: 0% and 100% ring renders (dasharray boundary). E6: excluded — LD owns lesson nav. |
| F7 | Trajectory public detail (restyle only) | Visitor reads structure, enrolls via existing flow | E1: trajectory w/o description blocks → sections hide. E2: enroll path unchanged. E3-E4: excluded — read-only. E5: long part list renders. E6: excluded. |
| F8 | Trajectory ENROLLED view (`Detail - Traject.dc.html`) | Voortgang timeline → Keuzes select + bevestig → Materialen → Berichten | E1: no materials/messages → per-tab empty states. E2: non-enrolled user hitting enrolled view → existing access gate unchanged (verify). E3: confirm keuze, leave, return → confirmed state + "Wijzig keuze". E4: double-click "Bevestig je keuze" → button disabled while request in flight. E5: keuze deadline passed → existing lock behavior, button disabled state per sheet. E6: confirm request fails → error toast, selection preserved. |
| F9 | Dashboard shell + nav (`Dashboard - Mijn account.dc.html`) | All 8 tabs via sidebar; rail collapse persists; mobile chips scroll; `?tab=` deep link | E1: n/a shell. E2: logged-out → redirect to login (gate preserved verbatim). E3: `?tab=certificaten` deep-link lands correctly; invalid `?tab=xyz` → home (allow-list). E4: excluded. E5: rail collapsed + unread badge → 9px dot on rail icon. E6: excluded — server-rendered shell. |
| F10 | Dashboard Home tab | Hero next-step, stat trio, Acties segmented tabs, enrollment panels | E1: zero enrollments → empty-state hero variant, stats show 0. E3: segmented tab switch is idempotent. E4: excluded — display only. E5: badge counts 0 → pill hidden. E6: excluded. E2 covered by F9. |
| F11 | Inschrijvingen + Trajecten tabs | Row cards w/ badges/progress; trajectory ring + parts + Open traject | E1: empty lists → design empty state. E3: revisit after status change → effective status reflected. E5: cancelled enrollment renders muted variant. E4/E6: excluded — read-only. |
| F12 | Offertes tab | View quote, "Herinner werkgever" action | E1: no quotes → empty state. E3: re-click herinner after success → existing throttle/feedback behavior unchanged. E4: double-click herinner → single request (loading guard). E6: action failure → error toast. E5: quote with € 0 / large totals renders. |
| F13 | Certificaten + Downloads tabs | List + download PDFs | E1: empty → empty states. E4: double-click download → no duplicate UI lock-up (icalLoading-style guard where present). E6: download failure → console error path unchanged, UI recovers. E3/E5: excluded — static lists. |
| F14 | Meldingen tab | Unread styling, mark-all-read | E1: zero notifications → empty state. E3: mark-all-read twice → idempotent. E4: double-click → guarded. E6: action fails → unread state preserved + error toast. E5: unread badge 99+ renders in pill. |
| F15 | Profiel tab | Edit personal + billing fields, save, toast | E3: edit → cancel → values revert (inlineEdit contract). E4: double save → `saving` guard. E5: empty optional field saves. E6: save failure → error shown, editing state preserved. E1/E2 covered by F9. |
| F16 | Enrollment form (restyled, `templates/forms/enrollment*`) | Complete multi-step enrollment with new field styles | E1: prefilled vs blank user data. E2: logged-out entry → existing behavior. E3: back-step keeps entered data. E4: double submit → existing guard preserved. E5: validation boundary (invalid email) shows design error field state. E6: submit failure → error state, data preserved. **Regression-critical: behavior must be byte-identical, only classes change.** |
| F17 | Contact (`Contact.dc.html`) | Read info, submit existing form | E1: page without form content → layout degrades gracefully. E6: form failure → FluentForms' own error rendering (restyled). E2/E3/E4/E5: owned by the existing form plugin — excluded from this plan. |
| F18 | Over ons (`Over ons.dc.html`) | Read editorial page | E1: missing placeholders render i18n'd defaults. Others: excluded — static content. |

---

## Field inventory deliverable (Task 10.4)

**File:** `docs/plans/2026-06-11-helder-tij-field-inventory.md`. One row per placeholder-stubbed content block: *field name · surface (template:line) · mockup source · suggested CPT/source · placeholder used*. Entries discovered at plan time (implementers append any new ones the moment they stub):

| Field | Surface | Mockup | Suggested source |
|---|---|---|---|
| `wat_je_leert` (checklist, 3-5 items) | edition detail, Omschrijving tab | Detail - Editie :76-84 | new `vad_edition` field (json) — or course-level |
| `voor_wie` (short text) | edition detail, Omschrijving tab | :85 | new edition/course field |
| `praktisch_locatie_note` | edition detail, Praktisch | :109 | extends existing `venue` |
| `inbegrepen` | edition detail, Praktisch + CTA incl-line | :110, :133 | new edition field |
| `annuleren_policy` | edition detail, Praktisch + CTA benefits | :111, :145 | new edition field or global setting |
| `lesgever` (name/role/bio/avatar) | edition detail, Lesgever tab | :116-125 | partial source exists: `EditionCPT` `speakers` (text, name only); role/bio/avatar new |
| `cta_benefits` (checklist) | edition + online detail sidebars | :143-146 / Detail - Online :124-128 | new field or global |
| homepage hero eyebrow/title/sub | front-page.php | Homepage :39-41 | theme mod / page content |
| mode-card counts (5 trajecten / 10 opleidingen / 8 modules) | front-page.php | :62/:72/:82 | **computed** from `stridence_catalog_items()` counts — not a stored field |
| `stats_trio` (3 × value+label) | front-page.php Waarom-blok | :125-129 | theme mod / options |
| `waarom_copy` + photo slot | front-page.php | :119-133 | page content + media |
| closing CTA copy | front-page.php | :138-145 | theme mod |
| contact persons (initials/names/blurb) | page-contact.php | Contact :48-55 | options / page meta |
| contact visit/call/billing blocks | page-contact.php | :57-72 | options |
| map embed slot | page-contact.php | :74-76 | media/embed |
| over-ons hero/long-read/quote/values/team | page-over-ons.php | Over ons :34-73 | page content + structured placeholders |
| **Follow-up (not a field):** email templates restyle | stride-core owned | — | post-launch, requires mu-plugin change |

---

# Task breakdown

> **Conventions for every task below:**
> - `Tier:` line per `testing-workflow` (A = RED-first behavioral test; B = `no unit test: Tier B, <reason>` + suite-green + reachable).
> - Close checklist per task: tier named · suite green (`ddev exec vendor/bin/phpunit --testsuite Unit` + `npm run build` in `web/app/themes/stridence`) · deferral line recorded · commit (atomic, language files untouched).
> - Deferral line default for template tasks: `Risk this test does NOT cover: multi-component visual — deferred to phase integration gate + /shakeout browser pass (Acceptance flows row Fx).`
> - All file paths relative to `/home/ntdst/Sites/stride/` unless noted. Theme root = `web/app/themes/stridence/`.

---

## Phase 0 — Branch + test infrastructure

### Task 0.1: Feature branch + baseline

**Files:** none (git only)

- [ ] **Step 1:** Check worktree state first (`git rev-parse --is-inside-work-tree && git worktree list`); if already in a task worktree, work in place. Otherwise: `git checkout -b feature/helder-tij-redesign staging`.
- [ ] **Step 2:** Baseline runs — `ddev exec vendor/bin/phpunit --testsuite Unit` (expect green) and `cd web/app/themes/stridence && npm install && npm run build` (expect green). Record counts.
- [ ] **Step 3:** Confirm `git status` — the modified `web/app/languages/*` files exist and are NEVER staged in any commit of this branch.

`Tier: B — no code. no unit test: Tier B, branch setup.`

### Task 0.2: Minimal Vitest setup for Alpine factory units

**Files:** Modify: `web/app/themes/stridence/package.json` · Create: `web/app/themes/stridence/vitest.config.js`

- [ ] **Step 1:** `npm install -D vitest jsdom` (theme dir).
- [ ] **Step 2:** Create `vitest.config.js`:
```js
import { defineConfig } from 'vitest/config';
export default defineConfig({
  test: { environment: 'jsdom', include: ['src/**/*.test.js'] },
});
```
- [ ] **Step 3:** Add script `"test:unit": "vitest run"` to `package.json`.
- [ ] **Step 4:** Smoke: create `src/setup.test.js` with `import { describe, it, expect } from 'vitest'; describe('setup', () => it('runs', () => expect(1).toBe(1)));` → `npm run test:unit` → PASS → delete the smoke file or keep as canary.
- [ ] **Step 5:** Commit: `chore(theme): add vitest for Alpine factory unit tests`

`Tier: B — tooling. no unit test: Tier B, test-infra setup (testing-workflow: no framework → set it up first).`

---

## Phase 1 — Foundations: tokens, Tailwind, fonts, base CSS

### Task 1.1: Swap tokens.css to v2 (Helder Tij)

**Files:** Modify: `web/app/themes/stridence/src/css/tokens.css` (full replace)

- [ ] **Step 1:** Replace file contents with `docs/stride-base-design/tokens.css` verbatim (it is a verified superset of v1 — finding #1; same RGB-triple consumption model).
- [ ] **Step 2:** `npm run build` → green. Load homepage + dashboard in browser: colors shift to teal/korenbloem/IJs, nothing unstyled (all v1 variable names survive).
- [ ] **Step 3:** Commit: `feat(theme): Helder Tij v2 design tokens`

`Tier: B — config/token swap. no unit test: Tier B, token file replace; verified by build + visual smoke.`
`Acceptance: per-task line + zero variable renames (superset verified at plan time).`

### Task 1.2: Extend tailwind.config.js for the five new v2 tokens

**Files:** Modify: `web/app/themes/stridence/tailwind.config.js`

- [ ] **Step 1:** In `theme.extend.colors`, extend exactly:
```js
accent: {
  DEFAULT: 'rgb(var(--color-accent) / <alpha-value>)',
  hover: 'rgb(var(--color-accent-hover) / <alpha-value>)',
  subtle: 'rgb(var(--color-accent-subtle) / <alpha-value>)',
  light: 'rgb(var(--color-accent-light) / <alpha-value>)',
},
border: {
  DEFAULT: 'rgb(var(--color-border) / <alpha-value>)',
  soft: 'rgb(var(--color-border-soft) / <alpha-value>)',
  strong: 'rgb(var(--color-border-strong) / <alpha-value>)',
},
text: {
  DEFAULT: 'rgb(var(--color-text) / <alpha-value>)',
  muted: 'rgb(var(--color-text-muted) / <alpha-value>)',
  faint: 'rgb(var(--color-text-faint) / <alpha-value>)',
  inverse: 'rgb(var(--color-text-inverse) / <alpha-value>)',
},
focus: 'rgb(var(--color-focus) / <alpha-value>)',
```
(other keys unchanged).
- [ ] **Step 2:** `npm run build` green; spot-check a `text-text-faint` class compiles (add to a template temporarily or check CSS output).
- [ ] **Step 3:** Commit: `feat(theme): expose accent-hover/subtle, border-soft, text-faint, focus tokens in Tailwind`

`Tier: B — config mapping. no unit test: Tier B, declarative config; verified by build output.`

### Task 1.3: Font swap — Hanken Grotesk + Newsreader (BOTH header templates — SSA-1)

**Files:** Modify: `web/app/themes/stridence/header.php:18` · `web/app/themes/stridence/header-dashboard.php` (same pattern ~line 20)

- [ ] **Step 1:** In both files, replace the default URL inside the existing `apply_filters('stridence_font_url', …)` call with:
```
https://fonts.googleapis.com/css2?family=Hanken+Grotesk:wght@400;500;600;700;800&family=Newsreader:ital,opsz,wght@0,6..72,300;0,6..72,400;0,6..72,500;1,6..72,300;1,6..72,400&display=swap
```
(weights union of all mockup helmets; the FILTER itself stays — clients override it.)
- [ ] **Step 2:** SSA-1 sweep: `grep -rn "fonts.googleapis" web/app/themes/stridence --include="*.php"` → exactly the two known sites, both updated.
- [ ] **Step 3:** Browser check: computed `font-family` on body = Hanken Grotesk; serif headings = Newsreader. Plus Jakarta Sans / Manrope no longer requested (Network tab).
- [ ] **Step 4:** Commit: `feat(theme): swap to Hanken Grotesk + Newsreader (both header templates, filter preserved)`

`Tier: B — asset URL swap. no unit test: Tier B, markup-only; verified by network + computed-style check.`

### Task 1.4: base.css + components.css re-skin pass (focus style, recipes audit)

**Files:** Modify: `web/app/themes/stridence/src/css/base.css`, `web/app/themes/stridence/src/css/components.css`

- [ ] **Step 1:** Read both files fully. In `base.css`: body/typography assumptions still valid (fonts come from tokens — likely no change); add/replace the global focus style per v2 tokens comment: `:focus-visible { outline: 2px solid rgb(var(--color-focus)); outline-offset: 2px; }`. Add the `st-spin` keyframes (used by loading buttons, Componenten :16): `@keyframes st-spin { to { transform: rotate(360deg); } }`.
- [ ] **Step 2:** In `components.css` `@layer components`: audit every rule for v1-era values (raw hex, old radii, old font weights). Re-skin to the component sheet where a rule corresponds to a sheet component; delete rules that the Phase 2/3 recipes supersede (note them for those tasks rather than duplicating).
- [ ] **Step 3:** `npm run build` green; visual smoke (homepage, a catalog, dashboard) — no obviously broken layout.
- [ ] **Step 4:** Commit: `feat(theme): base/components CSS reskin — focus ring, spinner keyframes, v1 rule audit`

`Tier: B — presentational CSS. no unit test: Tier B, style-only; verified by build + visual smoke.`

**Phase 1 integration gate:** `ddev exec vendor/bin/phpunit --testsuite Unit` green · `npm run build` green · browser smoke on `/`, `/klassikaal/` (or actual catalog slug), `/mijn-account/` — pages render, fonts + palette correct, no console errors.

`── REVIEW GATE ── (tier: STANDARD — multi-file UI foundation, no 1a surface, no invariant rewrite)`

---

## Phase 2 — Shared components A: chrome + primitives

### Task 2.1: Site header restyle

**Files:** Modify: `web/app/themes/stridence/header.php`
**Mockup:** any public mockup header (e.g. `Detail - Editie.dc.html` :23-32; sticky+blur variant: `Homepage.dc.html` :23).

- [ ] **Step 1:** Read current `header.php` nav markup. Restyle to: white bar (`bg-surface-card`), bottom hairline (`shadow-[0_1px_0] shadow-border-soft` or `border-b border-border-soft`), logo left, center nav links (15px/600, `text-text-muted`, hover `text-text`, active `text-primary`), right "Mijn account" ghost button (1px `border-border`, `rounded-[10px]`, hover `bg-surface-alt`). Sticky + `backdrop-blur` + translucent bg per Homepage mockup (locked decision: everywhere). Keep existing menu source (WP menu / hardcoded as currently), mobile menu behavior (`mobileMenu` Alpine) intact, all esc_* preserved.
- [ ] **Step 2:** Verify: desktop + mobile menu render; logged-in vs out states of "Mijn account" unchanged in behavior.
- [ ] **Step 3:** Commit: `feat(theme): Helder Tij site header`

`Tier: B — presentational markup. no unit test: Tier B, classname/layout-only.`

### Task 2.2: Footer restructure (2-group)

**Files:** Modify: `web/app/themes/stridence/footer.php:13-74`
**Mockup:** `Homepage.dc.html` :147-169.

- [ ] **Step 1:** Restructure: white footer (`bg-surface-card`), top hairline; left column = logo + address/contact lines (`text-text-faint`, 13px); right = two link groups **Aanbod** (Trajecten, Klassikaal, Online, Agenda) and **Stride** (Over ons, Contact, FAQ, Mijn account); bottom line keeps © + legal links (Privacybeleid, Algemene voorwaarden) — named deviation, design omits them. Keep the dashboard-template hide class on the `<footer>` (`page-mijn-account.php` check) verbatim. Keep the toast container block at :78-94 untouched (Task 3.4 owns it).
- [ ] **Step 2:** Verify all existing link URLs survive (diff old vs new link inventory — none dropped).
- [ ] **Step 3:** Commit: `feat(theme): Helder Tij 2-group footer`

`Tier: B — presentational. no unit test: Tier B, markup-only.`

### Task 2.3: Button + form-field recipes

**Files:** Modify: `web/app/themes/stridence/src/css/components.css`
**Mockup:** `Componenten.dc.html` :32-50 (buttons), :226-285 (form fields).

- [ ] **Step 1:** Define/replace `@layer components` classes (names: keep existing class names if templates already use e.g. `.btn` — read first; otherwise introduce):
  - `.btn-primary` (md): 15px/700 white on `primary`, `rounded-[10px]`, `px-[22px] py-3`, hover `primary-hover`, pressed `primary-dark`, disabled `#DCE3E2`-equivalent → use `bg-surface-container-highest text-text-faint cursor-not-allowed` (token-mapped, sheet shows literal #DCE3E2/#8A938F — map to nearest tokens, do NOT hardcode hex).
  - `.btn-primary-sm` 13px/700 `rounded-lg px-3.5 py-2`; `.btn-primary-lg` 16px/700 `rounded-xl px-[30px] py-[15px]`.
  - `.btn-ghost`: transparent, `border border-border`, `text-text`, hover `bg-surface-alt`; disabled variant `border-border-soft text-text-faint`.
  - `.btn-loading` helper: inline-flex + `.spinner` span (`border-2`, white-top, `animate-[st-spin_700ms_linear_infinite]`).
  - `.btn-load-more`: ghost, full-width centered (sheet :47-48) + loading variant on `bg-surface-alt`.
  - `.field-input`: `border border-border rounded-[10px] px-3.5 py-[11px] text-[15px] bg-surface-card`; focus: `border-primary` + `shadow-[0_0_0_3px_rgb(var(--color-primary)/0.14)]`; error: `border-error` + error shadow + 12px error text below; disabled: `bg-surface-alt text-text-faint border-border-soft cursor-not-allowed`.
  - `.field-label` 13px/600; checkbox (22px, `rounded-md`, checked `bg-primary` + ✓) and radio (22px round, checked 6.5px `border-primary`) recipes, min-height 44px touch rows.
- [ ] **Step 2:** `npm run build` green. Render a scratch page or existing form to eyeball states.
- [ ] **Step 3:** Commit: `feat(theme): Helder Tij button + form-field recipes`

`Tier: B — CSS recipes. no unit test: Tier B, style-only; states exercised at F15/F16 browser pass.`

### Task 2.4: Badge variants + breadcrumb

**Files:** Modify: `web/app/themes/stridence/partials/badge-status.php`, `web/app/themes/stridence/partials/breadcrumb.php`
**Mockup:** `Componenten.dc.html` :52-93.

- [ ] **Step 1:** Read `badge-status.php` — identify how variants map today. New canonical 8-variant map (recipe: `text-[12px] font-bold px-[11px] py-1 rounded-full`; card-size variant `text-[11px] px-[9px] py-[3px]`):

| Variant | Classes (token-mapped) | Label |
|---|---|---|
| open | `bg-badge-open-bg text-badge-open-text`* | Open inschrijving |
| few | badge-few pair | Nog enkele plaatsen |
| full | badge-full pair | Volzet |
| cancelled | badge-cancelled pair | Geannuleerd |
| online | badge-online pair | Online |
| free | badge-free pair | Gratis |
| enrolled | badge-online pair | ✓ Ingeschreven |
| trajectory | `bg-accent-subtle text-accent-hover` | Traject |

\* If badge token pairs aren't yet exposed in `tailwind.config.js` colors, add them in this task (same `rgb(var(--color-badge-*-bg) / <alpha-value>)` pattern) — extend Task 1.2's block. Status is never color-alone: label always rendered (tokens.css comment :60-61).
- [ ] **Step 2:** If the variant mapping lives in a PHP helper with branching (status→variant derivation), write the Tier-A contract test FIRST in `tests/Unit/` (RED: new `trajectory`/`enrolled` variants unmapped; include the negative path: unknown status → safe default variant, never a PHP notice) → implement → GREEN. If `badge-status.php` proves to be a pure echo template with no branching, record Tier B instead.
- [ ] **Step 3:** Breadcrumb per sheet :67-74: 13px, `text-text-faint`, `›` separators, current crumb `text-text font-semibold`; keep existing args contract so call sites don't change.
- [ ] **Step 4:** SSA-4 sweep: grep inline badge markup (see SSA-4) and convert stragglers in files this phase already touches; remaining sites are converted by their owning screen tasks (3.1, 8.x) — list them in the task report.
- [ ] **Step 5:** Full unit suite + build → commit: `feat(theme): 8-variant status badges + breadcrumb (Helder Tij)`

`Tier: A IF mapping logic exists (status→variant derivation incl. unknown-status fallback); else B (pure template). Name which in the close report.`

**Phase 2 integration gate:** suites + build green · browser: header/footer on public pages, breadcrumb + badges on a detail page render per sheet.

`── REVIEW GATE ── (tier: STANDARD — chrome + primitives, no 1a surface)`

---

## Phase 3 — Shared components B: cards, rows, progress, states, toast

### Task 3.1: Card partials — both families (SSA-2)

**Files:** Modify: `web/app/themes/stridence/partials/card-edition.php`, `partials/card-course.php`, `partials/card-trajectory.php`, `templates/components/course-card.php`
**Mockup:** `Componenten.dc.html` :96-186 (three card types + enrolled variants); trajectory dots strip :122-127.

- [ ] **Step 1:** Read all four partials + `helpers/catalog.php` prefetch arg shapes FIRST (INV-7: these are pure renderers — args in, markup out; the stored-status fallback in `card-edition.php` stays).
- [ ] **Step 2:** Restyle to sheet recipe: white card `rounded-[14px] shadow-card p-6 flex flex-col gap-3.5`, hover lift (`hover:shadow-elevated hover:-translate-y-0.5 transition` — apply on the link wrapper as in mockups); badge row (recipe from 2.4); 17px/700 title; 13px meta block; footer row price (16px/800) vs CTA (`text-primary` 14px/700, `→`). Variants: enrolled (✓ Ingeschreven + "Volgende sessie"), online-progress (label row + 7px progress bar), cancelled (opacity-85, muted title, alternatives CTA), trajectory (dots-and-lines progress strip: done=primary dot+line, current=hollow, upcoming=border dots), free/gratis footer.
- [ ] **Step 3:** No new queries, no status reads — render only from passed args. If a design element needs data the prefetch doesn't pass (e.g. "waarvan 1 keuzemodule"), render conditionally on arg presence; do NOT extend `helpers/catalog.php` queries in this task — if truly required, that's a separate theme-helper task to flag to the controller.
- [ ] **Step 4:** Verify through the REAL chain (seam, this is a wiring-ish task): load a catalog page (server-rendered first slice) AND trigger one "Toon meer" fetch — paged cards must come back with the new markup (both paths run `stridence_catalog_render_cards`). One negative: filter to an empty theme → no card markup leaks.
- [ ] **Step 5:** Suite + build → commit: `feat(theme): Helder Tij card partials (catalog renderers + dashboard course-card)`

`Tier: B — pure renderers, no logic added. no unit test: Tier B, presentational; seam verified live via catalog endpoint (step 4).`
`Risk not covered: visual-only — deferred to /shakeout F2-F4.`

### Task 3.2: Session row + progress bar + progress ring

**Files:** Modify: `web/app/themes/stridence/partials/session-row.php`, `partials/progress-bar.php`, `templates/dashboard/partials/progress-ring.php`, `templates/dashboard/partials/progress-bar.php`
**Mockup:** `Componenten.dc.html` :188-224.

- [ ] **Step 1:** Session row: white `rounded-xl shadow-card p-3.5 px-[18px]`, left date block (50px, `rounded-[11px]`, `bg-badge-online-bg text-badge-online-text`, day number 17px/800 + month uppercase 10px/700), middle title+date, right time (tabular-nums) + location 12px `text-text-faint`. Past session: `opacity-60`, date block `bg-surface-alt text-text-muted`. Keep existing args contract.
- [ ] **Step 2:** Progress bar: track `h-2 rounded-full bg-surface-alt`, fill `bg-primary rounded-full`, label row (12px/700 `text-text-muted`, right value tabular-nums). Sweep BOTH progress-bar partials (root + dashboard) to one recipe.
- [ ] **Step 3:** Progress ring (`progress-ring.php`): SVG per sheet :213-218 — track `stroke` = surface-alt (white when on tinted band, per Detail-Traject :54), fill `stroke-primary stroke-linecap-round`, `stroke-dasharray` from percent, rotate -90°, centered 800-weight percent label. Parameterize size (72/84/64 used across mockups). Animate from 0 via existing pattern only if already present — no new JS.
- [ ] **Step 4:** Suite + build → commit: `feat(theme): session row, progress bar + ring (Helder Tij)`

`Tier: B — presentational; dasharray math is trivial arithmetic in template. no unit test: Tier B. Risk not covered: 0%/100% boundary visual — deferred to /shakeout F6/F8 (E5).`

### Task 3.3: Empty + error states

**Files:** Modify: `web/app/themes/stridence/partials/empty-state.php`, `partials/error-state.php`
**Mockup:** `Componenten.dc.html` :287-308; catalog in-band variant `Catalogus - Klassikaal.dc.html` :78-87.

- [ ] **Step 1:** Empty: centered column, 56px white icon circle (shadow-card), 16-17px/700 title, 13-14px muted copy (max-w constrained), ghost action button. Support the catalog band variant (on `bg-surface-alt rounded-2xl py-16`). Keep args contract (icon/title/copy/action) — extend with optional band wrapper arg.
- [ ] **Step 2:** Error: same shape, icon circle `bg-badge-full-bg text-badge-full-text` with `!`, primary action button ("Opnieuw proberen").
- [ ] **Step 3:** Suite + build → commit: `feat(theme): Helder Tij empty/error states`

`Tier: B — presentational. no unit test: Tier B.`

### Task 3.4: Toast — design card, back-compatible payload (SSA-3)

**Files:** Modify: `web/app/themes/stridence/footer.php:78-94` (toast markup), `web/app/themes/stridence/src/main.js:24-49` (toastStore) · Test: `web/app/themes/stridence/src/toast.test.js`
**Mockup:** `Componenten.dc.html` :309-323; placement `Dashboard - Mijn account.dc.html` :324-331 (fixed right-24 bottom-24, slide-in).

- [ ] **Step 1 (RED):** Write Vitest for the new toastStore contract: (a) `show({message, type})` — legacy payload — sets visible with `title === message`, success icon; (b) `show({message: 'x', type: 'error'})` → error icon variant; (c) optional `sub` carried; (d) second `show` before timeout replaces content and resets timer (no double-hide); (e) `close()` hides immediately. Run → FAIL (new fields absent).
- [ ] **Step 2 (GREEN):** Extend toastStore: state `{visible, title, sub, type, timeout}`; `show({ message, type = 'success', sub = '' })` maps `message→title`; add `close()`. Keep 4000ms auto-hide + clearTimeout logic.
- [ ] **Step 3:** Replace footer toast markup: fixed bottom-right card (`w-[340px] max-w-[calc(100vw-3rem)] rounded-xl bg-surface-card shadow-overlay p-3.5 px-4`), 28px icon circle (success: badge-open pair ✓ / error: badge-full pair !), title 14px/700, sub 13px muted, × close button wired to `close()`. Slide-in via existing x-transition (translate-y) — respects reduced-motion (tokens.css media query).
- [ ] **Step 4:** SSA-3: confirm the 3 dispatch sites (`main.js` inlineEdit/inlineEditSection, `tab-offertes.php`, `forms/enrollment.js`) work unchanged — payload contract untouched. Run each dispatch path's surrounding code path mentally + one live browser dispatch.
- [ ] **Step 5:** `npm run test:unit` green ×3 runs (timer logic — determinism rule) → suite + build → commit: `feat(theme): Helder Tij toast card (back-compatible payload)`

`Tier: A — stateful timer/replace logic with a real behavioral contract (RED-first, incl. the replace-before-timeout negative path).`
`Risk not covered: cross-page dispatch sites live — deferred to /shakeout F12/F15/F16 (E6).`

**Phase 3 integration gate:** suites + Vitest + build green · browser: catalog page shows new cards via BOTH initial render and Toon-meer fetch; empty state via theme filter; toast fires from profiel save.

`── REVIEW GATE ── (tier: STANDARD — shared components, no 1a surface; INV-7 renderer contract is the review focus)`

---

## Phase 4 — Catalogs

### Task 4.1: Catalog Klassikaal

**Files:** Modify: `web/app/themes/stridence/page-klassikaal.php`
**Mockup:** `Catalogus - Klassikaal.dc.html`.

- [ ] **Step 1:** Header band: `bg-surface-alt` (no border-b per design — hairline comes from band contrast), Newsreader serif h1 (`font-serif font-normal text-[clamp]`), 16px muted intro (max-w-[560px]). Replace current bold sans h1.
- [ ] **Step 2:** Filter chips: existing Alpine mechanism kept VERBATIM (filter/page/counts/rollback logic untouched); restyle chip buttons per mockup :47-51 — pill `rounded-full px-4 py-2 min-h-9 border`, inactive: white bg/`border-border`/`text-text-muted`, active: `bg-primary text-white border-primary`; count pill inside (11px/700 tabular-nums; inactive `bg-surface-alt`, active `bg-white/[0.22] text-white`). "Alles" chip first with total.
- [ ] **Step 3:** Grid `repeat(auto-fill,minmax(300px,1fr)) gap-[18px]` (cards already restyled in 3.1). Empty state: in-band variant (3.3) with "Toon alles" → existing `clearFilter`-equivalent. Load-more: `.btn-load-more` + loading spinner variant bound to existing `loading` state.
- [ ] **Step 4:** Verify in browser: filter, empty theme, toon meer, error rollback (kill network in devtools → error state shows, filter rolls back — existing S8 behavior).
- [ ] **Step 5:** Suite + build → commit: `feat(theme): Helder Tij catalog — klassikaal`

`Tier: B — restyle of existing verified mechanism; Alpine object logic unchanged. no unit test: Tier B. Risk not covered: paging seam — exercised live in step 4 + /shakeout F2.`

### Task 4.2: Catalog Online

**Files:** Modify: `web/app/themes/stridence/page-online.php`
**Mockup:** `Catalogus - Online.dc.html` (same pattern as 4.1; online cards show "Op eigen tempo · ± X uur" meta + Gratis variants).

- [ ] Steps mirror Task 4.1 (do not reference it for markup — apply the same mockup-derived recipes directly; the two templates may share structure but each is edited completely). Verify + commit: `feat(theme): Helder Tij catalog — online`

`Tier: B — same rationale as 4.1.`

### Task 4.3: Catalog Trajecten

**Files:** Modify: `web/app/themes/stridence/archive-vad_trajectory.php`
**Mockup:** `Catalogus - Trajecten.dc.html`.

- [ ] **Step 1:** Read the current archive template (it may not share the chip/paging mechanism — adapt: if it's a plain archive loop, keep that, restyle band + grid + trajectory cards w/ dots strip from 3.1; add chips/paging ONLY if already present).
- [ ] **Step 2:** Empty state per design when no trajectories.
- [ ] **Step 3:** Verify + suite + build → commit: `feat(theme): Helder Tij catalog — trajecten`

`Tier: B — presentational. Risk not covered: visual — /shakeout F4.`

**Phase 4 integration gate:** suites + build · browser pass of F2/F3/F4 happy paths + E1 (empty) + E4 (double Toon meer) + E6 (endpoint failure rollback).

`── REVIEW GATE ── (tier: STANDARD — catalog restyles on existing mechanism; INV-7 convergence is the review focus)`

---

## Phase 5 — Detail pages

### Task 5.1: `contentTabs` Alpine factory + edition detail tab structure

**Files:** Modify: `web/app/themes/stridence/src/main.js`, `web/app/themes/stridence/single-vad_edition.php`, `templates/edition/tabs.php` · Test: `web/app/themes/stridence/src/content-tabs.test.js`
**Mockup:** `Detail - Editie.dc.html` :34-126 (band, tabs, four panels).

- [ ] **Step 1 (RED):** Vitest for `contentTabs(tabs, initial)`: (a) defaults to first tab; (b) `setTab('praktisch')` activates it; (c) **negative:** `setTab('bogus')` is ignored (activeTab unchanged); (d) `init()` with `location.hash = '#praktisch'` preselects; unknown hash → first tab. Run → FAIL.
- [ ] **Step 2 (GREEN):** Implement in `main.js`:
```js
Alpine.data('contentTabs', (tabs = [], initial = null) => ({
  tabs,
  activeTab: initial && tabs.includes(initial) ? initial : (tabs[0] ?? ''),
  isActive(id) { return this.activeTab === id; },
  setTab(id) { if (this.tabs.includes(id)) this.activeTab = id; },
  init() {
    const h = window.location.hash.replace('#', '');
    if (this.tabs.includes(h)) this.activeTab = h;
  },
}));
```
Run ×3 → green.
- [ ] **Step 3:** Rebuild `single-vad_edition.php` main column: header band (`bg-surface-alt`: breadcrumb, badge row [type + effective-status badge via 2.4 — status from the existing prefetch/effective-status data the template already receives], Newsreader h1 `font-normal clamp(30px,4.5vw,44px)`, meta dot-row [dates strong / venue / N sessies / price], "Onderdeel van de opleiding X — bekijk alle edities" accent link using the existing course relation). Replace scroll-spy sections with 4 real tab panels driven by `contentTabs(['omschrijving','programma','praktisch','lesgever'])`: underline tabs per sheet (:78-83 recipe — active `text-primary` + `shadow-[inset_0_-2px_0]` primary, inactive `text-text-faint` hover `text-text`). **Server-render-first:** all four panels in the DOM; `x-show` toggles; `x-cloak` styling so non-JS shows panels stacked (graceful).
- [ ] **Step 4:** Panel content: *Omschrijving* = existing edition/course description via `the_content()`/existing field + "Wat je leert" checklist (PLACEHOLDER — i18n'd sample list, field-inventory: `wat_je_leert`) + "Voor wie?" well (`bg-surface-alt rounded-[14px] p-5` — PLACEHOLDER `voor_wie`). *Programma* = existing sessions via `session-row.php` (3.2), empty-state when none + the "beide sessies horen bij dezelfde inschrijving" note when >1. *Praktisch* = 3 info cards (Locatie from `venue`; Inbegrepen PLACEHOLDER; Annuleren PLACEHOLDER). *Lesgever* = card with avatar-initials + name (source: edition `speakers` field if present, else placeholder) + role/bio PLACEHOLDERS. Every placeholder added to the field-inventory doc immediately.
- [ ] **Step 5:** SSA-6: remove `editionDetailTabs` usage from this template. Suite + Vitest + build → commit: `feat(theme): edition detail content tabs (Helder Tij)`

`Tier: A for the contentTabs factory (RED-first incl. the unknown-id negative path). Templates within: Tier B.`
`Risk not covered: tab deep-link in real browser — deferred to /shakeout F5 (E3).`

### Task 5.2: Edition sticky CTA sidebar + mobile CTA

**Files:** Modify: `web/app/themes/stridence/single-vad_edition.php` (sidebar region), possibly `templates/edition/` partial extraction
**Mockup:** `Detail - Editie.dc.html` :128-163.

- [ ] **Step 1:** Desktop sidebar (`lg:` only, `sticky top-6`, `flex-[0_1_360px] min-w-[300px]`): white card `rounded-2xl shadow-elevated p-7` with price block (32px/800 + "per deelnemer" faint; incl-line PLACEHOLDER `price_includes`), capacity bar (existing spots/capacity data → fill %; label "Nog X van Y plaatsen vrij" in warning color when few — reuse the same spots logic the current template/prefetch already exposes; do NOT re-derive status), two CTAs (primary "Schrijf je in" → existing `stride_enrollment_url`; ghost "Offerte voor je team" → existing quote-request link), divider, benefits checklist (✓ rows — PLACEHOLDER `cta_benefits`), then accent-subtle chip card "Liever een andere datum? / Alle edities →" linking to the course page (existing relation).
- [ ] **Step 2:** Mobile sticky bottom CTA (`lg:hidden fixed bottom-0 inset-x-0 z-40 bg-surface-card shadow-[0_-4px_16px]` + safe-area padding): price + spots line left, primary CTA right. Add `pb-24 lg:pb-0` page padding so content isn't obscured.
- [ ] **Step 3:** States: volzet → CTA becomes existing wachtlijst action (existing behavior — verify current template's volzet branch and keep it); cancelled/completed → existing gate rendering preserved.
- [ ] **Step 4:** Verify F5 happy + E5 in browser. Suite + build → commit: `feat(theme): edition sticky CTA + mobile CTA`

`Tier: B — presentational; enrollment gating logic NOT touched (it stays wherever it lives today). no unit test: Tier B.`

### Task 5.3: Online course detail

**Files:** Modify: `web/app/themes/stridence/single-sfwd-courses.php`, `templates/course/header.php`, `templates/course/content.php`, `templates/course/tabs.php`, `templates/course/sidebar-online.php`, `templates/course/mobile-cta.php`, `templates/course/editions-list.php`
**Mockup:** `Detail - Online opleiding.dc.html`.

- [ ] **Step 1:** Read all six course templates first. Header band: breadcrumb + badges (Online/Gratis via 2.4) + serif h1 + meta dot-row (Op eigen tempo / duration / N modules / toets — from existing LD data via `LearnDashHelper`; omit segments without data).
- [ ] **Step 2:** Main column: course intro via `the_content()` (INV-6 — LD owns content); "Inhoud van de opleiding" lesson list is **LD markup styled via `learndash.css` ONLY** (Task 10.3 carries the CSS; this task may add wrapper classes around `the_content()` but never re-implements the list). If the current template renders a custom lesson list via `LearnDashHelper::getLessons()` (read first!), restyle THAT markup to the mockup recipe (:66-101 — white card, divider rows, ✓ done circles, active row `bg-badge-online-bg` + "Hier ben je gebleven", locked rows muted) — that's helper-read presentation, allowed.
- [ ] **Step 3:** Sidebar (`sidebar-online.php`): progress ring (3.2, 64px) + "Goed bezig" header (enrolled state) OR start state (not enrolled); primary CTA ("Ga verder met module X" / "Start opleiding") from existing `LearnDashHelper::getCourseAction()`; benefits checklist PLACEHOLDER; accent-subtle chip "Liever klassikaal? / Bekijk de edities →" rendered ONLY when editions exist (`editions-list.php` data).
- [ ] **Step 4:** Mobile CTA (`mobile-cta.php`): progress % + modules-left line + CTA. SSA-6: drop `courseDetailTabs` if this template was its last consumer (grep).
- [ ] **Step 5:** Verify F6 happy + not-enrolled state. Suite + build → commit: `feat(theme): online course detail (Helder Tij)`

`Tier: B — presentational over LearnDashHelper reads. no unit test: Tier B. Risk not covered: LD-rendered content visual — /shakeout F6.`

### Task 5.4: Trajectory public detail restyle

**Files:** Modify: `web/app/themes/stridence/single-vad_trajectory.php`, `templates/trajectory/content.php`, `templates/trajectory/course-groups.php` (public path only)
**Mockup:** none 1:1 (locked decision 3: keep structure) — apply band/serif-h1/badge/card recipes from Phases 2-3 + `Detail - Traject.dc.html` header band as the visual reference for the non-enrolled variant (no ring).

- [ ] **Step 1:** Restyle header to the band pattern; body sections/cards to the shared recipes; course-groups list rows to the journey-card recipe where it fits without structural change.
- [ ] **Step 2:** Verify F7. Suite + build → commit: `feat(theme): trajectory public detail restyle`

`Tier: B — presentational. no unit test: Tier B.`

**Phase 5 integration gate:** suites + Vitest + build · browser: F5 (all four tabs + hash deep-link + unknown hash + sticky CTA + mobile CTA at <1024px), F6 (enrolled + not-enrolled), F7.

`── REVIEW GATE ── (tier: STANDARD — detail screens; INV-6/INV-7 read-paths are the review focus, no 1a surface)`

---

## Phase 6 — Trajectory enrolled view (rebuild to mockup)

### Task 6.1: Enrolled shell — header band + tabs

**Files:** Modify: `web/app/themes/stridence/templates/trajectory/dashboard.php`, `templates/trajectory/tabs.php`
**Mockup:** `Detail - Traject.dc.html` :35-72.

- [ ] **Step 1:** Read both templates + how the enrolled view currently routes/derives progress. Header band (`bg-surface-alt`): breadcrumb, badges (Traject + ✓ Ingeschreven), serif h1, meta line (N onderdelen · gestart … · verwachte afronding …— from existing trajectory data; omit absent segments), right-side 84px progress ring (3.2; track = white on the tinted band) + "X van Y onderdelen afgerond".
- [ ] **Step 2:** Tabs: underline style, `Voortgang / Keuzes / Materialen / Berichten`, accent count badge on Keuzes when a choice is open (existing open-choice data). **Keep the existing tab-state mechanism** (`?tab=` if that's what the current enrolled view uses — read first; if it currently uses `trajectoryDetailTabs` scroll-spy, switch to `contentTabs` with `?tab=`-style server preselect passed as `initial`). SSA-6 applies.
- [ ] **Step 3:** Suite + build → commit: `feat(theme): trajectory enrolled shell (band + ring + tabs)`

`Tier: B — shell markup; tab state reuses the tested contentTabs factory. no unit test: Tier B.`

### Task 6.2: Voortgang tab — journey timeline

**Files:** Modify: `web/app/themes/stridence/templates/trajectory/tab-voortgang.php`
**Mockup:** `Detail - Traject.dc.html` :74-122.

- [ ] **Step 1:** Timeline rows per state from EXISTING per-part data: done (✓ circle badge-open colors, primary connector, white card + "Afgerond" badge + completion date), active (primary dot circle, `bg-badge-online-bg` card, CTA "Bekijk editie" → edition link), upcoming/locked (hollow circle, outlined card), elective row (hollow + dashed-outline card, "Keuzemodule — kies 1 uit N", status line, button "Maak je keuze" → switches to Keuzes tab / "Wijzig keuze" ghost when confirmed).
- [ ] **Step 2:** Connector rail: 28px column, 2px line (primary above-current, border below). Last row: no trailing line.
- [ ] **Step 3:** Verify with seed trajectory data. Suite + build → commit: `feat(theme): trajectory journey timeline`

`Tier: B — presentational mapping of existing states. no unit test: Tier B. Risk not covered: all four state renders with real data — /shakeout F8.`

### Task 6.3: Keuzes + Materialen + Berichten tabs

**Files:** Modify: `web/app/themes/stridence/templates/trajectory/tab-keuzes.php`, `tab-materialen.php`, `tab-berichten.php`
**Mockup:** `Detail - Traject.dc.html` :124-172.

- [ ] **Step 1:** Keuzes: intro line (deadline from existing data), selectable cards grid (`minmax(260px,1fr)`): card w/ title + radio circle (selected: ring `0 0 0 2px primary` shadow + filled radio), desc, meta footer. Selection + "Bevestig je keuze" wire to the EXISTING choice mechanism (read current tab-keuzes.php first — keep its action/endpoint and validation exactly; restyle states: confirm button disabled until selection [`bg-surface-container-highest text-text-faint`], in-flight guard, hint line per state, success toast via toastStore, confirmed → "Wijzig keuze" ghost).
- [ ] **Step 2:** Materialen: download rows (38px PDF tile `bg-surface-alt`, title + meta, ghost Download button) using existing materials data + empty state.
- [ ] **Step 3:** Berichten: message rows — unread `bg-[#F4F6FC]`-equivalent → use `bg-accent-subtle/60` token form, avatar-initials circle, name+time row, body; read = white + inset hairline. Existing data source unchanged + empty state.
- [ ] **Step 4:** Live seam check (this wires UI to the real choice endpoint): drive one keuze select→bevestig against the dev server (seed data) + one negative (confirm with nothing selected → blocked client-side AND server response handled). Suite + build → commit: `feat(theme): trajectory keuzes/materialen/berichten (Helder Tij)`

`Tier: B — restyle over existing endpoint; the in-flight/disabled logic is markup-bound Alpine state. no unit test: Tier B; seam exercised live (step 4).`
`Risk not covered: concurrent double-confirm — deferred to /shakeout F8 (E4).`

**Phase 6 integration gate:** suites + Vitest + build · browser F8 full pass (timeline states, keuze flow incl. E3 re-entry + E4 double-click, materials, messages, empty states).

`── REVIEW GATE ── (tier: STANDARD — enrolled view rebuild; choice-flow wiring is the review focus; no 1a surface — choice endpoint pre-exists unchanged)`

---

## Phase 7 — Dashboard shell + navigation

### Task 7.1: App shell — switch to dashboard header, main column

**Files:** Modify: `web/app/themes/stridence/page-mijn-account.php`, `header-dashboard.php` (font line already done in 1.3), `footer-dashboard.php` (read; minimal changes)
**Mockup:** `Dashboard - Mijn account.dc.html` :21-100.

- [ ] **Step 1:** `page-mijn-account.php`: change `get_header()` → `get_header('dashboard')` (finding #11 — the minimal header exists). **Preserve verbatim:** the login redirect (:17-20), `$valid_tabs` allow-list + sanitize (:25-30), per-tab data fetch logic (:32-48), nav arrays, `$page_titles`.
- [ ] **Step 2:** New layout: `min-h-screen flex bg-surface` — sidebar component (7.2) + main column (`flex-1 min-w-0`): mobile top bar (7.3), then content wrapper `max-w-[1080px] mx-auto w-full p-5 lg:p-10 flex flex-col gap-6`. Page header: Newsreader serif title (`font-serif clamp(26px,3.5vw,34px)`) + 14px muted sub — add per-tab sub lines per mockup titles map (:387-396), i18n'd, with the home tab using the greeting as today.
- [ ] **Step 3:** Toast include stays (`templates/dashboard/partials/toast` — restyle it in this task to match 3.4's card if it differs from the footer toast; one recipe).
- [ ] **Step 4:** Verify: all 8 tabs reachable, invalid `?tab=` → home, logged-out → redirect. Suite + build → commit: `feat(theme): dashboard app shell (Helder Tij)`

`Tier: B — shell markup; auth gate + allow-list copied verbatim (no logic change). no unit test: Tier B. Risk not covered: gate regression — covered by phase-gate browser pass F9 (E2/E3) before sign-off.`

### Task 7.2: Sidebar with collapsible rail (Tier A factory)

**Files:** Modify: `web/app/themes/stridence/templates/dashboard/nav-sidebar.php`, `web/app/themes/stridence/src/main.js` · Test: `web/app/themes/stridence/src/sidebar-rail.test.js`
**Mockup:** `Dashboard - Mijn account.dc.html` :23-72; Componenten :326-352.

- [ ] **Step 1 (RED):** Vitest for `sidebarRail`: (a) default expanded when no stored value; (b) `toggle()` flips + persists `'1'`/`'0'` to localStorage key `stride-rail`; (c) restores collapsed from storage; (d) **negative:** garbage stored value (`'banana'`) → treated as expanded, no throw. Run → FAIL.
- [ ] **Step 2 (GREEN):**
```js
Alpine.data('sidebarRail', () => ({
  collapsed: false,
  init() { this.collapsed = localStorage.getItem('stride-rail') === '1'; },
  toggle() {
    this.collapsed = !this.collapsed;
    localStorage.setItem('stride-rail', this.collapsed ? '1' : '0');
  },
}));
```
Run ×3 → green.
- [ ] **Step 3:** Rebuild `nav-sidebar.php`: `lg:flex` only; expanded (240px, white, right hairline, `sticky top-0 h-screen p-5 px-3.5`): logo row + `«` collapse button (aria-label "Zijbalk inklappen"); primary group (4 items: icon tile + 14px label; active `bg-badge-online-bg text-badge-online-text font-bold` w/ primary icon, inactive muted w/ hover `bg-surface-alt`); divider; utility group (3 items; meldingen badge pill `bg-accent text-white` when `unread_count > 0`); bottom: divider + profile row (34px initials circle + name + org line) linking to `?tab=profiel`. Collapsed rail (56px): `»` expand button, icon-only 38px squares with `title` attrs, badge → 9px accent dot (ring offset white), avatar bottom. Both variants server-rendered, toggled by `x-show`/`:class` on the `sidebarRail` state; icons via `stridence_icon()` (Lucide-style, stroke 1.75 per sheet note).
- [ ] **Step 4:** Real nav stays `<a href="?tab=…">` links (server navigation, locked decision) — Alpine only handles collapse. Active state from `$current_tab` server-side.
- [ ] **Step 5:** Vitest ×3 + suite + build → commit: `feat(theme): dashboard sidebar with collapsible rail`

`Tier: A for the sidebarRail factory (RED-first incl. corrupted-storage negative). Template: Tier B.`
`Risk not covered: persistence across real page loads — deferred to /shakeout F9.`

### Task 7.3: Mobile top bar + nav chips (replaces bottom bar)

**Files:** Rewrite: `web/app/themes/stridence/templates/dashboard/nav-mobile.php` · Modify: `web/app/themes/stridence/page-mijn-account.php` (include position), `footer.php` (dashboard-hide rule re-check)
**Mockup:** `Dashboard - Mijn account.dc.html` :77-90.

- [ ] **Step 1:** Rewrite `nav-mobile.php` (single include site — finding #2): `lg:hidden sticky top-0 z-20 bg-surface-card` hairline bottom; row 1: logo + avatar-initials (→ `?tab=profiel`); row 2: horizontally scrollable chip row (`flex gap-2 overflow-x-auto py-3`, no scrollbar via existing utility or `[-ms-overflow-style:none]` pattern): one chip per all 7 tabs + profiel reachable via avatar — chips are `<a href="?tab=…">` pills (active `bg-primary text-white`, inactive `bg-surface-alt text-text-muted`), meldingen chip carries accent count pill. Active chip scrolled into view via tiny inline `x-init` (`$el.scrollIntoView({inline:'center', block:'nearest'})` on the active chip) — no new factory.
- [ ] **Step 2:** Move the include to the TOP of the main column in `page-mijn-account.php` (was bottom). Delete the old bottom-bar markup entirely. Re-check `footer.php`'s `hidden lg:block` rule for the dashboard template — bottom bar gone, so decide: keep footer hidden on mobile dashboard (design shows no footer on dashboard at all; `get_footer('dashboard')` already minimal — read it and keep its behavior).
- [ ] **Step 3:** Browser at <1024px: chips scroll, active centered, all tabs reachable, no bottom bar remnants, toast no longer needs `bottom-20` mobile offset (footer toast container: adjust the `bottom-20 lg:bottom-6` offset since the bottom bar is gone — `bottom-6` everywhere).
- [ ] **Step 4:** Suite + build → commit: `feat(theme): dashboard mobile top bar + nav chips`

`Tier: B — markup + one-line x-init; navigation is server links. no unit test: Tier B.`

**Phase 7 integration gate:** suites + Vitest + build · browser F9 full: 8 tabs, deep-link, invalid tab, logged-out redirect, rail collapse persists across reload, mobile chips at 375px width, unread badge in all three nav variants.

`── REVIEW GATE ── (tier: STANDARD — shell + nav rebuild; auth-gate verbatim-preservation is the review focus; no 1a surface)`

---

## Phase 8 — Dashboard tab content

### Task 8.1: Home tab

**Files:** Modify: `web/app/themes/stridence/templates/dashboard/tab-home.php`, `templates/dashboard/partials/hero-action.php`, `partials/stat-cards.php`, `partials/action-items.php`, `partials/panel-enrollment.php`, `partials/completion-checklist.php`
**Mockup:** `Dashboard - Mijn account.dc.html` :102-171.

- [ ] **Step 1:** Read all partials + `$home_data` shape first. Hero next-step band: `bg-badge-online-bg rounded-2xl` w/ uppercase eyebrow (`text-badge-online-text`), 19px/700 title, sub, primary CTA — fed by existing hero-action data; empty-enrollments variant keeps existing behavior, restyled.
- [ ] **Step 2:** Stat cards: white `rounded-[14px] shadow-card p-[18px] px-5`, 13px/600 muted label, 30px/800 tabular value, 12px context line (success/warning colored where the partial already encodes it).
- [ ] **Step 3:** "Acties nodig" card: white card w/ 17px/700 title + segmented control per sheet (:86-91): `inline-flex bg-surface-alt rounded-[11px] p-1`, active segment white bg + shadow-xs + bold + primary count pill, inactive muted + `bg-[#DDE1E7]`-equivalent pill (`bg-border-soft`). Keep the existing 3-tab Alpine state + current labels (locked decision). Action rows: `bg-surface rounded-xl` w/ title/sub + small primary CTA.
- [ ] **Step 4:** Enrollment panels grid (`minmax(320px,1fr)`): per mockup :144-169 — badges, title, next-session block, checklist well (`bg-surface rounded-xl`: ✓ done rows + hollow-circle open rows w/ "Invullen →" links) / online variant w/ progress bar + next-module + "Ga verder". Reuse course-card/checklist partials restyled.
- [ ] **Step 5:** Verify vs seed data incl. zero-state. Suite + build → commit: `feat(theme): dashboard home tab (Helder Tij)`

`Tier: B — presentational over existing data. no unit test: Tier B. Risk not covered: empty/zero variants — /shakeout F10 (E1).`

### Task 8.2: Inschrijvingen + Trajecten tabs

**Files:** Modify: `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php`, `tab-trajecten.php`
**Mockup:** :173-231.

- [ ] **Step 1:** Inschrijvingen: row cards (white `rounded-2xl shadow-card p-[22px] px-6`, badges + title + meta/progress, right action column — "1 actie open" warning label + ghost "Bekijk details" / primary "Ga verder" / cancelled muted variant w/ "Bekijk alternatieven"). Keep existing data + links; cancelled rows `opacity-80`.
- [ ] **Step 2:** Trajecten: card per trajectory: 72px ring + Traject badge + title + "X van Y onderdelen · keuzemodule status" + primary "Open traject" (→ enrolled view); parts checklist rows (done ✓ / active tinted / elective outlined w/ "Maak je keuze" mini-CTA). Existing data only.
- [ ] **Step 3:** Empty states (3.3) both tabs. Verify. Suite + build → commit: `feat(theme): dashboard inschrijvingen + trajecten tabs`

`Tier: B — presentational. no unit test: Tier B.`

### Task 8.3: Offertes + Certificaten + Downloads tabs

**Files:** Modify: `web/app/themes/stridence/templates/dashboard/tab-offertes.php`, `tab-certificaten.php`, `tab-downloads.php`
**Mockup:** :233-271.

- [ ] **Step 1:** Offertes: row card — "Offerte #X" + status badge (few/warning pair for wacht-op-goedkeuring), meta line w/ strong total (existing `stride_format_money`), actions: ghost "Herinner werkgever" (existing action + toast — keep its dispatch payload) + primary "Bekijk offerte". Keep all existing action wiring.
- [ ] **Step 2:** Certificaten: rows w/ 38px ✓ tile (badge-online pair), title + "behaald op {date}", ghost "Download PDF" (existing download action). Downloads: same recipe w/ PDF tile (`bg-surface-alt`) + meta line.
- [ ] **Step 3:** Empty states. Verify incl. one real download + one herinner action (toast). Suite + build → commit: `feat(theme): dashboard offertes/certificaten/downloads tabs`

`Tier: B — presentational; actions reused unchanged. no unit test: Tier B. Risk not covered: action failure paths — /shakeout F12/F13 (E6).`

### Task 8.4: Meldingen + Profiel tabs

**Files:** Modify: `web/app/themes/stridence/templates/dashboard/tab-meldingen.php`, `templates/dashboard/partials/notification-item.php`, `tab-profiel.php`
**Mockup:** :273-319; notification recipe also Componenten :355-367.

- [ ] **Step 1:** Meldingen: "Alles als gelezen markeren" text button (primary, right-aligned; existing action); notification rows — unread: `bg-accent-subtle/60 rounded-xl` + 8px accent dot + 700 title; read: white + inset hairline + 600 muted; right time label faint. Existing unread logic untouched.
- [ ] **Step 2:** Profiel: two cards max-w-[720px] — "Persoonlijke gegevens" (+ "Zichtbaar op je attesten…" sub) and "Facturatiegegevens" (+ sub), fields in `minmax(220px,1fr)` grid using the 2.3 field recipes. **Keep the existing save mechanism exactly** (read tab-profiel.php first — whether inlineEdit per-field or a section save; restyle, don't rewire). Save button primary + success toast (existing dispatch). Personal vs billing meta separation respected (CLAUDE.md: `organisation` ≠ `billing_company` — labels must keep mapping to the existing fields, no field renames).
- [ ] **Step 3:** Verify save round-trip + mark-all-read in browser. Suite + build → commit: `feat(theme): dashboard meldingen + profiel tabs`

`Tier: B — presentational; save flows reused. no unit test: Tier B. Risk not covered: double-save / failure — /shakeout F14/F15 (E4/E6).`

**Phase 8 integration gate:** suites + Vitest + build · browser F10-F15 happy paths + each tab's E1 empty state (re-seed or use a fresh seed user).

`── REVIEW GATE ── (tier: STANDARD — 4 tasks, content restyles over existing data/actions; no 1a surface)`

---

## Phase 9 — Marketing pages

### Task 9.1: Homepage rebuild

**Files:** Modify: `web/app/themes/stridence/front-page.php`
**Mockup:** `Homepage.dc.html`.

- [ ] **Step 1:** Read current front-page.php (note any existing dynamic sections to preserve data-wise). Rebuild sections in order: **Hero** (relative, two decorative blobs [`bg-badge-online-bg` 420px / `bg-accent-subtle` 380px circles, absolute, overflow-hidden on page], korenbloem uppercase eyebrow, Newsreader **light (300)** h1 `clamp(44px,7vw,84px)` w/ italic `<em>`, 16-19px sub max-w-[560px], primary lg CTA "Bekijk het aanbod" + ghost "Opleiding op maat" → contact). Copy: PLACEHOLDERS (i18n'd, field-inventory).
- [ ] **Step 2:** **Mode selector** ("Hoe wil je leren?" serif h2): 3 link cards w/ the decorative motifs per mockup :55-79 (trajectory dots strip korenbloem, klassikaal squares, online bars — small inline spans, token colors), 19px/700 title, copy, footer count + "Ontdek →". Counts COMPUTED from `stridence_catalog_items('klassikaal')` / `('online')` counts + trajectory count (existing helpers only — if a count isn't cheaply available, render the label without a count rather than a fake number).
- [ ] **Step 3:** **Binnenkort van start** band (`bg-surface-alt`): serif h2 + "Volledig aanbod →" link; 3 cards via the INV-7 prefetch + `stridence_catalog_render_cards` path limited to 3 upcoming items (reuse existing helper signatures — no new query shapes; if a "limit" arg doesn't exist, slice the items list before rendering, exactly like page-klassikaal.php does). E1: band hides (or shows empty state) when zero upcoming.
- [ ] **Step 4:** **Waarom Stride**: eyebrow + serif heading + copy + stats trio (30px/800 values) — ALL PLACEHOLDERS → field-inventory; photo slot: `aspect-[4/3] rounded-[20px]` placeholder div w/ i18n'd label (mockup's repeating-gradient pattern as CSS). **Closing CTA**: `bg-primary rounded-3xl` band w/ white serif light heading, white/85 copy, white CTA "Vraag een offerte" → contact. PLACEHOLDERS.
- [ ] **Step 5:** Verify F1 (incl. E1 with unseeded DB if feasible, else by temporarily filtering). Suite + build → commit: `feat(theme): Helder Tij homepage`

`Tier: B — presentational; counts via existing helpers. no unit test: Tier B. Risk not covered: zero-data render — /shakeout F1 (E1).`

### Task 9.2: Contact page template

**Files:** Create: `web/app/themes/stridence/page-contact.php` (Template Name: Contact — verify first whether a contact page template/page already exists and adapt rather than duplicate)
**Mockup:** `Contact.dc.html`.

- [ ] **Step 1:** Check how `/contact/` renders today (page.php + content? FluentForms shortcode?). The new template: header band (serif "Zeg ons gedag" + intro — PLACEHOLDERS), two-column content: LEFT = persons cluster (overlapping initials circles + blurb — PLACEHOLDER), info card (Bezoek ons / Bel of mail / Facturatie blocks w/ uppercase 11px labels, hairline dividers — PLACEHOLDERS), map slot (`aspect-video rounded-2xl` placeholder); RIGHT = white form card (`shadow-elevated rounded-2xl`) titled "Stuur ons een bericht" rendering **`the_content()`** so the existing page form (FluentForms or other) keeps its handler — style its fields via the 2.3 recipes scoped under the card (CSS targeting the form plugin's classes goes in `components.css`, token-only).
- [ ] **Step 2:** NO new form processor (security block). All placeholder strings i18n'd + field-inventory rows.
- [ ] **Step 3:** Verify F17 (form renders + submits via its existing handler). Suite + build → commit: `feat(theme): Helder Tij contact page`

`Tier: B — layout host for existing form. no unit test: Tier B.`

### Task 9.3: Over ons page template

**Files:** Create: `web/app/themes/stridence/page-over-ons.php` (Template Name: Over ons — same pre-check as 9.2)
**Mockup:** `Over ons.dc.html`.

- [ ] **Step 1:** Editorial hero (eyebrow, serif light h1 `clamp(38px,6vw,68px)`, serif lede) → long-read column (max-w-[760px], 17px/1.75): render `the_content()` for the prose; structured blocks below as template sections w/ PLACEHOLDERS: pull-quote (3px primary rule + serif italic), 21:9 photo slot, "Waar we voor staan" serif h2 + 3 value cards, "Het team" grid (`minmax(160px,1fr)`, initials-circle cards), closing CTA card (`bg-surface-alt rounded-[20px]`, serif heading + primary CTA → contact).
- [ ] **Step 2:** All copy PLACEHOLDERS i18n'd + field-inventory rows. Verify F18. Suite + build → commit: `feat(theme): Helder Tij over-ons page`

`Tier: B — static editorial template. no unit test: Tier B.`

**Phase 9 integration gate:** suites + build · browser F1/F17/F18.

`── REVIEW GATE ── (tier: STANDARD — marketing pages; placeholder/i18n discipline + INV-7 featured-band path are the review focus)`

---

## Phase 10 — Out-of-design surfaces, LearnDash skin, verification

### Task 10.1: Forms restyle (out-of-design surfaces — keep layouts + handlers)

**Files:** Modify: `web/app/themes/stridence/templates/forms/enrollment.php`, `templates/forms/enrollment/` (read dir), `enrollment.js` (class strings only), `interest.php`, `intake.php`, `evaluation.php`, `waitlist.php`, `stage-form.php`, `templates/forms/completion/task-*.php` (4 files)

- [ ] **Step 1:** Sweep every form template: swap field/label/button/badge/alert markup to the 2.3/2.4 recipes. **Structure, step logic, validation wiring, action names, hidden fields: byte-identical.** `enrollment.js`: only class-name strings may change; logic untouched (its toast dispatches verified in 3.4).
- [ ] **Step 2:** Drive one full enrollment in the browser (seed user, F16) — every step renders, validation error shows the design error state, submit succeeds.
- [ ] **Step 3:** Suite + build → commit: `feat(theme): Helder Tij form surfaces (recipes only, behavior frozen)`

`Tier: B — class-only changes; behavior frozen. no unit test: Tier B. Risk not covered: full multi-step regression — /shakeout F16 (all six edges).`

### Task 10.2: Misc surfaces

**Files:** Modify: `web/app/themes/stridence/404.php`, `page.php`, `index.php`, `archive-sfwd-courses.php`, `single-sfwd-lessons.php`

- [ ] **Step 1:** Restyle each to the band/typography/card recipes (404 uses the error-state partial). `single-sfwd-lessons.php`: wrapper styling only — LD content via `the_content()` (INV-6); the lesson chrome colors come from 10.3.
- [ ] **Step 2:** FAQ/agenda/login: regular pages/WP screens inheriting base styles — verify they look coherent; only add CSS if broken. **Emails: confirm no theme-owned email templates exist (finding #10) — if any are found in the theme, retoken them; stride-core-owned ones are OUT OF SCOPE (record follow-up in field-inventory doc).**
- [ ] **Step 3:** Suite + build → commit: `feat(theme): misc surfaces restyle`

`Tier: B. no unit test: Tier B.`

### Task 10.3: LearnDash skin pass (5-layer gotcha)

**Files:** Modify: `web/app/themes/stridence/src/css/learndash.css`

- [ ] **Step 1:** Walk the 5 layers from `gotcha_learndash_css_skin`: (1) token values — now v2 via `tokens.css` import chain (base.css :13); (2) hardcoded hex — only the two `var(--ld-focus-header-color, #333)` fallbacks (:397, :446): update fallback to `#292C31` (cool ink); sweep for any LD CSS custom-prop assignments carrying v1 hex and re-derive from v2; (3) enqueue deps (`learndash-front`/`ld30-modern`) — verify unchanged in the enqueue site (read `AssetHooks.php`/functions.php, change nothing unless broken); (4) `:root` scope — focus-mode props must be set at `:root`, not `.learndash-wrapper` (verify existing structure); (5) hex format — LD props that demand hex stay hex (derived from v2 values, comment-linked to the token name).
- [ ] **Step 2:** Browser: course page + lesson + focus mode render in Helder Tij colors. Restyle the lesson-list rows to the `Detail - Online opleiding` recipe via CSS overrides where the markup is LD's.
- [ ] **Step 3:** Suite + build → commit: `feat(theme): LearnDash skin → Helder Tij tokens`

`Tier: B — CSS skin. no unit test: Tier B. Risk not covered: focus-mode visual — /shakeout F6.`

### Task 10.4: VAD override smoke check + field-inventory doc + drift sweeps

**Files:** Create: `docs/plans/2026-06-11-helder-tij-field-inventory.md` · no code changes expected

- [ ] **Step 1 (VAD smoke — locked decision 1):** Activate `stride-client-vad` locally (mu-plugin present?  — check `web/app/mu-plugins/` for `stride-client-*`; activate per its own README/apply script). Verify: (a) its template overrides still WIN via `NTDST_Template_Loader::addPath()` priority; (b) `stridence_font_url` filter still overrides the new default; (c) its LD skin layers over 10.3; (d) block patterns render; (e) `?tab=` URLs work. Deactivate after. Record pass/fail per item in the task report — any failure is a bug to fix in THIS branch (base theme must stay override-friendly).
- [ ] **Step 2 (field inventory):** Write `docs/plans/2026-06-11-helder-tij-field-inventory.md` from the table in this plan + every placeholder implementers appended during execution. Columns: field name · surface (template:line) · mockup source · suggested CPT/source · placeholder used. Include the email-restyle follow-up.
- [ ] **Step 3 (drift sweeps):** SSA-5 (`grep -rn "var(--color" …` — all hits resolve in v2 tokens); SSA-6 (zero remaining `editionDetailTabs`/`trajectoryDetailTabs`/dead `courseDetailTabs` usages; dead factories deleted); SSA-1 re-check; `grep -rn "Plus Jakarta\|Manrope" web/app/themes/stridence` → only historical comments at most; stride-core untouched: `git diff --stat staging.. -- web/app/mu-plugins/` → empty.
- [ ] **Step 4:** Commit: `docs: Helder Tij field inventory + override verification`

`Tier: B — verification + docs. no unit test: Tier B.`

**Phase 10 integration gate (final):** FULL regression — `ddev exec vendor/bin/phpunit --testsuite Unit` + `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist` + `npm run test:unit` + `npm run build` all green · browser sweep of the full Acceptance-flows matrix (F1-F18) at desktop + 375px · console clean on every screen.

`── REVIEW GATE ── (tier: STANDARD — verification cluster; doc deliverable is LIGHT but the cluster includes form-surface code from 10.1/10.2, so STANDARD governs)`

---

## After the last gate

1. Run `/shakeout` (spec-complete gate): test-effectiveness audit + feature-acceptance VERIFY of the F1-F18 matrix (real browser, manifest pass/fail/not-reachable) + reviewer panel on the full branch diff at tier STANDARD.
2. `superpowers:finishing-a-development-branch` — PR `feature/helder-tij-redesign` → `staging`. Language files still untouched.

## Smoke test checklist (for Stefan, post-merge to staging)

- [ ] Visit `/` — Helder Tij hero, teal/korenbloem palette, Hanken Grotesk + Newsreader, no console errors
- [ ] Catalog: filter a theme chip, empty theme shows design empty state, Toon meer loads styled cards
- [ ] An edition: four tabs switch, `#praktisch` deep-link works, sticky CTA on desktop, bottom CTA on mobile
- [ ] `/mijn-account/` (seed_student1@seed.test / seedpass123): sidebar collapse persists after reload; on mobile the top chip bar replaces the old bottom bar; all 8 tabs render
- [ ] Trajectory enrolled view: ring in header, journey timeline, make + confirm a keuze → toast
- [ ] Enroll in a seeded edition end-to-end — behavior identical to before, new field styles
- [ ] Activate VAD client plugin — its branding still overrides the base
- [ ] `/contact/` — header band renders with escaped page title, info card (address/phone/email/facturatie) visible, map placeholder present, right column renders `the_content()` form seam (no layout breaks)
- [ ] `/over-ons/` — editorial hero (eyebrow + serif h1 + italic lede), long-read prose seam, pull-quote with left accent, 21:9 photo slot placeholder, values grid (3 cards), team grid (4 cards), closing CTA → `/contact/`

---

## Self-review (writing-plans checklist — done at authoring)

1. **Spec coverage:** every mockup has an owning task (Componenten → P2/P3; Homepage → 9.1; 3 catalogs → P4; Detail-Editie → 5.1/5.2; Detail-Online → 5.3; Detail-Traject → P6; Dashboard → P7/P8; Contact → 9.2; Over ons → 9.3); every scope decision (1-4) and known layout delta has a task; field-inventory + VAD check + LD skin present (10.x).
2. **Placeholder scan:** the only intentional "placeholders" are CONTENT placeholders mandated by Stefan's stub-and-document rule — each is i18n'd and tracked in the field-inventory deliverable. No plan-level TBDs remain.
3. **Type consistency:** `contentTabs(tabs, initial)` and `sidebarRail` signatures used identically at definition (5.1/7.2) and consumption (5.1, 6.1, 7.2); toast payload `{message, type, sub?}` consistent across 3.4 and all dispatch sites.
