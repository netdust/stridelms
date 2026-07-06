# Browser-CI Gate + Notification Regression Tests — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pin this session's user-facing notification changes with automated tests, and wire the Playwright browser suite into CI so rendered-page regressions fail the build.

**Architecture:** Two coupled tracks. Track A adds RED→GREEN tests at the right tier for three shipped behaviors (certificate notification URL, per-type icon/colour, auto-mark-read-on-view) — reusing the existing unit/integration/acceptance harnesses, not inventing new ones. Track B adds a `browser.yml` GitHub Actions workflow that reuses the exact WP-standup machinery `integration.yml` already proved (`scripts/ci/install-wp.sh` + `php -S` + committed Vite `dist/`), parameterises Playwright's hardcoded `baseURL` via an env var, and runs the DB-safe `setContent`-harness specs as the minimum viable gate.

**Tech Stack:** PHPUnit (Unit + Integration), Playwright (Chromium), GitHub Actions, Bedrock WordPress, PHP 8.3, MariaDB 10.11.

---

## Class & gate decisions (planner judgment — explicit)

**Class A** — new multi-task work (test additions + a new CI workflow), Stage 0→1→2→3. Stage 0 brainstorm skipped: the work is fully specified, no open design questions — only a Playwright-first-vs-Codeception decision, resolved below.

**Gates fired / not fired (trigger reasons):**

- **1a threat-modeling — DOES NOT FIRE.** Ran the Stride trigger list literally (CLAUDE.md "Threat-modeling triggers"). The work adds (a) test code and (b) CI config. No new runtime AJAX/REST/shortcode/settings surface, no new user-controlled URL, no untrusted-parsing path, no capability boundary, no DB-with-user-input path. The new workflow's secrets surface is **identical in kind** to `integration.yml`'s already-merged pattern (throwaway DB creds + `ci-not-secret` salts written into a scratch `.env`); it introduces **no new secret or credential** — no token, no external API key, no deploy credential. Therefore no new attack surface → gate does not fire. (Had the workflow added a real secret — e.g. a registry token or a deploy key — this call would flip.)
- **1b architecture-invariants — DOES NOT FIRE (confirmed against `ARCHITECTURE-INVARIANTS.md`, INV-1…INV-9 + INV-6b).** The tests PIN existing behavior; they add no convergence point and bypass none. The certificate-link fix is tangential to INV-6 (LearnDash) but the fix deliberately *avoids* the LD endpoint — it links to the `/mijn-account/?tab=certificaten` dashboard tab — so no `LMSAdapterInterface`/`LearnDashHelper` convergence is touched. The auto-mark-read test asserts through `NotificationService::markAllRead`/`getUnreadCount` (the existing service seam), not a new entry point. No INV is modified.
- **1g feature-acceptance — FIRES (the tests ARE the acceptance coverage).** Acceptance-flow matrix embedded below; each new test is one row, edge column mandatory.
- **wp-plan-requirements (WP stack gate) — NO-OP, stated explicitly.** This skill front-loads the four security pillars + nine drift categories for features that add data-flows or framework classes. This plan adds **neither** a user-facing data flow nor a new framework class — only PHPUnit/Playwright test files and a YAML workflow. There is no golden-path archetype (no CPT, no form, no settings page, no AJAX/REST). Per the skill's own "What this skill is NOT — not for trivial/no-PHP-logic changes," it no-ops here. Recorded so the reviewer does not expect security/drift blocks that have no subject.
- **1c ground-truth — DONE (see "Ground-truth findings" below).** Every load-bearing premise was read against real source before this plan shipped.

---

## Ground-truth findings (read against real source — do not re-trust the brief blindly; these are verified)

