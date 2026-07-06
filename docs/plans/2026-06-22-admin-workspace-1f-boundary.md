# Admin Workspace — Phase 1F — Boundary (select-all server expansion) + cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the first slice — promote the interim "select-all = this page only" model (1D's CR-1 honest stopgap) to a **server-side filter→ids expansion** that carries the grid filter to the server, expands it to the full matching id-set **inside the existing `MAX_BATCH` cap**, and applies the bulk action to all of them; and remove the worklist-export entry point from the dashboard while keeping the CSV route for back-compat.

**Architecture:** Two tasks. **4.1 (Tier A):** the bulk payload gains a `{select_all:true, filter:{…}}` shape; a shared param-normalisation step expands `filter` → the filtered registration-id set via a NEW repo method `idsForGridFilter(array $filters, int $limit)` that **reuses `buildGridFilters`** (the single WHERE source), capped at `MAX_BATCH + 1` so an over-cap expansion trips the EXISTING `too_many` guard rather than truncating; the client's `selectAllFiltered` carries the filter (not page-ids) and confirms the honest `gridPagination.total`. **4.2 (Tier B):** delete the "Inschrijvingen exporteren" quick-action button (and its dead `exportRegistrations()` JS) — the CSV REST route stays.

**Tech Stack:** WordPress (Bedrock) · NTDST Core (DI, `RegistrationRepository`, the `ntdst/v1/action` registry) · `stride-core` mu-plugin · Alpine.js (the existing 1854-line `admin-dashboard.js` `strideApp`) · `wp_vad_registrations` custom table (indexed) · PHPUnit integration suite (`phpunit-integration.xml.dist`, tests under `tests/Integration/`) · Playwright/Chrome for the acceptance pass.

**Work classification:** **Class B freshness review** of an authored spec (`2026-06-13-admin-workspace-spec.md` REVIEW GATE D / Tasks 4.1–4.2 / §5 / §6 / the F2/F3 boundary note) + phase-map, writing the executable Phase-1F plan. **This is the LAST cluster of the first slice** — after REVIEW GATE D clears, the held spec-close panel runs once over the whole 1B–1F slice, then the single merge. Cluster tier: **STANDARD** (boundary model + UI removal; behavior change, no new 1a surface — the bulk authz/nonce/cap were FULL-reviewed in cluster B; 4.1 adds a filter-expansion IN FRONT of that unchanged enforcement).

**Branch:** continue on `feat/admin-workspace-1b-pickers-traj-filter` (the user stacks the 1A→1F slice on one branch and merges at the end). Per harness, the planner does not branch.

## Global Constraints

- **All UI text is Dutch (nl_BE)** — labels, confirms, errors, toasts. Match the existing dashboard vocabulary (`inschrijvingen`, `geselecteerd`, `te veel`).
- **§5 hard rule (load-bearing):** the grid NEVER loads the full ~4000-row set client-side. "Select all" is a **server-side filter selection**, not 4000 materialised rows. 4.1 must keep this intact — the client sends the *filter*, the server expands.
- **The cap is single-source:** `BulkRegistrationHandler::MAX_BATCH = 500` (the B3 fix, `:50`). 4.1's expansion MUST funnel through / respect this same constant — **do NOT add a second cap definition** anywhere. Over-cap returns the existing `WP_Error('too_many', 'Te veel inschrijvingen geselecteerd (max 500).', ['status'=>400])` — NOT a silent truncation (the F2/F3 boundary).
- **INV-3:** the filter→ids expansion is a **repo method** (`idsForGridFilter` in `RegistrationRepository`), never inline `$wpdb` in the handler.
- **Structured-only filter params (Sibling-site audit):** the expanded `filter` reuses `buildGridFilters` — the SAME structured-only whitelist (`status`/`edition_id`/`company_id`/`trajectory_id`/`q`/`edition_scope`) the grid READ already validates (M4/M5). No `enrollment_data`/`selections` JSON param ever reaches the expansion.
- **Trajectory parent→child semantics (Sibling-site audit):** the expansion's trajectory filter MUST keep the A2 child-row semantics (`buildGridFilters` already routes `trajectory_id` through the parent→child join with `r.edition_id IS NOT NULL` excluding parents) — reusing `buildGridFilters` gives this for free; a bare `WHERE trajectory_id = T` is a bug.
- **§6:** Task 4.2 removes the worklist-export UI entry point ONLY. The `GET /admin/export/registrations` CSV route STAYS registered for back-compat.

---

## Gate-firing decision (explicit — run the trigger list literally, not by gut)

