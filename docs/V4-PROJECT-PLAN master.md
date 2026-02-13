# VAD Vormingen v4 - Deep Project Plan

**Date:** 2026-02-11 (updated 2026-02-13)
**Approach:** Fresh WordPress install, migrate users, query old DB for history
**Codebase:** `stride` theme with NTDST Core, DataManager ORM, UIkit frontend

---

## Executive Summary

Fresh WordPress installation with:
- LearnDash (course content only — lessons, quizzes, certificates)
- FluentCRM + FluentForms (CRM, forms)
- NTDST Core from Rossi (architecture, DI, DataManager ORM)
- Custom services for everything else (editions, sessions, enrollment, invoicing)

**User data migrated.** Historical data (enrollments, invoices, certificates) queried from old database.

### Key Architectural Decisions

- **LearnDash is a content engine only** — 4 integration points: `grantAccess`, `revokeAccess`, `isComplete`, `getCertificateLink`
- **Edition/Session model** — editions are scheduled instances of LD courses; sessions are individual meeting days within editions
- **DataManager ORM** — all CPTs registered via `ntdst_data()->register()` for schema, querying, and admin UI
- **Hybrid storage** — CPTs for content entities (editions, sessions, vouchers, quotes, trajectories, enrollments); postmeta for low-volume relational data (attendance, trajectory courses)
- **FluentCRM as contact store** — subscriber data, company data, tags, notes all in FluentCRM; UserDataSync provides multi-backend read/write
- **UIkit frontend** — dashboard and templates use UIkit 3 for layout and components

---

## Current Implementation Status

The following is already built and working. The plan phases below account for this — items marked ✅ are complete, items marked 🔄 need refactoring for the edition model.

### ✅ Complete (keep as-is)

| Service | Lines | Status |
|---------|-------|--------|
| `SubscriberService` | 1,038 | FluentCRM wrapper, field ops, tags, notes, company links |
| `OrganizationService` | 800 | Company CRUD, invoice data, member detection |
| `QuoteService` | 1,104 | CPT via DataManager, CRUD, status flow, PDF, API endpoints |
| `VoucherService` | 994 | CPT via DataManager, code gen, redemption with tx locking |
| `EnrollmentQuoteHandler` | 337 | Bridge: enrollment → quote creation with business rules |
| `QuoteUpdateHandler` | — | User-facing quote edit flow (billing, voucher code) |
| `UserDataSync` | 547 | Multi-backend sync (WP ↔ FluentCRM), findOrCreateUser |
| `SmartCodeService` | 369 | FluentCRM/FluentForms merge tags |
| `HistoricalDataService` | 553 | Read-only bridge to V3 database |
| `FieldRegistry` | 473 | Field name mapping, legacy ↔ new conversion |
| `LearnDashAdapter` | 218 | Thin LD wrapper behind interface |
| `FluentCRMAdapter` | — | Thin FluentCRM wrapper behind interface |
| `AdminMenuService` | 82 | Main Stride admin menu |
| `DashboardShortcodes` | — | Shortcode registration for dashboard pages |
| `ICalService` | — | Calendar export |
| `FluentFormsFieldHandler` | 122 | Company dropdown from FluentCRM companies |
| `ExactOnlineExporter` | 418 | CSV export for accounting |
| `QuoteCalculator` | 256 | Pure calculation helper |
| `QuoteItemFactory` | 331 | Standardized item creation |
| `VoucherCodeGenerator` | 102 | Code generation with collision detection |
| `QuotePDFGenerator` | — | DOMPDF rendering with template |
| `VATValidator` | — | Belgian BTW validation with async revalidation |
| `CurrencyFormatter` | — | EUR formatting |
| `QuoteConfig` | — | Configuration helper |
| `QuoteAuditLogger` | — | Audit trail |

### ✅ Complete Templates

| Template | Lines | Purpose |
|----------|-------|---------|
| `dashboard/home.php` | 195 | Dashboard home with upcoming courses |
| `dashboard/courses.php` | 94 | Course grid |
| `dashboard/quotes.php` | 148 | Quote list with status |
| `dashboard/profile.php` | 179 | Profile edit form |
| `dashboard/calendar.php` | 162 | Calendar view |
| `dashboard/trajectories.php` | 119 | Trajectory overview |
| `dashboard/trajectory-single.php` | 256 | Journey visualization |
| `course/archive.php` | 223 | Course catalog |
| `partials/course-card.php` | 126 | Course card component |
| `partials/course-sidebar.php` | 160 | Sidebar with enrollment actions |
| `forms/quote-update.php` | 421 | Customer quote edit form |
| `pdf/quote.php` | 488 | PDF quote template |

### 🔄 Needs Refactoring (for edition model)

| Service | Lines | What Changes |
|---------|-------|-------------|
| `CourseService` | 1,034 | Split into thin CourseService + new EditionService + SessionService |
| `EnrollmentService` | 413 | Accept editionId, create registration record |
| `FormSubmissionHandler` | 530 | Extract edition_id instead of course_id |
| `DashboardService` | 1,047 | Query editions instead of courses for dates/status |
| All dashboard templates | ~1,200 | Reference editions for dates, capacity, venue |

---

## Feature Inventory Summary

| Area | Features Count | Complexity |
|------|----------------|------------|
| Enrollment System | 15+ paths/variations | High |
| Voucher System | 10+ business rules | Medium |
| Admin Dashboard | 20+ features | High |
| User Frontend | 15+ views/features | Medium |
| Invoicing/Quotes | 10+ features | Medium |

---

## Data Model

### CPTs (registered via DataManager)

