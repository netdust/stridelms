# Personal Trajectory Dashboard Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a detailed dashboard page for enrolled trajectory participants at `/mijn-account/trajecten/{slug}/` with tabs for progress, elective choices, materials, and supervisor messages.

**Architecture:** Extend existing dashboard template with slug detection via rewrite rules. Create TrajectoryDashboardService in theme for data aggregation. Add messages metabox to trajectory admin using existing notes pattern.

**Tech Stack:** WordPress rewrite API, PHP 8.1+, Tailwind CSS, Alpine.js, NTDST framework services

---

## Task 1: Register Rewrite Rules and Query Var

**Files:**
- Modify: `web/app/themes/stridence/functions.php`

**Step 1: Add rewrite rule registration**

In `functions.php`, add after existing theme setup:

```php
// Personal trajectory dashboard routing
add_action('init', function (): void {
    add_rewrite_rule(
        '^mijn-account/trajecten/([^/]+)/?$',
        'index.php?pagename=mijn-account&trajectory_slug=$matches[1]',
        'top'
    );
}, 10);

add_filter('query_vars', function (array $vars): array {
    $vars[] = 'trajectory_slug';
    return $vars;
});
```

**Step 2: Flush rewrite rules**

Run: `ddev exec wp rewrite flush`
Expected: Success message

**Step 3: Commit**

```bash
git add web/app/themes/stridence/functions.php
git commit -m "feat(stridence): add trajectory dashboard rewrite rules"
```

---

## Task 2: Create TrajectoryDashboardService

**Files:**
- Create: `web/app/themes/stridence/services/frontend/TrajectoryDashboardService.php`
- Modify: `web/app/themes/stridence/theme-config.php`

**Step 1: Create the service class**

```php
<?php
/**
 * Trajectory Dashboard Service
 *
 * Aggregates data for personal trajectory dashboard display.
 * Frontend-only service in theme.
 *
 * @package stridence
 */

declare(strict_types=1);

namespace stride\services\frontend;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\TrajectoryMode;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Trajectory\TrajectoryService;
use WP_Error;
use WP_Post;

class TrajectoryDashboardService implements \NTDST_Service_Meta
{
    public function __construct(
        private readonly TrajectoryService $trajectoryService,
        private readonly RegistrationRepository $registrationRepo,
        private readonly EditionService $editionService,
        private readonly LMSAdapterInterface $lmsAdapter,
    ) {
        $this->init();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Trajectory Dashboard',
            'description' => 'Data aggregation for personal trajectory dashboard',
            'priority' => 20,
        ];
    }

    private function init(): void
    {
        // No hooks needed - pure data service
    }

    /**
     * Get trajectory by slug.
     *
     * @return WP_Post|null
     */
    public function getTrajectoryBySlug(string $slug): ?WP_Post
    {
        $posts = get_posts([
            'post_type' => 'vad_trajectory',
            'name' => $slug,
            'post_status' => 'publish',
            'posts_per_page' => 1,
        ]);

        return $posts[0] ?? null;
    }

    /**
     * Get user's enrollment for a trajectory.
     *
     * @return object|null Registration row or null
     */
    public function getEnrollmentForUser(int $userId, int $trajectoryId): ?object
    {
        $enrollments = $this->registrationRepo->findTrajectoryEnrollmentsByUser($userId);

        foreach ($enrollments as $enrollment) {
            if ((int) $enrollment->trajectory_id === $trajectoryId) {
                return $enrollment;
            }
        }

        return null;
    }

    /**
     * Get progress data for a user's trajectory enrollment.
     *
     * @return array{
     *   required_courses: array,
     *   elective_groups: array,
     *   completed_count: int,
     *   in_progress_count: int,
     *   total_required: int,
     *   mode: TrajectoryMode,
     *   edition_registrations: array
     * }
     */
    public function getProgressData(int $userId, int $trajectoryId): array
    {
        $trajectory = $this->trajectoryService->getTrajectory($trajectoryId);
        $mode = TrajectoryMode::tryFrom($trajectory['mode'] ?? '') ?? TrajectoryMode::Cohort;

        $requiredCourses = $this->trajectoryService->getRequiredCourses($trajectoryId);
        $electiveGroups = $this->trajectoryService->getElectiveGroups($trajectoryId);

        // Calculate required count
        $totalRequired = count($requiredCourses);
        foreach ($electiveGroups as $group) {
            $totalRequired += (int) ($group['required'] ?? 0);
        }

        // Get user's edition registrations for this trajectory
        $editionRegs = $this->registrationRepo->findEditionsByTrajectory($userId, $trajectoryId);

        // Calculate completion
        $completedCourses = [];
        $inProgressCourses = [];

        foreach ($requiredCourses as $course) {
            $this->checkCourseStatus(
                $userId,
                $course->ID,
                $editionRegs,
                $completedCourses,
                $inProgressCourses
            );
        }

        foreach ($electiveGroups as $group) {
            foreach ($group['courses'] ?? [] as $course) {
                $this->checkCourseStatus(
                    $userId,
                    $course->ID,
                    $editionRegs,
                    $completedCourses,
                    $inProgressCourses
                );
            }
        }

        return [
            'required_courses' => $requiredCourses,
            'elective_groups' => $electiveGroups,
            'completed_count' => count(array_unique($completedCourses)),
            'in_progress_count' => count(array_unique($inProgressCourses)),
            'total_required' => $totalRequired,
            'mode' => $mode,
            'edition_registrations' => $editionRegs,
            'completed_courses' => $completedCourses,
            'in_progress_courses' => $inProgressCourses,
        ];
    }

    /**
     * Check course completion/progress status.
     */
    private function checkCourseStatus(
        int $userId,
        int $courseId,
        array $editionRegs,
        array &$completedCourses,
        array &$inProgressCourses
    ): void {
        if ($this->lmsAdapter->isComplete($userId, $courseId)) {
            $completedCourses[] = $courseId;
            return;
        }

        // Check if enrolled in any edition for this course
        foreach ($editionRegs as $edReg) {
            $edCourseId = $this->editionService->getCourseId((int) $edReg->edition_id);
            if ($edCourseId === $courseId) {
                $inProgressCourses[] = $courseId;
                return;
            }
        }
    }

    /**
     * Get course materials for trajectory.
     *
     * @return array Array of course data with materials
     */
    public function getMaterials(int $trajectoryId, int $userId): array
    {
        $requiredCourses = $this->trajectoryService->getRequiredCourses($trajectoryId);
        $electiveGroups = $this->trajectoryService->getElectiveGroups($trajectoryId);

        $allCourses = $requiredCourses;
        foreach ($electiveGroups as $group) {
            foreach ($group['courses'] ?? [] as $course) {
                $allCourses[] = $course;
            }
        }

        $materials = [];
        foreach ($allCourses as $course) {
            // Check if user has access (via any edition enrollment)
            if (!$this->lmsAdapter->hasAccess($userId, $course->ID)) {
                continue;
            }

            $courseMeta = get_post_meta($course->ID, '_sfwd-courses', true);
            $courseMaterials = $courseMeta['sfwd-courses_course_materials'] ?? '';

            if (empty($courseMaterials)) {
                continue;
            }

            $materials[] = [
                'course_id' => $course->ID,
                'title' => $course->post_title,
                'materials' => $courseMaterials,
            ];
        }

        return $materials;
    }

    /**
     * Get messages/announcements for trajectory.
     *
     * @return array Array of message objects
     */
    public function getMessages(int $trajectoryId): array
    {
        $messages = get_post_meta($trajectoryId, 'trajectory_messages', true);

        if (empty($messages) || !is_array($messages)) {
            return [];
        }

        // Filter out deleted messages and sort by date descending
        $messages = array_filter($messages, fn($m) => empty($m['_deleted']));
        usort($messages, fn($a, $b) => strtotime($b['date'] ?? '') - strtotime($a['date'] ?? ''));

        return $messages;
    }
}
```

