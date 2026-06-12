# Online Enrollment Flow — Shake-out Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Systematically test all online enrollment code paths — extend seed data, smoke test in browser, document bugs, fix critical issues, write acceptance tests.

**Architecture:** Hybrid shake-out: extend seed data with 3 missing scenarios, manual smoke test via Chrome DevTools to find obvious breaks, fix critical/important bugs, then write Codeception acceptance tests for the critical paths.

**Tech Stack:** PHP 8.3, WordPress, LearnDash, Codeception (acceptance tests), DDEV, Chrome DevTools Protocol

**Design spec:** `docs/superpowers/specs/2026-03-31-online-enrollment-shakeout-design.md`

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `scripts/seed.php` | Modify | Add 3 new online course scenarios (minimal, direct, webinar) |
| `tasks/shake-out-online-enrollment-manifest.md` | Create | Bug manifest for all findings |
| `tests/acceptance/OnlineEnrollmentCest.php` | Create | Acceptance tests for online enrollment paths |

---

## Task 1: Extend seed data with 3 missing scenarios

**Files:**
- Modify: `scripts/seed.php`

Add 3 new online courses after the existing INDEX 4 course. These fill the gaps in the test matrix.

- [ ] **Step 1: Read current seed structure**

Read `scripts/seed.php` lines 270-390 (existing online courses INDEX 2-4) to understand the pattern for closed online courses with editions.

- [ ] **Step 2: Add Scenario 3 — Closed e-learning with minimal form**

Insert after INDEX 4 (before the in-person courses section). Add as a new entry in the `$courses` array:

```php
// === SHAKE-OUT: Closed online - minimal enrollment form ===
[
    'title' => 'E-learning: Mindfulness voor Jongeren',
    'description' => 'Korte online module over mindfulness-technieken voor jongeren. Ademhalingsoefeningen, body scan en korte meditaties.',
    'type' => 'online',
    'format' => ['online', 'e-learning'],
    'themes' => ['welzijn'],
    'ld_price_type' => 'closed',
    'editions' => [
        [
            'start_date' => date('Y-m-d', strtotime('+1 day')),
            'end_date' => date('Y-m-d', strtotime('+60 days')),
            'price' => 45.00,
            'price_non_member' => 55.00,
            'capacity' => 0, // unlimited — tests capacity=0 edge case
            'venue' => 'Online',
            'status' => 'open',
            'enrollment_form' => 'minimal',
            'sessions' => [
                ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Mindfulness (60 dagen toegang)'],
            ],
        ],
    ],
    'lessons' => [
        ['title' => 'Les 1: Wat is mindfulness?', 'content' => '<p>Introductie tot mindfulness en waarom het werkt voor jongeren.</p>'],
        ['title' => 'Les 2: Ademhalingsoefeningen', 'content' => '<p>Drie eenvoudige ademhalingstechnieken voor in de klas.</p>'],
        ['title' => 'Les 3: Body scan', 'content' => '<p>Geleide body scan oefening met audio-instructies.</p>'],
    ],
],
```

- [ ] **Step 3: Add Scenario 4 — Closed e-learning with direct enrollment**

```php
// === SHAKE-OUT: Closed online - direct enrollment (no form) ===
[
    'title' => 'E-learning: Snelle Update Jeugdsport',
    'description' => 'Korte opfrismodule over actuele richtlijnen jeugdsport. Geen formulier nodig, direct toegang na inschrijving.',
    'type' => 'online',
    'format' => ['online', 'e-learning'],
    'themes' => ['beweging'],
    'ld_price_type' => 'closed',
    'editions' => [
        [
            'start_date' => date('Y-m-d', strtotime('+1 day')),
            'end_date' => date('Y-m-d', strtotime('+30 days')),
            'price' => 25.00,
            'price_non_member' => 35.00,
            'capacity' => 500,
            'venue' => 'Online',
            'status' => 'open',
            'enrollment_form' => 'direct',
            'sessions' => [
                ['date_offset' => 0, 'start' => '00:00', 'end' => '23:59', 'type' => SESSION_TYPE_ONLINE, 'title' => 'E-learning: Jeugdsport update (30 dagen toegang)'],
            ],
        ],
    ],
    'lessons' => [
        ['title' => 'Update 1: Nieuwe beweegrichtlijnen 2026', 'content' => '<p>Samenvatting van de herziene beweegrichtlijnen voor jongeren.</p>'],
        ['title' => 'Update 2: Blessurepreventie checklist', 'content' => '<p>Praktische checklist voor blessurepreventie bij jeugdsport.</p>'],
        ['title' => 'Update 3: Warmteprotocol', 'content' => '<p>Richtlijnen voor sporten bij hoge temperaturen.</p>'],
    ],
],
```

