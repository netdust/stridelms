# Dashboard Redesign Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Redesign the user dashboard with a top-right navigation panel (desktop), bottom navbar (mobile), continue learning hero, and upcoming sessions section.

**Architecture:** The dashboard uses PHP templates in `templates/dashboard/`. We'll create a shared partial for the navigation panel/bottom navbar, update home.php to use the new layout, and include the navigation partial in all 6 dashboard templates.

**Tech Stack:** PHP templates, UIkit 3, CSS custom properties

---

## Task 1: Create Navigation Partial

**Files:**
- Create: `web/app/themes/stride/templates/dashboard/partials/nav-panel.php`

**Step 1: Create the partials directory and navigation partial**

```php
<?php
/**
 * Dashboard Navigation Panel Partial
 *
 * Desktop: Top-right card with icon + label rows
 * Mobile: Fixed bottom navbar with icons only
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Determine current page for active state
$currentUrl = $_SERVER['REQUEST_URI'] ?? '';
$navItems = [
    [
        'url' => '/mijn-account/mijn-cursussen/',
        'label' => __('Cursussen', 'stride'),
        'icon' => 'copy',
    ],
    [
        'url' => '/mijn-account/mijn-trajecten/',
        'label' => __('Trajecten', 'stride'),
        'icon' => 'git-branch',
    ],
    [
        'url' => '/mijn-account/mijn-offertes/',
        'label' => __('Offertes', 'stride'),
        'icon' => 'file-text',
    ],
    [
        'url' => '/mijn-account/mijn-profiel/',
        'label' => __('Profiel', 'stride'),
        'icon' => 'user',
    ],
    [
        'url' => '/mijn-account/kalender/',
        'label' => __('Kalender', 'stride'),
        'icon' => 'calendar',
    ],
];

/**
 * Check if URL matches current page
 */
function stride_is_active_nav($url, $currentUrl) {
    return strpos($currentUrl, $url) !== false;
}
?>

<!-- Desktop Navigation Panel (hidden on mobile) -->
<nav class="stride-nav-panel uk-visible@m" aria-label="<?php esc_attr_e('Dashboard navigatie', 'stride'); ?>">
    <ul class="stride-nav-panel__list">
        <?php foreach ($navItems as $item) :
            $isActive = stride_is_active_nav($item['url'], $currentUrl);
        ?>
            <li class="stride-nav-panel__item<?php echo $isActive ? ' stride-nav-panel__item--active' : ''; ?>">
                <a href="<?php echo esc_url(home_url($item['url'])); ?>" class="stride-nav-panel__link">
                    <span uk-icon="icon: <?php echo esc_attr($item['icon']); ?>; ratio: 1"></span>
                    <span class="stride-nav-panel__label"><?php echo esc_html($item['label']); ?></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>

<!-- Mobile Bottom Navbar (hidden on desktop) -->
<nav class="stride-bottom-navbar uk-hidden@m" aria-label="<?php esc_attr_e('Dashboard navigatie', 'stride'); ?>">
    <?php foreach ($navItems as $item) :
        $isActive = stride_is_active_nav($item['url'], $currentUrl);
    ?>
        <a href="<?php echo esc_url(home_url($item['url'])); ?>"
           class="stride-bottom-navbar__item<?php echo $isActive ? ' stride-bottom-navbar__item--active' : ''; ?>"
           aria-label="<?php echo esc_attr($item['label']); ?>">
            <span uk-icon="icon: <?php echo esc_attr($item['icon']); ?>; ratio: 1.2"></span>
        </a>
    <?php endforeach; ?>
</nav>
```

**Step 2: Verify the file was created**

Run: `ls -la web/app/themes/stride/templates/dashboard/partials/`
Expected: File `nav-panel.php` exists

**Step 3: Commit**

```bash
git add web/app/themes/stride/templates/dashboard/partials/nav-panel.php
git commit -m "feat(dashboard): add navigation panel partial"
```

---

## Task 2: Add CSS for Navigation Panel and Bottom Navbar

**Files:**
- Modify: `web/app/themes/stride/assets/css/stride.css` (append to end)

**Step 1: Add navigation panel styles**

Append to `stride.css`:

