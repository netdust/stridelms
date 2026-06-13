# Admin Workspace — Phase Map

> **Companion to** `docs/plans/2026-06-13-admin-workspace-spec.md` (the north-star design).
> This document decomposes the spec into **phases, each of which gets its own dated implementation plan** (brainstorm→plan→build→merge) before the next starts. It also assigns the **god-class strangle** — which slice of `AdminAPIController` (3,627 lines, 128 `$wpdb` calls) each phase extracts.
>
> **Decisions baked in (user, 2026-06-13):** (1) **strangle as we go** — no big-bang refactor phase; each phase extracts ONLY the controller code it touches, behavior-preserving, covered by existing tests. (2) **spec = north star, one plan per phase.** (3) This map is agreed first; detailed per-phase plans are written on demand when each phase starts.

---

## 0. Why phased (not one plan)

The spec is ~640 lines across 4 surfaces + a read-model + 10 bulk handlers + a god-class refactor. One plan would be an un-reviewable, un-bisectable blob and would violate the review-cluster size discipline (harness rule 1f). So each phase is a **sub-project**: its own plan, its own gates, its own merge. The spec's existing review clusters (A1/A2/B/C1/C2/D) become the backbone of the per-phase plans.

## 1. The god-class strangle — verified seams

`AdminAPIController.php` method map with `$wpdb` density (verified 2026-06-13). **Fat methods** (high `$wpdb`) are where extraction pays off; **thin methods** (0 `$wpdb`, already delegate) need little strangle work.

| Method | Lines | Domain | `$wpdb` | Strangle |
|---|---|---|---|---|
| `getStats` | 442–757 | stats | **32** | FAT — extract `AdminStatsService` |
| `getActionQueue` | 2187–2347 | action-queue | **18** | FAT — extract into stats/queue service |
| `getEditionsAgendaView` | 930–1126 | editions | **12** | FAT — editions service (Phase 2 territory) |
| `getUserDetail` | 2556–2892 | users | **11** | FAT — extract `AdminUserService` |
| `getQuotes` | 1491–1683 | quotes | **10** | FAT — `AdminQuoteQueryService` (mostly Phase-1-adjacent) |
| `getEditions` | 758–929 | editions | 8 | medium — editions service |
| `getTrajectories` | 1684–1920 | trajectories | 6 | medium — `AdminTrajectoryService` |
| `getEditionRegistrations` | 1297–1419 | registrations | 4 | medium — folds into the read-model service |
| `exportRegistrations` | 3508–3627 | export | 3 | Phase 3 — `AdminExportService` |
| `getTrajectory` | 1921–1998 | trajectories | 0 | thin — already delegates |
| `getPendingApprovals` | 1999–2120 | registrations | 0 | thin |
| `approveRegistration` | 2121–2156 | registrations | 0 | **thin — already delegates** (bulk wraps it) |
| `approvePostCourse` | 2157–2186 | registrations | 0 | **thin — already delegates** |
| `markAttendance` | 1420–1490 | attendance | 0 | **thin — already delegates** |
| `updateUserProfile` | 2893–3003 | users | 0 | thin — delegates to EnrollmentService |
| `revealSensitiveField` | 3004–3055 | users | 0 | thin (audited) |
| `searchUsers` | 2510–2555 | users | 0 | thin |
| `impersonateUser`/`endImpersonation` | 3316–3441 | impersonation | 1 | **already delegates to `ImpersonationHandler`** (mostly extracted) |
| `getActivityFeed` | 2435–2509 | activity | 1 | thin |
| `getNotifications`/`markNotificationsRead` | 3442–3507 | notifications | 1 | thin |

**Key insight that makes the strangle cheap:** the **bulk-action targets are already thin** (`approveRegistration`/`approvePostCourse`/`markAttendance` = 0 `$wpdb`, they delegate to `EnrollmentService`/`EnrollmentCompletion`). So the heavy `ntdst/v1/action` phase wraps existing clean domain logic — no SQL to move. The fat extraction lands in the **read-model** (`getStats`/`getUserDetail`/quotes) and **worklist** (`getActionQueue`/`getStats`) phases, exactly where we're already adding code.