1. **CI standup is reusable as-is.** `integration.yml` already: spins MariaDB 10.11 as a `services.database`, writes a scratch `.env` (DB creds + `ci-not-secret` salts, `WP_HOME=http://localhost:8080`), runs `scripts/ci/install-wp.sh`, then serves WP via `PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t web scripts/ci/router.php`. Track B's `browser.yml` clones this prelude verbatim, then runs Playwright against `http://127.0.0.1:8080` instead of `composer test:integration`.
2. **`install-wp.sh` serves the FULL frontend theme, not just REST/admin.** It runs `wp theme activate stridence`, `wp option update permalink_structure '/%postname%/'`, and `wp rewrite flush --hard` after theme activation specifically so the front-end REST worker renders catalog cards. The homepage at `/` is served with theme assets enqueued. **Highest-risk premise — CONFIRMED feasible.**
3. **No `npm run build` needed in CI.** The theme's Vite output is **committed**: `web/app/themes/stridence/dist/main.BM1T_qK5.js`, `dist/main.DIeeVLmq.css`, and crucially `dist/.vite/manifest.json` are all `git ls-files`-tracked. `AssetHooks::isDev()` returns dev-mode **only when the manifest is absent**; since it is committed, CI enqueues the built `dist/main.*.js` (which carries Alpine + the dashboard factories) on every page including `/`. So `page.goto('/')` in the harness specs gets a real `window.Alpine`. (Risk note T-B1 below covers the one way this breaks.)
4. **Playwright `baseURL` is hardcoded** to `'https://stride.ddev.site'` at `playwright.config.ts:16`. It MUST become env-parameterised for CI to hit `http://127.0.0.1:8080`. `ignoreHTTPSErrors: true` is already set, so an http target is fine.
5. **The DB-safe harness specs still need a served origin.** `tests/frontend/stridence/{dashboard-tabs,inline-edit,alpine-components,enrollment-form}.spec.ts` use `page.goto('/')` THEN `document.body.innerHTML = …` + `window.Alpine.initTree(...)`. They are DB-independent (they inject their own markup) but they are NOT origin-independent — they need `baseURL` to serve a page that loads Alpine. The CI-served WP at `127.0.0.1:8080` satisfies this. These four are the minimum-viable gate.
6. **`home_url()` is stubbed for unit tests** at `tests/Stubs/wordpress-stubs.php:1650`: returns `rtrim($_test_options['home'] ?? 'https://example.com', '/') . '/' . ltrim($path, '/')`. So in a unit test `mapCertificateIssued` resolves to `https://example.com/mijn-account/?tab=certificaten` unless `$_test_options['home']` is set. T1 asserts on a path substring to stay host-agnostic.
7. **`NotificationMapperTest` already constructs the mapper** with real `EditionRepository`/`SessionRepository` (line 19) and already has a `testMapsCertificateIssuedToNotification` (line 91) that asserts only `type==='certificate'` + title substring — **NOT the URL**. T1 extends this exact test class.
8. **The integration test already covers `markAllRead` → cache-invalidation → `getUnreadCount()==0`** (`NotificationCacheIntegrationTest::markAllReadInvalidatesCachedCount`, line 142). The genuinely-untested NEW behavior is the **tab template's snapshot-before-mark ORDERING**: `tab-meldingen.php` snapshots `getNotifications()` (preserving this-render read flags) and only THEN calls `markAllRead()`. T3 pins that the snapshot read state is independent of the subsequent mark — see T3 for why this lives at the service level, not the glue template.
9. **STALE ACCEPTANCE TEST — pre-existing breakage this session introduced.** `tests/acceptance/DashboardE2ECest.php::meldingenRendersListOrEmptyState` (line 240) classifies the populated state by `body.innerText.includes('Alles als gelezen markeren')`. **That button was removed this session** (manual mark-all-read control deleted). The Cest would now classify a real populated list as `'broken'` and fail. T4 fixes this assertion to key off a stable rendered-list signal. (This is acceptance-suite-only and runs against the disposable CI DB / Selenium — NOT against the dev DB; see Global Constraints.)
10. **Codeception is a heavy CI lift — defer.** `tests/acceptance.suite.yml` needs a **Selenium** service (`host: selenium, port: 4444`) AND a pre-loaded WPDb dump (`dump: tests/_data/dump.sql, populate: false`) with `tablePrefix: stride_` and seed data. **`tests/_data/dump.sql` is absent** and `install-wp.sh` produces a `wp_`-prefix install with no seed corpus. Wiring Codeception into CI means: add a Selenium container, generate+commit (or build) a seeded `stride_`-prefix dump, and reconcile the prefix. That is its own plan. **Decision: defer Codeception; gate Playwright only.** Recorded as OUT-OF-SCOPE.

---

## Global Constraints

