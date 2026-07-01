# Inschrijvingen Group-by Accordion Restoration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Work class:** A — multi-task, multi-file feature restoration (server row-composer refactor → new grouped-child-row contract on the repository → new grouped-child-row composition in the service → shared PHP `<tr>` partial → JS accordion wiring) with one real design decision (row-loading strategy).

**Goal:** Restore the "indelen per" (group-by) grid to a working ACCORDION — each group header (aggregates) expands to reveal the individual enrollment rows of that group, matching `docs/mockups/admin-workspace/inschrijvingen.html:272-341`.

**Architecture:** The grouped response gains a bounded `rows` array per group, composed by the SAME server row-composer the flat path uses (extracted to a shared `composeRows()`), over the `group_reg_ids` the grouped path ALREADY resolves. The template's grouped table is rebuilt into `<tbody class="ws-group">` sections reusing a shared `<tr>` partial (extracted once, included by both the flat and grouped tables). The JS `collapsed{}`/`toggleGroup()` scaffolding — currently dead — becomes wired to real per-group rows.

**Tech Stack:** WordPress (Bedrock) mu-plugin `stride-core`; PHP 8.3; Alpine.js (grid.js); Playwright (acceptance + JS-unit); PHPUnit (integration).

---

## Global Constraints

- Server-driven, ONE fetch per view change — NO client-side corpus (grid.js header rule; plan §5 of the rebuild). A group's rows must come from the server, never sliced from a 4k-row client array.
- Clean, no duplication, no dead code, efficient and safe (Stefan's explicit ask). Grouped child rows REUSE the flat row markup AND the flat server row-composer — neither is duplicated.
- UI text in Dutch (nl_BE); code in English.
- INV-3 (data access via repository), INV-5 (rendering via the plugin's own template tree — the plugin never calls the theme), INV-7 (status/offerte AS RECEIVED, `company.id`/`company.name` independent) hold on every touched path.
- `stride-core` (mu-plugin) MUST NOT call theme helpers. The shared `<tr>` partial lives in `stride-core/templates/admin/dashboard/` and is included via `require` from a sibling template inside the same plugin tree — plugin→plugin, INV-5-clean.

---

## Ground-truth reconciliation (Stage 1c — done before this plan shipped)

Every file:line in the brief was read against current source on `feat/admin-url-filter-state`. Corrections:

| Brief claim | Actual | Impact |
|---|---|---|
| grid.js `load()` "lines ~380-419" | `load()` is **370-420**; grouped branch **400-406** | none — same block |
| grid.js `groupLabel/groupKindLabel ~516-528` | **517-529** (`groupKindLabel` 517, `groupLabel` 520, `toggleGroup` 530) | none |
| service `getFlatPage() ~112-270` | **112-270** exactly; per-row composer loop **178-267** | confirms extraction boundary |
| service `getGroupedPage() ~330-390` | **330-390** exactly | confirmed |
| repo `queryForGridGrouped ~1865` | **1865**; `group_reg_ids` built **1959-1974** via an **unbounded** `$idsSql` (selects only `r.id, r.{groupBy}`) | **decisive** — see decision below |
| template flat rows "~157-245" | flat `<table>` **157-244**, `x-for="r in rows"` **183-242** | this is the REUSE target markup |
| template grouped table "~247-271" | aggregate-only grouped table **247-271** | this is the REBUILD target |
| template gates grouped on `total > 0` | grouped table gated `!loading && !error && groupBy && total > 0` (**247**); `total` stays the **registration** count; group count already read as `groups.length` (**356**) | the accordion child-row rendering does NOT change this gate — confirmed safe |

**Additional confirmed facts that drive the design:**
- The mockup's grouped `<tr>` markup (`inschrijvingen.html:315-340`) binds OLD flat-scalar keys (`r.name`, `editions[r.edition]`, `regStatus[r.status]`). It **cannot be ported verbatim** — the accordion `<tr>` must be rebuilt from the REBUILT flat markup (`inschrijvingen.php:183-242`, nested keys `r.user.name`, `r.status.value/label`, `r.offerteStatus`, `r.company.{id,name}`, `r.trajectory.title`, `r.anonymous`).
- `buildGridFilters()` (repo `2113`+) is the SINGLE WHERE/JOIN source for `queryForGrid`, `queryForGridGrouped`, and `idsForGridFilter`. Its base predicate `r.edition_id IS NOT NULL` (`2159`) excludes trajectory PARENT rows — the leak-check. Reusing it for child-row composition inherits scope/company/status/anonymous/trajectory-parent-exclusion **by construction**.
- `getGroupedPage()` already merges `$allRegIds = array_merge(...group_reg_ids)` and runs the two-step offerte resolver over them (`366-368`). The corpus for composing rows is **already gathered**.
- Dashboard surface partials are included via plain `require __DIR__ . '/dashboard/<x>.php'` from `templates/admin/dashboard.php` (`34-64`) — no theme call anywhere. A shared `<tr>` partial included the same way is INV-5-clean.
- Endpoint param sanitization (`AdminAPIController::getRegistrations` `1580-1607`) is shared by flat and grouped (same endpoint, same `group_by` param). In Strategy A the child rows ride the SAME request — no new endpoint, no new param surface.

---

## Row-loading decision — STRATEGY A (embed bounded rows in the grouped response)

**Decision: A — embed each group's rows in the grouped response, with a per-group row cap (`GROUP_ROW_CAP = 8`) and a "toon meer" affordance surfaced from a `has_more`/`row_total` field.**

### Why A over B (lazy per-group fetch)

1. **The corpus is already in memory — B would re-fetch what A already has.** `queryForGridGrouped` already pulls **every** reg-id of every visible group into PHP (the unbounded `$idsSql`, repo `1959-1974`, and the acknowledged `TODO(perf)` at `1923`). The offerte tally already loops over exactly this set. Strategy A composes rows from IDs that are **already resolved server-side in the same request**. Strategy B would throw that corpus away and issue a *second* scoped query per group-expand — strictly more queries for data we already fetched. That is the opposite of "efficient, no duplication."

2. **A adds ZERO new attack surface; B adds a new param/branch.** A rides the existing `GET /admin/registrations?group_by=…` request through the existing sanitized param path and the existing `buildGridFilters` WHERE. B needs a new "rows-for-this-group" param (a group-value the server must re-validate against the allowlist AND re-scope), i.e. a new place a wrong scope/leak can enter. Fewer branches on a data-access + capability surface is the safe choice (Stefan: "safe").

3. **A reuses BOTH convergence points; B risks a third scoping path.** A's child rows are composed by the extracted `composeRows()` (one composer) over IDs from `buildGridFilters` (one WHERE). B's scoped-fetch is a new call site that must re-derive the same group predicate — a third place `group_value → rows` scoping is decided, the exact drift INV-3 exists to prevent.

4. **Instant expand, no per-group loading state.** The mockup design (`collapsed{}`, click-to-toggle) is a pure client toggle over already-present rows. A matches it 1:1; B would bolt a loading spinner + error state onto each header the mockup never had.

### The bound (why a cap, and why 8)

An ungrouped group can hold 34+ rows (seeded `confirmed` = 26; production groups larger). Embedding **all** rows for **all** visible groups would inflate the grouped response unboundedly (the very perf smell the repo `TODO` flags). So:

- The server composes at most `GROUP_ROW_CAP = 8` rows per group (ordered by the same `registered_at DESC` default the flat path uses), and returns `row_total` (= the group's full `count`, already known — it IS `agg.cnt`) so the client can show "Toon alle N" when `count > 8`.
- "Toon alle N" navigates to the FLAT grid pre-filtered to that group (e.g. `groupBy=status` → set `filters.status=<value>` and clear grouping), which is the paginated, bounded, already-built surface. It does NOT lazy-append (that would reintroduce a client corpus). This keeps ONE bounded response and reuses the flat grid for the "see everything" case.

**Net query cost of A:** the grouped path's existing queries + the batch resolves (`batchGetUsers` / `batchGetPosts` / attendance / offerte) run over the CAPPED id set (≤ 8 × visible-groups) instead of the full corpus — i.e. A can make the response *cheaper* than today's unbounded tally by capping BEFORE the batch resolves. No N+1 across groups (all batch, keyed by id).

### Dead-code consequence

- `collapsed{}` + `toggleGroup()` (grid.js `270`,`530`) become WIRED (real rows to toggle) — kept.
- `avg_attendance_pct` is currently hardcoded `null` in grouped mode (service `384`). With child rows composed, the per-row `attendancePct` is now available; the header aggregate MAY compute a real average. **Decision: keep the header aggregate deferred (`null` → "—") for THIS plan** — computing a true cross-edition average is a separate concern (the mockup header shows "—" gracefully; the child rows show real per-row `%`). The `null` is not dead (the template renders "—" from it); leaving it is not a cleanliness violation. Noted so a reviewer does not flag it as an oversight.

---

## Golden path: form-data-flow / read-model extension (deviations named)

- [ ] Built to the read-model shape already established by `AdminRegistrationQueryService` (INV-3 repository read → service composition → thin controller). Read `netdust-wp:ntdst-patterns → golden-paths/content-type-feature.md` for the Repository→Service→Controller spine before task breakdown.
- [ ] Deviations from the slice (each named + justified):
  - "No new endpoint / no new CPT — this EXTENDS an existing read-model (`GET /admin/registrations` grouped branch) with an additive `rows` field. The controller callback is unchanged (same params, same envelope shape + one additive per-group key)."
  - "Rendering is an Alpine template partial included via `require`, not `ntdst_router()->single()` — this is an ADMIN dashboard surface already rendered inside the plugin's own `templates/admin/dashboard/` tree (dashboard.php `require`s each surface). INV-5-clean because it is plugin→plugin, never a theme helper call."

---

## WP security requirements (per data-flow)

Reference: `netdust-wp:wp-security` (four pillars). This feature introduces **NO new endpoint and NO new write** — it extends the existing read-only `GET /admin/registrations` grouped response with an additive `rows` field. The data-flow surface is therefore the existing endpoint, re-verified for the new field:

- [ ] **GET `/admin/registrations` (grouped, additive `rows`)**: authorize — UNCHANGED `permission_callback => canViewAdmin` (`current_user_can('stride_view')`, controller `542`,`549-552`); no new cap. validate — group_by still hard-validated against `GROUP_BY_ALLOWLIST` (service `337`, WP_Error 400 on miss); the new `GROUP_ROW_CAP` is a server constant, never a client param (no new input to validate). sanitize — child rows ride the SAME sanitized `$params` and the SAME `buildGridFilters` WHERE as the flat path (all binds via `$wpdb->prepare`, M4/M5 preserved); NO new user input reaches SQL. escape — child rows render through the SAME Alpine `x-text`/`icon('<literal>')` bindings as the flat rows (auto-escaped; INV-5 — `x-html` only ever binds a CONSTANT icon name). The child-row response must NOT expose any field the flat row does not (no fuller shape in grouped mode) — enforced by composing via the shared `composeRows()`.
- [ ] **Anonymous-identity + company-independence parity (data-exposure boundary)**: grouped child rows MUST apply the SAME `resolveAnonymousIdentity()` decode and the SAME `company.id`/`company.name` independence as flat rows. Guaranteed by construction (same `composeRows()`), and asserted by the Task 5 parity test (grouped child rows === flat rows for the same filter, incl. the anonymous row and the company-independent row).

> escape/validate/sanitize/authorize are ALL accounted for above; none is silently omitted (the new field adds no input pillar, and inherits the existing output pillar).

## ntdst-core layering requirements

- [ ] Data access goes through `RegistrationRepository` — the capped-per-group child-row id+column fetch is a REPOSITORY method (`queryForGridGroupedRows` or an extension of the existing `$idsSql`), never a `$wpdb` SELECT in `AdminRegistrationQueryService`. (INV-3.)
- [ ] No duplicated row composition — the flat composer loop (service `178-267`) is extracted to a private `composeRows(array $rows, ...): array` called by BOTH `getFlatPage()` and the grouped child-row path. (No second copy of identity/status/attendance/company/trajectory/offerte assembly.)
- [ ] No pure pass-through service methods added.
- [ ] No raw `wp_ajax_*` — this is REST, unchanged.
- [ ] No `ob_start()+include` rendering — the shared `<tr>` is an Alpine template partial `require`d into the surface (matches the existing dashboard partial pattern).
- [ ] No swallowed `WP_Error` — grouped path still returns `invalid_group_by` WP_Error on bad axis; the row-composition path returns the envelope or propagates.
- [ ] Data API vocabulary / meta prefix — the child-row fetch reads STRUCTURED columns only (id, user_id, edition_id, trajectory_id, status, company_id, enrollment_data) via the repo, same M5 discipline as `queryForGrid`; no hardcoded `_ntdst_*` outside the repo.
- [ ] Correct module layering — Repository (data) / Admin service (composition) / controller (thin) / template (render) unchanged.

> **The convergence contract:** these blocks are the convergence target for `/code-review` and `ntdst-drift-reviewer` at shake-out. Reviewers verify the diff against the named golden-path deviations + pillars + categories above (and the invariants cited below), not free-form — a gap is a one-line finding keyed to a named item, not a re-discovery.

---

## Architecture-invariants citation (gate 1b)

Touched convergence points (from `ARCHITECTURE-INVARIANTS.md`):

- **INV-3 (Data access via the per-domain Repository).** The new capped-per-group child-row query is added to `RegistrationRepository` (the sole `$wpdb` caller for `wp_vad_registrations`). `AdminRegistrationQueryService` composes but issues no `$wpdb`. **Bypass signal a reviewer checks:** any `$wpdb` in the service, or a second WHERE that isn't `buildGridFilters`.
- **INV-5 (Rendering via the plugin's own template tree; the plugin never calls the theme).** The shared `<tr>` partial lives in `stride-core/templates/admin/dashboard/` and is `require`d by both tables inside the plugin. Every `x-html` binds a CONSTANT icon name (`icon('<literal>')`); all data renders via `x-text` (auto-escaped). **Bypass signal:** an `x-html` bound to a data field, or a `stridence_*` theme-helper call from the plugin.
- **INV-7 (Status/offerte AS RECEIVED; `company.id`/`company.name` independent).** Child rows render `r.status.value/label` and `r.offerteStatus` exactly as the server emits them (no client re-derivation); `r.company.id` and `r.company.name` stay side-by-side, never merged. Guaranteed because child rows are composed by the SAME `composeRows()` the flat path uses. **Bypass signal:** a client-side status recomputation in the grouped `<tr>`, or a merged company cell.

No NEW convergence point is introduced (this extends existing read-model composition), so no `ARCHITECTURE-INVARIANTS.md` edit is required — only citation.

---

## Sibling-site audit

- [ ] **`composeRows()` extraction:** after extracting the composer, confirm `getFlatPage()` and the grouped child-row path are the ONLY two callers, and that the flat path's output is byte-identical to pre-extraction (the parity test + the existing `RegistrationGridQueryTest`/`AdminRegistrationsEndpointTest` green prove no drift).
- [ ] **`GROUP_BY_ALLOWLIST`** (repo `27`, mirrored in grid.js `152`): the child-row feature adds NO new group axis — verify no fourth axis leaks in. The three axes (`edition_id`,`status`,`company_id`) already covered by `AdminRegistrationsEndpointTest`.
- [ ] **The two trajectory parent→child join sites** (`buildGridFilters` `2202-2211` and `findChildRegistrationIdsByTrajectory`): the child-row fetch reuses `buildGridFilters`, so it inherits the parent-exclusion — confirm no third parent→child scoping copy is introduced.
- [ ] **`ws-group` / `collapsed` / `toggleGroup` CSS + JS pair:** the accordion markup relies on `.ws-group.is-expanded` and `.ws-grouphead.is-collapsed` — verify the CSS classes the mockup uses (`inschrijvingen.html:284-341`) exist in the admin workspace CSS or are added.

---

## Threat model (gate 1a) — scoped result: NOT triggered for a NEW model; existing surface re-verified

Ran the 1a trigger list literally against this work:
- User-controlled URLs / outbound requests to user-supplied addresses — **no**.
- Auth/session/token surfaces — **no** (no cap/permission change; `canViewAdmin` unchanged).
- Untrusted parsing — **no NEW** parsing. `enrollment_data` JSON is decoded by the EXISTING `resolveAnonymousIdentity()` (unchanged, already threat-covered).
- BYOK credentials — **no**.
- Multi-tenancy / role-based isolation — **this is the one to watch**: the grouped child rows are a data-EXPOSURE surface (admin registration data, incl. anonymous-lead identity + company scoping). BUT the exposure is bounded by `buildGridFilters` (same active-scope, company, status, trajectory-parent-exclusion as the already-shipped flat path) and composed by the same `composeRows()` — the child rows can expose **no row and no field** the flat grid does not already expose to the same `stride_view` actor. No new tenant boundary, no partner-API surface, no cross-role visibility widening.

**Conclusion:** no NEW `## Threat model` section is warranted (no new asset, actor, or attack introduced). The single security-relevant property — "grouped mode must not leak a fuller row shape or a broader row set than flat mode" — is captured as the parity requirement in the WP-security block and enforced by the Task 5 parity test. `/security-review` is therefore NOT auto-mandatory (no plan-time threat model authored); the FULL-tier cluster review below still runs `security-sentinel` because the diff touches the data layer (1h trigger).

---

## File structure

| File | Responsibility | Change |
|---|---|---|
| `web/app/mu-plugins/stride-core/Admin/AdminRegistrationQueryService.php` | Read-model composition | Extract `composeRows()` from `getFlatPage`; add capped child-row composition to `getGroupedPage` |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php` | `wp_vad_registrations` data access | Add capped-per-group child-row column fetch (extends/parallels `$idsSql`) |
| `web/app/mu-plugins/stride-core/templates/admin/dashboard/_reg-row.php` | Shared `<tr>` Alpine partial | **Create** — the one row markup both tables use |
| `web/app/mu-plugins/stride-core/templates/admin/dashboard/inschrijvingen.php` | Grid surface | Flat table `require`s `_reg-row.php`; grouped table rebuilt into `<tbody class="ws-group">` accordion |
| `web/app/mu-plugins/stride-core/assets/js/admin/grid.js` | Alpine factory | Store per-group `rows`; wire `collapsed`/`toggleGroup` to real rows; `showAllInGroup()` → flat pre-filter; add pure `groupRowsFrom()` mapper |
| `tests/Integration/RegistrationGridQueryTest.php` | Grouped parity | Add child-rows-===-flat-rows parity test (incl. anon + company-independent + cap) |
| `tests/frontend/admin/grid-mappers.spec.ts` | JS pure mappers | Add `groupRowsFrom()` Tier-A unit test |
| `tests/frontend/admin/inschrijvingen-grid.spec.ts` | Real-browser acceptance | Add the accordion expand/collapse acceptance flow |

---

# Tasks

Clusters are sized ≤4 tasks (1f). Provisional review tiers assigned per 1h.

---

## CLUSTER 1 — Server: extract `composeRows()` + capped child-row fetch (Tasks 1–3)

### Task 1: Extract `composeRows()` from `getFlatPage()` (pure refactor, behavior-preserving)

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminRegistrationQueryService.php:178-269`
- Test: `tests/Integration/RegistrationGridQueryTest.php` (existing green suite is the safety net)

**Interfaces:**
- Produces: `private function composeRows(array $rows, array $users, array $editions, array $trajectories, array $sessionCountByEdition, array $attendanceByEdition, array $offerteByReg): array` — the per-row loop currently inline at `178-267`, returning the item array (`id/user/anonymous/edition/status/offerteStatus/attendancePct/company/trajectory`). `getFlatPage()` calls it after its batch resolves.

- [ ] **Step 1: Confirm RED is impossible for a pure refactor — assert the existing parity baseline is GREEN first**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationGridQuery`
Expected: PASS (this is the behavior contract the extraction must preserve).

- [ ] **Step 2: Extract the `178-267` loop into `composeRows()`**, taking the already-resolved batch maps as params. `getFlatPage()` keeps its own ID-collection + batch-resolve (`124-176`), then calls `composeRows(...)`, then `paginationEnvelope(...)`. No behavior change.

- [ ] **Step 3: Run the flat-path integration + endpoint tests — must stay GREEN**

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "RegistrationGridQuery|AdminRegistrationsEndpoint|AdminRegistrationAnonymousIdentity|AdminRegistrationAttendancePct"`
Expected: PASS (identical output — extraction is transparent).

- [ ] **Step 4: PHPStan + Pint**

Run: `ddev exec composer lint:stan` then `ddev exec vendor/bin/pint web/app/mu-plugins/stride-core/Admin/AdminRegistrationQueryService.php`
Expected: clean.

- [ ] **Step 5: Commit** — `refactor(admin): extract composeRows() from getFlatPage (behavior-preserving)`

**Tier: B** — pure structural extraction, no new behavior. `no unit test: Tier B, extraction covered by the existing green flat-path integration + endpoint suite (the parity baseline).` Seam: n/a (no wiring changed).

---

### Task 2: Repository — capped-per-group child-row column fetch

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php:1865-1983` (add a method or extend the return of `queryForGridGrouped`)
- Test: `tests/Integration/RegistrationGridQueryTest.php`

**Interfaces:**
- Consumes: `buildGridFilters()` (the single WHERE source), the visible-page group values from the agg query.
- Produces: `public const GROUP_ROW_CAP = 8;` and a per-group id+column fetch that returns, per visible group value, up to `GROUP_ROW_CAP` FULL rows (same column set as `queryForGrid`'s `$dataSql`: `id,user_id,edition_id,trajectory_id,parent_registration_id,status,enrollment_path,company_id,registered_at,completed_at,cancelled_at,quote_id,enrolled_by,notes,enrollment_data`) ordered `registered_at DESC`, keyed `group_value => object[]`. Return shape extends the existing `queryForGridGrouped` array with `'group_rows' => array<string,array<object>>` (parallel to `group_reg_ids`).

  Implementation note: the existing `$idsSql` (`1959-1965`) already selects per-group ids over `$fullWhere`. Extend it to select the full column set AND apply a per-group cap. A per-group `LIMIT` requires either a windowed query (`ROW_NUMBER() OVER (PARTITION BY r.{groupBy} ORDER BY r.registered_at DESC)` filtered `<= GROUP_ROW_CAP` — MariaDB 10.11 supports window functions) OR a per-group-value bounded UNION. **Prefer the window-function approach** (one query, no N+1). `group_reg_ids` (the FULL id set) is STILL returned for the offerte tally — the cap applies ONLY to the composed-row set, not the aggregate/tally. This means the tally stays correct while the composed rows are bounded.

- [ ] **Step 1: Write the failing integration test** — assert capped child rows

```php
public function test_grouped_child_rows_are_capped_and_scoped(): void
{
    // seed >GROUP_ROW_CAP confirmed rows in one edition group, plus a
    // trajectory PARENT (edition_id NULL) that must NOT appear in any group.
    $repo = ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class);
    $result = $repo->queryForGridGrouped(['per_page' => 50], 'status');

    $this->assertArrayHasKey('group_rows', $result);
    foreach ($result['group_rows'] as $gv => $rows) {
        $this->assertLessThanOrEqual(
            \Stride\Modules\Enrollment\RegistrationRepository::GROUP_ROW_CAP,
            count($rows),
            "group '{$gv}' child rows must be capped at GROUP_ROW_CAP"
        );
        foreach ($rows as $r) {
            $this->assertNotNull($r->edition_id, 'no trajectory-parent (edition_id NULL) row may appear as a child');
        }
    }
    // group_reg_ids (the FULL set for the tally) is unbounded — cap is rows-only.
    // At least one group must have MORE reg_ids than composed rows to prove the cap bit.
    $capped = array_filter(
        $result['group_reg_ids'],
        fn($ids, $gv) => count($ids) > count($result['group_rows'][$gv] ?? []),
        ARRAY_FILTER_USE_BOTH
    );
    $this->assertNotEmpty($capped, 'at least one group must exceed the row cap to prove bounding');
}
```

- [ ] **Step 2: Run it — expect FAIL** (`group_rows` key missing)

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter test_grouped_child_rows_are_capped_and_scoped`
Expected: FAIL — `Failed asserting that an array has the key 'group_rows'`.

- [ ] **Step 3: Implement** — add `GROUP_ROW_CAP` const + the window-function per-group capped full-column fetch reusing `buildGridFilters` + the `$fullWhere` group filter; return `group_rows` alongside `group_reg_ids`.

- [ ] **Step 4: Run — expect PASS**, then the full grid query suite stays green.

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter RegistrationGridQuery`
Expected: PASS.

- [ ] **Step 5: PHPStan + Pint, then commit** — `feat(admin): capped per-group child-row fetch in RegistrationRepository (INV-3)`

**Tier: A** — new SQL data-access path with a scope/cap contract (the leak-and-bound surface). Test contract: RED-first integration test asserts (a) every composed child row is capped at `GROUP_ROW_CAP`, (b) no trajectory-parent (`edition_id NULL`) row appears as a child — the denial/leak path — and (c) the cap actually bit on at least one group. Seam: the un-mocked repo→DB chain over seeded rows.

---

### Task 3: Service — compose capped child rows into the grouped response

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminRegistrationQueryService.php:330-390`
- Test: `tests/Integration/RegistrationGridQueryTest.php`

**Interfaces:**
- Consumes: `composeRows()` (Task 1), `group_rows` (Task 2).
- Produces: each grouped `items[]` entry gains `'rows' => array` (the composed child rows for that group, ≤ cap) and `'row_total' => int` (= `count`, the full group size, so the client shows "Toon alle N" when `row_total > rows.length`). Existing keys (`group_value/count/pct_afgerond/avg_attendance_pct/offerte_verdeling`) unchanged.

  `getGroupedPage()` collects the union of `group_rows` row objects, runs the SAME batch resolves `getFlatPage` does (users/editions/trajectories/session-counts/attendance) over the CAPPED id set, calls `composeRows(...)` once, then buckets the composed items back per group by `group_value`. Reuses the already-computed `$offerteByReg` (`368`).

- [ ] **Step 1: Write the failing parity test** — grouped child rows === flat rows for the same filter (see Task 5 for the full parity assertion; here assert the field is present + shaped)

```php
public function test_grouped_response_carries_composed_child_rows(): void
{
    $service = ntdst_get(\Stride\Admin\AdminRegistrationQueryService::class);
    $dto = $service->getGridPage(['group_by' => 'status', 'per_page' => 50]);
    $this->assertNotInstanceOf(\WP_Error::class, $dto);
    foreach ($dto['items'] as $group) {
        $this->assertArrayHasKey('rows', $group);
        $this->assertArrayHasKey('row_total', $group);
        $this->assertLessThanOrEqual($group['row_total'], count($group['rows']));
        foreach ($group['rows'] as $row) {
            // same nested shape as a flat item — no fuller shape in grouped mode
            $this->assertSame(
                ['id','user','anonymous','edition','status','offerteStatus','attendancePct','company','trajectory'],
                array_keys($row),
                'grouped child row shape must equal the flat row shape (no fuller/leaked fields)'
            );
        }
    }
}
```

- [ ] **Step 2: Run — expect FAIL** (`rows` key missing).

- [ ] **Step 3: Implement** the child-row composition in `getGroupedPage()` reusing `composeRows()` + the existing offerte map.

- [ ] **Step 4: Run — expect PASS**; full grid query + endpoint suites green.

- [ ] **Step 5: PHPStan + Pint, commit** — `feat(admin): compose capped group child rows via shared composeRows() (INV-3/INV-7)`

**Tier: A** — the data-exposure composition (the shape-parity boundary). Test contract: RED-first — grouped child rows carry the SAME key set as flat rows (no fuller/leaked shape) and `rows.length <= row_total`. Seam: service→repo→DB over seeded rows. Deferral: cross-actor/company-independence + anonymous parity → the Task 5 dedicated parity test.

**── REVIEW GATE ── (tier: FULL — cluster adds a new SQL data-access path + a data-exposure read-model composition; 1a data-layer trigger. Run `security-sentinel` + `ntdst-drift-reviewer` + all finder angles; `/code-review --effort=high`; `/integration` on the cluster diff. No plan-time threat model → `/security-review` not auto-required, but FULL-tier `security-sentinel` verifies the leak/shape-parity boundary.)**

---

## CLUSTER 2 — Presentation: shared row partial + accordion template + JS wiring (Tasks 4–6)

### Task 4: Extract the shared `<tr>` partial and re-point the flat table

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/admin/dashboard/_reg-row.php`
- Modify: `web/app/mu-plugins/stride-core/templates/admin/dashboard/inschrijvingen.php:182-243`

**Interfaces:**
- Produces: `_reg-row.php` — the `<tr>` body currently at `inschrijvingen.php:184-241` (nested keys `r.user.name`, `r.status.value/label`, `r.offerteStatus`, `r.attendancePct`, `r.company.{id,name}`, `r.trajectory.title`, `r.anonymous`, `r.user.id`). It is markup-only (Alpine bindings + `<?php esc_html__() ?>` labels); it assumes an enclosing `x-for` provides `r`. The flat `<tbody>` `require`s it inside its `x-for="r in rows"`.

- [ ] **Step 1** (Tier B — no RED unit test): move the `<tr>…</tr>` (lines `184-241`) verbatim into `_reg-row.php` (keep the `defined('ABSPATH')||exit;` guard). In the flat table, replace the moved markup with `<template x-for="r in rows" :key="r.id"><?php require __DIR__ . '/_reg-row.php'; ?></template>`.

- [ ] **Step 2: Verify no visual/behavior regression via the EXISTING acceptance suite** (the flat-grid cold-landing tests must stay green — they assert real rows render).

Run: `cd /home/ntdst/Sites/stride-admin && npx playwright test tests/frontend/admin/inschrijvingen-grid.spec.ts`
Expected: PASS (flat grid unchanged — partial extraction is transparent).

- [ ] **Step 3: Commit** — `refactor(admin): extract shared _reg-row.php partial, flat table reuses it (INV-5)`

**Tier: B** — presentational extraction, no logic. `no unit test: Tier B, transparent markup move covered by the existing flat-grid Playwright acceptance suite.` Seam: n/a.

---

### Task 5: Server parity test — grouped child rows === flat rows (incl. anon + company independence)

**Files:**
- Modify: `tests/Integration/RegistrationGridQueryTest.php`

**Interfaces:**
- Consumes: `getGridPage()` flat + grouped (Tasks 1–3).

- [ ] **Step 1: Write the parity test (RED — before it can pass, Task 3 must be complete; if written first it fails on the missing `rows`)**

```php
public function test_grouped_child_rows_match_flat_rows_for_same_filter(): void
{
    $service = ntdst_get(\Stride\Admin\AdminRegistrationQueryService::class);

    // Flat rows for status=interest (the axis that surfaces anonymous leads).
    $flat = $service->getGridPage(['status' => 'interest', 'per_page' => 50]);
    $flatById = [];
    foreach ($flat['items'] as $r) { $flatById[$r['id']] = $r; }

    // Grouped by status, then the 'interest' group's child rows.
    $grouped = $service->getGridPage(['group_by' => 'status', 'per_page' => 50]);
    $interestGroup = null;
    foreach ($grouped['items'] as $g) {
        if ($g['group_value'] === 'interest') { $interestGroup = $g; break; }
    }
    $this->assertNotNull($interestGroup, 'interest group present');

    // Every composed child row must be byte-identical to the flat row of the same id
    // (same identity incl. anonymous fallback, same company.id/name independence,
    //  same status AS RECEIVED, same offerteStatus) — no drift, no fuller shape.
    foreach ($interestGroup['rows'] as $childRow) {
        $this->assertArrayHasKey($childRow['id'], $flatById, 'child row id exists in the flat set (same scope)');
        $this->assertSame(
            $flatById[$childRow['id']],
            $childRow,
            "grouped child row {$childRow['id']} must equal the flat row (identity/company/status/offerte parity)"
        );
    }

    // Explicit anonymous-lead assertion: at least one interest child row is anonymous
    // and its name/email came from the enrollment_data decode (not a wp_users join).
    $anon = array_values(array_filter($interestGroup['rows'], fn($r) => $r['anonymous'] === true));
    if ($anon) {
        $this->assertNotSame('', $anon[0]['user']['name'], 'anon row resolves a captured name');
    }
    // Company independence: company.id (FK) and company.name (billing) are separate keys.
    foreach ($interestGroup['rows'] as $r) {
        $this->assertArrayHasKey('id', $r['company']);
        $this->assertArrayHasKey('name', $r['company']);
    }
}
```

- [ ] **Step 2: Run — expect PASS** (after Tasks 1–3). If any field diverges, the composer was not truly shared — fix the composer, not the test.

Run: `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter test_grouped_child_rows_match_flat_rows_for_same_filter`
Expected: PASS.

- [ ] **Step 3: Commit** — `test(admin): grouped child rows === flat rows parity (anon + company independence)`

**Tier: A** — this IS the effectiveness test for the whole feature's safety property (no leak, no drift, no fuller shape). Test contract: RED-first parity — grouped child row byte-equals the flat row of the same id, including the anonymous-identity decode and the `company.id`/`company.name` independence. Seam: service→repo→DB, un-mocked.

---

### Task 6: JS — wire the accordion (per-group rows + toggle + show-all) and its pure mapper

**Files:**
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin/grid.js:400-406` (load) `+ 516-535` (grouping helpers) `+ 700-711` (UMD exports)
- Modify: `web/app/mu-plugins/stride-core/templates/admin/dashboard/inschrijvingen.php:247-271` (rebuild grouped table into the accordion)
- Test: `tests/frontend/admin/grid-mappers.spec.ts`

**Interfaces:**
- Produces (pure, Tier-A): `groupRowsFrom(group)` — given a grouped item `{ group_value, count, rows, row_total, … }`, returns `{ key: String(group_value), label: <via groupLabel>, rows: group.rows || [], count: group.count, hasMore: (group.row_total || 0) > (group.rows||[]).length, rowTotal: group.row_total }`. Exported on the UMD tail so the mapper test imports it without a browser. It NORMALIZES `group_value` to a stable string `key` (used by `collapsed[key]` + `toggleGroup(key)`), fixing that `collapsed{}` was previously keyed by nothing.
- Produces (factory): `groupsView` getter mapping `this.groups.map(groupRowsFrom)` for the template `x-for`; `showAllInGroup(g)` — for `groupBy==='status'` sets `filters.status=g.group_value` and clears grouping (`groupBy=''`), then `load(1)`; for `edition_id`/`company_id` sets the matching filter — reusing the flat, paginated grid for the full set (NO client corpus append).

- [ ] **Step 1: Write the failing JS unit test** for `groupRowsFrom`

```js
test.describe('groupRowsFrom', () => {
  test('maps a grouped item to a keyed accordion group with hasMore', () => {
    const g = { group_value: 'interest', count: 12, row_total: 12,
                rows: [{ id: 1 }, { id: 2 }] };
    const out = grid.groupRowsFrom(g);
    expect(out.key).toBe('interest');
    expect(out.rows).toHaveLength(2);
    expect(out.hasMore).toBe(true);        // 12 > 2
    expect(out.rowTotal).toBe(12);
  });
  test('null group_value coerces to a stable string key, no crash', () => {
    const out = grid.groupRowsFrom({ group_value: null, count: 0, row_total: 0, rows: [] });
    expect(typeof out.key).toBe('string'); // never null (collapsed[key] needs a string)
    expect(out.hasMore).toBe(false);
  });
});
```

- [ ] **Step 2: Run — expect FAIL** (`grid.groupRowsFrom is not a function`)

Run: `cd /home/ntdst/Sites/stride-admin && npx playwright test tests/frontend/admin/grid-mappers.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Implement** `groupRowsFrom` (pure, exported), the `groupsView` getter, `showAllInGroup()`; store `this.groups = data.items` unchanged (the child `rows` now ride inside each item). `toggleGroup`/`collapsed` stay but are now keyed by `groupRowsFrom().key`.

- [ ] **Step 4: Rebuild the grouped table** (`inschrijvingen.php:247-271`) into `<tbody class="ws-group">` per `g in groupsView`, each with a click-to-toggle `<tr class="ws-grouprow">` header (chevron `icon('chevDown')`, `groupKindLabel`, `g.label`, `g.count`, and the existing aggregate blocks) `@click="toggleGroup(g.key)"`, followed by `<template x-for="r in g.rows" :key="r.id"><?php require __DIR__ . '/_reg-row.php'; ?></template>` each `<tr x-show="!collapsed[g.key]">`, and a "Toon alle N" row `x-show="g.hasMore && !collapsed[g.key]"` `@click="showAllInGroup(g)"`. REUSES `_reg-row.php` (no markup duplication).

- [ ] **Step 5: Run the JS mapper unit + `tsc` check**

Run: `cd /home/ntdst/Sites/stride-admin && npx playwright test tests/frontend/admin/grid-mappers.spec.ts && npx tsc --noEmit -p tests/tsconfig.json 2>/dev/null || true`
Expected: mapper tests PASS.

- [ ] **Step 6: Commit** — `feat(admin): wire group-by accordion — per-group rows, toggle, show-all (reuses _reg-row.php)`

**Tier: A** (for the mapper) — `groupRowsFrom` is pure branching logic (keying + hasMore) with a null-coercion edge. Test contract: RED-first — maps a grouped item to a stable string `key` + correct `hasMore`, and a `null` group_value coerces to a string key without crashing (the `collapsed[key]` denial-of-crash path). The template rebuild + `showAllInGroup` glue is Tier-B (`no unit test: Tier B, presentational wiring covered by the Task 7 real-browser acceptance flow`). Seam: the accordion's real expand → rows-render is the Task 7 acceptance flow.

**── REVIEW GATE ── (tier: STANDARD — presentation cluster: template partial reuse + Alpine accordion wiring, NO 1a surface (no new endpoint/param/cap logic; data-exposure boundary already netted server-side in Cluster 1). 2 finder angles + `code-simplicity-reviewer` + the feature-acceptance browser pass (Task 7). No `security-sentinel`. `/code-review --effort=medium`; `/integration` on the cluster diff. One-way escalation: if any finder flags a data-shape/leak concern on the child-row render, promote to FULL.)**

---

## CLUSTER 3 — Acceptance + phase close (Task 7)

### Task 7: Real-browser acceptance flow — expand a group, rows appear and match flat; collapse hides

**Files:**
- Modify: `tests/frontend/admin/inschrijvingen-grid.spec.ts`

- [ ] **Step 1: Add the accordion acceptance test** (drives the real seeded backend via the existing backdoor login)

```ts
test('Group-by accordion: select "indelen per status" → headers render → expand shows real rows matching flat → collapse hides', async ({ page }) => {
  await loginAndLand(page, 'view=inschrijvingen');
  const grid = page.locator(GRID);
  await expect(grid).toBeVisible({ timeout: 15000 });
  await expect(grid.locator("table.ws-table:not(.ws-table--grouped) tbody tr").first()).toBeVisible({ timeout: 15000 });

  // Select "Indelen per status".
  await grid.locator("select.ws-select").filter({ has: page.locator("option[value='status']") }).first()
           .selectOption('status');

  // Group headers render (aggregate rows), flat table gone.
  const groups = grid.locator('table.ws-table--grouped tbody.ws-group');
  await expect(groups.first()).toBeVisible({ timeout: 15000 });
  expect(await groups.count()).toBeGreaterThan(1); // multiple seeded statuses

  // Pick the 'confirmed' group header (dominant seeded status) and expand it.
  const confirmedGroup = grid.locator('table.ws-table--grouped tbody.ws-group', {
    has: page.locator('.ws-grouphead__title', { hasText: 'Bevestigd' }),
  });
  await expect(confirmedGroup).toBeVisible({ timeout: 15000 });

  // Child rows are the <tr> AFTER the .ws-grouprow header, inside the same tbody.
  const childRows = confirmedGroup.locator('tr:not(.ws-grouprow)');

  // If it starts collapsed, click to expand; assert rows become visible.
  await confirmedGroup.locator('.ws-grouphead').click();
  await expect(childRows.first()).toBeVisible({ timeout: 15000 });
  expect(await childRows.filter({ hasText: 'Bevestigd' }).count()).toBeGreaterThan(0);

  // Each visible child row is a confirmed row (the group's own rows, correctly scoped).
  const n = Math.min(await childRows.count(), 5);
  for (let i = 0; i < n; i++) {
    await expect(childRows.nth(i)).toContainText('Bevestigd');
  }

  // Collapse hides them again.
  await confirmedGroup.locator('.ws-grouphead').click();
  await expect(childRows.first()).toBeHidden({ timeout: 15000 });
});
```

- [ ] **Step 2: Run the full admin acceptance suite**

Run: `cd /home/ntdst/Sites/stride-admin && npx playwright test tests/frontend/admin/inschrijvingen-grid.spec.ts`
Expected: PASS (all existing tests + the new accordion flow). If the seeded `confirmed` group starts EXPANDED by default, adjust the first click (the test asserts the toggle both ways regardless of initial state — verify the default and fix the assertion order if needed).

- [ ] **Step 3: Commit** — `test(admin): accordion expand/collapse acceptance flow (real seeded backend)`

**Tier: A** — the feature-behavior gate. Test contract: RED-first-ish (fails until Cluster 2 ships the accordion) — driving the real browser proves the group header expands to show correctly-scoped child rows and collapses to hide them. Seam: the full un-mocked chain (browser → shell api → grouped endpoint → repo → seeded DB).

**── REVIEW GATE ── (tier: STANDARD — test-only addition to an existing acceptance spec. Single generalist `reviewer` pass + confirm the new flow is GREEN in a real run, not just committed. If green, proceed to shake-out.)**

---

## Phase close (Stage 3)

1. **Integration gate:** `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist --filter "RegistrationGridQuery|AdminRegistrationsEndpoint"` + `npx playwright test tests/frontend/admin/` — all green.
2. **Test-effectiveness audit** over the branch diff: confirm the parity test (Task 5) goes RED if the composer stops being shared (mutate a field in one path, prove the test bites), and the cap test (Task 2) goes RED if `GROUP_ROW_CAP` is removed.
3. **Feature-acceptance:** drive the `## Acceptance flows` matrix below through the real browser.
4. **Shake-out** (`/shakeout`): branch review tier = **FULL** (the branch diff touches the data layer — Cluster 1). Panel: `reviewer` + `code-simplicity-reviewer` + `security-sentinel` + `performance-oracle` + `invariant-auditor` + `ntdst-drift-reviewer`.
5. **Finish** the branch.

---

## Acceptance flows

Reference: `netdust-agent:feature-acceptance`. One row per intended-use flow; Edges mandatory.

| # | Flow | Happy path | Edges (empty / denied / wrong-order / concurrent / boundary / mid-flow failure) | Layer |
|---|---|---|---|---|
| AF-1 | Group by status → expand a group → see that group's enrollment rows | Select "Indelen per status"; headers with aggregates render; click a header; the group's child rows appear, each showing the group's status; click again to collapse | **empty:** a status with 0 seeded rows renders NO group header (grouped `x-for` skips it) — no empty accordion. **denied:** a `stride_view`-only actor sees the SAME rows the flat grid already shows them (no wider exposure) — asserted by the Task 5 parity test, not the browser. **wrong-order:** clicking a header before the load settles is a no-op (rows not yet present; `collapsed[key]` still toggles harmlessly). **concurrent:** switching `groupBy` while expanded resets `collapsed={}` (grid.js `onGroupChange` `456`) — no stale open state. **boundary:** a group with exactly `GROUP_ROW_CAP` rows shows no "Toon alle N"; `CAP+1` shows it. **mid-flow failure:** a failed grouped fetch shows the error banner (existing), not a half-rendered accordion. | Real browser (Playwright, Task 7) + parity (PHPUnit, Task 5) |
| AF-2 | "Toon alle N" on an over-cap group → jump to the flat grid pre-filtered to that group | Expand an over-cap group; click "Toon alle N"; grid drops grouping and shows the flat, paginated, filtered list for that group value | **empty:** n/a (only shown when `hasMore`, i.e. `row_total > cap > 0`). **denied:** same scope as AF-1 (flat grid already gated `canViewAdmin`). **wrong-order:** clicking it while collapsed is impossible (`x-show="hasMore && !collapsed"`). **boundary:** exactly-cap groups never show the button. **mid-flow failure:** the follow-on flat `load(1)` uses the existing error banner. | Real browser (Playwright — add to Task 7 if time permits; else manual shake-out drive, recorded) |
| AF-3 | Group by editie / organisatie → same accordion behavior | Select "Editie" or "Organisatie"; headers + expand/collapse work identically | **empty:** "Geen editie"/"Geen organisatie" groups excluded (base predicate excludes `edition_id NULL`; company grouping over real ids). **boundary:** same cap behavior. Covered by the Task 5 parity test parameterization (three axes) at the server layer; browser-driven for `status` in Task 7 as the representative axis. | Parity (PHPUnit, three axes) + browser (status axis) |

---

## Self-review

- **Spec coverage:** the bug (aggregate-only grouped table) → Cluster 2 rebuilds it into the accordion (Task 6). The dropped child rows → Cluster 1 composes them server-side (Tasks 1–3). No-duplication → shared `composeRows()` (Task 1) + shared `_reg-row.php` (Task 4). No-dead-code → `collapsed`/`toggleGroup` wired (Task 6); `avg_attendance_pct` null decision documented. Efficient → cap-before-batch + reuse of the already-gathered corpus (decision §A). Safe → INV-3/5/7 cited, parity test (Task 5) enforces the no-leak/no-fuller-shape property, FULL-tier review on the data-layer cluster.
- **Type/name consistency:** `composeRows()` (Task 1) is consumed by Task 3; `group_rows`/`GROUP_ROW_CAP` (Task 2) consumed by Task 3; `groupRowsFrom`/`groupsView`/`showAllInGroup` (Task 6) consumed by the template; `row_total`/`hasMore` produced Task 3 → consumed Task 6. Names match across tasks.
- **Placeholder scan:** every code step carries real code or an exact command. The one implementation detail left to the implementer (window-function vs UNION for the per-group cap) is named with the preferred approach and a MariaDB-10.11 capability note — a bounded technique choice, not a placeholder.

---

## Execution handoff

Plan complete. Recommended: **subagent-driven-development**, one implementer per task, HALT at each `── REVIEW GATE ──`.