**Shared helper hazards** (used across domains → move to a shared base/trait, NOT into one service): `fetchPostTitles` (3105), `batchCountUserRegistrations` (3150), `fetchSessionCountByEdition` (3184), `fetchUserAttendanceByEdition` (3222), `enrichAuditContexts` (3056), `buildCourseTaxonomyJoin` (1170), `lineItemsToEuros` (3266), `buildApprovalItem` (3288), `fetchTaxonomyTerms` (1139). Each per-phase plan that extracts a service must check these against the hazard list and route shared ones to a common location first.

---

## 2. The phases

Each phase = one plan, executed and merged before the next. Phases 1A–1E are the **first slice (person workbench + trajectory layer)**; Phases 2–3 are the spec's already-deferred work.

### Phase 1A — Read-model + the registrations grid endpoint
**Builds (spec):** `queryForGrid` in `RegistrationRepository` + `GET /admin/registrations` (Tasks 1.1–1.3, cluster A1). The single biggest new backend piece.
**Strangle:** extract the registrations *query* shapes into `RegistrationRepository` (already the plan); begin `AdminRegistrationQueryService` to host the composite read-model assembly so the new endpoint is thin from day one. Fold `getEditionRegistrations` (4 `$wpdb`) into it opportunistically.
**Gates:** TIER FULL (M1/M4/M5 + INV-3). `── REVIEW GATE A1 ──` + HALT before 1B.
**Depends on:** nothing. **First to build.**

### Phase 1B — Pickers + trajectory filter
**Builds (spec):** `/admin/editions/options`, `/admin/trajectories/options`, trajectory filter in `queryForGrid` via the parent→child join (Tasks 1.4a/1.4b, cluster A2).
**Strangle:** the options endpoints are born thin (lightweight reads); no fat extraction, but they establish the "lightweight read endpoint" pattern. Repoint the existing heavy `loadQuoteEditions` quotes-filter call here (spec §10.4 bonus).
**Gates:** TIER FULL (the trajectory-join leak-check + param whitelists). `── REVIEW GATE A2 ──` + HALT.
**Depends on:** 1A (extends `queryForGrid`).

### Phase 1C — Bulk action layer
**Builds (spec):** the 9 smart `ntdst/v1/action` handlers + `stride_bulk_set_field` + bulk execution semantics + cache-bust events (Tasks 2.1–2.4, cluster B).
**Strangle:** **minimal — the targets are already thin.** `approveRegistration`/`approvePostCourse`/`markAttendance` already delegate to services (0 `$wpdb`), so the bulk handlers wrap clean domain logic. Optionally extract an `AdminApprovalService` if the single-item logic wants a home, but no SQL moves.
**Gates:** TIER FULL (M2/M3/M6/M7 confused-deputy + per-row authz). `── REVIEW GATE B ──`.
**Depends on:** 1A (the grid is what arms bulk actions; can overlap once the endpoint exists).

### Phase 1D — The Alpine UI (grid + worklist + case view)
**Builds (spec):** the grid component, multi-select + bulk bar, Vandaag worklist home, the Dossier case view, group-by (Tasks 3.1–3.4a, cluster C1) — **ported from the mockups into `admin-dashboard.js` + `dashboard.php`** (spec §12 mapping).
**Strangle:** extract `AdminStatsService` (getStats = 32 `$wpdb`, the fattest method) + the action-queue assembly (getActionQueue = 18) since the worklist home consumes them — this is where the biggest god-class win lands, on code we're already touching. Extract `AdminUserService` (getUserDetail = 11) for the case view.
**Gates:** TIER STANDARD (UI over already-gated endpoints) + the feature-acceptance browser pass (F1–F6). `── REVIEW GATE C1 ──`.
**Depends on:** 1A + 1B + 1C (consumes all the endpoints). **This is the in-place dashboard cutover** (spec §12.3) — switch default landing to Vandaag here.

