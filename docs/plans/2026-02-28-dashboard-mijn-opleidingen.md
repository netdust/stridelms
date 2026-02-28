# Dashboard "Mijn opleidingen" Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Rename the dashboard "Inschrijvingen" tab to "Mijn opleidingen" and show both classroom edition enrollments and online LearnDash courses, plus fix the certificates tab to include online course certificates.

**Architecture:** Extend `LMSAdapterInterface` with 3 new methods for enrolled courses, progress, and completion dates. Rebuild the tab template with 3 sections (klassikaal, online, afgerond). Fix certificates tab to query both sources. All deduplication logic lives in the template layer.

**Tech Stack:** PHP 8.3, WordPress/LearnDash, Tailwind CSS, Alpine.js

**Design doc:** `docs/plans/2026-02-28-dashboard-mijn-opleidingen-design.md`

---

## Phase 1: LMS Adapter Extension

### Task 1: Add 3 methods to LMSAdapterInterface

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Contracts/LMSAdapterInterface.php`

**Step 1: Add interface methods**

Add after the existing `getCertificateLink()` method:

```php
/**
 * Get all course IDs the user is enrolled in.
 *
 * @return int[]
 */
public function getEnrolledCourses(int $userId): array;

/**
 * Get course progress percentage (0-100).
 */
public function getProgress(int $userId, int $courseId): int;

/**
 * Get course completion timestamp, or null if not completed.
 */
public function getCompletionDate(int $userId, int $courseId): ?int;
```

Update the docblock at the top: change "Only 4 touch points" to "7 touch points".

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Contracts/LMSAdapterInterface.php
git commit -m "feat(lms): add getEnrolledCourses, getProgress, getCompletionDate to interface"
```

---

### Task 2: Write failing tests for new adapter methods

**Files:**
- Create: `tests/Unit/LearnDashAdapterTest.php`

**Step 1: Write the test file**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Integrations\LearnDash\LearnDashAdapter;
use Stride\Tests\TestCase;

