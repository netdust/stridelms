> **✅ COMPLETED & MERGED 2026-06-30** — merge commit `2964339e` on `main`. All clusters done: Cluster 1 (dead code) + Cluster 2 (course-card consolidation) landed via a parallel session; Cluster 3 (3A/3B/3C business-logic extraction) done on `refactor/stridence-cleanup` (12 commits, merged). All sub-clusters FULL-tier reviewed CLEAN. Suites on the merge commit: Unit 1123, Integration 692 (0 fail), Vitest 13, PHPStan clean. Feature-acceptance browser-driven. **Key deviation from this plan:** the archive teaser was NOT converged to the canonical rule (Stefan's product ruling — the teaser's no-date-window/no-dateless behavior is deliberate); Task 3.3 de-duplicated it WITHOUT behavior change (parity-guarded byte-identical). O5/O3 org moves DEFERRED. Two low-severity non-blockers carried: `warnIfCapped` has no test (log-only side-effect), and B7's deadline boundary is now one-tick `<` (open) per the service's canonical rule.

# Stridence theme remediation — gated implementation plan

**Date:** 2026-06-30
**Class:** B (executing an audited, ground-truthed punch list)
**Branch:** `refactor/stridence-cleanup`
**Source audit:** `~/.claude/projects/-home-ntdst-Sites-stride/memory/audit_stridence_theme_2026_06_30.md`
**Author:** planner persona (harnessed-development Stage 1)

Runs on its own branch so the full suite (unit + integration + acceptance + theme Vitest) can confirm nothing broke before merge. The real prize is **Cluster 3** (business-logic extraction into stride-core); Clusters 1–2 are low-risk hygiene that clear the field for it.

---

## Gate dispatch — which plan-time gates fired, and why

| Gate | Fires? | Trigger / reason |
|---|---|---|
| **1a Threat-modeling** | **NO** (with one inspected near-miss) | The prompt flagged "CatalogEndpoint relocation touches a public REST endpoint." Ground-truthed: `CatalogEndpoint` is **not** a `register_rest_route` route — it is an `ntdst/api_data/stride_catalog_page` **filter** (INV-2: nonce verified once by the framework at `Endpoints.php`), already on the `public_actions` allow-list **by design** (public catalog), already string-type-guarded against attacker-shaped JSON (`is_string(...)` + `sanitize_key` + `absint`). The work **repoints its data source** (theme helper → stride-core service); it adds **no new route, no new auth surface, no new untrusted-input parsing, no new outbound request, no new capability.** Running the 1a trigger list literally: user-controlled URL — no; auth/session/token — no; untrusted parsing — no new parser; BYOK — no; multi-tenancy boundary — no (catalog is public to all). **No trigger matches → 1a does not fire.** The endpoint's existing input-hardening (`INF-2` string guards) is **preserved verbatim** and must not regress — see Task 3.3. |
| **1b Architecture-invariants** | **YES** | Cluster 3 touches three named convergence points. **INV-3** (data reached through the per-domain Repository; field names from each CPT's `getFields()`, no central registry, no hardcoded `_ntdst_*`). **INV-7** (display status via `getEffectiveStatus()` — and `helpers/catalog.php`'s batch pre-pass is *named in INV-7* as the catalog-card convergence point). **INV-5** (plugin never calls theme; the extraction moves logic the *correct* direction — theme→core — but must not make core call back into the theme). Doc exists; cited inline per task. No new invariant authored. |
| **1g Feature-acceptance** | **YES** | Cluster 3 changes the behaviour-bearing render path of user-facing surfaces (catalog listing, single-course CTA, course header/editions list, trajectory dashboard). Matrix below. |
| **WP plan-requirements (stack override)** | **YES** | WordPress project; new framework methods on `EditionService`/`EditionRepository`, and a touched `api_data` data-flow. Blocks below. |
| **1c Spec-premise ground-truth** | **DONE** | Read `helpers/catalog.php`, `EditionService`, `EditionRepository`, `CatalogEndpoint`, `EnrollmentRouter`, `ARCHITECTURE-INVARIANTS.md`, and the B4/B5 inline sites against source before this plan shipped. Findings folded in below — most consequential: the audit's assumed-missing methods are confirmed missing, and B4's selection rule is **not identical** to the catalog pre-pass rule (reconciliation is a design task, not a move). |

---

## Golden path: content-type read-extension (deviations named)

This is **not** a new CPT/feature build — it is a *read-path consolidation* of existing `vad_edition` / `sfwd-courses` data into the existing repository+service layer. The nearest golden-path slice is `content-type-feature.md` (CPT → Repository → Service → frontend), but only its **read** half applies.

- Built to the read half of `golden-paths/content-type-feature.md` — Repository owns the query shape, Service owns the policy/aggregation, the theme template is a pure renderer.
- **Named deviations from the slice (justified):**
  1. **No new CPT, no new field registration, no Router.** Extension only — `vad_edition`/`sfwd-courses` and their `getFields()` are unchanged. The work *moves* query+policy code from theme into the existing `EditionRepository`/`EditionService`.
  2. **One frontend data-flow already exists and is touched, not created** — `CatalogEndpoint` (`api_data` filter). It keeps its current security shape (nonce-by-framework INV-2, public-actions allow-list, INF-2 string guards); only its *data enumeration* is repointed to the new service method.
  3. **The new catalog query method returns a light id+slug list, not WP_Posts** — preserving the existing batch-prepass contract (`stridence_catalog_edition_items_from_ids` shape) so the INV-7 prepass and the pure-renderer partials are unchanged.

