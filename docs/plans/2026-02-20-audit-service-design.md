# Audit Service Design

**Date:** 2026-02-20
**Status:** Approved
**Scope:** V1 - Enrollments, Completions, Attendance

## Overview

Event-based audit service for LMS compliance. Records who did what, when, with tamper-resistant storage. Users see milestones in their dashboard; admins get full audit log with filtering and export.

## Requirements

| Requirement | Decision |
|-------------|----------|
| Primary driver | Compliance (tamper-evident records) |
| Secondary | User transparency, admin oversight |
| Scope V1 | Enrollments, completions, attendance |
| Retention | 5-7 years |
| Tamper-evidence | Write-once table (no UPDATE/DELETE in app) |
| User view | Milestones only (enrolled, completed, certificate) |

## Approach

**Single Audit Log Table + Event Listeners**

All state changes flow through services that fire `stride/*` events. AuditService listens to these events and writes immutable records to `wp_stride_audit_log`.

Alternatives considered:
- Per-entity journal tables (more complex, harder cross-entity queries)
- WordPress CPT (wp_posts not optimized for high-volume append-only)

## Data Model

### Table: `wp_stride_audit_log`

```sql
CREATE TABLE wp_stride_audit_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED | Primary key |
| `entity_type` | VARCHAR(50) | `registration`, `completion`, `attendance` |
| `entity_id` | BIGINT UNSIGNED | ID of the entity |
| `action` | VARCHAR(50) | `created`, `cancelled`, `completed`, `marked_present`, etc. |
| `actor_id` | BIGINT UNSIGNED NULL | User who performed action (NULL for system) |
| `actor_type` | VARCHAR(20) | `user`, `system`, `cron` |
| `context` | JSON | Additional data (edition_id, course_id, old/new status, etc.) |
| `created_at` | DATETIME | Timestamp for retention cleanup |

## Service Architecture

### AuditRepository

`stride-core/Modules/Audit/AuditRepository.php`

```php
class AuditRepository
{
    public function insert(array $data): int;
    public function findByEntity(string $type, int $id): array;
    public function findByActor(int $actorId, ?string $entityType = null): array;
    public function findByDateRange(DateTime $from, DateTime $to, array $filters = []): array;
}
```

**No `update()` or `delete()` methods** — write-once by design.

### AuditService

`stride-core/Modules/Audit/AuditService.php`

```php
class AuditService implements \NTDST_Service_Meta
{
    public function record(
        string $entityType,
        int $entityId,
        string $action,
        ?int $actorId,
        array $context = []
    ): void;

    public function getForEntity(string $entityType, int $entityId): array;
    public function getForUser(int $userId): array;
    public function getMilestonesForUser(int $userId): array;
}
```

### Event Listeners

AuditService hooks into events in its constructor:

| Event | Action Recorded |
|-------|-----------------|
| `stride/registration/created` | `registration.created` |
| `stride/registration/cancelled` | `registration.cancelled` |
| `stride/attendance/marked` | `attendance.marked_present` / `marked_absent` / `marked_excused` |
| `learndash_course_completed` | `completion.course_completed` |
| `learndash_certificate_generated` | `completion.certificate_issued` |

### Actor Resolution

1. Event context (`enrolled_by`, `marked_by` if passed)
2. Fall back to `get_current_user_id()`
3. NULL + `actor_type='system'` for cron/automated actions

## Events to Add

| Location | New Event | Trigger |
|----------|-----------|---------|
| `AttendanceRepository` | `stride/attendance/marked` | After insert/update in `markAttendance()` |

LearnDash events (`learndash_course_completed`, `learndash_certificate_generated`) already exist; AuditService hooks them directly.

## User & Admin Views

### User Dashboard (Milestones)

Shortcode `[stride_my_activity]` or integrate into `[stride_my_courses]`.

Shows milestone cards:
- "You enrolled in Edition X on Jan 15, 2025"
- "You completed Course Y on Feb 20, 2025"
- "Certificate issued on Feb 20, 2025" (with download link)

### Admin View

Admin page: **Stride → Audit Log**

- Filterable table: entity type, date range, user (autocomplete)
- Columns: Date, User, Action, Entity, Details (expandable JSON)
- Export to CSV for compliance reporting
- Uses existing admin patterns (external CSS/JS, Alpine.js)

## Retention

Scheduled cron job (`stride_audit_cleanup`) runs weekly:
- Deletes records older than retention period (configurable, default 7 years)
- Logs cleanup count

## Data Flow

```
User action → Service method → dispatch() event
                                    ↓
                            AuditService listener
                                    ↓
                            AuditRepository::insert()
                                    ↓
                            wp_stride_audit_log table
```

## V2 Considerations

Future enhancements not in V1 scope:

1. **Chained hashes** — Each entry references previous entry's hash for cryptographic tamper detection (blockchain-lite)
2. **External verification** — Periodic exports to immutable storage (S3 Glacier, signed PDFs) for external audit
3. **Real-time notifications** — Alert admins on specific actions (e.g., bulk cancellations)
4. **Extended entity coverage** — Quotes, vouchers, user profile changes, course progress events
5. **Audit log search** — Full-text search in context JSON
6. **Granular user view** — Let users see detailed attendance history, not just milestones
7. **Audit reports** — Pre-built compliance reports (training completion rates, attendance summaries)
8. **API endpoints** — REST API for external integrations to query audit data
9. **Anonymization** — GDPR-compliant actor anonymization after user deletion (preserve audit integrity without PII)

## Out of Scope (V1)

- Cryptographic verification (chained hashes)
- External immutable storage
- Real-time notifications
- Quotes/vouchers/profile auditing
- Granular attendance in user view