**Step 2: Register service in theme-config.php**

Add to the services array:

```php
'services' => [
    'frontend' => [
        \stride\services\frontend\TrajectoryDashboardService::class,
        // ... existing services
    ],
],
```

**Step 3: Commit**

```bash
git add web/app/themes/stridence/services/frontend/TrajectoryDashboardService.php
git add web/app/themes/stridence/theme-config.php
git commit -m "feat(stridence): add TrajectoryDashboardService"
```

---

## Task 3: Modify page-mijn-account.php for Trajectory Slug Detection

**Files:**
- Modify: `web/app/themes/stridence/page-mijn-account.php`

**Step 1: Add trajectory slug detection after auth check**

After the authentication check (line ~20), add:

```php
// Check for personal trajectory dashboard route
$trajectory_slug = get_query_var('trajectory_slug');
if (!empty($trajectory_slug)) {
    get_template_part('templates/trajectory/dashboard', null, [
        'trajectory_slug' => sanitize_title($trajectory_slug),
        'user' => $user,
    ]);
    get_footer();
    exit;
}
```

The full modified section should look like:

```php
// Require authentication
if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url(get_permalink()));
    exit;
}

$user = wp_get_current_user();

// Check for personal trajectory dashboard route
$trajectory_slug = get_query_var('trajectory_slug');
if (!empty($trajectory_slug)) {
    get_header();
    get_template_part('templates/trajectory/dashboard', null, [
        'trajectory_slug' => sanitize_title($trajectory_slug),
        'user' => $user,
    ]);
    get_footer();
    exit;
}

// Get current tab from URL (default: inschrijvingen)
$valid_tabs = ['inschrijvingen', 'trajecten', 'offertes', 'certificaten', 'profiel'];
// ... rest of file
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/page-mijn-account.php
git commit -m "feat(stridence): detect trajectory slug in dashboard"
```

---

## Task 4: Create Trajectory Dashboard Shell Template

**Files:**
- Create: `web/app/themes/stridence/templates/trajectory/dashboard.php`

**Step 1: Create the dashboard shell**