| CPT | Volume | Purpose |
|-----|--------|---------|
| `vad_edition` | ~240/year | Scheduled course offerings (dates, capacity, venue, price) |
| `vad_session` | ~500/year | Individual meeting days within editions |
| `vad_voucher` | ~1,000/year | Discount codes ✅ already built |
| `vad_quote` | ~5,000/year | Quotes/invoices ✅ already built |
| `vad_trajectory` | ~5 total | Multi-year programs |
| `vad_enrollment` | ~50-100/year | Trajectory enrollments |

### Custom Tables

| Table | Volume | Reason |
|-------|--------|--------|
| `wp_vad_registrations` | ~5,000/year | High-volume, needs fast user+edition queries |

> **Note:** The original plan specified custom tables for quotes and quote_lines. The code already uses DataManager CPTs for both quotes and vouchers. At ~5k quotes/year, the CPT+meta approach works fine and gives admin UI for free. We keep this approach.

### Key Meta Structures

**Edition meta (on `vad_edition` CPT):**
```php
_vad_course_id       = 123              // linked LD course
_vad_start_date      = '2026-03-15'
_vad_end_date        = '2026-03-16'
_vad_capacity        = 20
_vad_price           = 250.00
_vad_price_non_member = 350.00
_vad_venue           = 'VAD Brussel'
_vad_speakers        = 'Jan Peeters, trainer; An Claes, gastspreker'
_vad_status          = 'open'           // open|full|cancelled|postponed|announcement|completed
_vad_invoice_item    = 'ART001'
_vad_invoice_enabled = true
_vad_certificate_enabled = true
_vad_custom_form     = 'Vorming Basis'  // FluentForms title
```

**Session meta (on `vad_session` CPT):**
```php
_vad_edition_id  = 456
_vad_date        = '2026-03-15'
_vad_start_time  = '09:00'
_vad_end_time    = '17:00'
_vad_location    = 'Zaal A'            // can differ from edition venue
_vad_attendees   = [42, 56, 78, 91]    // user IDs who attended
```

**Trajectory courses (on `vad_trajectory` CPT):**
```php
_vad_courses = [
    ['course_id' => 123, 'group' => 'Basismodules', 'required' => true, 'pick_count' => null],
    ['course_id' => 456, 'group' => 'Keuzemodules', 'required' => true, 'pick_count' => 2]
]
```

**Trajectory enrollment (on `vad_enrollment` CPT):**
```php
_vad_trajectory_id  = 789
_vad_user_id        = 42
_vad_enrolled_at    = '2026-01-15'
_vad_deadline_at    = '2028-01-15'
_vad_status         = 'active'          // active|completed|expired|withdrawn
_vad_graduated_at   = null
```

### Registration Table

```sql
CREATE TABLE wp_vad_registrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    edition_id BIGINT UNSIGNED NOT NULL,
    status ENUM('confirmed','cancelled','waitlist','interest') DEFAULT 'confirmed',
    enrollment_path ENUM('individual','colleague','trajectory','interest') DEFAULT 'individual',
    enrolled_by BIGINT UNSIGNED NULL,        -- manager user ID for colleague enrollments
    voucher_code VARCHAR(50) NULL,
    quote_id BIGINT UNSIGNED NULL,
    registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    cancelled_at DATETIME NULL,
    notes TEXT NULL,
    INDEX idx_user (user_id),
    INDEX idx_edition (edition_id),
    INDEX idx_status (status),
    UNIQUE KEY unique_user_edition (user_id, edition_id)
);
```

---

## Phase 0: Foundation (Week 1-2) ✅ COMPLETE

### 0.1 Fresh WordPress Setup
- [x] New WordPress installation on staging (Bedrock)
- [x] Configure wp-config.php with old DB connection for historical queries
- [x] Install LearnDash
- [x] Install FluentCRM
- [x] Install FluentForms
- [x] Basic security hardening

### 0.2 NTDST Core Setup
- [x] Port NTDST Core from Rossi
- [x] Set up service auto-discovery
- [x] Configure DI container (`ntdst_set()` / `ntdst_get()`)
- [x] DataManager ORM available (`ntdst_data()`)
- [x] Set up routing system
- [x] Test basic service loading

### 0.3 Theme Structure
```
themes/stride/
├── functions.php              ✅ Bootstrap with DI bindings
├── theme-config.php           ✅ All config in one place
├── style.css
├── header.php / footer.php
├── assets/
│   ├── css/stride.css
│   └── js/stride.js
├── services/
│   ├── FieldRegistry.php      ✅ Field name mapping
│   ├── adapters/              ✅ LearnDash + FluentCRM adapters
│   ├── contracts/             ✅ Interfaces for adapters
│   ├── core/                  ✅ CourseService, SubscriberService, OrganizationService, HistoricalDataService
│   ├── enrollment/            ✅ EnrollmentService, FormSubmissionHandler, FluentFormsFieldHandler
│   ├── invoicing/             ✅ QuoteService, VoucherService, helpers, admin, export
│   ├── handlers/              ✅ EnrollmentQuoteHandler, QuoteUpdateHandler
│   ├── smartcode/             ✅ SmartCodeService
│   ├── sync/                  ✅ UserDataSync, backends, hooks
│   ├── frontend/              ✅ DashboardService, DashboardShortcodes, ICalService
│   └── admin/                 ✅ AdminMenuService
├── templates/                 ✅ Dashboard, course, forms, pdf templates
├── forms/
└── setup/
```

