# Audit Remediation Sprint — Execution Plan (Class-B freshness review of AUDIT-2026-06-10)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Stop at every `── REVIEW GATE ──` marker.

**Goal:** Close the launch-blocking findings of `docs/architecture/AUDIT-2026-06-10.md` — CI safety net (M0), critical fixes (M1 minus parked 1.2), and the perf-critical core of M2 — with every premise re-verified against current source on 2026-06-10.

**Architecture:** No new subsystems. Fixes land at existing convergence points (`ARCHITECTURE-INVARIANTS.md` INV-1..7); the one new surface is an authenticated download path for completion proofs, built on the existing `ntdst/api_data` + `ntdst_response()->download()` pattern (ICalHandler).

**Tech Stack:** Bedrock WP / PHP 8.3, NTDST Core, PHPUnit + Codeception + Playwright, GitHub Actions, MariaDB 10.11.

**Authority chain:** Audit task IDs + Deps column = execution order (authoritative). Scope ruling Stefan 2026-06-10 = what's in/out. This document = reconciled premises + gates + clusters.

---

## Sprint scope summary

| Bucket | Contents | Status |
|---|---|---|
| **IN** | M0 entire (0.1–0.5), M1 minus 1.2 (1.1, 1.3, 1.4, 1.5, 1.6, 1.7), perf-critical M2 (2.1, 2.2, 2.4 = H-4, 2.5, 2.6, 2.3-prep = H-3), quick-win riders 3.5 + 3.7 (M3-numbered but explicitly pulled forward by the todo.md quick-wins line) | This plan |
| **PARKED** | 1.2 Makefile/deploy method — Stefan handles personally. 2.3 *enablement* depends on it (ops access) + Q5; only the drop-in **prep** is in scope | Do not plan/execute |
| **OUT (post-launch)** | Milestone 3 remainder (3.1–3.4, 3.6, 3.8–3.10), AdminAPIController decomposition, Partner-API rate limiting (M-3), module cycles (L-8) | Audit §"Explicitly NOT fixing now" |
| **Already resolved — do not re-do** | Audit Open Question 6 (stale memory re impersonation) — corrected in hardening Phase 2. Acceptance-suite prefix mismatch — fixed `eb931fd1`. H-2 = parked 1.2 | — |

Suites at sprint start: **924 unit + 369 integration + 121 acceptance green** (2026-06-10).

---

## Premise verification (Stage 1c — ground-truthed against source, 2026-06-10)

Every load-bearing audit claim was re-read in current source. Verdicts:

| Task | Finding | Verdict | Evidence / correction |
|---|---|---|---|
| 0.1/0.2 | CR-1: CI runs zero tests | **CONFIRMED** | `ci.yml` has no test step; `integration.yml` only curls `<title>Bedrock`. `composer test:unit` / `test:integration` scripts exist (composer.json:97–99) — the sketch's premise holds |
| 0.3 | M-7: no static analysis | **CONFIRMED** | No `phpstan.neon`, no phpstan in composer.json |
| 0.4 | INV-5 grep blind spot | **CONFIRMED** | `scripts/check-invariants.sh:103` greps only `stridence_`; the 4 `stride_format_date` core call sites pass it today |
| 0.5/1.1 | H-1: dead columns | **CONFIRMED — both still live** | `AdminAPIController.php` ~2236 `r.tasks IS NOT NULL ... r.tasks LIKE %s` (column is `completion_tasks`); ~3433 `ORDER BY ... r.created_at` (column is `registered_at`). Schema: `RegistrationTable.php` has `registered_at` + `completion_tasks`, no `tasks`/`created_at` |
| 1.3 | M-4: ENUM missing `'partner'` | **CONFIRMED** | `RegistrationTable.php`: `enrollment_path ENUM('individual','colleague','trajectory')` |
| 1.4 | M-1: PII reveal not audited | **CONFIRMED + 2 corrections** | `revealSensitiveField()` (~2930) writes no audit row. (a) Allowed fields are **4**, not 3 — `phone` is included; audit the field name. (b) Route is already gated by `canManageAdmin` (`AdminAPIController.php:342`) — capability gate is NOT part of the fix, only the audit row is |
| 1.5 | H-5: VAT in 3 places | **CONFIRMED** | Six `0.21` literals: `QuoteService.php:236,654`, `QuoteAdminController.php:504,549,568,614`. `QuoteCalculator::TAX_RATE` is canonical |
| 1.6 | H-6: core calls theme helper | **CONFIRMED** | `NotificationMapper.php:139`, `StrideMailBridge.php:223,759,760` |
| 1.7 | M-2: proofs in public library | **CONFIRMED** | `CompletionTaskHandler.php` ~170: `media_handle_upload('upload_file', 0)` → standard public attachment |
| 2.1 | CR-2: dashboard double aggregation | **CONFIRMED, premise partially DRIFTED** | Tab templates still re-run aggregation (`tab-inschrijvingen.php:25`, `tab-offertes.php:24`, **also `tab-downloads.php:141`** — a third site the audit missed). BUT: commit `98094869` (hardening Phase 1) made sidebar visibility static/always-visible in `page-mijn-account.php:59–70`; `getNavData()` (full hydration, UserDashboardService.php:109) is still called on non-home tabs and passed as `nav_items` (page line 140). **Correction to fix shape:** first check what the layout consumes `nav_items` for — the call may now be deletable outright, which is cheaper than the audit's EXISTS-flags rewrite. Memoization of `getEnrollmentData`/`getQuoteData` still needed for the tab templates either way. **Path correction:** `page-mijn-account.php` is at theme root, not `templates/dashboard/` |
| 2.2 | CR-3: catalog N+1 per card | **CONFIRMED** | `partials/card-edition.php:49–50` (`getEffectiveStatus` per card), `:60` (`get_post`), `:81–82` (`isEnrolled` per card); `partials/card-course.php:39` (WP_Query w/ meta_query per card); `page-klassikaal.php:37` `posts_per_page => 200` + Alpine client-side paging. `RegistrationRepository::countByEditions()` exists (:696). **Path correction:** partials live at `themes/stridence/partials/`, not `templates/partials/` |
| 2.3 | H-3: no object cache | **CONFIRMED** | No `web/app/object-cache.php` drop-in |
| 2.4 | H-4: JSON_EXTRACT badge scan | **CONFIRMED** | `findBySubjectUser()` with `JSON_EXTRACT(context,'$.user_id')` at `AuditRepository.php:186,228,262`; called per dashboard view via `NotificationService::getUnreadCount` (`page-mijn-account.php:73`). **Path correction:** ntdst-audit is a **plugin** (`web/app/plugins/ntdst-audit/`) shared with the framework — schema migration touches shared code |
| 2.5 | H-8: unbounded getPendingApprovals | **CONFIRMED, 1 criterion stale** | `SELECT *`, no LIMIT, `LIKE '%post_approval%'` confirmed (~1970–1984). The audit's acceptance criterion "add `completion_tasks IS NOT NULL`" is **already present** in both queries — drop that sub-criterion; the work is column-trim + SQL-side bucketing + LIMIT |
| 2.6 | exportRegistrations N+1 | **CONFIRMED** | Per-row `get_userdata` + `get_user_meta` + quote `get_var` inside the row loop (~3450–3471) |
| 3.5 | M-10/L-6 swallowed errors | **CONFIRMED (spot-checked)** | Sites per audit; one log line each |
| 3.7 | M-12 stale docs | **CONFIRMED** | README is stock Bedrock; CLAUDE.md service list short |