```php
<?php
/**
 * Personal Trajectory Dashboard
 *
 * Main shell for user's enrolled trajectory view with tabbed navigation.
 * Validates enrollment and loads appropriate tab content.
 *
 * @param array $args {
 *     @type string $trajectory_slug Trajectory post slug
 *     @type WP_User $user Current user
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use stride\services\frontend\TrajectoryDashboardService;

$trajectory_slug = $args['trajectory_slug'] ?? '';
$user = $args['user'] ?? wp_get_current_user();

if (empty($trajectory_slug)) {
    wp_safe_redirect(add_query_arg('tab', 'trajecten', get_permalink()));
    exit;
}

// Get service and trajectory
$dashboardService = ntdst_get(TrajectoryDashboardService::class);
$trajectory = $dashboardService->getTrajectoryBySlug($trajectory_slug);

// 404 if trajectory not found
if (!$trajectory) {
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    get_template_part('404');
    exit;
}

// Check enrollment
$enrollment = $dashboardService->getEnrollmentForUser($user->ID, $trajectory->ID);

if (!$enrollment) {
    // Not enrolled - redirect to public trajectory page
    wp_safe_redirect(get_permalink($trajectory->ID));
    exit;
}

// Get tab state
$valid_tabs = ['voortgang', 'keuzes', 'materialen', 'berichten'];
$current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'voortgang';

if (!in_array($current_tab, $valid_tabs, true)) {
    $current_tab = 'voortgang';
}

// Build back URL
$dashboard_url = get_permalink(get_page_by_path('mijn-account'));
$trajecten_tab_url = add_query_arg('tab', 'trajecten', $dashboard_url);

// Tab definitions
$tabs = [
    'voortgang' => [
        'label' => __('Voortgang', 'stridence'),
        'icon' => 'trending-up',
    ],
    'keuzes' => [
        'label' => __('Keuzes', 'stridence'),
        'icon' => 'check-square',
    ],
    'materialen' => [
        'label' => __('Materialen', 'stridence'),
        'icon' => 'file-text',
    ],
    'berichten' => [
        'label' => __('Berichten', 'stridence'),
        'icon' => 'bell',
    ],
];
?>

<div class="min-h-screen bg-surface-alt pb-20 lg:pb-0">
    <!-- Page Header -->
    <div class="bg-surface border-b border-border">
        <div class="container py-6 lg:py-8">
            <!-- Back link -->
            <a href="<?php echo esc_url($trajecten_tab_url); ?>"
               class="inline-flex items-center gap-1 text-sm text-text-muted hover:text-primary mb-4">
                <?php echo stridence_icon('chevron-left', 'w-4 h-4'); ?>
                <?php esc_html_e('Terug naar trajecten', 'stridence'); ?>
            </a>

            <div class="flex items-start gap-4">
                <div class="w-12 h-12 lg:w-16 lg:h-16 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
                    <?php echo stridence_icon('layers', 'w-6 h-6 lg:w-8 lg:h-8 text-primary'); ?>
                </div>
                <div>
                    <h1 class="font-heading text-xl lg:text-2xl font-bold text-text">
                        <?php echo esc_html($trajectory->post_title); ?>
                    </h1>
                    <p class="text-sm text-text-muted mt-1">
                        <?php
                        printf(
                            /* translators: %s: enrollment date */
                            esc_html__('Ingeschreven sinds %s', 'stridence'),
                            esc_html(date_i18n('j F Y', strtotime($enrollment->registered_at)))
                        );
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="bg-surface border-b border-border sticky top-0 z-30">
        <div class="container">
            <nav class="flex gap-1 overflow-x-auto -mb-px" aria-label="<?php esc_attr_e('Traject navigatie', 'stridence'); ?>">
                <?php foreach ($tabs as $slug => $tab) :
                    $is_active = ($current_tab === $slug);
                    $url = add_query_arg('tab', $slug);

                    $classes = $is_active
                        ? 'flex items-center gap-2 px-4 py-3 text-sm font-medium text-primary border-b-2 border-primary whitespace-nowrap'
                        : 'flex items-center gap-2 px-4 py-3 text-sm font-medium text-text-muted hover:text-text border-b-2 border-transparent whitespace-nowrap';
                ?>
                    <a href="<?php echo esc_url($url); ?>"
                       class="<?php echo esc_attr($classes); ?>"
                       <?php echo $is_active ? 'aria-current="page"' : ''; ?>>
                        <?php echo stridence_icon($tab['icon'], 'w-4 h-4'); ?>
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
    </div>

    <!-- Tab Content -->
    <div class="container py-6 lg:py-8">
        <?php
        get_template_part("templates/trajectory/tab-{$current_tab}", null, [
            'trajectory' => $trajectory,
            'enrollment' => $enrollment,
            'user' => $user,
            'dashboard_service' => $dashboardService,
        ]);
        ?>
    </div>
</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/trajectory/dashboard.php
git commit -m "feat(stridence): add trajectory dashboard shell template"
```

---

## Task 5: Create Voortgang (Progress) Tab

**Files:**
- Create: `web/app/themes/stridence/templates/trajectory/tab-voortgang.php`

**Step 1: Create the progress tab template**

