# VAD Vormingen v4 - Deep Project Plan

**Date:** 2026-02-11
**Approach:** Fresh WordPress install, migrate users, query old DB for history

---

## Executive Summary

Fresh WordPress installation with:
- LearnDash (courses)
- FluentCRM + FluentForms (CRM, forms)
- NTDST Core from Rossi (architecture)
- Custom services for everything else

**User data migrated.** Historical data (enrollments, invoices, certificates) queried from old database.

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

## Phase 0: Foundation (Week 1-2)

### 0.1 Fresh WordPress Setup
- [ ] New WordPress installation on staging
- [ ] Configure wp-config.php with old DB connection for historical queries
- [ ] Install LearnDash
- [ ] Install FluentCRM
- [ ] Install FluentForms
- [ ] Basic security hardening

### 0.2 NTDST Core Setup
- [ ] Port NTDST Core from Rossi (`/home/ntdst/Sites/rossi/app/content/mu-plugins/ntdst-core/`)
- [ ] Set up service auto-discovery
- [ ] Configure DI container
- [ ] Set up routing system
- [ ] Test basic service loading

### 0.3 Theme Structure
```
themes/stride/
├── functions.php
├── theme-config.php
├── services/
│   ├── core/
│   ├── enrollment/
│   ├── invoicing/
│   ├── voucher/
│   ├── admin/
│   └── integrations/
└── templates/
    ├── dashboard/
    ├── course/
    ├── invoice/
    ├── admin/
    └── emails/
```

### 0.4 Historical Data Bridge
- [ ] Create `HistoricalDataService` for querying old database
- [ ] Methods for: old enrollments, old invoices, old certificates, old vouchers
- [ ] Read-only queries with caching

---

## Phase 1: Core Services (Week 3-4)

### 1.1 CourseService (LearnDash Wrapper)

Must support all current functionality:

**Course Type Detection:**
- [ ] `isInPerson($courseId)` - has 'In-person' category
- [ ] `isOnline($courseId)` - has 'Online' category
- [ ] `isTraject($courseId)` - has 'Traject' category

**Course Dates:**
- [ ] `getCourseDates($courseId)` - array of timestamps from `course_days`
- [ ] `getStartDate($courseId)` - first date
- [ ] `getNextDate($courseId)` - next upcoming date
- [ ] `hasStarted($courseId)`
- [ ] `hasEnded($courseId)`

**Course Status:**
- [ ] `isCancelled($courseId)` - `course_status_cancelled`
- [ ] `isPostponed($courseId)` - `course_status_postponed`
- [ ] `isFull($courseId)` - `course_status_full`
- [ ] `isAnnouncement($courseId)` - `course_status_announcement`
- [ ] `isUpcoming($courseId)`

**Capacity:**
- [ ] `getCapacity($courseId)` - from `course_max_participants`
- [ ] `getEnrolledCount($courseId)`
- [ ] `hasAvailableSpots($courseId)`
- [ ] `getAvailableSpots($courseId)`

**Speakers/Supervisors:**
- [ ] `getCourseSpeakers($courseId)` - parse `course_supervisors` field
- [ ] Format: "Name, role; Name2, role2"

**User Relations:**
- [ ] `isUserEnrolled($userId, $courseId)`
- [ ] `getEnrolledUsers($courseId)`
- [ ] `enrollUser($userId, $courseId)`
- [ ] `unenrollUser($userId, $courseId)`

**Modules (Trajectories):**
- [ ] `getCourseModules($courseId)` - from `course_modules_select`
- [ ] `isModuleCourse($courseId)`

**Pricing:**
- [ ] `getCoursePrice($courseId)`
- [ ] `getCoursePriceNonMember($courseId)`
- [ ] `getInvoiceItem($courseId)`

**Settings:**
- [ ] `getCourseSetting($courseId, $key)`
- [ ] `setCourseSetting($courseId, $key, $value)`
- [ ] `isInvoiceEnabled($courseId)`
- [ ] `isCertificateEnabled($courseId)`
- [ ] `getCustomForm($courseId)`

### 1.2 SubscriberService (FluentCRM Wrapper)

**Core Operations:**
- [ ] `getSubscriber($userId)`
- [ ] `findOrCreate($userId)` - create contact if not exists
- [ ] `getSubscriberByEmail($email)`

**Field Operations:**
- [ ] `getField($userId, $fieldName)`
- [ ] `updateField($userId, $fieldName, $value)`
- [ ] `updateProfile($userId, $data)` - bulk update

