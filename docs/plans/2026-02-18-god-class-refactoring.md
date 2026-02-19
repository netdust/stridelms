# God Class Refactoring Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Eliminate God classes by extracting inline CSS/HTML to external files and splitting large services into focused, single-responsibility classes following established NTDST patterns.

**Architecture:** Extract presentation assets (CSS/HTML) to separate files. Split data services by domain. Convert AJAX handlers in theme to thin handlers in stride-core. Each class should be ~150 lines max, ~20 lines per method.

**Tech Stack:** PHP 8.3, WordPress, Alpine.js, NTDST Framework

---

## Established Patterns (Reference)

**Handler Pattern** (from previous refactoring):
```php
// Handlers are thin classes, no constructor DI, use ntdst_get() in methods
final class ExampleHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('wp_ajax_action_name', [$this, 'ajaxMethod']);
    }

    public function ajaxMethod(): void
    {
        // Nonce verification
        // Delegate to service via ntdst_get()
        // Return JSON response
    }
}
```

**Service Instantiates Handler** (in service's init() method):
```php
protected function init(): void
{
    ntdst_get(\Stride\Handlers\MyHandler::class);
}
```

---

## Phase 1: AdminDashboardService CSS/HTML Extraction

### Task 1.1: Create Admin CSS File

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/css/admin-dashboard.css`

**Step 1: Create assets directory structure**

```bash
mkdir -p web/app/mu-plugins/stride-core/assets/css
```

**Step 2: Extract CSS from AdminDashboardService**

Copy lines 202-1618 (the CSS inside `injectStyles()`) to the new CSS file. Remove the opening `echo '<style id="stride-dashboard-styles">` and closing `</style>';`.

The CSS file should start with:
```css
/* Stride Admin Dashboard Styles */
:root {
    --stride-bg: #f8fafc;
    --stride-card: #ffffff;
    /* ... rest of CSS variables and rules ... */
}
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/css/admin-dashboard.css
git commit -m "feat(admin): extract dashboard CSS to external file"
```

---

### Task 1.2: Update AdminDashboardService to Load External CSS

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php`

**Step 1: Replace injectStyles() method**

Replace the entire `injectStyles()` method (lines ~196-1619) with:

```php
/**
 * Inject CSS to hide WordPress UI
 */
public function injectStyles(): void
{
    if (!$this->isStridePage()) {
        return;
    }

    $cssPath = dirname(__DIR__) . '/assets/css/admin-dashboard.css';
    if (file_exists($cssPath)) {
        echo '<style id="stride-dashboard-styles">';
        include $cssPath;
        echo '</style>';
    }
}
```

**Step 2: Verify file loads correctly**

Run: `ddev exec wp eval "echo class_exists('\Stride\Admin\AdminDashboardService') ? 'OK' : 'FAIL';"`
Expected: OK

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "refactor(admin): load dashboard CSS from external file"
```

---

### Task 1.3: Create Admin Dashboard Template

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/admin/dashboard.php`

**Step 1: Create templates directory**

```bash
mkdir -p web/app/mu-plugins/stride-core/templates/admin
```

**Step 2: Extract HTML from renderDashboard()**

Create the template file with all the HTML from `renderDashboard()` method (lines 1624-2621). The template should be pure HTML/PHP with Alpine.js directives, starting with:

```php
<?php
/**
 * Admin Dashboard Template
 *
 * @var string $admin_url
 * @var string $user_name
 */
defined('ABSPATH') || exit;
?>
<div class="wrap stride-app" x-data="strideApp()">
    <!-- Header -->
    <header class="stride-header">
        <!-- ... rest of HTML ... -->
    </header>
    <!-- ... -->
</div>
```

Replace hardcoded PHP calls with template variables passed from the service.

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/templates/admin/dashboard.php
git commit -m "feat(admin): extract dashboard HTML to template file"
```

---

### Task 1.4: Update renderDashboard() to Use Template

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php`

**Step 1: Replace renderDashboard() method**

```php
/**
 * Render the dashboard page
 */
public function renderDashboard(): void
{
    $templatePath = dirname(__DIR__) . '/templates/admin/dashboard.php';

    $admin_url = admin_url();
    $user = wp_get_current_user();
    $user_name = $user->display_name;

    if (file_exists($templatePath)) {
        include $templatePath;
    }
}
```

**Step 2: Verify dashboard still renders**

Visit: https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-dashboard
Expected: Dashboard renders correctly

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "refactor(admin): use template for dashboard rendering"
```

---

### Task 1.5: Extract Admin JavaScript to External File

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js`
- Modify: `web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php`

**Step 1: Create JS directory and extract script**

```bash
mkdir -p web/app/mu-plugins/stride-core/assets/js
```

Extract the JavaScript from `injectScripts()` method (the content inside the `<script>` tags) to `admin-dashboard.js`.

**Step 2: Update injectScripts() to load external file**

```php
/**
 * Inject JavaScript
 */
public function injectScripts(): void
{
    if (!$this->isStridePage()) {
        return;
    }

    $jsPath = dirname(__DIR__) . '/assets/js/admin-dashboard.js';
    if (file_exists($jsPath)) {
        echo '<script>';
        include $jsPath;
        echo '</script>';
    }
}
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "refactor(admin): extract dashboard JavaScript to external file"
```

---

## Phase 2: DashboardService AJAX Handler Extraction

### Task 2.1: Create ProfileHandler in stride-core

**Files:**
- Create: `web/app/mu-plugins/stride-core/Handlers/ProfileHandler.php`

**Step 1: Create the handler**

```php
<?php

declare(strict_types=1);

namespace Stride\Handlers;

use WP_Error;

/**
 * Handles user profile AJAX requests.
 *
 * Thin handler - validates input, updates user data.
 */
final class ProfileHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('wp_ajax_stride_update_profile', [$this, 'ajaxUpdateProfile']);
    }

    /**
     * AJAX: Update user profile.
     */
    public function ajaxUpdateProfile(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_profile')) {
            wp_send_json_error(['message' => __('Ongeldige beveiligingstoken.', 'stride')]);
        }

        $result = $this->handleUpdateProfile($_POST);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success($result);
    }

    /**
     * Handle profile update.
     *
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleUpdateProfile(array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        // Sanitize input
        $firstName = sanitize_text_field($params['first_name'] ?? '');
        $lastName = sanitize_text_field($params['last_name'] ?? '');
        $phone = sanitize_text_field($params['phone'] ?? '');
        $company = sanitize_text_field($params['company'] ?? '');

        // Update user
        $result = wp_update_user([
            'ID' => $userId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($firstName . ' ' . $lastName),
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        // Update meta
        if ($phone) {
            update_user_meta($userId, 'phone', $phone);
        }
        if ($company) {
            update_user_meta($userId, 'company', $company);
        }

        return [
            'success' => true,
            'message' => __('Profiel bijgewerkt.', 'stride'),
        ];
    }
}
```

**Step 2: Verify handler created**

Run: `ddev exec wp eval "echo class_exists('\Stride\Handlers\ProfileHandler') ? 'OK' : 'FAIL';"`
Expected: OK

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/ProfileHandler.php
git commit -m "feat(handlers): add ProfileHandler for user profile AJAX"
```

---

### Task 2.2: Create ICalHandler in stride-core

**Files:**
- Create: `web/app/mu-plugins/stride-core/Handlers/ICalHandler.php`

**Step 1: Create the handler**

```php
<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Handles iCal download requests.
 *
 * Thin handler - generates iCal files for user's sessions.
 */
final class ICalHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('wp_ajax_stride_download_ical', [$this, 'ajaxDownloadIcal']);
    }

    /**
     * AJAX: Download iCal file.
     */
    public function ajaxDownloadIcal(): void
    {
        if (!wp_verify_nonce($_GET['nonce'] ?? '', 'stride_ical')) {
            wp_die(__('Ongeldige link.', 'stride'));
        }

        $userId = get_current_user_id();
        if (!$userId) {
            wp_die(__('Je moet ingelogd zijn.', 'stride'));
        }

        $sessionId = absint($_GET['session_id'] ?? 0);
        $editionId = absint($_GET['edition_id'] ?? 0);

        $ical = $this->generateIcal($userId, $sessionId, $editionId);

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="stride-calendar.ics"');
        echo $ical;
        exit;
    }

    /**
     * Generate iCal content.
     */
    private function generateIcal(int $userId, int $sessionId, int $editionId): string
    {
        $events = [];

        $sessionService = ntdst_get(SessionService::class);
        $editionService = ntdst_get(EditionService::class);

        if ($sessionId) {
            // Single session
            $session = $sessionService->getSession($sessionId);
            if ($session) {
                $events[] = $this->sessionToEvent($session, $editionService);
            }
        } elseif ($editionId) {
            // All sessions for edition
            $sessions = $sessionService->getSessionsForEdition($editionId);
            foreach ($sessions as $session) {
                $events[] = $this->sessionToEvent($session, $editionService);
            }
        } else {
            // All user's upcoming sessions
            $registrationRepo = ntdst_get(RegistrationRepository::class);
            $registrations = $registrationRepo->getByUser($userId, 'confirmed');

            foreach ($registrations as $reg) {
                $sessions = $sessionService->getSessionsForEdition($reg['edition_id']);
                foreach ($sessions as $session) {
                    $events[] = $this->sessionToEvent($session, $editionService);
                }
            }
        }

        return $this->buildIcal($events);
    }

    /**
     * Convert session to iCal event array.
     */
    private function sessionToEvent(array $session, EditionService $editionService): array
    {
        $edition = $editionService->getEdition($session['edition_id']);
        $course = get_post($edition['course_id'] ?? 0);

        return [
            'uid' => 'session-' . $session['id'] . '@stride',
            'summary' => $course ? $course->post_title : 'Stride Training',
            'description' => $session['description'] ?? '',
            'location' => $edition['venue'] ?? '',
            'start' => $session['start_time'] ?? '',
            'end' => $session['end_time'] ?? '',
        ];
    }

    /**
     * Build iCal file content.
     */
    private function buildIcal(array $events): string
    {
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//Stride//Calendar//NL\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";

        foreach ($events as $event) {
            if (empty($event['start'])) {
                continue;
            }

            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "UID:" . $event['uid'] . "\r\n";
            $ical .= "SUMMARY:" . $this->escapeIcal($event['summary']) . "\r\n";
            $ical .= "DTSTART:" . $this->formatIcalDate($event['start']) . "\r\n";
            if (!empty($event['end'])) {
                $ical .= "DTEND:" . $this->formatIcalDate($event['end']) . "\r\n";
            }
            if (!empty($event['location'])) {
                $ical .= "LOCATION:" . $this->escapeIcal($event['location']) . "\r\n";
            }
            if (!empty($event['description'])) {
                $ical .= "DESCRIPTION:" . $this->escapeIcal($event['description']) . "\r\n";
            }
            $ical .= "END:VEVENT\r\n";
        }

        $ical .= "END:VCALENDAR\r\n";

        return $ical;
    }

    /**
     * Escape string for iCal.
     */
    private function escapeIcal(string $text): string
    {
        return str_replace(["\n", "\r", ",", ";", "\\"], ["\\n", "", "\\,", "\\;", "\\\\"], $text);
    }

    /**
     * Format date for iCal.
     */
    private function formatIcalDate(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        return gmdate('Ymd\THis\Z', $timestamp);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/ICalHandler.php
git commit -m "feat(handlers): add ICalHandler for calendar downloads"
```

---

### Task 2.3: Register Handlers in DashboardService

**Files:**
- Modify: `web/app/themes/stride/services/frontend/DashboardService.php`

**Step 1: Update registerAjaxHandlers() to instantiate handlers**

Replace the `registerAjaxHandlers()` method:

```php
/**
 * Register AJAX handlers
 */
public function registerAjaxHandlers(): void
{
    // Instantiate handlers from stride-core
    ntdst_get(\Stride\Handlers\ProfileHandler::class);
    ntdst_get(\Stride\Handlers\ICalHandler::class);
}
```

**Step 2: Remove handleProfileUpdate() and handleIcalDownload() methods**

Delete the `handleProfileUpdate()` method (around line 983-1028) and `handleIcalDownload()` method (around line 1030-1145) from DashboardService.

**Step 3: Verify handlers still work**

Run: `ddev exec wp eval "echo has_action('wp_ajax_stride_update_profile') ? 'OK' : 'FAIL';"`
Expected: OK

**Step 4: Commit**

```bash
git add web/app/themes/stride/services/frontend/DashboardService.php
git commit -m "refactor(dashboard): move AJAX handlers to stride-core handlers"
```

---

## Phase 3: DashboardShortcodes Split

### Task 3.1: Create CourseShortcodes Class

**Files:**
- Create: `web/app/themes/stride/services/frontend/shortcodes/CourseShortcodes.php`

**Step 1: Create shortcodes directory**

```bash
mkdir -p web/app/themes/stride/services/frontend/shortcodes
```

**Step 2: Create CourseShortcodes class**

Extract `renderCourseCatalog()` and `renderCourseSidebar()` methods:

```php
<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * Course-related shortcodes.
 *
 * - [stride_course_catalog] - Course listing/archive
 * - [stride_course_sidebar] - Course action sidebar
 */
final class CourseShortcodes
{
    private ?DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService;
    }

    /**
     * Register shortcodes.
     */
    public function register(): void
    {
        add_shortcode('stride_course_catalog', [$this, 'renderCourseCatalog']);
        add_shortcode('stride_course_sidebar', [$this, 'renderCourseSidebar']);
    }

    /**
     * [stride_course_sidebar] - Course action sidebar
     */
    public function renderCourseSidebar(array $atts = []): string
    {
        // Extract method from DashboardShortcodes (lines 341-365)
        // ...
    }

    /**
     * [stride_course_catalog] - Course listing/archive
     */
    public function renderCourseCatalog(array $atts = []): string
    {
        // Extract method from DashboardShortcodes (lines 370-546)
        // ...
    }

    // Helper methods as needed
}
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/services/frontend/shortcodes/CourseShortcodes.php
git commit -m "feat(shortcodes): create CourseShortcodes class"
```

---

### Task 3.2: Create TrajectoryShortcodes Class

**Files:**
- Create: `web/app/themes/stride/services/frontend/shortcodes/TrajectoryShortcodes.php`

**Step 1: Create TrajectoryShortcodes class**

Extract `renderMyTrajectories()`, `renderTrajectory()`, and `renderTrajectoryCatalog()` methods.

```php
<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * Trajectory-related shortcodes.
 *
 * - [stride_my_trajectories] - User's trajectories
 * - [stride_trajectory] - Single trajectory view
 * - [stride_trajectory_catalog] - Public trajectory catalog
 */