- **DB SAFETY (load-bearing).** The dev/demo DB hosts a demo persona for a demo **tomorrow**. Integration/acceptance/Codeception suites issue table-wide `DELETE` on `vad_registrations` and refuse to run without `STRIDE_TEST_DB_DISPOSABLE=1` (`tests/Integration/bootstrap.php` guard). **No task in this plan runs integration, acceptance, or Codeception against the dev DB.** T1 + T2 are **unit** (stubbed WP, zero DB). T3's verification runs in **CI only** (disposable DB) OR via the local-CI-simulation scratch-DB recipe in `install-wp.sh`'s header (`stride_ci` scratch database) — never the live DDEV DB. T4's Cest is edited but executed only in a future Codeception-CI lift / against a disposable DB, never dev. The Playwright `setContent` harness specs are DB-safe (they inject their own markup).
- **No `npm run build` in CI** — the committed `dist/` + `dist/.vite/manifest.json` are the artifact (finding #3). Do not add a build step; if a build is ever needed, that is a separate decision.
- **Playwright Chromium only in the first CI gate** — the Pixel-5 `mobile` project and the `testIgnore`d specs (trajectory-enrollment, field-groups-enrollment, lti/**, uikit) stay out of the initial gate (they need seed data or are post-launch). Scope the CI run to `--project=chromium` and the harness specs.
- **All new UI assertion copy is Dutch (nl_BE)** — match existing strings exactly (`Meldingen`, `Geen meldingen`, etc.).
- **Match the framework, not the nearest sibling** (project lesson `lesson_match_framework_not_siblings`). T1 extends `NotificationMapperTest`'s existing shape; T3 extends `NotificationCacheIntegrationTest`'s `freshService()` pattern; T4 edits the existing Cest in place.

---

## Acceptance flows (1g — feature-acceptance matrix)

Each new/changed test is one intended-use flow. Edge column is mandatory (six edge classes: empty / error / boundary / unauthorized / concurrent / malformed — or why excluded).

| # | Flow (intended use) | Asserted by | Faithful layer | Edges covered (or excluded-why) |
|---|---|---|---|---|
| AF-1 | A certificate-issued notification links the user to the **Certificaten dashboard tab**, never the LD cert-PDF endpoint that renders "Access to certificate page is disallowed" | T1 (unit) | PHPUnit unit (stubbed `home_url`) | **boundary:** asserts the path `/mijn-account/?tab=certificaten` is present AND the legacy `certificate_link` value (`https://example.com/cert/123`, present in context) is ABSENT — the denial-direction assert. **malformed:** missing `course_id` still yields the tab URL (URL is course-independent). *empty/unauthorized/concurrent excluded:* pure mapper function, no auth, no state, no I/O. |
| AF-2 | Each notification type renders its correct icon + colour tile so the feed is scannable | T2 (unit, renders the PHP partial) | PHPUnit unit rendering `notification-item.php` | **boundary:** all 5 mapped types (certificate→award/`bg-success/10`, completion→check-circle/`bg-success/10`, enrollment→check-square/`bg-accent-subtle`, attendance→map-pin/`bg-info/10`, session→calendar/`bg-primary-subtle`). **malformed:** unknown type → fallback `bell`/`bg-accent-subtle`. **empty:** empty `$notification` array → partial returns nothing (no fatal). *unauthorized/concurrent excluded:* pure presentation partial. |
| AF-3 | Opening Meldingen shows this-load unread accents, then clears the badge for next load (auto-mark-read) | T3 (integration) | PHPUnit integration (real WP+DB, disposable) | **boundary:** snapshot read-state taken BEFORE mark is unaffected by the mark; after `markAllRead`, `getUnreadCount()==0`. **empty:** zero notifications → `markAllRead` is a no-op, count stays 0, no error. **concurrent:** a new subject-event after the snapshot re-primes a non-zero count next load (already covered by sibling `unreadCountPrimesTransientAndNewEventInvalidatesIt` — referenced, not duplicated). *unauthorized excluded:* service method, caller (tab template) is logged-in-gated upstream. |
| AF-4 | The Meldingen acceptance walk recognises a populated list by a **stable** signal (not the removed button) | T4 (acceptance Cest edit) | Codeception WPWebDriver (disposable DB only) | **empty:** still recognises `Geen meldingen`. **boundary:** populated state recognised by the rendered notification row anchor, not the deleted `Alles als gelezen markeren` control. *Executed in a future Codeception-CI lift; edit lands now to stop the stale assertion shipping.* |

---

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `tests/Unit/NotificationMapperTest.php` | Unit shape — extend with the cert-URL assertion | Modify |
| `tests/Unit/NotificationItemRenderTest.php` | NEW — render `notification-item.php` and assert type→icon/colour | Create |
| `tests/Integration/NotificationCacheIntegrationTest.php` | Integration shape — add the snapshot-before-mark ordering test | Modify |
| `tests/acceptance/DashboardE2ECest.php` | Fix the stale `meldingenRendersListOrEmptyState` signal | Modify |
| `playwright.config.ts` | Parameterise `baseURL` via `PLAYWRIGHT_BASE_URL` env | Modify |
| `.github/workflows/browser.yml` | NEW — Playwright CI gate reusing the integration WP-standup | Create |

---

## ── REVIEW GATE 1 ── (tier: STANDARD — notification regression tests; test code only, no 1a surface, no INV touched, no migration)
Cluster = T1 + T2 + T3 (3 tasks). STANDARD per the 1h table: multi-file behavior-pinning in test code outside any 1a surface or the data layer. Reviewer fan-out: 2 finders + simplicity. No security-sentinel. Hold this cluster as one unit; do NOT bundle with the CI cluster.

---

### Task 1: Pin the certificate-notification URL (Tier A)

**Test contract (RED-first):** the new assertion must fail against a mapper that returns the raw LD `certificate_link`, and pass against the shipped `home_url('/mijn-account/?tab=certificaten')` — asserting BOTH that the tab path is present AND the legacy cert-PDF link is absent (the denial direction).

**Files:**
- Modify: `tests/Unit/NotificationMapperTest.php` (extend `testMapsCertificateIssuedToNotification`, line 91-111, and add one boundary test)

**Interfaces:**
- Consumes: `NotificationMapper::fromAuditEntry(object $entry): array` returning `array{id,type,title,body,url,timestamp}`; `home_url()` stub at `tests/Stubs/wordpress-stubs.php:1650`.
- Produces: nothing downstream (leaf test).

- [ ] **Step 1: Add the URL asserts to the existing certificate test.** In `testMapsCertificateIssuedToNotification` (already builds an entry whose context carries `'certificate_link' => 'https://example.com/cert/123'`), append after the existing asserts:

```php
        // The certificate notification must link to the Certificaten dashboard
        // tab — NOT the raw LearnDash cert-PDF endpoint (the stored
        // certificate_link), which is nonce/permission-gated and renders
        // "Access to certificate page is disallowed".
        $this->assertStringContainsString(
            '/mijn-account/?tab=certificaten',
            $notification['url'],
            'certificate notification must link to the Certificaten dashboard tab',
        );
        $this->assertStringNotContainsString(
            'example.com/cert/123',
            $notification['url'],
            'denial path: must never link to the raw LearnDash certificate-PDF endpoint',
        );
```

- [ ] **Step 2: Add a boundary test — URL is course-independent.** Append a new test method:

```php
    /** @test */
    public function testCertificateUrlIsTheTabEvenWithoutCourseId(): void
    {
        $entry = (object) [
            'id' => 9,
            'entity_type' => 'completion',
            'entity_id' => 1,
            'action' => 'completion.certificate_issued',
            'actor_id' => null,
            'context' => json_encode([]), // no course_id, no certificate_link
            'created_at' => '2026-03-10 14:30:00',
        ];

        $notification = $this->mapper->fromAuditEntry($entry);

        $this->assertStringContainsString('/mijn-account/?tab=certificaten', $notification['url']);
    }
```

- [ ] **Step 3: Verify RED.** Temporarily change `NotificationMapper::mapCertificateIssued` to return the old `$context['certificate_link'] ?? ''` instead of the `home_url(...)` line, then run:

```
ddev exec vendor/bin/phpunit --filter NotificationMapper --testsuite Unit
```
Expected: the two new assertions FAIL (cert-link present / tab path absent).

- [ ] **Step 4: Revert the mapper to its shipped `home_url('/mijn-account/?tab=certificaten')` line and verify GREEN.**

```
ddev exec vendor/bin/phpunit --filter NotificationMapper --testsuite Unit
```
Expected: PASS (all NotificationMapper tests green).

- [ ] **Step 5: Commit.**

```bash
git add tests/Unit/NotificationMapperTest.php
git commit -m "test(notification): pin certificate notification links to certificaten tab not LD cert-PDF"
```

---

### Task 2: Pin per-type notification icon/colour rendering (Tier A)

**Test contract (RED-first):** rendering `notification-item.php` with a given `type` must emit that type's icon name and tile bg class; a wrong/unknown type must fall back to `bell`/`bg-accent-subtle`. Test fails if the type→style map is broken (e.g. a `bg-success-subtle` typo that renders blank).

**Decision (unit vs browser for T2): UNIT, rendering the PHP partial.** Rationale: `notification-item.php` is pure presentation given a `$notification` array — no JS, no Alpine, no DB. A unit test that `include`s the partial with captured output is faster, runs in the existing `--testsuite Unit` (already in CI), needs no served origin, and asserts the icon/colour map directly. A Playwright DOM assertion would require a seeded notification through the full stack (DB-dependent, dev-DB-unsafe) to reach the same partial — strictly more cost for the same coverage. The partial calls three WP/theme functions (`esc_url`, `esc_attr`, `esc_html`, `human_time_diff`, `stridence_icon`) — `esc_*`/`human_time_diff` are in `wordpress-stubs.php`; `stridence_icon` must be stubbed in the test (see Step 1).

**Files:**
- Create: `tests/Unit/NotificationItemRenderTest.php`
- Reads (not modified): `web/app/themes/stridence/templates/dashboard/partials/notification-item.php`

**Interfaces:**
- Consumes: the partial's `$args['notification']` contract (`id,type,title,body,url,timestamp,read`); the `$typeStyles` map at `notification-item.php:45-52`.
- Produces: nothing downstream.

- [ ] **Step 1: Write the render harness + the mapped-types test.** The partial uses `stridence_icon($name, $class)` — stub it to echo the icon name so the test can assert which icon was chosen. Render by output-buffering an `include`.

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Tests\TestCase;

class NotificationItemRenderTest extends TestCase
{
    private const PARTIAL = __DIR__
        . '/../../web/app/themes/stridence/templates/dashboard/partials/notification-item.php';

    protected function setUp(): void
    {
        parent::setUp();
        // The theme helper that emits the SVG — stub it to echo the icon name
        // so we can assert which icon the type-map selected. Also define the
        // ABSPATH guard the partial checks (defined('ABSPATH') || exit).
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/');
        }
        if (!function_exists('stridence_icon')) {
            function stridence_icon(string $name, string $class = ''): string
            {
                return "<svg data-icon=\"{$name}\" class=\"{$class}\"></svg>";
            }
        }
    }

    private function render(array $notification): string
    {
        ob_start();
        $args = ['notification' => $notification];
        include self::PARTIAL;
        return ob_get_clean();
    }

    /**
     * @test
     * @dataProvider mappedTypes
     */
    public function testTypeRendersItsIconAndTileColour(string $type, string $icon, string $bg): void
    {
        $html = $this->render([
            'id' => 'n1', 'type' => $type, 'title' => 'X',
            'body' => '', 'url' => '/x', 'timestamp' => time(), 'read' => false,
        ]);

        $this->assertStringContainsString("data-icon=\"{$icon}\"", $html, "type {$type} icon");
        $this->assertStringContainsString($bg, $html, "type {$type} tile bg");
    }

    public static function mappedTypes(): array
    {
        return [
            'certificate' => ['certificate', 'award',        'bg-success/10'],
            'completion'  => ['completion',  'check-circle', 'bg-success/10'],
            'enrollment'  => ['enrollment',  'check-square', 'bg-accent-subtle'],
            'attendance'  => ['attendance',  'map-pin',      'bg-info/10'],
            'session'     => ['session',     'calendar',     'bg-primary-subtle'],
        ];
    }

    /** @test */
    public function testUnknownTypeFallsBackToBell(): void
    {
        $html = $this->render([
            'id' => 'n2', 'type' => 'totally-unknown', 'title' => 'X',
            'body' => '', 'url' => '/x', 'timestamp' => time(), 'read' => false,
        ]);

        $this->assertStringContainsString('data-icon="bell"', $html);
        $this->assertStringContainsString('bg-accent-subtle', $html);
    }

    /** @test */
    public function testEmptyNotificationRendersNothing(): void
    {
        $this->assertSame('', trim($this->render([])));
    }
}
```

- [ ] **Step 2: Verify RED against a deliberately-broken variant.** Temporarily edit the partial's `$typeStyles['attendance']['bg']` from `bg-info/10` to a typo `bg-info-subtle`, run:

```
ddev exec vendor/bin/phpunit --filter NotificationItemRender --testsuite Unit
```
Expected: the `attendance` data-row FAILS (asserts `bg-info/10`, gets the typo). This proves the test bites a real colour-map regression.

- [ ] **Step 3: Revert the partial and verify GREEN.**

```
ddev exec vendor/bin/phpunit --filter NotificationItemRender --testsuite Unit
```
Expected: PASS (all rows + fallback + empty).

- [ ] **Step 4: Confirm no Pint/PHPStan regression on the new file.**

```
ddev exec composer lint && ddev exec composer lint:stan
```
Expected: clean (note: the inline `function stridence_icon` is namespaced inside the test namespace — acceptable in a test stub; if PHPStan flags it, move the stub to `tests/Stubs/stride-infrastructure-stubs.php` guarded by `function_exists`).

- [ ] **Step 5: Commit.**

```bash
git add tests/Unit/NotificationItemRenderTest.php
git commit -m "test(notification): pin per-type icon and tile colour in notification-item partial"
```

---

### Task 3: Pin auto-mark-read snapshot-before-mark ordering (Tier A)

**Test contract (RED-first):** after `getNotifications()` returns a snapshot (with this-load read flags) and `markAllRead()` is then called, `getUnreadCount()` is 0 and the snapshot taken before the mark still carries its original unread flags — i.e. marking does not retroactively mutate the already-returned render data, and the badge clears next load. Empty-notification case is a safe no-op.

**Why service-level, not the glue template:** `tab-meldingen.php` is presentational glue (Tier B by itself: it snapshots then calls the service). The *behavior* — snapshot read-state is independent of the subsequent mark, and the mark clears the count — lives in `NotificationService`. Pinning it at the service is the durable seam; the template merely calls these two methods in order. The existing `markAllReadInvalidatesCachedCount` covers the cache-clear half; this task adds the **ordering/independence** half and the **empty** edge.

**Files:**
- Modify: `tests/Integration/NotificationCacheIntegrationTest.php` (add two `/** @test */` methods, reuse the existing `freshService()`/`recordAudit()`/`transientKey()` helpers)

**Interfaces:**
- Consumes: `NotificationService::getNotifications(int): array` (each row has a `read` bool), `::markAllRead(int): void`, `::getUnreadCount(int): int`; the test's existing `freshService()` (line 170), `recordAudit()` (line 184), `self::$testUserId`.
- Produces: nothing downstream.

- [ ] **Step 1: Add the snapshot-independence test.** Append inside the class:

```php
    /**
     * @test
     * The Meldingen tab snapshots getNotifications() (with this-load read
     * flags) BEFORE calling markAllRead(), so the current render keeps its
     * unread accents while the badge clears for next load. Pin that ordering:
     * the snapshot is unaffected by the subsequent mark, and the count goes 0.
     */
    public function snapshotBeforeMarkKeepsRenderFlagsAndClearsBadge(): void
    {
        $this->recordAudit('registration', 'registration.created', [
            'user_id' => self::$testUserId,
            'edition_id' => 0,
        ]);

        $svc = $this->freshService();

        // 1. Snapshot — as the tab template does before marking.
        $snapshot = $svc->getNotifications(self::$testUserId);
        $this->assertNotEmpty($snapshot, 'fixture must produce at least one notification');
        $unreadInSnapshot = array_filter($snapshot, fn (array $n): bool => !$n['read']);
        $this->assertNotEmpty($unreadInSnapshot, 'arrival render must still show unread items');

        // 2. Mark — the auto-mark-read on tab view.
        $svc->markAllRead(self::$testUserId);

        // 3. The already-returned snapshot is unchanged (no retroactive mutation).
        foreach ($snapshot as $i => $n) {
            $this->assertSame(
                $unreadInSnapshot[$i]['read'] ?? $n['read'],
                $n['read'],
                'markAllRead must not retroactively mutate the returned snapshot',
            );
        }

        // 4. Badge clears for next load.
        $this->assertSame(0, $this->freshService()->getUnreadCount(self::$testUserId));
    }

    /**
     * @test
     * Empty edge: no notifications → markAllRead is a safe no-op, count stays 0.
     */
    public function markAllReadOnEmptyFeedIsANoOp(): void
    {
        $svc = $this->freshService();
        $this->assertSame(0, $svc->getUnreadCount(self::$testUserId));

        $svc->markAllRead(self::$testUserId); // must not error on an empty feed

        $this->assertSame(0, $this->freshService()->getUnreadCount(self::$testUserId));
    }
```

- [ ] **Step 2: Verify RED for the ordering test.** Temporarily reorder `tab-meldingen.php` is NOT how this is proven (the template isn't under test). Instead prove the snapshot-independence assertion bites by temporarily making `markAllRead` mutate in place — in `NotificationService::markAllRead`, temporarily add `$notifications[0]['read'] = true;` AND return that array by reference is not possible; simpler: assert the test currently fails if `getNotifications` returned a live reference. To get a clean RED without unsafe edits, run the test against a temporary `markAllRead` that ALSO clears `$this->cache` AND re-reads — skip if not reproducible. **Minimum RED proof:** comment out the `update_user_meta(... )` + `invalidateCountCache()` lines in `markAllRead`; run the suite — Step-1 assertion #4 (count==0) FAILS.

```
# In CI-disposable DB only — never dev DB.
ddev exec bash -c 'export DB_NAME=stride_ci DB_PREFIX=wp_; vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NotificationCacheIntegration'
```
Expected: `snapshotBeforeMark…` FAILS at the count==0 assert with `markAllRead`'s persistence commented out.

- [ ] **Step 3: Restore `markAllRead` and verify GREEN.**

```
ddev exec bash -c 'export DB_NAME=stride_ci DB_PREFIX=wp_; vendor/bin/phpunit -c phpunit-integration.xml.dist --filter NotificationCacheIntegration'
```
Expected: PASS (all NotificationCacheIntegration tests green). NOTE: if a local `stride_ci` scratch DB is not provisioned, defer this run to CI (T6) and check the box on the CI green — do NOT run against the dev DB (Global Constraints).

- [ ] **Step 4: Commit.**

```bash
git add tests/Integration/NotificationCacheIntegrationTest.php
git commit -m "test(notification): pin snapshot-before-mark ordering and empty-feed no-op for auto-mark-read"
```

---

### Task 4: Fix the stale Meldingen acceptance signal (Tier B)

`no unit test: Tier B, edit to an existing acceptance Cest assertion (test-infra correction, not new product behavior) — its own coverage is the Cest run itself.`

The current `meldingenRendersListOrEmptyState` keys the populated state off `'Alles als gelezen markeren'`, a button removed this session. Re-key to a stable rendered-list signal.

**Files:**
- Modify: `tests/acceptance/DashboardE2ECest.php:229-245`

- [ ] **Step 1: Replace the state-detection JS.** The populated list renders `<a>` notification rows from `notification-item.php` (anchor with a per-type icon tile) under `Vandaag`/`Eerder` sections; the empty state renders `Geen meldingen`. Re-key off those:

```php
        $state = (string) $I->executeJS(
            "const t = document.body.innerText;" .
            "if (t.includes('Geen meldingen')) return 'empty';" .
            "if (t.includes('Vandaag') || t.includes('Eerder')) return 'list';" .
            "return 'broken';"
        );
        \PHPUnit\Framework\Assert::assertContains($state, ['list', 'empty'], 'meldingen must render its list or its empty state');
```

- [ ] **Step 2: Verify the assertion no longer references the removed control.** Grep:

```
grep -n "Alles als gelezen markeren" tests/acceptance/DashboardE2ECest.php
```
Expected: NO matches.

- [ ] **Step 3: Commit.** (Cest execution deferred to a Codeception-CI lift / disposable DB — never dev DB. Landing the edit now stops the stale assertion from shipping.)

```bash
git add tests/acceptance/DashboardE2ECest.php
git commit -m "test(acceptance): re-key meldingen populated-state signal off the removed mark-all-read button"
```

---

## ── REVIEW GATE 2 ── (tier: STANDARD — Playwright CI gate; CI config + one-line config param, no 1a surface, no new secret beyond integration.yml's pattern)
Cluster = T5 + T6 (2 tasks). STANDARD per 1h: multi-file change (config + workflow) outside any 1a surface; the workflow adds no new credential (finding 1a). Reviewer fan-out: 2 finders + simplicity + a feature-acceptance check that the workflow actually runs the harness specs green. No security-sentinel. **If review finds the workflow introduces any real secret/token, this gate promotes to FULL (one-way escalation).**

---

### Task 5: Parameterise Playwright baseURL (Tier B)

`no unit test: Tier B, config change — verified by the workflow run in T6 (the gate's own green is the proof).`

**Files:**
- Modify: `playwright.config.ts:16`

- [ ] **Step 1: Make `baseURL` env-overridable, defaulting to DDEV for local devs.**

```ts
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'https://stride.ddev.site',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'on-first-retry',
    // Ignore HTTPS errors for local dev
    ignoreHTTPSErrors: true,
  },
```

- [ ] **Step 2: Verify local default is unchanged (no env set → DDEV URL).**

```
cd web/app/themes/stridence 2>/dev/null; cd /home/ntdst/Sites/stride; node -e "process.env.CI=''; const c=require('./playwright.config.ts'); " 2>/dev/null || echo "ts config not node-loadable directly — verified by inspection instead"
```
Expected: the line reads `process.env.PLAYWRIGHT_BASE_URL ?? 'https://stride.ddev.site'` (inspection is sufficient; the TS config isn't plain-node-requireable).

- [ ] **Step 3: Commit.**

```bash
git add playwright.config.ts
git commit -m "test(playwright): parameterise baseURL via PLAYWRIGHT_BASE_URL for CI"
```

---

### Task 6: Add the Playwright CI gate (Class A — CI)

`no unit test: Tier B (CI config) — verification is the workflow running GREEN on a ci-seam-* push.`

**Test contract:** the workflow stands up WP via the integration prelude, then runs the four DB-safe `setContent`-harness specs (`--project=chromium`) against the CI-served origin and they pass. RED→GREEN proof: a deliberate `expect(...).toHaveText('WRONG')` edit makes the gate red; reverting makes it green.

**Files:**
- Create: `.github/workflows/browser.yml`

**Interfaces:**
- Consumes: `scripts/ci/install-wp.sh`, `scripts/ci/router.php` (the integration WP-standup, finding #1); `PLAYWRIGHT_BASE_URL` env (T5); committed `dist/` + manifest (finding #3).
- Produces: a CI gate other PRs inherit.

- [ ] **Step 1: Write the workflow.** This clones `integration.yml`'s standup verbatim (MariaDB service, PHP 8.3 + wp-cli, scratch `.env`, `install-wp.sh`, `php -S` server) — then swaps the test step for Node + Playwright. Scope to the four DB-safe harness specs on Chromium.

```yaml
name: Browser

# Mirrors integration.yml's WP-standup, then runs the DB-safe Playwright
# setContent-harness specs against the CI-served WordPress. These specs inject
# their own markup (no seed DB needed) but need a real origin that loads the
# committed dist/main.*.js (Alpine + dashboard factories). No npm run build —
# web/app/themes/stridence/dist/ + dist/.vite/manifest.json are committed.
on:
  push:
    branches: [main, staging, 'ci-seam-*']
  pull_request:
  workflow_dispatch:

jobs:
  browser:
    runs-on: ubuntu-latest

    env:
      STRIDE_TEST_DB_DISPOSABLE: '1'
      PLAYWRIGHT_BASE_URL: 'http://127.0.0.1:8080'

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

    - name: Write .env
      run: |
        cat > .env <<'ENV'
        DB_NAME=wordpress
        DB_USER=wordpress
        DB_PASSWORD=password
        DB_HOST=127.0.0.1
        DB_PREFIX=wp_
        WP_ENV=development
        WP_HOME=http://localhost:8080
        WP_SITEURL=${WP_HOME}/wp
        AUTH_KEY=ci-not-secret
        SECURE_AUTH_KEY=ci-not-secret
        LOGGED_IN_KEY=ci-not-secret
        NONCE_KEY=ci-not-secret
        AUTH_SALT=ci-not-secret
        SECURE_AUTH_SALT=ci-not-secret
        LOGGED_IN_SALT=ci-not-secret
        NONCE_SALT=ci-not-secret
        ENV

    - name: Install WordPress + Stride stack
      run: bash scripts/ci/install-wp.sh

    - name: Serve WordPress over HTTP (php -S)
      run: |
        PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8080 -t web scripts/ci/router.php >/tmp/php-server.log 2>&1 &
        for i in $(seq 1 30); do
          if curl -fso /dev/null "http://localhost:8080/index.php?rest_route=/"; then
            echo "server up after ${i}s"; exit 0
          fi
          sleep 1
        done
        echo "PHP built-in server failed to come up"; cat /tmp/php-server.log; exit 1

    - name: Setup Node
      uses: actions/setup-node@v4
      with:
        node-version: '20'

    - name: Install Playwright + browser
      run: |
        npm ci
        npx playwright install --with-deps chromium

    # DB-safe setContent-harness specs ONLY (they inject their own markup and
    # need just a served origin that loads Alpine). Seed-dependent specs
    # (enrollment/*, trajectory, field-groups) are a follow-on once the seeder
    # runs in this job — tracked OUT-OF-SCOPE in the plan.
    - name: Run Playwright (DB-safe harness specs, chromium)
      run: |
        npx playwright test --project=chromium \
          tests/frontend/stridence/dashboard-tabs.spec.ts \
          tests/frontend/stridence/alpine-components.spec.ts \
          tests/frontend/stridence/inline-edit.spec.ts \
          tests/frontend/stridence/enrollment-form.spec.ts

    - name: Upload Playwright report on failure
      if: failure()
      uses: actions/upload-artifact@v4
      with:
        name: playwright-report
        path: playwright-report/
        retention-days: 7
```

- [ ] **Step 2: Smoke the harness specs locally against DDEV first (proves the specs themselves pass before trusting CI).**

```
cd /home/ntdst/Sites/stride && npx playwright test --project=chromium \
  tests/frontend/stridence/dashboard-tabs.spec.ts \
  tests/frontend/stridence/alpine-components.spec.ts \
  tests/frontend/stridence/inline-edit.spec.ts \
  tests/frontend/stridence/enrollment-form.spec.ts
```
Expected: PASS against `https://stride.ddev.site` (default baseURL). If any spec is flaky/seed-dependent, drop it from the gate list and note it OUT-OF-SCOPE — do NOT weaken the gate to make a flaky spec pass.

- [ ] **Step 3: Push to a throwaway seam branch and verify the gate runs GREEN in CI.**

```bash
git add .github/workflows/browser.yml
git commit -m "ci: add Playwright browser gate reusing the integration WP-standup"
git push origin HEAD:ci-seam-browser-gate
gh run watch
```
Expected: the `Browser` workflow's `Run Playwright` step passes. (Per project lesson `gotcha_ci_green_local_red`: trust the CI run, not local — verify via `gh run watch`.)

- [ ] **Step 4: Prove the gate BITES (RED).** Temporarily change one assertion in `dashboard-tabs.spec.ts` (e.g. `toHaveText('inschrijvingen')` → `toHaveText('WRONG')`), push to the seam branch, confirm the `Browser` run goes RED, then revert.

```bash
# after editing the assertion
git commit -am "tmp: prove browser gate bites" && git push origin HEAD:ci-seam-browser-gate
gh run watch    # expect FAIL on Run Playwright
git revert --no-edit HEAD && git push origin HEAD:ci-seam-browser-gate
gh run watch    # expect GREEN again
```

- [ ] **Step 5: Clean up the seam branch after the gate is proven.**

```bash
git push origin --delete ci-seam-browser-gate
```

---

## Out of scope (explicit follow-ups — keep this plan shippable before the demo)

- **Codeception-in-CI** (Selenium service + a generated/committed seeded `stride_`-prefix `tests/_data/dump.sql` + prefix reconciliation). Heavy, its own plan. Defer. (T4 lands the stale-signal fix now so the suite is correct when that lift happens.)
- **Seed-dependent Playwright specs in CI** (`enrollment/*`, and the currently-`testIgnore`d `trajectory-enrollment`, `field-groups-enrollment`) — need `scripts/seed.php` to run inside the CI job + the field-group fixture the config header flags as missing. Add once the harness gate is green and seeding is wired.
- **Playwright mobile (Pixel-5) project in CI** — add after the chromium gate is stable.
- **Backfilling unrelated weak surfaces** — certificate download-failure path, field-group enrollment, admin grid. NOT this plan; this plan closes only the gaps the user touched this session + establishes the browser gate.

---

## Convergence contract

The acceptance-flow matrix (AF-1…AF-4), the gate decisions, and the two REVIEW GATE tiers are the convergence target for `/code-review` at Stage 3. A reviewer verifies the diff against the named rows — e.g. "T1 asserts the cert URL is the tab path AND not the LD endpoint" — not free-form. A gap is a one-line finding keyed to a named AF row or task step, not a re-discovery. There is no security/drift block because 1a and wp-plan-requirements were assessed and recorded as not-firing with reasons above; a reviewer expecting one should read those two bullets first.

## Self-review

- **Spec coverage:** Goal 1 (CI browser gate) → T5 + T6. Goal 2 three sub-items → cert URL (T1), per-type icon/colour (T2), auto-mark-read (T3). Stale acceptance signal discovered during ground-truth (finding #9) → T4. All covered.
- **Placeholder scan:** all code/asserts are concrete; RED steps name the exact temporary edit. No TBD/TODO.
- **Type consistency:** `getNotifications`/`markAllRead`/`getUnreadCount` signatures match `NotificationService`; `fromAuditEntry` return shape matches `NotificationMapper`; `$typeStyles` keys/values copied verbatim from `notification-item.php:45-52`; the integration helpers (`freshService`, `recordAudit`, `transientKey`, `self::$testUserId`) match `NotificationCacheIntegrationTest`.