### Phase 1E — Trajectory case-view + Trajecten tab
**Builds (spec):** `GET /admin/users/{id}/trajectories` (the one true backend gap, wiring existing compute) + the Dossier trajectory section + the read-only Trajecten tab (Tasks 3.5–3.6, cluster C2).
**Strangle:** extract `AdminTrajectoryService` (getTrajectories = 6 `$wpdb` + getTrajectory) to host both the tab endpoints and the new `/users/{id}/trajectories` wiring.
**Gates:** TIER STANDARD + acceptance F7/F8. `── REVIEW GATE C2 ──`.
**Depends on:** 1A (grid trajectory filter for jump-to-grid) + 1D (the Dossier shell the section slots into).

### Phase 1F — Boundary + cleanup
**Builds (spec):** select-all-across-pages model + remove the worklist-export entry point (Tasks 4.1–4.2, cluster D).
**Strangle:** none (UI + a small repo guard).
**Gates:** TIER STANDARD. `── REVIEW GATE D ──`. **Closes the first slice.**
**Depends on:** 1C + 1D.

### Phase 2 — Cohort lens (DEFERRED, spec §1/§ phase boundaries)
Editie → sessie → per-session roster, attendance marking per session, roster extras badges, roster bulk actions, trajectory-roster bulk actions, exporters surfaced here.
**Strangle:** extract the editions/sessions services (`getEditions` 8 + `getEditionsAgendaView` 12 + session code) — the editions domain, which Phase 2 is centered on.
**Depends on:** Phase 1 complete.

### Phase 3 — Field-scoped deliverable export (DEFERRED, spec §6/§9)
Extend the 5 exporters with per-recipient field allowlists, invoice-stage exclusion, anonymise-skip; optional bulk-mutation audit trail.
**Strangle:** extract `AdminExportService` (exportRegistrations = 3 `$wpdb` + the exporter wiring).
**Depends on:** Phase 1 (uses the grid's selection/filter model as the export source).

---

## 3. Dependency graph (build order)

```
1A read-model ──┬─→ 1B pickers+traj-filter ──┐
                ├─→ 1C bulk layer ────────────┼─→ 1D UI/cutover ──→ 1E traj case-view+tab ──→ 1F cleanup
                │                              │
                └──────────────────────────────┘
                                   (1B, 1C can overlap once 1A's endpoint exists)
        ────────────────────────────────────────────────────────────────────
        Phase 2 (cohort) and Phase 3 (export) follow the full first slice.
```

**Critical path:** 1A → 1D → 1E. 1B and 1C can run in parallel with each other after 1A (different files: 1B in repo/query, 1C in handlers). 1F is last.

## 4. Strangle ledger (god-class shrink per phase)

| Phase | Extracts | Approx `$wpdb` moved out of controller |
|---|---|---|
| 1A | `AdminRegistrationQueryService` (+ getEditionRegistrations) | ~4 + the new query shapes |
| 1B | (lightweight reads, pattern only) | ~0 |
| 1C | optional `AdminApprovalService` (no SQL — already thin) | ~0 |
| 1D | `AdminStatsService` (32) + action-queue (18) + `AdminUserService` (11) | **~61** |
| 1E | `AdminTrajectoryService` (6) | ~6 |
| 2 | editions/sessions services (8 + 12 + sessions) | ~20+ |
| 3 | `AdminExportService` (3) | ~3 |

By end of first slice (1A–1F), ~70 of the controller's 128 `$wpdb` calls have moved into services — **over half the god class drained, on code the workspace was already touching**, with no dedicated rewrite phase. Phases 2–3 drain most of the rest.

## 5. How to use this map

- **When starting a phase:** invoke the planner (`netdust-agent` harness Stage 0→1) to write that phase's dated plan from the spec's tasks for that cluster + the strangle assignment above. Each plan re-grounds its premises against current source (the controller will have shrunk since this map was written).
- **The spec stays the design source.** This map is sequencing + extraction assignment, not a re-statement of the design.
- **Each phase merges before the next** (per the dependency graph). The strangle is incremental and behavior-preserving — existing tests are the safety net (green before AND after each extraction).
- **Shared-helper hazards** (§1) are checked per phase before extracting a service.