final class TrajectoryShortcodes
{
    private ?DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService;
    }

    public function register(): void
    {
        add_shortcode('stride_my_trajectories', [$this, 'renderMyTrajectories']);
        add_shortcode('stride_trajectory', [$this, 'renderTrajectory']);
        add_shortcode('stride_trajectory_catalog', [$this, 'renderTrajectoryCatalog']);
    }

    // Extract methods from DashboardShortcodes
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/services/frontend/shortcodes/TrajectoryShortcodes.php
git commit -m "feat(shortcodes): create TrajectoryShortcodes class"
```

---

### Task 3.3: Create QuoteShortcodes Class

**Files:**
- Create: `web/app/themes/stride/services/frontend/shortcodes/QuoteShortcodes.php`

**Step 1: Create QuoteShortcodes class**

Extract `renderMyQuotes()` and `renderQuoteUpdateForm()` methods.

```php
<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * Quote-related shortcodes.
 *
 * - [stride_my_quotes] - User's quotes list
 * - [stride_quote_update] - Quote update form
 */
final class QuoteShortcodes
{
    private ?DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService;
    }

    public function register(): void
    {
        add_shortcode('stride_my_quotes', [$this, 'renderMyQuotes']);
        add_shortcode('stride_quote_update', [$this, 'renderQuoteUpdateForm']);
    }

    // Extract methods from DashboardShortcodes
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/services/frontend/shortcodes/QuoteShortcodes.php
git commit -m "feat(shortcodes): create QuoteShortcodes class"
```

---

### Task 3.4: Create EnrollmentShortcodes Class

**Files:**
- Create: `web/app/themes/stride/services/frontend/shortcodes/EnrollmentShortcodes.php`

**Step 1: Create EnrollmentShortcodes class**

Extract `renderEnrollmentForm()`, `renderEdition()`, and `renderSessionSelection()` methods.

```php
<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * Enrollment-related shortcodes.
 *
 * - [stride_enrollment] - Enrollment form
 * - [stride_edition] - Single edition page
 * - [stride_session_selection] - Session selection UI
 */