```php
<?php
/**
 * Trajectory Tab: Voortgang (Progress)
 *
 * Shows overall progress bar and course completion status.
 *
 * @param array $args {
 *     @type WP_Post $trajectory
 *     @type object $enrollment
 *     @type WP_User $user
 *     @type TrajectoryDashboardService $dashboard_service
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Contracts\LMSAdapterInterface;
use Stride\Domain\TrajectoryMode;
use stride\services\frontend\TrajectoryDashboardService;

$trajectory = $args['trajectory'];
$enrollment = $args['enrollment'];
$user = $args['user'];
$dashboardService = $args['dashboard_service'];

// Get progress data
$progress = $dashboardService->getProgressData($user->ID, $trajectory->ID);
$lmsAdapter = ntdst_get(LMSAdapterInterface::class);

$completedCount = $progress['completed_count'];
$totalRequired = $progress['total_required'];
$progressPercent = $totalRequired > 0 ? round(($completedCount / $totalRequired) * 100) : 0;
?>

<div class="space-y-8">
    <!-- Progress Overview Card -->
    <div class="card p-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-lg font-semibold text-text">
                    <?php esc_html_e('Voortgang', 'stridence'); ?>
                </h2>
                <p class="text-sm text-text-muted">
                    <?php
                    printf(
                        esc_html__('%d van %d cursussen afgerond', 'stridence'),
                        $completedCount,
                        $totalRequired
                    );
                    ?>
                </p>
            </div>
            <div class="flex items-center gap-2">
                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-full text-xs font-medium <?php echo $progress['mode'] === TrajectoryMode::Cohort ? 'bg-primary/10 text-primary' : 'bg-accent/10 text-accent'; ?>">
                    <?php echo $progress['mode'] === TrajectoryMode::Cohort
                        ? esc_html__('Cohort', 'stridence')
                        : esc_html__('Zelfgestuurd', 'stridence'); ?>
                </span>
            </div>
        </div>

        <!-- Progress Bar -->
        <?php
        get_template_part('partials/progress-bar', null, [
            'attended' => $completedCount,
            'required' => $totalRequired,
            'label' => __('Totale voortgang', 'stridence'),
        ]);
        ?>
    </div>

    <!-- Required Courses -->
    <?php if (!empty($progress['required_courses'])) : ?>
        <section>
            <h3 class="text-sm font-medium text-text-muted uppercase tracking-wide mb-3">
                <?php esc_html_e('Verplichte cursussen', 'stridence'); ?>
            </h3>
            <div class="card divide-y divide-border">
                <?php foreach ($progress['required_courses'] as $course) :
                    $isComplete = $lmsAdapter->isComplete($user->ID, $course->ID);
                    $isInProgress = in_array($course->ID, $progress['in_progress_courses'], true);
                ?>
                    <div class="p-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <?php if ($isComplete) : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-success/10 flex items-center justify-center">
                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                </span>
                            <?php elseif ($isInProgress) : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center">
                                    <?php echo stridence_icon('clock', 'w-4 h-4 text-accent'); ?>
                                </span>
                            <?php else : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-border flex items-center justify-center">
                                    <span class="w-2 h-2 rounded-full bg-text-muted"></span>
                                </span>
                            <?php endif; ?>

                            <div class="min-w-0">
                                <h4 class="font-medium truncate <?php echo $isComplete ? 'text-text-muted line-through' : 'text-text'; ?>">
                                    <?php echo esc_html($course->post_title); ?>
                                </h4>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php if ($isComplete) : ?>
                                <span class="text-xs text-success font-medium">
                                    <?php esc_html_e('Afgerond', 'stridence'); ?>
                                </span>
                            <?php elseif ($isInProgress) : ?>
                                <a href="<?php echo esc_url(get_permalink($course)); ?>"
                                   class="btn-primary text-xs">
                                    <?php esc_html_e('Verder', 'stridence'); ?>
                                </a>
                            <?php else : ?>
                                <span class="text-xs text-text-muted">
                                    <?php esc_html_e('Nog te starten', 'stridence'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Elective Groups -->
    <?php foreach ($progress['elective_groups'] as $group) :
        $groupName = $group['name'] ?? __('Keuzecursussen', 'stridence');
        $groupRequired = (int) ($group['required'] ?? 0);
        $courses = $group['courses'] ?? [];

        if (empty($courses)) {
            continue;
        }

        // Count completed in this group
        $groupCompleted = 0;
        foreach ($courses as $course) {
            if ($lmsAdapter->isComplete($user->ID, $course->ID)) {
                $groupCompleted++;
            }
        }
    ?>
        <section>
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-medium text-text-muted uppercase tracking-wide">
                    <?php echo esc_html($groupName); ?>
                </h3>
                <?php if ($groupRequired > 0) : ?>
                    <span class="text-sm font-medium <?php echo $groupCompleted >= $groupRequired ? 'text-success' : 'text-text'; ?>">
                        <?php printf(esc_html__('%d/%d vereist', 'stridence'), $groupCompleted, $groupRequired); ?>
                    </span>
                <?php endif; ?>
            </div>

            <div class="card divide-y divide-border">
                <?php foreach ($courses as $course) :
                    $isComplete = $lmsAdapter->isComplete($user->ID, $course->ID);
                    $isInProgress = in_array($course->ID, $progress['in_progress_courses'], true);
                ?>
                    <div class="p-4 flex items-center justify-between gap-4">
                        <div class="flex items-center gap-3 min-w-0">
                            <?php if ($isComplete) : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-success/10 flex items-center justify-center">
                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                </span>
                            <?php elseif ($isInProgress) : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-accent/10 flex items-center justify-center">
                                    <?php echo stridence_icon('clock', 'w-4 h-4 text-accent'); ?>
                                </span>
                            <?php else : ?>
                                <span class="shrink-0 w-8 h-8 rounded-full bg-border flex items-center justify-center">
                                    <span class="w-2 h-2 rounded-full bg-text-muted"></span>
                                </span>
                            <?php endif; ?>

                            <div class="min-w-0">
                                <h4 class="font-medium truncate <?php echo $isComplete ? 'text-text-muted line-through' : 'text-text'; ?>">
                                    <?php echo esc_html($course->post_title); ?>
                                </h4>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <?php if ($isComplete) : ?>
                                <span class="text-xs text-success font-medium">
                                    <?php esc_html_e('Afgerond', 'stridence'); ?>
                                </span>
                            <?php elseif ($isInProgress) : ?>
                                <a href="<?php echo esc_url(get_permalink($course)); ?>"
                                   class="btn-primary text-xs">
                                    <?php esc_html_e('Verder', 'stridence'); ?>
                                </a>
                            <?php else : ?>
                                <span class="text-xs text-text-muted">
                                    <?php esc_html_e('Keuze', 'stridence'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/trajectory/tab-voortgang.php
git commit -m "feat(stridence): add trajectory voortgang tab"
```

---

## Task 6: Create Berichten (Messages) Tab

**Files:**
- Create: `web/app/themes/stridence/templates/trajectory/tab-berichten.php`

**Step 1: Create the messages tab template**