---

## WP security requirements (per data-flow)

Only **one** user-facing data-flow is touched; nothing new is introduced.

- [ ] `api_data` filter `stride_catalog_page` (`CatalogEndpoint::handleCatalogPage`): **validate** — `catalog` is `in_array(..., self::CATALOGS, true)`, `page` via `absint`/`max(1,…)`, `theme` via `sanitize_key` — **PRESERVE VERBATIM, do not regress** (INF-2 string guards against attacker-shaped JSON). **sanitize** — inputs already sanitized as above; the repointed service receives only the already-validated `$catalog` string and the slice page — no new raw input reaches the new method. **escape** — output is card HTML built by the existing pure-renderer partials (esc at the sink inside the partials, unchanged). **authorize** — n/a by design: public read-only catalog, anonymous nonces permitted via the `public_actions` allow-list (existing, unchanged). The repointing changes the *source of the items array*, not any pillar.
- No other flow: the new `EditionRepository::findCatalogEligible*` / `EditionService` catalog method are **internal** (called by the theme prepass + the endpoint), not a route. No new nonce/cap/sanitize surface.

> **escape n/a justification:** the new service methods return data structures (id lists, struct arrays), never echo. All HTML escaping stays in the theme partials at the sink.

---

## ntdst-core layering requirements (Cluster 3 new methods)