**Address/Invoice Data:**
- [ ] `getInvoiceAddress($userId)` - merged personal + org data
- [ ] `getBillingData($userId)`

**Notes:**
- [ ] `createNote($userId, $content, $type = null)`
- [ ] `getNotes($userId, $limit = 10)`

**Tags:**
- [ ] `addTag($userId, $tagId)`
- [ ] `removeTag($userId, $tagId)`
- [ ] `getTags($userId)`
- [ ] `hasTag($userId, $tagId)`

**Company/Organization:**
- [ ] `getCompany($userId)`
- [ ] `linkToCompany($userId, $companyId)`
- [ ] `unlinkFromCompany($userId)`
- [ ] `syncCompanyData($userId)` - sync billing from company
- [ ] `getCompanyByExportId($exportId)` - Winbooks lookup

**Member Status:**
- [ ] `isMember($userId)`
- [ ] `getMemberType($userId)` - sport, unif, etc.

**Batch Operations (Performance):**
- [ ] `getSubscribersBatch($userIds)`
- [ ] `getMembersBatch($userIds)`

### 1.3 OrganizationService

**Company Management:**
- [ ] `createCompany($data)` - create FluentCRM company
- [ ] `updateCompany($companyId, $data)`
- [ ] `getCompanyUsers($companyId)`
- [ ] `linkUserToCompany($userId, $companyId)`

**Company Custom Fields:**
```
- export_id (Winbooks ID)
- naam_organisatie_fac (invoice name)
- adres_organisatie_fac (address)
- stad_organisatie_fac (city)
- postcode_organisatie_fac (postal)
- btw_organisatie_fac (VAT)
- gln_nummer (GLN/Peppol)
- email_organisatie_fac (invoice email)
- afdeling_organisatie (department)
```

---

## Phase 2: Enrollment System (Week 5-7)

### 2.1 Enrollment Paths

**Path 1: Individual Course Enrollment**
- [ ] Form detection by course
- [ ] User lookup/creation
- [ ] Organization parsing (existing vs new)
- [ ] Profile sync
- [ ] LearnDash enrollment
- [ ] Quote creation
- [ ] Voucher application
- [ ] FluentCRM notes
- [ ] Capacity update

**Path 2: Colleague/Group Enrollment**
- [ ] Repeater field parsing: `[first_name, last_name, email]`
- [ ] Duplicate email validation
- [ ] Per-colleague enrollment loop
- [ ] Manager relationship tracking
- [ ] Per-colleague invoicing
- [ ] Manager gets CRM note for each enrollee

**Path 3: Trajectory Enrollment**
- [ ] Group-based enrollment
- [ ] Auto-enroll in mandatory courses
- [ ] Optional course selection UI
- [ ] Group membership creation
- [ ] Trajectory-level invoicing

**Path 4: Interest/Waitlist Form**
- [ ] Form submission only (no enrollment)
- [ ] Store for admin review
- [ ] Course association

### 2.2 Enrollment Form Fields

**User Identity:**
- [ ] email (required, user lookup)
- [ ] first_name, last_name

**Profile Fields:**
- [ ] profile_type (sport/unif)
- [ ] function_user / function_colleague
- [ ] phone_user / phone_colleague
- [ ] rijksregister (Belgian ID)

**Organization Fields:**
- [ ] organisations (smart field: numeric = existing, text = new)
- [ ] department
- [ ] Organisation AJAX loader (fetch company details)

**Invoice Fields (for new orgs):**
- [ ] invoice_organisation
- [ ] facturatie[address_line_1]
- [ ] facturatie[address_line_2]
- [ ] facturatie[city]
- [ ] facturatie[zip]
- [ ] invoice_vat
- [ ] invoice_gln
- [ ] invoice_email

**Discount Fields:**
- [ ] voucher (discount code)
- [ ] ordernumber (reference)

### 2.3 Enrollment Flow & Handlers

**Pre-Enrollment (Filter: `vad:before_enroll`):**
- [ ] VoucherHandler - validate voucher code
- [ ] CapacityHandler - check spots available
- [ ] PrerequisiteHandler - check requirements
- [ ] MemberTypeHandler - check access restrictions

**Pre-Enrollment (Action: `vad:pre_enroll`):**
- [ ] ProfileSyncHandler - update user profile
- [ ] OrganizationSyncHandler - link/create company

**Enrollment:**
- [ ] LearnDash `ld_update_course_access()`
- [ ] Update capacity status if full

