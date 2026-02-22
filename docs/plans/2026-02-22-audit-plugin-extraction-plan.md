# Audit Plugin Extraction Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Extract Stride's Audit module into standalone `ntdst-audit` plugin that can be reused across NTDST projects.

**Architecture:** Create a new plugin at `web/app/plugins/ntdst-audit/` following the ntdst-auth plugin pattern. The plugin depends on ntdst-core for DI and service patterns. Stride keeps a small bridge service that listens to Stride-specific hooks and calls the generic audit service.

**Tech Stack:** PHP 8.1+, NTDST Core (DI container, NTDST_Service_Meta), WordPress REST API, Alpine.js (admin UI)

**Design Doc:** `docs/plans/2026-02-22-audit-plugin-extraction-design.md`

---

## Phase 1: Create Plugin Scaffold

### Task 1.1: Create Plugin Directory Structure

**Files:**
- Create: `web/app/plugins/ntdst-audit/ntdst-audit.php`
- Create: `web/app/plugins/ntdst-audit/plugin-config.php`

**Step 1: Create plugin bootstrap file**

```php
<?php
/**
 * Plugin Name: NTDST Audit
 * Description: Generic audit logging for WordPress with admin viewer and REST API
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: NTDST
 * Text Domain: ntdst-audit
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Check ntdst-core dependency
if (!function_exists('ntdst_get')) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>NTDST Audit</strong> requires ntdst-core to be active.</p></div>';
    });
    return;
}

define('NTDST_AUDIT_PATH', plugin_dir_path(__FILE__));
define('NTDST_AUDIT_URL', plugin_dir_url(__FILE__));
define('NTDST_AUDIT_VERSION', '1.0.0');

// Autoloader for NTDST\Audit\ namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'NTDST\\Audit\\';
    $base_dir = NTDST_AUDIT_PATH . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Load config and register services
add_action('plugins_loaded', function () {
    $config = require NTDST_AUDIT_PATH . 'plugin-config.php';

    foreach ($config['services'] as $service) {
        ntdst_get($service);
    }
}, 20);
```

**Step 2: Create plugin config file**

```php
<?php

declare(strict_types=1);

return [
    'services' => [
        \NTDST\Audit\AuditService::class,
        \NTDST\Audit\Admin\AdminController::class,
        \NTDST\Audit\Admin\APIController::class,
    ],
];
```

**Step 3: Create directory structure**

Run:
```bash
mkdir -p web/app/plugins/ntdst-audit/{src/Admin,templates/admin,assets/css,assets/js}
```

**Step 4: Commit scaffold**

```bash
git add web/app/plugins/ntdst-audit/
git commit -m "feat(ntdst-audit): create plugin scaffold"
```

---

### Task 1.2: Create AuditTable Schema Class

**Files:**
- Create: `web/app/plugins/ntdst-audit/src/AuditTable.php`

**Step 1: Create table schema class**

```php
<?php

declare(strict_types=1);

namespace NTDST\Audit;

final class AuditTable
{
    public const TABLE_NAME = 'audit_log';

    public static function getTableName(): string
    {
        global $wpdb;
        $tableName = apply_filters('ntdst/audit/table_name', self::TABLE_NAME);
        return $wpdb->prefix . $tableName;
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

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-audit/src/AuditTable.php
git commit -m "feat(ntdst-audit): add AuditTable schema class"
```

---

### Task 1.3: Create AuditRepository

**Files:**
- Create: `web/app/plugins/ntdst-audit/src/AuditRepository.php`

**Step 1: Create repository class**

