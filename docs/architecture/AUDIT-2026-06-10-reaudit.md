# Stride LMS — Technical Audit & Improvement Plan (Independent re-audit)

**Date:** 2026-06-10 (evening) · **Branch:** `staging` @ `082c0e66` · **Method:** 4 parallel verification passes (closure-verification, open-findings sweep, security-sentinel on changed surfaces, testing/CI/DevEx) + direct spot-checks. Analysis only — no code modified.

> Companion to `AUDIT-2026-06-10.md` (the morning audit, grade B−). That audit was followed by a ~75-commit remediation sprint the same day. The core of this report is **independent verification of every claimed closure** plus a fresh sweep for what remains and what the sprint introduced.

---

## Executive Summary

**Overall health grade: B+** (up from this morning's self-assessed B−, and the upgrade is earned, not claimed: 15 of 18 remediation claims verified closed in code, 2 partial, 1 deliberately parked). The three morning Criticals are all verifiably dead — CI now runs 916 unit + 458 integration tests plus PHPStan on every push/PR, and the catalog/dashboard N+1 collapses are fixed *with query-budget regression tests* (≤40 / ≤60) so they cannot silently return. Security posture is genuinely strong: a fresh sentinel pass over today's ~75 commits found zero Critical/High/Medium issues — proof downloads, PII reveal, and the new public catalog endpoint all enforce authorization server-side, and all three schema migrations added today fail closed. The top remaining risks are: **(1)** the documented deploy procedure still does not exist (`site.yml` declares `make deploy-staging`; there is no Makefile) days from launch; **(2)** `wp_vad_registrations` has no UNIQUE constraint on (user_id, edition_id) and duplicate rows have already caused one real bug; **(3)** the behavioral suites (149 acceptance scenarios, 20 Playwright specs) run only on a developer's DDEV, and 60+ acceptance assertions are `dontSee('Fatal error')`-grade. The top opportunities are correspondingly cheap: decide the deploy method (the Ploi git-push webhook likely already works), add the UNIQUE key at the next version-gated schema bump, and exclude the test-login backdoor from the production artifact (one deploy-script line). What keeps this from A− is operational, not code: deploy story, 120 MB pack with ~90 MB debris, the 3,627-line `AdminAPIController`, and Redis prepped but not enabled.

---

## Repo Map

**Purpose.** Production-bound LMS for VAD training management (4,000+ users at launch); clean rewrite of VAD Vormingen v3. Maturity: late pre-launch ship-mode — hardening sprint phases 0–3 and the audit-remediation sprint both closed today; Phase 4 (deploy readiness) is the open frontier.

**Stack.** Bedrock WordPress, PHP 8.3, MariaDB 10.11 (DDEV local, Ploi hosting), LearnDash as content engine behind a 5-operation adapter, NTDST Core framework (DI container, router, data layer, audit plugin), FluentCRM/Forms/SMTP, DOMPDF, Tailwind + Alpine.js + Vite theme.

**Architecture.** Strict mu-plugin/theme split — `stride-core` (150 PHP files, ~44.9k lines: Modules/, Admin/, Handlers/, Domain/ value objects, Infrastructure/) and `stridence` theme (97 files, ~27.4k lines, presentation only). Two high-volume custom tables (`wp_vad_registrations`, `wp_vad_attendance`) owned 1:1 by repositories. Three REST surfaces (Admin, Partner, Assistant) plus the framework's nonce-verified `ntdst/api_data/*` action dispatch (30 req/min rate limit, origin check). Eight named architecture invariants in `ARCHITECTURE-INVARIANTS.md`; three (INV-1 authorization, INV-5 plugin/theme boundary, INV-8 VAT single-source) are **blocking** in CI via `scripts/check-invariants.sh`.

| Area | One-liner |
|---|---|
| `web/app/mu-plugins/stride-core/` | All business logic; largest file `Admin/AdminAPIController.php` (3,627 lines, 25 routes) |
| `web/app/themes/stridence/` | Presentation; new `services/frontend/CatalogEndpoint.php` powers paged public catalog |
| `tests/` | 73 Unit + 59 Integration files (CI), 22 acceptance Cests + 20 Playwright specs (DDEV-only) |
| `.github/workflows/` | ci.yml (invariants, Pint, composer validate, **unit suite, PHPStan L1**), integration.yml (**real WP + MariaDB integration suite**) |
| `phpstan.neon` + baseline | Level 1, baselined; new violations fail CI |
| `docs/`, `tasks/`, `site.yml` | Current and accurate: LAUNCH-CHECKLIST, AUDIT-2026-06-10, acceptance-flow manifests, ops config |
| `bck/`, root `*.zip` | ~90 MB tracked debris; pack size 120.5 MiB |

**What surprised me.** (1) The same-day audit→remediate→verify loop actually held up under independent verification — including RED-first regression tests for the bugs it fixed, which is rare discipline. (2) The morning audit's M-6 finding (theme hardcodes status arrays) was *quietly* fixed during the catalog rework — the close-out follow-up list still claims `front-page.php` hand-rolls literals, but it doesn't anymore (it uses a `WP_Query` meta filter; `catalog.php:75,468` and `archive-sfwd-courses.php:46` use `OfferingStatus::activeValues()`). The follow-up list is staler than the code. (3) An auth backdoor (`test-login-helper.php`) ships in the production artifact — its three gates are cryptographically sound, but it's one refactor away from being armed.

---

## Audit Report

**[F]** = verified fact (file read / grep / measured) · **[J]** = judgment. No Critical findings remain.

### Closure verification (the morning audit's headline items)

| Morning finding | Verdict | Evidence |
|---|---|---|
| CR-1 CI runs zero tests | **CLOSED** [F] | `ci.yml:82` `composer test:unit`; `integration.yml:93` `composer test:integration` against fresh WP + MariaDB; both block PRs |
| CR-2 dashboard 357+ queries | **CLOSED** [F] | Per-request memo `UserDashboardService.php:44,51,269-270` with invalidation `:74-78`; `getNavData()` deleted; budget test `tests/Integration/UserDashboardMemoizationTest.php` (≤60) |
| CR-3 catalog N+1 ×200 cards | **CLOSED** [F] | Batch prefetch `helpers/catalog.php:333-377`; partials are pure renderers; server cap 24 + paged `CatalogEndpoint.php`; budget test `CatalogRenderQueryBudgetTest.php` (≤40, N-independence ±3) |
| H-1 dead columns (broken CSV export + action queue) | **CLOSED** [F] | `AdminAPIController.php:2302-2304` (`completion_tasks`), `:612-615` (`registered_at`); RED-first tests `tests/Integration/Admin/RegistrationExportTest.php`, `ActionQueueIncompleteTasksTest.php` |
| H-4 audit-log badge scan | **CLOSED** [F] | Generated `subject_user_id` column + `idx_subject_user` (`ntdst-audit/src/AuditTable.php:64-69,136-142`); unread count cached, `mail.sent` excluded (commit `83311643`) |
| H-5 VAT in 3 places | **CLOSED** [F] | Sole literal in `QuoteCalculator.php:16`; enforced as blocking INV-8 (`check-invariants.sh:141-162`). Residual: `quote-admin.js:22` carries `taxRate: 0.21` as a *documented exception* — see L-6 below |
| H-6 plugin→theme helper call | **CLOSED** [F] | `stride_format_date` now in `stride-core/Support/formatting.php:19-27`; INV-5 grep blind spot fixed and blocking |
| H-8 unbounded approvals | **CLOSED** [F] | JSON pre-filter + column trim + pagination (`AdminAPIController.php:2295-2308`); `PendingApprovalsBoundedTest.php` |
| M-1 PII reveal unaudited | **CLOSED** [F] | `AuditService::record()` at `AdminAPIController.php:3024`; tests incl. audit-insert-failure path |
| M-2 public proof uploads | **CLOSED** [F] | Four layers: `uploads/stride-proofs/` + `.htaccess`, nginx `deny all` (`.ddev/nginx_full/nginx-site.conf:64-66`, prod rule in `site.yml:35-37`), `post_status private` (REST enumeration killed — caught by the review panel), owner-checked download handler with `realpath` containment (`CompletionProofStorage.php:114-124`) |
| M-4 partner ENUM | **CLOSED** [F] | `RegistrationTable.php:58`, SCHEMA_VERSION 2, version-gated migrate with failure backoff |
| M-7 no static analysis | **CLOSED** [F] | `phpstan.neon:18` level 1 + baseline, blocking in `ci.yml:86-87` |
| M-6 theme status literals | **CLOSED (quietly)** [F] | See "surprises" above; the close-out follow-up #4 is stale |
| M-12 stale docs | **CLOSED** [F] | README rewritten; CLAUDE.md service registry now matches `plugin-config.php` (25 class entries) — verified directly |
| L-2 test-login gate | **HARDENED** [F] | `test-login-helper.php:22-40`: hard `WP_ENV === 'production'` return, DDEV/Codeception second gate, env-only secret, HMAC + `hash_equals` |
| H-2 deploy path | **STILL OPEN — parked** [F] | `NO MAKEFILE` (verified); see H-1 below |

### High

**H-1 — The documented deploy procedure does not exist, and launch is imminent.** [F] `site.yml` declares `deploy.method: makefile` / `make deploy-staging`; no Makefile exists anywhere in the repo (verified by direct `ls`). Stefan explicitly parked this on 2026-06-10 ("solve later"), and the crash note in `tasks/todo.md` records the likely answer (Ploi git-push webhook running `composer install --no-dev`). [J] Parked-by-owner is a legitimate status, but it is now the **single largest launch risk in the repository**: everything else has tests, gates, and rollback notes; the act of shipping has none. It also blocks Redis enablement (task 2.3 depends on ops access) and the production nginx proof-deny rule can only be *verified* through a real deploy (`site.yml:35-37` documents the 403/200 curl pair to run).

**H-2 — `wp_vad_registrations` has no UNIQUE constraint on (user_id, edition_id); duplicate rows are not a hypothesis — they already caused a shipped bug.** [F] `RegistrationTable.php:51-80` defines eight indexes (`idx_user_edition` among them) but no UNIQUE key. Commit `8148deaa` (today) fixed `isEnrolled` diverging from batch results *under duplicate rows* — meaning duplicates exist in real data. Application-level `FOR UPDATE` capacity locks mitigate the main enrollment path, but trajectory cascades, partner API creation, and admin tooling all write to this table. [J] At 4,000 users with money attached (quotes hang off registrations), the data layer should enforce what the application promises. The close-out follow-up #2 already specifies the right shape: add it at the next SCHEMA_VERSION bump through the version-gated, backoff-protected `migrate()` — never a standalone ALTER. The missing piece is a dedup pass first (the existing duplicates will make the ALTER fail).

**H-3 — The behavioral test estate (149 acceptance scenarios + 20 Playwright specs, ~613 assertions) runs only on a developer's DDEV, and its assertion floor is weak.** [F] `acceptance.suite.yml` hardcodes DDEV's `db` host, `ckqp_` prefix, and a `selenium:4444` service; `playwright.config.ts` hardcodes `https://stride.ddev.site`. Neither has a CI job. 60+ scenarios across 15 Cest files use `dontSee('Fatal error')` as their sole assertion (`AdminEditionCest` 8×, `AdminVoucherCest` 7×, `DashboardTabShakeoutCest` 7×, `EnrollmentCest` 6×, `FullUserJourneyCest` 6×, `ProfileCest` 6×, …). [J] This was a deliberate, documented deferral ("Playwright after PHPUnit/Codeception are wired") and the unit/integration CI wiring was the right first move. But this repo has *already lived* the local-only-suite failure mode twice (`lesson_suite_counts_need_a_run`, the prefix-mismatch incident), and the smoke-tier assertions mean a page that renders an error message politely — rather than fataling — passes. The risk grows every week post-launch as "all suites green" again becomes a claim only one machine can check.

### Medium

**M-1 — Redis object cache: prepped, not enabled; the launch-scale caching story is still open.** [F] `wpackagist-plugin/redis-cache: ^2.8` in `composer.json:52`; no `object-cache.php` drop-in; enablement procedure documented. Blocked on open question Q5 (is Redis on the Ploi plan?) and on H-1 (ops access). [J] The query-budget fixes dramatically shrink the exposure (the morning audit's 1,500-query nightmare is gone), so this is now Medium, not High — but 4,000 users on options-table transients with zero persistent cache remains untested territory.

**M-2 — Notification read path is unbounded.** [F] `NotificationService.php:100-122` pulls **all** audit entries for a user via `AuditRepository::getForUser()` with no LIMIT or date floor, deduplicating in PHP; audit retention is 7 years. The badge *count* is now cached and indexed (H-4 closure), but opening the notification panel still hydrates an unbounded set that only grows. [J] Slow burn, not a launch blocker; will become user-visible for staff/power users first.

**M-3 — Registrations SQL still lives outside `RegistrationRepository` at ~9 sites (INV-3 drift, the documented `stale_database_reads` bug class).** [F] `vad_registrations` referenced directly in `EditionService.php:111,520`, `EditionFilesZipExporter.php:189`, `TrajectoryService.php:156`, `UserLifecycleService.php:335-480` (4 sites), plus a duplicate table-name constant at `EditionAdminController.php:32`. (`BatchQueryHelper.php:70` is infrastructure and arguably fine.) The sprint moved the pending-approvals scan into the repository (commit `bca0ecd3`) — the remainder is the deferred M3 work. INV-3 is advisory, not blocking, in `check-invariants.sh`.

**M-4 — LearnDash touched outside the adapter/helper at 5 sites (INV-6).** [F] `AuditBridge.php:192-193`, `EditionDetailsMetabox.php:228`, `EditionAdminController.php:1203,1214`, `PartnerAPIController.php:515-516`. LD upgrades currently break at 7 points instead of 2. Advisory invariant; deferred M3 scope.

**M-5 — ~90 MB of tracked binary debris; pack size 120.5 MiB.** [F] `bck/` = 52 MB (Kadence backup), five root SCORM/xAPI zips ≈ 40 MB, marketing screenshots. Awaiting the Q4 history-rewrite go/no-go; untracking now (without rewrite) is the safe default and stops the bleeding for every future clone, including CI checkouts on every push.

**M-6 — Partner API has no rate limiting on enrollment/user creation.** [F] `PartnerAPIController.php:614` — no throttle, no per-company quota. Tenant isolation and IDOR checks remain textbook (verified again today). Explicitly post-launch scope per standing decision; recorded here so it surfaces when partners onboard, not after.

**M-7 — `AdminAPIController` is now 3,627 lines (it *grew* during remediation), 25 routes, with direct unit coverage of ~3 methods.** [F] Line count via `wc -l`; the sprint's fixes were applied surgically and tested via integration tests, which is fine — but the god class absorbed more logic (batched export, bounded approvals). [J] The post-launch decomposition decision stands and re-litigating it is double-counting; the *trend* is the finding: every sprint that touches admin functionality makes the eventual extraction more expensive. The planned thin-controller/service-layer split (`project_unified_api_postlaunch`) should be scheduled, not just parked.

### Low

- **L-1 — PII reveal route has no rate limit.** [F] `AdminAPIController.php:364` — the framework's 30/min limiter covers only `ntdst/action`, not `stride/v1` REST. A compromised `stride_manage` session can script-harvest national IDs for the whole user base in minutes; every call is audited (detective control) but nothing throttles it (preventive). Small transient-counter fix.
- **L-2 — Test-login backdoor ships in the production artifact.** [F] `test-login-helper.php` is a tracked root mu-plugin, auto-loaded everywhere. All three gates verified sound (hard prod return; DDEV/Codeception env requirement; env-only secret + HMAC). [J] Defense-in-depth says an auth backdoor shouldn't rely solely on its own gates — one "cleanup" refactor of the `WP_ENV` check arms it. One-line deploy-script exclusion.
- **L-3 — PII-reveal audit is fail-open by design.** [F] `AdminAPIController.php:3020-3038`, documented "availability over strictness" ruling, tested. Accepted risk; recorded so it stays visible: during an audit-subsystem outage, PII access is unattributable except via webserver logs.
- **L-4 — `searchUsers` lets read-only `stride_view` holders enumerate emails/organisations via wildcard search.** [F] Route at `AdminAPIController.php:332-339` uses `canViewAdmin`; method returns `user_email` + `organisation`, 10 rows per query, unthrottled. Its sibling `getUserDetail` gates the sensitive tier correctly. Unchanged from the morning audit.
- **L-5 — Dead composer autoload** `"stride\\": "web/app/themes/stride/"` → nonexistent directory. [F] `composer.json:65`.
- **L-6 — `quote-admin.js:22` hardcodes `taxRate: 0.21`.** [F] Documented as an INV-8 exception (`check-invariants.sh:147-150`) — honest, but the JS preview can still drift from the server derivation. The close-out follow-up #7 (localize from `QuoteCalculator` via `wp_localize_script`) is the right fix.
- **L-7 — `scripts/` junk drawer.** [F] `auto-login.php`, `check-post-5913.php`, `check-user1-pass.php`, `test-fg.php` + 4 more one-offs alongside production tooling.
- **L-8 — One status-label map remains** at `EditionAdminController.php:1356-1367` (the other two sites from the morning audit's M-9 no longer exist — partially fixed). [F]
- **L-9 — Latent LTI integration-test failure.** [F] `WPDataConnectorTest::canUpdateExistingPlatform` (`tests/Integration/NetdustLTI/WPDataConnectorTest.php:156-173`) has no skip marker — if the netdust-lti suite path runs in CI it will red the build for a plugin that isn't in launch scope. Either fix `PlatformRepository::update()` or skip-with-rationale.
- **L-10 — Module pass-throughs** on `TrajectoryDashboardService` (3 methods, `:34-37, :44-47, :205-208`) — the documented `pure_passthrough_is_drift` pattern. [F]

*(Corrections to the morning audit by this pass: L-8 module cycles — this sweep found **no** circular imports between Edition/Enrollment/Trajectory; the import graph is clean fan-out, so that finding is retired. M-9 is two-thirds fixed. M-6 is fully fixed.)*

### Strengths (verified today — preserve these)

1. **The audit→remediate→verify loop works.** RED-first regression tests for fixed bugs, query budgets *enforced by tests*, blocking invariants extended as fixes landed (INV-8 created with the VAT fix). This is the strongest signal in the repo: it doesn't just fix bugs, it makes their *class* unrepresentable.
2. **Security on the changed surfaces is exemplary** — fresh sentinel pass found zero Critical/High/Medium: proof downloads resolve ownership from server-stamped meta with `realpath` containment; the new catalog endpoint allowlists every input and leaks nothing to guests (user ID flows only from `get_current_user_id()`); CSV formula-injection neutralization survived the batching rewrite on all seven cells; all three migrations added today are version-gated, result-checked, backoff-on-failure, fail-closed.
3. **CI is now a real gate**: invariants (3 blocking), Pint, composer validate, unit suite, PHPStan L1, full integration suite against fresh WP + MariaDB — all blocking on PR.
4. **Test quality at the top tier is genuinely high**: query-count budget tests with N-independence tolerance, denial-path coverage, DB-state assertions, audit-insert-failure coverage.
5. **Concurrency correctness where money/capacity is at stake** (`FOR UPDATE` + re-check on vouchers and capacity) — unchanged and still right.
6. Docs are current and honest — `site.yml` even documents the post-deploy curl verification pair for the proof-deny rule.

---

## Improvement Strategy

The morning audit's themes 1–3 (verification gap, read-path scale, single-source-of-truth) are substantially executed. The remaining findings cluster into four new themes:

### Theme 1 — The act of shipping is the last ungated path (H-1, M-1, L-2)
Every code path now has a gate; deployment has none — no procedure, no artifact hygiene (the backdoor ships), no enabled production cache. **Target state:** one verified deploy method (almost certainly Ploi git-push, per the crash note), a deploy artifact that excludes test tooling, Redis enabled or explicitly ruled out, and the proof-deny curl pair run on staging. **Principle:** the deploy is part of the product; it deserves the same RED→GREEN treatment (deploy to staging = the test).

### Theme 2 — Enforce in the schema what the application promises (H-2)
The application promises one active registration per user+edition; the schema doesn't, and reality already diverged. **Target state:** dedup pass + UNIQUE key at SCHEMA_VERSION 3 through the existing backoff-protected migration machinery. **Principle:** invariants that matter at 4,000 users belong in the database, not just in `FOR UPDATE` blocks.

### Theme 3 — Make the behavioral suites un-rottable (H-3, L-9)
1,374 CI-run test methods protect the code; ~760 behavioral assertions protect the *product* and run nowhere automatically. **Target state (post-launch, staged):** a nightly CI job running the acceptance suite against a containerized WP+selenium (the integration workflow already proves the DB recipe); assertion floor raised from `dontSee('Fatal error')` to at least one positive content assertion per scenario; the latent LTI failure skipped-with-rationale so the suite is honestly green. **Trade-off accepted:** not before launch — the pre-launch shakeouts were run by hand and documented; the rot risk is a weeks-scale problem, not a days-scale one.

### Theme 4 — Stop the god class from compounding (M-7, M-3, M-4)
`AdminAPIController` grew during the very sprint that fixed its bugs, and the registrations-SQL/LD-call drift classes feed it. **Target state:** the post-launch service-layer extraction gets a date, and until then a soft freeze: new admin endpoints land as separate controllers; the M3 consolidation tasks (repository method for edition-registrations, 4 LD helper methods) land as the first slice of the extraction, not as separate polish. **Principle:** you don't have to decompose it now, but you must stop feeding it.

### Explicitly NOT fixing (trade-offs)
- **AdminAPIController full decomposition before launch** — unchanged ruling, correct: risk without payoff days from launch.
- **Partner API rate limiting (M-6)** — post-launch scope holds; no partners are live.
- **Notification read-path rework (M-2)** — bound it cheaply (date floor + LIMIT) post-launch; full pagination is not worth pre-launch churn.
- **Git history rewrite for the 90 MB** — untrack-only is the right default until Stefan rules on Q4; a rewrite invalidates clones during launch week, which is the worst possible timing.
- **Playwright in CI** — acceptance-suite-in-CI dominates it (same coverage, one environment); do that first.

### Definition of done (measurable)
1. A documented deploy command exists and has performed one verified staging deploy (including the 403/200 proof-URL check).
2. `SHOW INDEX FROM wp_vad_registrations` includes a UNIQUE key; the dedup migration ran clean on staging's real data.
3. Production artifact contains no `test-login-helper.php`; `STRIDE_TEST_LOGIN_SECRET` unset in prod `.env`.
4. PII reveal throttled (e.g. 30/5min/user) with a test.
5. `git ls-files` returns no `bck/`, no root zips; CI checkout time drops accordingly.
6. (Post-launch) nightly acceptance job exists and is green; zero `dontSee('Fatal error')`-only scenarios.

---

## Task Plan

### Milestone 0 — Safety net *(mostly already done — the sprint built it; two residuals)*

| # | Task | Files/areas | Acceptance | Effort | Risk | Deps |
|---|---|---|---|---|---|---|
| 0.1 | RED regression test: duplicate (user_id, edition_id) rows are representable today | `tests/Integration/Enrollment/` | Test inserts a duplicate pair and currently passes (documents the hole); flips to expecting rejection after 1.2 | S | None | — |
| 0.2 | Skip-with-rationale or fix the latent LTI failure so the integration suite is honestly green | `tests/Integration/NetdustLTI/WPDataConnectorTest.php:156` (skip) or `netdust-lti/.../PlatformRepository.php::update()` (fix) | Suite green with no silent latents; skip cites this report | S | None | — |

### Milestone 1 — Launch-critical (this week, before launch)

| # | Task | Files/areas | Acceptance | Effort | Risk | Deps |
|---|---|---|---|---|---|---|
| 1.1 | **Resolve the deploy method** (Stefan-parked — needs his 30-minute decision, then execution): confirm Ploi git-push webhook, update `site.yml` to `deploy.method: git-push`, run one verified staging deploy incl. proof-URL 403/200 check | `site.yml`, Ploi panel | `site.yml` matches reality; one staging deploy performed and verified | M | Medium (first verified deploy) | Stefan |
| 1.2 | **Dedup + UNIQUE key** on `wp_vad_registrations` (user_id, edition_id, status-class) at SCHEMA_VERSION 3 via existing `migrate()` w/ backoff | `RegistrationTable.php`, dedup script | 0.1's test flips GREEN; migration clean on staging copy of real data | M | Medium (data migration — rehearse on staging dump) | 0.1 |
| 1.3 | **Exclude test-login-helper from prod artifact** + assert `STRIDE_TEST_LOGIN_SECRET` absent in prod env checklist | deploy script / composer archive-exclude, `docs/LAUNCH-CHECKLIST.md` | Deployed tree lacks the file | S | Low | 1.1 |
| 1.4 | **Rate-limit PII reveal** (transient counter, 30/5min/user) | `AdminAPIController.php:3004` | 31st call in window → 429; test | S | Low | — |
| 1.5 | **Redis enablement on staging** (post-Q5 answer): drop-in + flush procedure per the fleet rule | hosting, `object-cache.php` | Staging serves transients from Redis; no LMS blind-flush path | S–M | Medium (ops) | 1.1, Q5 |

### Milestone 2 — High-leverage (first weeks post-launch)

| # | Task | Files/areas | Acceptance | Effort | Risk | Deps |
|---|---|---|---|---|---|---|
| 2.1 | Nightly acceptance CI job (containerized WP + selenium, reuse integration.yml's DB recipe; parameterize `acceptance.suite.yml` host/prefix) | new workflow, `tests/acceptance.suite.yml` | Nightly run green; failures notify | L | Low (CI-only) | — |
| 2.2 | Raise acceptance assertion floor: replace the 60+ `dontSee('Fatal error')`-only checks with ≥1 positive assertion each | 15 Cest files | grep for solitary dontSee returns zero | M–L | Low | 2.1 helps verify |
| 2.3 | Bound notification reads (date floor matching retention policy + LIMIT, paginate panel) | `NotificationService.php:100-122` | Panel query bounded regardless of audit volume; test | M | Low | — |
| 2.4 | Consolidate registrations SQL: `RegistrationRepository` methods for the 9 external sites; delete duplicate table constant; promote INV-3 grep to blocking for `vad_registrations` | EditionService, TrajectoryService, UserLifecycleService, exporters | Grep returns only repository + BatchQueryHelper; blocking in check-invariants.sh | M | Medium | — |
| 2.5 | LD helper consolidation: 4 new `LearnDashHelper` methods, repoint 5 sites, INV-6 grep blocking | per M-4 list | INV-6 grep clean and blocking | M | Low | — |
| 2.6 | Localize `taxRate` from QuoteCalculator via `wp_localize_script`; remove the INV-8 JS exception | `quote-admin.js:22`, enqueue site | Exception deleted from check-invariants.sh; JS reads injected rate | S | Low | — |

### Milestone 3 — Quality & polish (post-launch backlog)

| # | Task | Effort | Notes |
|---|---|---|---|
| 3.1 | Untrack `bck/` + root zips + marketing pngs (~90 MB); history rewrite separately pending Q4 | S | Do during a quiet week, not launch week |
| 3.2 | Purge `scripts/` debris (8 one-off files) | S | |
| 3.3 | Remove dead `stride\` autoload (`composer.json:65`) | S | |
| 3.4 | Last status-label map → enum `label()` (`EditionAdminController.php:1356`) | S | |
| 3.5 | Gate or trim `searchUsers` for `stride_view` (mask email, or require `stride_manage` for email field) | S | Product call on supervisor needs |
| 3.6 | Delete `TrajectoryDashboardService` pass-throughs | S | |
| 3.7 | **Schedule** the AdminAPIController service-layer extraction (`project_unified_api_postlaunch`) + soft freeze: new admin endpoints land outside the god class | XL — needs its own plan | The one structural debt with a compounding interest rate |
| 3.8 | Partner API rate limiting — when partners onboard | M | |

### Quick wins (all S, do immediately)
**1.3** (exclude backdoor from artifact — one deploy-script line), **1.4** (PII throttle), **0.2** (honest-green suite), **3.1–3.4** (debris/autoload/label batch — under an hour combined), **2.6** (taxRate localization). Together: roughly half a day, closes two security-hardening items and the most embarrassing repo-hygiene items.

### Implementation sketches — top 3

**1.1 — Deploy method.** Evidence strongly suggests git-push: the crash note records Ploi's auto-deploy webhook already runs `composer install --no-dev` on push. Steps: (1) confirm in the Ploi panel that the staging site's deploy script exists and note its exact commands; (2) flip `site.yml` to `deploy.method: git-push` with the branch mapping (staging→staging site, main→prod); (3) push a trivial commit to staging and watch the webhook; (4) run the documented proof-URL verification (`site.yml:35-37` — expect 403 on `/app/uploads/stride-proofs/...`, 200 via the authed handler); (5) confirm prod `.env` lacks `STRIDE_TEST_LOGIN_SECRET` and `WP_ENV=production`. Gotchas: the Ploi script must include the nginx proof-deny rule (it lives in DDEV config locally — it does **not** travel with git; it must be added to the Ploi nginx template by hand), and `composer install --no-dev` must not prune anything stride-core lazily requires (DOMPDF is a prod dep — verify).

**1.2 — UNIQUE key.** (1) Write the dedup query first and run it read-only against a staging dump: `SELECT user_id, edition_id, COUNT(*) FROM wp_vad_registrations WHERE status IN (<active set>) GROUP BY 1,2 HAVING COUNT(*) > 1` — the CR-G4 fix's MIN-row determinism rule tells you which row to keep (keep `MIN(id)`, cancel the rest, don't delete — quotes may reference them). (2) Bump `SCHEMA_VERSION` to 3; in `migrate()`, run dedup *then* `ADD UNIQUE KEY uq_user_edition_active` — note MySQL/MariaDB can't do partial indexes, so either make it UNIQUE on (user_id, edition_id, status) and rely on the status-class transition rules, or add a generated `is_active` column and UNIQUE on (user_id, edition_id, is_active) with NULL for inactive (NULLs don't collide) — the generated-column pattern is already proven in `ntdst-audit/AuditTable.php`. (3) The existing failure-backoff machinery (commit `d7c9ac29`) handles a failed ALTER safely. (4) Flip test 0.1 to expect rejection; add a concurrency test that races two inserts.

**2.1 — Acceptance in CI nightly.** The integration workflow already proves the recipe for WP + MariaDB in Actions. Add: `selenium/standalone-chrome` as a service container; parameterize `acceptance.suite.yml` (`dsn`, `tablePrefix`, `host`) via `%...%` params from env (the suite already parameterizes `%WP_URL%`, so the pattern exists); seed via `scripts/seed.php` (the suite's fixtures already learned the bare-meta-key lesson). Gotcha: the Cests assume the `ckqp_` prefix from the ported v3 DB — the env-param change must reach `WPDb.tablePrefix`, and `test-login-helper` needs `CODECEPTION_TEST=1` set in the job env to pass its second gate. Run `schedule: cron` nightly, not on PR (Selenium flake on the PR path would erode trust in the gate).

---

## Open Questions (need Stefan)

1. **Deploy method (decides task 1.1):** confirm git-push via Ploi webhook as the method, or specify what the Makefile was meant to wrap. This is the only launch-blocking decision left in the repo.
2. **Q5 carried forward:** is Redis on the Ploi production plan? Decides 1.5's shape.
3. **Q4 carried forward:** go/no-go on git history rewrite for the 90 MB (untrack-only is the default; rewrite invalidates clones).
4. **UNIQUE-key status semantics (task 1.2):** when a user re-enrolls after cancellation, should the cancelled row block re-insert (UNIQUE on user+edition) or not (UNIQUE on active rows only)? The generated-column variant supports the latter; product intent decides.
5. **Dashboard LD floor (close-out follow-up #6):** do the ~32 free e-learnings belong on every user's dashboard? It hydrates per user and makes the "inschrijvingen" empty state unreachable — product ruling, not engineering.
6. **`searchUsers` for supervisors (3.5):** do `stride_view` holders need email addresses in search results, or can those be masked to the detail-view tier?
7. **Dateless/self-paced editions (follow-up #3):** currently excluded from all catalog enumerations by the date-window EXISTS clause — confirm that's intended product behavior before a client files it as a bug.

---

**Bottom line:** this codebase audited itself this morning, fixed three Criticals and eleven High/Mediums the same day, and — verified independently — the fixes are real, tested, and gated against regression. The remaining exposure is concentrated almost entirely in *operations*: the deploy path, the production cache, the artifact hygiene, and one missing database constraint. Those four items are days of work, and closing them is what separates a B+ codebase from an A− launch.