**Post-Enrollment (Action: `vad:after_enroll`):**
- [ ] QuoteHandler - create quote
- [ ] VoucherApplicationHandler - apply discount
- [ ] NotesHandler - CRM audit trail
- [ ] NotificationHandler - emails

### 2.4 Manager/Colleague Tracking

**ManagedEnrollmentHelper:**
- [ ] `storeRelationship($managerId, $enrolledUserId, $courseId)`
- [ ] `getEnrolledByManager($managerId, $courseId)`
- [ ] `getEnrollingManager($userId, $courseId)`
- [ ] `isManaged($userId, $courseId)`
- [ ] `removeRelationship($enrolledUserId, $courseId)`

**Meta Storage:**
- Enrolled user: `vad_enrolled_by_{course_id}` → manager ID
- Manager: `vad_managed_enrollments` → `[course_id => [user_ids]]`

### 2.5 Enrollment Validation

**canUserEnroll() checks:**
- [ ] Not already enrolled
- [ ] Course not cancelled
- [ ] Course not ended
- [ ] Capacity available
- [ ] Prerequisites met
- [ ] Member type allowed
- [ ] Custom filter passes

**Error Messages (Dutch):**
```
- "U bent al ingeschreven voor deze cursus."
- "Deze cursus is geannuleerd."
- "Deze cursus is reeds afgelopen."
- "Deze cursus is volzet."
- "U voldoet niet aan de voorwaarden."
- "U heeft geen toegang tot deze vorming."
```

---

## Phase 3: Quote/Invoice System (Week 8-9)

### 3.1 Quote CPT

**Post Type:** `vad_quote`

**Fields:**
```
- number: VADQ-2025-00123
- user_id
- status: draft | sent | exported | paid | cancelled
- items: [{course_id, title, price, quantity}]
- subtotal
- discount: {code, amount, type}
- tax (BTW 21%)
- total
- valid_until
- payment_reference: +++650/0012/34597+++ (Belgian OGM)
- exported_date
- exact_reference (optional)
- company_id (FluentCRM company link)
- gln_number
- order_number
- notes
```

### 3.2 Quote Service

**Creation:**
- [ ] `createQuote($userId, $items, $meta = [])`
- [ ] Auto-generate number (VADQ-YYYY-NNNNN)
- [ ] Calculate totals with tax
- [ ] Generate OGM payment reference
- [ ] Set valid_until (+30 days)
- [ ] CRM note: "Offerte {number} aangemaakt"

**Status Transitions:**
- [ ] `sendQuote($quoteId)` - status: sent, email to user
- [ ] `exportQuote($quoteId)` - status: exported, mark date
- [ ] `markPaid($quoteId)` - status: paid
- [ ] `cancelQuote($quoteId)` - status: cancelled

**PDF Generation:**
- [ ] Quote PDF template
- [ ] DOMPDF rendering
- [ ] Belgian OGM display
- [ ] Company address handling

### 3.3 Belgian OGM Generator

**Format:** `+++NNN/NNNN/NNNCC+++`

- [ ] `generate($baseNumber)` - create OGM from invoice number
- [ ] `validate($ogm)` - verify checksum
- [ ] Mod 97 checksum calculation

### 3.4 Exact Online Export

**Export Format (CSV):**
```
bookyear, bookmonth, number, date, duedate, amount,
contact, accountid, extnote, intnote, structcom, cart_details
```

- [ ] Weekly export functionality
- [ ] Batch export for selected quotes
- [ ] Mark as exported after download

---

## Phase 4: Voucher System (Week 10-11)

### 4.1 Voucher CPT

**Post Type:** `vad_voucher`

**Fields:**
```
- code: 25A123-abcde
- type: action | member | speaker | day
- owner_id (user or company)
- owner_type: user | organization
- discount_amount
- discount_type: percent | flat
- expiration_date
- max_uses
- is_single_use
- status: active | used | expired | cancelled
- used_date
- used_by_user_id
- used_on_quote_id
- created_via: admin | flow | api
- trigger_context
```

### 4.2 Voucher Service

**Creation:**
- [ ] `createVoucher($data)` - full creation flow
- [ ] `generateCode($type, $recipientId)` - format: `{YY}{PREFIX}{ID}-{RANDOM}`
- [ ] Prefixes: A=action, M=member, S=speaker, D=day
- [ ] Collision detection (10 retries)

**Validation:**
- [ ] `validateVoucher($code)` - returns true or error message
- [ ] Check: exists, not used (if single-use), not expired, not exceeded limit