```php
<?php

declare(strict_types=1);

namespace NTDST\Audit;

use DateTime;
use WP_Error;

class AuditRepository
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

        // Sanitize action - allow dots for namespacing (e.g., 'registration.created')
        $action = preg_replace('/[^a-z0-9._-]/', '', strtolower($data['action']));

        $insert = [
            'entity_type' => sanitize_key($data['entity_type']),
            'entity_id' => absint($data['entity_id']),
            'action' => $action,
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

        // Set "to" to end of day to include all entries from that day
        $toEndOfDay = clone $to;
        $toEndOfDay->setTime(23, 59, 59);

        $sql = "SELECT * FROM {$this->table()} WHERE created_at BETWEEN %s AND %s";
        $params = [$from->format('Y-m-d H:i:s'), $toEndOfDay->format('Y-m-d H:i:s')];

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

        $toEndOfDay = clone $to;
        $toEndOfDay->setTime(23, 59, 59);

        $sql = "SELECT COUNT(*) FROM {$this->table()} WHERE created_at BETWEEN %s AND %s";
        $params = [$from->format('Y-m-d H:i:s'), $toEndOfDay->format('Y-m-d H:i:s')];

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
     * Get distinct entity types for filter dropdown.
     */
    public function getDistinctEntityTypes(): array
    {
        global $wpdb;

        return $wpdb->get_col("SELECT DISTINCT entity_type FROM {$this->table()} ORDER BY entity_type");
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

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-audit/src/AuditRepository.php
git commit -m "feat(ntdst-audit): add AuditRepository"
```

---

### Task 1.4: Create AuditService

**Files:**
- Create: `web/app/plugins/ntdst-audit/src/AuditService.php`

**Step 1: Create service class**

```php
<?php

declare(strict_types=1);

namespace NTDST\Audit;

use WP_Error;

final class AuditService implements \NTDST_Service_Meta
{
    private AuditRepository $repository;

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Service',
            'description' => 'Generic audit logging for compliance',
            'priority' => 99,
        ];
    }

    public function __construct()
    {
        $this->repository = new AuditRepository();
        $this->init();
    }

    private function init(): void
    {
        // Ensure table exists
        if (!AuditTable::exists()) {
            AuditTable::create();
        }

        // Retention cleanup cron
        add_action('ntdst_audit_cleanup', [$this, 'runCleanup']);

        // Schedule cleanup if not scheduled
        if (!wp_next_scheduled('ntdst_audit_cleanup')) {
            wp_schedule_event(time(), 'weekly', 'ntdst_audit_cleanup');
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

        $result = $this->repository->insert([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'context' => $context,
        ]);

        if (!is_wp_error($result)) {
            ntdst_log('audit')->info("Audit: {$action}", [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ]);
        }

        return $result;
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
     * Get repository for advanced queries.
     */
    public function getRepository(): AuditRepository
    {
        return $this->repository;
    }

    /**
     * Run retention cleanup. Called by cron.
     */
    public function runCleanup(): void
    {
        $retentionYears = apply_filters('ntdst/audit/retention_years', 7);
        $before = new \DateTime("-{$retentionYears} years");

        $deleted = $this->repository->deleteOlderThan($before);

        ntdst_log('audit')->info('Audit cleanup completed', [
            'deleted_count' => $deleted,
            'retention_years' => $retentionYears,
        ]);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-audit/src/AuditService.php
git commit -m "feat(ntdst-audit): add AuditService with record/query API"
```

---

## Phase 2: Admin Interface

### Task 2.1: Create Admin Controller

**Files:**
- Create: `web/app/plugins/ntdst-audit/src/Admin/AdminController.php`

**Step 1: Create admin controller**