- **1a threat-modeling — ASSESSED, DOES NOT FIRE (with a named mitigation that is ALREADY SATISFIED by reuse).** This is the one place 1F could surface a real security concern, so the assessment is rigorous (full reasoning in the "Threat-modeling assessment" section below). Ran the trigger list literally: **no** new auth model (the existing `denyIfNotManager` / `stride_manage` gate + the registry's per-action nonce + Origin/Referer CSRF from cluster B are unchanged), **no** user-controlled URL, **no** new credential/BYOK, **no** untrusted parser, **no** new tenancy boundary (the admin grid is intentionally cross-company, spec §477). The ONE new thing is a **server-trusted `filter` input that the server expands into a bulk MUTATION set** — a potential confused-deputy / over-broad-mutation surface (the "empty filter expands to ALL rows" case). **Assessed and closed by construction:** the expansion reuses `buildGridFilters` (the SAME validated, structured-only WHERE the read endpoint already uses — M4/M5), and `buildGridFilters` ALWAYS appends `r.edition_id IS NOT NULL` + (default) the active-edition scope, so even an empty filter expands to a *bounded* corpus (active edition-grained rows), not "everything," and the `MAX_BATCH` cap is the hard backstop that converts any over-broad expansion into a `too_many` 400. Because the expansion reuses the validated grid filter + the cap + the existing per-action authz unchanged, **threat-modeling does not fire** — STANDARD tier stands. The empty-filter case is real but its mitigation (filter validation == the read's, cap as backstop) is *inherited by reusing `buildGridFilters`* — it is named as a REQUIRED, TESTED mitigation in Task 4.1, not left implicit.
- **1b architecture-invariants — APPLIES (cite, do not author).** **INV-3** (the expansion is a NEW repo method `idsForGridFilter` reusing `buildGridFilters`, no inline `$wpdb` in the handler), plus the two **Sibling-site audit** convergence constraints from the spec: structured-only filter params (M4/M5), and trajectory parent→child child-row semantics in the expansion. Doc: `/home/ntdst/Sites/stride/ARCHITECTURE-INVARIANTS.md`.
- **1g feature-acceptance — FIRES.** This IS the F2/F3 select-all-across-pages boundary. Matrix embedded below (the six edges, incl. empty-filter, over-cap, filter-respected, trajectory-child-rows, denied-actor, concurrent). The cluster-D integration gate is the boundary test + a browser/curl confirm that select-all-across-pages applies to the full filtered set within the cap and errors past it, and that the export button is gone.
- **API/boundary design — DOES NOT FIRE for a fresh contract review,** but the `select_all + filter` payload contract is pinned below (additive to the existing `{ids:[…]}` shape — a normal ids payload is unchanged).

---

## Freshness-review preamble — premises ground-truthed (2026-06-23) + DRIFT found

Every premise the spec/phase-map/brief asserts for cluster D was checked against current source. Evidence is `file:line`. **This is the highest-value section: the planner has caught real drift every cluster.**

### Confirmed (no drift)

- **The CR-1 interim select-all state exists exactly as described, and is HONEST.** `admin-dashboard.js`: `gridSelectAllFilter` (`:205`, comment "carry filter, not 4k rows"), `gridSelectedIds` (`:1085`), `gridSelectedCount` (`:1088-1091`, returns `gridPagination.total` when select-all armed), `selectAllFiltered()` (`:1114-1119`, currently marks only the **loaded page** rows + toasts "op deze pagina geselecteerd"), `runGridBulk()` (`:1168`) currently POSTs `{ ids }` = `gridSelectedIds` (loaded-page ids only) and, when `gridSelectAllFilter && total > ids.length`, shows the honest CR-1 confirm "Deze actie raakt alleen de N op deze pagina … andere pagina's … NIET meegenomen" (`:1179-1188`). `bulkApi(action, payload)` (`:1147`) POSTs `{...payload, action, nonce}` to `ntdst/v1/action`. **This is the exact interim behavior 4.1 REPLACES.**
- **The server cap exists and is single-source.** `BulkRegistrationHandler::MAX_BATCH = 500` (`:50`); `runBulk` (`:100`) reads `$params['ids']`, `absint`+dedupes, and applies the cap BEFORE the loop (`:107-113`) returning `WP_Error('too_many', 'Te veel inschrijvingen geselecteerd (max 500).', ['status'=>400])`. `finishBatch` (`:187`) and `finishQuoteBatch` (`:207`) propagate a `WP_Error` untouched. **4.1 funnels the expanded id-set through this SAME guard — the over-cap error is inherited, not re-implemented.**
- **`buildGridFilters` is the single WHERE source** (`RegistrationRepository.php:1896-2023`) consumed by BOTH `queryForGrid` (`:1608`) and `queryForGridGrouped` (`:1697`). It builds `active_join` + `where_clause` + `params` from the structured filters (`status`/`edition_id`/`company_id`/`trajectory_id`/`q`/`edition_scope`/`page`/`per_page`). **`idsForGridFilter` (4.1) reuses this method — no second filter definition.**
- **The trajectory filter keeps child-row semantics in `buildGridFilters` itself** (`:1979-1988`): it routes `trajectory_id` through the parent→child `LEFT JOIN … traj_parent` and `(r.trajectory_id = %d OR traj_parent.id IS NOT NULL)`, and the base predicate `r.edition_id IS NOT NULL` (`:1942`) keeps the parent OUT. **So an ids-only expansion that reuses `buildGridFilters` inherits the A2 child-row + leak-safe semantics for free** — the expansion does NOT need to re-thread the join.
- **The registry passes the FULL POST body to the handler as `$params`.** `Endpoints.php:353` dispatches `apply_filters("ntdst/api_data/{$action}", [], $params)` where `$params = get_request_params($request)` (`:335`) — the whole JSON body. So a client POST `{action, nonce, select_all:true, filter:{…}}` arrives intact as `$params['select_all']` + `$params['filter']`. No registry change needed.
- **The worklist-export entry point exists and is findable.** `templates/admin/dashboard.php:412`: `<button class="sd-btn sd-btn--ghost sd-btn--block" @click="exportRegistrations()">Inschrijvingen exporteren</button>` (in the "Snelle acties" card). Its JS is `admin-dashboard.js:596-598` `exportRegistrations() { window.location.href = this.config.apiUrl + '/admin/export/registrations?_wpnonce=' + this.config.nonce; }`. **4.2 removes BOTH the button and the now-dead JS method; the CSV route stays.**
- **Canonical test location is `tests/Integration/`** (repo root) per `phpunit-integration.xml.dist:14`. Existing siblings to follow for fixtures: `RegistrationGridQueryTest.php` (`:1`, 46KB — the grid-query fixture pattern, incl. trajectory parent/child + active-scope + dateless rows) and `BulkCacheBustTest.php` (the bulk-handler integration pattern). **`BulkSelectAllTest.php` does NOT exist yet** — 4.1 creates it.