```php
<?php
/**
 * Trajectory Tab: Berichten (Messages)
 *
 * Read-only announcement board from supervisors.
 *
 * @param array $args {
 *     @type WP_Post $trajectory
 *     @type object $enrollment
 *     @type WP_User $user
 *     @type TrajectoryDashboardService $dashboard_service
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$trajectory = $args['trajectory'];
$dashboardService = $args['dashboard_service'];

// Get messages
$messages = $dashboardService->getMessages($trajectory->ID);

// Message type config
$messageTypes = [
    'announcement' => [
        'label' => __('Aankondiging', 'stridence'),
        'icon' => 'bell',
        'class' => 'bg-primary/10 text-primary',
    ],
    'faq' => [
        'label' => __('FAQ', 'stridence'),
        'icon' => 'help-circle',
        'class' => 'bg-accent/10 text-accent',
    ],
    'update' => [
        'label' => __('Update', 'stridence'),
        'icon' => 'info',
        'class' => 'bg-success/10 text-success',
    ],
];
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-text">
            <?php esc_html_e('Berichten', 'stridence'); ?>
        </h2>
        <?php if (!empty($messages)) : ?>
            <span class="text-sm text-text-muted">
                <?php printf(esc_html__('%d berichten', 'stridence'), count($messages)); ?>
            </span>
        <?php endif; ?>
    </div>

    <?php if (empty($messages)) : ?>
        <?php
        get_template_part('partials/empty-state', null, [
            'icon' => 'bell',
            'title' => __('Geen berichten', 'stridence'),
            'message' => __('Er zijn nog geen berichten geplaatst voor dit traject.', 'stridence'),
        ]);
        ?>
    <?php else : ?>
        <div class="space-y-4">
            <?php foreach ($messages as $message) :
                $type = $message['type'] ?? 'announcement';
                $typeConfig = $messageTypes[$type] ?? $messageTypes['announcement'];
                $authorId = (int) ($message['author'] ?? 0);
                $author = $authorId ? get_userdata($authorId) : null;
                $authorName = $author ? ($author->display_name ?: $author->user_login) : __('Beheerder', 'stridence');
                $date = $message['date'] ?? '';
            ?>
                <article class="card p-4">
                    <header class="flex items-start justify-between gap-4 mb-3">
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 w-10 h-10 rounded-full <?php echo esc_attr($typeConfig['class']); ?> flex items-center justify-center">
                                <?php echo stridence_icon($typeConfig['icon'], 'w-5 h-5'); ?>
                            </span>
                            <div>
                                <span class="text-xs font-medium <?php echo esc_attr($typeConfig['class']); ?> px-2 py-0.5 rounded">
                                    <?php echo esc_html($typeConfig['label']); ?>
                                </span>
                                <p class="text-sm text-text-muted mt-1">
                                    <?php echo esc_html($authorName); ?>
                                    <?php if ($date) : ?>
                                        <span class="mx-1">&middot;</span>
                                        <?php echo esc_html(date_i18n('j F Y', strtotime($date))); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </header>

                    <div class="prose prose-sm max-w-none text-text">
                        <?php echo wp_kses_post(nl2br(esc_html($message['content'] ?? ''))); ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/trajectory/tab-berichten.php
git commit -m "feat(stridence): add trajectory berichten tab"
```

---

