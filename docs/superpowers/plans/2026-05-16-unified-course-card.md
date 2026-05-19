# Unified Expandable Course Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate three duplicated course-card implementations behind one expandable partial with mode-aware rendering (public / enrolled-edition / enrolled-online / completed).

**Architecture:** A single shared partial at `templates/components/course-card.php` consumes a normalised `$args` shape. Two builder helpers in `helpers/templates.php` map the existing data sources (`UserDashboardService::getEnrollmentData()` enrollment arrays and trajectory course posts) into that shape. Three call-sites swap their inline markup for `get_template_part()` calls. The Alpine `expandable()` factory is extended to accept an `initialOpen` argument so the first card on each dashboard list can default-expand.

**Tech Stack:** PHP 8.1+, WordPress, Stride theme (Tailwind + Alpine.js), PHPUnit (Unit), Codeception WPWebDriver (Acceptance).

**Source spec:** `docs/superpowers/specs/2026-05-16-unified-course-card-design.md`

---

## File Structure

**New files:**
- `web/app/themes/stridence/templates/components/course-card.php` — the shared partial
- `tests/Unit/CourseCardBuilderTest.php` — unit tests for both builder functions

**Modified files:**
- `web/app/themes/stridence/helpers/templates.php` — add two builder functions
- `web/app/themes/stridence/src/main.js` — extend `expandable()` factory to accept initial-open
- `web/app/themes/stridence/templates/dashboard/tab-home.php` — swap Opleidingen card markup
- `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php` — swap 3 card sections
- `web/app/themes/stridence/templates/trajectory/course-groups.php` — swap two duplicated card blocks
- `tests/acceptance/DashboardCest.php` (new or existing) — 2 acceptance tests
- `tests/acceptance/TrajectoryCest.php` (likely new) — 1 acceptance test

---

## Task 1: Extend `expandable()` Alpine factory to accept initial-open

**Files:**
- Modify: `web/app/themes/stridence/src/main.js:250-256`

- [ ] **Step 1: Read the current `expandable()` factory**

Located at `web/app/themes/stridence/src/main.js`, around line 250. Current code:

```js
Alpine.data('expandable', () => ({
  open: false,

  toggle() {
    this.open = !this.open;
  },
}));
```

- [ ] **Step 2: Replace it with a version that accepts initial-open**

```js
Alpine.data('expandable', (initialOpen = false) => ({
  open: Boolean(initialOpen),

  toggle() {
    this.open = !this.open;
  },
}));
```

Backwards-compatible: existing callers using `x-data="expandable()"` get `open: false` as before.

- [ ] **Step 3: Build the theme assets**

Run: `cd web/app/themes/stridence && npm run build`
Expected: Vite produces new `dist/main.*.css` and `dist/main.*.js`. No errors.

- [ ] **Step 4: Verify in browser**

Visit `https://stride.ddev.site/trajecten/{slug}/` as admin (use the test-login URL from CLAUDE.md if needed). Confirm trajectory expandable cards still toggle correctly. Open browser devtools, no console errors.

- [ ] **Step 5: Commit**

```bash
git add web/app/themes/stridence/src/main.js web/app/themes/stridence/dist/
git commit -m "feat(theme): expandable() accepts initialOpen arg"
```

---

## Task 2: Create unit tests for `stridence_build_course_card_args_from_enrollment`

**Files:**
- Create: `tests/Unit/CourseCardBuilderTest.php`
- Test target: `web/app/themes/stridence/helpers/templates.php` (function to be added in Task 3)

- [ ] **Step 1: Write the failing test file**

Create `tests/Unit/CourseCardBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Tests\TestCase;

/**
 * Unit tests for course-card builder helpers.
 *
 * Targets two pure-mapping functions in themes/stridence/helpers/templates.php:
 * - stridence_build_course_card_args_from_enrollment()
 * - stridence_build_course_card_args_from_trajectory_course()
 */
final class CourseCardBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/web/app/themes/stridence/helpers/templates.php';
    }

    // --- from_enrollment: edition with pending tasks ---

    public function test_enrollment_edition_with_pending_tasks_sets_pending_count_and_task_summary(): void
    {
        $enrollment = [
            'type'         => 'edition',
            'edition_id'   => 100,
            'course_id'    => 50,
            'course_title' => 'Eerste hulp bij sportblessures',
            'start_date'   => '2026-06-10',
            'venue'        => 'Brussel',
            'sessions'     => [],
            'task_summary' => ['total' => 3, 'completed' => 1],
            'complete_url' => 'https://example.test/vormingen/edition-100/voltooien/',
            'cta'          => ['url' => 'https://example.test/vormingen/edition-100/voltooien/', 'label' => 'Inschrijving voltooien'],
            'progress'     => ['attended' => 0, 'required' => 0],
        ];

        $args = stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertSame('edition', $args['type']);
        $this->assertTrue($args['enrolled']);
        $this->assertSame(2, $args['meta']['pending_tasks_count']);
        $this->assertSame(['total' => 3, 'completed' => 1], $args['body']['task_summary']);
        $this->assertSame('Inschrijving voltooien', $args['body']['primary_cta']['label']);
        $this->assertFalse($args['initial_open']);
    }

    // --- from_enrollment: online course with progress ---

    public function test_enrollment_online_at_60pct_sets_progress_label(): void
    {
        $enrollment = [
            'type'              => 'online',
            'course_id'         => 50,
            'course_title'      => 'Sportpsychologie 101',
            'course_url'        => 'https://example.test/opleidingen/sport-psy/',
            'progress'          => 60,
            'format_label'      => 'Online',
            'total_lessons'     => 5,
            'completed_lessons' => 3,
            'days_remaining'    => 28,
        ];

        $args = stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertSame('online', $args['type']);
        $this->assertTrue($args['enrolled']);
        $this->assertSame(60, $args['body']['progress_pct']);
        $this->assertSame('3 van 5 lessen', $args['meta']['progress_label']);
        $this->assertSame('https://example.test/opleidingen/sport-psy/', $args['body']['primary_cta']['url']);
        $this->assertSame('Verder leren', $args['body']['primary_cta']['label']);
    }

    // --- from_enrollment: completed flag ---

    public function test_enrollment_completed_clears_primary_cta_and_sets_voltooid_pill(): void
    {
        $enrollment = [
            'type'              => 'online',
            'course_id'         => 50,
            'course_title'      => 'Sportpsychologie 101',
            'progress'          => 100,
            'total_lessons'     => 5,
            'completed_lessons' => 5,
            'completed_at'      => '2026-04-20',
            'certificate_url'   => 'https://example.test/cert',
        ];

        $args = stridence_build_course_card_args_from_enrollment($enrollment, completed: true);

        $this->assertSame(['label' => 'Voltooid', 'tone' => 'muted'], $args['status_pill']);
        $this->assertNull($args['body']['primary_cta']);
        $this->assertSame(100, $args['body']['progress_pct']);
    }

    // --- from_enrollment: edition online progress = 0 means 'Start cursus' ---

    public function test_enrollment_online_zero_progress_uses_start_cursus_label(): void
    {
        $enrollment = [
            'type'              => 'online',
            'course_id'         => 50,
            'course_title'      => 'New course',
            'course_url'        => 'https://example.test/opleidingen/new/',
            'progress'          => 0,
            'total_lessons'     => 5,
            'completed_lessons' => 0,
        ];

        $args = stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertSame('Start cursus', $args['body']['primary_cta']['label']);
    }

    // --- from_enrollment: edition with sessions list ---

    public function test_enrollment_edition_passes_sessions_through_to_body(): void
    {
        $enrollment = [
            'type'         => 'edition',
            'edition_id'   => 100,
            'course_id'    => 50,
            'course_title' => 'Cursus A',
            'start_date'   => '2026-06-10',
            'venue'        => '',
            'sessions'     => [
                ['date' => '2026-06-10', 'start_time' => '09:00', 'end_time' => '12:00'],
                ['date' => '2026-06-17', 'start_time' => '09:00', 'end_time' => '12:00'],
            ],
            'task_summary' => null,
            'cta'          => null,
            'progress'     => ['attended' => 0, 'required' => 2],
        ];

        $args = stridence_build_course_card_args_from_enrollment($enrollment);

        $this->assertCount(2, $args['body']['sessions']);
        $this->assertSame('2026-06-10', $args['body']['sessions'][0]['date']);
        $this->assertNull($args['body']['primary_cta']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec vendor/bin/phpunit --filter CourseCardBuilderTest --testsuite Unit`