final class EnrollmentShortcodes
{
    private ?DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService;
    }

    public function register(): void
    {
        add_shortcode('stride_enrollment', [$this, 'renderEnrollmentForm']);
        add_shortcode('stride_edition', [$this, 'renderEdition']);
        add_shortcode('stride_session_selection', [$this, 'renderSessionSelection']);
    }

    // Extract methods from DashboardShortcodes
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/services/frontend/shortcodes/EnrollmentShortcodes.php
git commit -m "feat(shortcodes): create EnrollmentShortcodes class"
```

---

### Task 3.5: Create UserDashboardShortcodes Class

**Files:**
- Create: `web/app/themes/stride/services/frontend/shortcodes/UserDashboardShortcodes.php`

**Step 1: Create UserDashboardShortcodes class**

Extract `renderDashboard()`, `renderMyCourses()`, `renderMyProfile()`, and `renderMyCalendar()` methods.

```php
<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

use stride\services\frontend\DashboardService;

/**
 * User dashboard shortcodes.
 *
 * - [stride_dashboard] - Main dashboard home
 * - [stride_my_courses] - User's enrolled courses
 * - [stride_my_profile] - User profile edit
 * - [stride_my_calendar] - User's upcoming dates
 */
final class UserDashboardShortcodes
{
    private ?DashboardService $dashboardService;

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService;
    }

    public function register(): void
    {
        add_shortcode('stride_dashboard', [$this, 'renderDashboard']);
        add_shortcode('stride_my_courses', [$this, 'renderMyCourses']);
        add_shortcode('stride_my_profile', [$this, 'renderMyProfile']);
        add_shortcode('stride_my_calendar', [$this, 'renderMyCalendar']);
    }

    // Extract methods from DashboardShortcodes
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/services/frontend/shortcodes/UserDashboardShortcodes.php
git commit -m "feat(shortcodes): create UserDashboardShortcodes class"
```

---

### Task 3.6: Create ShortcodeBase Trait

**Files:**
- Create: `web/app/themes/stride/services/frontend/shortcodes/ShortcodeBase.php`

**Step 1: Create shared trait for common functionality**

```php
<?php

