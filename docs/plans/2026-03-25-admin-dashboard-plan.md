# Admin Dashboard Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite the Stride admin dashboard frontend (CSS + template + JS) with a Soft Violet design system and add new backend features: action queue with configurable rules, user tracking with audit trail and impersonation, health checks, admin activity feed.

**Architecture:** Full-screen Alpine.js SPA (existing pattern). Three frontend files rewritten: `dashboard.php`, `admin-dashboard.css`, `admin-dashboard.js`. Backend additions to `AdminAPIController` for new endpoints. Three new plain classes: `ActionQueueService`, `HealthCheckService`, `AdminActivityMapper`. New settings tab for alert rules. Impersonation via server-side transient + cookie pattern.

**Tech Stack:** Alpine.js 3.x, Flatpickr, PHP 8.3, WordPress REST API, CSS custom properties

**Spec:** `docs/plans/2026-03-25-admin-dashboard-redesign.md`

---

## File Structure

### Files to Rewrite (full replacement)

| File | Responsibility |
|------|---------------|
| `stride-core/templates/admin/dashboard.php` | Alpine.js app template — 5 views, header, slide-overs |
| `stride-core/assets/css/admin-dashboard.css` | Soft Violet design system — all component styles |
| `stride-core/assets/js/admin-dashboard.js` | Alpine app state, API calls, routing, formatting |

### Files to Modify

| File | Changes |
|------|---------|
| `stride-core/Admin/AdminDashboardService.php` | Pass new localized data (firstName, alert rules config) |
| `stride-core/Admin/AdminAPIController.php` | Add 7 new REST routes + handler methods |
| `stride-core/Admin/StrideSettingsService.php` | Add `notifications` tab save handler + option constant |
| `stride-core/templates/admin/settings.php` | Add `notifications` tab entry |

### Files to Create

| File | Responsibility |
|------|---------------|
| `stride-core/Admin/ActionQueueService.php` | Evaluates configured alert rules, returns prioritized items |
| `stride-core/Admin/HealthCheckService.php` | Checks registration flow + mail delivery status |
| `stride-core/Admin/AdminActivityMapper.php` | Admin-perspective strings from audit log entries |
| `stride-core/Admin/ImpersonationHandler.php` | Session switching, cookie/transient management, admin bar button |
| `stride-core/templates/admin/settings/tab-notifications.php` | Meldingen settings tab UI |

### Test Files to Create

| File | Covers |
|------|--------|
| `tests/Unit/ActionQueueServiceTest.php` | Rule evaluation logic |
| `tests/Unit/HealthCheckServiceTest.php` | Health check logic |
| `tests/Unit/AdminActivityMapperTest.php` | Audit entry → admin string mapping |
| `tests/Unit/ImpersonationHandlerTest.php` | Security validation, cookie/transient logic |

### Spec Deviations (documented)

| Spec | Plan | Reason |
|------|------|--------|
| `GET/POST /admin/alert-rules` endpoints | Settings AJAX tab via `stride_save_settings` | Reuses existing settings infrastructure — no redundant REST endpoints |

---

## Phase 1: Design System + Layout Shell (CSS + Template Structure)

### Task 1: CSS Design System — Soft Violet

**Files:**
- Rewrite: `stride-core/assets/css/admin-dashboard.css`

This is the foundation. All other tasks depend on these styles existing.

- [ ] **Step 1: Write the CSS custom properties and reset**

Write the complete CSS file with:
- CSS custom properties (color palette from spec)
- WordPress admin chrome hiding (admin bar, sidebar, notices)
- Full-screen layout with `stride-dashboard` body class
- Base typography (system font stack, font sizes, weights)

```css
/* === Custom Properties === */
:root {
    --primary: #7c3aed;
    --primary-light: #ede9fe;
    --primary-bg: #f8f7fc;
    --primary-subtle: #f5f3ff;
    --text-primary: #1e1b3a;
    --text-secondary: #475569;
    --text-muted: #a78bfa;
    --success: #22c55e;
    --warning: #f59e0b;
    --danger: #ef4444;
    --surface: #ffffff;
    --border: #ede9fe;
}
```

- [ ] **Step 2: Add component styles**

Add styles for all reusable components:
- `.sd-header` — top bar (56px height, white bg, bottom border)
- `.sd-header__nav` — horizontal tab navigation
- `.sd-header__tab` — tab buttons with active underline (`--primary`)
- `.sd-kpi-row` — horizontal flex container for KPI cards
- `.sd-kpi-card` — white card with border, label/value/trend
- `.sd-card` — generic content card (white bg, border, rounded corners 12px, padding 20px)
- `.sd-card__title` — card heading (13px, 600 weight)
- `.sd-table` — clean table (no outer border, row dividers `--primary-subtle`)
- `.sd-table__row--today` — today row highlight
- `.sd-table__row--past` — dimmed past row
- `.sd-badge` — pill badge with per-status colors (open, vol, geannuleerd, etc.)
- `.sd-badge--priority-*` — priority dots (red, amber, blue) for action queue
- `.sd-btn` — primary button (`--primary` bg)
- `.sd-btn--ghost` — ghost button (`--primary-light` bg)
- `.sd-avatar` — 32px circle with initials
- `.sd-slideout` — right-aligned 600px panel with overlay
- `.sd-slideout__overlay` — backdrop
- `.sd-input`, `.sd-select` — form inputs with focus ring
- `.sd-empty` — empty state (centered icon + text)
- `.sd-toast` — notification toast (bottom-right)
- `.sd-popover` — confirmation popover (for quote quick-send)
- `.sd-skeleton` — loading skeleton animation

- [ ] **Step 3: Add layout styles**

- `.sd-layout` — two-column grid (60/40 split)
- `.sd-layout__primary` — left column
- `.sd-layout__secondary` — right column
- `.sd-content` — scrollable content area below header
- Responsive breakpoints: 1400px (stack columns), 768px (mobile)

- [ ] **Step 4: Add specific component styles**

- Action queue items (priority dot + text + link)
- Activity feed (avatar + text + timestamp)
- User detail layout
- Attendance grid cells
- Notification bell dropdown
- Tab underline animation
- Flatpickr theme overrides (match violet palette)

- [ ] **Step 5: Verify CSS loads correctly**

```bash
ddev exec wp eval "
    echo file_exists(ABSPATH . '../app/mu-plugins/stride-core/assets/css/admin-dashboard.css') ? 'CSS exists' : 'MISSING';
"
```

Visit `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-dashboard` and verify purple-tinted background loads.

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/css/admin-dashboard.css
git commit -m "feat(dashboard): rewrite CSS with Soft Violet design system"
```

---

### Task 2: Template Shell — 5-Tab Layout

**Files:**
- Rewrite: `stride-core/templates/admin/dashboard.php`

- [ ] **Step 1: Write the template with header + routing**

Replace the entire `dashboard.php` with the new Alpine.js app structure:
- Header bar with "Stride" text, 5 tab buttons, notification bell, user avatar
- Content area with `x-show` for each view
- Slide-over container (shared, right-aligned)
- Toast container (bottom-right)
- Hash-based routing: `#/`, `#/edities`, `#/offertes`, `#/trajecten`, `#/gebruikers`

Template variables available from `AdminDashboardService::renderDashboard()`:
- `$admin_url` — WP admin base URL
- `$user_name` — current user display name

Alpine.js `x-data` binds to `strideApp()` (defined in JS file).

Key template sections:
1. `sd-header` with tabs and user controls
2. `x-show="view === 'dashboard'"` — Dashboard home (KPIs + two-column)
3. `x-show="view === 'edities'"` — Editions flat table + filters
4. `x-show="view === 'offertes'"` — Quotes table + filters
5. `x-show="view === 'trajecten'"` — Trajectories table + filters
6. `x-show="view === 'gebruikers'"` — User search + detail
7. Slide-over overlay container
8. Toast notification area

Each view section follows the spec's column layout and widget structure.

- [ ] **Step 2: Add Dashboard home view markup**

Inside `view === 'dashboard'`:
- Greeting row: "Hi, {firstName}" + date
- KPI row (5 cards)
- Two-column layout:
  - Left: Action queue card + Komende sessies table
  - Right: Quick actions card + Activity feed card + User search widget

- [ ] **Step 3: Add Edities view markup**

- Filter bar: search, status dropdown, date range (Flatpickr), course tag dropdown, reset
- Flat session table with columns from spec
- Pagination controls
- Edition slide-over template (3 tabs: Studenten, Aanwezigheid, Info)

- [ ] **Step 4: Add Offertes view markup**

- Filter bar: search, status dropdown, edition dropdown, reset
- Quotes table with columns from spec
- Quick-send popover template
- Pagination
- Quote slide-over template (2 tabs: Details, Items)