- [ ] **Data access through a Repository** (INV-3) — the raw `WP_Query`/`get_posts` against `vad_edition`/`sfwd-courses` and the `stridence_catalog_date_window_meta_query()` builder move into **`EditionRepository`** (new query methods). Meta keys derive from `$this->getMetaPrefix()`, never hardcoded `_ntdst_*`. Field names come from `EditionCPT::getFields()` (INV-3: no central registry — confirmed, `ARCHITECTURE-INVARIANTS.md` INV-3 says the CLAUDE.md FieldRegistry reference is stale).
- [ ] **No pure pass-through Service methods** (`lesson_pure_passthrough_is_drift`) — `EditionService` catalog/aggregation methods must add real policy/transformation (eligibility rule, band-ordering hand-off, price aggregation, primary-edition ranking, public-visibility partition), not `return $this->repo->X()`. The eligibility *query* lives in the repo; the *policy that composes it* (format-exclusion, dateless treatment, status filtering via `getEffectiveStatuses`) lives in the service.
- [ ] **No swallowed `WP_Error`** (INV-4) — new methods return typed values; any failure path returns/bubbles `WP_Error`, never `null`/`false` silently. (Note B5's existing `catch (\Throwable) { // ignore }` for optional price — the extracted method keeps price-optional semantics but must not swallow a *non-price* error.)
- [ ] **Status read through `getEffectiveStatus()`** (INV-7) — the extracted catalog/primary-edition/visibility logic reads effective status via `getEffectiveStatuses()`/`getEffectiveStatus()`, never raw stored `status` meta for a gate/display decision. The existing prepass already does this correctly — **preserve**.
- [ ] **Correct module layering** — new code lands in `Modules/Edition/` (`EditionRepository`, `EditionService`). No theme code added to stride-core; no stride-core code calling `stridence_*` (INV-5).
- [ ] **Data API vocabulary** — n/a (no new `ntdst_data()` writes; reads use existing repo accessors / `getField` / `findFields`).

> **Convergence contract.** These blocks + the matrix below are the convergence target for `/code-review` and `ntdst-drift-reviewer` at the Cluster-3 review gate and at shake-out. Reviewers verify the diff against the named golden-path deviations + pillars + INV-3/5/7 + the acceptance matrix — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## Acceptance flows (1g) — surfaces whose render path changes in Cluster 3

Edges column mandatory. Six edge classes: empty/zero · denied/guest actor · wrong-order/re-entry · concurrent/double · boundary value · mid-flow failure. Driven at shake-out via the theme Playwright/Chrome against the running dev server + the seeded DB (`scripts/seed.php`), guests via logged-out session.

| # | Flow (intended use) | Surface / route | Tasks | Edges (mandatory) |
|---|---|---|---|---|
| AF-1 | Visitor browses the classroom catalog and sees one card per eligible edition, dateless interest editions surfaced on page 1, "Toon meer" loads the next slice | `/opleidingen` (klassikaal) + `stride_catalog_page` endpoint | B1, B2 | **empty:** no eligible editions → empty-state, no fatal · **guest:** logged-out sees cards w/o enrolled badges · **wrong-order:** page=999 returns empty slice not error · **concurrent:** n/a (read-only) · **boundary:** exactly `STRIDENCE_CATALOG_PER_PAGE` items → `has_more=false`; dateless-only set (band B alone) renders on page 1 · **mid-flow failure:** a catalog edition whose course was just trashed → card suppressed (INF-1 guard preserved) |
| AF-2 | Visitor browses the online catalog and sees online editions **and** pure-LD online courses that never had an edition | `/online` + `stride_catalog_page` | B1, B2 | **empty:** no online courses → empty-state · **guest:** ok · **wrong-order:** stale theme filter slug → filtered to empty, no error · **concurrent:** n/a · **boundary:** course with editions AND pure-LD course both present → no duplicate card · **mid-flow failure:** online course with a draft edition → not listed |
| AF-3 | Visitor lands on the course archive (the SEO/index surface) and the eligible set matches the catalog exactly (B3 fork closed) | `archive-sfwd-courses.php` | B3 | **empty:** no published courses → archive empty-state · **guest:** ok · **wrong-order:** n/a · **concurrent:** n/a · **boundary:** classroom-teaser course that previously slipped the "no date window" fork now obeys the **same** eligibility rule as B1 (the divergence is the bug being fixed — assert parity) · **mid-flow failure:** course-less edition handled |
| AF-4 | Visitor on a single course page sees the correct CTA driven by the primary edition (enrollable cohort wins) | `single-sfwd-courses.php` (online branch) | B4 | **empty:** no active edition → "Niet beschikbaar", no fatal · **guest:** sees enroll CTA, no enrolled state · **wrong-order:** n/a · **concurrent:** n/a · **boundary:** multi-cohort e-learning with one running + one open cohort → CTA picks the **open** one (the exact bug B4's rule fixes — assert) · **mid-flow failure:** primary edition status flips between prepass and CTA → falls back to `getEffectiveStatus` single read |
| AF-5 | Visitor on a single course page (in-person) sees the header meta line: next edition date, upcoming count, price range | `templates/course/header.php` | B5 | **empty:** in-person course with no upcoming editions → no date/price line, no fatal · **guest:** ok · **wrong-order:** n/a · **concurrent:** n/a · **boundary:** all editions in the past → `upcoming_count=0`, no min/max; one edition with no price → excluded from range, others still aggregate · **mid-flow failure:** price lookup throws → price omitted, header still renders (preserve the existing optional-price `catch`) |
| AF-6 | Visitor on a single course page sees the publicly-visible editions list partitioned into upcoming/past | `templates/course/editions-list.php` | B6 | **empty:** no publicly-visible editions → list hidden/empty-state · **guest:** sees public set only (no draft/announcement-only leak) · **wrong-order:** n/a · **concurrent:** n/a · **boundary:** edition exactly at the public-visibility threshold renders on the correct side · **mid-flow failure:** edition with effective-status flip filtered consistently |
| AF-7 | Enrolled vs not-enrolled user on the trajectory dashboard sees the choice-window state derived identically to `tab-keuzes` | `templates/trajectory/dashboard.php` | B7 | **empty:** trajectory with no choice groups → no window UI · **guest:** n/a (dashboard is authed) · **wrong-order:** window closed → choices locked, matches `tab-keuzes` exactly (the bug: dashboard copy re-derived the rule and could drift) · **concurrent:** n/a · **boundary:** `opens_at`/`deadline` exactly now → matches `isChoiceWindowOpen()` decision · **mid-flow failure:** missing window meta → `isChoiceWindowOpen()` default path, no fatal |

> **O5 router pages (enrollment-form / completion) are NOT in this matrix** because O5 is recommended **DEFER** (below). If O5 is pulled in, add AF-8 (enrollment form renders post-move) + AF-9 (completion task renders post-move), edges: closed enrollment, wrong-stage task, already-completed re-entry, guest redirect.

---

## Cluster structure & review tiers (1f / 1h)

Three clusters, ordered cheapest-and-safest first so the field is clear before the high-value extraction. Each `── REVIEW GATE ──` is a hard STOP: commit the cluster, run `/integration` on its diff, review at the stated tier, do not start the next cluster until clear.

- **Cluster 1 — Dead code & artifacts** — provisional tier **LIGHT** (deletions + a `.gitignore` line; no behaviour, no 1a surface, no invariant). One generalist `reviewer` pass + "suite still green."
- **Cluster 2 — Organization consolidation** — provisional tier **STANDARD** (behaviour-preserving file moves + loader swaps + 2 stride-core router slug edits if O5 included; multi-file, no 1a surface). 2 finder angles + `code-simplicity-reviewer` + a feature-acceptance browser pass that the moved templates still render.
- **Cluster 3 — Business-logic extraction** — provisional tier **FULL** (touches the **data layer / repository** + the **INV-7 catalog convergence point** + the **public `api_data` endpoint's** data source + effective-status logic). Full finder fan-out + `security-sentinel` (verifies the endpoint's INF-2 guards didn't regress) + `ntdst-drift-reviewer` (INV-3 / pass-through check) + the feature-acceptance browser pass over AF-1…AF-7.

One-way escalation (1h): any finding on a 1a surface in any cluster promotes that cluster to FULL. `/security-review` is **not** independently mandated here because the 1a threat-model gate did **not** fire at plan time (no `## Threat model` exists) — but the Cluster-3 FULL tier already dispatches `security-sentinel` over the endpoint diff as a tier obligation.

---

# CLUSTER 1 — Dead code & stray artifacts

**Provisional review tier: LIGHT.** Pure deletion + gitignore. The only risk is deleting a live caller — every delete is gated on a re-verified zero-caller check.

### Task 1.1 — Delete the dead top-level `partials/progress-bar.php` `[Tier B]`
- **What:** Delete `web/app/themes/stridence/partials/progress-bar.php` (the `attended`/`required` contract). Ground-truthed: only `templates/dashboard/partials/progress-bar.php` (the `percentage` contract) is loaded — by `templates/dashboard/partials/panel-enrollment.php:68` via `stridence_template_part('templates/dashboard/partials/progress-bar', …)`. The top-level one has **zero** callers.
- **Gate before delete:** `grep -rn "partials/progress-bar\b" web/app/themes/stridence --include=*.php` returns only the `dashboard/partials/` path. If anything else appears, STOP.
- **no unit test: Tier B, pure deletion of a zero-caller file** — verification is "grep clean + suite green."

### Task 1.2 — Remove committed test artifacts + gitignore them `[Tier B]`
- **What:** `git rm web/app/themes/stridence/test-results/.last-run.json` (confirmed tracked). Add `test-results/` to `web/app/themes/stridence/.gitignore` (file exists). Delete `web/app/themes/stridence/templates/trajectory/memory/.stop-hook-state.json` and its now-empty `templates/trajectory/memory/` dir (stray hook artifact).
- **Gate:** confirm `templates/trajectory/memory/` contains nothing but the stop-hook dump before `rmdir`.
- **no unit test: Tier B, artifact removal + gitignore — verified by `git status` clean and `git ls-files | grep -E 'test-results|trajectory/memory'` empty.**

### Task 1.3 — Verify-then-delete the dead intake/evaluation shortcode cluster `[Tier B]`
- **What:** Delete the dead duplicate intake/evaluation path. The REAL intake/evaluation flow is the completion-task questionnaire (`templates/forms/completion/task-questionnaire.php` → task-type→`intake`/`evaluation` stage → `QuestionnaireRepository::getGroupsForStage()`, the full stride-core Questionnaire module with admin builder). The dead path:
  - `services/frontend/shortcodes/IntakeShortcodes.php`
  - `services/frontend/shortcodes/EvaluationShortcodes.php`
  - `templates/forms/intake.php`
  - `templates/forms/evaluation.php`
  - `templates/forms/stage-form.php` (ground-truthed: **only** `intake.php` + `evaluation.php` require it — confirmed via `grep -rln "stage-form"`)
  - `functions.php:245-246` (the two `require_once`) **and** `functions.php:252-253` (the two `->register()` calls) — ground-truthed line numbers.
- **MANDATORY pre-delete gate (the delete is conditional on this passing):**
  1. `do_shortcode` / shortcode-string usage: `grep -rn "stride_intake\|stride_evaluation" web/app/themes/stridence` returns only the registration lines being deleted.
  2. **DB placement check at execution time** (shortcodes can live in post content — registration ≠ dead): `ddev exec wp eval "global \$wpdb; echo \$wpdb->get_var(\"SELECT COUNT(*) FROM \$wpdb->posts WHERE post_status='publish' AND (post_content LIKE '%[stride_intake%' OR post_content LIKE '%[stride_evaluation%')\");"` must return `0`. The audit says dev-DB-confirmed-0; **re-run it on the working DB before deleting** — if non-zero, STOP and convert the placement to the completion-task flow first.
- **no unit test: Tier B, dead-code deletion gated on a re-verified zero-placement check** — verification is the two grep/DB gates + suite green.

**Integration gate (Cluster 1):** run `ddev exec vendor/bin/phpunit --testsuite Unit` + `-c phpunit-integration.xml.dist` + the theme Vitest (`cd web/app/themes/stridence && npm run test`) — all green, same counts (deletions touch no tested code). Smoke `/opleidingen`, `/online`, a dashboard enrollment panel (progress bar still renders), and a completion-task intake/evaluation questionnaire (the LIVE flow still works) in the browser.

`── REVIEW GATE ── (tier: LIGHT — deletions + gitignore only, no behaviour change, no 1a surface, no invariant touched)`

---

# CLUSTER 2 — Organization consolidation

**Provisional review tier: STANDARD.** Behaviour-preserving moves + loader swaps. The one elevated bit is O5 (touches stride-core's `EnrollmentRouter` — 2 slug edits); flagged, and O5 is recommended DEFER.

### Task 2.1 — Consolidate the two transposed course-card files (O1 + O4) `[Tier B]`
- **What:** Two transposed files: `partials/card-course.php` (flat catalog card, loaded via `stridence_template_html` at `helpers/catalog.php:485`) vs `templates/components/course-card.php` (expandable dashboard/trajectory card, loaded via raw `get_template_part` at `tab-inschrijvingen.php:229`, `trajectory/course-groups.php:42,75` — ground-truthed). Move the expandable one into `partials/` with a non-transposed name: **`partials/card-course-expandable.php`**. Delete the one-file `templates/components/` dir. Convert the **3** raw `get_template_part('templates/components/course-card', …)` calls to `stridence_template_part('partials/card-course-expandable', …)` so the expandable card is client-override-resolvable like every other partial (O4 folds in here).
- **Caller-doc sweep:** update `helpers/templates.php:91` and `:226` (they reference `templates/components/course-card.php` / `partials/card-course.php` by name) to the new name.
- **Gate:** after the move, `grep -rn "templates/components\|get_template_part" web/app/themes/stridence --include=*.php` shows no remaining `components/course-card` reference and no raw `get_template_part` for the card.
- **no unit test: Tier B, file move + loader-call swap, behaviour-preserving** — verified by: the expandable card still renders on the dashboard inschrijvingen tab and trajectory course-groups (browser), the flat catalog card still renders on `/opleidingen` (browser), suite green.

### Task 2.2 — Fix the CLAUDE.md theme map `[Tier B]`
- **What:** Update the project `CLAUDE.md` theme-structure block (~lines 264-279) to match reality **after** Tasks 2.1 (and O5 if pulled in): remove the listed-but-nonexistent `templates/{invoice,emails,pdf,enrollment}` (those live in stride-core, not the theme), add the real `partials/`, `forms/`, `enrollment/` (unless O5 removes them), `services/frontend/hooks/`. Reflect the new `partials/card-course-expandable.php` and the removed `templates/components/`.
- **no unit test: Tier B, documentation edit** — verified by reading the map against `ls -R` of the theme.

### Task 2.3 (OPTIONAL — recommend DEFER) — Move router-rendered form pages to `templates/pages/` (O5) `[Tier B]`
- **What:** `forms/completion.php` and `enrollment/form.php` are LIVE router-rendered PAGE wrappers — ground-truthed: `EnrollmentRouter.php:123` does `$response->render('enrollment/form')` and `:208` does `->render('forms/completion')` (resolves against theme root). Proposal: move to `templates/pages/enrollment-form.php` + `templates/pages/completion.php`, update the **2** router slugs in `EnrollmentRouter.php`, delete the single-file `forms/` + `enrollment/` top-level dirs (bodies stay under `templates/forms/`).
- **⚠️ Crosses the plugin boundary** (edits stride-core's `EnrollmentRouter`). This is the only Cluster-2 task that touches stride-core.
- **RECOMMENDATION: DEFER.** Reasoning: this is a 2-file move for a 2-slug edit that crosses into stride-core and risks a router 500 on the two most important conversion pages (enrollment + completion) for **pure tidiness** — no duplication is removed, no rule is un-forked. The cost/risk/value ratio is wrong for ship-mode (`feedback_ship_mode`). If pulled in: it becomes a `[Tier B]` move **but** add AF-8/AF-9 to the matrix and run them through the browser (the router render path has no unit coverage), and the cluster tier stays STANDARD (router slug edit is not a 1a surface).

### Task 2.4 (OPTIONAL — recommend DEFER) — Collapse `templates/dashboard/partials/` into `partials/dashboard/` (O3) `[Tier B]`
- **What:** After 2.1 removes `templates/components/`, two partial homes remain (`partials/`, `templates/dashboard/partials/`). O3 proposes collapsing the dashboard one into `partials/dashboard/` — ~9 files + ~9 caller slugs.
- **RECOMMENDATION: DEFER.** Reasoning: large mechanical churn (9 files, ~9 caller-slug edits, every one a chance to mistype a slug into a silent blank render) for zero behaviour gain and zero de-duplication — it just relocates an already-coherent subtree. The transposition that actually *caused bugs* (the two card files, two progress bars) is fixed by 2.1 + 1.1; the remaining "3 homes → 2 homes" is cosmetic. In ship-mode, defer. Revisit if a third generation of dashboard partials starts to drift.

**Integration gate (Cluster 2):** suite green (unit + integration + theme Vitest), unchanged counts. Browser-smoke every moved surface: `/opleidingen` flat card, dashboard inschrijvingen expandable card, trajectory course-groups card. If O5 pulled in: enrollment form page + completion task page render (no router 500).

`── REVIEW GATE ── (tier: STANDARD — behaviour-preserving file moves + loader swaps; O5/O3 deferred; if O5 is pulled in it touches EnrollmentRouter but a router slug edit is not a 1a surface — stays STANDARD)`

---

# CLUSTER 3 — Business-logic extraction into stride-core

**Provisional review tier: FULL.** Touches the data layer (`EditionRepository` query shapes), the INV-7 catalog convergence point, the public `api_data` endpoint's data source, and effective-status logic. B1↔B3 are coupled (same service). Each method is **pure-ish logic → Tier A, RED-first**, with a one-line test contract.

> **Sequencing within the cluster:** the cluster is >4 tasks, so it is split into **three review sub-clusters** with their own gates: **3A** = catalog engine (B1+B3+B2, the coupled core), **3B** = course-page methods (B4+B5+B6), **3C** = the two cheap one-liners (B7+B8+B9). Each sub-cluster ≤3-4 tasks.

> **INV-3 / INV-7 contract for the whole cluster:** the *query* (raw `WP_Query`/`get_posts` + `stridence_catalog_date_window_meta_query`) moves into `EditionRepository` methods that derive meta keys from `$this->getMetaPrefix()`. The *policy* (format-exclusion, dateless treatment, status-filtering via `getEffectiveStatuses`, primary-edition ranking, price aggregation, public-visibility partition) lives in `EditionService`. The theme keeps **only** presentation: `stridence_catalog_render_cards()`, `stridence_catalog_order_into_bands()` (KLASSIKAAL band-ordering is presentation, stays in theme), and the pure-renderer partials. The INV-7 batch prepass (`stridence_prefetch_edition_cards`/`stridence_prefetch_course_cards`) already calls `getEffectiveStatuses()`/`countByEditions()` correctly — **preserve that wiring; do not move the prepass, only its raw enumeration queries.**

## Sub-cluster 3A — Catalog eligibility engine (B1 + B3 + B2)

### Task 3.1 — Add `EditionRepository::findCatalogEligibleIds()` + move the date-window builder `[Tier A]`
- **What:** New repository method(s) that own the raw enumeration currently in `stridence_catalog_klassikaal_items()` / `stridence_catalog_online_items()`: the `WP_Query` against `vad_edition` with the `stridence_catalog_date_window_meta_query()` clauses (active-status IN + the 3-branch dated/end-fallback/dateless OR), `post_status=publish`, `fields=ids`, capped at `STRIDENCE_CATALOG_MAX_ITEMS`, plus the online-course `tax_query` + the pure-LD `courseIdsWithAnyEdition` diff. Meta keys via `$this->getMetaPrefix()`. The builder `stridence_catalog_date_window_meta_query()` becomes a **private** repo helper (the "one builder for all sites" comment is now structurally enforced — it can no longer fork because it has one home).
- **Test contract (Tier A, RED-first):** assert the eligibility predicate — a dated edition inside the 2-day grace is included; a dated edition past the grace is excluded; an `end_date`-missing + `start_date`-in-grace edition is included; a **fully dateless** edition is included; a draft edition / draft-course edition is excluded; the result is capped at `MAX_ITEMS`. (This is the canonical rule the audit says forked — pin it in one test.)
- **INV-3 citation:** new query shape lives in the repo, meta prefix from `getMetaPrefix()`.

### Task 3.2 — Add `EditionService::getCatalogItems(string $catalog)` (policy) + repoint the theme `[Tier A]`
- **What:** New service method that composes the repo enumeration into the light item list the theme prepass expects: applies the **format-exclusion** (online-only courses excluded from klassikaal), the **kind tagging** (`edition` vs pure-LD `course`), and returns the `list<array{kind, edition?, course_id?, themes}>` shape **identical** to today's `stridence_catalog_items()` output (so the prepass + partials are untouched). The theme's `stridence_catalog_items()` / `_klassikaal_items()` / `_online_items()` / `_edition_items_from_ids()` shrink to a thin call into `EditionService::getCatalogItems()` + the **band-ordering** (`stridence_catalog_order_into_bands` stays in the theme — it is KLASSIKAAL presentation, not policy). Preserve `stridence_catalog_warn_if_capped` observability (move into the service or keep as a service-side log).
- **Test contract (Tier A, RED-first):** assert format-exclusion (an online-only-format course's edition does NOT appear in `klassikaal`; appears in `online`); assert a pure-LD online course with no edition appears as a `course` kind in `online`; assert the returned item shape matches the documented `{kind, edition{id,title,course_id,start_date,end_date,venue,price,capacity,status,spots_remaining}, themes}` contract (a characterization test against the current theme output).
- **No pass-through (INV-3 layering):** the service adds the policy; the repo holds the query — neither is a bare delegate.

### Task 3.3 — Repoint `CatalogEndpoint` + `archive-sfwd-courses.php` to the service (B2 + B3) `[Tier B for the endpoint repoint; Tier A for the archive parity]`
- **What (B2):** `CatalogEndpoint::handleCatalogPage` already calls `stridence_catalog_items($catalog)` — after 3.2 that helper delegates to the service, so the endpoint needs **no signature change**; confirm it now enumerates via the service and **the INF-2 input guards + nonce-by-framework + public-actions allow-list are byte-for-byte unchanged** (security-sentinel verifies at the gate). If the team prefers the endpoint call the service directly, that is a one-line swap — either way, **no pillar changes.**
- **What (B3):** `archive-sfwd-courses.php:24-142` builds its OWN divergent meta_query (admits "classroom teaser has NO date window"). Replace that inline query with a call to the **same** `EditionService::getCatalogItems()` / repo method so the eligibility rule stops forking. This is the fork the centralization exists to kill.
- **Test contract (Tier A for B3 parity):** an integration test asserting the archive's eligible edition set == the catalog's eligible set for the same fixtures (the "classroom teaser with no date window" case now obeys the shared rule — assert it is treated identically). The endpoint repoint itself is Tier B (no logic, same guards) — verified by a characterization test that the endpoint's response `html/count/total/has_more` shape is unchanged for a fixture catalog.
- **INF-2 preservation is BLOCKING:** diff the `handleCatalogPage` input-handling lines before/after — they must be identical.

**Integration gate (3A):** unit + integration green; a new integration test pins catalog==archive eligibility parity; browser-drive AF-1, AF-2, AF-3 (empty + dateless-page-1 + pure-LD + archive-parity edges) against seeded DB.

`── REVIEW GATE ── (tier: FULL — data-layer query extraction + the INV-7 catalog convergence point + the public api_data endpoint's data source; security-sentinel verifies INF-2 guards unregressed, ntdst-drift-reviewer verifies INV-3 no-pass-through)`

## Sub-cluster 3B — Course-page aggregation methods (B4 + B5 + B6)

### Task 3.4 — Add `EditionService::getPrimaryEdition(int $courseId, ?int $userId)` (B4) `[Tier A]`
- **What:** Extract the primary-edition CTA pick. **Ground-truth caveat (1c):** the two existing picks are **not identical** — `single-sfwd-courses.php:62-95` ranks *enrollable-first over the active set, picks the first `allowsEnrollment()`*; the catalog prepass `stridence_prefetch_course_cards:612-626` ranks `allowsEnrollment ? 2 : 1` per course and keeps the best. **Design decision (must be made in this task, not assumed):** define one canonical ranking — recommend "highest rank wins; rank = `allowsEnrollment()?2 : isActive()?1 : 0`; tie-break by enumeration order" — and have BOTH `single-sfwd-courses.php` and the prepass delegate to it. If the two rules must stay subtly different, document why and do NOT force a false merge. Reads status via `getEffectiveStatuses()` (INV-7).
- **Test contract (Tier A, RED-first):** the multi-cohort case — a course with one *running* (active, not enrollable) and one *open* (enrollable) cohort returns the **open** one as primary (the exact bug B4 fixes); a course with only a running cohort returns it (active, not enrollable); a course with no active edition returns `null`.

### Task 3.5 — Add `EditionService::getCourseHeaderSummary(int $courseId)` (B5) `[Tier A]`
- **What:** Extract `templates/course/header.php:39-74`'s inline next-edition + upcoming-count + price-range aggregation into a method returning a struct `{next_edition_date, upcoming_count, price_min_cents, price_max_cents}`. Preserve the **optional-price** semantics (the existing `catch (\Throwable) { // ignore price }` — price absent excludes that edition from the range, never fatals). Reads `getPrice()` + `start_date` via repo `getField`.
- **Test contract (Tier A, RED-first):** all-past editions → `upcoming_count=0`, null dates/prices; mixed → next date is the soonest future start, count counts only future; one edition with no price → excluded from min/max while priced ones aggregate; a `getPrice` throw → that edition omitted, method still returns the rest.

### Task 3.6 — Add `EditionService::getPubliclyVisibleEditions(int $courseId, ?int $userId)` (B6) `[Tier A]`
- **What:** Extract `templates/course/editions-list.php:75-113`'s public-visibility status policy + upcoming/past partition into a method returning `{upcoming: [...], past: [...]}` of publicly-visible editions (effective-status driven, INV-7). The template becomes a pure renderer over the struct.
- **Test contract (Tier A, RED-first):** a draft/announcement-only edition is excluded from the public set; an upcoming publicly-visible edition lands in `upcoming`, a past one in `past`; the partition uses effective status (a stored-future edition whose effective status is Completed is treated as past). Denial-style edge: a non-public edition never leaks to a guest.

**Integration gate (3B):** suite green; browser-drive AF-4 (multi-cohort CTA), AF-5 (header empty + price-range boundary), AF-6 (visibility leak edge) against seeded DB.

`── REVIEW GATE ── (tier: FULL — effective-status policy + visibility gating on a public course surface; ntdst-drift-reviewer verifies no pass-through, security-sentinel n/a but invariant-auditor checks INV-7)`

## Sub-cluster 3C — Cheap delegations (B7 + B8 + B9)

### Task 3.7 — Delegate the trajectory dashboard choice-window to `TrajectoryService::isChoiceWindowOpen()` (B7) `[Tier A]`
- **What:** `templates/trajectory/dashboard.php:76-99` re-derives the choice-window rule with its own `strtotime`. **Ground-truthed: `TrajectoryService::isChoiceWindowOpen(int)` already exists** (line 407) and the sibling `tab-keuzes.php` was already fixed to call it ("Shake-out BUG-4"). Replace the dashboard's inline derivation with the same call.
- **Test contract (Tier A, RED-first):** the dashboard and `tab-keuzes` now return the **same** open/closed decision for the same trajectory at the same boundary (the drift being closed) — assert via the service method directly: window-open trajectory → true, closed → false, boundary `now == deadline` → matches the service's decision. (The cheapest fix; the test pins the no-drift contract.)

### Task 3.8 — Replace the hardcoded meta key in `NavigationHooks` (B8) `[Tier B]`
- **What:** `services/frontend/hooks/NavigationHooks.php:83` hardcodes `'_ntdst_course_id'`. Replace with `ntdst_get(EditionRepository::class)->getCourseId($editionId)` (or `getField($id,'course_id')`) so the meta key derives from the model, not a string literal (INV-3: no hardcoded `_ntdst_*`).
- **no unit test: Tier B, swapping a hardcoded meta-key read for the repository accessor — behaviour-identical; verified by the suite + a browser check that the nav item still resolves the course.** (If `EditionRepository::getCourseId` does not exist as a public method, this becomes Tier A: add it + test it. Ground-truth at execution: `EditionService::getCourseId` exists; confirm the repo-level accessor before choosing the tier.)

### Task 3.9 — Pre-assemble profile/enrollment user meta via a stride-core service (B9) `[Tier A]`
- **What:** `templates/dashboard/tab-profiel.php:25-50` + `templates/forms/enrollment.php:38-49` read user meta directly via `get_user_meta`. The sibling `tab-inschrijvingen.php` correctly uses `UserDashboardService`. Add a stride-core user/profile read method (extend `UserDashboardService` or `ProfileTypeService`) that returns the pre-assembled profile/billing struct (respecting the personal-vs-billing meta-key separation documented in CLAUDE.md — `organisation` ≠ `billing_company`, never fall back between them). Both templates consume the struct.
- **Test contract (Tier A, RED-first):** the assembled struct keeps personal (`organisation`/`department`) and billing (`billing_company`/`billing_address_1`/…) **separate** — an empty `organisation` does NOT fall back to `billing_company` (the documented trap); each field maps to its exact meta key.

**Integration gate (3C):** suite green; browser-drive AF-7 (trajectory dashboard vs tab-keuzes parity); smoke the profile tab + enrollment form prefill (B9) and a nav item (B8).

`── REVIEW GATE ── (tier: FULL — B9 touches user-meta read assembly + B7 is an invariant-parity fix; kept FULL because the cluster is the data/effective-status zone, though 3C alone is the lightest sub-cluster — invariant-auditor + drift-reviewer suffice, security-sentinel n/a)`

---

## Spec-close (Stage 3, after all three clusters)

1. **Phase-complete integration gate** — full unit (`vendor/bin/phpunit --testsuite Unit`), integration (`-c phpunit-integration.xml.dist`), acceptance suite, theme Vitest (`npm run test`). Cite the **run** (date + totals), never file inventory (`lesson_suite_counts_need_a_run`). Baseline to beat: unit 1048, integration 510 (from `project_admin_workspace_1a_done`) — confirm at branch start, the suite has moved.
2. **Test-effectiveness audit** over the Cluster-3 diff — for each extracted method, name the test that goes RED if the eligibility/ranking/visibility/window rule breaks; mark any `blind` and close it. Especially the catalog==archive parity and the multi-cohort CTA (green-but-blind denial paths are the dominant escape on policy extractions).
3. **Feature-acceptance verification** — drive AF-1…AF-7 through the theme's browser harness against the seeded DB (NOT the demo/dev DB — `gotcha_unseed_misses_acceptance_fixtures`). Emit the pass/fail/not-reachable manifest. No UI flow is `pass` without a browser driving it.
4. **Shake-out / `/shakeout`** — branch review tier is **FULL** (the branch diff includes the Cluster-3 data-layer + endpoint surface). Panel: `reviewer` + `code-simplicity-reviewer` + `security-sentinel` + `performance-oracle` + `invariant-auditor` + `ntdst-drift-reviewer` (WP). The endpoint's INF-2 guards and INV-3/5/7 are the convergence targets.
5. **Finish** — `superpowers:finishing-a-development-branch`, then run the **full suite once more on the merge commit** (the stated goal: confirm nothing broke). Merge `refactor/stridence-cleanup` only on green.
6. **Compound** — patch `docs/architecture/CODE-MAP.md` (the new `EditionService` catalog/aggregation surface + the now-single-home date-window builder) and a `/skill-audit` scoped to what this taught. Report-only.

---

## Sibling-site audit blocks

- **Date-window eligibility rule (INV-3 / catalog):** after Task 3.1 there must be **exactly one** home for the active-status + 2-day-grace + dateless predicate. Sweep: `grep -rn "activeValues\|end_date.*NOT EXISTS\|-2 days\|date_window" web/app` — the only matches should be the new repo method + its test. `archive-sfwd-courses.php`, `CatalogEndpoint`, and the theme item-builders must hold ZERO copies of the meta_query after 3.2/3.3.
- **Primary-edition ranking (B4):** after Task 3.4, `single-sfwd-courses.php` and `stridence_prefetch_course_cards` must both call `getPrimaryEdition()` — `grep -rn "allowsEnrollment\(\).*break\|best_rank" web/app/themes/stridence` should show no remaining inline ranking outside the prepass's delegation.
- **Effective-status reads (INV-7):** `grep -rn "->status\b\|'status'" web/app/themes/stridence/templates/course web/app/themes/stridence/single-sfwd-courses.php` — confirm no new raw stored-status gate/display read was introduced; all go through the extracted methods / `getEffectiveStatus(es)`.
- **Hardcoded `_ntdst_` (INV-3):** after B8, `grep -rn "_ntdst_" web/app/themes/stridence --include=*.php` should be empty (or only in comments).

---

## Recommendations summary

- **Include:** Cluster 1 (all), Cluster 2 Tasks 2.1+2.2, **all of Cluster 3** (the real debt — catalog fork B1/B3 is *actively diverging*, so B1/B2/B3 are the highest-value work and should not wait).
- **DEFER (recommended):** **O5** (Task 2.3 — router page move; crosses into stride-core for pure tidiness, risks a 500 on the two conversion pages, no de-duplication) and **O3** (Task 2.4 — collapse dashboard partials home; 9-file churn for cosmetic gain). Both are explicitly OPTIONAL; in ship-mode, the value/risk ratio says defer. Pull O5 in only if a client-override of the enrollment/completion pages is imminent (the `templates/pages/` home would make that cleaner); pull O3 in only if a third generation of dashboard partials starts drifting.
- **Branch:** `refactor/stridence-cleanup`. Full suite on the merge commit is the go/no-go.