### 0.4 Historical Data Bridge
- [x] `HistoricalDataService` with configurable legacy DB connection
- [x] Methods for: old enrollments, old invoices, old certificates, old vouchers
- [x] Read-only queries with request-level caching (15 min TTL)

---

## Phase 1: Core Services (Week 3-4) — ✅ MOSTLY COMPLETE

### 1.1 CourseService (LearnDash Wrapper) ✅

Already built with 1,034 lines. Current implementation wraps LD course meta directly.

**Complete:**
- [x] Course type detection (`isInPerson`, `isOnline`, `isTraject`)
- [x] Course dates (`getCourseDates`, `getStartDate`, `getNextDate`, `hasStarted`, `hasEnded`)
- [x] Course status (`isCancelled`, `isPostponed`, `isFull`, `isAnnouncement`)
- [x] Capacity (`getCapacity`, `getEnrolledCount`, `hasAvailableSpots`)
- [x] Speakers parsing
- [x] User enrollment (`isUserEnrolled`, `enrollUser`, `unenrollUser`)
- [x] Modules/trajectories
- [x] Pricing (`getCoursePrice`, `getInvoiceItem`)
- [x] Settings, certificates, custom forms
- [x] Request-level settings cache
- [x] Authorization helpers

> **⚠️ REFACTORING NEEDED:** Most of these methods move to `EditionService` in Phase 1.5. CourseService becomes a thin wrapper around LD content only.

### 1.2 SubscriberService ✅

Already built with 1,038 lines.

- [x] Core operations (get, findOrCreate, getByEmail)
- [x] Field operations (get, update, bulk update)
- [x] Invoice/billing data
- [x] Notes (create, get)
- [x] Tags (add, remove, get, has)
- [x] Company links (get, link, unlink, sync)
- [x] Member status detection
- [x] Batch operations
- [x] Email domain helpers

### 1.3 OrganizationService ✅

Already built with 800 lines.

- [x] Company CRUD via FluentCRM
- [x] Company custom fields (invoice name, address, VAT, GLN, export ID)
- [x] User-company linking
- [x] Partner/member detection

### 1.4 UserDataSync ✅

Already built with 547 lines. Multi-backend sync layer.

- [x] Read strategy: check backends in priority order
- [x] Write strategy: write-through to ALL backends
- [x] `findOrCreateUser()` with WP user + FluentCRM contact creation
- [x] `setField()` / `setFields()` — syncs to WP usermeta + FluentCRM
- [x] Backend abstraction (`WordPressUserStorage`, `FluentCRMStorage`)

### 1.5 FieldRegistry ✅

Already built with 473 lines.

- [x] All field name constants (subscriber, company, course)
- [x] Legacy V3 ↔ V4 field mapping
- [x] Context-aware conversion (`legacyToNew`, `newToLegacy`)
- [x] Database field name resolution (legacy mode toggle)
- [x] Labels for admin UI

---

## Phase 1.5: Edition/Session Layer (Week 5-6) — ✅ COMPLETE

This critical refactoring step is complete. The Edition/Session layer is implemented and tested.

### Council Review Notes (2026-02-13)

The architecture was reviewed by a multi-agent council (Architect, Designer, Engineer, Researcher). Key findings:

**Unanimous Agreement:**
- Sessions as separate CPT - correct for admin UX, filtering, bulk operations
- LearnDash stays minimal (4-point integration) - prevents coupling
- Edition as connection layer between Course content and Session logistics

**Recommendations for Future Phases:**
- **Attendance Table Migration (Phase 5):** Current attendance uses JSON arrays in postmeta. Council recommends migrating to dedicated `{prefix}_vad_attendance` table before building bulk check-in UI. Schema below.
- **Certificate Merge Tags (Phase 5):** LearnDash certificates need custom shortcodes to include Edition-specific data (dates, instructor). Plan: `[stride_edition_dates]`, `[stride_instructor]`, etc.

**Proposed Attendance Table (implement before Phase 7 Admin Dashboard):**
```sql
CREATE TABLE {prefix}_vad_attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('present','absent','excused') DEFAULT 'present',
  marked_by BIGINT UNSIGNED NULL,
  marked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_session_user (session_id, user_id),
  INDEX idx_user_status (user_id, status),
  UNIQUE KEY unique_session_user (session_id, user_id)
);
```

### 1.5.1 CourseService Split ✅

**Implemented:** CourseService remains for LearnDash content, EditionService handles scheduling, SessionService handles meeting days.

```
services/core/
├── CourseService.php      # LearnDash content wrapper (grantAccess, revokeAccess, isComplete, getCertificateLink)
├── EditionService.php     # Scheduled offerings (dates, price, capacity, venue, status)
├── SessionService.php     # Meeting days (time slots, attendance, hours calculation)
└── RegistrationRepository.php  # Enrollment records (custom table)
```

### 1.5.2 EditionService ✅

**Implemented:** `services/core/EditionService.php` (~400 lines)

- [x] `vad_edition` CPT registered via DataManager
- [x] Edition CRUD: `createEdition()`, `getEdition()`, `updateEdition()`
- [x] Query methods: `getEditionsForCourse()`, `getUpcomingEditions()`
- [x] Date methods: `getStartDate()`, `getEndDate()`, `hasStarted()`, `hasEnded()`
- [x] Status methods: `getStatus()`, `isCancelled()`, `isPostponed()`, `isFull()`
- [x] Capacity: `getCapacity()`, `getRegisteredCount()`, `hasAvailableSpots()`
- [x] Pricing: `getPrice()`, `getPriceNonMember()`
- [x] Venue/Speakers: `getVenue()`, `getSpeakers()`
- [x] Linked course: `getCourseId()`