**Application:**
- [ ] `applyToQuote($voucherId, $quoteId)`
- [ ] `trackUsage($voucherId, $quoteId, $userId)`
- [ ] CRM note: "Voucher gebruikt: {code}"

**Error Messages (Dutch):**
```
- "De kortingscode '{code}' bestaat niet of is ongeldig."
- "De kortingscode '{code}' is reeds gebruikt."
- "De kortingscode '{code}' is verlopen."
- "De kortingscode '{code}' heeft het maximaal aantal gebruiken bereikt."
```

### 4.3 Auto Day Voucher Logic

**Trigger:** User enrolls in multi-day course WITH voucher

**Process:**
1. Detect course has 2+ days
2. Calculate prorated discount: `100 / dayCount` per day
3. Create new day voucher with +1 day expiration
4. Deactivate original voucher (status: cancelled)
5. Link metadata: original ↔ day voucher
6. Return new code for quote

**Metadata:**
- New voucher: `original_voucher_code`, `converted_date`, `converted_for_course`
- Original: `converted_to_day_voucher`, `converted_date`

### 4.4 Voucher Admin

- [ ] Voucher overview table
- [ ] Create voucher form (recipient, type, amount, expiration)
- [ ] Bulk actions: activate, deactivate, resend email
- [ ] Expiry reminder emails
- [ ] Usage statistics

---

## Phase 5: User Frontend (Week 12-14)

### 5.1 User Dashboard (`/mijn-account/`)

**Dashboard Home:**
- [ ] Welcome message
- [ ] Upcoming courses (next 3)
- [ ] Recent activity
- [ ] Quick links

**My Courses (`/mijn-account/cursussen/`):**
- [ ] Course grid with cards
- [ ] Status badges (enrolled, in progress, completed, etc.)
- [ ] Progress percentage
- [ ] Course dates for in-person
- [ ] Continue button (online)
- [ ] Certificate download (completed)
- [ ] Filter: all / in-person / online / completed

**My Trajectories (`/mijn-account/trajecten/`):**
- [ ] Trajectory cards with progress
- [ ] Journey visualization (not grid!)
- [ ] Next action prominence
- [ ] Mandatory vs optional courses
- [ ] Completion percentage at trajectory level

**My Quotes (`/mijn-account/offertes/`):**
- [ ] Quote list with status
- [ ] View quote details
- [ ] Download PDF
- [ ] Payment reference display

**My Profile (`/mijn-account/profiel/`):**
- [ ] Edit personal info
- [ ] Edit contact info
- [ ] View organization link
- [ ] Change password link

### 5.2 Trajectory Journey View

**Replace grid with journey visualization:**

```
┌─────────────────────────────────────────────────────────────┐
│  Traject: CGG Preventiewerker TAD                           │
│  ────────────────────────────────────────────────────────── │
│                                                             │
│  Your Progress: ████████░░░░░░░░ 45%                       │
│                                                             │
│  ┌─────┐    ┌─────┐    ┌─────┐    ┌─────┐                  │
│  │  ✓  │───▶│  ✓  │───▶│ NOW │───▶│  ○  │                  │
│  │Mod 1│    │Mod 2│    │Mod 3│    │Mod 4│                  │
│  └─────┘    └─────┘    └─────┘    └─────┘                  │
│                                                             │
│  Next Up: Neurobiologie - March 14 @ VAD                   │
│  [Add to Calendar]                                          │
│                                                             │
│  ┌── Choose 2 of 3 Electives ──────────────────────────┐   │
│  │ [ ] EUPC Training                                   │   │
│  │ [✓] Motiverende Gespreksvoering     COMPLETED       │   │
│  │ [ ] Coachen van organisaties                        │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  Upon completion: Certificate + Tag applied                │
└─────────────────────────────────────────────────────────────┘
```

**Features:**
- [ ] Visual path representation
- [ ] Current position indicator
- [ ] Next action prominently displayed
- [ ] Elective choice interface
- [ ] Completion criteria visible
- [ ] Calendar integration

### 5.3 Course Listing & Filtering

**Course Catalog Page:**
- [ ] Filter by category/taxonomy
- [ ] Filter by date range
- [ ] Filter by type (in-person / online)
- [ ] Search by title
- [ ] Pagination
- [ ] Course cards with key info