**Nothing in scope was already fixed by the hardening sprint.** Drift corrections above are folded into the task specs below.

---

## Threat model

> Written 2026-06-10 at plan time for the audit-remediation sprint. Covers the security-touching tasks: 1.4 (PII reveal audit), 1.7 (completion-proof upload protection), 1.3 (enrollment_path ENUM migration), 2.4 (audit-table migration). The rest of the sprint (CI wiring, perf reshaping, doc fixes) adds no attack surface. This section is the convergence target — `/code-review` verifies against these numbered mitigations, not free-form.

### What we're defending

1. **Special-category PII in `wp_usermeta`** — `national_id` (rijksregisternummer), `date_of_birth`, `professional_license_number`, `phone` — revealed in cleartext via `GET /stride/v1/admin/users/{id}/reveal`.
2. **Completion-proof files** uploaded by learners (may contain certificates/diplomas bearing national IDs) — currently standard attachments at guessable public `/uploads/YYYY/MM/` URLs.
3. **Integrity of `wp_vad_registrations`** — `enrollment_path` and `company_id` correctness underpin Partner-API tenant scoping (`findByCompany`) and all enrollment reporting.
4. **Forensic integrity of the ntdst-audit table** (`web/app/plugins/ntdst-audit/`) — the impersonation/GDPR trail; 27k+ rows in dev, larger in prod.

### Who we're defending against

| Actor | Scope |
|---|---|
| Unauthenticated internet users / crawlers guessing upload URLs | **IN** |
| Authenticated learners reaching other users' proofs or admin endpoints | **IN** |
| `stride_view`-only staff escalating to PII (reveal is `stride_manage`-gated — verified at `AdminAPIController.php:342`) | **IN** (gate exists; we add detectability) |
| Phished/malicious holder of a `stride_manage` account harvesting PII | **IN for detection** (audit trail), OUT for prevention (rate limiting deferred) |
| Leaked partner credential mass-creating users | **OUT** — M-3 rate limiting is post-launch per scope ruling |
| Insider with direct DB access | **OUT** |

### Attacks to defend against

1. **Public proof disclosure**: unauthenticated fetch of a completion proof via its `/uploads/YYYY/MM/<name>` URL (guessable, crawler-indexable) or attachment page.
2. **IDOR on the new download handler**: authenticated learner requests another registration's proof by iterating attachment/registration IDs.
3. **Path traversal / arbitrary file read** via the download handler's file parameter (`../../.env` class).
4. **Stored-XSS / content-sniffing** via a served proof (HTML/SVG uploaded as "proof", served inline, executing in the site origin).
5. **Repudiation of PII access** (the M-1 finding itself): an admin reveals national IDs with zero forensic trail — GDPR special-category access is unprovable either way.
6. **Silent tenant-integrity corruption**: partner enrollments insert `enrollment_path=''` (non-strict ENUM coercion), making partner rows invisible to path-filtered queries — an integrity drift, not an injection.
7. **Audit-table migration damage**: the 2.4 ALTER (generated column + indexes) on a shared-plugin table losing rows, breaking other ntdst-audit consumers, or locking the table mid-deploy.
8. **Convergence bypass on the new surface**: the download handler shipping as a raw `wp_ajax_*` with no nonce, or an inline `permission_callback`, bypassing INV-1/INV-2.

### Mitigations required