```css
/* ========================================
   DASHBOARD NAVIGATION
   ======================================== */

/* Desktop Navigation Panel */
.stride-nav-panel {
    background: var(--stride-surface);
    border-radius: var(--stride-radius-lg);
    box-shadow: var(--stride-shadow-sm);
    border: 1px solid var(--stride-border-light);
    padding: var(--stride-space-sm);
    min-width: 180px;
}

.stride-nav-panel__list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.stride-nav-panel__item {
    margin: 0;
}

.stride-nav-panel__link {
    display: flex;
    align-items: center;
    gap: var(--stride-space-sm);
    padding: var(--stride-space-sm) var(--stride-space-md);
    border-radius: var(--stride-radius-md);
    color: var(--stride-text);
    text-decoration: none;
    transition: all var(--stride-transition-fast);
    font-size: var(--stride-font-size-sm);
    font-weight: 500;
}

.stride-nav-panel__link:hover {
    background: var(--stride-bg);
    color: var(--stride-primary);
    text-decoration: none;
}

.stride-nav-panel__item--active .stride-nav-panel__link {
    background: var(--stride-primary-light);
    color: var(--stride-primary);
}

.stride-nav-panel__label {
    flex: 1;
}

/* Mobile Bottom Navbar */
.stride-bottom-navbar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 100;
    display: flex;
    justify-content: space-around;
    align-items: center;
    background: var(--stride-surface);
    border-top: 1px solid var(--stride-border);
    padding: var(--stride-space-sm) var(--stride-space-md);
    padding-bottom: max(var(--stride-space-sm), env(safe-area-inset-bottom));
}

.stride-bottom-navbar__item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: var(--stride-space-sm);
    color: var(--stride-text-muted);
    text-decoration: none;
    transition: color var(--stride-transition-fast);
    border-radius: var(--stride-radius-md);
}

.stride-bottom-navbar__item:hover {
    color: var(--stride-primary);
    text-decoration: none;
}

.stride-bottom-navbar__item--active {
    color: var(--stride-primary);
}

/* Add bottom padding to dashboard content on mobile to account for navbar */
@media (max-width: 959px) {
    .stride-dashboard-home,
    .stride-dashboard-courses,
    .stride-dashboard-trajectories,
    .stride-dashboard-quotes,
    .stride-dashboard-profile,
    .stride-dashboard-calendar {
        padding-bottom: 80px;
    }
}
```

**Step 2: Verify styles were added**

Run: `grep -c "stride-nav-panel" web/app/themes/stride/assets/css/stride.css`
Expected: Multiple matches (at least 10)

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/css/stride.css
git commit -m "style(dashboard): add navigation panel and bottom navbar styles"
```

---

## Task 3: Add CSS for Continue Learning Hero

**Files:**
- Modify: `web/app/themes/stride/assets/css/stride.css` (append)

**Step 1: Add continue learning hero styles**

Append to `stride.css`:

```css
/* ========================================
   CONTINUE LEARNING HERO
   ======================================== */

.stride-continue-hero {
    background: var(--stride-surface);
    border-radius: var(--stride-radius-lg);
    box-shadow: var(--stride-shadow-sm);
    border: 1px solid var(--stride-border-light);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

@media (min-width: 640px) {
    .stride-continue-hero {
        flex-direction: row;
    }
}

.stride-continue-hero__image {
    width: 100%;
    height: 160px;
    background: var(--stride-secondary-light);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

@media (min-width: 640px) {
    .stride-continue-hero__image {
        width: 200px;
        height: auto;
        min-height: 180px;
    }
}

.stride-continue-hero__image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.stride-continue-hero__placeholder {
    color: var(--stride-text-muted);
}

.stride-continue-hero__body {
    flex: 1;
    padding: var(--stride-space-lg);
    display: flex;
    flex-direction: column;
    gap: var(--stride-space-sm);
}

.stride-continue-hero__title {
    font-size: var(--stride-font-size-xl);
    font-weight: 600;
    color: var(--stride-text);
    margin: 0;
    line-height: var(--stride-line-height-tight);
}

.stride-continue-hero__meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--stride-space-md);
    font-size: var(--stride-font-size-sm);
    color: var(--stride-text-muted);
}

.stride-continue-hero__meta-item {
    display: flex;
    align-items: center;
    gap: var(--stride-space-xs);
}