```php
<?php

declare(strict_types=1);

namespace NTDST\Audit\Admin;

use DateTime;
use NTDST\Audit\AuditService;

final class AdminController implements \NTDST_Service_Meta
{
    private const MENU_SLUG = 'ntdst-audit-log';

    public static function metadata(): array
    {
        return [
            'name' => 'Audit Admin Controller',
            'description' => 'Admin interface for viewing audit logs',
            'admin_only' => true,
            'priority' => 100,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerAdminPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_head', [$this, 'injectStyles']);
        add_action('admin_footer', [$this, 'injectScripts']);
        add_action('wp_ajax_ntdst_audit_export_csv', [$this, 'exportCsv']);
    }

    private function getCapability(): string
    {
        return apply_filters('ntdst/audit/capability', 'manage_options');
    }

    public function registerAdminPage(): void
    {
        add_management_page(
            'Audit Log',
            'Audit Log',
            $this->getCapability(),
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

        wp_localize_script('alpinejs', 'NtdstAuditConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ntdst_audit'),
            'restUrl' => rest_url('ntdst/v1/audit'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    public function injectStyles(): void
    {
        if (!$this->isAuditPage()) {
            return;
        }

        $cssPath = NTDST_AUDIT_PATH . 'assets/css/admin-audit.css';
        if (file_exists($cssPath)) {
            echo '<style id="ntdst-audit-styles">';
            include $cssPath;
            echo '</style>';
        }
    }

    public function injectScripts(): void
    {
        if (!$this->isAuditPage()) {
            return;
        }

        $jsPath = NTDST_AUDIT_PATH . 'assets/js/admin-audit.js';
        if (file_exists($jsPath)) {
            echo '<script>';
            include $jsPath;
            echo '</script>';
        }
    }

    public function renderPage(): void
    {
        $templatePath = NTDST_AUDIT_PATH . 'templates/admin/audit-log.php';

        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            echo '<div class="wrap"><h1>Audit Log</h1><p>Template not found.</p></div>';
        }
    }

    public function exportCsv(): void
    {
        if (!current_user_can($this->getCapability())) {
            wp_die('Unauthorized');
        }

        check_ajax_referer('ntdst_audit', 'nonce');

        $auditService = ntdst_get(AuditService::class);
        $repository = $auditService->getRepository();

        try {
            $from = new DateTime($_POST['from'] ?? '-30 days');
            $to = new DateTime($_POST['to'] ?? 'now');
        } catch (\Exception $e) {
            wp_send_json_error(['message' => 'Invalid date format'], 400);
        }

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

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-audit/src/Admin/AdminController.php
git commit -m "feat(ntdst-audit): add AdminController for Tools menu"
```

---

### Task 2.2: Create API Controller

**Files:**
- Create: `web/app/plugins/ntdst-audit/src/Admin/APIController.php`

**Step 1: Create API controller**

```php
<?php

declare(strict_types=1);

namespace NTDST\Audit\Admin;

use DateTime;
use NTDST\Audit\AuditService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class APIController implements \NTDST_Service_Meta
{
    private const NAMESPACE = 'ntdst/v1';

    public static function metadata(): array
    {
        return [
            'name' => 'Audit API Controller',
            'description' => 'REST API endpoints for audit log',
            'admin_only' => true,
            'priority' => 101,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    private function getCapability(): string
    {
        return apply_filters('ntdst/audit/capability', 'manage_options');
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

        register_rest_route(self::NAMESPACE, '/audit/entity-types', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'getEntityTypes'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can($this->getCapability());
    }

    public function getEntries(WP_REST_Request $request): WP_REST_Response
    {
        $auditService = ntdst_get(AuditService::class);
        $repository = $auditService->getRepository();

        try {
            $from = new DateTime($request->get_param('from') ?: '-30 days');
            $to = new DateTime($request->get_param('to') ?: 'now');
        } catch (\Exception $e) {
            return new WP_REST_Response(['message' => 'Invalid date format'], 400);
        }

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

    public function getEntityTypes(WP_REST_Request $request): WP_REST_Response
    {
        $auditService = ntdst_get(AuditService::class);
        $types = $auditService->getRepository()->getDistinctEntityTypes();

        return new WP_REST_Response($types);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/plugins/ntdst-audit/src/Admin/APIController.php
git commit -m "feat(ntdst-audit): add REST API controller"
```

---

### Task 2.3: Copy Admin Assets

**Files:**
- Create: `web/app/plugins/ntdst-audit/templates/admin/audit-log.php`
- Create: `web/app/plugins/ntdst-audit/assets/css/admin-audit.css`
- Create: `web/app/plugins/ntdst-audit/assets/js/admin-audit.js`

**Step 1: Copy template (update config variable name)**

Copy from `web/app/mu-plugins/stride-core/templates/admin/audit-log.php` and change:
- `StrideAuditConfig` → `NtdstAuditConfig`

**Step 2: Copy CSS**

Copy from `web/app/mu-plugins/stride-core/assets/css/admin-audit.css` (no changes needed).

**Step 3: Copy JS (update config variable name)**

