# Audit Service Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Event-based audit service for LMS compliance covering enrollments, completions, and attendance with write-once storage.

**Architecture:** Single `wp_stride_audit_log` table. AuditService listens to existing `stride/*` events and LearnDash hooks. AuditRepository is write-only (no update/delete). Admin page with filtering and CSV export. User milestone shortcode.

**Tech Stack:** PHP 8.3, WordPress, Alpine.js (admin), UIkit (frontend shortcode)

**Design Doc:** `docs/plans/2026-02-20-audit-service-design.md`

---

## Task 1: Create AuditTable

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Audit/AuditTable.php`

**Step 1: Create the table class**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Audit;

final class AuditTable
{
    public const TABLE_NAME = 'stride_audit_log';

    public static function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }

    public static function create(): void
    {
        global $wpdb;

        $table = self::getTableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(50) NOT NULL,
            actor_id BIGINT UNSIGNED NULL,
            actor_type VARCHAR(20) NOT NULL DEFAULT 'user',
            context JSON,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_entity (entity_type, entity_id),
            INDEX idx_actor (actor_id),
            INDEX idx_created (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function exists(): bool
    {
        global $wpdb;
        $table = self::getTableName();
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }
}
```

**Step 2: Verify file created**

Run: `ls -la web/app/mu-plugins/stride-core/Modules/Audit/`
Expected: `AuditTable.php` exists

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/AuditTable.php
git commit -m "feat(audit): add AuditTable for audit log storage"
```

---

## Task 2: Create AuditRepository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Audit/AuditRepository.php`

**Step 1: Create the repository class (write-only)**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Audit;

use DateTime;
use WP_Error;

final class AuditRepository
{
    private function table(): string
    {
        return AuditTable::getTableName();
    }

    /**
     * Insert an audit entry. Returns entry ID or WP_Error.
     */
    public function insert(array $data): int|WP_Error
    {
        global $wpdb;

        $required = ['entity_type', 'entity_id', 'action'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Required field: {$field}");
            }
        }

        $insert = [
            'entity_type' => sanitize_key($data['entity_type']),
            'entity_id' => absint($data['entity_id']),
            'action' => sanitize_key($data['action']),
            'actor_id' => isset($data['actor_id']) ? absint($data['actor_id']) : null,
            'actor_type' => sanitize_key($data['actor_type'] ?? 'user'),
            'context' => isset($data['context']) ? wp_json_encode($data['context']) : null,
        ];

        $result = $wpdb->insert($this->table(), $insert);