.stride-continue-hero__progress {
    display: flex;
    align-items: center;
    gap: var(--stride-space-sm);
    margin-top: var(--stride-space-xs);
}

.stride-continue-hero__progress .uk-progress {
    flex: 1;
    margin: 0;
    height: 8px;
}

.stride-continue-hero__progress-text {
    font-size: var(--stride-font-size-sm);
    font-weight: 600;
    color: var(--stride-text);
    min-width: 40px;
    text-align: right;
}

.stride-continue-hero__action {
    margin-top: auto;
    padding-top: var(--stride-space-sm);
}

.stride-continue-hero__action .uk-button {
    min-width: 140px;
}

/* Empty state variation */
.stride-continue-hero--empty {
    text-align: center;
    padding: var(--stride-space-xl);
}

.stride-continue-hero--empty .stride-continue-hero__body {
    align-items: center;
}
```

**Step 2: Verify styles were added**

Run: `grep -c "stride-continue-hero" web/app/themes/stride/assets/css/stride.css`
Expected: Multiple matches (at least 15)

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/css/stride.css
git commit -m "style(dashboard): add continue learning hero styles"
```

---

## Task 4: Add CSS for Dashboard Layout Grid

**Files:**
- Modify: `web/app/themes/stride/assets/css/stride.css` (append)

**Step 1: Add dashboard layout styles**

Append to `stride.css`:

```css
/* ========================================
   DASHBOARD LAYOUT
   ======================================== */

.stride-dashboard-layout {
    display: flex;
    flex-direction: column;
    gap: var(--stride-space-lg);
}

@media (min-width: 960px) {
    .stride-dashboard-layout {
        display: grid;
        grid-template-columns: 1fr 200px;
        grid-template-rows: auto auto 1fr;
        gap: var(--stride-space-lg);
    }

    .stride-dashboard-layout__greeting {
        grid-column: 1;
        grid-row: 1;
    }

    .stride-dashboard-layout__nav {
        grid-column: 2;
        grid-row: 1 / 3;
        align-self: start;
    }

    .stride-dashboard-layout__hero {
        grid-column: 1;
        grid-row: 2;
    }

    .stride-dashboard-layout__content {
        grid-column: 1 / -1;
        grid-row: 3;
    }
}

/* Upcoming sessions horizontal layout */
.stride-sessions-grid {
    display: grid;
    gap: var(--stride-space-md);
    grid-template-columns: 1fr;
}

@media (min-width: 640px) {
    .stride-sessions-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 960px) {
    .stride-sessions-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

/* Session card styling */
.stride-session-card {
    background: var(--stride-surface);
    border-radius: var(--stride-radius-lg);
    box-shadow: var(--stride-shadow-sm);
    border: 1px solid var(--stride-border-light);
    padding: var(--stride-space-md);
    display: flex;
    gap: var(--stride-space-md);
    transition: box-shadow var(--stride-transition-fast);
    text-decoration: none;
    color: inherit;
}

.stride-session-card:hover {
    box-shadow: var(--stride-shadow-md);
    text-decoration: none;
    color: inherit;
}

.stride-session-card__date {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 50px;
    padding: var(--stride-space-sm);
    background: var(--stride-primary-light);
    border-radius: var(--stride-radius-md);
    color: var(--stride-primary);
}

.stride-session-card__day {
    font-size: var(--stride-font-size-2xl);
    font-weight: 700;
    line-height: 1;
}

.stride-session-card__month {
    font-size: var(--stride-font-size-xs);
    font-weight: 600;
    text-transform: uppercase;
}

.stride-session-card__info {
    flex: 1;
    min-width: 0;
}

.stride-session-card__title {
    font-size: var(--stride-font-size-sm);
    font-weight: 600;
    color: var(--stride-text);
    margin: 0 0 var(--stride-space-xs);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stride-session-card__meta {
    font-size: var(--stride-font-size-xs);
    color: var(--stride-text-muted);
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.stride-session-card__meta-item {
    display: flex;
    align-items: center;
    gap: var(--stride-space-xs);
}

.stride-session-card__badge {
    display: inline-flex;
    align-items: center;
    padding: 2px 8px;
    background: var(--stride-success-light);
    color: var(--stride-success);
    border-radius: var(--stride-radius-sm);
    font-size: var(--stride-font-size-xs);
    font-weight: 600;
}
```