class LearnDashAdapterTest extends TestCase
{
    private LearnDashAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new LearnDashAdapter();
    }

    /**
     * @test
     */
    public function getEnrolledCoursesReturnsEmptyWhenLDUnavailable(): void
    {
        // LearnDash function not defined in test env
        $result = $this->adapter->getEnrolledCourses(1);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * @test
     */
    public function getProgressReturnsZeroWhenLDUnavailable(): void
    {
        $result = $this->adapter->getProgress(1, 100);
        $this->assertSame(0, $result);
    }

    /**
     * @test
     */
    public function getCompletionDateReturnsNullWhenLDUnavailable(): void
    {
        $result = $this->adapter->getCompletionDate(1, 100);
        $this->assertNull($result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function getEnrolledCoursesReturnsIntArray(): void
    {
        // Define stub LD function
        if (!function_exists('learndash_user_get_enrolled_courses')) {
            eval('
                function learndash_user_get_enrolled_courses(int $userId, array $args = []): array
                {
                    return [101, 202, 303];
                }
            ');
        }

        $adapter = new LearnDashAdapter();
        $result = $adapter->getEnrolledCourses(42);

        $this->assertSame([101, 202, 303], $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function getProgressReturnsPercentage(): void
    {
        if (!function_exists('learndash_course_progress')) {
            eval('
                function learndash_course_progress(array $args = []): ?array
                {
                    return ["percentage" => 75];
                }
            ');
        }

        $adapter = new LearnDashAdapter();
        $result = $adapter->getProgress(42, 101);

        $this->assertSame(75, $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function getCompletionDateReturnsTimestamp(): void
    {
        if (!function_exists('learndash_user_get_course_completed_date')) {
            eval('
                function learndash_user_get_course_completed_date(int $userId, int $courseId): int
                {
                    return 1709136000;
                }
            ');
        }
        if (!function_exists('learndash_course_completed')) {
            eval('
                function learndash_course_completed(int $userId, int $courseId): bool
                {
                    return true;
                }
            ');
        }

        $adapter = new LearnDashAdapter();
        $result = $adapter->getCompletionDate(42, 101);

        $this->assertSame(1709136000, $result);
    }

    /**
     * @test
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function getCompletionDateReturnsNullForIncompleteCoure(): void
    {
        if (!function_exists('learndash_course_completed')) {
            eval('
                function learndash_course_completed(int $userId, int $courseId): bool
                {
                    return false;
                }
            ');
        }

        $adapter = new LearnDashAdapter();
        $result = $adapter->getCompletionDate(42, 101);

        $this->assertNull($result);
    }
}
```

**Step 2: Run tests to verify they fail**

```bash
ddev exec vendor/bin/phpunit --filter LearnDashAdapterTest --testsuite Unit
```

Expected: FAIL — methods not implemented yet.

**Step 3: Commit test file**

```bash
git add tests/Unit/LearnDashAdapterTest.php
git commit -m "test(lms): add failing tests for new adapter methods"
```

---

### Task 3: Implement the 3 adapter methods

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashAdapter.php`

**Step 1: Add implementations**

Add after the existing `getCertificateLink()` method:

```php
public function getEnrolledCourses(int $userId): array
{
    if (!function_exists('learndash_user_get_enrolled_courses')) {
        return [];
    }

    return learndash_user_get_enrolled_courses($userId);
}

public function getProgress(int $userId, int $courseId): int
{
    if (!function_exists('learndash_course_progress')) {
        return 0;
    }

    $progress = learndash_course_progress([
        'user_id'   => $userId,
        'course_id' => $courseId,
        'array'     => true,
    ]);

    return (int) ($progress['percentage'] ?? 0);
}

public function getCompletionDate(int $userId, int $courseId): ?int
{
    if (!$this->isComplete($userId, $courseId)) {
        return null;
    }

    if (!function_exists('learndash_user_get_course_completed_date')) {
        return null;
    }

    $timestamp = learndash_user_get_course_completed_date($userId, $courseId);

    return $timestamp ?: null;
}
```

Update the class docblock: "7 methods" instead of "4 methods".

**Step 2: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit --filter LearnDashAdapterTest --testsuite Unit
```

Expected: ALL PASS

**Step 3: Run full unit suite for regressions**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL PASS

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Integrations/LearnDash/LearnDashAdapter.php
git commit -m "feat(lms): implement getEnrolledCourses, getProgress, getCompletionDate"
```

---

## Phase 2: Navigation Rename

### Task 4: Rename tab in sidebar and mobile nav

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/nav-sidebar.php`
- Modify: `web/app/themes/stridence/templates/dashboard/nav-mobile.php`

**Step 1: Update sidebar nav**

In `nav-sidebar.php`, change the `inschrijvingen` entry in the `$tabs` array:

```php
'inschrijvingen' => [
    'label' => __('Mijn opleidingen', 'stridence'),
    'icon'  => 'book-open',
],
```

**Step 2: Update mobile nav**

In `nav-mobile.php`, same change to the `$tabs` array:

```php
'inschrijvingen' => [
    'label' => __('Mijn opleidingen', 'stridence'),
    'icon'  => 'book-open',
],
```

**Step 3: Verify icon exists**

Check that `book-open.svg` exists in the icons directory:

```bash
ls web/app/themes/stridence/icons/book-open.svg 2>/dev/null || echo "MISSING - need to add icon"
```

If missing, download from Lucide icons (the icon set already in use).

**Step 4: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/nav-sidebar.php \
        web/app/themes/stridence/templates/dashboard/nav-mobile.php
git commit -m "feat(dashboard): rename Inschrijvingen tab to Mijn opleidingen"
```

---

## Phase 3: Rebuild the Tab Template

### Task 5: Rebuild tab-inschrijvingen.php with 3 sections

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php`

This is the largest task. The template currently only queries `RegistrationRepository`. It needs to also query the LMS adapter for online courses and merge completed items.

**Step 1: Rewrite the data-fetching section (top of file)**

Replace the entire file. The new structure:

```php
<?php
/**
 * Dashboard Tab: Mijn opleidingen (My Courses)
 *
 * Shows user's classroom editions AND online courses.
 * Three sections: klassikaal (active editions), online (active LD courses),
 * afgerond (merged completed from both sources).
 *
 * @param array $args {
 *     @type WP_User $user Current user object
 * }
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\RegistrationStatus;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Enrollment\EnrollmentCompletionService;

$user    = $args['user'] ?? wp_get_current_user();
$user_id = $user->ID;

// Get services
$registrationRepo    = ntdst_get(RegistrationRepository::class);
$editionService      = ntdst_get(EditionService::class);
$sessionService      = ntdst_get(SessionService::class);
$attendanceService   = ntdst_get(AttendanceService::class);
$completionService   = ntdst_get(CompletionService::class);
$enrollmentCompletion = ntdst_get(EnrollmentCompletionService::class);
$lmsAdapter          = ntdst_get(LMSAdapterInterface::class);

// ── Section A: Edition-based registrations ──────────────────
$registrations = $registrationRepo->findByUser($user_id);

$active_editions    = [];
$completed_items    = [];
$cancelled_editions = [];
$edition_course_ids = []; // Track which courses are covered by editions

foreach ($registrations as $reg) {
    if (empty($reg->edition_id)) {
        continue;
    }

    $edition_id = (int) $reg->edition_id;
    $edition    = $editionService->getEdition($edition_id);

    if (is_wp_error($edition)) {
        continue;
    }

    $editionModel = ntdst_data()->get('vad_edition');
    $course_id    = $editionService->getCourseId($edition_id);
    $course       = $course_id ? get_post($course_id) : null;

    // Track this course as edition-linked
    if ($course_id) {
        $edition_course_ids[] = $course_id;
    }

    $reg_data = [
        'id'               => (int) $reg->id,
        'edition_id'       => $edition_id,
        'edition'          => $edition,
        'course'           => $course,
        'course_id'        => $course_id,
        'course_title'     => $course ? $course->post_title : $edition->post_title,
        'start_date'       => $editionModel->getMeta($edition_id, 'start_date', ''),
        'venue'            => $editionModel->getMeta($edition_id, 'venue', ''),
        'status'           => $reg->status,
        'registered_at'    => $reg->registered_at,
        'sessions'         => $sessionService->getSessionsForEdition($edition_id),
        'progress'         => $completionService->getProgress($edition_id, $user_id),
        'completion_tasks' => $reg->completion_tasks ?? null,
        'type'             => 'edition',
    ];

    // Add attendance for each session
    foreach ($reg_data['sessions'] as &$session) {
        $attendance_status = $attendanceService->getStatus((int) $session['id'], $user_id);
        $session['attendance'] = $attendance_status?->value;
    }
    unset($session);

    $status = RegistrationStatus::tryFrom($reg->status) ?? RegistrationStatus::Confirmed;

    switch ($status) {
        case RegistrationStatus::Completed:
            $reg_data['completed_at'] = $reg->completed_at ?? $reg_data['start_date'];
            $completed_items[] = $reg_data;
            break;
        case RegistrationStatus::Cancelled:
            $cancelled_editions[] = $reg_data;
            break;
        default:
            $active_editions[] = $reg_data;
    }
}

// ── Section B: Online courses (direct LearnDash) ────────────
$enrolled_course_ids = $lmsAdapter->getEnrolledCourses($user_id);
$edition_course_ids  = array_unique($edition_course_ids);

$active_online  = [];

foreach ($enrolled_course_ids as $course_id) {
    // Skip courses already covered by an edition registration
    if (in_array($course_id, $edition_course_ids, true)) {
        continue;
    }

    $course = get_post($course_id);
    if (!$course || $course->post_status !== 'publish') {
        continue;
    }

    $is_complete    = $lmsAdapter->isComplete($user_id, $course_id);
    $progress       = $lmsAdapter->getProgress($user_id, $course_id);
    $completion_date = $lmsAdapter->getCompletionDate($user_id, $course_id);

    // Determine format badge from ld_course_category
    $format_label = __('Online', 'stridence');
    $categories = get_the_terms($course_id, 'ld_course_category');
    if ($categories && !is_wp_error($categories)) {
        foreach ($categories as $cat) {
            if ($cat->slug === 'e-learning') {
                $format_label = 'E-learning';
            } elseif ($cat->slug === 'webinar') {
                $format_label = 'Webinar';
            }
        }
    }

    $course_data = [
        'course_id'      => $course_id,
        'course_title'   => $course->post_title,
        'course_url'     => get_permalink($course_id),
        'progress'       => $progress,
        'format_label'   => $format_label,
        'type'           => 'online',
    ];

    if ($is_complete) {
        $course_data['completed_at'] = $completion_date
            ? date('Y-m-d', $completion_date)
            : '';
        $course_data['certificate_url'] = $lmsAdapter->getCertificateLink($user_id, $course_id);
        $completed_items[] = $course_data;
    } else {
        $active_online[] = $course_data;
    }
}

// Sort completed items by date (newest first)
usort($completed_items, function ($a, $b) {
    $date_a = $a['completed_at'] ?? $a['start_date'] ?? '';
    $date_b = $b['completed_at'] ?? $b['start_date'] ?? '';
    return strcmp($date_b, $date_a);
});

// ── Upcoming Sessions (unchanged from before) ───────────────
$upcoming_sessions = [];
$today = date('Y-m-d');

foreach ($active_editions as $reg) {
    foreach ($reg['sessions'] as $session) {
        if (!empty($session['date']) && $session['date'] >= $today) {
            $upcoming_sessions[] = array_merge($session, [
                'course_title' => $reg['course_title'],
                'edition_id'   => $reg['edition_id'],
            ]);
        }
    }
}

usort($upcoming_sessions, fn($a, $b) => strcmp($a['date'] ?? '', $b['date'] ?? ''));
$upcoming_sessions = array_slice($upcoming_sessions, 0, 3);
```

**Step 2: Write the HTML sections**

After the PHP block, write the template output. Keep the "Komende sessies" and "Actieve klassikale" sections identical to current markup. Add the new online section between active editions and completed:

```html
<!-- ── Online cursussen section ── -->
<?php if (!empty($active_online)) : ?>
    <section>
        <h2 class="font-heading text-xl font-bold text-text mb-4">
            <?php esc_html_e('Online cursussen', 'stridence'); ?>
        </h2>
        <div class="space-y-3">
            <?php foreach ($active_online as $course) : ?>
                <div class="card p-4">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                <h3 class="font-semibold text-text truncate">
                                    <?php echo esc_html($course['course_title']); ?>
                                </h3>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-accent/10 text-accent">
                                    <?php echo esc_html($course['format_label']); ?>
                                </span>
                            </div>
                            <!-- Progress bar -->
                            <div class="flex items-center gap-3 mt-2">
                                <div class="flex-1 h-2 bg-border rounded-full overflow-hidden">
                                    <div class="h-full bg-accent rounded-full transition-all"
                                         style="width: <?php echo esc_attr($course['progress']); ?>%"></div>
                                </div>
                                <span class="text-sm text-text-muted whitespace-nowrap">
                                    <?php echo esc_html($course['progress']); ?>%
                                </span>
                            </div>
                        </div>
                        <a href="<?php echo esc_url($course['course_url']); ?>"
                           class="btn-primary text-sm shrink-0">
                            <?php echo $course['progress'] > 0
                                ? esc_html__('Verder leren', 'stridence')
                                : esc_html__('Start cursus', 'stridence'); ?>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
```

For the "Afgerond" section, merge editions and online — each row shows a format badge and links to certificate if available:

```html
<!-- ── Afgerond section (merged) ── -->
<?php if (!empty($completed_items)) : ?>
    <section x-data="{ open: false }">
        <button type="button"
                class="w-full flex items-center justify-between gap-4 mb-4"
                @click="open = !open">
            <h2 class="font-heading text-xl font-bold text-text">
                <?php printf(
                    esc_html__('Afgerond (%d)', 'stridence'),
                    count($completed_items)
                ); ?>
            </h2>
            <span class="text-text-muted transition-transform duration-200"
                  :class="{ 'rotate-180': open }">
                <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
            </span>
        </button>

        <div x-show="open" x-collapse>
            <div class="card divide-y divide-border">
                <?php foreach ($completed_items as $item) : ?>
                    <div class="p-4 flex items-center justify-between gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <h3 class="font-medium text-text truncate">
                                    <?php echo esc_html($item['course_title']); ?>
                                </h3>
                                <?php if (($item['type'] ?? '') === 'online') : ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-accent/10 text-accent">
                                        <?php echo esc_html($item['format_label'] ?? __('Online', 'stridence')); ?>
                                    </span>
                                <?php else : ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-primary/10 text-primary">
                                        <?php esc_html_e('Klassikaal', 'stridence'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm text-text-muted">
                                <?php
                                $date = $item['completed_at'] ?? $item['start_date'] ?? '';
                                if ($date) {
                                    echo esc_html(stride_format_date($date));
                                }
                                ?>
                            </p>
                        </div>
                        <?php
                        $cert_url = $item['certificate_url'] ?? '';
                        if (!$cert_url && !empty($item['course'])) {
                            $cert_url = $lmsAdapter->getCertificateLink($user_id, $item['course_id'] ?? 0) ?: '';
                        }
                        ?>
                        <?php if ($cert_url) : ?>
                            <a href="<?php echo esc_url(add_query_arg('tab', 'certificaten', get_permalink())); ?>"
                               class="btn-ghost text-sm">
                                <?php echo stridence_icon('award', 'w-4 h-4 mr-1'); ?>
                                <?php esc_html_e('Certificaat', 'stridence'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
<?php endif; ?>
```

Keep the "Geannuleerd" section unchanged at the bottom.

**Step 3: Verify rendering**

```bash
ddev launch /mijn-account/?tab=inschrijvingen
```

Manually check: tab label says "Mijn opleidingen", online courses appear, completed section merges both types.

**Step 4: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php
git commit -m "feat(dashboard): rebuild tab with klassikaal + online + merged afgerond sections"
```

---

## Phase 4: Certificates Tab Fix

### Task 6: Fix certificates tab to include online courses

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-certificaten.php`

**Step 1: Extend the data-fetching loop**

After the existing edition-based certificate loop (which iterates `$registrations`), add a second loop for online courses:

```php
// ── Online course certificates ──────────────────────────────
// Get all LD enrolled courses, subtract edition-linked ones
$enrolled_course_ids = $lmsAdapter->getEnrolledCourses($user_id);

foreach ($enrolled_course_ids as $courseId) {
    // Skip if already covered by an edition certificate above
    $already_covered = false;
    foreach ($certificates as $cert) {
        if ((int) ($cert['course_id'] ?? 0) === $courseId) {
            $already_covered = true;
            break;
        }
    }
    if ($already_covered) {
        continue;
    }

    // Check completion
    if (!$lmsAdapter->isComplete($user_id, $courseId)) {
        continue;
    }

    $course = get_post($courseId);
    if (!$course) {
        continue;
    }

    $certificate_url = $lmsAdapter->getCertificateLink($user_id, $courseId);
    $completion_date = $lmsAdapter->getCompletionDate($user_id, $courseId);

    $certificates[] = [
        'edition_id'      => 0,
        'course_id'       => $courseId,
        'course_title'    => $course->post_title,
        'edition_title'   => __('Online cursus', 'stridence'),
        'completed_at'    => $completion_date ? date('Y-m-d', $completion_date) : '',
        'certificate_url' => $certificate_url,
        'has_certificate' => !empty($certificate_url),
    ];
}
```

Also add `$lmsAdapter = ntdst_get(LMSAdapterInterface::class);` to the services block at the top (it's already imported but verify).

The sort at the bottom already handles it: `usort($certificates, fn($a, $b) => strcmp($b['completed_at'], $a['completed_at']));`

**Step 2: Verify rendering**

```bash
ddev launch /mijn-account/?tab=certificaten
```

Check: online course certificates appear alongside edition certificates.

**Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-certificaten.php
git commit -m "feat(dashboard): include online course certificates in certificaten tab"
```

---

## Phase 5: Integration Verification

### Task 7: Run full test suite and verify

**Step 1: Run all unit tests**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL PASS

**Step 2: Manual smoke test**

```markdown
## Smoke Test

- [ ] Visit: https://stride.ddev.site/mijn-account/
      Expected: Tab reads "Mijn opleidingen" with book-open icon (sidebar + mobile)

- [ ] Visit: https://stride.ddev.site/mijn-account/?tab=inschrijvingen
      Expected: Shows "Klassikale opleidingen" section with edition cards

- [ ] Expected: Shows "Online cursussen" section with progress bars and "Verder leren" buttons
      (requires user to have LearnDash course access — log in as seed_student1@seed.test / seedpass123)

- [ ] Expected: "Afgerond" section merges both completed editions and completed online courses

- [ ] Visit: https://stride.ddev.site/mijn-account/?tab=certificaten
      Expected: Certificates from both editions AND online courses appear

- [ ] Console: DevTools > Console
      Expected: No red errors
```

**Step 3: Final commit (if any fixups needed)**

```bash
git add -A
git commit -m "fix(dashboard): post-review adjustments for mijn opleidingen"
```