- [ ] **Step 5: Add Trajecten view markup**

- Filter bar: search, status dropdown, reset
- Trajectories table with columns from spec
- Pagination
- Trajectory slide-over template (3 tabs: Details, Cursussen, Studenten)

- [ ] **Step 6: Add Gebruikers view markup**

- Large search input with results dropdown
- User detail two-column layout:
  - Left: user header card, registrations table, quotes table
  - Right: audit timeline, attendance summary
- "Bekijk als gebruiker" button with `manage_options` gate

- [ ] **Step 7: Add shared components**

- Notification bell dropdown template
- Toast container
- Confirmation popover (for quote quick-send)

- [ ] **Step 8: Verify template renders**

Visit `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-dashboard` — should see the header, tabs, and empty dashboard layout with correct styling.

- [ ] **Step 9: Commit**

```bash
git add web/app/mu-plugins/stride-core/templates/admin/dashboard.php
git commit -m "feat(dashboard): rewrite template with 5-tab layout and Soft Violet design"
```

---

### Task 3: Alpine.js App — Core Routing + Dashboard Home

**Files:**
- Rewrite: `stride-core/assets/js/admin-dashboard.js`
- Modify: `stride-core/Admin/AdminDashboardService.php` (add firstName to localized data)

- [ ] **Step 1: Update AdminDashboardService to pass firstName**

In `AdminDashboardService::enqueueAssets()`, update the `StrideConfig` localization to include:

```php
'user' => [
    'id' => $user->ID,
    'name' => $user->display_name,
    'email' => $user->user_email,
    'firstName' => $user->first_name ?: $user->display_name,
],
```

- [ ] **Step 2: Write the Alpine.js app core**

Write `admin-dashboard.js` with the `strideApp()` function:

**State:**
```javascript
{
    // Routing
    view: 'dashboard',

    // Dashboard home
    stats: { upcomingEditions: 0, totalRegistrations: 0, pendingQuotes: 0, todaySessions: 0, actionCount: 0 },
    actionQueue: [],
    upcomingSessions: [],
    activityFeed: [],
    healthChecks: { registration: 'green', mail: 'green' },

    // Editions
    editions: [],
    editionFilters: { search: '', status: '', date_from: '', date_to: '', course_tag: 0 },
    editionPage: 1,
    editionTotalPages: 1,
    courseTags: [],
    selectedEdition: null,
    editionTab: 'students',
    editionRegistrations: [],
    editionSessions: [],

    // Quotes
    quotes: [],
    quoteFilters: { search: '', status: '', edition_id: 0 },
    quotePage: 1,
    quoteTotalPages: 1,
    quoteEditions: [],
    selectedQuote: null,
    quoteTab: 'details',
    quickSendTarget: null,

    // Trajectories
    trajectories: [],
    trajectoryFilters: { search: '', status: '' },
    trajectoryPage: 1,
    trajectoryTotalPages: 1,
    selectedTrajectory: null,
    trajectoryTab: 'details',

    // Users
    userSearchQuery: '',
    userSearchResults: [],
    selectedUser: null,

    // Notifications
    notifications: [],
    unreadCount: 0,
    showNotifications: false,

    // UI state
    loading: false,
    slideoverOpen: false,
    toast: null,

    // Config
    config: window.StrideConfig || {},
}
```

**Core methods:**
- `init()` — parse hash, load data for current view, set up hash change listener
- `parseHash()` — extract view from `window.location.hash`
- `switchView(view)` — update hash, load data for new view
- `api(endpoint, options)` — fetch wrapper with nonce, error handling, returns JSON
- `showToast(message, type)` — show toast notification
- `openSlideOver()` / `closeSlideOver()` — toggle slide-over
- `formatDate(dateStr)` — Dutch date formatting
- `formatCurrency(cents)` — "€ 45,00" formatting
- `formatRelativeTime(timestamp)` — "2 uur geleden"

**Dashboard home methods:**
- `loadDashboard()` — parallel fetch: stats, action queue, upcoming sessions, activity feed
- `dismissAction(ruleType, subjectId)` — POST to dismiss endpoint
- `dashboardUserSearch(query)` — debounced user search from dashboard widget
- `navigateToUser(userId)` — switch to gebruikers tab with user pre-selected

- [ ] **Step 3: Add editions view methods**

- `loadEditions()` — GET `/admin/editions` with filters and pagination
- `loadCourseTags()` — GET `/admin/course-tags`
- `openEdition(id)` — GET edition detail + registrations, open slide-over
- `loadEditionRegistrations(editionId)` — GET `/admin/editions/{id}/registrations`
- `toggleAttendance(sessionId, userId, currentStatus)` — POST `/admin/attendance`, cycle status
- `initDateRangePicker()` — Flatpickr initialization with Dutch locale
- `resetEditionFilters()` — clear all filters, reload

- [ ] **Step 4: Add quotes view methods**

- `loadQuotes()` — GET `/admin/quotes` with filters
- `loadQuoteEditions()` — GET `/admin/editions?per_page=100&view=list` for edition dropdown
- `openQuote(id)` — open quote slide-over
- `showQuickSend(quote)` — show confirmation popover
- `confirmQuickSend()` — POST to send quote email, update table row
- `cancelQuickSend()` — close popover

- [ ] **Step 5: Add trajectories view methods**

- `loadTrajectories()` — GET `/admin/trajectories` with filters
- `openTrajectory(id)` — open trajectory slide-over

- [ ] **Step 6: Add users view methods**

- `searchUsers(query)` — GET `/admin/users/search?q=...` (min 2 chars, debounced 300ms)
- `selectUser(userId)` — GET `/admin/users/{id}/detail`, display user detail
- `impersonateUser(userId)` — POST `/admin/users/{id}/impersonate`, redirect on success
- `clearUserSearch()` — reset to empty search state

- [ ] **Step 7: Add notification bell methods**

- `loadNotifications()` — GET `/admin/notifications`
- `toggleNotifications()` — show/hide dropdown
- `markAllRead()` — POST `/admin/notifications/read`

- [ ] **Step 8: Verify the full SPA works**

Visit `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-dashboard`
- Tab switching should work (hash routing)
- Dashboard home should load stats, sessions, activity (from existing endpoints)
- Editions/Quotes/Trajectories tabs should load data (existing endpoints)
- Users tab shows search bar (new endpoint not yet built — verify graceful error handling)

- [ ] **Step 9: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/js/admin-dashboard.js
git add web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "feat(dashboard): rewrite Alpine.js app with 5-view routing and Soft Violet UI"
```

---

## Phase 2: Backend — New Services + Endpoints

### Task 4: ActionQueueService

**Files:**
- Create: `stride-core/Admin/ActionQueueService.php`
- Test: `tests/Unit/ActionQueueServiceTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Admin\ActionQueueService;

class ActionQueueServiceTest extends TestCase
{
    public function test_returns_empty_array_when_no_rules_active(): void
    {
        $service = new ActionQueueService();
        $result = $service->evaluate([
            'capacity_threshold' => ['enabled' => false, 'value' => 80],
            'session_approaching' => ['enabled' => false, 'value' => 1],
            'stale_quote' => ['enabled' => false, 'value' => 7],
            'pending_approval' => ['enabled' => false],
            'edition_starting' => ['enabled' => false, 'value' => 3],
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ], []);
        $this->assertSame([], $result);
    }

    public function test_capacity_rule_fires_when_above_threshold(): void
    {
        $service = new ActionQueueService();
        $editions = [
            ['id' => 1, 'title' => 'Excel Basis', 'registered' => 16, 'capacity' => 20],
        ];
        $rules = [
            'capacity_threshold' => ['enabled' => true, 'value' => 80],
            'session_approaching' => ['enabled' => false, 'value' => 1],
            'stale_quote' => ['enabled' => false, 'value' => 7],
            'pending_approval' => ['enabled' => false],
            'edition_starting' => ['enabled' => false, 'value' => 3],
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ];
        $result = $service->evaluate($rules, ['editions' => $editions]);
        $this->assertCount(1, $result);
        $this->assertSame('capacity_threshold', $result[0]['rule']);
        $this->assertSame('amber', $result[0]['priority']);
    }

    public function test_capacity_rule_does_not_fire_below_threshold(): void
    {
        $service = new ActionQueueService();
        $editions = [
            ['id' => 1, 'title' => 'Excel Basis', 'registered' => 10, 'capacity' => 20],
        ];
        $rules = [
            'capacity_threshold' => ['enabled' => true, 'value' => 80],
            'session_approaching' => ['enabled' => false, 'value' => 1],
            'stale_quote' => ['enabled' => false, 'value' => 7],
            'pending_approval' => ['enabled' => false],
            'edition_starting' => ['enabled' => false, 'value' => 3],
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ];
        $result = $service->evaluate($rules, ['editions' => $editions]);
        $this->assertCount(0, $result);
    }