        if ($result === false) {
            return new WP_Error('db_error', 'Failed to insert audit entry');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Find audit entries by entity.
     */
    public function findByEntity(string $type, int $id): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE entity_type = %s AND entity_id = %d ORDER BY created_at DESC",
            $type,
            $id
        ));
    }

    /**
     * Find audit entries by actor (user).
     */
    public function findByActor(int $actorId, ?string $entityType = null): array
    {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE actor_id = %d";
        $params = [$actorId];

        if ($entityType !== null) {
            $sql .= " AND entity_type = %s";
            $params[] = $entityType;
        }

        $sql .= " ORDER BY created_at DESC";

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Find audit entries within a date range with optional filters.
     */
    public function findByDateRange(
        DateTime $from,
        DateTime $to,
        array $filters = [],
        int $limit = 100,
        int $offset = 0
    ): array {
        global $wpdb;

        $sql = "SELECT * FROM {$this->table()} WHERE created_at BETWEEN %s AND %s";
        $params = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];

        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = %s";
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['actor_id'])) {
            $sql .= " AND actor_id = %d";
            $params[] = (int) $filters['actor_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = %s";
            $params[] = $filters['action'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Count entries matching filters (for pagination).
     */
    public function countByDateRange(DateTime $from, DateTime $to, array $filters = []): int
    {
        global $wpdb;

        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE created_at BETWEEN %s AND %s";
        $params = [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')];

        if (!empty($filters['entity_type'])) {
            $sql .= " AND entity_type = %s";
            $params[] = $filters['entity_type'];
        }

        if (!empty($filters['actor_id'])) {
            $sql .= " AND actor_id = %d";
            $params[] = (int) $filters['actor_id'];
        }

        if (!empty($filters['action'])) {
            $sql .= " AND action = %s";
            $params[] = $filters['action'];
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Get user milestones (enrollments + completions only).
     */
    public function getMilestonesForUser(int $userId): array
    {
        global $wpdb;

        $milestoneActions = [
            'registration.created',
            'completion.course_completed',
            'completion.certificate_issued',
        ];

        $placeholders = implode(',', array_fill(0, count($milestoneActions), '%s'));

        $sql = "SELECT * FROM {$this->table()}
                WHERE actor_id = %d
                AND action IN ({$placeholders})
                ORDER BY created_at DESC";

        $params = array_merge([$userId], $milestoneActions);

        return $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * Delete entries older than retention period. For cron cleanup only.
     */
    public function deleteOlderThan(DateTime $before): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE created_at < %s",
            $before->format('Y-m-d H:i:s')
        ));
    }
}
```

**Step 2: Verify file created**

Run: `ls -la web/app/mu-plugins/stride-core/Modules/Audit/`
Expected: `AuditRepository.php` exists alongside `AuditTable.php`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/AuditRepository.php
git commit -m "feat(audit): add write-only AuditRepository"
```

---

## Task 3: Create AuditService with Event Listeners

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Audit/AuditService.php`

**Step 1: Create the service class**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Audit;

use Stride\Infrastructure\AbstractService;
use WP_Error;

final class AuditService extends AbstractService
{
    private AuditRepository $repository;

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Service',
            'description' => 'Event-based audit logging for compliance',
            'priority' => 99, // Load late to ensure other services are ready
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'audit';
    }

    protected function init(): void
    {
        $this->repository = new AuditRepository();

        // Ensure table exists
        if (!AuditTable::exists()) {
            AuditTable::create();
        }

        // Registration events
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);

        // Attendance events
        add_action('stride/attendance/marked', [$this, 'onAttendanceMarked']);

        // LearnDash completion events
        add_action('learndash_course_completed', [$this, 'onCourseCompleted'], 10, 2);

        // Retention cleanup cron
        add_action('stride_audit_cleanup', [$this, 'runCleanup']);

        // Schedule cleanup if not scheduled
        if (!wp_next_scheduled('stride_audit_cleanup')) {
            wp_schedule_event(time(), 'weekly', 'stride_audit_cleanup');
        }
    }

    /**
     * Record an audit entry.
     */
    public function record(
        string $entityType,
        int $entityId,
        string $action,
        ?int $actorId = null,
        array $context = []
    ): int|WP_Error {
        $actorType = 'user';

        if ($actorId === null) {
            $actorId = get_current_user_id() ?: null;
            if ($actorId === null || $actorId === 0) {
                $actorType = 'system';
                $actorId = null;
            }
        }

        return $this->repository->insert([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'context' => $context,
        ]);
    }

    /**
     * Get audit entries for an entity.
     */
    public function getForEntity(string $entityType, int $entityId): array
    {
        return $this->repository->findByEntity($entityType, $entityId);
    }

    /**
     * Get audit entries for a user (as actor).
     */
    public function getForUser(int $userId): array
    {
        return $this->repository->findByActor($userId);
    }

    /**
     * Get milestone entries for user dashboard.
     */
    public function getMilestonesForUser(int $userId): array
    {
        return $this->repository->getMilestonesForUser($userId);
    }

    /**
     * Get repository for admin queries.
     */
    public function getRepository(): AuditRepository
    {
        return $this->repository;
    }

    // --- Event Handlers ---

    public function onRegistrationCreated(array $data): void
    {
        $actorId = $data['enrolled_by'] ?? $data['user_id'] ?? null;

        $this->record(
            'registration',
            (int) $data['registration_id'],
            'registration.created',
            $actorId ? (int) $actorId : null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
                'enrollment_path' => $data['enrollment_path'] ?? 'individual',
            ]
        );

        ntdst_log('audit')->info('Audit: registration.created', [
            'registration_id' => $data['registration_id'],
        ]);
    }

    public function onRegistrationCancelled(array $data): void
    {
        $this->record(
            'registration',
            (int) $data['registration_id'],
            'registration.cancelled',
            null, // Current user
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
            ]
        );

        ntdst_log('audit')->info('Audit: registration.cancelled', [
            'registration_id' => $data['registration_id'],
        ]);
    }

    public function onAttendanceMarked(array $data): void
    {
        $action = match ($data['status'] ?? 'present') {
            'present' => 'attendance.marked_present',
            'absent' => 'attendance.marked_absent',
            'excused' => 'attendance.marked_excused',
            default => 'attendance.marked',
        };

        $this->record(
            'attendance',
            (int) $data['attendance_id'],
            $action,
            isset($data['marked_by']) ? (int) $data['marked_by'] : null,
            [
                'session_id' => $data['session_id'] ?? null,
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
                'status' => $data['status'] ?? null,
            ]
        );

        ntdst_log('audit')->info("Audit: {$action}", [
            'attendance_id' => $data['attendance_id'],
        ]);
    }

    public function onCourseCompleted(array $data, \WP_User $user): void
    {
        $courseId = $data['course']->ID ?? $data['course_id'] ?? 0;

        $this->record(
            'completion',
            $courseId,
            'completion.course_completed',
            $user->ID,
            [
                'course_id' => $courseId,
                'course_title' => $data['course']->post_title ?? '',
            ]
        );

        ntdst_log('audit')->info('Audit: completion.course_completed', [
            'course_id' => $courseId,
            'user_id' => $user->ID,
        ]);
    }

    /**
     * Run retention cleanup. Called by cron.
     */
    public function runCleanup(): void
    {
        $retentionYears = apply_filters('stride/audit/retention_years', 7);
        $before = new \DateTime("-{$retentionYears} years");

        $deleted = $this->repository->deleteOlderThan($before);

        ntdst_log('audit')->info('Audit cleanup completed', [
            'deleted_count' => $deleted,
            'retention_years' => $retentionYears,
        ]);
    }
}
```

**Step 2: Verify file created**

Run: `ls -la web/app/mu-plugins/stride-core/Modules/Audit/`
Expected: `AuditService.php`, `AuditRepository.php`, `AuditTable.php`

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/AuditService.php
git commit -m "feat(audit): add AuditService with event listeners"
```

---

## Task 4: Register AuditService in plugin-config.php

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Add AuditService to services array**

Find the `'services'` array and add after the last entry:

```php
\Stride\Modules\Audit\AuditService::class,
```

**Step 2: Verify registration**

Run: `ddev exec wp eval "echo class_exists('\Stride\Modules\Audit\AuditService') ? 'OK' : 'FAIL';"`
Expected: `OK`

**Step 3: Verify table created**

Run: `ddev exec wp db query "SHOW TABLES LIKE 'wp_stride_audit_log'"`
Expected: `wp_stride_audit_log`

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(audit): register AuditService in plugin config"
```

---

## Task 5: Add stride/attendance/marked Event to AttendanceService

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceService.php`

**Step 1: Check if event already exists**

Read the `markPresent`, `markAbsent`, `markExcused` methods. If they don't fire `stride/attendance/marked`, add it.

**Step 2: Add event dispatch after recording attendance**

In each mark method, after the `$this->repository->record()` call succeeds, add:

```php
if (!is_wp_error($result)) {
    do_action('stride/attendance/marked', [
        'attendance_id' => $result,
        'session_id' => $sessionId,
        'user_id' => $userId,
        'status' => 'present', // or 'absent', 'excused'
        'edition_id' => $session['edition_id'] ?? null,
        'marked_by' => $markedBy ?? get_current_user_id(),
    ]);
}
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Attendance/AttendanceService.php
git commit -m "feat(audit): add stride/attendance/marked event dispatch"
```

---

## Task 6: Create AuditAdminController

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Audit/Admin/AuditAdminController.php`

**Step 1: Create the admin controller**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Audit\Admin;

use DateTime;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Audit\AuditService;

final class AuditAdminController extends AbstractService
{
    private const MENU_SLUG = 'stride-audit-log';
    private const CAPABILITY = 'manage_options';
    private const PARENT_SLUG = 'stride-dashboard';

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Admin Controller',
            'description' => 'Admin interface for viewing audit logs',
            'admin_only' => true,
            'priority' => 100,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'audit_admin';
    }

    protected function init(): void
    {
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'injectStyles']);
        add_action('admin_footer', [$this, 'injectScripts']);
        add_action('wp_ajax_stride_audit_export_csv', [$this, 'exportCsv']);
    }

    public function registerAdminPage(): void
    {
        add_submenu_page(
            self::PARENT_SLUG,
            'Audit Log',
            'Audit Log',
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_style('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', [], '4.6.13');
        wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', [], '4.6.13', true);
        wp_enqueue_script('flatpickr-nl', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/nl.js', ['flatpickr'], '4.6.13', true);

        wp_enqueue_script(
            'alpinejs',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            ['flatpickr'],
            '3.14.0',
            ['strategy' => 'defer']
        );

        wp_localize_script('alpinejs', 'StrideAuditConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('stride_audit'),
            'restUrl' => rest_url('stride/v1/audit'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function injectStyles(): void
    {
        if (!$this->isAuditPage()) {
            return;
        }

        $cssPath = dirname(__DIR__, 2) . '/assets/css/admin-audit.css';
        if (file_exists($cssPath)) {
            echo '<style id="stride-audit-styles">';
            include $cssPath;
            echo '</style>';
        }
    }

    public function injectScripts(): void
    {
        if (!$this->isAuditPage()) {
            return;
        }

        $jsPath = dirname(__DIR__, 2) . '/assets/js/admin-audit.js';
        if (file_exists($jsPath)) {
            echo '<script>';
            include $jsPath;
            echo '</script>';
        }
    }

    public function renderPage(): void
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/admin/audit-log.php';

        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<div class="wrap"><h1>Audit Log</h1><p>Template not found.</p></div>';
        }
    }

    public function exportCsv(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('Unauthorized');
        }

        check_ajax_referer('stride_audit', 'nonce');

        $auditService = ntdst_get(AuditService::class);
        $repository = $auditService->getRepository();

        $from = new DateTime($_POST['from'] ?? '-30 days');
        $to = new DateTime($_POST['to'] ?? 'now');
        $filters = [
            'entity_type' => sanitize_text_field($_POST['entity_type'] ?? ''),
            'actor_id' => absint($_POST['actor_id'] ?? 0) ?: null,
        ];

        $entries = $repository->findByDateRange($from, $to, array_filter($filters), 10000, 0);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="audit-log-' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');

        fputcsv($output, ['ID', 'Date', 'Entity Type', 'Entity ID', 'Action', 'Actor ID', 'Actor Type', 'Context']);

        foreach ($entries as $entry) {
            fputcsv($output, [
                $entry->id,
                $entry->created_at,
                $entry->entity_type,
                $entry->entity_id,
                $entry->action,
                $entry->actor_id ?? 'system',
                $entry->actor_type,
                $entry->context,
            ]);
        }

        fclose($output);
        exit;
    }

    private function isAuditPage(): bool
    {
        $screen = get_current_screen();
        if (!$screen) {
            $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
            return $page === self::MENU_SLUG;
        }
        return str_contains($screen->id, self::MENU_SLUG);
    }
}
```

**Step 2: Register in plugin-config.php**

Add to services array:

```php
\Stride\Modules\Audit\Admin\AuditAdminController::class,
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/Admin/AuditAdminController.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(audit): add AuditAdminController for admin UI"
```

---

## Task 7: Create Admin Template

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/admin/audit-log.php`

**Step 1: Create the template**

```php
<?php
/**
 * Audit Log Admin Template
 * Alpine.js application for viewing audit entries.
 *
 * @package Stride\Modules\Audit
 */

defined('ABSPATH') || exit;
?>
<div class="wrap stride-audit-app" x-data="strideAuditApp()">
    <h1 class="wp-heading-inline">Audit Log</h1>

    <!-- Filters -->
    <div class="stride-audit-filters">
        <div class="stride-audit-filter-row">
            <label>
                <span>From</span>
                <input type="text" x-ref="dateFrom" x-model="filters.from" placeholder="Start date">
            </label>
            <label>
                <span>To</span>
                <input type="text" x-ref="dateTo" x-model="filters.to" placeholder="End date">
            </label>
            <label>
                <span>Entity Type</span>
                <select x-model="filters.entity_type">
                    <option value="">All</option>
                    <option value="registration">Registration</option>
                    <option value="completion">Completion</option>
                    <option value="attendance">Attendance</option>
                </select>
            </label>
            <label>
                <span>User</span>
                <input type="text" x-model="filters.user_search" placeholder="Search user..." @input.debounce.300ms="searchUsers">
                <select x-model="filters.actor_id" x-show="userResults.length > 0">
                    <option value="">Select user...</option>
                    <template x-for="user in userResults" :key="user.id">
                        <option :value="user.id" x-text="user.name + ' (' + user.email + ')'"></option>
                    </template>
                </select>
            </label>
            <button type="button" class="button button-primary" @click="loadEntries">Filter</button>
            <button type="button" class="button" @click="exportCsv">Export CSV</button>
        </div>
    </div>

    <!-- Loading State -->
    <div x-show="loading" class="stride-audit-loading">
        <span class="spinner is-active"></span> Loading...
    </div>

    <!-- Results Table -->
    <table class="wp-list-table widefat fixed striped" x-show="!loading">
        <thead>
            <tr>
                <th style="width: 140px;">Date</th>
                <th style="width: 100px;">Entity</th>
                <th style="width: 80px;">ID</th>
                <th style="width: 180px;">Action</th>
                <th style="width: 150px;">Actor</th>
                <th>Details</th>
            </tr>
        </thead>
        <tbody>
            <template x-for="entry in entries" :key="entry.id">
                <tr>
                    <td x-text="formatDate(entry.created_at)"></td>
                    <td>
                        <span class="stride-audit-badge" :class="'stride-audit-badge--' + entry.entity_type" x-text="entry.entity_type"></span>
                    </td>
                    <td x-text="entry.entity_id"></td>
                    <td x-text="entry.action"></td>
                    <td>
                        <template x-if="entry.actor_id">
                            <span x-text="entry.actor_name || 'User #' + entry.actor_id"></span>
                        </template>
                        <template x-if="!entry.actor_id">
                            <em class="stride-audit-system">system</em>
                        </template>
                    </td>
                    <td>
                        <button type="button" class="button button-small" @click="entry.expanded = !entry.expanded">
                            <span x-text="entry.expanded ? 'Hide' : 'Show'"></span>
                        </button>
                        <pre x-show="entry.expanded" x-text="formatContext(entry.context)" class="stride-audit-context"></pre>
                    </td>
                </tr>
            </template>
            <tr x-show="entries.length === 0 && !loading">
                <td colspan="6">No audit entries found.</td>
            </tr>
        </tbody>
    </table>

    <!-- Pagination -->
    <div class="stride-audit-pagination" x-show="totalPages > 1">
        <button type="button" class="button" :disabled="page === 1" @click="page--; loadEntries()">Previous</button>
        <span x-text="'Page ' + page + ' of ' + totalPages"></span>
        <button type="button" class="button" :disabled="page >= totalPages" @click="page++; loadEntries()">Next</button>
    </div>
</div>
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/templates/admin/audit-log.php
git commit -m "feat(audit): add admin audit log template"
```

---

## Task 8: Create Admin CSS

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/css/admin-audit.css`

**Step 1: Create the CSS file**

```css
/* Stride Audit Log Admin Styles */
.stride-audit-app {
    max-width: 1400px;
}

.stride-audit-filters {
    background: #fff;
    padding: 16px;
    margin: 16px 0;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
}

.stride-audit-filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    align-items: flex-end;
}

.stride-audit-filter-row label {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.stride-audit-filter-row label span {
    font-weight: 600;
    font-size: 12px;
    color: #1d2327;
}

.stride-audit-filter-row input,
.stride-audit-filter-row select {
    min-width: 150px;
}

.stride-audit-loading {
    padding: 24px;
    text-align: center;
    color: #646970;
}

.stride-audit-loading .spinner {
    float: none;
    margin: 0 8px 0 0;
}

.stride-audit-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.stride-audit-badge--registration {
    background: #e7f3ff;
    color: #0073aa;
}

.stride-audit-badge--completion {
    background: #d4edda;
    color: #155724;
}

.stride-audit-badge--attendance {
    background: #fff3cd;
    color: #856404;
}

.stride-audit-system {
    color: #646970;
}

.stride-audit-context {
    margin-top: 8px;
    padding: 8px;
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    border-radius: 3px;
    font-size: 12px;
    max-width: 400px;
    overflow-x: auto;
    white-space: pre-wrap;
    word-break: break-word;
}

.stride-audit-pagination {
    margin-top: 16px;
    display: flex;
    gap: 12px;
    align-items: center;
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/css/admin-audit.css
git commit -m "feat(audit): add admin audit CSS"
```

---

## Task 9: Create Admin JavaScript

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/js/admin-audit.js`

**Step 1: Create the JavaScript file**

```javascript
/**
 * Stride Audit Log Admin App
 */
function strideAuditApp() {
    return {
        entries: [],
        loading: false,
        page: 1,
        perPage: 50,
        totalPages: 1,
        totalEntries: 0,
        userResults: [],
        filters: {
            from: '',
            to: '',
            entity_type: '',
            actor_id: '',
            user_search: ''
        },

        init() {
            // Initialize date pickers
            this.$nextTick(() => {
                if (typeof flatpickr !== 'undefined') {
                    flatpickr(this.$refs.dateFrom, {
                        dateFormat: 'Y-m-d',
                        locale: 'nl',
                        defaultDate: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000)
                    });
                    flatpickr(this.$refs.dateTo, {
                        dateFormat: 'Y-m-d',
                        locale: 'nl',
                        defaultDate: new Date()
                    });
                }
            });

            // Set default date range
            const now = new Date();
            const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000);
            this.filters.from = thirtyDaysAgo.toISOString().split('T')[0];
            this.filters.to = now.toISOString().split('T')[0];

            // Load initial data
            this.loadEntries();
        },

        async loadEntries() {
            this.loading = true;

            try {
                const params = new URLSearchParams({
                    from: this.filters.from,
                    to: this.filters.to,
                    page: this.page,
                    per_page: this.perPage
                });

                if (this.filters.entity_type) {
                    params.append('entity_type', this.filters.entity_type);
                }
                if (this.filters.actor_id) {
                    params.append('actor_id', this.filters.actor_id);
                }

                const response = await fetch(
                    StrideAuditConfig.restUrl + '?' + params.toString(),
                    {
                        headers: {
                            'X-WP-Nonce': StrideAuditConfig.restNonce
                        }
                    }
                );

                if (!response.ok) {
                    throw new Error('Failed to load audit entries');
                }

                const data = await response.json();
                this.entries = data.entries.map(e => ({ ...e, expanded: false }));
                this.totalEntries = data.total;
                this.totalPages = Math.ceil(data.total / this.perPage);

            } catch (error) {
                console.error('Error loading audit entries:', error);
                alert('Error loading audit entries');
            } finally {
                this.loading = false;
            }
        },

        async searchUsers() {
            if (this.filters.user_search.length < 2) {
                this.userResults = [];
                return;
            }

            try {
                const response = await fetch(
                    StrideAuditConfig.restUrl + '/users?search=' + encodeURIComponent(this.filters.user_search),
                    {
                        headers: {
                            'X-WP-Nonce': StrideAuditConfig.restNonce
                        }
                    }
                );

                if (response.ok) {
                    this.userResults = await response.json();
                }
            } catch (error) {
                console.error('Error searching users:', error);
            }
        },

        exportCsv() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = StrideAuditConfig.ajaxUrl;

            const fields = {
                action: 'stride_audit_export_csv',
                nonce: StrideAuditConfig.nonce,
                from: this.filters.from,
                to: this.filters.to,
                entity_type: this.filters.entity_type,
                actor_id: this.filters.actor_id
            };

            for (const [key, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        },

        formatDate(dateStr) {
            const date = new Date(dateStr);
            return date.toLocaleString('nl-NL', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        },

        formatContext(contextStr) {
            if (!contextStr) return '{}';
            try {
                return JSON.stringify(JSON.parse(contextStr), null, 2);
            } catch {
                return contextStr;
            }
        }
    };
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/js/admin-audit.js
git commit -m "feat(audit): add admin audit JavaScript"
```

---

## Task 10: Create REST API Endpoint for Admin

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Audit/Admin/AuditAPIController.php`

**Step 1: Create the REST controller**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Audit\Admin;

use DateTime;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Audit\AuditService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class AuditAPIController extends AbstractService
{
    private const NAMESPACE = 'stride/v1';

    public static function metadata(): array
    {
        return [
            'name' => 'Audit API Controller',
            'description' => 'REST API endpoints for audit log',
            'admin_only' => true,
            'priority' => 101,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'audit_api';
    }

    protected function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/audit', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getEntries'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'from' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'to' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'entity_type' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'actor_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint',
                ],
                'page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'required' => false,
                    'type' => 'integer',
                    'default' => 50,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/audit/users', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'searchUsers'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => [
                'search' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function getEntries(WP_REST_Request $request): WP_REST_Response
    {
        $auditService = ntdst_get(AuditService::class);
        $repository = $auditService->getRepository();

        $from = new DateTime($request->get_param('from') ?: '-30 days');
        $to = new DateTime($request->get_param('to') ?: 'now');
        $page = max(1, $request->get_param('page'));
        $perPage = min(100, max(1, $request->get_param('per_page')));
        $offset = ($page - 1) * $perPage;

        $filters = array_filter([
            'entity_type' => $request->get_param('entity_type'),
            'actor_id' => $request->get_param('actor_id'),
        ]);

        $entries = $repository->findByDateRange($from, $to, $filters, $perPage, $offset);
        $total = $repository->countByDateRange($from, $to, $filters);

        // Enrich with actor names
        $actorIds = array_filter(array_unique(array_column($entries, 'actor_id')));
        $actorNames = [];

        if (!empty($actorIds)) {
            $users = get_users(['include' => $actorIds, 'fields' => ['ID', 'display_name']]);
            foreach ($users as $user) {
                $actorNames[$user->ID] = $user->display_name;
            }
        }

        $enrichedEntries = array_map(function ($entry) use ($actorNames) {
            $entry->actor_name = $actorNames[$entry->actor_id] ?? null;
            return $entry;
        }, $entries);

        return new WP_REST_Response([
            'entries' => $enrichedEntries,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    public function searchUsers(WP_REST_Request $request): WP_REST_Response
    {
        $search = $request->get_param('search');

        $users = get_users([
            'search' => '*' . $search . '*',
            'search_columns' => ['user_login', 'user_email', 'display_name'],
            'number' => 10,
            'fields' => ['ID', 'display_name', 'user_email'],
        ]);

        $results = array_map(function ($user) {
            return [
                'id' => $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
            ];
        }, $users);

        return new WP_REST_Response($results);
    }
}
```

**Step 2: Register in plugin-config.php**

Add to services array:

```php
\Stride\Modules\Audit\Admin\AuditAPIController::class,
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/Admin/AuditAPIController.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(audit): add REST API for audit log queries"
```

---

## Task 11: Create User Milestones Shortcode

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Audit/ActivityShortcode.php`

**Step 1: Create the shortcode class**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Audit;

use Stride\Modules\Edition\EditionService;

final class ActivityShortcode
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly EditionService $editionService,
    ) {
        add_shortcode('stride_my_activity', [$this, 'renderMilestones']);
    }

    /**
     * Render user's milestone activity.
     */
    public function renderMilestones(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '<p>Je moet ingelogd zijn om je activiteit te zien.</p>';
        }

        $userId = get_current_user_id();
        $milestones = $this->auditService->getMilestonesForUser($userId);

        if (empty($milestones)) {
            return '<div class="uk-alert uk-alert-primary">Nog geen activiteit gevonden.</div>';
        }

        $output = '<div class="uk-timeline">';

        foreach ($milestones as $milestone) {
            $context = json_decode($milestone->context ?? '{}', true) ?: [];
            $date = date_i18n('j F Y', strtotime($milestone->created_at));
            $icon = $this->getIcon($milestone->action);
            $label = $this->getLabel($milestone->action, $context);

            $output .= sprintf(
                '<div class="uk-timeline-item">
                    <div class="uk-timeline-icon">
                        <span uk-icon="%s"></span>
                    </div>
                    <div class="uk-timeline-content uk-card uk-card-default uk-card-body uk-card-small">
                        <p class="uk-text-meta uk-margin-remove-bottom">%s</p>
                        <p class="uk-margin-remove-top">%s</p>
                    </div>
                </div>',
                esc_attr($icon),
                esc_html($date),
                wp_kses_post($label)
            );
        }

        $output .= '</div>';

        return $output;
    }

    private function getIcon(string $action): string
    {
        return match ($action) {
            'registration.created' => 'check',
            'completion.course_completed' => 'star',
            'completion.certificate_issued' => 'file-pdf',
            default => 'info',
        };
    }

    private function getLabel(string $action, array $context): string
    {
        return match ($action) {
            'registration.created' => $this->getRegistrationLabel($context),
            'completion.course_completed' => $this->getCompletionLabel($context),
            'completion.certificate_issued' => $this->getCertificateLabel($context),
            default => 'Activiteit geregistreerd',
        };
    }

    private function getRegistrationLabel(array $context): string
    {
        $editionId = $context['edition_id'] ?? 0;
        $edition = $editionId ? $this->editionService->getEdition($editionId) : null;

        if ($edition && !is_wp_error($edition)) {
            return sprintf('Je hebt je ingeschreven voor <strong>%s</strong>.', esc_html($edition->post_title));
        }

        return 'Je hebt je ingeschreven voor een cursus.';
    }

    private function getCompletionLabel(array $context): string
    {
        $courseTitle = $context['course_title'] ?? '';

        if ($courseTitle) {
            return sprintf('Je hebt <strong>%s</strong> afgerond.', esc_html($courseTitle));
        }

        return 'Je hebt een cursus afgerond.';
    }

    private function getCertificateLabel(array $context): string
    {
        $courseTitle = $context['course_title'] ?? '';

        if ($courseTitle) {
            return sprintf('Certificaat uitgereikt voor <strong>%s</strong>.', esc_html($courseTitle));
        }

        return 'Certificaat uitgereikt.';
    }
}
```

**Step 2: Register in plugin-config.php**

The shortcode class needs to be instantiated. Add a service wrapper or instantiate in AuditService. Simplest: add to services array (if it implements NTDST_Service_Meta or is auto-constructed).

For simplicity, instantiate it in AuditService's init():

In `AuditService::init()`, add at the end:

```php
// Register user-facing shortcode
new ActivityShortcode($this, ntdst_get(EditionService::class));
```

**Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/ActivityShortcode.php
git add web/app/mu-plugins/stride-core/Modules/Audit/AuditService.php
git commit -m "feat(audit): add user milestones shortcode [stride_my_activity]"
```

---

## Task 12: Verify Full Integration

**Step 1: Verify table exists**

Run: `ddev exec wp db query "DESCRIBE wp_stride_audit_log"`
Expected: Table columns shown

**Step 2: Test event listener**

Run: `ddev exec wp eval "do_action('stride/registration/created', ['registration_id' => 999, 'user_id' => 1, 'edition_id' => 1]);"`

Then verify entry:
Run: `ddev exec wp db query "SELECT * FROM wp_stride_audit_log WHERE entity_id = 999"`
Expected: Row with `registration.created` action

**Step 3: Test admin page**

Navigate to: `https://stride.ddev.site/wp/wp-admin/admin.php?page=stride-audit-log`
Expected: Audit log page loads with filter form

**Step 4: Test shortcode**

Add `[stride_my_activity]` to a page and view as logged-in user.
Expected: Timeline with milestone (from test in step 2)

**Step 5: Clean up test data**

Run: `ddev exec wp db query "DELETE FROM wp_stride_audit_log WHERE entity_id = 999"`

**Step 6: Final commit**

```bash
git add -A
git commit -m "feat(audit): complete audit service implementation

- AuditTable: wp_stride_audit_log with indexes
- AuditRepository: write-only with query methods
- AuditService: event listeners for registrations, attendance, completions
- AuditAdminController: admin page with filtering
- AuditAPIController: REST endpoints for admin queries
- ActivityShortcode: user milestone timeline
- Retention cleanup via weekly cron"
```

---

## V2 Notes (Future Enhancements)

Document these in `AuditService.php` as a docblock comment:

```php
/**
 * V2 Considerations (not in V1):
 *
 * 1. Chained hashes - Each entry references previous hash for tamper detection
 * 2. External verification - Exports to S3 Glacier / signed PDFs
 * 3. Real-time notifications - Admin alerts on specific actions
 * 4. Extended coverage - Quotes, vouchers, profile changes
 * 5. Full-text search - Search within context JSON
 * 6. Granular user view - Detailed attendance history
 * 7. Audit reports - Pre-built compliance reports
 * 8. REST API for external systems - External integrations
 * 9. GDPR anonymization - Actor anonymization after user deletion
 */
```

---

## Summary

| Task | Description | Files |
|------|-------------|-------|
| 1 | AuditTable | `Modules/Audit/AuditTable.php` |
| 2 | AuditRepository | `Modules/Audit/AuditRepository.php` |
| 3 | AuditService | `Modules/Audit/AuditService.php` |
| 4 | Register service | `plugin-config.php` |
| 5 | Add attendance event | `Modules/Attendance/AttendanceService.php` |
| 6 | AuditAdminController | `Modules/Audit/Admin/AuditAdminController.php` |
| 7 | Admin template | `templates/admin/audit-log.php` |
| 8 | Admin CSS | `assets/css/admin-audit.css` |
| 9 | Admin JS | `assets/js/admin-audit.js` |
| 10 | AuditAPIController | `Modules/Audit/Admin/AuditAPIController.php` |
| 11 | ActivityShortcode | `Modules/Audit/ActivityShortcode.php` |
| 12 | Integration verification | Manual testing |