Expected: 5 ERRORS or FAILURES with "Call to undefined function stridence_build_course_card_args_from_enrollment()"

- [ ] **Step 3: Commit the failing tests**

```bash
git add tests/Unit/CourseCardBuilderTest.php
git commit -m "test(course-card): failing unit tests for from_enrollment builder"
```

---

## Task 3: Implement `stridence_build_course_card_args_from_enrollment`

**Files:**
- Modify: `web/app/themes/stridence/helpers/templates.php` (append at the end of file)

- [ ] **Step 1: Add the builder function**

Append to `web/app/themes/stridence/helpers/templates.php`:

```php
/**
 * Build course-card partial args from a UserDashboardService enrollment array.
 *
 * Maps the dashboard service's enrollment shape (edition or online) into the
 * normalised contract consumed by templates/components/course-card.php.
 *
 * @param array $enrollment One element of $data['active_editions'], $data['active_online'],
 *                          or $data['completed_items'] from UserDashboardService::getEnrollmentData().
 * @param bool  $completed  When true: clears primary_cta, sets status_pill to 'Voltooid'.
 * @return array            See course-card.php docblock for the full contract.
 */
function stridence_build_course_card_args_from_enrollment(array $enrollment, bool $completed = false): array
{
    $type = $enrollment['type'] ?? 'edition';
    $isOnline = $type === 'online';

    $courseId    = (int) ($enrollment['course_id'] ?? 0);
    $courseTitle = (string) ($enrollment['course_title'] ?? '');
    $thumbnailId = $courseId ? (int) get_post_thumbnail_id($courseId) : 0;

    // Status pill
    $statusPill = null;
    if ($completed) {
        $statusPill = ['label' => __('Voltooid', 'stridence'), 'tone' => 'muted'];
    } elseif ($isOnline) {
        $statusPill = ['label' => __('Online', 'stridence'), 'tone' => 'accent'];
    } else {
        $statusPill = ['label' => __('Klassikaal', 'stridence'), 'tone' => 'primary'];
    }

    // Meta (collapsed-header secondary line)
    $meta = [
        'start_date'          => null,
        'venue'               => null,
        'progress_label'      => null,
        'days_remaining'      => null,
        'pending_tasks_count' => null,
    ];

    // Body (expanded content)
    $body = [
        'excerpt'           => null,
        'progress_pct'      => null,
        'sessions'          => [],
        'upcoming_editions' => [],
        'task_summary'      => null,
        'primary_cta'       => null,
        'secondary_cta'     => null,
    ];

    if ($isOnline) {
        $totalLessons     = (int) ($enrollment['total_lessons'] ?? 0);
        $completedLessons = (int) ($enrollment['completed_lessons'] ?? 0);
        $progressPct      = (int) ($enrollment['progress'] ?? 0);

        $meta['progress_label'] = $totalLessons > 0
            ? sprintf(
                _n('%d van %d les', '%d van %d lessen', $totalLessons, 'stridence'),
                $completedLessons,
                $totalLessons
            )
            : null;
        $meta['days_remaining'] = isset($enrollment['days_remaining']) ? (int) $enrollment['days_remaining'] : null;

        $body['progress_pct'] = $progressPct;

        if (!$completed) {
            $ctaUrl = $enrollment['course_url'] ?? '';
            if (!$ctaUrl && $courseId) {
                $ctaUrl = get_permalink($courseId) ?: '';
            }
            if ($ctaUrl) {
                $body['primary_cta'] = [
                    'url'   => $ctaUrl,
                    'label' => $progressPct > 0
                        ? __('Verder leren', 'stridence')
                        : __('Start cursus', 'stridence'),
                ];
            }
        }
    } else {
        // edition
        $meta['start_date'] = !empty($enrollment['start_date']) ? (string) $enrollment['start_date'] : null;
        $meta['venue']      = !empty($enrollment['venue']) ? (string) $enrollment['venue'] : null;

        $taskSummary = $enrollment['task_summary'] ?? null;
        if ($taskSummary) {
            $body['task_summary']       = $taskSummary;
            $pending                    = (int) ($taskSummary['total'] ?? 0) - (int) ($taskSummary['completed'] ?? 0);
            $meta['pending_tasks_count'] = $pending > 0 ? $pending : null;
        }

        // Sessions list (already on the enrollment shape)
        if (!empty($enrollment['sessions']) && is_array($enrollment['sessions'])) {
            $body['sessions'] = array_values($enrollment['sessions']);
        }

        // Progress for editions = attended/required
        $progress = $enrollment['progress'] ?? null;
        if (is_array($progress)) {
            $required = (int) ($progress['required'] ?? 0);
            $attended = (int) ($progress['attended'] ?? 0);
            $body['progress_pct'] = $required > 0 ? (int) round(($attended / $required) * 100) : null;
            if ($required > 0) {
                $meta['progress_label'] = sprintf(
                    _n('%d van %d sessie', '%d van %d sessies', $required, 'stridence'),
                    $attended,
                    $required
                );
            }
        }

        if (!$completed && !empty($enrollment['cta'])) {
            $body['primary_cta'] = $enrollment['cta'];
        }
    }

    // Secondary CTA: always 'Bekijk cursus' linking to the course permalink, when we have one
    if ($courseId) {
        $coursePermalink = get_permalink($courseId);
        if ($coursePermalink) {
            $body['secondary_cta'] = [
                'url'   => $coursePermalink,
                'label' => __('Bekijk cursus', 'stridence'),
            ];
        }
    }

    return [
        'course_id'    => $courseId,
        'course_title' => $courseTitle,
        'thumbnail_id' => $thumbnailId ?: null,
        'type'         => $isOnline ? 'online' : 'edition',
        'status_pill'  => $statusPill,
        'enrolled'     => true,
        'initial_open' => false,
        'meta'         => $meta,
        'body'         => $body,
    ];
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit --filter CourseCardBuilderTest --testsuite Unit`
Expected: 5 PASS

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/helpers/templates.php tests/Unit/CourseCardBuilderTest.php
git commit -m "feat(theme): add from_enrollment course-card builder"
```

---

## Task 4: Unit tests + implementation for `stridence_build_course_card_args_from_trajectory_course`

**Files:**
- Modify: `tests/Unit/CourseCardBuilderTest.php` (add 3 more tests)
- Modify: `web/app/themes/stridence/helpers/templates.php` (add second builder)

- [ ] **Step 1: Add the failing tests**

Append to `tests/Unit/CourseCardBuilderTest.php` before the closing `}`:

```php
    // --- from_trajectory_course: required course with editions ---

    public function test_trajectory_required_course_with_editions_populates_body_editions(): void
    {
        $course = $this->createMock(\WP_Post::class);
        $course->ID = 50;
        $course->post_title = 'Verplichte basiscursus';

        // Stride/EditionService is invoked via ntdst_get(); see _registerEditionServiceStub() helper
        $this->_registerEditionServiceStub([
            ['id' => 100, 'start_date' => '2026-06-10', 'venue' => 'Brussel', 'can_enroll' => true],
            ['id' => 101, 'start_date' => '2026-07-15', 'venue' => 'Gent',    'can_enroll' => true],
        ]);

        $pill = ['label' => 'Verplicht', 'tone' => 'primary'];
        $args = stridence_build_course_card_args_from_trajectory_course($course, $pill);

        $this->assertSame('public', $args['type']);
        $this->assertFalse($args['enrolled']);
        $this->assertSame($pill, $args['status_pill']);
        $this->assertCount(2, $args['body']['upcoming_editions']);
        $this->assertSame('2026-06-10', $args['meta']['start_date']);
        $this->assertNotNull($args['body']['secondary_cta']);
        $this->assertNull($args['body']['primary_cta']);
    }

    // --- from_trajectory_course: course with no editions ---

    public function test_trajectory_course_with_no_editions_leaves_meta_start_date_null(): void
    {
        $course = $this->createMock(\WP_Post::class);
        $course->ID = 51;
        $course->post_title = 'Cursus zonder editie';

        $this->_registerEditionServiceStub([]);

        $args = stridence_build_course_card_args_from_trajectory_course($course, ['label' => 'Keuzevak', 'tone' => 'accent']);

        $this->assertSame([], $args['body']['upcoming_editions']);
        $this->assertNull($args['meta']['start_date']);
    }

    // --- from_trajectory_course: status pill passes through unchanged ---

    public function test_trajectory_course_passes_status_pill_through(): void
    {
        $course = $this->createMock(\WP_Post::class);
        $course->ID = 52;
        $course->post_title = 'Cursus C';

        $this->_registerEditionServiceStub([]);

        $pill = ['label' => 'Speciaal', 'tone' => 'accent'];
        $args = stridence_build_course_card_args_from_trajectory_course($course, $pill);

        $this->assertSame($pill, $args['status_pill']);
    }

    /**
     * Register an EditionService stub in the DI container that returns the given
     * edition rows from getEditionsForCourse() and treats can_enroll/canEnroll as truthy.
     */
    private function _registerEditionServiceStub(array $editions): void
    {
        $stub = new class($editions) {
            public function __construct(private array $editions) {}
            public function getEditionsForCourse(int $courseId): array { return $this->editions; }
            public function canEnroll(int $editionId): bool
            {
                foreach ($this->editions as $ed) {
                    if ((int) ($ed['id'] ?? 0) === $editionId) {
                        return (bool) ($ed['can_enroll'] ?? false);
                    }
                }
                return false;
            }
        };
        $this->registerService(\Stride\Modules\Edition\EditionService::class, $stub);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `ddev exec vendor/bin/phpunit --filter CourseCardBuilderTest --testsuite Unit`
Expected: 3 new ERRORS — "Call to undefined function stridence_build_course_card_args_from_trajectory_course()"

- [ ] **Step 3: Implement the trajectory builder**

Append to `web/app/themes/stridence/helpers/templates.php`:

```php
/**
 * Build course-card partial args from a trajectory course WP_Post.
 *
 * Used by `templates/trajectory/course-groups.php` to render each required or
 * elective course as an expandable card. Always produces the 'public' mode
 * (no per-user state, no progress, secondary "Bekijk cursus" CTA only).
 *
 * @param \WP_Post $course      Course post (sfwd-courses)
 * @param array    $statusPill  ['label' => string, 'tone' => 'primary'|'accent']
 * @return array                See course-card.php docblock for the full contract.
 */
function stridence_build_course_card_args_from_trajectory_course(\WP_Post $course, array $statusPill): array
{
    $courseId    = (int) $course->ID;
    $courseTitle = (string) $course->post_title;
    $thumbnailId = (int) get_post_thumbnail_id($courseId);

    // Excerpt: prefer the WP excerpt, fall back to trimmed content
    $excerpt = has_excerpt($courseId)
        ? get_the_excerpt($courseId)
        : wp_trim_words(get_post_field('post_content', $courseId), 25);

    // Upcoming editions via EditionService (DI)
    $editionService    = ntdst_get(\Stride\Modules\Edition\EditionService::class);
    $allEditions       = $editionService->getEditionsForCourse($courseId);
    $upcomingEditions  = [];
    $nextStartDate     = null;

    if (is_array($allEditions)) {
        $editionModel = ntdst_data()->get('vad_edition');
        foreach ($allEditions as $ed) {
            $editionId = (int) ($ed['id'] ?? $ed['ID'] ?? 0);
            if (!$editionId || !$editionService->canEnroll($editionId)) {
                continue;
            }
            $startDate = (string) ($ed['start_date'] ?? $editionModel->getMeta($editionId, 'start_date', ''));
            $venue     = (string) ($ed['venue'] ?? $editionModel->getMeta($editionId, 'venue', ''));
            $upcomingEditions[] = [
                'id'         => $editionId,
                'start_date' => $startDate ?: null,
                'venue'      => $venue ?: null,
            ];
            if ($nextStartDate === null && $startDate) {
                $nextStartDate = $startDate;
            }
            if (count($upcomingEditions) >= 3) {
                break;
            }
        }
    }

    return [
        'course_id'    => $courseId,
        'course_title' => $courseTitle,
        'thumbnail_id' => $thumbnailId ?: null,
        'type'         => 'public',
        'status_pill'  => $statusPill,
        'enrolled'     => false,
        'initial_open' => false,
        'meta'         => [
            'start_date'          => $nextStartDate,
            'venue'               => null,
            'progress_label'      => null,
            'days_remaining'      => null,
            'pending_tasks_count' => null,
        ],
        'body'         => [
            'excerpt'           => $excerpt ?: null,
            'progress_pct'      => null,
            'sessions'          => [],
            'upcoming_editions' => $upcomingEditions,
            'task_summary'      => null,
            'primary_cta'       => null,
            'secondary_cta'     => [
                'url'   => get_permalink($courseId) ?: '',
                'label' => __('Bekijk cursus', 'stridence'),
            ],
        ],
    ];
}
```

- [ ] **Step 4: Run tests to verify all pass**

Run: `ddev exec vendor/bin/phpunit --filter CourseCardBuilderTest --testsuite Unit`
Expected: 8 PASS (5 from Task 2 + 3 new)

- [ ] **Step 5: Commit**

```bash
git add web/app/themes/stridence/helpers/templates.php tests/Unit/CourseCardBuilderTest.php
git commit -m "feat(theme): add from_trajectory_course course-card builder"
```

---

## Task 5: Create the `course-card.php` partial

**Files:**
- Create: `web/app/themes/stridence/templates/components/course-card.php`

- [ ] **Step 1: Create the directory and file**

```bash
mkdir -p web/app/themes/stridence/templates/components
```

- [ ] **Step 2: Write the partial**

Create `web/app/themes/stridence/templates/components/course-card.php`:

```php
<?php
/**
 * Course Card — unified expandable component
 *
 * Renders a collapsible course card. Three modes via $args['type']:
 *   - 'edition'  : enrolled classroom edition (sessions, tasks, CTA)
 *   - 'online'   : enrolled online course (progress bar, resume CTA)
 *   - 'public'   : course in trajectory context (excerpt, upcoming editions)
 *
 * Contract: see docs/superpowers/specs/2026-05-16-unified-course-card-design.md
 *
 * @param array $args See spec for full shape.
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$args = $args ?? [];

$courseId    = (int) ($args['course_id'] ?? 0);
$courseTitle = (string) ($args['course_title'] ?? '');
$thumbnailId = $args['thumbnail_id'] ?? null;
$type        = (string) ($args['type'] ?? 'public');
$statusPill  = $args['status_pill'] ?? null;
$enrolled    = (bool) ($args['enrolled'] ?? false);
$initialOpen = (bool) ($args['initial_open'] ?? false);

$meta = $args['meta'] ?? [];
$body = $args['body'] ?? [];

$startDate         = $meta['start_date'] ?? null;
$venue             = $meta['venue'] ?? null;
$progressLabel     = $meta['progress_label'] ?? null;
$daysRemaining     = $meta['days_remaining'] ?? null;
$pendingTasksCount = (int) ($meta['pending_tasks_count'] ?? 0);

$excerpt          = $body['excerpt'] ?? null;
$progressPct      = $body['progress_pct'] ?? null;
$sessions         = $body['sessions'] ?? [];
$upcomingEditions = $body['upcoming_editions'] ?? [];
$taskSummary      = $body['task_summary'] ?? null;
$primaryCta       = $body['primary_cta'] ?? null;
$secondaryCta     = $body['secondary_cta'] ?? null;

// Pill tone → Tailwind classes
$pillToneClasses = [
    'primary' => 'bg-primary/10 text-primary',
    'accent'  => 'bg-accent/10 text-accent',
    'muted'   => 'bg-surface-alt text-text-muted',
];
$pillClass = $statusPill ? ($pillToneClasses[$statusPill['tone'] ?? 'muted'] ?? $pillToneClasses['muted']) : '';
?>
<div class="card" x-data="expandable(<?php echo $initialOpen ? 'true' : 'false'; ?>)">
    <button type="button"
            class="w-full p-4 flex items-center gap-4 text-left"
            @click="toggle()">
        <!-- Thumbnail -->
        <div class="w-14 h-14 rounded overflow-hidden flex-shrink-0">
            <?php if ($thumbnailId) : ?>
                <?php echo wp_get_attachment_image($thumbnailId, 'thumbnail', false, ['class' => 'w-full h-full object-cover']); ?>
            <?php else : ?>
                <div class="w-full h-full bg-surface-alt flex items-center justify-center">
                    <?php echo stridence_icon('book-open', 'w-6 h-6 text-text-muted'); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Title + meta line -->
        <div class="flex-1 min-w-0">
            <h4 class="font-semibold text-text truncate">
                <?php echo esc_html($courseTitle); ?>
            </h4>
            <div class="flex flex-wrap gap-3 mt-1 text-sm text-text-muted">
                <?php if ($statusPill) : ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium <?php echo esc_attr($pillClass); ?>">
                        <?php echo esc_html($statusPill['label']); ?>
                    </span>
                <?php endif; ?>
                <?php if ($startDate) : ?>
                    <span class="flex items-center gap-1">
                        <?php echo stridence_icon('calendar', 'w-3.5 h-3.5'); ?>
                        <?php echo esc_html(stride_format_date($startDate)); ?>
                    </span>
                <?php endif; ?>
                <?php if ($venue) : ?>
                    <span class="flex items-center gap-1">
                        <?php echo stridence_icon('map-pin', 'w-3.5 h-3.5'); ?>
                        <?php echo esc_html($venue); ?>
                    </span>
                <?php endif; ?>
                <?php if ($progressLabel) : ?>
                    <span><?php echo esc_html($progressLabel); ?></span>
                <?php endif; ?>
                <?php if ($daysRemaining !== null && $daysRemaining > 0 && $daysRemaining <= 30) : ?>
                    <span class="text-status-warning">
                        <?php echo esc_html(sprintf(__('Nog %d dagen', 'stridence'), $daysRemaining)); ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending tasks dot -->
        <?php if ($pendingTasksCount > 0) : ?>
            <span class="w-2 h-2 rounded-full bg-warning shrink-0" title="<?php
                echo esc_attr(sprintf(
                    _n('%d openstaande taak', '%d openstaande taken', $pendingTasksCount, 'stridence'),
                    $pendingTasksCount
                ));
            ?>"></span>
        <?php endif; ?>

        <!-- Chevron -->
        <span class="shrink-0 text-text-muted transition-transform duration-200"
              :class="{ 'rotate-180': open }">
            <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
        </span>
    </button>

    <!-- Expanded body -->
    <div x-show="open" x-collapse class="border-t border-border">
        <div class="p-4 space-y-4">
            <?php if ($excerpt) : ?>
                <p class="text-sm text-text-muted">
                    <?php echo esc_html($excerpt); ?>
                </p>
            <?php endif; ?>

            <?php if ($progressPct !== null) : ?>
                <div>
                    <div class="flex items-center justify-between text-xs text-text-muted mb-1">
                        <?php if ($progressLabel) : ?>
                            <span><?php echo esc_html($progressLabel); ?></span>
                        <?php else : ?>
                            <span><?php esc_html_e('Voortgang', 'stridence'); ?></span>
                        <?php endif; ?>
                        <span><?php echo (int) $progressPct; ?>%</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-surface-alt overflow-hidden">
                        <div class="h-full bg-primary rounded-full" style="width: <?php echo (int) $progressPct; ?>%"></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($sessions)) : ?>
                <div class="space-y-2">
                    <p class="text-xs font-medium text-text-muted uppercase tracking-wide">
                        <?php esc_html_e('Sessies', 'stridence'); ?>
                    </p>
                    <div class="divide-y divide-border rounded-lg border border-border">
                        <?php foreach ($sessions as $s) :
                            $sDate = $s['date'] ?? '';
                            $sStart = $s['start_time'] ?? '';
                            $sEnd = $s['end_time'] ?? '';
                        ?>
                            <div class="p-3 flex items-center gap-3 text-sm text-text-muted">
                                <?php if ($sDate) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                        <?php echo esc_html(stride_format_date($sDate)); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($sStart || $sEnd) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('clock', 'w-4 h-4'); ?>
                                        <?php echo esc_html(trim($sStart . ' – ' . $sEnd, ' –')); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($upcomingEditions)) : ?>
                <div class="space-y-2">
                    <p class="text-xs font-medium text-text-muted uppercase tracking-wide">
                        <?php esc_html_e('Beschikbare edities', 'stridence'); ?>
                    </p>
                    <div class="divide-y divide-border rounded-lg border border-border">
                        <?php foreach ($upcomingEditions as $ed) : ?>
                            <div class="p-3 flex items-center gap-3 text-sm text-text-muted">
                                <?php if (!empty($ed['start_date'])) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('calendar', 'w-4 h-4'); ?>
                                        <?php echo esc_html(stride_format_date($ed['start_date'])); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($ed['venue'])) : ?>
                                    <span class="flex items-center gap-1">
                                        <?php echo stridence_icon('map-pin', 'w-4 h-4'); ?>
                                        <?php echo esc_html($ed['venue']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($type === 'public') : ?>
                <p class="text-sm text-text-muted italic">
                    <?php esc_html_e('Nog geen edities gepland', 'stridence'); ?>
                </p>
            <?php endif; ?>

            <?php if ($taskSummary) :
                $tsTotal = (int) ($taskSummary['total'] ?? 0);
                $tsDone  = (int) ($taskSummary['completed'] ?? 0);
                if ($tsTotal > 0) : ?>
                    <p class="text-sm text-text-muted">
                        <?php echo esc_html(sprintf(
                            __('Taken: %d van %d voltooid', 'stridence'),
                            $tsDone,
                            $tsTotal
                        )); ?>
                    </p>
                <?php endif;
            endif; ?>

            <?php if ($primaryCta || $secondaryCta) : ?>
                <div class="flex flex-wrap gap-3 pt-2">
                    <?php if ($primaryCta) : ?>
                        <a href="<?php echo esc_url($primaryCta['url']); ?>" class="btn-primary text-sm">
                            <?php echo esc_html($primaryCta['label']); ?>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 ml-1'); ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($secondaryCta) : ?>
                        <a href="<?php echo esc_url($secondaryCta['url']); ?>" class="btn-secondary text-sm">
                            <?php echo esc_html($secondaryCta['label']); ?>
                            <?php echo stridence_icon('chevron-right', 'w-4 h-4 ml-1'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
```

- [ ] **Step 3: Smoke-test rendering by hand**

Add a temporary `eval-file` test:

```bash
cat > /tmp/card-smoke.php <<'EOF'
$args = [
    'course_id' => 1,
    'course_title' => 'Smoke test course',
    'thumbnail_id' => null,
    'type' => 'public',
    'status_pill' => ['label' => 'Verplicht', 'tone' => 'primary'],
    'enrolled' => false,
    'initial_open' => false,
    'meta' => ['start_date' => '2026-06-10', 'venue' => null, 'progress_label' => null, 'days_remaining' => null, 'pending_tasks_count' => null],
    'body' => [
        'excerpt' => 'A short description',
        'progress_pct' => null,
        'sessions' => [],
        'upcoming_editions' => [['id' => 100, 'start_date' => '2026-06-10', 'venue' => 'Brussel']],
        'task_summary' => null,
        'primary_cta' => null,
        'secondary_cta' => ['url' => 'https://example.test/', 'label' => 'Bekijk cursus'],
    ],
];
include get_stylesheet_directory() . '/templates/components/course-card.php';
EOF
cp /tmp/card-smoke.php web/app/themes/stridence/_smoke.php
ddev exec wp eval-file web/app/themes/stridence/_smoke.php 2>&1 | head -30
rm web/app/themes/stridence/_smoke.php
```

Expected: HTML output starts with `<div class="card" x-data="expandable(false)">`, ends with `</div>`. No PHP fatal errors.

- [ ] **Step 4: Commit**

```bash
git add web/app/themes/stridence/templates/components/course-card.php
git commit -m "feat(theme): add unified course-card partial"
```

---

## Task 6: Swap `tab-home.php` Opleidingen section to use the partial

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-home.php:167-300`

- [ ] **Step 1: Read the current Opleidingen section**

Read `web/app/themes/stridence/templates/dashboard/tab-home.php` lines 165–305 to confirm the foreach loop boundaries before editing.

- [ ] **Step 2: Replace the foreach body**

Find this section starting around line 168:

```php
<?php if (!empty($enrollments)) : ?>
    <section>
        <div class="flex items-center justify-between mb-3">
            ...
            <h3 ... >Opleidingen</h3>
            ...
        </div>
        <div class="space-y-4">
            <?php foreach (array_slice($enrollmentsJson, 0, 4) as $enrollment) :
                $eType       = $enrollment['type'] ?? 'edition';
                ...
                // ~120 lines of inline card markup
                ...
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
```

Replace the foreach loop body (everything between `<?php foreach (array_slice(...)) :` and `<?php endforeach; ?>`) with:

```php
<?php foreach (array_slice($enrollmentsJson, 0, 4) as $i => $enrollment) :
    $args = stridence_build_course_card_args_from_enrollment($enrollment);
    $args['initial_open'] = ($i === 0);
    get_template_part('templates/components/course-card', null, $args);
endforeach; ?>
```

Verify the surrounding `<div class="space-y-4">` and `</section>` tags remain.

- [ ] **Step 3: Visual smoke test**

Visit `https://stride.ddev.site/mijn-account/` as `seed_student1` (test-login URL in CLAUDE.md). Confirm:
- Opleidingen section renders cards
- First card is expanded by default
- Other cards collapsed
- Click expanding works
- No console errors

Capture a screenshot to `/tmp/dashboard-home-after.png` for comparison.

- [ ] **Step 4: Run existing tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit && ddev exec vendor/bin/phpunit --testsuite Integration`
Expected: all green (no Unit/Integration tests target dashboard view layer; this is sanity).

- [ ] **Step 5: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-home.php
git commit -m "refactor(dashboard): tab-home Opleidingen uses course-card partial"
```

---

## Task 7: Swap `tab-inschrijvingen.php` to use the partial

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php`

- [ ] **Step 1: Read the file structure**

Open `web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php` and locate the three section loops:
- `active_editions` foreach (around lines 40–130)
- `active_online` foreach (around lines 130–220)
- `completed_items` foreach (around lines 220–290)
- `cancelled_editions` foreach (around lines 290–310) — **leave unchanged**

- [ ] **Step 2: Replace active_editions loop**

In the `active_editions` section, replace the entire foreach body with:

```php
<?php foreach ($active_editions as $i => $reg) :
    $args = stridence_build_course_card_args_from_enrollment($reg);
    $args['initial_open'] = ($i === 0);
    get_template_part('templates/components/course-card', null, $args);
endforeach; ?>
```

- [ ] **Step 3: Replace active_online loop**

In the `active_online` section, replace the entire foreach body with:

```php
<?php foreach ($active_online as $i => $reg) :
    $args = stridence_build_course_card_args_from_enrollment($reg);
    // First online card auto-expands ONLY if active_editions is empty
    $args['initial_open'] = ($i === 0 && empty($active_editions));
    get_template_part('templates/components/course-card', null, $args);
endforeach; ?>
```

- [ ] **Step 4: Replace completed_items loop**

In the `completed_items` section, replace the foreach body with:

```php
<?php foreach ($completed_items as $reg) :
    $args = stridence_build_course_card_args_from_enrollment($reg, completed: true);
    get_template_part('templates/components/course-card', null, $args);
endforeach; ?>
```

- [ ] **Step 5: Confirm cancelled_editions section is untouched**

Verify `cancelled_editions` section is rendered exactly as before — no card-partial call.

- [ ] **Step 6: Visual smoke test**

Visit `https://stride.ddev.site/mijn-account/?tab=inschrijvingen` as `seed_student1`. Confirm:
- Klassikale opleidingen section renders cards, first one expanded
- Online cursussen section renders cards, all collapsed (since editions are not empty)
- Voltooid section renders cards, all collapsed, no primary CTA
- Cancelled section still renders its minimal layout

- [ ] **Step 7: Run tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit && ddev exec vendor/bin/phpunit --testsuite Integration`
Expected: all green.

- [ ] **Step 8: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-inschrijvingen.php
git commit -m "refactor(dashboard): tab-inschrijvingen uses course-card partial"
```

---

## Task 8: Swap `course-groups.php` (trajectory) to use the partial

**Files:**
- Modify: `web/app/themes/stridence/templates/trajectory/course-groups.php`

- [ ] **Step 1: Replace the required-courses foreach body**

In the file, find the required courses foreach (around line 34). Replace its body (everything from `$course_id = $course->ID;` through the closing `</div>` of the card) with:

```php
<?php foreach ($requiredCourses as $course) :
    $args = stridence_build_course_card_args_from_trajectory_course(
        $course,
        ['label' => __('Verplicht', 'stridence'), 'tone' => 'primary']
    );
    get_template_part('templates/components/course-card', null, $args);
endforeach; ?>
```

- [ ] **Step 2: Replace the elective-courses foreach body**

In the elective groups section (around line 173), replace each inner foreach body with:

```php
<?php foreach ($courses as $course) :
    $args = stridence_build_course_card_args_from_trajectory_course(
        $course,
        ['label' => __('Keuzevak', 'stridence'), 'tone' => 'accent']
    );
    get_template_part('templates/components/course-card', null, $args);
endforeach; ?>
```

- [ ] **Step 3: Remove the now-unused `use Stride\Modules\Edition\EditionService;` import at the top**

The builder uses `ntdst_get()` internally; the template no longer needs the use statement. Remove line 15.

Also remove the `$editionService = ntdst_get(EditionService::class);` line (around line 21) — no longer referenced.

- [ ] **Step 4: Visual smoke test**

Find a trajectory slug: `ddev wp post list --post_type=vad_trajectory --post_status=publish --format=table --fields=ID,post_name | head -3`

Visit `https://stride.ddev.site/trajecten/{slug}/`. Confirm:
- Required + elective sections render
- Cards render with title, thumbnail/icon, status pill
- Expand reveals excerpt + upcoming editions list
- "Bekijk cursus" link works

- [ ] **Step 5: Run all tests**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit && ddev exec vendor/bin/phpunit --testsuite Integration`
Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add web/app/themes/stridence/templates/trajectory/course-groups.php
git commit -m "refactor(trajectory): course-groups uses course-card partial"
```

---

## Task 9: Acceptance test — dashboard home auto-expand + click

**Files:**
- Modify or create: `tests/acceptance/DashboardCest.php` (check if exists; if not, create)

- [ ] **Step 1: Check for existing DashboardCest**

```bash
ls tests/acceptance/DashboardCest.php 2>/dev/null || echo "DOES NOT EXIST"
```

If it does not exist, create a new file. If it does, append the new method.

- [ ] **Step 2: Write the failing acceptance test**

If creating new, full file:

```php
<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for the unified course-card on the dashboard.
 */
class DashboardCest
{
    /**
     * SCENARIO: Dashboard home Opleidingen — first card auto-expands.
     *
     *   GIVEN: seed_student1 has at least 2 active enrollments
     *   WHEN:  visiting /mijn-account/ (tab=home)
     *   THEN:  the first course card body is visible without clicking;
     *          the second card body is hidden until clicked.
     */
    public function courseCardOnHomeAutoExpandsFirst(AcceptanceTester $I): void
    {
        $I->wantTo('verify the first course card auto-expands on the dashboard home');

        // Login as seed_student1 (existing seed user, multiple enrollments)
        $userId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'seed_student1']);
        $I->loginAsUserId($userId, '/mijn-account/');

        $I->waitForElement('section', 5);

        // Find all course cards inside the Opleidingen section.
        // Each card uses x-data="expandable(...)".
        $cardCount = $I->executeJS("return document.querySelectorAll('[x-data^=\"expandable\"]').length;");
        $I->assertGreaterThanOrEqual(2, $cardCount, 'expected at least 2 course cards for this test');

        // First card body must be visible (open:true)
        $firstOpen = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            return Alpine.\$data(card).open;
        ");
        $I->assertTrue($firstOpen, 'first card should be open by default');

        // Second card body must be hidden (open:false)
        $secondOpen = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[1];
            return Alpine.\$data(card).open;
        ");
        $I->assertFalse($secondOpen, 'second card should be closed by default');

        // Click the second card's header — body becomes visible
        $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[1];
            card.querySelector('button').click();
        ");
        $I->wait(1);

        $secondOpenAfterClick = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[1];
            return Alpine.\$data(card).open;
        ");
        $I->assertTrue($secondOpenAfterClick, 'second card should be open after clicking its header');
    }
}
```

- [ ] **Step 3: Regenerate Codeception support files**

Run: `ddev exec vendor/bin/codecept build`
Expected: "AcceptanceTesterActions.php generated successfully" — no errors.

- [ ] **Step 4: Run the new test**

Run: `ddev exec vendor/bin/codecept run acceptance DashboardCest --no-colors`
Expected: PASS.

If the test fails because seed_student1 has too few enrollments, run `ddev exec wp eval-file scripts/seed.php` to refresh seed data first.

- [ ] **Step 5: Commit**

```bash
git add tests/acceptance/DashboardCest.php tests/_support/_generated/AcceptanceTesterActions.php
git commit -m "test(acceptance): course-card auto-expands first card on dashboard home"
```

---

## Task 10: Acceptance test — inschrijvingen tab + trajectory detail

**Files:**
- Modify: `tests/acceptance/DashboardCest.php` (add second method)
- Create: `tests/acceptance/TrajectoryCest.php`

- [ ] **Step 1: Add inschrijvingen test to DashboardCest**

Append this method inside the `DashboardCest` class:

```php
    /**
     * SCENARIO: Mijn opleidingen tab — first active card auto-expands.
     *
     *   GIVEN: seed_student1 has at least 1 active classroom edition
     *   WHEN:  visiting /mijn-account/?tab=inschrijvingen
     *   THEN:  the first card in Klassikale opleidingen is expanded;
     *          its body contains either a session list or task summary.
     */
    public function courseCardOnInschrijvingenTabAutoExpandsFirst(AcceptanceTester $I): void
    {
        $I->wantTo('verify the first card on inschrijvingen tab auto-expands');

        $userId = (int) $I->grabFromDatabase('stride_users', 'ID', ['user_login' => 'seed_student1']);
        $I->loginAsUserId($userId, '/mijn-account/?tab=inschrijvingen');

        $I->waitForElement('section', 5);

        $firstOpen = $I->executeJS("
            const cards = document.querySelectorAll('[x-data^=\"expandable\"]');
            if (cards.length === 0) return null;
            return Alpine.\$data(cards[0]).open;
        ");
        $I->assertTrue($firstOpen, 'first card on inschrijvingen tab should be open by default');
    }
```

- [ ] **Step 2: Create TrajectoryCest**

```php
<?php

declare(strict_types=1);

use Tests\Support\AcceptanceTester;

/**
 * Acceptance tests for trajectory detail page rendering.
 */
class TrajectoryCest
{
    /**
     * SCENARIO: Trajectory detail course card expands on click.
     *
     *   GIVEN: a published trajectory with linked courses
     *   WHEN:  visiting /trajecten/{slug}/ and clicking the first course card header
     *   THEN:  the card body becomes visible.
     */
    public function courseCardOnTrajectoryDetailExpandsOnClick(AcceptanceTester $I): void
    {
        $I->wantTo('verify trajectory detail course cards expand on click');

        // Find any published trajectory slug
        $trajectoryId = (int) $I->grabFromDatabase('stride_posts', 'ID', [
            'post_type'   => 'vad_trajectory',
            'post_status' => 'publish',
        ]);
        if ($trajectoryId === 0) {
            $I->markTestSkipped('No published trajectories — skip this acceptance check.');
            return;
        }
        $slug = (string) $I->grabFromDatabase('stride_posts', 'post_name', ['ID' => $trajectoryId]);

        $I->amOnPage('/trajecten/' . $slug . '/');
        $I->waitForElement('[x-data^="expandable"]', 5);

        // Collapsed state confirmed
        $firstOpen = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            return Alpine.\$data(card).open;
        ");
        $I->assertFalse($firstOpen, 'trajectory cards should start collapsed');

        // Click to expand
        $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            card.querySelector('button').click();
        ");
        $I->wait(1);

        $openAfterClick = $I->executeJS("
            const card = document.querySelectorAll('[x-data^=\"expandable\"]')[0];
            return Alpine.\$data(card).open;
        ");
        $I->assertTrue($openAfterClick, 'trajectory card should be open after clicking');
    }
}
```

- [ ] **Step 3: Regenerate support + run new tests**

Run: `ddev exec vendor/bin/codecept build && ddev exec vendor/bin/codecept run acceptance DashboardCest:courseCardOnInschrijvingenTabAutoExpandsFirst,TrajectoryCest --no-colors`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests/acceptance/DashboardCest.php tests/acceptance/TrajectoryCest.php tests/_support/_generated/AcceptanceTesterActions.php
git commit -m "test(acceptance): course-card expand behaviour on inschrijvingen + trajectory"
```

---

## Task 11: Full suite verification + visual sanity

**Files:** none — verification only

- [ ] **Step 1: Run all suites**

Run, in this order:

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --testsuite Integration
ddev exec vendor/bin/codecept run acceptance --no-colors
```

Expected: all green. Record final counts in commit message of Task 12.

- [ ] **Step 2: Visual sanity via chrome-devtools**

Using `chrome-devtools` MCP, screenshot:
1. `/mijn-account/` as seed_student1
2. `/mijn-account/?tab=inschrijvingen` as seed_student1
3. `/trajecten/{slug}/` (any published trajectory)

For each:
- Card layout looks consistent with the design (collapsed header + chevron)
- No layout breakage on mobile breakpoint (≤640px)
- Expand animation works (no instant jump)

- [ ] **Step 3: Cross-check client mu-plugin overrides**

```bash
grep -rln "tab-home\|tab-inschrijvingen\|course-groups\|course-card" web/app/mu-plugins/stride-client-* 2>/dev/null
```

If any matches: review them. Clients may have copied old template markup. Update or note as follow-up.

- [ ] **Step 4: Commit if any client-plugin updates were needed**

If client templates needed an update:

```bash
git add web/app/mu-plugins/stride-client-*
git commit -m "fix(client): align client template overrides with course-card partial"
```

If no changes needed, no commit.

---

## Task 12: Update memory + close out

**Files:**
- Modify: `memory/STATE.md`
- Modify: `tasks/todo.md` if relevant

- [ ] **Step 1: Update STATE.md**

Append to the "What happened" section in `memory/STATE.md`:

```markdown
- `2026-05-16` — Unified course-card partial shipped. Consolidates 3 duplicate
  implementations (tab-home Opleidingen, tab-inschrijvingen, trajectory course-groups)
  into `templates/components/course-card.php` + two builder helpers in
  `helpers/templates.php`. First card in each dashboard list auto-expands.
  Tests: 8 new unit tests for builders + 3 new acceptance tests for expand/collapse.
  Spec: `docs/superpowers/specs/2026-05-16-unified-course-card-design.md`.
  Plan: `docs/superpowers/plans/2026-05-16-unified-course-card.md`.
```

Update the "Last refresh:" line at the top of STATE.md.

- [ ] **Step 2: Final commit**

```bash
git add memory/STATE.md
git commit -m "memory(state): unified course card shipped"
```

- [ ] **Step 3: Done**

Course card consolidation complete. Three call-sites unified, no architectural regression, full suite green.

---

## Self-Review

Skimmed the spec section-by-section against the plan:

- **Architecture/component contract** → Task 5 builds the partial matching the contract; Tasks 3, 4 produce data matching it. ✓
- **Builder functions** → Tasks 2, 3, 4 cover both with full unit coverage. ✓
- **Call-site changes (3 sites)** → Tasks 6, 7, 8. ✓
- **Auto-expand behaviour (first card only)** → Tasks 6, 7 set `initial_open` per index. Inschrijvingen has the active_online conditional auto-expand. ✓
- **Cancelled section unchanged** → Task 7 step 5 explicit. ✓
- **Testing** → Unit tests in Tasks 2/4. Three acceptance tests in Tasks 9/10. Visual sanity in Task 11. ✓
- **Alpine factory needs initialOpen** → Task 1. ✓
- **Empty states** → handled inside partial in Task 5 step 2. ✓
- **Risks: client-plugin overrides** → Task 11 step 3. ✓

No placeholders, no TBDs, no "similar to Task N" stubs. Each step has either exact code or an exact command. Type/signature consistency: `stridence_build_course_card_args_from_enrollment` and `stridence_build_course_card_args_from_trajectory_course` are used identically wherever they appear.