Copy from `web/app/mu-plugins/stride-core/assets/js/admin-audit.js` and change:
- `StrideAuditConfig` → `NtdstAuditConfig`
- `stride_audit` → `ntdst_audit` (nonce action)

**Step 4: Commit**

```bash
git add web/app/plugins/ntdst-audit/templates/ web/app/plugins/ntdst-audit/assets/
git commit -m "feat(ntdst-audit): add admin template and assets"
```

---

## Phase 3: Stride Migration

### Task 3.1: Create Stride AuditBridge

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Audit/AuditBridge.php`

**Step 1: Create bridge service**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Audit;

use NTDST\Audit\AuditService;
use Stride\Infrastructure\AbstractService;

/**
 * Bridge between Stride events and generic NTDST Audit plugin.
 */
final class AuditBridge extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Audit Bridge',
            'description' => 'Connects Stride events to NTDST Audit plugin',
            'priority' => 99,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'audit_bridge';
    }

    protected function init(): void
    {
        // Registration events
        add_action('stride/registration/created', [$this, 'onRegistrationCreated']);
        add_action('stride/registration/cancelled', [$this, 'onRegistrationCancelled']);

        // Attendance events
        add_action('stride/attendance/marked', [$this, 'onAttendanceMarked']);

        // LearnDash completion events
        add_action('learndash_course_completed', [$this, 'onCourseCompleted'], 10, 2);
    }

    private function audit(): AuditService
    {
        return ntdst_get(AuditService::class);
    }

    public function onRegistrationCreated(array $data): void
    {
        $actorId = $data['enrolled_by'] ?? $data['user_id'] ?? null;

        $this->audit()->record(
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
    }

    public function onRegistrationCancelled(array $data): void
    {
        $this->audit()->record(
            'registration',
            (int) $data['registration_id'],
            'registration.cancelled',
            null,
            [
                'user_id' => $data['user_id'] ?? null,
                'edition_id' => $data['edition_id'] ?? null,
            ]
        );
    }

    public function onAttendanceMarked(array $data): void
    {
        $action = match ($data['status'] ?? 'present') {
            'present' => 'attendance.marked_present',
            'absent' => 'attendance.marked_absent',
            'excused' => 'attendance.marked_excused',
            default => 'attendance.marked',
        };

        $this->audit()->record(
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
    }

    public function onCourseCompleted(array $data, \WP_User $user): void
    {
        $courseId = $data['course']->ID ?? $data['course_id'] ?? 0;
        $courseTitle = $data['course']->post_title ?? '';

        $this->audit()->record(
            'completion',
            $courseId,
            'completion.course_completed',
            $user->ID,
            [
                'course_id' => $courseId,
                'course_title' => $courseTitle,
            ]
        );

        // Check if course has a certificate
        if (function_exists('learndash_get_course_certificate_link')) {
            $certificateLink = learndash_get_course_certificate_link($courseId, $user->ID);
            if (!empty($certificateLink)) {
                $this->audit()->record(
                    'completion',
                    $courseId,
                    'completion.certificate_issued',
                    $user->ID,
                    [
                        'course_id' => $courseId,
                        'course_title' => $courseTitle,
                        'certificate_link' => $certificateLink,
                    ]
                );
            }
        }
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/AuditBridge.php
git commit -m "feat(stride): add AuditBridge to connect to ntdst-audit plugin"
```

---

### Task 3.2: Update ActivityShortcode to Use External Service

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Audit/ActivityShortcode.php`

**Step 1: Update imports and constructor**

Change namespace import from `Stride\Modules\Audit\AuditService` to `NTDST\Audit\AuditService`.

The rest of the class stays the same since the API is identical.

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Audit/ActivityShortcode.php
git commit -m "refactor(stride): update ActivityShortcode to use ntdst-audit"
```

---

### Task 3.3: Update Stride plugin-config.php

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

**Step 1: Replace audit service registrations**

Remove:
```php
\Stride\Modules\Audit\AuditService::class,
\Stride\Modules\Audit\Admin\AuditAdminController::class,
\Stride\Modules\Audit\Admin\AuditAPIController::class,
```

Add:
```php
\Stride\Modules\Audit\AuditBridge::class,
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "refactor(stride): replace audit services with bridge"
```