**Course Detail Page:**
- [ ] Course header (title, excerpt, category)
- [ ] Course content tabs
- [ ] Materials/documents tab
- [ ] Lessons & quizzes listing (online)
- [ ] Sidebar widget with key info
- [ ] Enrollment button with status logic
- [ ] FAQ accordion

### 5.4 Course Sidebar Actions

**Status-aware buttons:**
```
Not logged in     → "Meld je aan om in te schrijven"
Not enrolled      → "Schrijf je in" (if available)
Enrolled          → "Ga verder" (online) or status message
Completed         → "Download Certificaat"
Course cancelled  → "Deze cursus is geannuleerd"
Course full       → "Deze cursus is volzet"
Course ended      → "Deze cursus is afgelopen"
Announcement      → "Binnenkort beschikbaar"
```

### 5.5 Calendar Features

- [ ] Agenda view with upcoming sessions
- [ ] Add to calendar (iCal download)
- [ ] Days until countdown
- [ ] Past session styling
- [ ] Filter: all courses / my courses

---

## Phase 6: Admin Dashboard (Week 15-18)

### 6.1 Course Dashboard

**In-Person Dashboard:**
- [ ] Course list sorted by date
- [ ] Filters: date range, admin, tags
- [ ] Search by name
- [ ] Pagination
- [ ] Quick stats per course

**Course Detail View:**
- [ ] Tab: Cursisten (enrolled students)
- [ ] Tab: Facturen (invoices/quotes)
- [ ] Tab: Inzendingen (form submissions)
- [ ] Tab: Interesse (interest forms)

### 6.2 Students Table (Cursisten)

**Columns:**
- [ ] Name (with FluentCRM link)
- [ ] Function
- [ ] Organization (merged company + personal)
- [ ] Department
- [ ] VAD Member badge
- [ ] Enrollment type (direct, group mandatory, group optional)
- [ ] Quote/Invoice number
- [ ] Attendance checkboxes per day

**Actions:**
- [ ] View as user
- [ ] Unenroll
- [ ] View form submission
- [ ] Send certificate

**Bulk Actions:**
- [ ] Mark present (all days)
- [ ] Mark absent (all days)
- [ ] Send certificates
- [ ] Unenroll selected

### 6.3 Attendance Tracking

- [ ] Per-day attendance checkboxes
- [ ] AJAX toggle updates
- [ ] Bulk set day as present/absent
- [ ] Store: `course_{id}_attended_day_{index}` → timestamp or 0

### 6.4 Quote/Invoice Table

**Columns:**
- [ ] Number (color-coded: quote vs invoice)
- [ ] Customer email
- [ ] Date
- [ ] Due date
- [ ] Amount
- [ ] Status

**Actions:**
- [ ] Send to user
- [ ] Export to Exact
- [ ] Mark as paid
- [ ] Cancel

### 6.5 Course Status Toggles

- [ ] Announcement mode
- [ ] Full/at capacity
- [ ] Cancelled
- [ ] Postponed
- [ ] Completed (enable certificates)

**On status change:**
- [ ] Fire appropriate hooks
- [ ] Trigger FluentCRM automations
- [ ] Notify enrolled users

### 6.6 Course Materials Panel

- [ ] Enable/disable materials
- [ ] File upload (dropzone)
- [ ] Rich text editor for content
- [ ] Shortcode support

### 6.7 Email Panel

- [ ] Select recipients (all or specific)
- [ ] Template dropdown
- [ ] Subject and body editors
- [ ] Merge tags: {first_name}, {course_title}, etc.
- [ ] Send via AJAX

### 6.8 Export Functionality

**Export Types:**
- [ ] Students export (CSV)
- [ ] Invoices export (CSV)
- [ ] Submissions export (CSV)
- [ ] Interest forms export (CSV)

**PDF Downloads:**
- [ ] Attendance list
- [ ] Name cards

### 6.9 Unified User Profile View

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
│  📚 COURSES (from new system)                                   │
│  • Vorming Drugs Basis     │ In-Person │ ✅ Completed │ 03/24   │
│  • Online Intro            │ Online    │ 75%          │         │
│                                                                 │
│  📚 HISTORICAL COURSES (from old system)                        │
│  • Previous Course 2023    │ In-Person │ Completed    │ 2023    │
├─────────────────────────────────────────────────────────────────┤
│  💰 QUOTES                                                      │
│  • VADQ-0123 │ Vorming Drugs │ €250 │ Exported                 │
│                                                                 │
│  💰 HISTORICAL INVOICES (from old system)                       │
│  • VADV-2023-001 │ Previous │ €200 │ Paid                      │
├─────────────────────────────────────────────────────────────────┤
│  📝 RECENT NOTES                                                │
│  • 2024-03-15: Ingeschreven voor Vorming Drugs Basis            │
│  • 2024-03-15: Offerte VADQ-0123 aangemaakt                     │
├─────────────────────────────────────────────────────────────────┤
│  🏷️ TAGS: VAD Lid, Actief 2024                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