### 1.5.3 SessionService ✅

**Implemented:** `services/core/SessionService.php` (~450 lines)

- [x] `vad_session` CPT registered via DataManager
- [x] Session CRUD: `createSession()`, `getSession()`, `updateSession()`
- [x] Query methods: `getSessionsForEdition()`, `getSessionCount()`, `getDayCount()`
- [x] Attendance: `markPresent()`, `markAbsent()`, `isPresent()`, `getAttendees()`
- [x] Hours calculation: `getSessionDuration()`, `getTotalHours()`, `getHoursAttended()`, `getAttendanceRate()`
- [x] Authorization: `canManageAttendance()` with filter hook
- [x] Batch fetching: `batchGetAttendees()` for N+1 prevention

**Current Attendance Storage:** JSON array in postmeta (`_vad_attendees`)
**Future Migration:** Move to dedicated `{prefix}_vad_attendance` table (see council notes above)

### 1.5.4 FieldRegistry Updates ✅

**Implemented:** All edition and session field constants added to `FieldRegistry.php`

- [x] Edition fields: `EDITION_COURSE_ID`, `EDITION_START_DATE`, `EDITION_END_DATE`, `EDITION_CAPACITY`, `EDITION_PRICE`, `EDITION_PRICE_NON_MEMBER`, `EDITION_VENUE`, `EDITION_SPEAKERS`, `EDITION_STATUS`
- [x] Session fields: `SESSION_EDITION_ID`, `SESSION_DATE`, `SESSION_START_TIME`, `SESSION_END_TIME`, `SESSION_ATTENDEES`
- [x] Legacy course fields kept for HistoricalDataService compatibility

### 1.5.5 Registration Table Setup ✅

**Implemented:** `services/core/RegistrationRepository.php` + `{prefix}_vad_registrations` table

Table created with columns:
- `id`, `user_id`, `edition_id`, `status`, `enrollment_path`
- `enrolled_by`, `voucher_code`, `quote_id`
- `registered_at`, `cancelled_at`, `notes`

Repository methods:
- [x] CRUD: `create()`, `get()`, `update()`, `delete()`
- [x] Queries: `getByEdition()`, `getByUser()`, `countByEdition()`
- [x] Status: `confirm()`, `cancel()`, `waitlist()`
- [x] Path constants: `PATH_INDIVIDUAL`, `PATH_COLLEAGUE`, `PATH_TRAJECTORY`, `PATH_INTEREST`

### 1.5.6 Wiring Updates 🔄

**Phase 1.5 Core Complete.** Wiring updates are part of Phase 2 (Enrollment) and Phase 6 (Frontend).

| Service | Status | Notes |
|---------|--------|-------|
| EnrollmentService | 🔄 Phase 2 | Refactor to accept `editionId` instead of `courseId` |
| FormSubmissionHandler | 🔄 Phase 2 | Extract `edition_id` from form data |
| EnrollmentQuoteHandler | 🔄 Phase 3 | Resolve price from Edition, not Course |
| SmartCodeService | 🔄 Phase 5 | Add `stride_edition.*` merge tags |
| DashboardService | 🔄 Phase 6 | Query editions instead of courses for dates |

### 1.5.7 Development Seed Scripts ✅

**Implemented:** `scripts/seed.php` and `scripts/unseed.php`

```bash
# Seed test data (users, courses, editions, sessions, registrations, vouchers, quotes)
ddev exec wp eval-file scripts/seed.php

# Remove all seed data
ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'
```

Creates: 11 users, 8 courses, 9 editions, 13 sessions, 12 registrations, 3 groups, 7 vouchers, 13 quotes

---

## Phase 2: Enrollment System (Week 7-8) — ✅ COMPLETE

### 2.1 Enrollment Paths

**Path 1: Individual Edition Enrollment** ✅
- [x] Form detection
- [x] User lookup/creation via UserDataSync
- [x] Organization parsing (existing company vs new)
- [x] Profile sync via UserDataSync
- [x] Registration record creation via `RegistrationRepository::create()`
- [x] LearnDash enrollment via CourseService adapter
- [x] Quote creation via EnrollmentQuoteHandler
- [x] Voucher application
- [x] FluentCRM notes
- [x] Capacity update via `EditionService::updateCapacityStatus()`

**Path 2: Colleague/Group Enrollment** ✅
- [x] Repeater field parsing: `[first_name, last_name, email]`
- [x] Duplicate email validation
- [x] Per-colleague enrollment loop
- [x] Manager relationship tracking
- [x] Per-colleague invoicing
- [x] Manager gets CRM note for each enrollee
- [x] Per-colleague registration records via `enrollInEdition()` per colleague

**Path 3: Trajectory Enrollment** ✅
- [x] Group-based enrollment via `enrollUserInGroup()` (deprecated)
- [x] Trajectory CPT creation (`vad_trajectory`) via TrajectoryService
- [x] Trajectory enrollment table (`wp_vad_trajectory_enrollments`) via TrajectoryEnrollmentRepository
- [x] Auto-enroll in mandatory course editions for cohort mode (ProgressEngine)
- [ ] Optional course selection UI (Phase 6)
- [x] Trajectory-level invoicing via EnrollmentQuoteHandler
- [x] Trajectory modes: self-paced (individual pace) and cohort (pre-linked editions)
- [x] Elective choice tracking in enrollment repository

> **Note:** Full trajectory enrollment implemented in Phase 5.

**Path 4: Interest/Waitlist Form** ✅
- [x] Form submission only (no enrollment)
- [x] Store for admin review via `registerInterest()`
- [x] Course/edition association