## Task 7: Add Messages Metabox to Trajectory Admin

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/Admin/TrajectoryAdminController.php`

**Step 1: Add messages metabox registration**

In `registerMetaboxes()`, add after the enrollments metabox:

```php
// Messages metabox
add_meta_box(
    'stride_trajectory_messages',
    __('Berichten', 'stride'),
    [$this, 'renderMessagesMetabox'],
    TrajectoryCPT::POST_TYPE,
    'normal',
    'default'
);
```

**Step 2: Add renderMessagesMetabox method**

Add this method after `renderSidebarMetabox()`:

```php
public function renderMessagesMetabox(WP_Post $post): void
{
    if ($post->post_status === 'auto-draft') {
        echo '<p class="description">' . esc_html__('Sla het traject eerst op om berichten toe te voegen.', 'stride') . '</p>';
        return;
    }

    $messages = get_post_meta($post->ID, 'trajectory_messages', true);
    if (!is_array($messages)) {
        $messages = [];
    }

    // Message types
    $messageTypes = [
        'announcement' => [
            'label' => __('Aankondiging', 'stride'),
            'icon' => 'megaphone',
        ],
        'faq' => [
            'label' => __('FAQ', 'stride'),
            'icon' => 'editor-help',
        ],
        'update' => [
            'label' => __('Update', 'stride'),
            'icon' => 'info-outline',
        ],
    ];
    ?>
    <style>
        .stride-messages-timeline { margin-bottom: 20px; }
        .stride-message-item { display: flex; gap: 12px; padding: 12px; background: #f9f9f9; border-radius: 4px; margin-bottom: 8px; position: relative; }
        .stride-message-item:hover .stride-message-delete { opacity: 1; }
        .stride-message-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .stride-message-icon.announcement { background: #e5f0ff; color: #2271b1; }
        .stride-message-icon.faq { background: #fff3e0; color: #b26200; }
        .stride-message-icon.update { background: #e6f4ea; color: #00a32a; }
        .stride-message-body { flex: 1; min-width: 0; }
        .stride-message-meta { font-size: 11px; color: #646970; margin-bottom: 4px; }
        .stride-message-meta .type-badge { padding: 1px 6px; border-radius: 3px; font-weight: 500; margin-right: 8px; }
        .stride-message-meta .type-badge.announcement { background: #e5f0ff; color: #2271b1; }
        .stride-message-meta .type-badge.faq { background: #fff3e0; color: #b26200; }
        .stride-message-meta .type-badge.update { background: #e6f4ea; color: #00a32a; }
        .stride-message-content { font-size: 13px; line-height: 1.5; color: #1d2327; white-space: pre-wrap; }
        .stride-message-delete { position: absolute; top: 8px; right: 8px; cursor: pointer; color: #b32d2e; opacity: 0; transition: opacity 0.2s; }
        .stride-empty-messages { text-align: center; padding: 30px; color: #646970; }
        .stride-add-message-form textarea { width: 100%; min-height: 80px; margin-bottom: 10px; }
        .stride-add-message-form .form-row { display: flex; justify-content: space-between; align-items: center; }
        .stride-add-message-form .type-selector { display: flex; gap: 12px; }
        .stride-add-message-form .type-selector label { display: flex; align-items: center; gap: 4px; cursor: pointer; font-size: 12px; }
        .stride-add-message-form .type-icon { width: 20px; height: 20px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; }
        .stride-add-message-form .type-icon.announcement { background: #e5f0ff; color: #2271b1; }
        .stride-add-message-form .type-icon.faq { background: #fff3e0; color: #b26200; }
        .stride-add-message-form .type-icon.update { background: #e6f4ea; color: #00a32a; }
    </style>

    <!-- Messages Timeline -->
    <div id="stride-messages-list" class="stride-messages-timeline">
        <?php if (empty($messages)): ?>
            <div class="stride-empty-messages">
                <?php esc_html_e('Nog geen berichten toegevoegd.', 'stride'); ?>
            </div>
        <?php else: ?>
            <?php foreach ($messages as $index => $message): ?>
                <?php if (!empty($message['_deleted'])) continue; ?>
                <?php
                $type = $message['type'] ?? 'announcement';
                $typeConfig = $messageTypes[$type] ?? $messageTypes['announcement'];
                ?>
                <div class="stride-message-item" data-index="<?php echo esc_attr($index); ?>">
                    <div class="stride-message-icon <?php echo esc_attr($type); ?>">
                        <span class="dashicons dashicons-<?php echo esc_attr($typeConfig['icon']); ?>"></span>
                    </div>
                    <div class="stride-message-body">
                        <div class="stride-message-meta">
                            <span class="type-badge <?php echo esc_attr($type); ?>"><?php echo esc_html($typeConfig['label']); ?></span>
                            <span class="author"><?php echo esc_html($message['author'] ?? __('Onbekend', 'stride')); ?></span>
                            <span class="date"><?php echo esc_html($message['date'] ?? ''); ?></span>
                        </div>
                        <div class="stride-message-content"><?php echo esc_html($message['content'] ?? ''); ?></div>
                    </div>
                    <span class="stride-message-delete dashicons dashicons-no-alt" title="<?php esc_attr_e('Verwijderen', 'stride'); ?>"></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Add Message Form -->
    <div class="stride-add-message-form">
        <textarea id="stride-message-content" placeholder="<?php esc_attr_e('Schrijf een bericht voor deelnemers...', 'stride'); ?>"></textarea>
        <div class="form-row">
            <div class="type-selector">
                <?php foreach ($messageTypes as $typeKey => $typeConfig): ?>
                    <label>
                        <input type="radio" name="stride_message_type" value="<?php echo esc_attr($typeKey); ?>" <?php checked($typeKey, 'announcement'); ?>>
                        <span class="type-icon <?php echo esc_attr($typeKey); ?>"><span class="dashicons dashicons-<?php echo esc_attr($typeConfig['icon']); ?>"></span></span>
                        <?php echo esc_html($typeConfig['label']); ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" id="stride-add-message">
                <?php esc_html_e('Bericht toevoegen', 'stride'); ?>
            </button>
        </div>
    </div>

    <input type="hidden" id="stride_messages_data" name="ntdst_fields[trajectory_messages]" value="<?php echo esc_attr(json_encode($messages)); ?>">
    <?php
}
```

**Step 3: Update handleSave to save messages**

In `handleSave()`, add after existing field processing:

```php
// Save trajectory messages
if (isset($fields['trajectory_messages'])) {
    $messages = json_decode(wp_unslash($fields['trajectory_messages']), true);
    if (is_array($messages)) {
        // Filter out deleted messages
        $messages = array_values(array_filter($messages, fn($m) => empty($m['_deleted'])));
        update_post_meta($postId, 'trajectory_messages', $messages);
    }
}
```

**Step 4: Add JavaScript for messages (in trajectory-admin.js)**

Add to the existing JS file or create inline script:

```javascript
// Messages handling
jQuery(function($) {
    var $messagesList = $('#stride-messages-list');
    var $messagesData = $('#stride_messages_data');
    var $messageContent = $('#stride-message-content');

    function getMessages() {
        try {
            return JSON.parse($messagesData.val()) || [];
        } catch (e) {
            return [];
        }
    }

    function saveMessages(messages) {
        $messagesData.val(JSON.stringify(messages));
    }

    // Add message
    $('#stride-add-message').on('click', function() {
        var content = $messageContent.val().trim();
        if (!content) return;

        var type = $('input[name="stride_message_type"]:checked').val() || 'announcement';
        var messages = getMessages();
        var currentUser = typeof strideTrajectoryAdmin !== 'undefined' ? strideTrajectoryAdmin.currentUser : 'Admin';

        messages.unshift({
            type: type,
            content: content,
            author: currentUser,
            date: new Date().toLocaleDateString('nl-NL', { day: 'numeric', month: 'short', year: 'numeric' })
        });

        saveMessages(messages);
        $messageContent.val('');

        // Reload metabox or trigger save
        $('#publish').click();
    });

    // Delete message
    $messagesList.on('click', '.stride-message-delete', function() {
        if (!confirm('Weet je zeker dat je dit bericht wilt verwijderen?')) return;

        var $item = $(this).closest('.stride-message-item');
        var index = $item.data('index');
        var messages = getMessages();

        if (messages[index]) {
            messages[index]._deleted = true;
            saveMessages(messages);
            $item.fadeOut(function() { $(this).remove(); });
        }
    });
});
```

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/Admin/TrajectoryAdminController.php
git commit -m "feat(stride-core): add messages metabox to trajectory admin"
```

---

## Task 8: Create Materialen (Materials) Tab

**Files:**
- Create: `web/app/themes/stridence/templates/trajectory/tab-materialen.php`

**Step 1: Create the materials tab template**

```php
<?php
/**
 * Trajectory Tab: Materialen (Materials)
 *
 * Shows course materials from LearnDash for accessible courses.
 *
 * @param array $args {
 *     @type WP_Post $trajectory
 *     @type object $enrollment
 *     @type WP_User $user
 *     @type TrajectoryDashboardService $dashboard_service
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$trajectory = $args['trajectory'];
$user = $args['user'];
$dashboardService = $args['dashboard_service'];

// Get materials
$materials = $dashboardService->getMaterials($trajectory->ID, $user->ID);
?>

<div class="space-y-6">
    <h2 class="text-lg font-semibold text-text">
        <?php esc_html_e('Materialen', 'stridence'); ?>
    </h2>

    <?php if (empty($materials)) : ?>
        <?php
        get_template_part('partials/empty-state', null, [
            'icon' => 'file-text',
            'title' => __('Geen materialen', 'stridence'),
            'message' => __('Er zijn nog geen materialen beschikbaar voor dit traject.', 'stridence'),
        ]);
        ?>
    <?php else : ?>
        <div class="space-y-4">
            <?php foreach ($materials as $material) : ?>
                <div class="card" x-data="expandable(true)">
                    <button type="button"
                            class="w-full p-4 flex items-center justify-between gap-4 text-left"
                            @click="toggle()">
                        <div class="flex items-center gap-3">
                            <span class="shrink-0 w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
                                <?php echo stridence_icon('file-text', 'w-5 h-5 text-primary'); ?>
                            </span>
                            <h3 class="font-medium text-text">
                                <?php echo esc_html($material['title']); ?>
                            </h3>
                        </div>
                        <span class="shrink-0 text-text-muted transition-transform duration-200"
                              :class="{ 'rotate-180': open }">
                            <?php echo stridence_icon('chevron-down', 'w-5 h-5'); ?>
                        </span>
                    </button>

                    <div x-show="open" x-collapse class="border-t border-border">
                        <div class="p-4 prose prose-sm max-w-none">
                            <?php echo wp_kses_post($material['materials']); ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/trajectory/tab-materialen.php
git commit -m "feat(stridence): add trajectory materialen tab"
```

---

## Task 9: Create Keuzes (Elective Selection) Tab

**Files:**
- Create: `web/app/themes/stridence/templates/trajectory/tab-keuzes.php`

**Step 1: Create the elective selection tab template**

```php
<?php
/**
 * Trajectory Tab: Keuzes (Elective Selection)
 *
 * Handles elective course selection with choice window states.
 *
 * @param array $args {
 *     @type WP_Post $trajectory
 *     @type object $enrollment
 *     @type WP_User $user
 *     @type TrajectoryDashboardService $dashboard_service
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Modules\Trajectory\TrajectorySelection;

$trajectory = $args['trajectory'];
$enrollment = $args['enrollment'];
$user = $args['user'];
$dashboardService = $args['dashboard_service'];

$trajectoryService = ntdst_get(TrajectoryService::class);
$trajectoryData = $trajectoryService->getTrajectory($trajectory->ID);

// Get elective groups
$electiveGroups = $trajectoryService->getElectiveGroups($trajectory->ID);

// Check choice window status
$choiceAvailable = $trajectoryData['choice_available_date'] ?? '';
$choiceDeadline = $trajectoryData['choice_deadline'] ?? '';

$now = time();
$windowOpen = false;
$windowBefore = false;
$windowAfter = false;

if (!empty($choiceAvailable) && !empty($choiceDeadline)) {
    $availableTime = strtotime($choiceAvailable);
    $deadlineTime = strtotime($choiceDeadline);

    if ($now < $availableTime) {
        $windowBefore = true;
    } elseif ($now >= $availableTime && $now <= $deadlineTime) {
        $windowOpen = true;
    } else {
        $windowAfter = true;
    }
}

// Get current selections
$selections = $enrollment->selections ? json_decode($enrollment->selections, true) : [];
?>

<div class="space-y-6">
    <h2 class="text-lg font-semibold text-text">
        <?php esc_html_e('Keuzecursussen', 'stridence'); ?>
    </h2>

    <?php if (empty($electiveGroups)) : ?>
        <?php
        get_template_part('partials/empty-state', null, [
            'icon' => 'check-square',
            'title' => __('Geen keuzevakken', 'stridence'),
            'message' => __('Dit traject heeft geen keuzecursussen.', 'stridence'),
        ]);
        ?>
    <?php elseif ($windowBefore) : ?>
        <!-- Choice window not yet open -->
        <div class="card p-6 text-center">
            <div class="w-16 h-16 rounded-full bg-accent/10 flex items-center justify-center mx-auto mb-4">
                <?php echo stridence_icon('clock', 'w-8 h-8 text-accent'); ?>
            </div>
            <h3 class="font-semibold text-text mb-2">
                <?php esc_html_e('Keuzemoment nog niet beschikbaar', 'stridence'); ?>
            </h3>
            <p class="text-text-muted mb-4">
                <?php
                printf(
                    esc_html__('Je kunt je keuzes maken vanaf %s.', 'stridence'),
                    esc_html(date_i18n('j F Y', strtotime($choiceAvailable)))
                );
                ?>
            </p>

            <!-- Preview electives -->
            <div class="text-left mt-6">
                <h4 class="text-sm font-medium text-text-muted uppercase tracking-wide mb-3">
                    <?php esc_html_e('Beschikbare keuzes', 'stridence'); ?>
                </h4>
                <?php foreach ($electiveGroups as $group) : ?>
                    <div class="mb-4">
                        <p class="text-sm font-medium text-text mb-2">
                            <?php echo esc_html($group['name'] ?? __('Keuzegroep', 'stridence')); ?>
                            <span class="text-text-muted font-normal">
                                (<?php printf(esc_html__('kies %d', 'stridence'), (int) ($group['required'] ?? 1)); ?>)
                            </span>
                        </p>
                        <ul class="text-sm text-text-muted space-y-1">
                            <?php foreach ($group['courses'] ?? [] as $course) : ?>
                                <li class="flex items-center gap-2">
                                    <span class="w-1.5 h-1.5 rounded-full bg-border"></span>
                                    <?php echo esc_html($course->post_title); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php elseif ($windowOpen) : ?>
        <!-- Choice window is open -->
        <div class="card p-4 bg-success/5 border-success/20 mb-6">
            <div class="flex items-center gap-3">
                <?php echo stridence_icon('check-square', 'w-5 h-5 text-success'); ?>
                <div>
                    <p class="font-medium text-success">
                        <?php esc_html_e('Keuzemoment is open', 'stridence'); ?>
                    </p>
                    <p class="text-sm text-text-muted">
                        <?php
                        printf(
                            esc_html__('Maak je keuze voor %s.', 'stridence'),
                            esc_html(date_i18n('j F Y', strtotime($choiceDeadline)))
                        );
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <form id="elective-selection-form" class="space-y-6">
            <?php foreach ($electiveGroups as $groupIndex => $group) :
                $groupName = $group['name'] ?? __('Keuzegroep', 'stridence');
                $required = (int) ($group['required'] ?? 1);
                $courses = $group['courses'] ?? [];
            ?>
                <div class="card p-4">
                    <h3 class="font-medium text-text mb-1">
                        <?php echo esc_html($groupName); ?>
                    </h3>
                    <p class="text-sm text-text-muted mb-4">
                        <?php printf(esc_html__('Selecteer %d cursus(sen)', 'stridence'), $required); ?>
                    </p>

                    <div class="space-y-2">
                        <?php foreach ($courses as $course) :
                            $isSelected = in_array($course->ID, $selections[$groupIndex] ?? [], true);
                            $inputType = $required === 1 ? 'radio' : 'checkbox';
                        ?>
                            <label class="flex items-center gap-3 p-3 rounded-lg border border-border hover:border-primary cursor-pointer transition-colors <?php echo $isSelected ? 'border-primary bg-primary/5' : ''; ?>">
                                <input type="<?php echo esc_attr($inputType); ?>"
                                       name="selections[<?php echo esc_attr($groupIndex); ?>]<?php echo $required > 1 ? '[]' : ''; ?>"
                                       value="<?php echo esc_attr($course->ID); ?>"
                                       <?php checked($isSelected); ?>
                                       class="text-primary">
                                <span class="text-text"><?php echo esc_html($course->post_title); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="flex justify-end">
                <button type="submit" class="btn-primary">
                    <?php esc_html_e('Keuzes opslaan', 'stridence'); ?>
                </button>
            </div>
        </form>

    <?php else : ?>
        <!-- Choice window closed -->
        <div class="card p-6">
            <div class="flex items-center gap-3 mb-4">
                <?php echo stridence_icon('check', 'w-5 h-5 text-success'); ?>
                <h3 class="font-medium text-text">
                    <?php esc_html_e('Keuzeperiode is gesloten', 'stridence'); ?>
                </h3>
            </div>

            <?php if (!empty($selections)) : ?>
                <p class="text-sm text-text-muted mb-4">
                    <?php esc_html_e('Jouw geselecteerde cursussen:', 'stridence'); ?>
                </p>

                <?php foreach ($electiveGroups as $groupIndex => $group) :
                    $groupSelections = $selections[$groupIndex] ?? [];
                    if (empty($groupSelections)) continue;
                ?>
                    <div class="mb-4">
                        <p class="text-sm font-medium text-text mb-2">
                            <?php echo esc_html($group['name'] ?? __('Keuzegroep', 'stridence')); ?>
                        </p>
                        <ul class="space-y-2">
                            <?php foreach ($group['courses'] ?? [] as $course) :
                                if (!in_array($course->ID, $groupSelections, true)) continue;
                            ?>
                                <li class="flex items-center gap-2 text-text">
                                    <?php echo stridence_icon('check', 'w-4 h-4 text-success'); ?>
                                    <?php echo esc_html($course->post_title); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p class="text-text-muted">
                    <?php esc_html_e('Er zijn geen keuzes gemaakt tijdens de keuzeperiode.', 'stridence'); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/trajectory/tab-keuzes.php
git commit -m "feat(stridence): add trajectory keuzes tab"
```

---

## Task 10: Add Missing Icons

**Files:**
- Create: `web/app/themes/stridence/icons/trending-up.svg`
- Create: `web/app/themes/stridence/icons/check-square.svg`
- Create: `web/app/themes/stridence/icons/help-circle.svg`

**Step 1: Create trending-up.svg**

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline><polyline points="17 6 23 6 23 12"></polyline></svg>
```

**Step 2: Create check-square.svg**

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
```

**Step 3: Create help-circle.svg**

```svg
<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
```

**Step 4: Commit**

```bash
git add web/app/themes/stridence/icons/trending-up.svg
git add web/app/themes/stridence/icons/check-square.svg
git add web/app/themes/stridence/icons/help-circle.svg
git commit -m "feat(stridence): add trajectory dashboard icons"
```

---

## Task 11: Update Dashboard Tab-Trajecten with Links

**Files:**
- Modify: `web/app/themes/stridence/templates/dashboard/tab-trajecten.php`

**Step 1: Update the "Bekijk traject" link to point to personal dashboard**

Change the action link from:

```php
<a href="<?php echo esc_url(get_permalink($traj['trajectory_id'])); ?>"
```

To:

```php
<?php
$dashboard_url = get_permalink(get_page_by_path('mijn-account'));
$trajectory_dashboard_url = trailingslashit($dashboard_url) . 'trajecten/' . get_post_field('post_name', $traj['trajectory_id']) . '/';
?>
<a href="<?php echo esc_url($trajectory_dashboard_url); ?>"
```

**Step 2: Commit**

```bash
git add web/app/themes/stridence/templates/dashboard/tab-trajecten.php
git commit -m "feat(stridence): link to personal trajectory dashboard"
```

---

## Task 12: Final Integration Test

**Step 1: Flush rewrite rules**

Run: `ddev exec wp rewrite flush`

**Step 2: Test the flow**

1. Visit https://stride.ddev.site/mijn-account/?tab=trajecten
2. Click on an enrolled trajectory
3. Verify redirect to /mijn-account/trajecten/{slug}/
4. Test all four tabs: Voortgang, Keuzes, Materialen, Berichten
5. Test back link returns to trajecten tab

**Step 3: Test admin messages**

1. Go to WordPress admin → Trajecten → Edit a trajectory
2. Find the "Berichten" metabox
3. Add a test message
4. Save the trajectory
5. View the messages tab in frontend

**Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete personal trajectory dashboard implementation"
```

---

## Summary

This plan implements the personal trajectory dashboard with:

1. **Routing** - Rewrite rules for `/mijn-account/trajecten/{slug}/`
2. **Access Control** - Enrollment verification before showing dashboard
3. **TrajectoryDashboardService** - Data aggregation for frontend
4. **Four tabs**:
   - Voortgang: Progress overview with course completion status
   - Keuzes: Elective selection with choice window handling
   - Materialen: Course materials from LearnDash
   - Berichten: Read-only messages from supervisors
5. **Admin Messages** - Metabox using existing notes pattern
6. **Navigation** - Tab links and back navigation to dashboard

All templates follow existing Stridence patterns with Tailwind CSS and Alpine.js.
