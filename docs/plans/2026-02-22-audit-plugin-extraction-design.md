# Audit Plugin Extraction Design

**Date:** 2026-02-22
**Status:** Approved
**Goal:** Extract Stride's Audit module into a standalone reusable plugin

## Overview

Create `ntdst-audit` as a reusable WordPress plugin that provides generic audit logging. The plugin depends on NTDST Core for DI and service patterns but has no Stride-specific code.

## Package Structure

```
web/app/plugins/ntdst-audit/
├── ntdst-audit.php              # Plugin header + register services
├── composer.json                # PSR-4 autoload, requires ntdst-core
├── plugin-config.php            # Service list
├── src/
│   ├── AuditService.php         # Implements NTDST_Service_Meta - public API
│   ├── AuditRepository.php      # Database operations
│   ├── AuditTable.php           # Schema management
│   └── Admin/
│       ├── AdminController.php  # Tools submenu, CSV export
│       └── APIController.php    # REST endpoints
├── templates/
│   └── admin/audit-log.php      # Alpine.js admin UI
└── assets/
    ├── css/admin-audit.css
    └── js/admin-audit.js
```

**Namespace:** `NTDST\Audit`

## Public API

```php
namespace NTDST\Audit;

class AuditService implements \NTDST_Service_Meta
{
    // Record an audit entry
    public function record(
        string $entityType,    // e.g., 'user', 'order', 'registration'
        int $entityId,
        string $action,        // e.g., 'created', 'updated', 'deleted'
        ?int $actorId = null,  // null = current user or 'system'
        array $context = []    // arbitrary JSON data
    ): int|WP_Error;

    // Query methods
    public function getForEntity(string $type, int $id): array;
    public function getForUser(int $userId): array;
    public function getRepository(): AuditRepository;
}
```

**Usage:**
```php
ntdst_get(\NTDST\Audit\AuditService::class)->record(
    'order',
    $orderId,
    'payment.completed',
    context: ['amount' => 99.00, 'method' => 'ideal']
);
```

## Admin Interface

- **Location:** Tools > Audit Log (`add_management_page()`)
- **Capability:** `manage_options` (configurable via filter)

**Features:**
- Date range filter (Flatpickr)
- Entity type filter (dynamic dropdown)
- User search (autocomplete)
- Paginated results table
- Expandable JSON context
- CSV export

**REST Endpoints:**
- `GET /ntdst/v1/audit` - List entries with filters
- `GET /ntdst/v1/audit/users` - User search
- `GET /ntdst/v1/audit/entity-types` - Distinct entity types

## Configuration Filters

```php
// Change required capability
add_filter('ntdst/audit/capability', fn() => 'edit_others_posts');

// Change retention period (default 7 years)
add_filter('ntdst/audit/retention_years', fn() => 5);

// Change table name
add_filter('ntdst/audit/table_name', fn() => 'custom_audit_log');
```

## Database

Same schema as current Stride implementation:

```sql
CREATE TABLE {prefix}audit_log (
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
);
```

## Migration Path for Stride

### 1. Remove from stride-core
- `Modules/Audit/` directory
- `templates/admin/audit-log.php`
- `assets/css/admin-audit.css`
- `assets/js/admin-audit.js`
- Service registrations from `plugin-config.php`

### 2. Add dependency
```bash
composer require ntdst/audit
```

### 3. Create bridge in stride-core
New file: `Stride\Modules\Audit\AuditBridge.php` (~40 lines)

Listens to Stride hooks and calls the external AuditService:
- `stride/registration/created`
- `stride/registration/cancelled`
- `stride/attendance/marked`
- `learndash_course_completed`

### 4. Keep ActivityShortcode in Stride
The `[stride_my_activity]` shortcode stays in stride-core (Stride-specific UI) but uses `ntdst_get(\NTDST\Audit\AuditService::class)` for data.

### 5. Database
No migration needed - same table schema. Update table name constant from `stride_audit_log` to `audit_log`.

## Dependencies

- **Requires:** ntdst-core (NTDST_Service_Meta, ntdst_get, ntdst_log)
- **No Stride dependencies**

## Out of Scope (V2 Considerations)

From original AuditService comments - not included in this extraction:
- Chained hashes for tamper detection
- External verification (S3 Glacier, signed PDFs)
- Real-time admin notifications
- Full-text search in context JSON
- Pre-built compliance reports
- GDPR anonymization