### 2.2 Enrollment Form Fields ✅

Already handled by `FormSubmissionHandler.buildEnrollmentData()` with Dutch/English field name support.

### 2.3 Enrollment Flow & Handlers ✅

**Pre-Enrollment (Filter: `stride/enrollment/before_enroll`):**
- [x] Data modification or abort (returns WP_Error)

**Enrollment:**
- [x] LearnDash `ld_update_course_access()` via adapter
- [x] Registration record in `wp_vad_registrations`
- [x] Capacity check via `EditionService::hasAvailableSpots()`

**Post-Enrollment (Action: `stride/enrollment/completed`):**
- [x] EnrollmentQuoteHandler — auto-creates quote (item type: 'edition')
- [x] CRM audit note
- [x] Update edition status if full via `EditionService::onRegistrationCreated()`

### 2.4 Manager/Colleague Tracking ✅

Implemented via registration table: `enrolled_by` column.

- [x] `getEnrollingManager()` retrieves manager from registration
- [x] `isManaged()` check
- [x] Colleague enrollment path tracks manager relationship

### 2.5 Enrollment Validation ✅

Implemented in `EditionService.canUserEnroll()`:

- [x] Not already enrolled (via RegistrationRepository)
- [x] Not cancelled
- [x] Not ended
- [x] Capacity available
- [x] Not announcement mode
- [x] External filter hook (`stride/edition/can_enroll`)

### 2.6 Cancellation Rules ✅

Implemented in `EnrollmentService`:

- [x] `getCancellationPolicy()` - Returns policy based on 14-day rule
- [x] >14 days before edition start: free cancellation allowed
- [x] ≤14 days before edition start: can swap to colleague, quote still invoiced
- [x] `cancelRegistration()` - Updates status, fires hook with policy info
- [x] `swapToColleague()` - Creates new registration, links to same quote

**Key methods:**
```php
$policy = $enrollmentService->getCancellationPolicy($registrationId);
// Returns: can_cancel, free_cancellation, can_swap, days_until_start, message

$enrollmentService->cancelRegistration($registrationId);
$enrollmentService->swapToColleague($registrationId, $colleagueUserId);
```

---

## Phase 3: Quote/Invoice System (Week 9-10) — ✅ MOSTLY COMPLETE

### 3.1 Quote CPT ✅

**Post Type:** `vad_quote` — registered via DataManager with full field schema.

Already built with:
- [x] CPT registration via `ntdst_data()->register()`
- [x] Field schema (user_id, item_type, item_id, status, items JSON, billing JSON, totals, dates)
- [x] Status flow: draft → sent → exported
- [x] Quote number generation (atomic MySQL transaction: `VADQ-YYYY-NNNNN`)
- [x] API endpoints (get, update, list)
- [x] PDF generation with DOMPDF
- [x] PDF download route with secure hash
- [x] VAT revalidation hook
- [x] Admin controller with custom metaboxes (overview, actions, notes)

### 3.2 Quote Service ✅

- [x] `createQuoteForItem($userId, $itemType, $itemId, $data)` — generic item support
- [x] `sendQuote($quoteId)` — status: sent, fires hook
- [x] `exportQuote($quoteId)` — status: exported, fires hook
- [x] `getQuote($quoteId)` — full quote data
- [x] `getUserQuotes($userId)` — with status filter
- [x] `findQuote($where)` — flexible query
- [x] Currency formatting
- [x] Item type agnostic via filter: `stride/quote/resolve_item`

### 3.3 Quote Business Rules 🔄

- [x] Auto-create on enrollment (via EnrollmentQuoteHandler)
- [x] Skip rules: admin users, internal domains (vad.be, druglijn.be), geen-factuur tag, free courses
- [x] Duplicate prevention (check existing by user+course)
- [x] Quote cancellation on free cancellation (>14 days before edition start) — via `EnrollmentQuoteHandler::onEnrollmentCancelled()`
- [x] `QuoteService::cancelQuote()` — new method, sets status to 'cancelled'
- [x] `QuoteService::STATUS_CANCELLED` — new status constant
- [ ] Billing details editable until 14 days before edition start (NEW — needs edition date)
- [ ] Lock quote 14 days before (cron job, NEW)
- [ ] Belgian OGM payment reference generation (verify implementation)

### 3.4 Exact Online Export ✅

Already built with 418 lines.
- [x] CSV export format
- [x] Batch processing with chunk size
- [x] Capability checks
- [x] Mark as exported after download

### 3.5 Refactoring for Edition Model ✅

All edition-model refactoring complete in `EnrollmentQuoteHandler`:

- [x] `resolvePrice()` — resolves from edition via `EditionService::getPrice()`
- [x] `shouldCreateQuote()` — checks edition price via `EditionService::getPrice()`
- [x] Quote item type: `'edition'` for new quotes
- [x] `'course'` type handling preserved for legacy/historical quotes
- [x] Registration-quote linking via `RegistrationRepository::linkQuote()`

---

## Phase 4: Voucher System (Week 11-12) — ✅ MOSTLY COMPLETE

### 4.1 Voucher CPT ✅

**Post Type:** `vad_voucher` — registered via DataManager with full field schema.

Already built with:
- [x] CPT registration via `ntdst_data()->register()`
- [x] Field schema (code, type, usage_limit, used_count, discount_type, discount_value, dates, status, redemptions JSON)
- [x] Status: active → exhausted/expired/disabled
- [x] Rate limiting on validation API
- [x] Transaction locking for redemption (prevents race conditions)
- [x] Admin controller
- [x] Item type agnostic (works with any item type via filter)

