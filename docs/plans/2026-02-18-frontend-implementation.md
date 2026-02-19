# Stride Frontend Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a clean, mobile-first frontend for Stride LMS with Headspace-inspired design, using only the existing stride-core services.

**Architecture:** Theme-only presentation layer. Templates call stride-core services directly via `ntdst_get()`. No intermediate frontend service layer - just templates and CSS. PWA features added incrementally.

**Tech Stack:** UIkit 3.21.6, Inter font, CSS custom properties, vanilla JS, stride-core services (`Stride\Modules\*`)

**Design Reference:** `docs/plans/2026-02-18-frontend-design-headspace.md`

---

## Available Services Reference

Before implementing, understand these are the ONLY services available. Do not invent methods.

### EditionService (`Stride\Modules\Edition\EditionService`)
```php
$service = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$service->getEdition(int $editionId): WP_Post|WP_Error
$service->getEditionsForCourse(int $courseId): array
$service->getUpcomingEditions(int $limit = 10): array
$service->getPrice(int $editionId, bool $isMember = true): Money
$service->canEnroll(int $editionId): bool
$service->hasAvailableSpots(int $editionId): bool
$service->getCapacity(int $editionId): int
$service->getRegisteredCount(int $editionId): int
$service->getStatus(int $editionId): EditionStatus
$service->getCourseId(int $editionId): ?int
```

### SessionService (`Stride\Modules\Edition\SessionService`)
```php
$service = ntdst_get(\Stride\Modules\Edition\SessionService::class);
$service->getSession(int $sessionId): ?array
$service->getSessionsForEdition(int $editionId): array
$service->getSessionCount(int $editionId): int
$service->getDayCount(int $editionId): int
$service->getTotalHours(int $editionId): float
```

### EnrollmentService (`Stride\Modules\Enrollment\EnrollmentService`)
```php
$service = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$service->enroll(int $userId, int $editionId, array $options = []): int|WP_Error
$service->cancel(int $registrationId): bool|WP_Error
$service->isEnrolled(int $userId, int $editionId): bool
$service->getUserEnrollments(int $userId): array  // Returns stdClass objects
$service->getRegistration(int $registrationId): stdClass|WP_Error
```

### QuoteService (`Stride\Modules\Invoicing\QuoteService`)
```php
$service = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
$service->getQuote(int $quoteId): array|WP_Error
$service->getUserQuotes(int $userId): array
$service->getQuoteByRegistration(int $registrationId): ?array
```

### TrajectoryService (`Stride\Modules\Trajectory\TrajectoryService`)
```php
$service = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);
$service->getTrajectory(int $trajectoryId): ?array
$service->getActiveTrajectories(): array
$service->getOpenTrajectories(): array
$service->getCourses(int $trajectoryId): array
$service->getRequiredCourses(int $trajectoryId): array
$service->getElectiveGroups(int $trajectoryId): array
$service->getCourseCount(int $trajectoryId): int
$service->isEnrollmentOpen(int $trajectoryId): bool
```

### AttendanceService (`Stride\Modules\Attendance\AttendanceService`)
```php
$service = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
$service->countAttended(int $userId, int $editionId): int
$service->getHoursAttended(int $userId, int $editionId): float
$service->getAttendanceRate(int $userId, int $editionId): float
```

### CompletionService (`Stride\Modules\Completion\CompletionService`)
```php
$service = ntdst_get(\Stride\Modules\Completion\CompletionService::class);
$service->isComplete(int $editionId, int $userId): bool
$service->getProgress(int $editionId, int $userId): array
```

### Existing Shortcodes (in stride-core)
- `[stride_my_courses]` - DashboardShortcode
- `[stride_my_quotes]` - QuotesShortcode

---

## Task 1: Design System CSS

**Files:**
- Create: `web/app/themes/stride/assets/css/stride.css`
- Modify: `web/app/themes/stride/functions.php`

**Step 1: Create design system CSS with Headspace colors and components**

**Step 2: Enqueue Inter font and stride.css in functions.php**

**Step 3: Verify CSS loads in browser**

**Step 4: Commit**

---

## Task 2: Shell Templates (Header/Footer/Nav)

**Files:**
- Create: `web/app/themes/stride/templates/shell/mobile-header.php`
- Create: `web/app/themes/stride/templates/shell/desktop-nav.php`
- Create: `web/app/themes/stride/templates/shell/bottom-nav.php`
- Modify: `web/app/themes/stride/header.php`
- Modify: `web/app/themes/stride/footer.php`

**Step 1: Create mobile header with logo and user icon**