**Step 2: Verify styles were added**

Run: `grep -c "stride-dashboard-layout\|stride-session-card" web/app/themes/stride/assets/css/stride.css`
Expected: Multiple matches (at least 20)

**Step 3: Commit**

```bash
git add web/app/themes/stride/assets/css/stride.css
git commit -m "style(dashboard): add layout grid and session card styles"
```

---

## Task 5: Rewrite Dashboard Home Template

**Files:**
- Modify: `web/app/themes/stride/templates/dashboard/home.php`

**Step 1: Update home.php with new layout**

Replace entire contents of `home.php`:

```php
<?php
/**
 * Dashboard Home Template
 *
 * Redesigned dashboard with:
 * - Greeting section
 * - Navigation panel (desktop) / Bottom navbar (mobile)
 * - Continue learning hero
 * - Upcoming sessions
 *
 * @package stride
 */

defined('ABSPATH') || exit;

// Services - lazy loaded from DI container
$enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$editionService = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$sessionService = ntdst_get(\Stride\Modules\Edition\SessionService::class);
$completionService = ntdst_get(\Stride\Modules\Completion\CompletionService::class);
$attendanceService = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);

// Current user
$user = wp_get_current_user();
$userId = $user->ID;
$firstName = $user->first_name ?: $user->display_name;

// Time-based greeting
$hour = (int) wp_date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = __('Goedemorgen', 'stride');
} elseif ($hour >= 12 && $hour < 18) {
    $greeting = __('Goedemiddag', 'stride');
} else {
    $greeting = __('Goedenavond', 'stride');
}

// Get user enrollments (confirmed registrations)
$enrollments = $enrollmentService->getUserEnrollments($userId);

// Build active courses list with progress data
$activeCourses = [];
$upcomingSessions = [];
$totalCourses = 0;

foreach ($enrollments as $enrollment) {
    $editionId = (int) $enrollment->edition_id;
    $edition = $editionService->getEdition($editionId);

    if (is_wp_error($edition)) {
        continue;
    }

    // Get course info
    $courseId = $editionService->getCourseId($editionId);
    $courseTitle = $courseId ? get_the_title($courseId) : ($edition->post_title ?? __('Onbekende cursus', 'stride'));

    // Get progress data
    $progress = $completionService->getProgress($editionId, $userId);
    $isComplete = $progress['is_complete'] ?? false;
    $percentage = $progress['percentage'] ?? 0;

    // Get session info
    $sessions = $sessionService->getSessionsForEdition($editionId);
    $totalSessions = count($sessions);
    $attendedCount = $attendanceService->countAttended($userId, $editionId);

    $totalCourses++;

    // Determine if online by checking session types
    $isOnline = true;
    foreach ($sessions as $session) {
        $sessionType = $session['type'] ?? 'online';
        if (in_array($sessionType, ['in_person', 'webinar'], true)) {
            $isOnline = false;
            break;
        }
    }

    // Determine URL
    $url = $isOnline && $courseId
        ? get_permalink($courseId)
        : get_permalink($editionId);

    // Get thumbnail
    $thumbnail = $courseId ? get_the_post_thumbnail_url($courseId, 'stride_course_card') : null;

    // Find next session date
    $nextSession = null;
    $today = wp_date('Y-m-d');
    foreach ($sessions as $session) {
        $sessionDate = $session['date'] ?? '';
        if ($sessionDate && $sessionDate >= $today) {
            $nextSession = $session;
            break;
        }
    }

    // Build course data (only non-complete courses for "continue learning")
    if (!$isComplete) {
        $activeCourses[] = [
            'edition_id' => $editionId,
            'course_id' => $courseId,
            'title' => $courseTitle,
            'url' => $url,
            'is_online' => $isOnline,
            'percentage' => $percentage,
            'total_sessions' => $totalSessions,
            'attended' => $attendedCount,
            'thumbnail' => $thumbnail,
            'next_session' => $nextSession,
        ];
    }

    // Collect upcoming sessions
    foreach ($sessions as $session) {
        $sessionDate = $session['date'] ?? '';
        if ($sessionDate && $sessionDate >= $today) {
            $upcomingSessions[] = [
                'session_id' => $session['id'],
                'edition_id' => $editionId,
                'course_title' => $courseTitle,
                'course_url' => $url,
                'date' => $sessionDate,
                'start_time' => $session['start_time'] ?? '',
                'end_time' => $session['end_time'] ?? '',
                'location' => $session['location'] ?? '',
                'is_online' => $isOnline,
            ];
        }
    }
}

// Sort upcoming sessions by date and limit to 3
usort($upcomingSessions, fn($a, $b) => strcmp($a['date'], $b['date']));
$upcomingSessions = array_slice($upcomingSessions, 0, 3);

// Get the most recent active course for "Continue Learning"
$continueCoruse = !empty($activeCourses) ? $activeCourses[0] : null;

// Count active courses
$activeCount = count($activeCourses);

// Dutch month names
$dutchMonths = [
    1 => 'jan', 2 => 'feb', 3 => 'mrt', 4 => 'apr', 5 => 'mei', 6 => 'jun',
    7 => 'jul', 8 => 'aug', 9 => 'sep', 10 => 'okt', 11 => 'nov', 12 => 'dec'
];
?>

<div class="stride-dashboard-home">
    <div class="stride-dashboard-layout">
        <!-- Greeting Section -->
        <section class="stride-dashboard-layout__greeting stride-greeting">
            <h1 class="stride-greeting__title">
                <?php echo esc_html($greeting . ', ' . $firstName . '!'); ?>
            </h1>
            <p class="stride-greeting__subtitle">
                <?php
                if ($activeCount > 0) {
                    printf(
                        esc_html(_n(
                            'Je hebt %d actieve cursus',
                            'Je hebt %d actieve cursussen',
                            $activeCount,
                            'stride'
                        )),
                        $activeCount
                    );
                } else {
                    esc_html_e('Welkom op je persoonlijke dashboard.', 'stride');
                }
                ?>
            </p>
        </section>

        <!-- Navigation Panel (Desktop) -->
        <div class="stride-dashboard-layout__nav">
            <?php include locate_template('templates/dashboard/partials/nav-panel.php'); ?>
        </div>

        <!-- Continue Learning Hero -->
        <section class="stride-dashboard-layout__hero">
            <?php if ($continueCoruse) : ?>
                <div class="stride-continue-hero">
                    <div class="stride-continue-hero__image">
                        <?php if ($continueCoruse['thumbnail']) : ?>
                            <img src="<?php echo esc_url($continueCoruse['thumbnail']); ?>" alt="<?php echo esc_attr($continueCoruse['title']); ?>">
                        <?php else : ?>
                            <span class="stride-continue-hero__placeholder" uk-icon="icon: play-circle; ratio: 3"></span>
                        <?php endif; ?>
                    </div>
                    <div class="stride-continue-hero__body">
                        <h2 class="stride-continue-hero__title"><?php echo esc_html($continueCoruse['title']); ?></h2>
                        <div class="stride-continue-hero__meta">
                            <?php if ($continueCoruse['next_session']) :
                                $nextDate = strtotime($continueCoruse['next_session']['date']);
                                $nextDay = date('j', $nextDate);
                                $nextMonthNum = (int) date('n', $nextDate);
                                $nextMonth = $dutchMonths[$nextMonthNum];
                            ?>
                                <span class="stride-continue-hero__meta-item">
                                    <span uk-icon="icon: calendar; ratio: 0.8"></span>
                                    <?php echo esc_html("Volgende sessie: {$nextDay} {$nextMonth}"); ?>
                                    <?php if ($continueCoruse['next_session']['start_time']) : ?>
                                        <?php echo esc_html(', ' . $continueCoruse['next_session']['start_time']); ?>
                                    <?php endif; ?>
                                </span>
                            <?php elseif ($continueCoruse['is_online']) : ?>
                                <span class="stride-continue-hero__meta-item">
                                    <span uk-icon="icon: laptop; ratio: 0.8"></span>
                                    <?php esc_html_e('Online cursus', 'stride'); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="stride-continue-hero__progress">
                            <progress class="uk-progress" value="<?php echo esc_attr($continueCoruse['percentage']); ?>" max="100"></progress>
                            <span class="stride-continue-hero__progress-text"><?php echo esc_html($continueCoruse['percentage'] . '%'); ?></span>
                        </div>
                        <div class="stride-continue-hero__action">
                            <a href="<?php echo esc_url($continueCoruse['url']); ?>" class="uk-button uk-button-primary">
                                <?php esc_html_e('Doorgaan', 'stride'); ?>
                                <span uk-icon="icon: arrow-right"></span>
                            </a>
                        </div>
                    </div>
                </div>
            <?php else : ?>
                <!-- Empty state: No active courses -->
                <div class="stride-continue-hero stride-continue-hero--empty">
                    <div class="stride-continue-hero__body">
                        <span uk-icon="icon: book; ratio: 2" class="stride-text-muted"></span>
                        <h2 class="stride-continue-hero__title uk-margin-small-top"><?php esc_html_e('Nog geen cursussen', 'stride'); ?></h2>
                        <p class="stride-text-muted"><?php esc_html_e('Ontdek ons aanbod en start met leren.', 'stride'); ?></p>
                        <div class="stride-continue-hero__action">
                            <a href="<?php echo esc_url(home_url('/cursussen/')); ?>" class="uk-button uk-button-primary">
                                <?php esc_html_e('Ontdek cursussen', 'stride'); ?>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <!-- Upcoming Sessions Section -->
        <section class="stride-dashboard-layout__content">
            <h3 class="uk-h4 uk-margin-medium-bottom"><?php esc_html_e('Aankomende sessies', 'stride'); ?></h3>

            <?php if (!empty($upcomingSessions)) : ?>
                <div class="stride-sessions-grid">
                    <?php foreach ($upcomingSessions as $session) :
                        $date = strtotime($session['date']);
                        $day = date('j', $date);
                        $monthNum = (int) date('n', $date);
                        $month = $dutchMonths[$monthNum];
                    ?>
                        <a href="<?php echo esc_url($session['course_url']); ?>" class="stride-session-card">
                            <div class="stride-session-card__date">
                                <span class="stride-session-card__day"><?php echo esc_html($day); ?></span>
                                <span class="stride-session-card__month"><?php echo esc_html($month); ?></span>
                            </div>
                            <div class="stride-session-card__info">
                                <h4 class="stride-session-card__title"><?php echo esc_html($session['course_title']); ?></h4>
                                <div class="stride-session-card__meta">
                                    <?php if ($session['start_time']) : ?>
                                        <span class="stride-session-card__meta-item">
                                            <span uk-icon="icon: clock; ratio: 0.7"></span>
                                            <?php
                                            echo esc_html($session['start_time']);
                                            if ($session['end_time']) {
                                                echo ' - ' . esc_html($session['end_time']);
                                            }
                                            ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($session['is_online']) : ?>
                                        <span class="stride-session-card__badge"><?php esc_html_e('Online', 'stride'); ?></span>
                                    <?php elseif ($session['location']) : ?>
                                        <span class="stride-session-card__meta-item">
                                            <span uk-icon="icon: location; ratio: 0.7"></span>
                                            <?php echo esc_html($session['location']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="stride-text-muted"><?php esc_html_e('Geen sessies gepland', 'stride'); ?></p>
            <?php endif; ?>
        </section>
    </div>

    <!-- Mobile Bottom Navbar (included from partial, shown only on mobile) -->
    <?php include locate_template('templates/dashboard/partials/nav-panel.php'); ?>
</div>
```

