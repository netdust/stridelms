# Codeception Acceptance CI Gate — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the Codeception acceptance suite (currently DDEV-local only) into GitHub Actions CI, gating a small reliable SMOKE SUBSET green first, via a Codeception env overlay (`tests/_envs/ci.yml`) that leaves the DDEV-default config untouched.

**Architecture:** A new workflow `acceptance.yml` mirrors the existing `integration.yml`/`browser.yml` WordPress standup (MariaDB service, `install-wp.sh`, `php -S` + `router.php`), adds a `selenium/standalone-chrome` service container, and runs `vendor/bin/codecept run acceptance --env ci`. The overlay reconciles the three things the DDEV-default suite hardcodes for DDEV (`host: selenium` → the runner-mapped Selenium endpoint; `dsn host: db` → the runner-mapped MariaDB; `tablePrefix: stride_` → the CI install prefix) and — critically — points both `WP_HOME` and `WP_URL` at the **runner-host bridge-gateway IP** so the Selenium container can reach `php -S` while the asset-origin host string still matches the page origin (the CORS-module trap). The shared `tests/acceptance.suite.yml` is NOT edited, so local DDEV runs keep working.

**Tech Stack:** GitHub Actions, Codeception `^4.5` (`lucatume/wp-browser ^4.5`), WPWebDriver + WPDb, `selenium/standalone-chrome:latest`, MariaDB 10.11, PHP 8.3 `php -S`, wp-cli.

---

## Global Constraints