## Phase 7: Event-Driven Automations (Week 19)

### 7.1 Event Layer

**System Events (not user-triggered):**
- [ ] `vad:course_date_approaching` (3 days before)
- [ ] `vad:course_date_today`
- [ ] `vad:course_cancelled`
- [ ] `vad:course_postponed`
- [ ] `vad:user_completed_trajectory`
- [ ] `vad:quote_expires_soon` (7 days before)
- [ ] `vad:voucher_expires_soon` (30 days before)
- [ ] `vad:attendance_marked`

### 7.2 FluentCRM Integration

**Automation Triggers:**
- [ ] Register custom triggers in FluentCRM
- [ ] Course enrollment trigger
- [ ] Course completion trigger
- [ ] Trajectory completion trigger
- [ ] Invoice paid trigger

**Tag Management:**
- [ ] Auto-tag on trajectory completion
- [ ] Auto-tag on certification

### 7.3 Scheduled Tasks (Cron)

- [ ] Daily: check course dates approaching
- [ ] Daily: check expiring vouchers
- [ ] Weekly: reminder for expiring quotes
- [ ] Daily: update next course date calculations

---

## Phase 8: Migration & Launch (Week 20-22)

### 8.1 User Migration

**Migrate:**
- [ ] `wp_users` table
- [ ] `wp_usermeta` (passwords, essential meta)
- [ ] FluentCRM contacts (or re-sync from users)
- [ ] User-company links

**Verify:**
- [ ] User can login
- [ ] FluentCRM contact exists
- [ ] Company links intact

### 8.2 Historical Data Bridge Testing

- [ ] Test old enrollment queries
- [ ] Test old invoice queries
- [ ] Test old certificate access
- [ ] Performance testing with real data

### 8.3 Course Setup

- [ ] Create course structure in new LearnDash
- [ ] Configure course meta fields
- [ ] Set up trajectories/groups
- [ ] Configure enrollment forms

### 8.4 Go-Live Checklist

- [ ] DNS switch planning
- [ ] SSL certificate
- [ ] Email configuration
- [ ] Backup strategy
- [ ] Rollback plan
- [ ] User communication

### 8.5 Old System Archive

- [ ] Keep old system accessible (read-only)
- [ ] Document access URLs for historical lookups
- [ ] Set up monitoring for database queries

---

## Feature Dependency Map

```
Phase 0: Foundation
    └── Phase 1: Core Services
            ├── Phase 2: Enrollment (needs CourseService, SubscriberService)
            │       └── Phase 4: Vouchers (needs Enrollment)
            ├── Phase 3: Quotes (needs SubscriberService)
            ├── Phase 5: User Frontend (needs all services)
            └── Phase 6: Admin (needs all services)
                    └── Phase 7: Automations (needs Admin)
                            └── Phase 8: Migration
```

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| User can't login | Test migration script on subset first |
| Historical data slow | Add caching layer, indexes |
| FluentCRM sync issues | Verify contact creation early |
| Missing features discovered late | Feature inventory reviewed with admin users |
| Old DB unavailable | Backup and document access |

---

## Success Criteria

### For Users
- [ ] Can find their courses easily
- [ ] Can see trajectory progress as journey
- [ ] Can access historical data
- [ ] Enrollment flow works smoothly

### For Admins
- [ ] Single view for user data
- [ ] Fewer tools to remember
- [ ] Attendance tracking works
- [ ] Exports work

### For Code
- [ ] Clean architecture
- [ ] < 5,000 lines of custom code
- [ ] All tests pass
- [ ] No BuddyBoss, no GetPaid

---

## References

- **Stride Project:** `/home/ntdst/Sites/stride/`
- **Architecture Proposal:** `docs/ARCHITECTURE-V4-PROPOSAL.md`
- **V3 Analysis:** `docs/ARCHITECTURE-V3-ANALYSIS.md`
- **V3 Codebase (reference):** `/home/ntdst/Sites/vad-vormingen/`
- **NTDST Core (Rossi reference):** `/home/ntdst/Sites/rossi/`