**Step 2: Verify changes**

Run: `grep -c "stride-dashboard-layout\|stride-continue-hero" web/app/themes/stride/templates/dashboard/home.php`
Expected: Multiple matches

**Step 3: Commit**

```bash
git add web/app/themes/stride/templates/dashboard/home.php
git commit -m "feat(dashboard): rewrite home template with new layout"
```

---

## Task 6: Add Navigation to Courses Template

**Files:**
- Modify: `web/app/themes/stride/templates/dashboard/courses.php`

**Step 1: Add navigation panel and bottom navbar to courses template**

At the beginning of the template (after the opening `<div>` for the main container), add:

```php
<!-- Navigation Panel (Desktop) -->
<div class="uk-visible@m" style="position: absolute; top: 0; right: 0;">
    <?php include locate_template('templates/dashboard/partials/nav-panel.php'); ?>
</div>
```

And wrap the content in a relative container. Also add at the end before the closing `</div>`:

```php
<!-- Mobile Bottom Navbar -->
<?php include locate_template('templates/dashboard/partials/nav-panel.php'); ?>
```

The key changes needed:
1. Find the opening container div (after PHP logic section)
2. Add `position: relative;` to it
3. Add the nav panel include after the page header
4. Add the bottom navbar include before the closing div
5. Add `stride-dashboard-courses` class for mobile padding