### DRIFT found (act on these)

1. **`idsForGridFilter` does NOT exist — it must be ADDED (the spec said "check whether an ids-only query method exists or must be added").** `grep` for `idsForGrid`/`idsForGridFilter` in `RegistrationRepository.php` returns nothing; the only grid methods are `queryForGrid` (`:1608`) and `queryForGridGrouped` (`:1697`). **Decision: add `public function idsForGridFilter(array $filters, int $limit): array`** returning the matching `r.id` set (LIMIT `$limit`), built from `buildGridFilters` — the same `active_join` + `where_clause` + `params`, SELECTing `r.id` instead of the full row set, ORDER BY `r.id` for determinism, `LIMIT %d`. This is the INV-3 home for the expansion.

2. **CRITICAL — the expansion must land in `$params['ids']` BEFORE the per-handler quote pre-resolver, not only inside `runBulk`.** `setQuoteStatusForRows` (`:295-321`, the path for `stride_bulk_quote_sent`/`_exported`) reads `$params['ids']` **independently at `:307`** to build the reg→quote `$map` in one query (the B2 N+1 fix) — BEFORE it calls `runBulk` (`:310`). If expansion happened only inside `runBulk`, the quote handlers would build `$map` from the un-expanded (empty, under select-all) ids → every quote row would fail `no_quote`. **Therefore: expand ONCE at handler entry via a shared private helper** `resolveBulkIds(array $params): array|WP_Error` that returns the params with `ids` populated (expanding `filter` when `select_all` is set, capped to `MAX_BATCH + 1` so the existing `runBulk` cap catches over-cap), and have EVERY public handler call it first (right after `denyIfNotManager`), passing the resolved params onward. `runBulk` then reads the already-expanded `$params['ids']` unchanged, and `setQuoteStatusForRows`'s `:307` read sees the expanded set too. This is the only structural change to the handler; the per-row loop, the cap, `finishBatch`, and the authz are all untouched.
   - **Cap interaction (get this exactly right):** `idsForGridFilter` is called with `$limit = MAX_BATCH + 1` (501). If it returns 501 ids, `runBulk`'s existing `count($ids) > MAX_BATCH` guard (`:107`) fires `too_many`. So the over-cap error is produced by the EXISTING guard on the expanded set — no new cap, no new error. (Fetching `MAX_BATCH + 1` rather than capping at `MAX_BATCH` is what lets the guard distinguish "exactly 500, OK" from "more than 500, reject" without a separate count query.)

3. **`selectAllFiltered()` currently marks only loaded-page rows — it must STOP doing that under the new model.** Today (`:1114-1119`) it sets `gridSelected[r.id]=true` for the loaded page AND `gridSelectAllFilter=true`. Under 4.1 the client no longer needs per-row ids for select-all — it carries the *filter*. **4.1 changes `selectAllFiltered()` to set `gridSelectAllFilter=true` WITHOUT marking page rows** (or keep marking the page purely for the checkbox UI but STOP sending those ids when the flag is armed), and changes `runGridBulk` to POST `{select_all:true, filter:<current grid filters>}` instead of `{ids}` when `gridSelectAllFilter` is armed. The CR-1 "andere pagina's NIET meegenomen" confirm (`:1179-1188`) is REPLACED by the honest "dit raakt N inschrijvingen — bevestig" confirm where N = `gridPagination.total`.
   - **Watch:** `toggleGridRow` (`:1083`) and `toggleGridPage` (`:1108`) reset `gridSelectAllFilter=false` on hand-deselect — keep that (hand-touching a row breaks the "all on this filter" contract and must fall back to explicit-ids mode). `gridSelectedCount` (`:1091`) already returns `gridPagination.total` when the flag is armed — that IS the honest N for the confirm; no change needed there.

4. **The current grid filters object the client must send as `filter`.** `runGridBulk` must serialise the SAME filters the grid is currently showing. Ground-truth the exact state name at execution: the grid filter state is `gridFilters` (referenced in the 1E plan at `:194`/`:871` for `gridFilters.trajectory_id`, and the active scope/status/edition/company/q live alongside). **Send the structured subset only** (`status`, `edition_id`, `company_id`, `trajectory_id`, `q`, `edition_scope`) — NOT `page`/`per_page`/`sort` (the expansion ignores paging). Confirm the field names by reading `gridFilters` + `loadGrid`'s param-building (`:918-948`) at execution; mirror exactly what `loadGrid` already sends to `/admin/registrations` minus paging/sort.