namespace stride\services\frontend\shortcodes;

defined('ABSPATH') || exit;

/**
 * Shared functionality for shortcode classes.
 */
trait ShortcodeBase
{
    /**
     * Get template path.
     */
    private function getTemplatePath(string $template): string
    {
        return get_stylesheet_directory() . '/templates/' . $template;
    }

    /**
     * Render a template with data.
     */
    private function renderTemplate(string $template, array $data = []): string
    {
        $templatePath = $this->getTemplatePath($template);

        if (!file_exists($templatePath)) {
            if (current_user_can('manage_options')) {
                return '<div class="uk-alert uk-alert-warning">Template not found: ' . esc_html($template) . '</div>';
            }
            return '';
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $templatePath;
        return ob_get_clean();
    }

    /**
     * Check if user is logged in and return login message if not.
     */
    private function requireLogin(): ?string
    {
        if (is_user_logged_in()) {
            return null;
        }

        return $this->renderTemplate('dashboard/login-required.php', [
            'login_url' => wp_login_url(get_permalink()),
            'register_url' => wp_registration_url(),
        ]);
    }

    /**
     * Resolve service from DI container.
     */
    private function resolveService(string $class): ?object
    {
        if (function_exists('ntdst_get')) {
            try {
                return ntdst_get($class);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }
}
```

**Step 2: Update shortcode classes to use trait**

Add `use ShortcodeBase;` to each shortcode class.

**Step 3: Commit**

```bash
git add web/app/themes/stride/services/frontend/shortcodes/ShortcodeBase.php
git commit -m "feat(shortcodes): add ShortcodeBase trait for shared functionality"
```

---

### Task 3.7: Update DashboardShortcodes to Delegate

**Files:**
- Modify: `web/app/themes/stride/services/frontend/DashboardShortcodes.php`

**Step 1: Refactor DashboardShortcodes to instantiate and register sub-classes**

```php
<?php

namespace stride\services\frontend;

defined('ABSPATH') || exit;

use stride\services\frontend\shortcodes\CourseShortcodes;
use stride\services\frontend\shortcodes\TrajectoryShortcodes;
use stride\services\frontend\shortcodes\QuoteShortcodes;
use stride\services\frontend\shortcodes\EnrollmentShortcodes;
use stride\services\frontend\shortcodes\UserDashboardShortcodes;

/**
 * Dashboard Shortcodes Service
 *
 * Orchestrates registration of all dashboard shortcodes.
 * Each domain has its own shortcode class.
 */
class DashboardShortcodes implements \NTDST_Service_Meta
{
    private ?DashboardService $dashboardService;

    public static function metadata(): array
    {
        return [
            'name' => 'Dashboard Shortcodes',
            'description' => 'Registers dashboard shortcodes and template routing',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 20,
        ];
    }

    public function __construct(?DashboardService $dashboardService = null)
    {
        $this->dashboardService = $dashboardService ?? $this->resolveService(DashboardService::class);

        add_action('init', [$this, 'registerShortcodes']);
    }

    private function resolveService(string $class): ?object
    {
        if (function_exists('ntdst_get')) {
            try {
                return ntdst_get($class);
            } catch (\Exception $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Register all shortcodes via sub-classes.
     */
    public function registerShortcodes(): void
    {
        // User dashboard shortcodes
        (new UserDashboardShortcodes($this->dashboardService))->register();

        // Course shortcodes
        (new CourseShortcodes($this->dashboardService))->register();

        // Trajectory shortcodes
        (new TrajectoryShortcodes($this->dashboardService))->register();

        // Quote shortcodes
        (new QuoteShortcodes($this->dashboardService))->register();

        // Enrollment shortcodes
        (new EnrollmentShortcodes($this->dashboardService))->register();
    }
}
```

**Step 2: Verify all shortcodes still work**

Run: `ddev exec wp eval "echo shortcode_exists('stride_dashboard') && shortcode_exists('stride_course_catalog') ? 'OK' : 'FAIL';"`
Expected: OK

**Step 3: Commit**

```bash
git add web/app/themes/stride/services/frontend/DashboardShortcodes.php
git commit -m "refactor(shortcodes): delegate to domain-specific shortcode classes"
```

---

## Phase 4: Verification and Cleanup

### Task 4.1: Run All Tests

**Step 1: Run handler tests**

```bash
ddev exec wp eval-file scripts/test-handlers.php
```

Expected: All PASS

**Step 2: Run enrollment form tests**

```bash
ddev exec wp eval-file scripts/test-enrollment-form.php
```

Expected: All tests passed

**Step 3: Verify shortcodes**

```bash
ddev exec wp eval "
\$shortcodes = ['stride_dashboard', 'stride_my_courses', 'stride_course_catalog', 'stride_trajectory_catalog', 'stride_enrollment', 'stride_my_quotes'];
foreach (\$shortcodes as \$sc) {
    echo \$sc . ': ' . (shortcode_exists(\$sc) ? 'OK' : 'FAIL') . PHP_EOL;
}
"
```

Expected: All OK

**Step 4: Commit test verification**

```bash
git add -A
git commit -m "test: verify all refactored components work correctly"
```

---

### Task 4.2: Update Line Counts Report

**Step 1: Check final line counts**

```bash
wc -l web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
wc -l web/app/themes/stride/services/frontend/DashboardShortcodes.php
wc -l web/app/themes/stride/services/frontend/DashboardService.php
```

Expected:
- AdminDashboardService: ~100-150 lines (down from 3027)
- DashboardShortcodes: ~50 lines (down from 869)
- DashboardService: ~800 lines (still needs future work but AJAX handlers removed)

---

## Files Summary

### Phase 1 - Admin Dashboard
| File | Action | Purpose |
|------|--------|---------|
| `mu-plugins/stride-core/assets/css/admin-dashboard.css` | Create | External CSS file |
| `mu-plugins/stride-core/assets/js/admin-dashboard.js` | Create | External JS file |
| `mu-plugins/stride-core/templates/admin/dashboard.php` | Create | Dashboard template |
| `mu-plugins/stride-core/Admin/AdminDashboardService.php` | Modify | Load external assets |

### Phase 2 - Handlers
| File | Action | Purpose |
|------|--------|---------|
| `mu-plugins/stride-core/Handlers/ProfileHandler.php` | Create | Profile AJAX |
| `mu-plugins/stride-core/Handlers/ICalHandler.php` | Create | Calendar AJAX |
| `themes/stride/services/frontend/DashboardService.php` | Modify | Remove AJAX methods |

### Phase 3 - Shortcodes
| File | Action | Purpose |
|------|--------|---------|
| `themes/stride/services/frontend/shortcodes/ShortcodeBase.php` | Create | Shared trait |
| `themes/stride/services/frontend/shortcodes/UserDashboardShortcodes.php` | Create | User dashboard |
| `themes/stride/services/frontend/shortcodes/CourseShortcodes.php` | Create | Course shortcodes |
| `themes/stride/services/frontend/shortcodes/TrajectoryShortcodes.php` | Create | Trajectory shortcodes |
| `themes/stride/services/frontend/shortcodes/QuoteShortcodes.php` | Create | Quote shortcodes |
| `themes/stride/services/frontend/shortcodes/EnrollmentShortcodes.php` | Create | Enrollment shortcodes |
| `themes/stride/services/frontend/DashboardShortcodes.php` | Modify | Orchestrate sub-classes |

---

## Future Work (Out of Scope)

The following are identified but not addressed in this plan:
- **DashboardService** (1458 lines) - Split into domain-specific data services
- **AdminAPIController** (1636 lines) - Split into endpoint handlers
- **TrajectoryAdminController** (1251 lines) - Extract metaboxes
- **EditionAdminController** (953 lines) - Extract metaboxes
- **QuoteAdminController** (733 lines) - Extract metaboxes
- **VoucherAdminController** (633 lines) - Extract metaboxes

These should be addressed in future refactoring iterations following the same patterns established here.