**Step 2: Test the page still renders**

Visit: `/mijn-account/mijn-cursussen/`
Expected: Page renders with navigation panel on desktop

**Step 3: Commit**

```bash
git add web/app/themes/stride/templates/dashboard/courses.php
git commit -m "feat(dashboard): add navigation to courses page"
```

---

## Task 7: Add Navigation to Remaining Dashboard Templates

**Files:**
- Modify: `web/app/themes/stride/templates/dashboard/trajectories.php`
- Modify: `web/app/themes/stride/templates/dashboard/quotes.php`
- Modify: `web/app/themes/stride/templates/dashboard/profile.php`
- Modify: `web/app/themes/stride/templates/dashboard/calendar.php`

**Step 1: Add navigation to trajectories.php**

Same pattern as courses.php:
1. Add wrapper with `stride-dashboard-trajectories` class
2. Add nav panel include (desktop)
3. Add bottom navbar include (mobile)

**Step 2: Add navigation to quotes.php**

Same pattern with `stride-dashboard-quotes` class.

**Step 3: Add navigation to profile.php**

Same pattern with `stride-dashboard-profile` class.

**Step 4: Add navigation to calendar.php**

Same pattern with `stride-dashboard-calendar` class.

**Step 5: Test all pages render**

Visit each page and verify navigation appears:
- `/mijn-account/mijn-trajecten/`
- `/mijn-account/mijn-offertes/`
- `/mijn-account/mijn-profiel/`
- `/mijn-account/kalender/`