5. **No spec/source contradiction on the CSV route.** §6 + Task 4.2 both say keep the route, remove the UI. `GET /admin/export/registrations` is still the target of `exportRegistrations()` (`:597`). 4.2 leaves the route registration in `AdminAPIController` untouched and removes only the button + the dead JS method. (Confirm at execution the route is still registered — `grep -n 'export/registrations' AdminAPIController.php` — and do NOT touch it.)

**Net:** no premise is fatally wrong. The CR-1 interim state is exactly as described and is the honest stopgap 4.1 promotes. The cap is single-source and the over-cap error is inherited by fetching `MAX_BATCH+1`. The real (small) engineering subtlety the spec glossed is **DRIFT #2** — the expansion must populate `$params['ids']` at handler entry so the quote handlers' independent `:307` pre-resolver sees it, not only inside `runBulk`. `idsForGridFilter` is net-new (DRIFT #1) but trivial because `buildGridFilters` already exists and already carries the trajectory child-row + active-scope + empty-filter-is-bounded semantics. The spec's design holds.

---

## Threat-modeling assessment (the rigorous version — this is the one place 1F could bite)

**Trigger list, run literally:**

| Trigger | Present in 1F? | Reasoning |
|---|---|---|
| New/changed auth model | **No** | `denyIfNotManager()` (`stride_manage`) is the first line of every handler, unchanged. The registry's per-action nonce (`wp_verify_nonce($nonce,$action)`, `Endpoints.php:343`) + Origin/Referer CSRF are unchanged. All FULL-reviewed in cluster B (M2/M6). |
| User-controlled URL / outbound | **No** | No URL leaves the server; no `wp_remote_*`; the CSV route 4.2 leaves alone is unchanged. |
| New credential / BYOK | **No** | None. |
| Untrusted parser | **No** | The `filter` is a structured key→scalar map run through `buildGridFilters`' existing `absint`/enum/`esc_like` validation; no JSON-from-network parsing, no frontmatter, no file. |
| New tenancy/workspace boundary | **No** | The admin grid is intentionally cross-company (spec §477 — company-scoping is explicitly out of scope; the admin sees all). The expansion does not introduce a per-tenant boundary. |
| **Server-trusted input feeding a MUTATION** | **YES — assessed** | The `filter` is a NEW server-trusted input shape that the server expands into a bulk **mutation** set. This is the real surface. See below. |

**The one real surface — `filter` → ids → mutation. Two failure modes assessed:**

1. **Empty/malformed filter expands to ALL rows (over-broad mutation).** If `idsForGridFilter([])` returned every registration id, a `select_all` with no filter would mutate the entire corpus. **Mitigated by construction:** `idsForGridFilter` reuses `buildGridFilters`, which ALWAYS appends `r.edition_id IS NOT NULL` (`:1942`) and — by default (`edition_scope` defaults to `active`, `:1921`) — the active-edition scope predicate. So an empty filter expands to *active edition-grained rows*, a bounded set, never "everything including trajectory parents." AND `idsForGridFilter` is called with `$limit = MAX_BATCH + 1`, so any expansion exceeding 500 is rejected `too_many` by the EXISTING `runBulk` guard. The cap is the hard backstop; the filter validation is the same one the READ endpoint already trusts. **Required + TESTED mitigation (Task 4.1 contract):** (a) the expansion reuses `buildGridFilters` (asserted by the leak/respect tests), and (b) a filter matching > `MAX_BATCH` rows returns `too_many`, not a truncated/partial mutation.

2. **Confused deputy — a filter referencing rows the actor shouldn't touch.** The admin capability model is GLOBAL (`stride_manage` covers all registrations — spec M3/§468); there is no per-row owner the actor could be tricked past. The per-row path in `runBulk` still resolves each id via `repo->find($id)` and validates the transition (M3 unchanged). So a row the filter surfaces but that is in an invalid state for the action lands in `failed[]`, not mutated. No new confused-deputy surface beyond what cluster B already defends.

**Conclusion: threat-modeling DOES NOT FIRE.** The new input is bounded by the same validated filter the read already trusts (M4/M5 via `buildGridFilters`) and backstopped by the single-source cap; the authz/nonce/per-row checks are inherited unchanged from cluster B. The empty-filter case is real but its mitigation is *inherited by reusing `buildGridFilters` + the cap* — named and tested in Task 4.1, not left implicit. **Tier stays STANDARD** with one-way escalation: if a reviewer finds the expansion does NOT reuse `buildGridFilters` (a forked filter definition), or that an empty/over-cap filter is NOT bounded/rejected, that lands on a 1a surface and promotes cluster D to FULL.

---

## Acceptance flows

> Per `netdust-agent:feature-acceptance` (situation A — authored at plan-time). One row per intended-use flow; the **Edges** column enumerates the six edge classes (empty/zero, denied actor, wrong-order/re-entry, concurrent/double, boundary, mid-flow failure). This is the **F2/F3 select-all-across-pages boundary** the spec's boundary note (§525) defers to Task 4.1. The cluster-D integration gate **drives this through the REAL admin page via Playwright/Chrome (not jsdom)** AND the API layer (curl of the `ntdst/v1/action` registry with a `select_all` payload), emitting a pass/fail/not-reachable manifest. No UI flow is `pass` without a browser driving it.