### 4.2 Voucher Service ✅

- [x] `createVoucher($data)` with code generation
- [x] `createBatch($count, $data)` for bulk creation
- [x] `validateVoucher($code, $itemId)` with comprehensive checks
- [x] `redeemVoucher($code, $userId, $itemType, $itemId)` with atomic transaction
- [x] `calculateDiscount($voucher, $itemType, $itemId, $itemPrice)` — supports full/fixed/percentage
- [x] `expireVouchers()` — maintenance cron
- [x] Query methods (by batch, by status, user redemptions)

### 4.3 Voucher Business Rules (VAD-specific) — 🔄 NEEDS IMPLEMENTATION

- [ ] 5 voucher types: member, action, speaker, day, social
- [ ] Member vouchers: 2/year with membership, 2-year expiry
- [ ] Member vouchers: can't use for tweejarige opleiding
- [ ] Day voucher auto-conversion for multi-day editions (prorated: 1/N per day)
- [ ] Ervaringsdeskundigen 50% social tariff
- [ ] Can't split, extend, or refund vouchers

### 4.4 Voucher Admin ✅

- [x] Admin controller with overview
- [x] Create/edit forms
- [x] Status management

---

## Phase 5: Attendance & Completion (Week 13-14) — ✅ COMPLETE

### 5.0 Council Recommendations (Pre-requisites) ✅

#### 5.0.1 Attendance Table Migration ✅

Migrated from JSON postmeta to dedicated table for concurrent check-ins and audit trails.

```sql
CREATE TABLE {prefix}_vad_attendance (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  edition_id BIGINT UNSIGNED NOT NULL,
  session_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  status ENUM('present','absent','excused') DEFAULT 'present',
  marked_by BIGINT UNSIGNED NULL,
  marked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_session_user (session_id, user_id),
  INDEX idx_user_status (user_id, status),
  UNIQUE KEY unique_session_user (session_id, user_id)
);
```

- [x] Create migration to add `{prefix}_vad_attendance` table
- [x] Create `AttendanceRepository` class with CRUD methods
- [x] Update `SessionService` to use repository instead of postmeta
- [x] Add migration script to copy existing postmeta attendance to new table (`scripts/migrate-attendance-to-table.php`)
- [x] Add `marked_by` and `marked_at` for audit trail

#### 5.0.2 Certificate Merge Tags ✅

LearnDash certificates include Edition-specific data via SmartCodeService shortcodes.

- [x] Create custom shortcodes: `[stride_edition_title]`, `[stride_instructor]`, `[stride_venue]`
- [x] Additional shortcodes: `[stride_hours_attended]`, `[stride_total_hours]`, `[stride_attendance_rate]`
- [x] Register shortcodes in SmartCodeService
- [x] Hook into LearnDash certificate generation to inject Edition context

### 5.1 Attendance Tracking ✅ (Basic Implementation)

Basic attendance implemented in SessionService. Uses postmeta (to be migrated in 5.0.1).

```php
// SessionService methods (already implemented):
$sessionService->markPresent($sessionId, $userId);
$sessionService->markAbsent($sessionId, $userId);
$sessionService->isPresent($sessionId, $userId);
$sessionService->getAttendees($sessionId);
$sessionService->getHoursAttended($userId, $editionId);
$sessionService->getAttendanceRate($userId, $editionId);
```

### 5.2 CompletionEngine ✅

**Implemented:** `core/CompletionEngine.php`

Determines if a user has completed an edition based on attendance. Supports 3 modes:
- `attend_all`: Must attend all sessions
- `percentage`: Must attend X% of sessions
- `count`: Must attend at least N sessions

```php
// CompletionEngine methods:
$completionEngine->isEditionComplete($editionId, $userId);
$completionEngine->processCompletion($editionId, $userId); // Auto-triggers LD course completion
```

### 5.3 ProgressEngine ✅

**Implemented:** `core/ProgressEngine.php`

Tracks trajectory progress and handles enrollment.

```php
class ProgressEngine {
    public function getTrajectoryProgress(int $trajectoryId, int $userId): array {
        $courses = get_post_meta($trajectoryId, '_vad_courses', true);
        $completed = [];

        foreach ($courses as $course) {
            $courseId = $course['course_id'];
            if ($courseService->isComplete($userId, $courseId)) {
                $completed[] = $courseId;
            }
        }

        return [
            'total' => count($courses),
            'completed' => count($completed),
            'percentage' => count($completed) / max(count($courses), 1) * 100,
            'completed_courses' => $completed,
        ];
    }

    // Check group requirements (basis + keuze modules)
    public function meetsRequirements(int $trajectoryId, int $userId): bool;

    // Auto-graduate when all requirements met
    public function checkGraduation(int $trajectoryId, int $userId): void;
}
```

---

## Phase 6: User Frontend (Week 15-17) — 🔄 TEMPLATES EXIST, NEED EDITION REFACTOR

### 6.1 User Dashboard (`/mijn-account/`) ✅ Structure exists

**Dashboard Home** (`dashboard/home.php`, 195 lines) ✅
- [x] Welcome message
- [x] Upcoming courses
- [x] Recent activity
- [x] Quick links
- [ ] 🔄 Query upcoming editions instead of courses

**My Courses** (`dashboard/courses.php`, 94 lines) ✅
- [x] Course grid with cards
- [x] Status badges
- [x] Progress display
- [ ] 🔄 Show editions (with dates) grouped by course