**Step 6: Commit**

```bash
git add web/app/themes/stride/templates/dashboard/trajectories.php \
        web/app/themes/stride/templates/dashboard/quotes.php \
        web/app/themes/stride/templates/dashboard/profile.php \
        web/app/themes/stride/templates/dashboard/calendar.php
git commit -m "feat(dashboard): add navigation to all dashboard pages"
```

---

## Task 8: Visual Testing and Polish

**Files:**
- Possibly: `web/app/themes/stride/assets/css/stride.css`

**Step 1: Test dashboard home at various screen sizes**

Test breakpoints:
- 320px (mobile)
- 768px (tablet)
- 1024px (desktop)
- 1440px (large desktop)

Verify:
- Navigation panel shows on desktop (960px+)
- Bottom navbar shows on mobile (<960px)
- Continue hero is responsive
- Session cards stack properly

**Step 2: Fix any visual issues found**

Adjust CSS as needed.

**Step 3: Test all dashboard pages**

Verify navigation appears and works on:
- Home
- Courses
- Trajectories
- Quotes
- Profile
- Calendar

**Step 4: Commit any fixes**

```bash
git add web/app/themes/stride/assets/css/stride.css
git commit -m "style(dashboard): polish responsive layout"
```

---

## Task 9: Final Review and Cleanup

**Step 1: Run through entire dashboard flow**

1. Log in as test user
2. Visit dashboard home
3. Click each navigation item
4. Verify active states work
5. Test on mobile viewport
6. Verify bottom navbar active states

**Step 2: Remove any unused CSS from old design**

Check for unused classes related to old "total progress" card if needed.

**Step 3: Final commit**

```bash
git add -A
git commit -m "feat(dashboard): complete dashboard redesign"
```

---

## Summary

| Task | Description | Files |
|------|-------------|-------|
| 1 | Create navigation partial | `partials/nav-panel.php` |
| 2 | Add nav panel CSS | `stride.css` |
| 3 | Add continue hero CSS | `stride.css` |
| 4 | Add layout grid CSS | `stride.css` |
| 5 | Rewrite home template | `home.php` |
| 6 | Add nav to courses | `courses.php` |
| 7 | Add nav to other pages | 4 template files |
| 8 | Visual testing | CSS adjustments |
| 9 | Final review | Cleanup |

Total: ~9 focused tasks, each 5-15 minutes.