**Step 2: Create desktop navigation with menu and user dropdown**

**Step 3: Create bottom navigation (mobile only, logged-in users)**

**Step 4: Update header.php to include shell templates**

**Step 5: Update footer.php to include bottom nav**

**Step 6: Verify shell works on mobile and desktop**

**Step 7: Commit**

---

## Task 3: Dashboard Home Page

**Files:**
- Create: `web/app/themes/stride/templates/dashboard/home.php`
- Create: `web/app/themes/stride/page-mijn-account.php`

**Step 1: Create dashboard home template with:**
- Time-aware greeting
- Progress card (uses CompletionService)
- Upcoming sessions (uses SessionService)
- Active courses list (uses EnrollmentService, EditionService)
- Empty state with CTA

**Step 2: Create page template file**

**Step 3: Verify dashboard works for logged-in users**

**Step 4: Commit**

---

## Task 4: Course Catalog Page

**Files:**
- Create: `web/app/themes/stride/templates/course/catalog.php`
- Create: `web/app/themes/stride/page-cursussen.php`

**Step 1: Create course catalog template with:**
- Edition cards from EditionService::getUpcomingEditions()
- Price display using EditionService::getPrice()
- Day count using SessionService::getDayCount()
- Online/Klassikaal badge
- Enrollment status

**Step 2: Create page template file**

**Step 3: Verify catalog displays editions**

**Step 4: Commit**

---

## Task 5: Edition Detail Page

**Files:**
- Modify: `web/app/themes/stride/single-vad_edition.php`

**Step 1: Update edition detail with:**
- Hero image from linked course
- Tabs: Over, Programma
- Session list from SessionService
- Price and capacity info
- Sticky CTA with enrollment button

**Step 2: Verify edition detail works**

**Step 3: Commit**

---

## Task 6: My Courses Page

**Files:**
- Create: `web/app/themes/stride/templates/dashboard/courses.php`
- Create: `web/app/themes/stride/page-mijn-cursussen.php`

**Step 1: Create my courses template using [stride_my_courses] shortcode**

**Step 2: Create page template file**

**Step 3: Commit**

---

## Task 7: My Quotes Page

**Files:**
- Create: `web/app/themes/stride/templates/dashboard/quotes.php`
- Create: `web/app/themes/stride/page-offertes.php`

**Step 1: Create my quotes template using [stride_my_quotes] shortcode**

**Step 2: Create page template file**

**Step 3: Commit**

---

## Task 8: Trajectory Catalog Page

**Files:**
- Create: `web/app/themes/stride/templates/trajectory/catalog.php`
- Create: `web/app/themes/stride/page-trajecten.php`

**Step 1: Create trajectory catalog template with:**
- Trajectory cards from TrajectoryService::getOpenTrajectories()
- Course count using TrajectoryService::getCourseCount()
- Price display

**Step 2: Create page template file**

**Step 3: Commit**

---

## Task 9: Trajectory Detail Page

**Files:**
- Modify: `web/app/themes/stride/single-vad_trajectory.php`

**Step 1: Update trajectory detail with:**
- Required courses list
- Elective groups
- Enrollment deadline
- CTA

**Step 2: Commit**

---

## Task 10: LearnDash Focus Mode Styling

**Files:**
- Create: `web/app/themes/stride/assets/css/focus-mode.css`
- Modify: `web/app/themes/stride/functions.php`

**Step 1: Create Focus Mode CSS with Stride colors**

**Step 2: Conditionally enqueue on LearnDash content**

**Step 3: Commit**

---

## Task 11: PWA Manifest

**Files:**
- Create: `web/manifest.json`
- Modify: `web/app/themes/stride/functions.php`

**Step 1: Create PWA manifest**

**Step 2: Add manifest link to head**

**Step 3: Commit**

---

## Task 12: Profile Page

**Files:**
- Create: `web/app/themes/stride/templates/dashboard/profile.php`
- Create: `web/app/themes/stride/page-profiel.php`

**Step 1: Create profile template with user info and quick links**

**Step 2: Create page template file**

**Step 3: Commit**

---

## Verification Checklist

After all tasks:
- [ ] `/cursussen/` loads course catalog
- [ ] `/trajecten/` loads trajectory catalog
- [ ] `/mijn-account/` loads dashboard
- [ ] `/mijn-account/cursussen/` loads my courses
- [ ] `/mijn-account/offertes/` loads my quotes
- [ ] `/mijn-account/profiel/` loads profile
- [ ] Mobile bottom nav appears on mobile
- [ ] Desktop nav appears on desktop
- [ ] LearnDash Focus Mode has Stride styling