| # | Intended-use flow | Happy path | Edges (all six — mandatory) |
|---|---|---|---|
| **D1** *(select-all across pages → server expansion)* | Filter the grid (e.g. `status=pending`), click "Selecteer alle N", choose a bulk action → confirm "dit raakt N inschrijvingen" → the action applies to the **whole filtered set across all pages**, within the cap | Filter to a set of, say, 80 pending rows (page size 25). Click select-all → `gridSelectedCount` shows 80, confirm shows "dit raakt 80 inschrijvingen". "Goedkeuren" → server expands the filter to all 80 ids and approves them; report "80 geslaagd" (not just the 25 on the page). | **empty (zero):** filter matches 0 rows → select-all is disabled / shows 0, bulk action no-ops (nothing to expand). **denied:** a `stride_supervisor` (view-only) crafts a `select_all` POST → `denyIfNotManager` 403 BEFORE any expansion or loop (M2, unchanged). **wrong-order/re-entry:** hand-deselect one row after arming select-all → `gridSelectAllFilter` resets to false (`:1083`), falls back to explicit-ids mode, no stale filter sent. **concurrent:** another admin mutates a matching row mid-expansion → that row's per-row path is idempotent (M9); the expansion is a fresh query at request time, so it reflects current state. **boundary (over-cap):** filter matches > `MAX_BATCH` (501+) rows → `idsForGridFilter` returns 501 → `runBulk` guard returns `too_many` 400 "Te veel inschrijvingen geselecteerd (max 500)" — **NOT a silent truncation**; the client surfaces the Dutch error toast. **mid-flow failure:** expansion succeeds (≤500) but row 5 fails its domain op → non-atomic per-row report "N-1 geslaagd, 1 mislukt" (M9, unchanged). |
| **D2** *(filter is respected + bounded — the security edge)* | The expansion mutates ONLY rows matching the carried filter — never the whole corpus, never another filter's rows | With `status=pending` + `company_id=X` armed and select-all, only pending rows of company X are expanded/approved; a `confirmed` row, or a pending row of company Y, is untouched. | **empty-filter (over-broad guard):** a `select_all` with an EMPTY `filter` expands ONLY to active edition-grained rows (`buildGridFilters` base predicate + active scope), still capped — never trajectory parents, never terminal-edition rows by default; over the cap → `too_many`. **trajectory child-rows:** `select_all` with `trajectory_id=T` expands to T's **child edition-rows** (the parent→child join in `buildGridFilters`), NOT the parent, NOT another trajectory's rows (the A2 leak-check, inherited). **denied:** as D1. **structured-only:** a smuggled `filter.dietary` / any `enrollment_data` JSON key is ignored — `buildGridFilters` only reads the structured whitelist (M5). **concurrent / mid-flow:** as D1. |

**D2 leak/respect assertions are the load-bearing security tests** (the threat-model surface): the expansion respects the filter, an empty filter is bounded (not the whole corpus), and the trajectory filter expands to child rows only. These are asserted RED-first in the Task 4.1 integration test.

---

## Per-task breakdown — Phase 1F (cluster D)

> Tier tags per `netdust-agent:testing-workflow`. 4.1 is **Tier A** (real expansion logic + the over-cap denial-ish boundary + the leak/respect security edge) — RED-first. 4.2 is **Tier B** (UI removal). The cluster is 2 tasks (≤4, harness rule 1f) — one review unit, STANDARD tier.

### ── REVIEW GATE D tasks ──

#### Task 4.1: Select-all-across-pages as a server-side filter→ids expansion

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php` (add `idsForGridFilter(array $filters, int $limit): array` reusing `buildGridFilters`)
- Modify: `web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php` (add `resolveBulkIds(array $params): array|WP_Error`; call it first in EVERY public handler; quote path's `:307` pre-resolver reads the resolved ids)
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js` (`selectAllFiltered` carries the filter; `runGridBulk` POSTs `{select_all, filter}` when armed; replace the CR-1 confirm with the honest "dit raakt N — bevestig")
- Test: `web/app/mu-plugins/stride-core/tests/Integration/BulkSelectAllTest.php` → **canonical path `tests/Integration/BulkSelectAllTest.php`** (repo root, per `phpunit-integration.xml.dist:14`)