- **Do NOT edit `tests/acceptance.suite.yml` or `codeception.yml`.** They are the DDEV-default source of truth; reconciliation happens ONLY in the new `tests/_envs/ci.yml` overlay. (Memory `bug_acceptance_prefix_mismatch`: the prefix has flip-flopped `stride_`↔`ckqp_` across DB ports — the shared suite default stays `stride_` to keep DDEV green; CI overrides via overlay.)
- **DB-SAFETY (BLOCKING):** the acceptance suite drives a LIVE DB and writes timestamped fixtures that survive `unseed.php` (memory `gotcha_unseed_misses_acceptance_fixtures`). It MUST only ever run against a throwaway DB. **Never run `codecept run acceptance` against the DDEV dev/demo DB, and never locally in this plan's verification steps** — every "does it work" proof in this plan is the CI run on a seam branch, or a disposable scratch DB. The CI MariaDB service container is a fresh throwaway per run — that is the only acceptable target.
- **Suite scope is the SMOKE SUBSET only** for this plan: `SmokeTestCest`, `EnrollmentCest`, `AdminEditionCest`, `AttendanceCest`, `ProfileCest`. Expanding to all 27 Cests + the seeder is an explicit follow-up (Phase 4 stub below names it; it is OUT of scope here).
- **Throwaway secrets only in the workflow** — `STRIDE_TEST_LOGIN_SECRET` is a CI literal (like `integration.yml`'s `ci-not-secret`), never a real secret, never echoed into logs.
- **Host-string identity rule (CORS):** wherever a host appears for the browser to navigate (`WP_URL`) and wherever the theme emits asset URLs (`WP_HOME`), the host STRING must be byte-identical. The dashboard/enrollment bundle is `type="module"` (CORS-checked); a host-string mismatch = `net::ERR_FAILED` = `window.Alpine` never defined = every Alpine-driving Cest fails. (Memory `gotcha_playwright_ci_cors_module`.)
- PHP 8.3, wp-cli, Composer `--optimize-autoloader`, mirror `integration.yml` step-for-step where shared.

---

## Class & gate decisions (planner judgment — explicit)

**Work class: A** (new multi-task CI capability). Stages 0→1→2→3.

| Gate | Fires? | Trigger / reason |
|---|---|---|
| **1a Threat-modeling** | **YES (light)** | The workflow places `STRIDE_TEST_LOGIN_SECRET` — the literal that arms an **auth-bypass backdoor** (`test-login-helper.php`) — into a CI workflow file. That is an auth/credential surface. The `## Threat model` below is short because the backdoor is hard-gated (verified), but the gate is NOT a no-op: it must affirm the gate holds. |
| **1b Architecture-invariants** | **NO** | Read `ARCHITECTURE-INVARIANTS.md` (INV-1..INV-7). Every convergence point is a **runtime PHP** axis (authorization, AJAX nonce, repository data access, WP_Error, templating, LearnDash adapter, selection state, effective status). This work adds **no runtime PHP code path** — only CI YAML + a Codeception overlay + an optional `composer` script alias. No convergence point is touched. Stated, not assumed. |
| **wp-plan-requirements (Block 0 golden path)** | **none** | No matching archetype — this is CI/test infra, no AJAX/REST/form/CPT/Service/Repository. `## Golden path: none (no matching archetype)`. Blocks 1–3 (per-flow security, ntdst-core layering, drift pre-check) are empty by the same reason: no data flow, no new framework class. |
| **1g Feature-acceptance** | **YES** | The gate IS acceptance coverage — `## Acceptance flows` matrix below names which smoke Cests run + what green means + the bite-proof. |
| **API/boundary design** | **NO** | No API or module boundary designed. |
| **1c Ground-truth** | **DONE** | The three load-bearing premises (Selenium→`php -S` networking; prefix reconciliation; `test-login-helper.php` gate conditions) were read against real source before this plan; see "Ground-truth findings" below. |

---

## Ground-truth findings (read against real source 2026-06-30 — premises CONFIRMED)

1. **`test-login-helper.php` gate (read in full).** Inert unless ALL hold: (a) hard `return` when `WP_ENV === 'production'` (line 23); (b) test-env signal — `getenv('CODECEPTION_TEST')==='true'` OR `defined('CODECEPTION_TEST')` OR `getenv('DDEV_PROJECT')==='stride'` (lines 28–35); (c) `STRIDE_TEST_LOGIN_SECRET` present in `$_ENV`/`getenv` with **no hardcoded fallback** (lines 38–41). Endpoints use `hash_hmac('sha256', 'login:'.$id, SECRET)` + `hash_equals` (lines 54–56). → A CI-only throwaway secret in a non-production workflow is NOT a production-exposure path. Gate is sound.
   - **Consumer side:** `tests/_support/Helper/Acceptance.php::testLoginSecret()` reads `getenv('STRIDE_TEST_LOGIN_SECRET')`, else parses `STRIDE_TEST_LOGIN_SECRET=...` from `codecept_root_dir('.env')`, else **throws**. So CI MUST provide the secret to BOTH the web side (Bedrock reads project `.env`) AND the codecept CLI process. A single project-root `.env` line satisfies both (web reads it via Bedrock; the helper parses the same file). The workflow also exports `CODECEPTION_TEST=true` so the web-side test-env signal fires without relying on `DDEV_PROJECT`.

2. **Prefix reconciliation.** `tests/acceptance.suite.yml` hardcodes `WPDb.tablePrefix: 'stride_'` (matches live DDEV since 2026-06-12). `scripts/ci/install-wp.sh` installs with `DB_PREFIX` from `.env` — the existing workflows pass `wp_`. **Decision: install the CI WP with `DB_PREFIX=stride_`** (one-line `.env` change in the new workflow) and set the overlay's `WPDb.tablePrefix: 'stride_'`, so overlay + install agree AND match the shared default — no divergence to drift. The shared suite default is never touched. (Alternative — install `wp_`, override overlay to `wp_` — also works but creates a second prefix value to keep in sync; rejected for fewer moving parts.)

3. **Selenium → `php -S` networking (HIGHEST RISK — designed explicitly).** In GHA, `codecept` + `php -S` run on the **runner host**; `selenium` is a **service container** on a GHA-managed bridge network. Two facts collide:
   - **Reachability:** the chrome process inside the selenium container cannot reach the runner's `localhost` — it must reach the runner via the **bridge gateway IP** of selenium's network, and `php -S` must bind `0.0.0.0` (not `127.0.0.1`) to accept that connection.
   - **CORS:** `WP_URL` (navigated) and `WP_HOME` (asset origin) host strings must match.
   - **Resolution:** resolve the gateway IP **at runtime from inside the running selenium container** (`docker exec <selenium> ip route | awk '/default/{print $3}'`) → `HOST_GW`. Use `http://$HOST_GW:8080` for **both** `WP_HOME` and `WP_URL` (overlay reads `WP_URL`). Bind `php -S 0.0.0.0:8080`. WPDb runs on the runner → DSN host `127.0.0.1` (mapped MariaDB). WPWebDriver `host` (the Selenium endpoint) = `localhost`, port `4444` (runner→service mapping). This single `HOST_GW` value flows into `.env` (`WP_HOME`/`WP_SITEURL`), the `wp core install --url`, and the overlay's `WP_URL`/`WPDb.url` — one source, host strings identical everywhere. **This is the premise most likely to be wrong; Task 3 verifies it empirically (curl from inside the selenium container to `$HOST_GW:8080` BEFORE running any Cest) so a networking failure surfaces as a clear diagnostic, not a wall of Cest timeouts.**

4. **Smoke-subset self-seeding (each `_before` read).** Confirmed self-sufficient WITHOUT `scripts/seed.php`:
   - `SmokeTestCest` — `frontPageLoads` needs nothing; `adminDashboardLoads` uses `grabAdminUserId()` which falls back to the lowest admin → the `ciadmin` user `install-wp.sh` creates. ✓
   - `EnrollmentCest` — `_before` fully self-seeds course+edition+user via `havePostInDatabase`/`haveUserInDatabase`. ✓
   - `AdminEditionCest` — `_before` only needs `grabAdminUserId()` → `ciadmin`. ✓
   - `AttendanceCest` — `_before` fully self-seeds course/edition/session/student + uses `grabAdminUserId()`. ✓
   - `ProfileCest` — `_before` fully self-seeds the test user. ✓
   - **Excluded (seed-dependent — confirmed):** `CourseSidebarStatusCest` (`assertNotEmpty(...'Seed data must provide editions')`), and per the brief: `DashboardE2ECest`, `TrajectoryCascadeCest`, `DashboardTabShakeoutCest`, `DashboardCest`, `OnlineEnrollmentCest`, `AssistantPluginCest`, `CatalogShakeoutCest`. These + the seeder are Phase 4 (follow-up, out of scope).
   - **Decision: the smoke gate does NOT run `scripts/seed.php`.** Every chosen Cest self-seeds or uses the install-created admin, so a seeder-free first gate is both simpler and removes the seeder as a flake variable while we de-risk the container-networking unknown. The seeder + seed-dependent Cests join in Phase 4.

---

## Threat model (gate 1a — light)

**Asset:** the auth-bypass login backdoor (`web/app/mu-plugins/test-login-helper.php`) — it can set an auth cookie for any user ID given a valid HMAC of `STRIDE_TEST_LOGIN_SECRET`.

**Actors:** CI (legitimate); an attacker who reads the public repo / a leaked CI log (adversary).

**Attacks → mitigations:**
- *Backdoor reachable in production.* → Mitigated in code, verified by read: `test-login-helper.php` hard-`return`s on `WP_ENV==='production'` (line 23) AND requires a test-env signal (lines 28–35) AND requires the secret with no fallback (lines 38–41). Production never sets `CODECEPTION_TEST`/`STRIDE_TEST_LOGIN_SECRET` and is `WP_ENV=production`. The workflow only ever sets these on the throwaway CI WordPress (`WP_ENV=development`). **No new production path is introduced by this work.**
- *Secret leak grants real access.* → The CI secret is a **throwaway literal** committed in the workflow (sibling pattern: `integration.yml`'s `ci-not-secret`), valid ONLY against the per-run throwaway CI WordPress that is destroyed with the runner. It is not a production secret and unlocks nothing beyond the disposable CI site. Do NOT use a GitHub Actions `secrets.*` value — a literal makes the throwaway nature explicit and avoids masking-in-logs concerns.
- *Secret echoed into logs.* → No workflow step `echo`s the secret; it is written into `.env` via a heredoc (not on a command line) and exported as a job-level `env:` literal. Bite: the run log must not contain the secret value (Task 4 acceptance checks the standup log does not print it).

**Out of scope (explicit deferrals):** real secrets management / rotation (no real secret exists here); hardening the backdoor itself (unchanged by this work); any production deployment path (this is CI-only).

---

## Golden path: none (no matching archetype)

CI/test infrastructure — no CPT/Repository/Service/Handler/AJAX/REST/shortcode/form. `wp-plan-requirements` Blocks 1–3 (per-flow WP-security, ntdst-core layering, drift pre-check) are empty: no user-facing data flow and no new framework class are introduced. The single PHP file added (`tests/_envs/ci.yml` is YAML; no PHP) — there is no PHP runtime code in this plan at all.

---

## Acceptance flows (gate 1g)

The gate's deliverable IS this matrix. "Green" = the workflow's `codecept run acceptance --env ci` step exits 0 with all listed Cest methods passing, on a `ci-seam-*` branch push.

| Flow (smoke Cest) | What it proves end-to-end | Self-seeds? | Edges covered / excluded |
|---|---|---|---|
| `SmokeTestCest::frontPageLoads` | `php -S` + theme + Selenium-navigation wiring works; no fatal on `/` | yes (none needed) | **Empty:** bare install, no content — intentionally the emptiest page. **Error:** asserts `dontSee('Fatal error')`. Edge classes *concurrent/auth/malformed* excluded — static GET of `/`. |
| `SmokeTestCest::adminDashboardLoads` | login backdoor + admin auth cookie + wp-admin render | yes (`ciadmin`) | **Auth:** drives the `loginAsUserId` backdoor (proves `STRIDE_TEST_LOGIN_SECRET` wiring). **Error:** `grabAdminUserId` throws if no admin. |
| `EnrollmentCest` (all methods) | real Alpine enrollment page renders + reacts (`Alpine.$data`) → **proves the CORS-module origin is correct** | yes (full) | **Empty/malformed/edge:** owned by each Cest method (this is the primary CORS canary — if host strings mismatch, this Cest fails first). |
| `AdminEditionCest` (all methods) | admin edition CRUD UI reachable + functional | yes (admin) | **Auth:** admin-gated screens. |
| `AttendanceCest` (all methods) | admin attendance grid renders a seeded confirmed student | yes (full) | **Edge:** in_person session type gate (only markable for in_person/webinar). |
| `ProfileCest` (all methods) | profile read/update flow | yes (full) | **Error/validation:** profile field validation per method. |

**What "green" means (the bite-proof, Task 4):** the gate is real only when proven green → break one assertion → red → revert → green. Specifically: temporarily change a `SmokeTestCest` assertion to something false, push to a `ci-seam-*` branch, confirm the `acceptance` job goes RED, revert, confirm GREEN. A gate that cannot bite is not a gate.

---

## File structure

| File | Responsibility |
|---|---|
| `tests/_envs/ci.yml` (CREATE) | Codeception env overlay — overrides WPWebDriver `host`/`url` + WPDb `dsn`/`tablePrefix`/`url` for CI ONLY. Merged via `--env ci`. The DDEV-default `acceptance.suite.yml` is untouched. |
| `.github/workflows/acceptance.yml` (CREATE) | The CI job: MariaDB + Selenium services, WP standup (reuses `install-wp.sh` + `router.php`), runtime gateway resolution, `.env` synthesis, `php -S 0.0.0.0`, reachability self-check, `codecept run acceptance --env ci`, artifact upload on failure. |
| `composer.json` (MODIFY, optional) | Add `"test:acceptance:ci": "codecept run acceptance --env ci"` alias so the invocation has one named home (mirrors `test:integration`). |

No changes to `tests/acceptance.suite.yml`, `codeception.yml`, `scripts/ci/install-wp.sh`, `scripts/ci/router.php`, or any Cest.

---

## Task breakdown

### ── REVIEW GATE A ── (tier: STANDARD — CI config + Codeception overlay; no runtime code, no 1a surface yet) Tasks 1–2

Provisional tier per 1h: STANDARD — Tasks 1–2 are the env overlay + the optional composer alias; multi-file config, no auth surface (the secret enters in Task 3). One-way escalation note: if review finds the overlay leaks a real credential or touches the shared suite default, escalate to FULL.

---

### Task 1: Create the CI env overlay `tests/_envs/ci.yml`

**Files:**
- Create: `tests/_envs/ci.yml`

**Interfaces:**
- Consumes: the merged base config from `tests/acceptance.suite.yml` (WPWebDriver + WPDb module configs) and params `%WP_URL%`, `%WP_ADMIN_USERNAME%`, `%WP_ADMIN_PASSWORD%`, `%WP_ADMIN_PATH%` from `tests/.env`. The overlay overrides keys; unspecified keys fall through to the base.
- Produces: the `ci` env name consumed by `vendor/bin/codecept run acceptance --env ci`.

**Tier expectation (testing-workflow): Tier B — `no unit test: Tier B, declarative config file (a Codeception env overlay); its correctness is proven by the suite running green in CI (Task 3/4), not by a bespoke unit test`.** Test contract n/a.

- [ ] **Step 1: Write the overlay**

Codeception env files override the suite's `modules.config` block. The CI host is the runner-bridge gateway, injected at runtime via `tests/.env` (`%WP_URL%`); the only literals here are the structural CI overrides (Selenium endpoint reachable from the runner as `localhost`, MariaDB DSN reachable from the runner as `127.0.0.1`, and the prefix that matches the CI install).

```yaml
# tests/_envs/ci.yml — CI-ONLY overlay merged via `codecept run acceptance --env ci`.
# Overrides ONLY what differs from the DDEV-default tests/acceptance.suite.yml:
#   - WPWebDriver.host: the Selenium service is reachable from the runner host as
#     localhost:4444 (GHA maps the service container's published port to runner
#     localhost), NOT the docker-compose service name `selenium` DDEV uses.
#   - WPDb.dsn host: the MariaDB service is reachable from the runner as 127.0.0.1.
#   - WPDb.tablePrefix: the CI WordPress is installed with DB_PREFIX=stride_, matching
#     the shared default — kept explicit so the overlay is self-describing.
# WP_URL (the browser-navigated origin) comes from tests/.env, set by the workflow to
# http://<runner-bridge-gateway>:8080 so the Selenium container can REACH php -S AND the
# host string matches WP_HOME's asset origin (CORS-module rule). The shared suite default
# is never touched — local DDEV runs keep working.
modules:
  config:
    WPWebDriver:
      host: localhost
      port: 4444
      path: /wd/hub
    WPDb:
      dsn: 'mysql:host=127.0.0.1;dbname=wordpress'
      user: 'wordpress'
      password: 'password'
      tablePrefix: 'stride_'
```

- [ ] **Step 2: Verify the overlay parses (no DB/network — pure load check)**

Run (safe — `--env ci` with no DB present will fail at module connect, NOT at parse; we only assert the env merges and the overlay is recognized):

```bash
ddev exec vendor/bin/codecept run acceptance --env ci --no-exit -g __nonexistent_group__ 2>&1 | head -20
```

Expected: Codeception loads the `ci` env and reports `0 tests` for the bogus group (proves the overlay YAML parsed and the env name resolved). It MUST NOT print a YAML parse error. **It must NOT actually execute any Cest** — the bogus `-g` group guarantees zero Cests run, so this never touches the DDEV DB (Global Constraint: no acceptance run against the dev DB). If it errors on DB connect instead of parsing, that is acceptable here — the parse happened first.

- [ ] **Step 3: Commit**

```bash
git add tests/_envs/ci.yml
git commit -m "test(ci): add Codeception CI env overlay for acceptance suite"
```

---

### Task 2: Add the `test:acceptance:ci` composer alias (optional, naming only)

**Files:**
- Modify: `composer.json` (the `scripts` block — add one line after `test:integration`)

**Interfaces:**
- Consumes: nothing.
- Produces: `composer test:acceptance:ci` → `codecept run acceptance --env ci`, referenced by Task 3's workflow.

**Tier expectation (testing-workflow): Tier B — `no unit test: Tier B, a one-line composer script alias; correctness is the workflow invoking it (Task 3)`.**

- [ ] **Step 1: Add the script line**

In `composer.json` `"scripts"`, after `"test:integration": "phpunit -c phpunit-integration.xml.dist",` add:

```json
        "test:acceptance:ci": "codecept run acceptance --env ci",
```

- [ ] **Step 2: Verify it is registered**

```bash
ddev exec composer run-script --list 2>&1 | grep "test:acceptance:ci"
```

Expected: the line `test:acceptance:ci` appears. (Do NOT actually run it here — it would hit the DDEV DB. The workflow runs it against the throwaway CI DB only.)

- [ ] **Step 3: Commit**

```bash
git add composer.json
git commit -m "chore(ci): add test:acceptance:ci composer alias"
```

---

### ── REVIEW GATE B ── (tier: FULL — the workflow introduces STRIDE_TEST_LOGIN_SECRET, an auth-backdoor literal, and the Selenium↔php-S networking is the highest-risk seam) Tasks 3–4

Provisional tier per 1h: **FULL** — Task 3 places the `STRIDE_TEST_LOGIN_SECRET` auth-bypass literal into the workflow (a 1a auth/credential surface) AND owns the highest-risk networking premise. Per the 1h rule, a 1a surface ⇒ FULL: review with all finders + `security-sentinel` (confirm the secret is throwaway, the backdoor stays prod-gated, no log echo). One-way escalation already at FULL.

---

### Task 3: Create the acceptance CI workflow `.github/workflows/acceptance.yml`

**Files:**
- Create: `.github/workflows/acceptance.yml`

**Interfaces:**
- Consumes: `scripts/ci/install-wp.sh`, `scripts/ci/router.php` (reused unchanged), `tests/_envs/ci.yml` (Task 1), `composer test:acceptance:ci` (Task 2).
- Produces: a `ci-seam-*`/PR-triggered `acceptance` job that gates the smoke subset green.

**Tier expectation (testing-workflow): Tier A — the workflow IS the test harness; its "test contract" is the Acceptance flows matrix above. The RED-first proof is Task 4 (break→red→revert→green). The denial/auth path under test is the login-backdoor (`SmokeTestCest::adminDashboardLoads` + `EnrollmentCest`).** No bespoke unit test (a workflow is not unit-testable); the bite-proof in Task 4 is the equivalent RED-first evidence.

- [ ] **Step 1: Write the workflow**

Key design points baked in (all ground-truthed above):
- Two service containers: `database` (mirrors `integration.yml`) + `selenium` with `--shm-size=2g` and `4444:4444`.
- Standup mirrors `integration.yml` (checkout → PHP → composer → `.env` → `install-wp.sh` → `php -S`), with three CI-acceptance-specific deltas: (a) `.env` adds `STRIDE_TEST_LOGIN_SECRET` + `DB_PREFIX=stride_`; (b) `WP_HOME`/`WP_SITEURL` + the `wp core install --url` use the runtime-resolved bridge gateway; (c) `php -S` binds `0.0.0.0`.
- Gateway resolved at runtime from inside the running selenium container (no hardcoded subnet).
- A reachability self-check (curl from inside selenium to `$HOST_GW:8080`) BEFORE codecept, so a networking failure is a one-line diagnostic, not a Cest timeout wall.

```yaml
name: Acceptance

# Smoke subset of the Codeception acceptance suite, run against a throwaway
# CI WordPress + a Selenium service container. Gates a SMALL reliable subset
# first (SmokeTestCest + 4 self-seeding Cests, no seeder); the seeder + the
# remaining 22 Cests are a follow-up (see docs/plans/2026-06-30-codeception-ci-gate.md
# Phase 4). The shared tests/acceptance.suite.yml is NOT edited — CI overrides
# live in tests/_envs/ci.yml, merged via `--env ci`. DDEV-local runs untouched.
on:
  push:
    branches: [main, staging, 'ci-seam-*']
  pull_request:
  workflow_dispatch:

# Least-privilege: reads the repo + uploads a failure artifact only.
permissions:
  contents: read

jobs:
  acceptance:
    runs-on: ubuntu-latest

    env:
      # The standup mirrors integration.yml's disposable-DB affirmation.
      STRIDE_TEST_DB_DISPOSABLE: '1'
      # Throwaway literal — NOT a real secret (mirrors integration.yml's
      # ci-not-secret). Valid ONLY against this per-run throwaway WordPress,
      # which is destroyed with the runner. The login backdoor it arms is hard-
      # gated to non-production WP_ENV (web/app/mu-plugins/test-login-helper.php).
      STRIDE_TEST_LOGIN_SECRET: 'ci-acceptance-not-secret'
      # Web-side test-env signal so test-login-helper.php arms without DDEV.
      CODECEPTION_TEST: 'true'

    services:
      database:
        image: mariadb:10.11
        env:
          MYSQL_DATABASE: wordpress
          MYSQL_USER: wordpress
          MYSQL_PASSWORD: password
          MYSQL_ROOT_PASSWORD: password
        ports:
          - 3306:3306
        options: >-
          --health-cmd="healthcheck.sh --connect --innodb_initialized"
          --health-interval=5s
          --health-timeout=20s
          --health-retries=10
      selenium:
        image: selenium/standalone-chrome:latest
        ports:
          - 4444:4444
        # CRITICAL: chrome crashes intermittently without enlarged /dev/shm.
        options: >-
          --shm-size=2g
          --health-cmd="curl -f http://localhost:4444/wd/hub/status"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=10

    steps:
    - uses: actions/checkout@v4

    - name: Setup PHP
      uses: shivammathur/setup-php@v2
      with:
        php-version: '8.3'
        extensions: mysqli, gd, zip, intl
        coverage: none
        tools: wp-cli

    - name: Cache Composer dependencies
      id: composer-cache
      run: echo "dir=$(composer config cache-files-dir)" >> $GITHUB_OUTPUT

    - uses: actions/cache@v4
      with:
        path: ${{ steps.composer-cache.outputs.dir }}
        key: ${{ runner.os }}-composer-${{ hashFiles('**/composer.lock') }}
        restore-keys: ${{ runner.os }}-composer-

    - name: Install Composer dependencies
      run: composer install --no-progress --prefer-dist --optimize-autoloader

    # Resolve the bridge-gateway IP the SELENIUM container uses to reach the
    # runner host. This single value is used for BOTH WP_HOME (asset origin)
    # and WP_URL (browser-navigated origin) so the host strings match (CORS-
    # module rule) AND the selenium container can reach php -S. No hardcoded
    # subnet — read the default route from inside the running container.
    - name: Resolve runner-host gateway as seen by Selenium
      id: gw
      run: |
        SVC=$(docker ps --filter "ancestor=selenium/standalone-chrome:latest" --format '{{.ID}}' | head -1)
        if [ -z "$SVC" ]; then echo "selenium container not found"; docker ps; exit 1; fi
        HOST_GW=$(docker exec "$SVC" sh -c "ip route | awk '/default/ {print \$3}'")
        if [ -z "$HOST_GW" ]; then echo "could not resolve gateway"; exit 1; fi
        echo "host_gw=$HOST_GW" >> "$GITHUB_OUTPUT"
        echo "Selenium reaches runner host at: $HOST_GW"

    # WP_HOME/WP_URL host = the gateway IP (same string both sides). DB_PREFIX
    # stride_ matches the overlay + the shared suite default. The login secret
    # is written via heredoc (never on a command line / never echoed).
    - name: Write .env
      run: |
        HOST_GW='${{ steps.gw.outputs.host_gw }}'
        cat > .env <<ENV
        DB_NAME=wordpress
        DB_USER=wordpress
        DB_PASSWORD=password
        DB_HOST=127.0.0.1
        DB_PREFIX=stride_
        WP_ENV=development
        WP_HOME=http://${HOST_GW}:8080
        WP_SITEURL=http://${HOST_GW}:8080/wp
        STRIDE_TEST_LOGIN_SECRET=${STRIDE_TEST_LOGIN_SECRET}
        AUTH_KEY=ci-not-secret
        SECURE_AUTH_KEY=ci-not-secret
        LOGGED_IN_KEY=ci-not-secret
        NONCE_KEY=ci-not-secret
        AUTH_SALT=ci-not-secret
        SECURE_AUTH_SALT=ci-not-secret
        LOGGED_IN_SALT=ci-not-secret
        NONCE_SALT=ci-not-secret
        ENV

    # install-wp.sh hardcodes `wp core install --url=http://localhost:8080`.
    # The acceptance run needs the install URL to match WP_HOME (gateway IP) so
    # siteurl/home options emit gateway-host asset URLs. Override the option
    # AFTER the script runs (the script set localhost; correct it here) rather
    # than forking the shared script.
    - name: Install WordPress + Stride stack
      run: bash scripts/ci/install-wp.sh

    - name: Point siteurl/home at the gateway host
      run: |
        HOST_GW='${{ steps.gw.outputs.host_gw }}'
        wp option update home "http://${HOST_GW}:8080"
        wp option update siteurl "http://${HOST_GW}:8080/wp"
        wp rewrite flush --hard

    # Synthesize tests/.env the suite + Acceptance helper read (%WP_URL%,
    # admin creds, admin path, and the SAME login secret so the codecept CLI
    # process resolves it via Acceptance::testLoginSecret()).
    - name: Write tests/.env for Codeception
      run: |
        HOST_GW='${{ steps.gw.outputs.host_gw }}'
        cat > tests/.env <<ENV
        WP_URL=http://${HOST_GW}:8080
        WP_ADMIN_USERNAME=ciadmin
        WP_ADMIN_PASSWORD=ciadmin
        WP_ADMIN_PATH=/wp/wp-admin
        STRIDE_TEST_LOGIN_SECRET=${STRIDE_TEST_LOGIN_SECRET}
        ENV

    # Bind 0.0.0.0 (NOT 127.0.0.1) so the selenium container can reach php -S
    # via the gateway. Health-probe via the gateway host, matching what the
    # browser will use.
    - name: Serve WordPress over HTTP (php -S on 0.0.0.0)
      run: |
        PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8080 -t web scripts/ci/router.php >/tmp/php-server.log 2>&1 &
        for i in $(seq 1 30); do
          if curl -fso /dev/null "http://localhost:8080/index.php?rest_route=/"; then
            echo "server up after ${i}s"; exit 0
          fi
          sleep 1
        done
        echo "PHP built-in server failed to come up"; cat /tmp/php-server.log; exit 1

    # HIGHEST-RISK PREMISE CHECK: prove the selenium container can actually
    # reach php -S at the gateway BEFORE running Cests, so a networking failure
    # is one clear line, not 5 Cest timeouts.
    - name: Verify Selenium can reach php -S
      run: |
        HOST_GW='${{ steps.gw.outputs.host_gw }}'
        SVC=$(docker ps --filter "ancestor=selenium/standalone-chrome:latest" --format '{{.ID}}' | head -1)
        docker exec "$SVC" sh -c "curl -fsS -o /dev/null -w '%{http_code}\n' http://${HOST_GW}:8080/index.php?rest_route=/" \
          || { echo "Selenium CANNOT reach php -S at ${HOST_GW}:8080 — networking premise failed"; cat /tmp/php-server.log; exit 1; }
        echo "Selenium reaches php -S at ${HOST_GW}:8080 — networking OK"

    # SMOKE SUBSET ONLY — explicit Cest list (self-seeding / install-admin; no
    # seeder). Expanding to all 27 + the seeder is Phase 4.
    - name: Run acceptance smoke subset
      run: |
        vendor/bin/codecept run acceptance --env ci \
          SmokeTestCest EnrollmentCest AdminEditionCest AttendanceCest ProfileCest

    - name: Upload Codeception output on failure
      if: failure()
      uses: actions/upload-artifact@v4
      with:
        name: codeception-output
        path: tests/_output/
        retention-days: 7
```

- [ ] **Step 2: Validate the workflow YAML locally (syntax only — no run)**

```bash
# Confirm the file is valid YAML (no execution — pushing to a seam branch is the real run).
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/acceptance.yml')); print('YAML OK')"
```

Expected: `YAML OK`. (We do NOT run acceptance locally — Global Constraint: never against the DDEV DB.)

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/acceptance.yml
git commit -m "ci(acceptance): gate smoke subset of Codeception suite in GitHub Actions"
```

---

### Task 4: Prove the gate runs GREEN and BITES on a seam branch

**Files:** none (CI verification only).

**Interfaces:**
- Consumes: the pushed `acceptance.yml` (Task 3).
- Produces: the green→red→green evidence that makes the gate real (the same standard `browser.yml` met — see its header "proven green→break-assertion→red→revert→green").

**Tier expectation (testing-workflow): Tier A — this IS the RED-first evidence for the harness. Test contract: the `acceptance` job must (a) pass with all 5 smoke Cests green, and (b) FAIL when a single smoke assertion is falsified, then pass again on revert.**

- [ ] **Step 1: Push to a seam branch and watch it go GREEN**

```bash
git checkout -b ci-seam-acceptance-smoke
git push -u origin ci-seam-acceptance-smoke
gh run watch --exit-status
```

Expected: the `Acceptance` workflow completes with the `acceptance` job GREEN. If the "Verify Selenium can reach php -S" step fails, the networking premise (Ground-truth finding 3) is wrong — STOP and diagnose from that step's output before touching Cests (this is the designed failure-localization point).

- [ ] **Step 2: Break one assertion → confirm RED**

Temporarily edit `tests/acceptance/SmokeTestCest.php` `frontPageLoads`: change `$I->dontSee('Fatal error');` to `$I->see('this-string-will-never-be-on-the-page-xyz');`. Commit + push:

```bash
git commit -am "test(ci): TEMP break smoke assertion to prove the gate bites"
git push
gh run watch --exit-status || echo "EXPECTED RED — gate bites"
```

Expected: the `acceptance` job goes RED on the falsified assertion. This proves the gate would catch a real regression.

- [ ] **Step 3: Revert the break → confirm GREEN again**

```bash
git revert --no-edit HEAD
git push
gh run watch --exit-status
```

Expected: GREEN again. The gate is now proven (green → bite → green).

- [ ] **Step 4: Confirm no secret leaked into the run log**

```bash
gh run view --log 2>/dev/null | grep -c "ci-acceptance-not-secret" || true
```

Expected: `0` (the heredoc-written secret is never echoed). If non-zero, the 1a mitigation "no log echo" is violated — find the offending step and silence it before merge.

- [ ] **Step 5: Clean up the seam branch (after merge of the real change)**

The `ci-seam-*` branch is throwaway proof; the real deliverable (`acceptance.yml` + `ci.yml` + composer alias) merges via the normal branch. Delete the seam branch once green is demonstrated:

```bash
git push origin --delete ci-seam-acceptance-smoke
```

---

## Integration gate (per phase)

- **After Gate A (Tasks 1–2):** `ddev exec composer run-script --list | grep test:acceptance:ci` shows the alias; `codecept run acceptance --env ci -g __nonexistent_group__` parses the overlay (0 tests, no YAML error). No DB touched.
- **After Gate B (Tasks 3–4):** the `acceptance` job is GREEN on `ci-seam-acceptance-smoke`, the "Verify Selenium can reach php -S" step passed, the bite-proof (Step 2/3) demonstrated red-then-green, and Step 4 shows `0` secret leaks.

---

## Phase 4 — Follow-up (OUT OF SCOPE of this plan; named so it isn't lost)

Once the smoke gate is reliably green, a follow-up plan expands coverage:
1. Add a `wp eval-file scripts/seed.php` step after install (the seeder is WP_ENV-gated to development/local — `install-wp.sh` sets `WP_ENV=development`, so it runs).
2. Add the seed-dependent Cests: `DashboardE2ECest`, `TrajectoryCascadeCest`, `DashboardTabShakeoutCest`, `DashboardCest`, `OnlineEnrollmentCest`, `AssistantPluginCest`, `CatalogShakeoutCest`, `CourseSidebarStatusCest`.
3. Iterate the remaining Cests to the full 27, quarantining flaky ones rather than letting them red the gate.
This phasing de-risks the container-networking + CORS unknowns (this plan) before committing 27 Cests of flake surface.

---

## Convergence contract

The `## Threat model` (gate 1a) and `## Acceptance flows` (gate 1g) sections above are the convergence target for `/code-review` and `security-sentinel` at Review Gate B. Reviewers verify the diff against the named mitigations (throwaway secret, prod-gated backdoor, no log echo) and the named smoke-Cest matrix — not free-form. A gap is a one-line finding keyed to a named item.

## Self-review (planner)

- **Spec coverage:** env overlay (Task 1) ✓; smoke-subset-first (Task 3 explicit Cest list) ✓; `--env ci` invocation (Task 2/3) ✓; Selenium service `--shm-size=2g` + healthcheck (Task 3) ✓; prefix reconciliation (overlay + `.env` DB_PREFIX=stride_) ✓; CORS host-string identity (gateway IP both sides) ✓; Selenium→php-S reachability (runtime gateway + 0.0.0.0 bind + self-check) ✓; tests/.env synthesis (Task 3) ✓; login-secret threat model (gate 1a) ✓; bite-proof (Task 4) ✓; DB-safety (never DDEV DB) ✓.
- **Placeholder scan:** no TBD/TODO; every step has concrete commands/content.
- **Type/name consistency:** `HOST_GW` / `steps.gw.outputs.host_gw`, `STRIDE_TEST_LOGIN_SECRET`, `stride_` prefix, Cest names match across overlay, workflow, and acceptance matrix.