    public function test_pending_approval_rule_always_red_priority(): void
    {
        $service = new ActionQueueService();
        $rules = [
            'capacity_threshold' => ['enabled' => false, 'value' => 80],
            'session_approaching' => ['enabled' => false, 'value' => 1],
            'stale_quote' => ['enabled' => false, 'value' => 7],
            'pending_approval' => ['enabled' => true],
            'edition_starting' => ['enabled' => false, 'value' => 3],
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ];
        $data = ['pending_approvals' => [
            ['id' => 101, 'user_name' => 'Jan', 'edition_title' => 'Excel'],
            ['id' => 102, 'user_name' => 'Marie', 'edition_title' => 'EHBO'],
        ]];
        $result = $service->evaluate($rules, $data);
        $this->assertCount(1, $result);
        $this->assertSame('red', $result[0]['priority']);
        $this->assertStringContainsString('2', $result[0]['text']);
    }

    public function test_results_sorted_by_priority(): void
    {
        $service = new ActionQueueService();
        $rules = [
            'capacity_threshold' => ['enabled' => true, 'value' => 80],
            'session_approaching' => ['enabled' => false, 'value' => 1],
            'stale_quote' => ['enabled' => false, 'value' => 7],
            'pending_approval' => ['enabled' => true],
            'edition_starting' => ['enabled' => true, 'value' => 3],
            'incomplete_tasks' => ['enabled' => false, 'value' => 7],
        ];
        $data = [
            'editions' => [['id' => 1, 'title' => 'Excel', 'registered' => 18, 'capacity' => 20]],
            'pending_approvals' => [['id' => 101, 'user_name' => 'Jan', 'edition_title' => 'Excel']],
            'starting_soon' => [['id' => 2, 'title' => 'EHBO', 'start_date' => date('Y-m-d', strtotime('+2 days'))]],
        ];
        $result = $service->evaluate($rules, $data);
        // Red items first, then amber, then blue
        $priorities = array_column($result, 'priority');
        $this->assertSame('red', $priorities[0]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit tests/Unit/ActionQueueServiceTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Write ActionQueueService**

Create `stride-core/Admin/ActionQueueService.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Admin;

/**
 * Evaluates configured alert rules against current data.
 *
 * Pure logic class — no database queries. Data is passed in by the caller
 * (AdminAPIController fetches data, passes it here for evaluation).
 *
 * Each rule returns zero or one action item.
 */
final class ActionQueueService
{
    /** Default rule configuration */
    public const DEFAULTS = [
        'capacity_threshold' => ['enabled' => true, 'value' => 80],
        'session_approaching' => ['enabled' => true, 'value' => 1],
        'stale_quote' => ['enabled' => true, 'value' => 7],
        'pending_approval' => ['enabled' => true],
        'edition_starting' => ['enabled' => true, 'value' => 3],
        'incomplete_tasks' => ['enabled' => true, 'value' => 7],
    ];

    private const PRIORITY_ORDER = ['red' => 0, 'amber' => 1, 'blue' => 2];

    /**
     * Evaluate all enabled rules and return prioritized action items.
     *
     * @param array $rules Rule configuration (from settings)
     * @param array $data Pre-fetched data keyed by data type
     * @return array<int, array{rule: string, priority: string, text: string, subject_id: int|null, url: string}>
     */
    public function evaluate(array $rules, array $data): array
    {
        $items = [];

        if ($rules['pending_approval']['enabled'] ?? false) {
            $items = array_merge($items, $this->evaluatePendingApprovals($data));
        }
        if ($rules['capacity_threshold']['enabled'] ?? false) {
            $items = array_merge($items, $this->evaluateCapacity($rules['capacity_threshold']['value'] ?? 80, $data));
        }
        if ($rules['session_approaching']['enabled'] ?? false) {
            $items = array_merge($items, $this->evaluateSessionApproaching($rules['session_approaching']['value'] ?? 1, $data));
        }
        if ($rules['stale_quote']['enabled'] ?? false) {
            $items = array_merge($items, $this->evaluateStaleQuotes($rules['stale_quote']['value'] ?? 7, $data));
        }
        if ($rules['edition_starting']['enabled'] ?? false) {
            $items = array_merge($items, $this->evaluateEditionStarting($rules['edition_starting']['value'] ?? 3, $data));
        }
        if ($rules['incomplete_tasks']['enabled'] ?? false) {
            $items = array_merge($items, $this->evaluateIncompleteTasks($rules['incomplete_tasks']['value'] ?? 7, $data));
        }

        // Sort by priority: red first, then amber, then blue
        usort($items, fn(array $a, array $b) =>
            (self::PRIORITY_ORDER[$a['priority']] ?? 9) <=> (self::PRIORITY_ORDER[$b['priority']] ?? 9)
        );

        return $items;
    }

    private function evaluatePendingApprovals(array $data): array
    {
        $pending = $data['pending_approvals'] ?? [];
        if (empty($pending)) {
            return [];
        }
        $count = count($pending);
        return [[
            'rule' => 'pending_approval',
            'priority' => 'red',
            'text' => sprintf('%d inschrijving%s wacht%s op goedkeuring', $count, $count > 1 ? 'en' : '', $count > 1 ? 'en' : ''),
            'subject_id' => null,
            'url' => '',
        ]];
    }

    private function evaluateCapacity(int $threshold, array $data): array
    {
        $items = [];
        foreach ($data['editions'] ?? [] as $edition) {
            $capacity = (int) ($edition['capacity'] ?? 0);
            $registered = (int) ($edition['registered'] ?? 0);
            if ($capacity > 0 && $registered > 0) {
                $pct = ($registered / $capacity) * 100;
                if ($pct >= $threshold) {
                    $items[] = [
                        'rule' => 'capacity_threshold',
                        'priority' => 'amber',
                        'text' => sprintf('%s bijna vol (%d/%d)', $edition['title'] ?? '', $registered, $capacity),
                        'subject_id' => (int) $edition['id'],
                        'url' => '',
                    ];
                }
            }
        }
        return $items;
    }

    private function evaluateSessionApproaching(int $daysBefore, array $data): array
    {
        // Sessions approaching without attendance marked
        $items = [];
        foreach ($data['sessions_approaching'] ?? [] as $session) {
            $items[] = [
                'rule' => 'session_approaching',
                'priority' => 'amber',
                'text' => sprintf('Sessie %s nadert — aanwezigheid nog niet ingevuld', $session['edition_title'] ?? ''),
                'subject_id' => (int) ($session['edition_id'] ?? 0),
                'url' => '',
            ];
        }
        return $items;
    }

    private function evaluateStaleQuotes(int $daysAsOld, array $data): array
    {
        $stale = $data['stale_quotes'] ?? [];
        if (empty($stale)) {
            return [];
        }
        $count = count($stale);
        return [[
            'rule' => 'stale_quote',
            'priority' => 'amber',
            'text' => sprintf('%d offerte%s staat%s al meer dan %d dagen op concept',
                $count, $count > 1 ? 's' : '', $count > 1 ? 'n' : '', $daysAsOld),
            'subject_id' => null,
            'url' => '',
        ]];
    }

    private function evaluateEditionStarting(int $daysBefore, array $data): array
    {
        $items = [];
        foreach ($data['starting_soon'] ?? [] as $edition) {
            $items[] = [
                'rule' => 'edition_starting',
                'priority' => 'blue',
                'text' => sprintf('%s start binnenkort', $edition['title'] ?? ''),
                'subject_id' => (int) ($edition['id'] ?? 0),
                'url' => '',
            ];
        }
        return $items;
    }

    private function evaluateIncompleteTasks(int $daysAfter, array $data): array
    {
        $items = [];
        foreach ($data['incomplete_tasks'] ?? [] as $task) {
            $items[] = [
                'rule' => 'incomplete_tasks',
                'priority' => 'amber',
                'text' => sprintf('%s: %d deelnemer%s heeft taken niet afgerond',
                    $task['edition_title'] ?? '', $task['count'] ?? 0, ($task['count'] ?? 0) > 1 ? 's' : ''),
                'subject_id' => (int) ($task['edition_id'] ?? 0),
                'url' => '',
            ];
        }
        return $items;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
ddev exec vendor/bin/phpunit tests/Unit/ActionQueueServiceTest.php
```

Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/ActionQueueService.php tests/Unit/ActionQueueServiceTest.php
git commit -m "feat(dashboard): add ActionQueueService with configurable rule evaluation"
```

---

### Task 5: AdminActivityMapper

**Files:**
- Create: `stride-core/Admin/AdminActivityMapper.php`
- Test: `tests/Unit/AdminActivityMapperTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Admin\AdminActivityMapper;

class AdminActivityMapperTest extends TestCase
{
    public function test_maps_registration_created_to_admin_perspective(): void
    {
        $entry = (object) [
            'id' => 1,
            'action' => 'registration.created',
            'actor_id' => 42,
            'context' => json_encode(['edition_id' => 55, 'edition_title' => 'Excel Basis']),
            'created_at' => '2026-03-25 10:30:00',
        ];

        $result = AdminActivityMapper::fromAuditEntry($entry, 'Jan Peeters');

        $this->assertSame('enrollment', $result['type']);
        $this->assertStringContainsString('Jan Peeters', $result['text']);
        $this->assertStringContainsString('Excel Basis', $result['text']);
    }

    public function test_maps_attendance_marked_present(): void
    {
        $entry = (object) [
            'id' => 2,
            'action' => 'attendance.marked_present',
            'actor_id' => 1,
            'context' => json_encode(['edition_title' => 'EHBO', 'user_name' => 'Marie Claes']),
            'created_at' => '2026-03-25 11:00:00',
        ];

        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin');
        $this->assertSame('attendance', $result['type']);
    }

    public function test_returns_fallback_for_unknown_action(): void
    {
        $entry = (object) [
            'id' => 3,
            'action' => 'unknown.action',
            'actor_id' => 1,
            'context' => '{}',
            'created_at' => '2026-03-25 12:00:00',
        ];

        $result = AdminActivityMapper::fromAuditEntry($entry, 'Admin');
        $this->assertSame('action', $result['type']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit tests/Unit/AdminActivityMapperTest.php
```

- [ ] **Step 3: Write AdminActivityMapper**

Create `stride-core/Admin/AdminActivityMapper.php`:

```php
<?php
declare(strict_types=1);

namespace Stride\Admin;

/**
 * Maps audit log entries to admin-perspective activity feed strings.
 *
 * Unlike NotificationMapper (student-facing: "Je inschrijving..."),
 * this produces admin-facing strings: "Jan Peeters schreef zich in voor..."
 */
final class AdminActivityMapper
{
    /**
     * @return array{id: int, type: string, text: string, actor_name: string, timestamp: int}
     */
    public static function fromAuditEntry(object $entry, string $actorName): array
    {
        $context = json_decode($entry->context ?? '{}', true) ?: [];
        $action = $entry->action ?? '';

        [$type, $text] = match ($action) {
            'registration.created' => [
                'enrollment',
                sprintf('%s heeft zich ingeschreven voor %s', $actorName, $context['edition_title'] ?? 'onbekend'),
            ],
            'registration.cancelled' => [
                'enrollment',
                sprintf('Inschrijving van %s voor %s is geannuleerd', $actorName, $context['edition_title'] ?? 'onbekend'),
            ],
            'attendance.marked_present' => [
                'attendance',
                sprintf('%s aanwezig gemarkeerd bij %s', $context['user_name'] ?? $actorName, $context['edition_title'] ?? 'onbekend'),
            ],
            'attendance.marked_absent' => [
                'attendance',
                sprintf('%s afwezig gemarkeerd bij %s', $context['user_name'] ?? $actorName, $context['edition_title'] ?? 'onbekend'),
            ],
            'attendance.marked_excused' => [
                'attendance',
                sprintf('%s verontschuldigd bij %s', $context['user_name'] ?? $actorName, $context['edition_title'] ?? 'onbekend'),
            ],
            'completion.course_completed' => [
                'completion',
                sprintf('%s heeft %s afgerond', $actorName, $context['edition_title'] ?? $context['course_title'] ?? 'onbekend'),
            ],
            'completion.certificate_issued' => [
                'completion',
                sprintf('Certificaat uitgereikt aan %s voor %s', $actorName, $context['edition_title'] ?? 'onbekend'),
            ],
            'quote.created' => [
                'quote',
                sprintf('Offerte aangemaakt voor %s — %s', $actorName, $context['edition_title'] ?? 'onbekend'),
            ],
            'quote.sent' => [
                'quote',
                sprintf('Offerte verzonden naar %s', $actorName),
            ],
            default => [
                'action',
                sprintf('%s: %s', $actorName, str_replace('.', ' ', $action)),
            ],
        };

        return [
            'id' => (int) ($entry->id ?? 0),
            'type' => $type,
            'text' => $text,
            'actor_name' => $actorName,
            'timestamp' => strtotime($entry->created_at ?? 'now') ?: time(),
        ];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/AdminActivityMapperTest.php
```

Expected: ALL PASS

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminActivityMapper.php tests/Unit/AdminActivityMapperTest.php
git commit -m "feat(dashboard): add AdminActivityMapper for admin-perspective audit feed"
```

---

### Task 6: HealthCheckService

**Files:**
- Create: `stride-core/Admin/HealthCheckService.php`
- Test: `tests/Unit/HealthCheckServiceTest.php`

- [ ] **Step 1: Write failing test**

Test that health checks return correct status based on input data (timestamps of last registration and last mail send).

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Admin\HealthCheckService;

class HealthCheckServiceTest extends TestCase
{
    public function test_registration_green_when_recent(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time() - 3600, // 1 hour ago
            lastMailSend: time() - 7200,
            hasOpenEditions: true
        );
        $this->assertSame('green', $result['registration']);
    }

    public function test_registration_green_when_no_open_editions(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: 0, // never
            lastMailSend: time(),
            hasOpenEditions: false
        );
        $this->assertSame('green', $result['registration']);
    }

    public function test_registration_amber_when_stale_with_open_editions(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time() - 90000, // 25 hours ago
            lastMailSend: time(),
            hasOpenEditions: true
        );
        $this->assertSame('amber', $result['registration']);
    }

    public function test_mail_green_when_recent(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time(),
            lastMailSend: time() - 3600,
            hasOpenEditions: true
        );
        $this->assertSame('green', $result['mail']);
    }

    public function test_mail_amber_when_stale(): void
    {
        $service = new HealthCheckService();
        $result = $service->evaluate(
            lastRegistration: time(),
            lastMailSend: time() - 90000,
            hasOpenEditions: true
        );
        $this->assertSame('amber', $result['mail']);
    }
}
```

- [ ] **Step 2: Run test to verify fails**

```bash
ddev exec vendor/bin/phpunit tests/Unit/HealthCheckServiceTest.php
```

- [ ] **Step 3: Write HealthCheckService**

```php
<?php
declare(strict_types=1);

namespace Stride\Admin;

/**
 * Simple health checks for registration flow and mail delivery.
 *
 * Pure logic — timestamps passed in by caller.
 */
final class HealthCheckService
{
    private const STALE_THRESHOLD = 86400; // 24 hours

    /**
     * @return array{registration: string, mail: string}
     */
    public function evaluate(int $lastRegistration, int $lastMailSend, bool $hasOpenEditions): array
    {
        $now = time();

        // Registration: green if recent OR no open editions
        $registrationOk = !$hasOpenEditions || ($lastRegistration > 0 && ($now - $lastRegistration) < self::STALE_THRESHOLD);

        // Mail: green if recent send
        $mailOk = $lastMailSend > 0 && ($now - $lastMailSend) < self::STALE_THRESHOLD;

        return [
            'registration' => $registrationOk ? 'green' : 'amber',
            'mail' => $mailOk ? 'green' : 'amber',
        ];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/HealthCheckServiceTest.php
```

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/HealthCheckService.php tests/Unit/HealthCheckServiceTest.php
git commit -m "feat(dashboard): add HealthCheckService for registration and mail health"
```

---

### Task 7: ImpersonationHandler

**Files:**
- Create: `stride-core/Admin/ImpersonationHandler.php`
- Test: `tests/Unit/ImpersonationHandlerTest.php`

- [ ] **Step 1: Write failing test**

Test the validation logic (not the WordPress session functions — those require integration tests).

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Stride\Admin\ImpersonationHandler;

class ImpersonationHandlerTest extends TestCase
{
    public function test_validates_target_is_not_admin(): void
    {
        $handler = new ImpersonationHandler();
        // validateTarget returns WP_Error or true
        $result = $handler->validateTarget(
            targetUserId: 42,
            targetIsAdmin: true,
            callerHasManageOptions: true
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('cannot_impersonate_admin', $result->get_error_code());
    }

    public function test_validates_caller_has_manage_options(): void
    {
        $handler = new ImpersonationHandler();
        $result = $handler->validateTarget(
            targetUserId: 42,
            targetIsAdmin: false,
            callerHasManageOptions: false
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('forbidden', $result->get_error_code());
    }

    public function test_validates_target_exists(): void
    {
        $handler = new ImpersonationHandler();
        $result = $handler->validateTarget(
            targetUserId: 0,
            targetIsAdmin: false,
            callerHasManageOptions: true
        );
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_passes_validation_for_valid_target(): void
    {
        $handler = new ImpersonationHandler();
        $result = $handler->validateTarget(
            targetUserId: 42,
            targetIsAdmin: false,
            callerHasManageOptions: true
        );
        $this->assertTrue($result);
    }

    public function test_generates_token_of_sufficient_length(): void
    {
        $handler = new ImpersonationHandler();
        $token = $handler->generateToken();
        $this->assertGreaterThanOrEqual(32, strlen($token));
    }
}
```

- [ ] **Step 2: Run test to verify fails**

```bash
ddev exec vendor/bin/phpunit tests/Unit/ImpersonationHandlerTest.php
```

- [ ] **Step 3: Write ImpersonationHandler**

```php
<?php
declare(strict_types=1);

namespace Stride\Admin;

use WP_Error;

/**
 * Handles user impersonation ("Bekijk als gebruiker").
 *
 * Session switching via transient + cookie pattern.
 * WordPress session functions (wp_set_auth_cookie, etc.) are called
 * by AdminAPIController — this class handles validation and token management.
 */
final class ImpersonationHandler
{
    public const COOKIE_NAME = 'stride_impersonate_token';
    public const TRANSIENT_PREFIX = 'stride_impersonate_';
    public const TTL = 3600; // 1 hour

    /**
     * Validate that impersonation is allowed.
     *
     * @return true|WP_Error
     */
    public function validateTarget(int $targetUserId, bool $targetIsAdmin, bool $callerHasManageOptions): true|WP_Error
    {
        if (!$callerHasManageOptions) {
            return new WP_Error('forbidden', 'Onvoldoende rechten voor impersonatie.');
        }

        if ($targetUserId <= 0) {
            return new WP_Error('invalid_user', 'Gebruiker niet gevonden.');
        }

        if ($targetIsAdmin) {
            return new WP_Error('cannot_impersonate_admin', 'Kan geen andere beheerder impersoneren.');
        }

        return true;
    }

    /**
     * Generate a cryptographically secure random token.
     */
    public function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Store impersonation transient (server-side).
     */
    public function storeSession(string $token, int $originalAdminId): void
    {
        set_transient(self::TRANSIENT_PREFIX . $token, $originalAdminId, self::TTL);
    }

    /**
     * Retrieve the original admin ID from a token.
     */
    public function getOriginalAdmin(string $token): int
    {
        $adminId = get_transient(self::TRANSIENT_PREFIX . $token);
        return $adminId ? (int) $adminId : 0;
    }

    /**
     * Clean up impersonation session.
     */
    public function endSession(string $token): void
    {
        delete_transient(self::TRANSIENT_PREFIX . $token);
    }

    /**
     * Check if an impersonation session is active (from cookie).
     */
    public function isActive(): bool
    {
        return !empty($_COOKIE[self::COOKIE_NAME] ?? '');
    }

    /**
     * Get the token from cookie.
     */
    public function getTokenFromCookie(): string
    {
        return sanitize_text_field($_COOKIE[self::COOKIE_NAME] ?? '');
    }
}
```

- [ ] **Step 4: Run tests**

```bash
ddev exec vendor/bin/phpunit tests/Unit/ImpersonationHandlerTest.php
```

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/ImpersonationHandler.php tests/Unit/ImpersonationHandlerTest.php
git commit -m "feat(dashboard): add ImpersonationHandler with validation and token management"
```

---

### Task 8: API Endpoints — Action Queue, Health Checks, Activity Feed

**Files:**
- Modify: `stride-core/Admin/AdminAPIController.php` (add constructor deps + routes + methods)
- Modify: `stride-core/Admin/AdminDashboardService.php` (wire new services, impersonation admin bar hook)

**Spec note:** The spec defines `GET/POST /admin/alert-rules` endpoints. This plan deliberately deviates — alert rules are managed through the existing settings AJAX mechanism (`ntdst/api_data/stride_save_settings`) in Task 9, which reuses proven infrastructure. The dashboard JS reads rules from the `getActionQueue()` response.

**Dependencies:** `AdminAPIController` constructor needs additional deps. Use `ntdst_get()` inside methods for new services (keeps constructor change minimal):
- `ActionQueueService` → lazy via `ntdst_get()` in `getActionQueue()`
- `HealthCheckService` → lazy via `ntdst_get()` in `getHealthChecks()`
- `AdminActivityMapper` → static methods, no DI needed

- [ ] **Step 1: Update AdminDashboardService to wire impersonation admin bar hook**

In `AdminDashboardService::init()`, after existing `AdminAPIController` and `AdminGuidePage` instantiation, add the impersonation admin bar hook:

```php
// Impersonation admin bar hook (runs on ALL pages, not just dashboard)
$impersonation = new ImpersonationHandler();
if ($impersonation->isActive()) {
    add_filter('show_admin_bar', '__return_true', 999);
    add_action('admin_bar_menu', function (\WP_Admin_Bar $bar) use ($impersonation) {
        $token = $impersonation->getTokenFromCookie();
        $adminId = $impersonation->getOriginalAdmin($token);
        if ($adminId > 0) {
            $adminUser = get_userdata($adminId);
            $bar->add_node([
                'id' => 'stride-end-impersonation',
                'title' => sprintf('⮐ Terug naar %s', $adminUser ? $adminUser->display_name : 'admin'),
                'href' => rest_url('stride/v1/admin/impersonate/end') . '?token=' . urlencode($token) . '&_wpnonce=' . wp_create_nonce('wp_rest'),
                'meta' => ['class' => 'stride-impersonation-notice'],
            ]);
        }
    }, 999);
}
```

Also update `getStats()` to include action queue count (cheap count from transient or fast query):

```php
// In StrideConfig localization, add:
'notificationRules' => StrideSettingsService::getNotificationRules(),
```

- [ ] **Step 2: Add action queue + health check + activity routes to registerRoutes()**

```php
// Action queue
register_rest_route(self::NAMESPACE, '/admin/action-queue', [
    'methods' => 'GET',
    'callback' => [$this, 'getActionQueue'],
    'permission_callback' => [$this, 'canViewAdmin'],
]);

// Dismiss action queue item
register_rest_route(self::NAMESPACE, '/admin/action-queue/dismiss', [
    'methods' => 'POST',
    'callback' => [$this, 'dismissActionItem'],
    'permission_callback' => [$this, 'canViewAdmin'],
    'args' => [
        'rule' => ['type' => 'string', 'required' => true],
        'subject_id' => ['type' => 'integer', 'default' => 0],
    ],
]);

// Health checks
register_rest_route(self::NAMESPACE, '/admin/health-checks', [
    'methods' => 'GET',
    'callback' => [$this, 'getHealthChecks'],
    'permission_callback' => [$this, 'canViewAdmin'],
]);

// Activity feed
register_rest_route(self::NAMESPACE, '/admin/activity', [
    'methods' => 'GET',
    'callback' => [$this, 'getActivityFeed'],
    'permission_callback' => [$this, 'canViewAdmin'],
    'args' => [
        'limit' => ['type' => 'integer', 'default' => 10, 'maximum' => 50],
    ],
]);
```

- [ ] **Step 3: Implement getActionQueue() with transient caching**

```php
public function getActionQueue(WP_REST_Request $request): WP_REST_Response
{
    $userId = get_current_user_id();
    $rules = StrideSettingsService::getNotificationRules();

    // Check transient cache (5 min TTL)
    $cacheKey = 'stride_action_queue';
    $cached = get_transient($cacheKey);

    if ($cached === false) {
        // Fetch data for each rule...
        $data = $this->fetchActionQueueData($rules);
        $service = new ActionQueueService();
        $items = $service->evaluate($rules, $data);
        set_transient($cacheKey, $items, 300);
    } else {
        $items = $cached;
    }

    // Filter out dismissed items for this admin
    $dismissed = get_user_meta($userId, 'stride_dismissed_actions', true) ?: [];

    // Prune old dismissals (>30 days)
    $dismissed = array_filter($dismissed, fn($d) =>
        isset($d['date']) && strtotime($d['date']) > strtotime('-30 days')
    );
    update_user_meta($userId, 'stride_dismissed_actions', $dismissed);

    $today = current_time('Y-m-d');
    $items = array_filter($items, function ($item) use ($dismissed, $today) {
        foreach ($dismissed as $d) {
            if ($d['rule'] === $item['rule']
                && ($d['subject_id'] ?? 0) === ($item['subject_id'] ?? 0)
                && $d['date'] === $today) {
                return false;
            }
        }
        return true;
    });

    return new WP_REST_Response(array_values($items));
}
```

Add private helper `fetchActionQueueData()` that queries editions/capacity, pending approvals, stale quotes, approaching sessions, starting editions, incomplete tasks.

Add cache invalidation hooks in `AdminDashboardService::init()`:
```php
// Invalidate action queue cache on data changes
$invalidate = fn() => delete_transient('stride_action_queue');
add_action('stride/registration/created', $invalidate);
add_action('stride/registration/confirmed', $invalidate);
add_action('stride/registration/cancelled', $invalidate);
add_action('stride/attendance/marked', $invalidate);
add_action('save_post_vad_quote', $invalidate);
```

- [ ] **Step 4: Implement dismissActionItem()**

Store `[rule, subject_id, date]` in user meta array `stride_dismissed_actions`.

- [ ] **Step 5: Implement getHealthChecks()**

Query last registration timestamp from `wp_vad_registrations`, last mail send from Fluent SMTP log or wp_mail hook, check open editions. Pass to `HealthCheckService::evaluate()`.

- [ ] **Step 6: Implement getActivityFeed()**

```php
public function getActivityFeed(WP_REST_Request $request): WP_REST_Response
{
    $limit = (int) $request->get_param('limit');
    $audit = ntdst_get(\NTDST\Audit\AuditService::class);
    $entries = $audit->getRecent($limit);

    $items = array_map(function ($entry) {
        $actorName = $this->resolveUserName((int) ($entry->actor_id ?? 0));
        return AdminActivityMapper::fromAuditEntry($entry, $actorName);
    }, $entries);

    return new WP_REST_Response($items);
}
```

- [ ] **Step 7: Update getStats() to include actionCount**

Add a cheap count query for pending actions (or read from cached transient) so the KPI card can show the count without waiting for the full action queue to load.

- [ ] **Step 8: Verify endpoints**

```bash
ddev exec wp eval "echo wp_remote_retrieve_body(wp_remote_get(rest_url('stride/v1/admin/action-queue'), ['headers' => ['X-WP-Nonce' => wp_create_nonce('wp_rest')]]));"
ddev exec wp eval "echo wp_remote_retrieve_body(wp_remote_get(rest_url('stride/v1/admin/health-checks'), ['headers' => ['X-WP-Nonce' => wp_create_nonce('wp_rest')]]));"
```

- [ ] **Step 9: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php
git commit -m "feat(dashboard): add action queue, health check, activity feed API endpoints with caching"
```

---

### Task 8b: API Endpoints — User Search + Detail

**Files:**
- Modify: `stride-core/Admin/AdminAPIController.php`

- [ ] **Step 1: Add user routes to registerRoutes()**

```php
// User search
register_rest_route(self::NAMESPACE, '/admin/users/search', [
    'methods' => 'GET',
    'callback' => [$this, 'searchUsers'],
    'permission_callback' => [$this, 'canViewAdmin'],
    'args' => [
        'q' => ['type' => 'string', 'required' => true, 'minLength' => 2],
    ],
]);

// User detail
register_rest_route(self::NAMESPACE, '/admin/users/(?P<id>\d+)/detail', [
    'methods' => 'GET',
    'callback' => [$this, 'getUserDetail'],
    'permission_callback' => [$this, 'canViewAdmin'],
    'args' => [
        'id' => ['type' => 'integer', 'required' => true],
        'reg_page' => ['type' => 'integer', 'default' => 1],
    ],
]);
```

- [ ] **Step 2: Implement searchUsers()**

```php
public function searchUsers(WP_REST_Request $request): WP_REST_Response
{
    $query = sanitize_text_field($request->get_param('q'));

    $userQuery = new \WP_User_Query([
        'search' => "*{$query}*",
        'search_columns' => ['user_login', 'user_email', 'display_name'],
        'number' => 10,
        'orderby' => 'display_name',
        'fields' => ['ID', 'display_name', 'user_email'],
    ]);

    $users = array_map(function ($user) {
        return [
            'id' => (int) $user->ID,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'organisation' => get_user_meta($user->ID, 'organisation', true) ?: '',
            'registration_count' => $this->countUserRegistrations((int) $user->ID),
        ];
    }, $userQuery->get_results());

    return new WP_REST_Response($users);
}
```

- [ ] **Step 3: Implement getUserDetail()**

Aggregate user data following the response schema from the spec:
- User profile: `get_userdata()` + user meta (organisation, department, phone, profile_type)
- Registrations: query `wp_vad_registrations` WHERE `user_id`, paginated (20 per page), join edition titles
- Quotes: query `vad_quote` posts where user meta matches, with edition titles and totals
- Attendance summary: per edition, count sessions attended vs total, hours completed
- Audit trail: last 50 entries from ntdst-audit WHERE subject_user_id = target user
- Return `registrations_total` and `audit_trail_total` for counts when lists are capped

- [ ] **Step 4: Verify endpoints**

```bash
ddev exec wp eval "echo wp_remote_retrieve_body(wp_remote_get(rest_url('stride/v1/admin/users/search?q=seed'), ['headers' => ['X-WP-Nonce' => wp_create_nonce('wp_rest')]]));"
```

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "feat(dashboard): add user search and user detail API endpoints"
```

---

### Task 8c: API Endpoints — Impersonation + Notifications

**Files:**
- Modify: `stride-core/Admin/AdminAPIController.php`

- [ ] **Step 1: Add impersonation + notification routes to registerRoutes()**

```php
// Impersonate user
register_rest_route(self::NAMESPACE, '/admin/users/(?P<id>\d+)/impersonate', [
    'methods' => 'POST',
    'callback' => [$this, 'impersonateUser'],
    'permission_callback' => [$this, 'canManageAdmin'],
    'args' => [
        'id' => ['type' => 'integer', 'required' => true],
    ],
]);

// End impersonation — validates cookie+transient internally, not just __return_true
register_rest_route(self::NAMESPACE, '/admin/impersonate/end', [
    'methods' => ['GET', 'POST'],
    'callback' => [$this, 'endImpersonation'],
    'permission_callback' => function () {
        // Must have a valid impersonation cookie — not open to anonymous
        $handler = new ImpersonationHandler();
        if (!$handler->isActive()) {
            return false;
        }
        $token = $handler->getTokenFromCookie();
        return $handler->getOriginalAdmin($token) > 0;
    },
]);

// Notifications
register_rest_route(self::NAMESPACE, '/admin/notifications', [
    'methods' => 'GET',
    'callback' => [$this, 'getNotifications'],
    'permission_callback' => [$this, 'canViewAdmin'],
]);

// Mark notifications read
register_rest_route(self::NAMESPACE, '/admin/notifications/read', [
    'methods' => 'POST',
    'callback' => [$this, 'markNotificationsRead'],
    'permission_callback' => [$this, 'canViewAdmin'],
]);
```

- [ ] **Step 2: Implement impersonateUser()**

```php
public function impersonateUser(WP_REST_Request $request): WP_REST_Response|WP_Error
{
    $targetId = (int) $request->get_param('id');
    $targetUser = get_userdata($targetId);

    if (!$targetUser) {
        return new WP_Error('not_found', 'Gebruiker niet gevonden.', ['status' => 404]);
    }

    $handler = new ImpersonationHandler();
    $validation = $handler->validateTarget(
        targetUserId: $targetId,
        targetIsAdmin: user_can($targetId, 'manage_options'),
        callerHasManageOptions: current_user_can('manage_options')
    );

    if (is_wp_error($validation)) {
        return $validation;
    }

    $adminId = get_current_user_id();
    $token = $handler->generateToken();
    $handler->storeSession($token, $adminId);

    // Log impersonation start in audit trail
    $audit = ntdst_get(\NTDST\Audit\AuditService::class);
    $audit->log('impersonation.started', $adminId, $targetId, [
        'admin_name' => wp_get_current_user()->display_name,
        'target_name' => $targetUser->display_name,
    ]);

    // Switch session
    wp_clear_auth_cookie();
    wp_set_auth_cookie($targetId, false);

    // Set impersonation cookie
    setcookie(ImpersonationHandler::COOKIE_NAME, $token, [
        'expires' => time() + ImpersonationHandler::TTL,
        'path' => COOKIEPATH,
        'domain' => COOKIE_DOMAIN,
        'secure' => is_ssl(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    return new WP_REST_Response([
        'success' => true,
        'redirect' => home_url('/'),
    ]);
}
```

- [ ] **Step 3: Implement endImpersonation()**

Validate token from cookie, retrieve original admin ID from transient, switch session back, clean up cookie + transient, log audit event, redirect to dashboard.

- [ ] **Step 4: Implement getNotifications()**

```php
public function getNotifications(WP_REST_Request $request): WP_REST_Response
{
    $userId = get_current_user_id();
    $lastReadId = (int) get_user_meta($userId, 'stride_last_read_notification_id', true);

    $audit = ntdst_get(\NTDST\Audit\AuditService::class);
    // Query notification-worthy events (last 10)
    $notificationActions = [
        'registration.created', 'registration.cancelled',
        'quote.created', 'completion.course_completed',
    ];
    $entries = $audit->getRecentByActions($notificationActions, 10);

    $notifications = array_map(function ($entry) use ($lastReadId) {
        $actorName = $this->resolveUserName((int) ($entry->actor_id ?? 0));
        $mapped = AdminActivityMapper::fromAuditEntry($entry, $actorName);
        $mapped['read'] = $mapped['id'] <= $lastReadId;
        return $mapped;
    }, $entries);

    $unread = count(array_filter($notifications, fn($n) => !$n['read']));

    return new WP_REST_Response([
        'notifications' => $notifications,
        'unread_count' => $unread,
    ]);
}
```

- [ ] **Step 5: Implement markNotificationsRead()**

```php
public function markNotificationsRead(WP_REST_Request $request): WP_REST_Response
{
    $userId = get_current_user_id();
    $audit = ntdst_get(\NTDST\Audit\AuditService::class);
    $latest = $audit->getLatestId();
    update_user_meta($userId, 'stride_last_read_notification_id', $latest);

    return new WP_REST_Response(['success' => true, 'unread_count' => 0]);
}
```

- [ ] **Step 6: Verify impersonation round-trip**

Log in as admin, navigate to Gebruikers tab, search for seed_student1, click impersonate. Verify:
- Session switches, frontend shows as student
- Admin bar shows "Terug naar" button (even for student users — `show_admin_bar` forced true)
- Click return → back to admin dashboard
- Audit log shows impersonation start/end events

- [ ] **Step 7: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "feat(dashboard): add impersonation and notification API endpoints"
```

---

### Task 8d: CSV Export Endpoint

**Files:**
- Modify: `stride-core/Admin/AdminAPIController.php`

**Spec requirement:** "Inschrijvingen exporteren" quick action triggers a CSV download of confirmed registrations for upcoming editions.

- [ ] **Step 1: Add export route**

```php
register_rest_route(self::NAMESPACE, '/admin/export/registrations', [
    'methods' => 'GET',
    'callback' => [$this, 'exportRegistrations'],
    'permission_callback' => [$this, 'canManageAdmin'],
]);
```

- [ ] **Step 2: Implement exportRegistrations()**

Query confirmed registrations for upcoming editions. Output CSV with headers: Naam, E-mail, Organisatie, Editie, Datum, Status, Offerte #.

```php
public function exportRegistrations(WP_REST_Request $request): void
{
    if (!current_user_can('stride_manage')) {
        wp_die('Forbidden');
    }

    $registrations = $this->getUpcomingRegistrations();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inschrijvingen-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Naam', 'E-mail', 'Organisatie', 'Editie', 'Datum', 'Status', 'Offerte #'], ';');

    foreach ($registrations as $reg) {
        fputcsv($output, [
            $reg['name'],
            $reg['email'],
            $reg['organisation'],
            $reg['edition_title'],
            $reg['date'],
            $reg['status'],
            $reg['quote_number'],
        ], ';');
    }

    fclose($output);
    exit;
}
```

- [ ] **Step 3: Wire JS quick action to export endpoint**

In `admin-dashboard.js`, the "Inschrijvingen exporteren" button triggers:
```javascript
exportRegistrations() {
    window.location.href = this.config.apiUrl + '/admin/export/registrations?_wpnonce=' + this.config.nonce;
}
```

- [ ] **Step 4: Verify CSV download**

Click "Inschrijvingen exporteren" on dashboard → browser downloads CSV file. Open in Excel → verify columns and data.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Admin/AdminAPIController.php
git commit -m "feat(dashboard): add CSV export endpoint for registrations"
```

---

## Phase 3: Settings + Integration

### Task 9: Meldingen Settings Tab

**Files:**
- Create: `stride-core/templates/admin/settings/tab-notifications.php`
- Modify: `stride-core/templates/admin/settings.php` (add tab entry)
- Modify: `stride-core/Admin/StrideSettingsService.php` (add save handler + option + localized data)

- [ ] **Step 1: Add notifications tab to settings.php**

In `settings.php`, add to the `$tabs` array:

```php
$tabs = [
    'general'       => ['label' => 'Algemeen', 'icon' => 'dashicons-admin-generic'],
    'company'       => ['label' => 'Bedrijf', 'icon' => 'dashicons-building'],
    'profile-types' => ['label' => 'Profieltypes', 'icon' => 'dashicons-groups'],
    'notifications' => ['label' => 'Meldingen', 'icon' => 'dashicons-bell'],
];
```

Add the tab content section:

```php
<!-- Tab: Meldingen -->
<div x-show="activeTab === 'notifications'" style="display: none;">
    <?php if (file_exists($templateDir . '/tab-notifications.php')): ?>
        <?php include $templateDir . '/tab-notifications.php'; ?>
    <?php endif; ?>
</div>
```

- [ ] **Step 2: Write tab-notifications.php template**

```php
<?php
/**
 * Settings tab: Meldingen (Action Queue Rules)
 */
defined('ABSPATH') || exit;
?>

<div class="stride-settings__section">
    <h2>Meldingen</h2>
    <p class="description">Configureer welke meldingen op het dashboard verschijnen en wanneer ze worden geactiveerd.</p>

    <table class="form-table" role="presentation">
        <template x-for="(rule, key) in notifications" :key="key">
            <tr>
                <th scope="row">
                    <label>
                        <input type="checkbox" x-model="rule.enabled">
                        <span x-text="ruleLabels[key]"></span>
                    </label>
                </th>
                <td>
                    <template x-if="key !== 'pending_approval'">
                        <span>
                            <input type="number" x-model.number="rule.value"
                                   class="small-text" min="1" max="365"
                                   :disabled="!rule.enabled">
                            <span x-text="ruleUnits[key]"></span>
                        </span>
                    </template>
                    <template x-if="key === 'pending_approval'">
                        <span class="description">Altijd actief wanneer ingeschakeld</span>
                    </template>
                </td>
            </tr>
        </template>
    </table>

    <p class="submit">
        <button type="button" class="button button-primary"
                @click="saveTab('notifications')"
                :disabled="saving">
            <span x-show="!saving">Opslaan</span>
            <span x-show="saving">Opslaan...</span>
        </button>
    </p>
</div>
```

- [ ] **Step 3: Add save handler + option to StrideSettingsService**

Add constant:
```php
private const OPTION_NOTIFICATIONS = 'stride_notification_rules';
```

Add to `handleSaveSettings()` match:
```php
'notifications' => $this->saveNotificationSettings($params),
```

Add method:
```php
private function saveNotificationSettings(array $params): array
{
    $rules = [];
    $ruleKeys = ['capacity_threshold', 'session_approaching', 'stale_quote', 'pending_approval', 'edition_starting', 'incomplete_tasks'];

    foreach ($ruleKeys as $key) {
        $rules[$key] = [
            'enabled' => filter_var($params[$key . '_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ];
        if ($key !== 'pending_approval') {
            $value = absint($params[$key . '_value'] ?? 0);
            $rules[$key]['value'] = max(1, min(365, $value ?: ActionQueueService::DEFAULTS[$key]['value']));
        }
    }

    update_option(self::OPTION_NOTIFICATIONS, $rules);
    return ['message' => 'Meldingsinstellingen opgeslagen.'];
}

public static function getNotificationRules(): array
{
    $saved = get_option(self::OPTION_NOTIFICATIONS, []);
    return array_merge(ActionQueueService::DEFAULTS, is_array($saved) ? $saved : []);
}
```

Add to `getLocalizedData()`:
```php
'notifications' => self::getNotificationRules(),
```

Also add Alpine.js labels/units data:
```php
'ruleLabels' => [
    'capacity_threshold' => 'Editie bijna vol',
    'session_approaching' => 'Sessie nadert',
    'stale_quote' => 'Offerte niet verzonden',
    'pending_approval' => 'Goedkeuring nodig',
    'edition_starting' => 'Editie start binnenkort',
    'incomplete_tasks' => 'Taken niet afgerond',
],
'ruleUnits' => [
    'capacity_threshold' => '%',
    'session_approaching' => 'dag(en) voor aanvang',
    'stale_quote' => 'dag(en) als concept',
    'edition_starting' => 'dag(en) voor start',
    'incomplete_tasks' => 'dag(en) na laatste sessie',
],
```

- [ ] **Step 4: Update settings.js to handle notifications tab save**

In the existing `settings.js` file, add the `notifications` data binding and save logic. The existing `saveTab()` method already sends tab data via `ntdstAPI.call()` — just ensure the notifications data is included in the params.

- [ ] **Step 5: Verify settings tab works**

Visit `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-settings`, switch to "Meldingen" tab. Toggle rules, change thresholds, save. Verify option saved:

```bash
ddev exec wp option get stride_notification_rules --format=json
```

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/templates/admin/settings/tab-notifications.php
git add web/app/mu-plugins/stride-core/templates/admin/settings.php
git add web/app/mu-plugins/stride-core/Admin/StrideSettingsService.php
git add web/app/mu-plugins/stride-core/assets/js/admin/settings.js
git commit -m "feat(dashboard): add Meldingen settings tab for action queue rules"
```

---

## Phase 4: Integration Testing + Polish

### Task 10: Integration Smoke Tests

**Files:**
- No new test files — manual verification with seed data

- [ ] **Step 1: Seed development data**

```bash
ddev exec wp eval-file scripts/seed.php
```

- [ ] **Step 2: Verify Dashboard Home**

Visit `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-dashboard`

Check:
- [ ] "Hi, [name]" greeting renders with current date
- [ ] KPI cards show correct numbers (match WP admin counts)
- [ ] Action queue shows items (pending approvals from seed data)
- [ ] Upcoming sessions table shows sessions with correct dates
- [ ] Activity feed shows recent audit entries
- [ ] Quick action links navigate to correct WP admin pages
- [ ] User search widget works (type "seed" → shows seed users)
- [ ] No console errors

- [ ] **Step 3: Verify Edities Tab**

Navigate to `#/edities`:
- [ ] Table shows upcoming sessions (each session = 1 row)
- [ ] Today rows highlighted
- [ ] Past rows (up to 3 days) shown, older hidden
- [ ] Filters work: search, status, date range, course tag
- [ ] Click edition name → slide-over opens
- [ ] Slide-over tabs work: Studenten, Aanwezigheid, Info
- [ ] Attendance toggle works (cycles present → absent → excused → clear)
- [ ] Edit button links to WP edit page
- [ ] Pagination works

- [ ] **Step 4: Verify Offertes Tab**

Navigate to `#/offertes`:
- [ ] Quotes table shows with correct columns
- [ ] Filters work
- [ ] Quick-send 📧 shows confirmation popover
- [ ] Quote slide-over shows details and items
- [ ] Edit link goes to WP quote edit page

- [ ] **Step 5: Verify Trajecten Tab**

Navigate to `#/trajecten`:
- [ ] Trajectory table renders
- [ ] Filters work
- [ ] Slide-over shows details, courses, students

- [ ] **Step 6: Verify Gebruikers Tab**

Navigate to `#/gebruikers`:
- [ ] Search for "seed_student1" → user found
- [ ] Click user → detail view shows registrations, quotes, audit trail, attendance
- [ ] "Bekijk als gebruiker" button appears (if manage_options)
- [ ] Click impersonate → switches to user, admin bar shows "Terug naar" button
- [ ] Click "Terug naar" → returns to admin dashboard

- [ ] **Step 7: Verify Settings Tab**

Visit Settings → Meldingen:
- [ ] All 6 rules shown with toggles and thresholds
- [ ] Save works, values persist on reload
- [ ] Changing threshold affects action queue on dashboard

- [ ] **Step 8: Verify Responsive**

Resize browser to 1200px, 768px widths:
- [ ] Dashboard columns stack
- [ ] Tables remain scrollable
- [ ] Header stays functional
- [ ] Slide-overs still usable

- [ ] **Step 9: Commit any fixes**

```bash
git add -A
git commit -m "fix(dashboard): integration fixes from smoke testing"
```

---

### Task 11: Final Polish + Cleanup

- [ ] **Step 1: Verify no console errors across all views**

Open browser DevTools, navigate through all 5 tabs, open/close slide-overs, toggle attendance, search users. Zero console errors expected.

- [ ] **Step 2: Verify no PHP errors**

```bash
ddev exec wp eval "error_reporting(E_ALL); ini_set('display_errors', '1'); echo 'OK';"
# Check debug.log
ddev exec tail -20 /var/www/html/web/wp/wp-content/debug.log
```

- [ ] **Step 3: Run full test suite**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass (including new ActionQueueService, AdminActivityMapper, HealthCheckService, ImpersonationHandler tests).

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore(dashboard): final polish and cleanup"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpcs --standard=PSR12 \
  web/app/mu-plugins/stride-core/Admin/ActionQueueService.php \
  web/app/mu-plugins/stride-core/Admin/AdminActivityMapper.php \
  web/app/mu-plugins/stride-core/Admin/HealthCheckService.php \
  web/app/mu-plugins/stride-core/Admin/ImpersonationHandler.php \
  web/app/mu-plugins/stride-core/Admin/AdminAPIController.php \
  web/app/mu-plugins/stride-core/Admin/AdminDashboardService.php \
  web/app/mu-plugins/stride-core/Admin/StrideSettingsService.php
```

Expected: No errors. Fix all issues before proceeding.

### Stage V2: Unit Tests

**Test files created:**
- `tests/Unit/ActionQueueServiceTest.php`
- `tests/Unit/AdminActivityMapperTest.php`
- `tests/Unit/HealthCheckServiceTest.php`
- `tests/Unit/ImpersonationHandlerTest.php`

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass.

### Stage V3: Acceptance Tests (Browser)

**Manual browser testing (no Codeception acceptance tests for admin SPA — Alpine.js app not testable via standard WP acceptance):**

```
ADMIN FLOW:
  SCENARIO: Dashboard loads with stats
    GIVEN: Seed data exists, admin logged in
    WHEN: Navigate to stride-dashboard
    THEN: KPI cards show numbers, action queue shows items, sessions table has rows

  SCENARIO: Edition slide-over attendance
    GIVEN: Editions tab, click an edition
    WHEN: Toggle attendance cell
    THEN: Cell updates, server returns success, cell shows new status

  SCENARIO: User impersonation round-trip
    GIVEN: Gebruikers tab, search for student
    WHEN: Click "Bekijk als gebruiker"
    THEN: Session switches, admin bar shows return button
    WHEN: Click "Terug naar admin"
    THEN: Session restores, back on dashboard

  SCENARIO: Quick-send quote
    GIVEN: Offertes tab, find a draft quote
    WHEN: Click 📧 icon
    THEN: Confirmation popover appears
    WHEN: Click "Verzenden"
    THEN: Quote status updates to Verzonden, toast confirms

  SCENARIO: Settings save
    GIVEN: Settings → Meldingen tab
    WHEN: Change capacity threshold to 90%, save
    THEN: Option saved, dashboard action queue reflects new threshold

ERROR FLOW:
  SCENARIO: User search with 1 character
    GIVEN: Gebruikers tab
    WHEN: Type "a" (1 char)
    THEN: No API call, hint text shown

  SCENARIO: Impersonate another admin
    GIVEN: Gebruikers tab, search for admin user
    WHEN: Click "Bekijk als gebruiker"
    THEN: Error toast "Kan geen andere beheerder impersoneren"
```

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/phpunit
```

Expected: Zero failures across all suites. New tests + existing tests all pass.

### Stage V5: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-dashboard
      Expected: Soft Violet dashboard loads, purple-tinted background, "Hi, [name]" greeting, KPI cards visible
- [ ] Action: Click through all 5 tabs (Dashboard, Edities, Offertes, Trajecten, Gebruikers)
      Expected: Each tab loads data, no console errors, hash updates in URL
- [ ] Action: Open an edition slide-over, toggle attendance
      Expected: Cell updates, no console error, server response 200
- [ ] Action: Search user "seed_student1" in Gebruikers tab
      Expected: User found, detail view shows registrations and audit trail
- [ ] Action: Click "Bekijk als gebruiker" on a non-admin user
      Expected: Page redirects, admin bar shows "Terug naar" button
- [ ] Action: Click "Terug naar" in admin bar
      Expected: Returns to dashboard as admin
- [ ] Admin: https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-settings → Meldingen tab
      Expected: 6 rules shown, toggles and threshold inputs work, save persists
- [ ] Database: `ddev exec wp option get stride_notification_rules --format=json`
      Expected: JSON with rule config
```