**My Trajectories** (`dashboard/trajectories.php`, 119 lines) ✅
- [x] Trajectory cards with progress
- [ ] 🔄 Query `vad_trajectory` CPT instead of LD groups

**Trajectory Single** (`dashboard/trajectory-single.php`, 256 lines) ✅
- [x] Journey visualization (not grid)
- [x] Current position indicator
- [x] Next action prominently displayed
- [x] Mandatory vs optional courses
- [ ] 🔄 Query `vad_trajectory` CPT for course groups

**My Quotes** (`dashboard/quotes.php`, 148 lines) ✅
- [x] Quote list with status
- [x] View details
- [x] Download PDF

**My Profile** (`dashboard/profile.php`, 179 lines) ✅
- [x] Edit personal info
- [x] Edit contact info
- [x] Organization link

### 6.2 Course Catalog ✅ Structure exists

**Course Archive** (`course/archive.php`, 223 lines) ✅
- [x] Filter by category
- [x] Search by title
- [x] Pagination
- [x] Course cards
- [ ] 🔄 Show next available edition dates on cards

**Course Card** (`partials/course-card.php`, 126 lines) ✅
- [x] Card layout with key info
- [ ] 🔄 Display edition dates, capacity, status

**Course Sidebar** (`partials/course-sidebar.php`, 160 lines) ✅
- [x] Status-aware enrollment buttons
- [x] Course info (dates, venue, speakers)
- [ ] 🔄 Pull info from edition, not course meta

### 6.3 Calendar Features ✅

- [x] Calendar template (162 lines)
- [x] iCal export service
- [ ] 🔄 Calendar data from editions/sessions

### 6.4 Quote Update Form ✅

- [x] Customer quote edit form (421 lines)
- [x] Billing details editing
- [x] Voucher code application

---

## Phase 7: Admin Dashboard (Week 18-21)

### 7.1 Edition Dashboard (NEW — replaces "Course Dashboard")

**Edition List View:**
- [ ] Edition list sorted by start date
- [ ] Filters: date range, status, course
- [ ] Search by title
- [ ] Quick stats per edition (registered / capacity)
- [ ] Status badges (open, full, cancelled, etc.)

**Edition Detail View (tabs):**
- [ ] Tab: Cursisten (registered students from `wp_vad_registrations`)
- [ ] Tab: Offertes (quotes linked to this edition)
- [ ] Tab: Inzendingen (form submissions)
- [ ] Tab: Interesse (interest registrations)

### 7.2 Students Table (Cursisten)

**Columns:**
- [ ] Name (with FluentCRM link)
- [ ] Function
- [ ] Organization (merged company + personal)
- [ ] Department
- [ ] VAD Member badge
- [ ] Enrollment path (individual, colleague, trajectory)
- [ ] Quote number
- [ ] Attendance checkboxes per session

**Actions:**
- [ ] View as user
- [ ] Unenroll (with cancellation rules)
- [ ] View form submission
- [ ] Send certificate

**Bulk Actions:**
- [ ] Mark present (all sessions)
- [ ] Mark absent (all sessions)
- [ ] Send certificates
- [ ] Unenroll selected

### 7.3 Attendance Panel

**Prerequisite:** Complete Phase 5.0.1 (Attendance Table Migration) before building this UI.

- [ ] Per-session attendance checkboxes
- [ ] AJAX toggle via `AttendanceRepository` (not postmeta)
- [ ] Bulk set session as present/absent (single INSERT with multiple rows)
- [ ] Hours column (calculated from session durations)
- [ ] Completion status indicator
- [ ] Audit trail display (who marked, when)

### 7.4 Quote/Invoice Table ✅ (admin metaboxes exist)

Already built via `QuoteAdminController` with:
- [x] Overview metabox
- [x] Actions metabox (send, export)
- [x] Notes metabox

### 7.5 Edition Status Toggles

- [ ] Status dropdown (open, full, cancelled, postponed, announcement, completed)
- [ ] On status change: fire hooks, trigger FluentCRM automations

### 7.6 Email Panel

- [ ] Select recipients (all registered or specific)
- [ ] Template dropdown
- [ ] Subject and body editors
- [ ] Merge tags via SmartCodeService: `{stride_edition.title}`, `{stride_contact.first_name}`, etc.
- [ ] Send via AJAX

### 7.7 Export Functionality

**Export Types:**
- [ ] Students export (CSV) — from registrations table
- [ ] Quotes export (CSV) — from quote CPT
- [ ] Attendance list (PDF)
- [ ] Name cards (PDF)

### 7.8 Unified User Profile View

**When admin views a user, show everything:**