**Interfaces:**
- Consumes: `RegistrationRepository::buildGridFilters(array): array` (existing, `:1896` — the single WHERE source); `BulkRegistrationHandler::MAX_BATCH` (existing const, `:50`); `runBulk(array $params, callable): array|WP_Error` (existing, `:100`, reads `$params['ids']`); the registry `$params` body (full POST JSON via `Endpoints.php:353`).
- Produces:
  - `RegistrationRepository::idsForGridFilter(array $filters, int $limit): array` → a flat `list<int>` of matching `r.id`, capped at `$limit` rows. Reuses `buildGridFilters` (same `active_join`/`where_clause`/`params`), `SELECT r.id … ORDER BY r.id ASC LIMIT %d`.
  - `BulkRegistrationHandler::resolveBulkIds(array $params): array|WP_Error` → returns `$params` with `ids` populated: if `!empty($params['select_all'])`, expand `$params['filter']` (a structured-key array) via `idsForGridFilter($filter, self::MAX_BATCH + 1)` and set `$params['ids']` to the result; otherwise return `$params` unchanged. (Does NOT itself enforce the cap — it fetches `MAX_BATCH+1` so `runBulk`'s existing guard rejects an over-cap set.)
  - **Payload contract (additive — a normal `{ids:[…]}` payload is unchanged):**
    ```json
    { "action": "stride_bulk_approve", "nonce": "…",
      "select_all": true,
      "filter": { "status": "pending", "edition_id": 0, "company_id": 0,
                  "trajectory_id": 0, "q": "", "edition_scope": "active" } }
    ```
    When `select_all` is absent/falsey, handlers use `$params['ids']` exactly as today.

- **Tier: A. RED-first.**
- **Test contract** (`tests/Integration/BulkSelectAllTest.php`, integration DB with real indexes; build the fixture following `RegistrationGridQueryTest` — pending/confirmed rows across editions + companies, a trajectory parent+children, an over-cap batch):
  1. a `select_all + filter` bulk op (e.g. `stride_bulk_approve` with `{status:'pending'}`) **expands server-side to the full filtered set across pages and applies to ALL of them** (not just one page) — assert every pending row in the filtered set is confirmed, count > one page.
  2. a filter matching **MORE than `MAX_BATCH` rows returns the `too_many` 400** (`'Te veel inschrijvingen geselecteerd (max 500).'`) **rather than truncating** — assert NO row was mutated and the error code is `too_many` (the F2/F3 boundary; the over-cap backstop).
  3. **the expansion respects the filter** — a row NOT matching the filter (e.g. a `confirmed` row, or a pending row of a different `company_id`) is **untouched** after a `{status:'pending', company_id:X}` select-all.
  4. **INV / leak — the trajectory filter expands to CHILD rows (parent→child), not the parent** — `select_all` with `{trajectory_id:T}` expands to T's child edition-rows only (assert the parent row `edition_id=NULL` is NOT in the expanded set, and no other trajectory's rows are).
  5. **empty-filter is bounded (the threat-model mitigation)** — `select_all` with an empty `filter` does NOT expand to trajectory parents or terminal-edition rows in default scope (reuses `buildGridFilters`' base predicate + active scope); if the active corpus exceeds the cap it returns `too_many`, never an unbounded mutation.
  6. **denial path** — a request without `stride_manage` returns 403 BEFORE any expansion (assert `idsForGridFilter` is never reached / no row mutated) — reuses `denyIfNotManager` (M2).

- [ ] **Step 1 (RED):** Write `BulkSelectAllTest.php` per the contract above (fixtures: pending/confirmed rows across ≥2 editions + ≥2 companies; a trajectory parent + ≥2 children; for the over-cap case, seed `MAX_BATCH + 5` matching rows OR temporarily assert against a small `$limit` — prefer a real over-cap fixture if seed time allows, else a focused unit-level assertion on `idsForGridFilter`'s LIMIT + `runBulk`'s guard). Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BulkSelectAll`. Expected: FAIL (`idsForGridFilter`/`resolveBulkIds` absent; `select_all` ignored).
- [ ] **Step 2 (GREEN — repo):** Add `idsForGridFilter(array $filters, int $limit): array` to `RegistrationRepository` directly mirroring `queryForGrid`'s use of `buildGridFilters` (DRIFT #1):
      ```php
      public function idsForGridFilter(array $filters, int $limit): array
      {
          global $wpdb;
          $built       = $this->buildGridFilters($filters);
          $activeJoin  = $built['active_join'];
          $whereClause = $built['where_clause'];
          $params      = $built['params'];
          $regTable    = $this->table();

          $sql = "SELECT r.id
                  FROM {$regTable} r
                  LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
                  {$activeJoin}
                  {$whereClause}
                  ORDER BY r.id ASC
                  LIMIT %d";
          $allParams = array_merge($params, [max(1, $limit)]);
          $ids = $wpdb->get_col($wpdb->prepare($sql, ...$allParams));
          return array_map('intval', $ids ?: []);
      }
      ```
      (Note: `buildGridFilters`' trajectory JOIN unshifts its `%d` to the FRONT of `$params` — `array_merge($params, [$limit])` keeps the `LIMIT %d` last, matching SQL placeholder order. Mirror `queryForGrid`'s exact param ordering.)
- [ ] **Step 3 (GREEN — handler):** Add `resolveBulkIds` and wire it into every public handler (DRIFT #2 — this is the structural change):
      ```php
      private function resolveBulkIds(array $params): array
      {
          if (empty($params['select_all'])) {
              return $params;
          }
          $filter = is_array($params['filter'] ?? null) ? $params['filter'] : [];
          $repo   = ntdst_get(RegistrationRepository::class);
          // Fetch MAX_BATCH+1 so runBulk's existing cap guard rejects an
          // over-cap expansion (count > MAX_BATCH) rather than truncating.
          $params['ids'] = $repo->idsForGridFilter($filter, self::MAX_BATCH + 1);
          return $params;
      }
      ```
      In EVERY public `handleBulk*` method, immediately after `denyIfNotManager()`, replace the existing `$params` use with `$params = $this->resolveBulkIds($params);` BEFORE `runBulk`/`setQuoteStatusForRows`/the quote `:307` pre-resolver. Verify (read each handler) that the quote path (`setQuoteStatusForRows`) now receives the resolved `$params` so its `:307` `$map` build and `runBulk`'s `:102` read see the SAME expanded ids. The cap, the per-row loop, `finishBatch`/`finishQuoteBatch`, and authz are UNCHANGED.
- [ ] **Step 4 (GREEN — verify):** Run `--filter BulkSelectAll`. Expected: PASS (all six contract assertions). Also run the FULL bulk suite to prove no regression to the explicit-ids path: `--filter 'Bulk'`. Expected: identical GREEN (normal `{ids}` payloads unchanged).
- [ ] **Step 5 (client):** In `admin-dashboard.js`:
      - Change `selectAllFiltered()` (`:1114`) to arm `gridSelectAllFilter=true` and toast the honest total (`gridPagination.total`), WITHOUT relying on per-page ids for the bulk POST. (Keep marking the visible page's checkboxes for UI clarity if desired, but the bulk path must NOT depend on them when the flag is armed.)
      - Change `runGridBulk(actionId)` (`:1168`): when `gridSelectAllFilter` is armed, REPLACE the CR-1 "andere pagina's NIET meegenomen" confirm (`:1179-1188`) with the honest confirm `Deze actie raakt ${this.gridSelectedCount} inschrijving(en). Doorgaan?` (N = `gridPagination.total`), and POST `{ select_all: true, filter: <structured grid filters> }` via `bulkApi(actionId, …)` INSTEAD of `{ ids }`. When the flag is NOT armed, POST `{ ids }` exactly as today. Build the `filter` from the current `gridFilters` structured subset (`status`/`edition_id`/`company_id`/`trajectory_id`/`q`/`edition_scope`) — mirror exactly what `loadGrid` (`:918-948`) sends to `/admin/registrations` MINUS `page`/`per_page`/`sort` (DRIFT #4 — confirm field names at execution).
      - On a `too_many` error from `bulkApi`, the existing `catch` (`:1223-1225`) already surfaces the server's Dutch message as an error toast — confirm the over-cap message renders (no client-side truncation).
- [ ] **Step 6 (commit):**
      ```bash
      git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php \
              web/app/mu-plugins/stride-core/Handlers/BulkRegistrationHandler.php \
              web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js \
              tests/Integration/BulkSelectAllTest.php
      git commit -m "feat(admin): select-all-across-pages via server-side filter→ids expansion (capped, task 4.1)"
      ```

#### Task 4.2: Remove the worklist-export entry point (keep the CSV route)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/templates/admin/dashboard.php` (remove the "Inschrijvingen exporteren" button at `:412`)
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js` (remove the now-dead `exportRegistrations()` method at `:596-598`)

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing — pure removal. The `GET /admin/export/registrations` REST route in `AdminAPIController` is **left registered** (back-compat, §6).

- **Tier: B.** `no unit test: Tier B, UI removal — the replacement worklists are covered by F1 (Phase 1D, already shipped).`
- **Ground-truth (done in freshness review):** the button EXISTS at `dashboard.php:412` (in the "Snelle acties" card), its JS at `admin-dashboard.js:596-598`. **Not drift — it is present and is the entry point to remove.** (If a future execution finds it already gone, this task becomes a no-op + a note; as of 2026-06-23 it is present.)
- [ ] **Step 1:** Remove the `<button … @click="exportRegistrations()">Inschrijvingen exporteren</button>` line from `dashboard.php:412`. In its place (same "Snelle acties" card) add a one-line note that the Vandaag worklists replace it, e.g. a muted hint or simply leave the three remaining quick actions — and add a code comment: `<!-- Worklist-export entry point removed (Task 4.2); the 5 Vandaag worklist queues replace it. CSV route /admin/export/registrations kept for back-compat. -->`.
- [ ] **Step 2:** Remove the dead `exportRegistrations()` method (`admin-dashboard.js:596-598`) — nothing else calls it (confirm with `grep -n exportRegistrations admin-dashboard.js` returning only the definition before removal). Do NOT touch the route in `AdminAPIController`.
- [ ] **Step 3 (verify):** `grep -rn 'exportRegistrations\|Inschrijvingen exporteren' web/app/mu-plugins/stride-core/` returns nothing (button + method gone); `grep -n 'export/registrations' web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` STILL returns the route registration (back-compat kept).
- [ ] **Step 4 (commit):**
      ```bash
      git add web/app/mu-plugins/stride-core/templates/admin/dashboard.php \
              web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js
      git commit -m "chore(admin): remove worklist-export entry point; Vandaag queues replace it; CSV route kept (task 4.2)"
      ```

**Integration gate (cluster D):**
- New backend: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter BulkSelectAll` GREEN (all six 4.1 assertions). Full bulk suite `--filter 'Bulk'` identical GREEN (no regression to the explicit-ids path).
- **Feature-acceptance browser pass (situation B):** drive **D1/D2** through the REAL admin page via Playwright/Chrome (`playwright.config.ts`) — NOT jsdom — and emit the pass/fail/not-reachable manifest. Confirm: filter the grid, select-all across pages, run a bulk action → the report covers the WHOLE filtered set across pages (not just the visible page); a filter matching > `MAX_BATCH` → the "te veel rijen" error toast, NO partial mutation; the "Inschrijvingen exporteren" button is GONE from Snelle acties.
- **Manual curl** (API layer): POST to `ntdst/v1/action` `{action:'stride_bulk_approve', nonce, select_all:true, filter:{status:'pending'}}` as coordinator → report covers all matching pending rows (capped); as supervisor (view-only) → 403; with a filter known to match > 500 rows → `too_many` 400.

**── REVIEW GATE D ── (tier: STANDARD — select-all boundary model + worklist-export removal; behavior change, no new 1a surface — the bulk authz/nonce/cap were FULL-reviewed in cluster B, 4.1 adds a `buildGridFilters`-reusing expansion in front of that unchanged enforcement). HALT — run `/code-review` + `/security-review` on the cluster-D diff before declaring the first slice complete.** STANDARD fan-out: `/code-review` (2 finders + simplicity) + `/security-review` + the feature-acceptance browser pass above; NO `security-sentinel` panel UNLESS a finding lands on a 1a surface (then the cluster promotes to FULL — one-way escalation). **The most likely escalation trigger:** the expansion does NOT reuse `buildGridFilters` (a forked filter definition → M4/M5 surface), OR an empty/over-cap filter is not bounded/rejected (over-broad mutation → the threat-model surface). Reviewer focus: (1) `idsForGridFilter` reuses `buildGridFilters` — no second filter definition (Sibling-site audit: structured-only); (2) the over-cap path returns `too_many` via the EXISTING single-source `MAX_BATCH` guard — no second cap, no truncation; (3) the trajectory filter expands to child rows (parent→child via `buildGridFilters`), never the parent, never another trajectory (the A2 leak-check, inherited); (4) the expansion lands in `$params['ids']` BEFORE the quote handlers' `:307` pre-resolver (DRIFT #2 — else quote select-all silently no-ops); (5) the normal `{ids}` payload path is unchanged (no regression).

> **LAST CLUSTER OF THE FIRST SLICE.** After REVIEW GATE D clears, the held spec-close panel runs ONCE over the WHOLE 1B–1F slice (the stacked branch diff), per the spec's review-deferral, then the single merge to `main`. Do NOT merge cluster-by-cluster.

---

## Sibling-site audit (constraints this cluster must route through)

Convergence targets from the spec — surfaced so the reviewer keys findings to a named item:
1. **Single filter definition (structured-only, M4/M5).** The `select_all` expansion MUST reuse `buildGridFilters` — the SAME WHERE the grid READ (`queryForGrid`/`queryForGridGrouped`) uses. A second/forked filter definition in `idsForGridFilter` or the handler is a finding (and a 1a-surface escalation). No `enrollment_data`/`selections` JSON param reaches the expansion.
2. **Single cap definition.** `MAX_BATCH = 500` is the one source. The expansion fetches `MAX_BATCH + 1` so the EXISTING `runBulk` guard produces `too_many`; a second cap constant or a client-side truncation is a finding.
3. **Trajectory parent→child child-row semantics.** The expansion's `trajectory_id` filter routes through `buildGridFilters`' parent→child join (inherited); a bare `WHERE trajectory_id = T` anywhere is a bug (misses children + leak risk).

## Out of scope for Phase 1F (do NOT pull in)
- **Removing/altering the `GET /admin/export/registrations` CSV route** — kept for back-compat (§6); 4.2 removes only the UI entry point.
- **Field-scoped deliverable export** — Phase 3 (§6/§9).
- **A per-row bulk-mutation audit trail** — Phase 3 deferral (spec §481); the select-all expansion does not add one.
- **Cohort lens / trajectory-roster bulk actions** — Phase 2.
- **"Fixing" the deferred-stub bulk actions** (`stride_bulk_message`/`stride_bulk_generate_doc`) — they remain Phase-2/3 stubs; 4.1 only changes how ids reach handlers, not the stubs.

## Self-review (against the spec)
- **Spec coverage:** Task 4.1 select-all server expansion (capped, filter-carry, F2/F3 boundary) ✓; Task 4.2 worklist-export entry-point removal, CSV route kept (§6) ✓; the §5 "never load 4k rows client-side" contract preserved (client carries the filter) ✓; the §525 boundary note's "server expands to ids inside a capped batch + max-batch guard + 'dit raakt N — bevestig' confirm" ✓.
- **Gates fired + reasoned:** 1a threat-modeling ASSESSED + NOT fired (the server-trusted-filter→mutation surface assessed literally; empty-filter + over-broad-mutation named as inherited+tested mitigations; tier stays STANDARD with one-way escalation) ✓; 1b architecture-invariants cited (INV-3 repo method + the two Sibling-site audits) ✓; 1g feature-acceptance FIRED (D1/D2 matrix, six edges, incl. empty-filter/over-cap/filter-respected/trajectory-child/denied/concurrent) ✓.
- **Per-task tiers:** 4.1 Tier A (RED-first, six-assertion contract incl. denial + the leak/respect security edges + the payload contract + cap-reuse + empty-filter mitigation) ✓; 4.2 Tier B (reason: UI removal, replacement covered by F1) ✓.
- **Review cluster sized + tiered:** cluster D = 2 tasks (≤4, 1f) ✓; provisional tier STANDARD with one-way escalation note ✓; `── REVIEW GATE D ──` marker + HALT ✓; last-cluster spec-close-panel note ✓.
- **Drift caught:** `idsForGridFilter` is net-new (#1); the expansion must populate `$params['ids']` at handler entry so the quote pre-resolver sees it (#2 — the real subtlety); `selectAllFiltered` must stop sending page-ids under the new model (#3); the client `filter` is the structured `gridFilters` subset minus paging (#4); CSV route stays (#5). No premise fatally wrong.