---

### Task 3.4: Remove Old Stride Audit Files

**Files:**
- Delete: `web/app/mu-plugins/stride-core/Modules/Audit/AuditService.php`
- Delete: `web/app/mu-plugins/stride-core/Modules/Audit/AuditRepository.php`
- Delete: `web/app/mu-plugins/stride-core/Modules/Audit/AuditTable.php`
- Delete: `web/app/mu-plugins/stride-core/Modules/Audit/Admin/` directory
- Delete: `web/app/mu-plugins/stride-core/templates/admin/audit-log.php`
- Delete: `web/app/mu-plugins/stride-core/assets/css/admin-audit.css`
- Delete: `web/app/mu-plugins/stride-core/assets/js/admin-audit.js`

**Step 1: Remove files**

```bash
rm web/app/mu-plugins/stride-core/Modules/Audit/AuditService.php
rm web/app/mu-plugins/stride-core/Modules/Audit/AuditRepository.php
rm web/app/mu-plugins/stride-core/Modules/Audit/AuditTable.php
rm -rf web/app/mu-plugins/stride-core/Modules/Audit/Admin/
rm web/app/mu-plugins/stride-core/templates/admin/audit-log.php
rm web/app/mu-plugins/stride-core/assets/css/admin-audit.css
rm web/app/mu-plugins/stride-core/assets/js/admin-audit.js
```

**Step 2: Commit**

```bash
git add -A
git commit -m "refactor(stride): remove old audit module files"
```

---

## Phase 4: Testing & Verification

### Task 4.1: Activate Plugin and Verify

**Step 1: Activate ntdst-audit plugin**

```bash
ddev wp plugin activate ntdst-audit
```

Expected: Plugin activates without errors.

**Step 2: Verify table created**

```bash
ddev wp db query "SHOW TABLES LIKE '%audit_log%'"
```

Expected: `wp_audit_log` table exists.

**Step 3: Verify admin menu**

Visit: `https://stride.ddev.site/wp/wp-admin/tools.php?page=ntdst-audit-log`

Expected: Audit Log page loads with filter UI.

**Step 4: Verify REST API**

```bash
ddev wp eval "echo rest_url('ntdst/v1/audit');"
```

Expected: Returns API URL.

---

### Task 4.2: Test Record and Query

**Step 1: Test recording via WP-CLI**

```bash
ddev wp eval "
\$audit = ntdst_get(\NTDST\Audit\AuditService::class);
\$id = \$audit->record('test', 1, 'test.created', null, ['foo' => 'bar']);
echo is_wp_error(\$id) ? 'ERROR: ' . \$id->get_error_message() : 'Created entry: ' . \$id;
"
```

Expected: `Created entry: N` (some ID)

**Step 2: Test query**

```bash
ddev wp eval "
\$audit = ntdst_get(\NTDST\Audit\AuditService::class);
\$entries = \$audit->getForEntity('test', 1);
echo count(\$entries) . ' entries found';
"
```

Expected: `1 entries found`

---

### Task 4.3: Test Stride Integration

**Step 1: Trigger a Stride event** (if seed data exists)

Visit: Dashboard and perform an action that triggers audit (e.g., registration).

**Step 2: Check audit log**

Visit: `https://stride.ddev.site/wp/wp-admin/tools.php?page=ntdst-audit-log`

Expected: Entry appears with `registration.created` action.

---

### Task 4.4: Final Commit

**Step 1: Verify no PHP errors**

```bash
ddev wp eval "echo 'OK';"
```

Expected: `OK`

**Step 2: Create summary commit if needed**

If all tests pass, the extraction is complete.

---

## Summary

| Phase | Tasks | Description |
|-------|-------|-------------|
| 1 | 1.1-1.4 | Create ntdst-audit plugin scaffold with core classes |
| 2 | 2.1-2.3 | Add admin interface (Tools menu, REST API, assets) |
| 3 | 3.1-3.4 | Migrate Stride to use external plugin via bridge |
| 4 | 4.1-4.4 | Verify plugin works standalone and with Stride |

**Total tasks:** 12 tasks across 4 phases