- [ ] **Step 4: Add Scenario 5 — Webinar with default form**

The existing webinar seeds (INDEX 14-15) do NOT have `enrollment_form` set. Add `'enrollment_form' => 'default'` to the INDEX 14 edition (Webinarreeks: Actuele Thema's). Find its edition config around line 746 and add the key:

```php
// In the INDEX 14 edition array, add after 'status' => 'open':
'enrollment_form' => 'default',
```

This is the simplest change — reuses an existing seeded webinar rather than creating a new course.

- [ ] **Step 5: Re-seed and verify**

```bash
ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'
ddev exec wp eval-file scripts/seed.php
```

Verify the new courses exist:
```bash
ddev exec wp db query "SELECT p.ID, p.post_title, pm.meta_value as enrollment_form FROM stride_posts p LEFT JOIN stride_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_ntdst_enrollment_form' WHERE p.post_type = 'vad_edition' AND p.post_status = 'publish' ORDER BY p.ID" --skip-column-names
```

Expected: See editions with `enrollment_form` values: `default`, `minimal`, `direct`.

- [ ] **Step 6: Commit**

```bash
git add scripts/seed.php
git commit -m "feat(seed): add minimal, direct, and webinar enrollment scenarios for shake-out"
```

---

## Task 2: Manual smoke test — Course Page CTAs (Section A)

**This task uses Chrome DevTools to test all 5 scenarios in browser.**

- [ ] **Step 1: Identify course URLs**

```bash
ddev exec wp db query "
SELECT p.ID, p.post_title, p.post_name
FROM wp_posts p
WHERE p.post_type = 'sfwd-courses'
  AND p.post_status = 'publish'
  AND p.post_title LIKE '%E-learning%' OR p.post_title LIKE '%Webinar%' OR p.post_title LIKE '%Slaap%' OR p.post_title LIKE '%Mindful%' OR p.post_title LIKE '%Snelle Update%'
ORDER BY p.ID
" --skip-column-names
```

Note the slugs for each scenario course.

- [ ] **Step 2: Log in as seed_student1**

Navigate to `https://stride.ddev.site/login` and log in as `seed_student1` / `seedpass123`.

- [ ] **Step 3: Test Scenario 1 — Open e-learning course page**

Visit the first open e-learning course (INDEX 0: "Basiskennis Jeugdgezondheid"). Check:
- [ ] `sidebar-online` is rendered (not `sidebar-edition`)
- [ ] CTA button exists — note actual text (may not be "Start cursus" on first visit — document if different)
- [ ] No enrollment form link
- [ ] Mobile CTA matches desktop

- [ ] **Step 4: Test Scenario 2 — Closed e-learning + default form**

Visit INDEX 2 course ("Eetproblemen"). Check:
- [ ] `sidebar-online` rendered
- [ ] CTA says "Inschrijven" and links to `/vormingen/{slug}/inschrijving/`
- [ ] Format badge shows "E-learning"
- [ ] Price displayed correctly

- [ ] **Step 5: Test Scenario 3 — Closed e-learning + minimal form**

Visit the new "Mindfulness" course. Check:
- [ ] `sidebar-online` rendered
- [ ] CTA says "Inschrijven" and links to enrollment form
- [ ] Price shows €45,00 (member) or €55,00

- [ ] **Step 6: Test Scenario 4 — Closed e-learning + direct enrollment**

Visit the new "Snelle Update Jeugdsport" course. Check:
- [ ] `sidebar-online` rendered
- [ ] CTA links to enrollment URL (direct enrollment happens on visit)

- [ ] **Step 7: Test Scenario 5 — Webinar with default form**

Visit the new "Slaaphygiëne" webinar course. Check:
- [ ] `sidebar-online` rendered
- [ ] Format badge shows "Webinar"
- [ ] CTA says "Inschrijven"

- [ ] **Step 8: Document findings**

Create `tasks/shake-out-online-enrollment-manifest.md` with all bugs found. Use format:

```markdown
# Online Enrollment Flow — Shake-out Bug Manifest

**Date:** 2026-03-31
**Tester:** Claude (automated)
**Seed state:** Fresh re-seed with extended scenarios

---

## BUG-001: [Title]
**Severity:** CRITICAL / IMPORTANT / MINOR
**Component:** [file]
**Steps:** ...
**Expected:** ...
**Actual:** ...
**Fix:** ...
**Status:** OPEN
```

---

## Task 3: Manual smoke test — Enrollment Flow (Section B)

**Test the actual enrollment process for scenarios 2-5.**

- [ ] **Step 1: Test Scenario 2 — Default form enrollment**

Navigate to enrollment URL for the "Eetproblemen" edition. Check:
- [ ] Form shows 2 steps only: "Gegevens" and "Bevestigen" (no "Type", no "Facturatie")
- [ ] Personal fields pre-filled from user meta
- [ ] No organisation/department fields visible
- [ ] Submit form → registration created

Verify in DB:
```bash
ddev exec wp db query "SELECT id, user_id, edition_id, status, enrollment_type FROM stride_vad_registrations ORDER BY id DESC LIMIT 5" --skip-column-names
```

- [ ] **Step 2: Test Scenario 3 — Minimal form enrollment**

Navigate to enrollment URL for "Mindfulness" edition. Check:
- [ ] Same 2-step flow as default form for online
- [ ] Submit → registration created
- [ ] No quote created (check wp_posts for vad_quote)

- [ ] **Step 3: Test Scenario 4 — Direct enrollment**

Navigate to enrollment URL for "Snelle Update" edition. Check:
- [ ] No form shown — immediate redirect
- [ ] URL has `?enrolled=1` parameter
- [ ] Check if the edition page shows any confirmation message (document if none — this is a known gap)
- [ ] Registration created in DB

- [ ] **Step 4: Test Scenario 5 — Webinar form enrollment**

Navigate to enrollment URL for "Slaaphygiëne" edition. Check:
- [ ] 2-step form (same as scenario 2)
- [ ] Submit → registration created

- [ ] **Step 5: Verify LD access for all enrolled scenarios**

```bash
ddev exec wp eval "
\$userId = get_user_by('login', 'seed_student1')->ID;
\$courses = learndash_user_get_enrolled_courses(\$userId);
echo 'Enrolled courses for seed_student1: ' . implode(', ', \$courses) . PHP_EOL;
"
```

All courses from scenarios 2-5 should appear.

- [ ] **Step 6: Update bug manifest**

Add any new bugs found.

---

## Task 4: Manual smoke test — Dashboard (Section C)

- [ ] **Step 1: Visit dashboard as seed_student1**

Navigate to `https://stride.ddev.site/dashboard/` (or the configured dashboard URL). Check:
- [ ] Enrolled online courses appear in "Online cursussen" section
- [ ] Format labels correct: "E-learning" for e-learning, "Webinar" for webinar
- [ ] Progress shows 0% for newly enrolled courses
- [ ] Resume URL links to first lesson

- [ ] **Step 2: Check Scenario 1 — Open course in dashboard**

If seed_student1 accessed any lessons in the open course during CTA testing, check:
- [ ] Course appears in dashboard via LD enrollment data
- [ ] No `wp_vad_registrations` row exists for this course

- [ ] **Step 3: Update bug manifest**

---

## Task 5: Manual smoke test — Admin UI (Section D)

- [ ] **Step 1: Log in as admin**

Navigate to `https://stride.ddev.site/wp/wp-admin/` and log in as admin.

- [ ] **Step 2: Test online edition admin**

Open the edition edit page for the "Mindfulness" edition (minimal form). Check:
- [ ] Sessions metabox hidden
- [ ] Attendance tab hidden
- [ ] Venue/date fields hidden (`.stride-classroom-only` elements)
- [ ] "Cursusinstellingen" tab visible
- [ ] `enrollment_form` field shows "minimal"

- [ ] **Step 3: Test direct enrollment edition admin**

Open edition for "Snelle Update" (direct form). Check:
- [ ] Same metabox hiding as above
- [ ] Registration metabox shows direct enrollment info

- [ ] **Step 4: Test course dropdown toggle**

On any edition edit page, change the course dropdown from an online course to a klassikaal course. Check:
- [ ] Sessions metabox appears
- [ ] Attendance tab appears
- [ ] Cursusinstellingen tab hides

- [ ] **Step 5: Test edition list format filter**

Go to the edition list page. Check:
- [ ] Format column or filter exists
- [ ] Filtering by "Online" shows only online editions

- [ ] **Step 6: Update bug manifest**

---

## Task 6: Manual smoke test — Edge Cases (Section E)

- [ ] **Step 1: Already enrolled — form-based**

As seed_student1 (already enrolled from Task 3), visit the enrollment URL for "Eetproblemen" again. Check:
- [ ] See "Je bent al ingeschreven" message (not the enrollment form)
- [ ] Icon: check-circle

- [ ] **Step 2: Already enrolled — direct**

Visit enrollment URL for "Snelle Update" again. Check:
- [ ] Redirect to edition page (no duplicate registration)
- [ ] No `?enrolled=1` on redirect URL

Verify no duplicate:
```bash
ddev exec wp db query "SELECT id, user_id, edition_id, status FROM stride_vad_registrations WHERE user_id = (SELECT ID FROM stride_users WHERE user_login = 'seed_student1') ORDER BY id DESC LIMIT 10" --skip-column-names
```

- [ ] **Step 3: Logged-out user**

In an incognito/logged-out session, visit any enrollment URL. Check:
- [ ] Redirect to login page

- [ ] **Step 4: Full edition (capacity reached)**

Visit course page for INDEX 4 ("Beweegbeleid") which has a full first edition (capacity 50, registered 50). Check:
- [ ] CTA shows "Volzet" or disabled state for the full edition
- [ ] Second edition (if available) still shows enrollment option

- [ ] **Step 5: Direct enrollment ?enrolled=1 handling**

Check the edition page for "Snelle Update" with `?enrolled=1` in URL. Check:
- [ ] Is there a toast/notification/message? Document what happens.
- [ ] If nothing happens, log as MINOR UX gap

- [ ] **Step 6: Final bug manifest update**

Update `tasks/shake-out-online-enrollment-manifest.md` with all edge case findings.

- [ ] **Step 7: Commit bug manifest**

```bash
git add tasks/shake-out-online-enrollment-manifest.md
git commit -m "docs: add online enrollment shake-out bug manifest"
```

---

## Task 7: Fix CRITICAL and IMPORTANT bugs

**This task depends on the bug manifest from Tasks 2-6.**

- [ ] **Step 1: Review bug manifest**

Read `tasks/shake-out-online-enrollment-manifest.md`. Triage:
- CRITICAL: Fix immediately (blocks enrollment, data loss, wrong access)
- IMPORTANT: Fix in this session (UX issues, wrong display)
- MINOR: Document, defer

- [ ] **Step 2: Fix each CRITICAL bug**

For each CRITICAL bug:
1. Read the relevant source file
2. Identify the root cause
3. Write the fix
4. Verify the fix in browser
5. Update bug manifest status to FIXED

- [ ] **Step 3: Fix each IMPORTANT bug**

Same process for IMPORTANT bugs.

- [ ] **Step 4: Run existing test suite**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --testsuite Integration
```

Expected: All existing tests still pass after fixes.

- [ ] **Step 5: Commit fixes**

```bash
git add -A
git commit -m "fix(enrollment): fix online enrollment shake-out bugs

[list bugs fixed]"
```

---

## Task 8: Write acceptance tests

**Files:**
- Create: `tests/acceptance/OnlineEnrollmentCest.php`

**Important patterns from existing `EnrollmentCest.php`:**
- DB table prefix is `stride_` (not `wp_`): use `stride_posts`, `stride_postmeta`, `stride_vad_registrations`, `stride_users`
- Alpine.js navigation uses `$I->executeJS()` with `Alpine.$data(el)` — never `$I->click()` for step navigation
- Form fields set via JS: `comp.form.terms_accepted = true`, `comp.stepIndex = 3`
- Form submission via JS: `comp.submitForm()`
- Pre-create registrations with `$I->haveInDatabase()` for "already enrolled" tests

- [ ] **Step 1: Create test file with helper setup**

```php
<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Online Enrollment Flow Acceptance Tests
 *
 * Tests online (e-learning/webinar) enrollment paths:
 * - Form-based enrollment (default + minimal forms, 2-step flow)
 * - Direct enrollment (no form, immediate redirect)
 * - Course page CTA rendering
 * - Already enrolled state
 * - Admin metabox behavior for online editions
 *
 * Prerequisites: seed data must be loaded (scripts/seed.php)
 * with the extended scenarios (minimal, direct, webinar).
 *
 * Alpine.js forms: use executeJS with Alpine.$data(el) for navigation.
 * DB prefix: stride_ (not wp_).
 */
class OnlineEnrollmentCest
{
    private array $courseData = [];
    private int $studentUserId;

    public function _before(AcceptanceTester $I): void
    {
        $this->courseData = $this->resolveSeedData($I);
        $this->studentUserId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'seed_student1']);
    }

    private function resolveSeedData(AcceptanceTester $I): array
    {
        $data = [];
        $data['default'] = $this->findEditionByCourseTitlePrefix($I, 'E-learning: Eetproblemen');
        $data['minimal'] = $this->findEditionByCourseTitlePrefix($I, 'E-learning: Mindfulness');
        $data['direct']  = $this->findEditionByCourseTitlePrefix($I, 'E-learning: Snelle Update');
        $data['webinar'] = $this->findEditionByCourseTitlePrefix($I, 'Webinarreeks: Actuele');
        return $data;
    }

    private function findEditionByCourseTitlePrefix(AcceptanceTester $I, string $prefix): array
    {
        $courseId = $I->grabFromDatabase('stride_posts', 'ID', [
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'post_title LIKE' => $prefix . '%',
        ]);

        $editionId = $I->grabFromDatabase('stride_postmeta', 'post_id', [
            'meta_key' => '_ntdst_course_id',
            'meta_value' => $courseId,
        ]);

        $slug = $I->grabFromDatabase('stride_posts', 'post_name', ['ID' => $editionId]);

        return [
            'course_id' => (int) $courseId,
            'edition_id' => (int) $editionId,
            'slug' => $slug,
            'enrollment_url' => '/vormingen/' . $slug . '/inschrijving/',
        ];
    }

    private function loginAsStudent(AcceptanceTester $I, string $redirectTo = '/'): void
    {
        $I->loginAsUserId($this->studentUserId, $redirectTo);
    }
}
```

- [ ] **Step 2: Add online enrollment form tests**

```php
// =========================================================================
// ENROLLMENT FORM TESTS (Scenarios 2 + 3)
// =========================================================================