```
┌─────────────────────────────────────────────────────────────────┐
│  USER: Jan Janssens                              [Edit in CRM]  │
├─────────────────────────────────────────────────────────────────┤
│  📋 PROFILE              │  🏢 ORGANIZATION                     │
│  jan@example.be          │  VAD Partner vzw                     │
│  +32 123 456 789         │  Department: Preventie               │
│  Preventiewerker         │  VAD Member: ✅                      │
├─────────────────────────────────────────────────────────────────┤
│  📚 EDITIONS (from new system)                                   │
│  • Vorming Drugs Basis (Mrt 2026) │ ✅ Completed │ 12h attended │
│  • Online Intro                    │ 75%          │              │
│                                                                 │
│  📚 HISTORICAL COURSES (from old system)                        │
│  • Previous Course 2023    │ In-Person │ Completed    │ 2023    │
├─────────────────────────────────────────────────────────────────┤
│  💰 QUOTES                                                      │
│  • VADQ-2026-00123 │ Vorming Drugs │ €250 │ Exported           │
│                                                                 │
│  💰 HISTORICAL INVOICES (from old system)                       │
│  • VADV-2023-001 │ Previous │ €200 │ Paid                      │
├─────────────────────────────────────────────────────────────────┤
│  📝 RECENT NOTES                                                │
│  • 2026-03-15: Ingeschreven voor Vorming Drugs Basis (Mrt 2026) │
│  • 2026-03-15: Offerte VADQ-2026-00123 aangemaakt               │
├─────────────────────────────────────────────────────────────────┤
│  🏷️ TAGS: VAD Lid, Actief 2026                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Phase 8: Automations & Cron (Week 22)

### 8.1 Event Layer

**System Events:**
- [ ] `stride/edition/date_approaching` (3 days before)
- [ ] `stride/edition/date_today`
- [ ] `stride/edition/cancelled`
- [ ] `stride/edition/postponed`
- [ ] `stride/edition/completed`
- [ ] `stride/trajectory/user_completed`
- [ ] `stride/quote/expires_soon` (7 days before)
- [ ] `stride/voucher/expires_soon` (30 days before)
- [ ] `stride/attendance/marked`

### 8.2 FluentCRM Integration

**Automation Triggers:**
- [ ] Register custom triggers in FluentCRM
- [ ] Edition enrollment trigger
- [ ] Edition completion trigger
- [ ] Trajectory completion trigger

**Tag Management:**
- [ ] Auto-tag on trajectory completion
- [ ] Auto-tag on certification

### 8.3 Scheduled Tasks (Cron)

- [ ] Daily: lock quotes 14 days before edition start
- [ ] Daily: check edition dates approaching
- [ ] Daily: expire vouchers past valid_until
- [ ] Weekly: reminder for expiring quotes
- [ ] Weekly: trajectory progress reports

---

## Phase 9: Migration & Launch (Week 23-25)

### 9.1 User Migration

**Migrate:**
- [ ] `wp_users` table (preserve IDs + passwords)
- [ ] `wp_usermeta` (essential meta)
- [ ] FluentCRM contacts (or re-sync from users)
- [ ] User-company links

**Verify:**
- [ ] User can login
- [ ] FluentCRM contact exists
- [ ] Company links intact

### 9.2 Content Setup

- [ ] Create LD courses (content only — lessons, quizzes)
- [ ] Create editions for each scheduled course offering
- [ ] Create sessions within each edition
- [ ] Create trajectories with course groups
- [ ] Configure enrollment forms (edition_id in hidden field)

### 9.3 Historical Data Bridge Testing

- [ ] Test old enrollment queries via HistoricalDataService
- [ ] Test old invoice queries
- [ ] Test old certificate access
- [ ] Performance testing with real data

### 9.4 Go-Live Checklist

- [ ] DNS switch planning
- [ ] SSL certificate
- [ ] Email configuration (FluentCRM SMTP)
- [ ] Backup strategy
- [ ] Rollback plan
- [ ] User communication

### 9.5 Old System Archive

- [ ] Keep old system accessible (read-only)
- [ ] Document access URLs for historical lookups
- [ ] Set up monitoring for database queries

---

## Feature Dependency Map

```
Phase 0: Foundation ✅
    └── Phase 1: Core Services ✅
            └── Phase 1.5: Edition/Session Layer 🆕 CRITICAL PATH
                    ├── Phase 2: Enrollment (needs EditionService, RegistrationRepository)
                    │       └── Phase 4: Vouchers (needs Enrollment)
                    ├── Phase 3: Quotes (needs EditionService for price)
                    ├── Phase 5: Attendance & Completion (needs SessionService)
                    ├── Phase 6: User Frontend (needs all services)
                    └── Phase 7: Admin (needs all services)
                            └── Phase 8: Automations (needs Admin)
                                    └── Phase 9: Migration
```

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| CourseService split breaks existing code | Keep old methods as deprecated wrappers during transition |
| User can't login | Test migration script on subset first |
| Historical data slow | HistoricalDataService has 15-min caching ✅ |
| FluentCRM sync issues | UserDataSync multi-backend approach handles this ✅ |
| DataManager CPT performance at scale | Monitor query count; custom tables are fallback at >50k records |
| Missing features discovered late | Feature inventory reviewed with admin users |
| Old DB unavailable | Backup and document access |

---

## Success Criteria

### For Users
- [ ] Can find their editions (with dates) easily
- [ ] Can see trajectory progress as journey
- [ ] Can access historical data
- [ ] Enrollment flow works smoothly

### For Admins
- [ ] Single view for user data (current + historical)
- [ ] Edition dashboard with registrations, attendance, quotes in tabs
- [ ] Attendance tracking with checkboxes (not forms)
- [ ] Edition reusable from same LD course (no content duplication)
- [ ] Exports work (CSV, PDF)

### For Code
- [ ] Clean architecture with service layer
- [ ] 6 CPTs (edition, session, voucher, quote, trajectory, enrollment)
- [ ] 1 custom table (registrations)
- [ ] LearnDash as content engine only (4 integration points)
- [ ] < 8,000 lines custom code
- [ ] All services behind interfaces for testability

---

## References

- **Stride Project:** `/home/ntdst/Sites/stride/`
- **Architecture Proposal:** `docs/ARCHITECTURE-V4-PROPOSAL.md`
- **V3 Analysis:** `docs/ARCHITECTURE-V3-ANALYSIS.md`
- **V3 Codebase (reference):** `/home/ntdst/Sites/vad-vormingen/`
- **NTDST Core (Rossi reference):** `/home/ntdst/Sites/rossi/`