1. **Proofs leave the public library.** `CompletionTaskHandler` upload path stores proofs in a non-web-served (or deny-ruled) directory: `uploads/stride-proofs/` with an `.htaccess`/nginx deny (Ploi runs nginx — the deny rule must be documented as a deploy-time server directive, with `.htaccess` as defense-in-depth) — or `wp_handle_upload` with a custom `upload_dir` filter + attachment meta marking it protected. Code-checkable: an acceptance test fetches a freshly-uploaded proof's direct URL unauthenticated and asserts 403/404. **Existing already-uploaded proofs are migrated by the same task** (move files + update attachment meta).
2. **Ownership check at the handler.** Download flows through one handler method that loads the registration via `RegistrationRepository`, then allows only `registration.user_id === get_current_user_id()` OR `current_user_can('stride_manage')`. Denial path tested (authenticated non-owner → `WP_Error`/403).
3. **No user-supplied paths.** Handler accepts an attachment ID only (`absint`), resolves via `get_attached_file()`, verifies the resolved path is inside the protected dir (`str_starts_with(realpath(...))`), never concatenates user strings into paths.
4. **Safe serving headers.** Response sets `Content-Type` from the stored validated MIME, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`. Use `ntdst_response()->download()` (the ICalHandler convergence point) if it sets these; otherwise extend it rather than hand-rolling headers.
5. **One audit line at the convergence point.** `revealSensitiveField()` calls `ntdst_get(AuditService::class)->record('user', $userId, 'admin.pii_reveal', null, ['field' => $field])` (signature verified at `AuditService.php:55`) on every **successful** reveal — after the field allow-list check, before returning. Invalid-field requests still return 400 with no value and no audit row. Test asserts the row exists with actor + target + field.
6. **Detectable harvest.** No new prevention; the audit rows from M5 are the detection mechanism. Capability gate stays exactly `canManageAdmin` — do not weaken to `canViewAdmin` (L-3 shows the view-tier already over-reaches on `searchUsers`).
7. **Additive, reversible ENUM migration.** `ALTER TABLE ... MODIFY enrollment_path ENUM('individual','colleague','trajectory','partner') DEFAULT 'individual'` (purely additive — existing values untouched), then backfill `UPDATE ... SET enrollment_path='partner' WHERE enrollment_path='' AND company_id IS NOT NULL`. Integration test inserts via `RegistrationRepository::PATH_PARTNER` and reads back `'partner'`.
8. **Audit-table migration discipline.** 2.4 adds a STORED generated column (`subject_user_id`) + index via a versioned migration in ntdst-audit's own schema class; no row rewrites; deploy note says run off-peak; rollback = drop column/index. Row count asserted equal before/after in the integration test.
9. **New surface routes through named convergence points.** The download handler registers as an `ntdst/api_data/*` filter (framework nonce, INV-2) exactly like `ICalHandler::init()` (`add_filter('ntdst/api_data/stride_download_ical', ...)`), with authz inside per INV-1's frontend-AJAX row. No raw `wp_ajax_*`, no new REST route, no new capability.

### Out of scope (explicit deferrals)

- **Partner-API rate limiting (M-3)** — post-launch per scope ruling; blast radius bounded by company scoping.
- **Reveal-endpoint rate limiting / anomaly alerts** — audit rows give detection; throttling deferred.
- **Already-leaked URL copies** (crawler caches, CDN) of proofs uploaded before this sprint — we relocate the files; cache eviction is operational, not code.
- **Encryption-at-rest of uploads** — filesystem trust assumed; out of scope.
- **Audit-log retention tiers (L-7)** — M3 / 3.10, post-launch.
- **Git history rewrite (Q4 / 3.1)** — out of this sprint entirely.

### How to use this section

- **Controller pre-flight:** verify each cluster's tasks carry these mitigations before dispatch (Cluster B → M5–M8; Cluster D → M1–M4, M9; Cluster F → M8).
- **`/code-review` at each gate:** "Verify the diff against the threat model in `tasks/plans/2026-06-10-audit-remediation-plan.md`. Check each numbered mitigation in this cluster's range: in place / missing / deferred-per-list."
- **`/evaluate` retros:** unimplemented mitigations are plan-correction defects.
- **Downstream phases:** cross-reference, don't re-litigate; extend if 1.7's surface grows (e.g. admin bulk-download).

---

## Architecture invariants touched (gate 1b — cited from `ARCHITECTURE-INVARIANTS.md`, root)

| Invariant | Touched by | Obligation |
|---|---|---|
| **INV-1** (authz at entry point, by capability) | 1.4, 1.7 | Reveal keeps `canManageAdmin`; download handler does per-user ownership authz inside the handler (frontend-AJAX row of INV-1's table); no new caps, no inline `permission_callback` |
| **INV-2** (frontend AJAX nonce by framework) | 1.7 | Handler registers via `ntdst/api_data/*` filter — never raw `wp_ajax_*`; MUST NOT re-verify the nonce |
| **INV-3** (data through repositories; reg table owned by `RegistrationRepository`) | 1.3, 2.1, 2.2, 2.5, 2.6 | 1.3's migration lives in `RegistrationTable.php` (schema class — allowed). 2.5/2.6 modify *existing, known* `$wpdb` in AdminAPIController (accepted drift zone — do not ADD new direct-SQL sites; if a new query shape is needed, add a repo method). 2.2's batch pre-pass uses `RegistrationRepository::countByEditions()` + a new `SessionRepository::countByEditions()` — new queries go IN the repos |
| **INV-4** (WP_Error + `ntdst_log` channels) | 3.5, 1.7 | The three swallow-sites get `ntdst_log()` warnings (never `error_log`); download handler returns `WP_Error` on denial |
| **INV-5** (plugin never calls theme) | 0.4, 1.6 | 0.4 extends the audit grep beyond `stridence_` to `stride_format_\|stride_enrollment_url`; 1.6 closes the 4 known bypasses by moving the formatter into stride-core (theme helper delegates to core, arrow restored) |
| **INV-6** (LD through adapter/helper) | 2.1 | Memoize the **assembled array** in `UserDashboardService`, not LD helper internals — don't fork the LD read path (audit sketch gotcha, consistent with INV-6) |
| **INV-7** (display status via `getEffectiveStatus()`) | 2.2 | The batch path MUST NOT fork status logic: add `EditionService::getEffectiveStatusFromPrefetched(...)` that the existing `getEffectiveStatus()` delegates to — one decision point preserved (`lesson_effective_status_pattern`). This is the highest-risk invariant touch of the sprint |

---

## Golden path & WP security requirements (stack gate — netdust-wp:wp-plan-requirements)

**Golden path:** `none (no matching archetype)` for clusters A/B/C/E/F (CI config, column fixes, migrations, query reshaping — no new vertical slice). Cluster D (1.7) builds to the **form/AJAX data-flow** archetype with one named deviation: *response is a binary download via `ntdst_response()->download()` (ICalHandler precedent), not a JSON payload.*

### WP security requirements (per data-flow — pillars per `netdust-wp:wp-security`)

- [ ] **AJAX `ntdst/api_data/stride_download_proof` (new, 1.7):** nonce — by framework (INV-2, do not re-verify) · authorize — logged-in + ownership-or-`stride_manage` inside handler · sanitize — `absint` on attachment/registration id, no path strings accepted · escape — n/a (binary download; headers per threat-model M4)
- [ ] **Upload path change (1.7, `CompletionTaskHandler`):** existing ownership + content-based MIME validation retained (verified strength — do not regress) · new protected-dir storage per threat-model M1/M3
- [ ] **REST `GET /admin/users/{id}/reveal` (modified, 1.4):** authorize — existing `canManageAdmin` untouched · validate — existing field allow-list untouched · NEW: audit row per threat-model M5 · escape — n/a (JSON response of meta value, REST serializes)
- [ ] **`$wpdb` rewrites (1.1, 2.5, 2.6):** all queries remain `$wpdb->prepare`d; no new interpolation; CSV output keeps `sanitizeCsvCell()` neutralization (verified existing at :3498)
- [ ] **Migrations (1.3, 2.4):** no user input touches DDL; backfill UPDATE is constant-valued

### ntdst-core layering requirements (applicable rows only)

- [ ] New session-count batch query lands in `SessionRepository`, registration batch reads in `RegistrationRepository` — not in templates/controllers (INV-3)
- [ ] No raw `wp_ajax_*` for the download handler (INV-2)
- [ ] No swallowed `WP_Error` — 1.7 handler and 3.5 sites propagate or log via `ntdst_log` (INV-4)
- [ ] No hardcoded `_ntdst_` keys in the 2.2 batch pre-pass — bare field names through the repos (INV-3 vocabulary)
- [ ] Per-task acceptance for module-touching tasks: drift pre-check clean (`/drift-reviewer <touched path>`) + the flow's security line above satisfied in the diff

> These blocks + the threat model + the invariants table are the convergence target for `/code-review` and `ntdst-drift-reviewer` at every gate below. Reviewers verify the diff against named items, not free-form.

---

## Acceptance flows (gate 1g — verify at shake-out via real browser/wire)

| # | Flow (intended use) | Steps | Edges (all six classes per row — mandatory) |
|---|---|---|---|
| AF-1 | **Learner downloads their own completion proof; outsiders cannot** (1.7) | Log in as seed_student1 → dashboard → completion task with uploaded proof → click download → file received with attachment disposition | **empty/zero:** task with no upload → no download affordance, no error. **denied actor:** (a) logged-out direct URL fetch of the stored file → 403/404; (b) seed_student2 requesting student1's attachment id → error, no bytes. **wrong-order/re-entry:** download link for an attachment whose registration was since cancelled/anonymised → denied or 404, no orphan leak. **concurrent/double:** double-click download → two clean responses, no corruption. **boundary:** largest-allowed upload downloads completely (content-length matches). **mid-flow failure:** file missing on disk (deleted out-of-band) → clean `WP_Error` message, not a PHP warning/empty 200 |
| AF-2 | **Admin reveals a sensitive field and the trail proves it** (1.4) | Log in as admin → user detail → reveal rijksregisternummer → value shown → audit log shows actor/target/field row | **empty/zero:** user with no `national_id` meta → empty value, audit row still written (access attempt is the event). **denied actor:** `stride_view`-only user calls the route → 403, no audit row needed (denial is WP's). **wrong-order:** reveal for non-existent user id → 404, no audit row. **concurrent/double:** two rapid reveals → two audit rows (no dedupe — each access is an event). **boundary:** `field=phone` (the 4th allowed field) audited identically. **mid-flow failure:** audit insert failing must NOT block the reveal response — log the failure via `ntdst_log` (availability over strictness; decision recorded here) |
| AF-3 | **Dashboard tabs render identically, cheaply** (2.1) | Log in as seeded learner with enrollments+quotes → visit `?tab=inschrijvingen`, `?tab=offertes`, `?tab=downloads` → content identical to pre-change snapshot, sidebar consistent | **empty/zero:** brand-new user with zero enrollments → empty states render, no notice-level PHP errors. **denied actor:** logged-out hit of `?tab=offertes` → login redirect (existing behavior preserved). **wrong-order:** unknown `?tab=bogus` → existing fallback unchanged. **concurrent/double:** memoization is per-request only — two different users in interleaved requests never see each other's data (no static cross-request cache). **boundary:** user at the LearnDash-heavy end (many editions) stays ≤ ~60 queries. **mid-flow failure:** one corrupt registration row (NULL edition) doesn't blank the whole tab |
| AF-4 | **Catalog pages show the same cards, batched** (2.2) | Visit `/klassikaal` + `/online` logged-out and logged-in → same cards/statuses/CTAs as pre-change (Playwright visual parity + existing acceptance Cests) | **empty/zero:** zero active editions → empty-state copy, ≤ ~40 queries still holds. **denied actor:** n/a — public page (excluded: no authz dimension; logged-in vs out IS covered as the `isEnrolled` branch). **wrong-order:** edition whose course was trashed → card skipped, no fatal (the `get_post` null branch). **concurrent/double:** n/a server-side (read-only page) — covered instead by cache-correctness: a status change reflects on next load (no stale pre-pass cache across requests). **boundary:** exactly at the server-render cap (24 + "Toon meer") and one past it — pagination boundary card not dropped or doubled. **mid-flow failure:** one card's prefetch data missing from the map → that card falls back/skips; page renders |
| AF-5 | **Admin CSV export contains the registrations** (1.1 — restores a dead feature) | Admin dashboard → export registrations → CSV has header + one row per confirmed registration, ordered by edition date | **empty/zero:** no confirmed registrations → header-only CSV, not a 500. **denied actor:** `stride_view`/anon hit of the export route → denied (existing gate asserted in test). **wrong-order:** n/a — single idempotent GET (excluded). **concurrent/double:** two simultaneous exports → both complete (read-only). **boundary:** registration with NULL edition (LEFT JOIN row) appears without fatal. **mid-flow failure:** covered by 2.6 later — per-row user lookup failure yields blank cells, not aborted stream |

Verification layer: AF-1/2 = Codeception acceptance (real browser + DB assertions) — AF-2's audit-row check asserts **persisted state**, not just UI. AF-3/4 = existing acceptance Cests + Playwright visual pass + the new query-count integration tests. AF-5 = integration test (0.5) + one acceptance touch.

---

## Task plan (audit Deps column order — clusters of ≤4, gates between)

Conventions per task: **Finding** (audit ID) · **Verdict** (from table above) · **Files** (verified paths) · **Tier** line per `testing-workflow` (the tier decides — a `Unit test:` prefix never forces a tautology) · acceptance criteria. Tier-A tasks: RED first, watch it fail. Every task close: tier named + full suite green + deferral line.

### Cluster A — CI safety net (Milestone 0 core)

**Task A1 (= audit 0.1): Wire unit suite into CI** — Deps: none
- Finding CR-1 · CONFIRMED
- Files: `.github/workflows/ci.yml`
- Steps: add `tests` job — checkout → `shivammathur/setup-php` (8.3, ext `mysqli`, no coverage) → composer cache → `composer install` → `composer test:unit`. First verify `tests/bootstrap.php` runs without DDEV (audit sketch: Brain-Monkey-style stubs, likely `STRIDE_TESTING=1` env is enough — check before assuming).
- `no unit test: Tier B, CI config — verification is the seam itself`. Seam proof (mandatory, this IS the acceptance criterion): push a branch commit with one deliberately failing assertion → CI goes RED → revert → GREEN. Both runs linked in the task report.
- Deferral line: `Risk not covered: none beyond the seam proof — gate is self-verifying`.

**Task A2 (= audit 0.3): PHPStan level 1 + baseline** — Deps: none
- Finding M-7 · CONFIRMED
- Files: new `phpstan.neon`, `composer.json` (dev-dep + `lint:stan` script), `.github/workflows/ci.yml`
- Level 1, paths = `web/app/mu-plugins/stride-core` + `web/app/themes/stridence`; generate baseline; new violations fail CI.
- `no unit test: Tier B, analysis config`. Seam proof: confirm PHPStan **flags the H-1 pattern class** — run it pre-baseline and verify the dead-column query class (or an equivalent planted unknown-property access) appears; record the finding ID in the task report. If level 1 cannot see the `$wpdb` string-SQL class (likely — column names live in SQL strings), record that honestly and note 0.5's integration tests as the actual net for that class.

**Task A3 (= audit 0.4): Fix INV-5 audit grep blind spot** — Deps: none
- Finding H-6 (detection half) · CONFIRMED (`check-invariants.sh:103` covers only `stridence_`)
- Files: `scripts/check-invariants.sh`, `ARCHITECTURE-INVARIANTS.md` (INV-5 audit-move block)
- Extend grep to `stride_format_\|stride_enrollment_url\|stridence_`. **Sibling-site audit:** sweep `themes/stridence/helpers/*.php` for any OTHER theme-defined helper called from stride-core (the blind spot class, not just the known instance) — add each prefix found.
- `no unit test: Tier B, grep script`. Seam proof (RED-first by design): the extended check MUST flag exactly the 4 known H-6 sites now (its RED state), and INV-5 stays **advisory** until Task C2 lands, then flips blocking. Record the 4-hit output.

**Task A4 (= audit 0.2): Run integration suite in CI** — Deps: 0.1 (A1)
- Finding CR-1 · CONFIRMED
- Files: `.github/workflows/integration.yml`
- Replace the curl-title assertion with `composer test:integration` after `wp core install` in the devcontainer `runCmd`. Audit-sketch gotchas to verify (not assume): `tests/Integration/bootstrap.php` DB credentials need env mapping; table-prefix expectations (`ckqp_` from the ported v3 DB vs CI's fresh install — the acceptance suite already learned this; check `phpunit-integration.xml.dist`).
- `no unit test: Tier B, CI config`. Seam proof: deliberately-broken integration test → RED → revert → GREEN, runs linked.

`── REVIEW GATE ── (tier: STANDARD — CI/tooling behavior the whole sprint depends on; no 1a surface, but a falsely-green pipeline is this repo's lived failure mode (lesson_suite_counts_need_a_run) — reviewer must verify the four seam proofs ran, not just read YAML)`

### Cluster B — Dead features restored + PII trail + partner path (quick wins, security core)

**Task B1 (= audit 0.5): RED regression tests for the two dead-column bugs** — Deps: 0.2 (A4)
- Finding H-1 · CONFIRMED (both)
- Files: new `tests/Integration/Admin/RegistrationExportTest.php`, `tests/Integration/Admin/ActionQueueIncompleteTasksTest.php`
- Tier A. Test contract: (1) seed a confirmed registration with a `completion_tasks` JSON containing `"completed":false` older than the rule cutoff → assert the action-queue `incomplete_tasks` bucket contains it — **RED now** (query hits nonexistent `r.tasks`, swallowed to `[]`); (2) seed confirmed registrations → call the export path → assert CSV body contains ≥1 data row — **RED now** (`ORDER BY r.created_at` errors → empty result). Watch both fail for the *column* reason (assert the emptiness, and log `$wpdb->last_error` in the failure message so the RED is attributable).
- Deferral line: `Risk not covered: none for this contract — denial path of the export route added in AF-5's acceptance touch`.

**Task B2 (= audit 1.1): Fix the dead columns** — Deps: 0.5 (B1)
- Finding H-1 · CONFIRMED
- Files: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` (~2236–2237: `r.tasks` → `r.completion_tasks` twice; ~3433: `r.created_at` → `r.registered_at`)
- Tier A (continues B1's cycle). Contract: B1's two tests GREEN; full suite green.
- Deferral line: `Risk not covered: per-row export perf — owned by 2.6 (Cluster E)`.

**Task B3 (= audit 1.3): Add `'partner'` to enrollment_path ENUM + backfill** — Deps: none
- Finding M-4 · CONFIRMED
- Files: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php` (ENUM definition + schema version bump / migration hook per the table class's existing upgrade pattern — read it first)
- Threat-model M7 applies: additive MODIFY + backfill `UPDATE ... SET enrollment_path='partner' WHERE enrollment_path='' AND company_id IS NOT NULL`.
- Tier A (migration). Test contract: integration test creates a registration with `'enrollment_path' => RegistrationRepository::PATH_PARTNER` and reads back `'partner'` (RED now: empty string), plus a backfill assertion on a pre-seeded empty-string+company row. Run the test file ≥3× (DB state, not time — but migration idempotency: running the migration twice must be a no-op).
- Deferral line: `Risk not covered: partner-API end-to-end enrollment — Partner API is post-launch scope; covered by existing PartnerAPI integration tests' fixtures once they use the real path`.

**Task B4 (= audit 1.4): Audit-log the PII reveal** — Deps: none
- Finding M-1 · CONFIRMED, with corrections: 4 fields incl. `phone`; capability gate already correct (`canManageAdmin`, line 342) — do not touch it
- Files: `web/app/mu-plugins/stride-core/Admin/AdminAPIController.php` (`revealSensitiveField`, ~2930–2950)
- Threat-model M5 + AF-2's mid-flow ruling: `record('user', $userId, 'admin.pii_reveal', null, ['field' => $field])` after allow-list + user-exists checks; an audit-insert `WP_Error` is logged via `ntdst_log('audit')` and does NOT block the response. Remove the stale "audit-friendly later" comment.
- Tier A (security guard adjacent — the audit row IS the contract). Test contract (RED first): successful reveal → exactly one audit row with actor=admin, entity=target user, `context.field` set — including `field=phone`; denial paths: invalid field → 400 AND zero audit rows; non-existent user → 404 AND zero audit rows.
- Deferral line: `Risk not covered: reveal rate limiting — explicitly deferred per threat model`.

`── REVIEW GATE ── (tier: FULL — cluster touches a 1a surface (special-category PII access trail) and the registrations data layer/migration; security-sentinel verifies threat-model M5–M8)`

### Cluster C — One source of truth: money + dates + log-line/doc riders

**Task C1 (= audit 1.5): Consolidate VAT/totals into QuoteCalculator** — Deps: 0.1 (A1)
- Finding H-5 · CONFIRMED (6 literals at verified lines)
- Files: `Modules/Invoicing/QuoteService.php:236,654`, `Modules/Invoicing/Admin/QuoteAdminController.php:504,549,568,614`, `Modules/Invoicing/QuoteCalculator.php` (canonical `TAX_RATE`)
- Order of work (financial math — tests FIRST per audit risk note): pin current behavior with characterization tests on the admin discount path (`applyManualDiscount`/`removeDiscount`) over fixture quotes (incl. discount > subtotal, €0 quote, cent-rounding edge `…,005`), THEN refactor both files to delegate subtotal→discount→tax→total derivation to `QuoteCalculator`/`QuoteService`. If the characterization tests reveal the two paths **already disagree** on some fixture, STOP and surface to controller — that's a live financial bug, not a refactor.
- Tier A. Test contract: discount-path totals identical pre/post for the fixture matrix; grep `0\.21` returns only `QuoteCalculator.php`. Add that grep to `check-invariants.sh` (audit Theme-3 "done means").
- Deferral line: `Risk not covered: concurrent quote edit — pre-existing M2 (trajectory quote race), post-launch scope`.

**Task C2 (= audit 1.6): Move `stride_format_date` into stride-core** — Deps: 0.4 (A3)
- Finding H-6 · CONFIRMED (4 call sites)
- Files: new core helper (e.g. `stride-core` bootstrap/helpers file — follow where `stride-core.php` loads procedural helpers; if none exists, a `Support/formatting.php` required from the loader), `Modules/Notification/NotificationMapper.php:139`, `Modules/Mail/StrideMailBridge.php:223,759,760`, `themes/stridence/helpers/formatting.php:17` (theme helper becomes a delegating wrapper, kept for theme callers)
- Function-exists guard so theme + core never double-declare.
- Tier A (small but real: the contract is core-independence). Test contract: a **unit-suite** test (theme not loaded there) calls the core formatter and asserts the Dutch output for a fixed date — RED before the move (function undefined in unit context proves the decoupling, AND a behavioral assertion on format prevents a silent format fork).
- Acceptance: A3's extended invariant grep goes from 4 hits → 0; INV-5 check flipped to blocking; mail render verified once with the default (non-stridence) theme active (the H-6 fatal scenario).
- Deferral line: `Risk not covered: other theme-helper classes — swept in A3's sibling-site audit`.

**Task C3 (= audit 3.5, quick-win rider): Log the swallowed WP_Errors + ignored grantAccess returns** — Deps: none
- Finding M-10 + L-6 · CONFIRMED
- Files: `Modules/Invoicing/Admin/QuoteAdminController.php:540-543,563-566`, `Modules/Edition/EditionDuplicator.php:214-216`, `Modules/Enrollment/EnrollmentService.php:146,331,572` (mirror the existing logging at `TrajectoryCascadeService.php:399-400`)
- One `ntdst_log('<channel>')->warning()` per site (channels per INV-4: `invoicing`, `enrollment`); behavior otherwise unchanged.
- `no unit test: Tier B, log-line glue — no branching contract of its own; INV-4 grep + suite green verify reach`.
- Deferral line: `Risk not covered: whether duplication/discount failures should also surface to the admin UI — product question, post-launch`.

**Task C4 (= audit 3.7, quick-win rider): README rewrite + CLAUDE.md registry refresh** — Deps: none
- Finding M-12 · CONFIRMED
- Files: `README.md` (10–30 lines → CLAUDE.md, LAUNCH-CHECKLIST, ddev quickstart), `CLAUDE.md` (service list reconciled against `plugin-config.php:33-56` — currently 15 of 23; add Membership/Reporting modules + missing Handlers; remove `stride` theme references incl. Key Decision #9 wording)
- `no unit test: Tier B, documentation`.

`── REVIEW GATE ── (tier: STANDARD — financial-math consolidation is high-stakes but not a 1a surface; 2 finders + simplicity pass, reviewer diffs the characterization fixtures first. Escalation trigger: if C1's characterization step finds live path disagreement, promote to FULL)`

### Cluster D — Completion-proof protection (own cluster: security boundary + open question)

> **Dispatch precondition:** surface **Q2** (what do users upload as proof?) to Stefan before dispatching. Default if unanswered: execute — the audit's own framing (certificates/IDs plausible) makes protection the safe default; an answer of "benign files only" demotes this to M3 and the cluster is skipped this sprint.

**Task D1 (= audit 1.7): Protected storage + authenticated download for completion proofs** — Deps: none
- Finding M-2 · CONFIRMED (`media_handle_upload('upload_file', 0)` at `Handlers/CompletionTaskHandler.php:~170`)
- Files: `web/app/mu-plugins/stride-core/Handlers/CompletionTaskHandler.php` (upload path + new download filter `ntdst/api_data/stride_download_proof`, mirroring `ICalHandler::init()`), theme template(s) that currently render the proof's public URL (find via grep for the attachment usage — repoint to the handler URL), one-off migration for existing proof attachments (move + meta), deploy-note addition (nginx deny rule for the protected dir — Ploi).
- Implements threat-model **M1–M4 + M9** exactly as written there (they are the spec). Existing ownership + content-MIME validation in the handler is a verified strength — keep it.
- Tier A (security). Test contract (RED first): unauthenticated fetch of an uploaded proof's direct URL succeeds **today** — that's the RED; after: 403/404. Denial paths: non-owner authenticated → `WP_Error`; path-traversal attempt (crafted id / filename) → rejected. Owner path: bytes + `Content-Disposition: attachment` + `nosniff` asserted. Seam test (this task wires a new `api_data` filter): ≥1 real un-mocked HTTP request through the mounted filter + the non-owner negative case.
- Acceptance flow AF-1 drives all six edges at shake-out.
- Deferral line: `Risk not covered: CDN/crawler-cached copies of pre-existing URLs + encryption at rest — explicit threat-model deferrals`.

`── REVIEW GATE ── (tier: FULL — 1a surface: file handling + auth boundary on user files; security-sentinel verifies threat-model M1–M4/M9; one-task cluster is deliberate (irreversible storage relocation rides with it))`

### Cluster E — Admin/dashboard read paths (perf-critical M2, part 1)

**Task E1 (= audit 2.1): Dashboard memoization + cheap nav** — Deps: 0.2 (A4)
- Finding CR-2 · CONFIRMED with DRIFT correction: sidebar visibility is static since `98094869`; `getNavData()` (full hydration) is still called on non-home tabs and passed as `nav_items` (page-mijn-account.php:140). **First step is therefore:** trace what the layout actually consumes from `nav_items` — if nothing (or only static keys), delete the `getNavData()` call instead of building EXISTS flags; only fall back to the audit's `hasActiveRegistrations()`-style EXISTS methods (`RegistrationRepository.php:923` exists) if real consumers remain. Cheaper than the audit's sketch.
- Files: `web/app/themes/stridence/page-mijn-account.php` (theme root — audit's path corrected), `Modules/User/UserDashboardService.php` (per-request `private array` memo keyed by user id for `getEnrollmentData` + `getQuoteData`, mirroring `RegistrationRepository::$findByUserCache` at :958-991 incl. its invalidation point at :1289 — find every write path that must clear the memo), tab templates re-running aggregation: `templates/dashboard/tab-inschrijvingen.php:25`, `tab-offertes.php:24`, **`tab-downloads.php:141`** (third site, audit missed it)
- INV-6 note: memoize the assembled array, never the LD helper calls.
- Tier A. Test contract: (1) second `getEnrollmentData($userId)` call in one request issues **zero** new queries (assert via `$wpdb->num_queries` delta) — RED first; (2) query-count test: non-home tab render ≤ 60 queries (provisional budget — Q3); (3) cross-user isolation: memo for user A never returned for user B (AF-3's concurrent edge, unit-level); (4) existing `UserDashboardServiceTest.php` (598 lines) stays green.
- Deferral line: `Risk not covered: rendered-output parity — AF-3 browser pass at shake-out`.

**Task E2 (= audit 2.5): Bound getPendingApprovals** — Deps: 0.5 (B1)
- Finding H-8 · CONFIRMED; stale criterion dropped (`completion_tasks IS NOT NULL` already present in both queries)
- Files: `Admin/AdminAPIController.php:~1968-2030`
- Work: column-trim the `SELECT *`, move the approval/post_approval bucketing into SQL where expressible, add LIMIT + pagination params consistent with the controller's existing list endpoints. No new `$wpdb` sites (INV-3 note above).
- Tier A. Test contract: endpoint returns **identical buckets** on seed data pre/post (characterization assertion), result sets bounded (LIMIT visible in query, row count ≤ limit on over-seeded data) — RED via the characterization harness before the rewrite.
- Deferral line: `Risk not covered: admin-UI pagination affordance for >limit queues — surface to controller if seed data exceeds one page`.

**Task E3 (= audit 2.6): Batch exportRegistrations** — Deps: 1.1 (B2)
- Finding (CR-class rider on H-1's site) · CONFIRMED (per-row `get_userdata`/`get_user_meta`/quote `get_var` in the loop at ~3450-3471)
- Files: `Admin/AdminAPIController.php:~3445-3500`; batch helpers via `Infrastructure/BatchQueryHelper` (already used correctly across Admin/) — `cache_users`/bulk meta + one quote map keyed by registration
- Tier A. Test contract: seeded N-row export — query count independent of N (assert `num_queries` delta ≤ fixed constant for N=5 vs N=50); output byte-identical to pre-change for the same fixtures (extends B1's export test). AF-5's mid-flow edge: a registration whose user was deleted yields blank cells, not an aborted stream.
- Deferral line: `Risk not covered: none new — denial path owned by AF-5`.

`── REVIEW GATE ── (tier: STANDARD — multi-file read-path reshaping with output-identity pinned by characterization tests; no schema, no authz change; queries stay in the known accepted AdminAPIController drift zone. Escalation trigger (one-way): any NEW direct-SQL site outside that zone, or any authz-adjacent diff → FULL)`

### Cluster F — Notification badge + audit-table index (perf-critical M2, part 2)

**Task F1 (= audit 2.4): Cache unread count + index the audit table** — Deps: none
- Finding H-4 · CONFIRMED · Path correction: `ntdst-audit` is a shared **plugin** (`web/app/plugins/ntdst-audit/`) — schema change is a framework-plugin migration, version it there, and check its other consumer projects' assumptions before altering (the plugin may ship to Rossi etc.)
- Files: `web/app/plugins/ntdst-audit/src/AuditRepository.php` (`findBySubjectUser` :176-262 — rewrite predicates onto the new column), ntdst-audit schema/migration class (STORED generated column `subject_user_id` from `JSON_EXTRACT(context,'$.user_id')` + index; index on `action`), `Modules/Notification/NotificationService.php:100` (`getUnreadCount` cached — transient/object-cache keyed per user, invalidated on new subject-targeted audit event via the bridge), `Modules/Audit/AuditBridge.php:525-542` (exclude `mail.sent` as a notification source, per the audit's recommendation — confirm with a grep that nothing else consumes mail.sent notifications first)
- Threat-model M8 applies (row-count parity, off-peak deploy note, rollback = drop column/index, idempotent migration).
- Tier A (migration + cache invalidation logic). Test contract (RED first): (1) migration test — row count identical before/after, `subject_user_id` populated correctly for rows with/without `$.user_id` in context (NULL edge); (2) EXPLAIN on the badge query no longer range-scans (integration assertion on the query plan, or minimally: index used — `key` column non-null); (3) invalidation — new subject event bumps the cached count; stale cache never survives a new event (the cache-correctness denial path); (4) `mail.sent` no longer increments unread.
- Deferral line: `Risk not covered: 7-year retention growth (L-7) — post-launch 3.10; cache stampede on the count — acceptable single-key cost`.

**Task F2 (= audit 2.3-prep): Redis object-cache drop-in — PREP ONLY** — Deps: 1.2 (PARKED) + Q5
- Finding H-3 · CONFIRMED (no drop-in)
- Files: add the chosen `object-cache.php` drop-in dependency via composer (e.g. `wpackagist-plugin/redis-cache` — but do NOT activate/copy the drop-in), document the enablement + flush procedure in `site.yml`/deploy notes (**rule: never blind-flush Redis on LMS sites** — fleet rule, cite it in the doc)
- `no unit test: Tier B, config/dependency prep — enablement deferred; nothing executable changes`.
- **BLOCKED for enablement** on Q5 (Redis on Ploi plan?) + parked 1.2 (ops access). Prep is unblocked.

`── REVIEW GATE ── (tier: FULL — schema migration on a shared framework plugin's table (data-layer/migration trigger) with cross-project blast radius; reviewer verifies threat-model M8 + the cross-consumer check)`

### Cluster G — Catalog batch hydration (perf-critical M2, part 3 — biggest item, last)

**Task G1 (= audit 2.2): Batch-hydrate catalog; card partials become pure renderers** — Deps: 0.2 (A4); sequenced after E1 so the `getEffectiveStatusFromPrefetched` refactor benefits from the dashboard work's patterns
- Finding CR-3 · CONFIRMED · Path correction: partials at `themes/stridence/partials/card-edition.php` + `partials/card-course.php` (not `templates/partials/`)
- Files: `partials/card-edition.php` (:49-50 status, :60 get_post, :81-82 isEnrolled — all become data-in), `partials/card-course.php` (:39 per-card WP_Query removed), `page-klassikaal.php` (:37 posts_per_page 200 → server cap 24 + "Toon meer" paged endpoint, :141-152 Alpine paging reworked), `page-online.php`, `archive-sfwd-courses.php`, `templates/.../editions-list.php`; `Modules/Edition/EditionService.php` (extract `getEffectiveStatusFromPrefetched(...)`; existing `getEffectiveStatus()` delegates — **INV-7: one decision point, never fork**); `Modules/Edition/SessionRepository.php` (new `countByEditions()` GROUP BY); reuse `RegistrationRepository::countByEditions()` (:696) + `findByUser()` set for logged-in `isEnrolled`
- Batch pre-pass per the audit's sketch: collect ids → `update_post_caches` / `update_meta_cache` / `update_object_term_cache` + the two repo batch counts + one findByUser set → resolved array into partials.
- Tier A. Test contract (RED first on the count): (1) query-count integration test — catalog render ≤ 40 queries at 28 editions AND at 28+N seeded editions (the independence assertion is the contract); (2) `getEffectiveStatusFromPrefetched` equivalence — for a matrix of edition states (terminal, past end-date, classroom-no-sessions, open) it returns exactly what `getEffectiveStatus` returns (the INV-7 denial path: divergence = failure); (3) enrolled-state parity for a logged-in fixture.
- **Sibling-site audit (mandatory):** `getEffectiveStatus()` is a server-side guard elsewhere (INV-7 table) — grep all callers and confirm none are accidentally switched to the prefetched variant without full data; list callers in the task report.
- Acceptance flow AF-4 (incl. the pagination boundary + trashed-course edges) at shake-out; Playwright visual parity on `/klassikaal` + `/online`.
- Deferral line: `Risk not covered: page-cache layer for anonymous traffic — H-3/2.3 territory, partially deferred with Q5`.

`── REVIEW GATE ── (tier: FULL — touches the INV-7 convergence point (named architecture invariant) on the highest-traffic public pages; full finder set + feature-acceptance browser pass; security-sentinel scope limited to "no new input surface" confirmation)`

### Phase integration gates

After every cluster's review gate passes: `/integration` (unit + integration + type-check) before the next cluster dispatches. After Cluster G: full `/shakeout` (test-effectiveness audit + AF-1..5 manifest + reviewer panel at the branch's highest tier = FULL).

---

## Blocked-on-Stefan map (open questions → tasks)

| Q | Question | Blocks | Unblocked default |
|---|---|---|---|
| Q2 | What do users upload as completion proof? | **D1 (1.7) dispatch priority only** — work is fully specified either way | Execute D1 (protection is the safe default); "benign-only" answer demotes it to M3 |
| Q3 | Perf budget — are ≤40 catalog / ≤60 dashboard the real targets? | Acceptance **thresholds** of E1 + G1 (not the work itself) | Proceed with inferred budgets; thresholds are one-line test constants to adjust |
| Q4 | Git history rewrite go/no-go (90 MB purge) | **Nothing in-scope** (3.1 is M3/post-launch) | No action this sprint |
| Q5 | Redis available on the Ploi production plan? | **F2 enablement** (prep unblocked); shape of H-3 mitigation | Prep the drop-in + docs; if "no Redis", H-3 falls back to page cache / longer TTLs — new mini-task next sprint |

Additionally parked (not a question): **1.2** Makefile/deploy — Stefan personally; F2 enablement also waits on it for ops access.

---

## Execution order summary

A (0.1, 0.3, 0.4, 0.2 — STANDARD) → B (0.5, 1.1, 1.3, 1.4 — FULL) → C (1.5, 1.6, 3.5, 3.7 — STANDARD) → D (1.7 — FULL, surface Q2 first) → E (2.1, 2.5, 2.6 — STANDARD) → F (2.4, 2.3-prep — FULL) → G (2.2 — FULL).

Respects every audit Dep: 0.1→0.2→0.5→1.1→2.6; 0.4→1.6; 0.1→1.5; 0.2→2.1/2.2; 0.5→2.5. Quick-wins (0.1, 1.1, 1.3, 1.4, 1.6, 3.5, 3.7) all land within the first three clusters. Tier escalation is one-way: any finding on a 1a surface promotes its cluster to FULL.