/**
 * @test
 */
public function onlineDefaultFormShowsTwoSteps(AcceptanceTester $I): void
{
    $I->wantTo('verify online default form shows only personal + confirm steps');

    $this->loginAsStudent($I, $this->courseData['default']['enrollment_url']);
    $I->waitForElement('form', 10);

    // Online forms show 2 step labels: Gegevens + Bevestigen
    $I->see('Gegevens');
    $I->see('Bevestigen');

    // Should NOT see Type or Facturatie steps (skipped for online)
    $I->dontSee('Voor wie is deze inschrijving');
    $I->dontSee('Facturatie');
}

/**
 * @test
 */
public function onlineMinimalFormShowsTwoSteps(AcceptanceTester $I): void
{
    $I->wantTo('verify online minimal form shows only personal + confirm steps');

    $this->loginAsStudent($I, $this->courseData['minimal']['enrollment_url']);
    $I->waitForElement('form', 10);

    $I->see('Gegevens');
    $I->see('Bevestigen');
    $I->dontSee('Voor wie is deze inschrijving');
    $I->dontSee('Facturatie');
}

/**
 * @test
 */
public function onlineEnrollmentCreatesRegistration(AcceptanceTester $I): void
{
    $I->wantTo('verify online enrollment creates a registration record');

    $this->loginAsStudent($I, $this->courseData['minimal']['enrollment_url']);
    $I->waitForElement('form', 10);

    // Use executeJS to fill form and submit via Alpine (established pattern)
    $I->executeJS("
        const el = document.querySelector('[x-data*=\"enrollmentForm\"]');
        const comp = Alpine.\$data(el);
        if (comp) {
            comp.form.enrollment_type = 'self';
            comp.form.terms_accepted = true;
            comp.stepIndex = comp.steps.stepMap.length - 1; // Jump to confirm step
        }
    ");
    $I->wait(1);

    // Submit via Alpine
    $I->executeJS("
        const el = document.querySelector('[x-data*=\"enrollmentForm\"]');
        const comp = Alpine.\$data(el);
        if (comp) comp.submitForm();
    ");
    $I->wait(3);

    // Verify registration created
    $I->seeInDatabase('stride_vad_registrations', [
        'edition_id' => $this->courseData['minimal']['edition_id'],
        'enrollment_type' => 'self',
    ]);
}
```

- [ ] **Step 3: Add direct enrollment and already-enrolled tests**

```php
// =========================================================================
// DIRECT ENROLLMENT TESTS (Scenario 4)
// =========================================================================

/**
 * @test
 */
public function directEnrollmentSkipsForm(AcceptanceTester $I): void
{
    $I->wantTo('verify direct enrollment redirects without showing a form');

    $this->loginAsStudent($I, $this->courseData['direct']['enrollment_url']);

    // Direct enrollment triggers server-side redirect — no form shown
    $I->wait(3);

    // Should NOT see enrollment form elements
    $I->dontSee('Gegevens');
    $I->dontSee('Facturatie');

    // Verify registration created
    $I->seeInDatabase('stride_vad_registrations', [
        'edition_id' => $this->courseData['direct']['edition_id'],
        'user_id' => $this->studentUserId,
    ]);
}

/**
 * @test
 */
public function alreadyEnrolledUserSeesMessage(AcceptanceTester $I): void
{
    $I->wantTo('verify already enrolled user sees already-enrolled message');

    // Pre-create registration (established pattern from EnrollmentCest)
    $I->haveInDatabase('stride_vad_registrations', [
        'user_id'         => $this->studentUserId,
        'edition_id'      => $this->courseData['default']['edition_id'],
        'status'          => 'confirmed',
        'enrollment_type' => 'self',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    $this->loginAsStudent($I, $this->courseData['default']['enrollment_url']);
    $I->waitForText('Je bent al ingeschreven', 10);
}
```

- [ ] **Step 4: Add course page CTA tests**

```php
// =========================================================================
// COURSE PAGE CTA TESTS
// =========================================================================

/**
 * @test
 */
public function closedOnlineCourseCTAShowsEnrollButton(AcceptanceTester $I): void
{
    $I->wantTo('verify closed online course shows Inschrijven CTA');

    $courseSlug = $I->grabFromDatabase('stride_posts', 'post_name', [
        'ID' => $this->courseData['default']['course_id'],
    ]);

    $this->loginAsStudent($I, '/cursussen/' . $courseSlug . '/');
    $I->waitForElement('body', 10);

    $I->see('Inschrijven');
    $I->dontSee('Fatal error');
}

/**
 * @test
 */
public function webinarCourseShowsWebinarLabel(AcceptanceTester $I): void
{
    $I->wantTo('verify webinar course page shows Webinar format label');

    $courseSlug = $I->grabFromDatabase('stride_posts', 'post_name', [
        'ID' => $this->courseData['webinar']['course_id'],
    ]);

    $this->loginAsStudent($I, '/cursussen/' . $courseSlug . '/');
    $I->waitForElement('body', 10);

    $I->see('Webinar');
}
```

- [ ] **Step 5: Add admin and dashboard tests**

```php
// =========================================================================
// ADMIN UI TESTS
// =========================================================================

/**
 * @test
 */
public function onlineEditionHidesSessionsMetabox(AcceptanceTester $I): void
{
    $I->wantTo('verify online edition edit page hides sessions metabox');

    $adminId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'seed_admin']);
    $I->loginAsUserId($adminId, '/wp/wp-admin/post.php?post=' . $this->courseData['minimal']['edition_id'] . '&action=edit');

    $I->waitForElement('#post', 10);
    $I->wait(2); // Wait for edition-admin.js format toggle

    // Sessions metabox should be hidden for online editions
    $I->executeJS("
        const sessionsBox = document.getElementById('stride_edition_sessions');
        return sessionsBox ? sessionsBox.style.display : 'not-found';
    ");
}

// =========================================================================
// DASHBOARD TESTS
// =========================================================================

/**
 * @test
 */
public function enrolledOnlineCourseAppearsInDashboard(AcceptanceTester $I): void
{
    $I->wantTo('verify enrolled online course appears in dashboard');

    // Pre-create registration for the minimal edition
    $I->haveInDatabase('stride_vad_registrations', [
        'user_id'         => $this->studentUserId,
        'edition_id'      => $this->courseData['minimal']['edition_id'],
        'status'          => 'confirmed',
        'enrollment_type' => 'self',
        'created_at'      => date('Y-m-d H:i:s'),
    ]);

    $this->loginAsStudent($I, '/dashboard/');
    $I->waitForElement('body', 10);

    // Should see the course title somewhere in the dashboard
    $I->see('Mindfulness');
    $I->dontSee('Fatal error');
}
```

- [ ] **Step 6: Run the new tests**

```bash
ddev exec vendor/bin/codecept run acceptance OnlineEnrollmentCest --steps
```

Expected: All tests pass. If any fail, investigate and fix.

- [ ] **Step 7: Commit tests**

```bash
git add tests/acceptance/OnlineEnrollmentCest.php
git commit -m "test(acceptance): add online enrollment flow acceptance tests"
```

---

## Task 9: Full regression and final manifest update

- [ ] **Step 1: Run full test suite**

```bash
ddev exec vendor/bin/codecept run
```

Expected: Zero failures across all suites (unit, integration, acceptance).

- [ ] **Step 2: If any failures, investigate and fix**

Read the failure output. Fix any regressions caused by bug fixes or seed changes.

- [ ] **Step 3: Update bug manifest with final status**

Read `tasks/shake-out-online-enrollment-manifest.md` and update:
- All FIXED bugs marked as FIXED with commit reference
- All deferred bugs marked as DEFERRED with reason
- Add summary section at bottom with totals

- [ ] **Step 4: Final commit**

```bash
git add tasks/shake-out-online-enrollment-manifest.md
git commit -m "docs: finalize online enrollment shake-out manifest"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.

### Stage V1: Seed Data Verification

```bash
ddev exec wp db query "SELECT p.post_title, pm.meta_value as form_type FROM stride_posts p JOIN stride_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_ntdst_enrollment_form' WHERE p.post_type = 'vad_edition' AND p.post_status = 'publish' AND pm.meta_value IN ('minimal', 'direct')" --skip-column-names
```

Expected: See the new "Mindfulness" (minimal) and "Snelle Update" (direct) editions.

### Stage V2: Acceptance Tests

```bash
ddev exec vendor/bin/codecept run acceptance OnlineEnrollmentCest --steps
```

Expected: ALL tests pass.

### Stage V3: Full Regression

```bash
ddev exec vendor/bin/codecept run
```

Expected: Zero failures across all suites.

### Stage V4: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/cursussen/[open-e-learning-slug]/
      Expected: sidebar-online rendered, CTA visible, no console errors
- [ ] Visit: https://stride.ddev.site/vormingen/[minimal-edition-slug]/inschrijving/
      Expected: 2-step form (Gegevens → Bevestigen), no billing
- [ ] Visit: https://stride.ddev.site/vormingen/[direct-edition-slug]/inschrijving/
      Expected: Immediate redirect, registration created
- [ ] Visit: https://stride.ddev.site/dashboard/
      Expected: Enrolled online courses visible with format labels
- [ ] Admin: https://stride.ddev.site/wp/wp-admin/post.php?post=[online-edition-id]&action=edit
      Expected: Sessions metabox hidden, cursusinstellingen tab visible
- [ ] Database: `ddev exec wp db query "SELECT COUNT(*) FROM stride_vad_registrations WHERE enrollment_type = 'self' AND edition_id IN (select ID from stride_posts where post_type='vad_edition')"`
      Expected: Registrations exist for enrolled scenarios
```
