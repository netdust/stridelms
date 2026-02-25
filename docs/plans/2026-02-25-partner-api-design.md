# Partner API Design

**Date:** 2026-02-25
**Status:** Approved
**Module:** `stride-core/Modules/PartnerAPI`

## Overview

REST API for partner organizations to query their users' data and enroll users in courses. Partners are companies (training organizations, employers) who send their employees/members to Stride courses.

## Goals

- Partners can query enrollments, certificates, attendance for their users
- Partners can enroll users via API
- Scoped access: partners only see their own users' data
- Simple auth using WordPress Application Passwords
- Minimal new infrastructure (no custom tables for auth)

## Non-Goals (v1)

- Progress tracking (lesson-level completion)
- Self-service partner portal
- Webhooks for real-time updates
- FluentCRM company linkage (future)

---

## Architecture

### Module Structure

```
stride-core/
├── Modules/
│   └── PartnerAPI/
│       ├── PartnerAPIController.php      # REST routes + handlers
│       ├── PartnerAuthMiddleware.php     # Role check + company_id extraction
│       └── PartnerEnrollmentService.php  # Enrollment logic for API
```

### Authentication

Uses WordPress Application Passwords (built-in since WP 5.6):

1. Admin creates WP user with role `partner`
2. Admin sets `_stride_company_id` user meta
3. Admin generates Application Password in user profile
4. Partner uses Basic auth: `Authorization: Basic base64(username:app_password)`

**Auth flow:**
```
Request with Basic auth
    ↓
WP authenticates → get_current_user_id()
    ↓
Check user role = 'partner'
    ↓
Get company_id from usermeta
    ↓
Scope all queries by company_id
```

### Company Scoping

**User level:** `_stride_company_id` in `wp_usermeta`
- Users belonging to a company have this meta set
- Partner queries filter by users with matching company_id

**Registration level:** `company_id` column in `wp_vad_registrations`
- Optional override per enrollment
- Allows freelancers training for multiple clients
- Falls back to user's company_id if not set

**Company entity:** Stubbed for now
- `company_id` is just an integer, not linked to any entity
- Future: links to FluentCRM company

---

## Database Changes

### Add to wp_vad_registrations

```sql
ALTER TABLE wp_vad_registrations
ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER quote_id,
ADD INDEX idx_company (company_id);
```

### Add partner role (on activation)

```php
add_role('partner', 'Partner', ['read' => true]);
```

### User meta

```
_stride_company_id = {integer}
```

---

## API Endpoints

**Base URL:** `/wp-json/stride/v1/partner`

### GET /users

List users belonging to partner's company.

**Response:**
```json
{
  "data": [
    {
      "id": 123,
      "email": "jan@acme.com",
      "first_name": "Jan",
      "last_name": "Janssen",
      "registered_at": "2026-01-15T10:00:00Z"
    }
  ],
  "total": 25,
  "page": 1,
  "per_page": 20
}
```

### GET /enrollments

List enrollments for partner's users.

**Query params:**
- `page`, `per_page` - pagination
- `status` - filter by status (confirmed, completed, cancelled)
- `edition_id` - filter by edition
- `user_id` - filter by specific user (must belong to company)

**Response:**
```json
{
  "data": [
    {
      "id": 456,
      "user_id": 123,
      "user_email": "jan@acme.com",
      "edition_id": 789,
      "edition_title": "Basisopleiding Vastgoed - Maart 2026",
      "course_title": "Basisopleiding Vastgoed",
      "status": "confirmed",
      "registered_at": "2026-02-01T14:30:00Z",
      "completed_at": null
    }
  ]
}
```

### GET /enrollments/{id}

Single enrollment with full details.

### GET /certificates

List certificates for partner's users.

**Response:**
```json
{
  "data": [
    {
      "user_id": 123,
      "user_email": "jan@acme.com",
      "course_id": 101,
      "course_title": "Basisopleiding Vastgoed",
      "completed_at": "2026-02-20T16:00:00Z",
      "certificate_url": "https://stride.be/certificate/abc123"
    }
  ]
}
```

### GET /attendance

Attendance records for in-person sessions.

**Query params:**
- `edition_id` - filter by edition
- `user_id` - filter by user

**Response:**
```json
{
  "data": [
    {
      "user_id": 123,
      "session_id": 555,
      "session_date": "2026-03-01",
      "session_title": "Dag 1 - Introductie",
      "status": "present",
      "hours": 7.5
    }
  ]
}
```

### POST /enrollments

Enroll a user in an edition or trajectory.

**Request:**
```json
{
  "user_email": "jan@acme.com",
  "edition_id": 789,
  "create_user": true
}
```

- `user_email` - required
- `edition_id` or `trajectory_id` - one required
- `create_user` - optional, create WP user if not exists (default: false)

**Response:**
```json
{
  "id": 456,
  "user_id": 123,
  "edition_id": 789,
  "status": "confirmed",
  "registered_at": "2026-02-25T12:00:00Z"
}
```

**Errors:**
- `400` - Invalid request (missing fields, invalid edition)
- `403` - User exists but doesn't belong to partner's company
- `404` - Edition not found
- `409` - User already enrolled

---

## Implementation Notes

### Reuses Existing Services

- `RegistrationRepository` - query/create enrollments
- `AttendanceRepository` - query attendance
- `LearnDashHelper` - get certificates
- `EditionService` - validate editions

### Scoped Queries

All queries add company filter:

```php
private function getCompanyId(): int
{
    return (int) get_user_meta(
        get_current_user_id(),
        '_stride_company_id',
        true
    );
}

// In repository calls
$registrations = $this->registrationRepository->findByCompany(
    $this->getCompanyId(),
    $filters
);
```

### User Creation (POST /enrollments)

When `create_user: true`:
1. Create WP user with email
2. Set `_stride_company_id` to partner's company
3. Generate random password (user can reset)
4. Proceed with enrollment

---

## Admin Interface

Partners are managed via standard WP user admin:

1. **Create user** with role `partner`
2. **Set company_id** via custom field on user profile
3. **Generate Application Password** in user profile (WP core UI)

Optional enhancement (later): Custom admin page listing all partners with their API usage.

---

## Security Considerations

- HTTPS required (Application Passwords only work over HTTPS in production)
- Rate limiting via existing NTDST API rate limiter (30 req/min default)
- Partners can only access their own company's data
- Enrollment creates audit trail via existing logging

---

## Future Enhancements

- **FluentCRM integration** - Link company_id to FluentCRM companies
- **Webhooks** - Notify partners of enrollment/completion events
- **Usage dashboard** - Show API usage stats per partner
- **Granular permissions** - Read-only vs read-write per partner
- **MCP wrapper** - Expose Partner API via MCP for AI agents
