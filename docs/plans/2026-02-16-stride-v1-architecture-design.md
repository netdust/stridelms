# Stride LMS V1 Architecture Design

**Date:** 2026-02-16
**Status:** Approved
**Approach:** Fresh start on ntdst-core, vertical slices, mobile-first PWA

---

## Executive Summary

Rebuild Stride LMS from scratch using ntdst-core as foundation. Previous attempt created god classes (1000+ line services). This design enforces clean architecture through:

- **Module boundaries** with interfaces
- **Shared infrastructure** (no redundant code)
- **Vertical slices** (working features early)
- **Mobile-first PWA** for student dashboard

---

## Council Review (2026-02-16)

Architecture reviewed by 4-agent council (Architect, Designer, Engineer, Researcher).

### Consensus

- Proceed with ntdst-core
- Phase 0 documentation sprint before coding
- Mobile wireframes before frontend work
- ~200 line soft limit per class (300+ needs justification)
- Direct service calls first, events only when coupling hurts
- LearnDash minimal integration (4 touch points)

### Non-Negotiable Gates

1. Documentation sprint (2 days)
2. Mobile wireframes approved
3. V3 load profile documented
4. Monthly debt review

---

## Module Structure

```
stride-core/                          # mu-plugin (business logic)
├── Contracts/                        # Interfaces FIRST
│   ├── RepositoryInterface.php
│   ├── EditionQueryInterface.php
│   ├── LMSAdapterInterface.php
│   └── ...
│
├── Domain/                           # Value Objects + DTOs (shared)
│   ├── Money.php                     # Immutable price handling
│   ├── DateRange.php                 # Start/end date pair
│   ├── EditionStatus.php             # open|full|cancelled|...
│   ├── RegistrationStatus.php        # confirmed|cancelled|waitlist
│   └── QuoteStatus.php               # draft|sent|exported
│
├── Infrastructure/                   # Shared base classes
│   ├── AbstractRepository.php        # Common CRUD via ntdst_data()
│   └── AbstractService.php           # Common service patterns
│
├── Modules/                          # Bounded contexts
│   ├── Edition/
│   │   ├── EditionRepository.php
│   │   ├── EditionService.php
│   │   ├── SessionService.php
│   │   └── SessionSelectionService.php
│   │
│   ├── Enrollment/
│   │   ├── RegistrationRepository.php
│   │   └── EnrollmentService.php
│   │
│   ├── Invoicing/
│   │   ├── QuoteRepository.php
│   │   ├── QuoteService.php
│   │   ├── QuoteCalculator.php
│   │   └── VoucherService.php
│   │
│   ├── Trajectory/
│   │   ├── TrajectoryRepository.php
│   │   ├── TrajectoryService.php
│   │   ├── TrajectoryEnrollmentRepository.php
│   │   └── ProgressEngine.php
│   │
│   └── User/
│       ├── UserDataSync.php
│       ├── SubscriberService.php
│       └── OrganizationService.php
│
├── Handlers/                         # Cross-module wiring
│   ├── EnrollmentQuoteHandler.php
│   ├── CapacityHandler.php
│   └── ...
│
├── Adapters/
│   └── LearnDashAdapter.php          # 4 methods only
│
└── plugin-config.php
```

---

## Data Model

### Enrollment Paths

| Path | Flow | Registration Target |
|------|------|---------------------|
| In-person course | Course → Edition → Registration | edition_id |
| Online course | Course → Direct enrollment | course_id (no edition) |
| Trajectory self-paced | Trajectory → User picks courses/editions | Per-course registrations |
| Trajectory cohort | Trajectory → Pre-linked editions | Auto-enroll all editions |

### CPTs (via DataManager)

| CPT | Purpose |
|-----|---------|
| `vad_edition` | Scheduled in-person offering |
| `vad_session` | Meeting day within edition |
| `vad_trajectory` | Multi-course program |
| `vad_voucher` | Discount codes |
| `vad_quote` | Invoices |

### Custom Tables

| Table | Why |
|-------|-----|
| `wp_vad_registrations` | High volume, fast queries |
| `wp_vad_attendance` | Many rows per session, audit trail |
| `wp_vad_trajectory_enrollments` | Elective choices, deadlines |
| `wp_vad_session_registrations` | Session selection tracking |

### Session Types

| Type | Scheduled | Completion |
|------|-----------|------------|
| `in_person` | date + time | admin checkbox |
| `webinar` | date + time | admin checkbox |
| `online` | deadline | LD auto-tracks |
| `assignment` | deadline | LD auto-tracks |

Sessions can also be **optional** (user opts in).

### Choice Windows

Both trajectories and editions use the same pattern:

```
Enroll → Choice Window Opens → [User makes choices] → Deadline → Locked
```

**Trajectory (cohort mode):**
```php
_vad_mode = 'cohort'
_vad_choice_available_date = '2026-02-01'
_vad_choice_deadline = '2026-02-15'
_vad_enrollment_deadline = '2026-01-31'
_vad_courses = [
    ['course_id' => 123, 'group' => 'Basis', 'required' => true],
    ['course_id' => 456, 'group' => 'Keuze', 'required' => false, 'pick_count' => 2]
]
```

**Edition (session selection):**
```php
_vad_selection_deadline = '2026-03-01'
_vad_session_slots = [
    ['slot' => 'dag1_vm', 'label' => 'Dag 1 Voormiddag', 'pick_count' => 1, 'required' => true]
]
```

### Relationships

```
Trajectory (cohort)
├── enrollment_deadline
├── choice_available / choice_deadline
├── courses[] with pick_count
└── TrajectoryEnrollment
        └── elective_choices[] → locked after deadline

Course (LearnDash)
├── type: online → direct enrollment
└── type: in-person/hybrid → Edition
                               ├── session_slots[] with pick_count
                               ├── selection_deadline
                               └── Registration
                                      └── SessionRegistrations[]

Session
├── belongs to slot
├── type: in_person | webinar | online | assignment
└── optional flag
```

---

## Service Architecture

### Size Targets

| Metric | Soft Limit | Hard Concern |
|--------|------------|--------------|
| Class lines | ~200 | 300+ investigate |
| Method lines | ~25 | 40+ split it |
| Constructor params | 5 | 7+ class does too much |
| Public methods | ~8 | 12+ probably god class |

### Cross-Module Communication

Modules communicate via **interfaces**, not concrete classes:

```php
class EnrollmentService
{
    public function __construct(
        private EditionQueryInterface $editions,  // Interface
        private RegistrationRepository $registrations,
    ) {}
}
```

### Handlers for Cross-Cutting Concerns

```php
add_action('stride/enrollment/completed', [EnrollmentQuoteHandler::class, 'handle']);
add_action('stride/registration/created', [CapacityHandler::class, 'handle']);
```

### LearnDash Integration (4 Points Only)

```php
interface LMSAdapterInterface
{
    public function grantAccess(int $userId, int $courseId): bool;
    public function revokeAccess(int $userId, int $courseId): bool;
    public function isComplete(int $userId, int $courseId): bool;
    public function getCertificateLink(int $userId, int $courseId): ?string;
}
```

---

## Mobile-First PWA

### Scope

| Feature | Priority |
|---------|----------|
| Installable | Must have |
| Mobile-first responsive | Must have |
| Touch targets 44px+ | Must have |
| Fast loading (SW for assets) | Must have |
| Push notifications | Nice to have (Phase 2+) |
| Offline content | Not needed |

### Student Dashboard Pages

```
/mijn-account/
├── /cursussen/      My enrolled courses/editions
├── /agenda/         Upcoming sessions calendar
├── /trajecten/      My trajectories + progress
├── /offertes/       My quotes
└── /profiel/        Profile edit
```

### Tech Stack

- UIkit 3 base
- Custom mobile components (minimal)
- PWA manifest for installability
- Service worker for static asset caching only

---

## Phase 0 Deliverables

### Checklist

| Deliverable | Time |
|-------------|------|
| **Documentation** | |
| DI container patterns | 0.5 day |
| Service lifecycle | 0.5 day |
| Debugging patterns | 0.5 day |
| ntdst-wp skill review | 0.5 day |
| **Wireframes** | |
| Dashboard home (mobile) | 0.5 day |
| Agenda view (mobile) | 0.5 day |
| Course/edition cards | 0.25 day |
| Session selection | 0.25 day |
| Trajectory progress | 0.25 day |
| **Architecture setup** | |
| Module structure | 0.5 day |
| Contracts/interfaces | 0.5 day |
| Domain value objects | 0.5 day |
| Base classes | 0.5 day |
| **Validation** | |
| V3 load profile | 0.25 day |
| Stress test plan | 0.25 day |

**Total: ~3-4 days**

### Exit Criteria

- [ ] Can explain DI container to new developer
- [ ] Mobile wireframes approved
- [ ] Contracts/ folder complete
- [ ] Domain/ folder complete
- [ ] Infrastructure/ folder complete
- [ ] V3 peak load documented

---

## Implementation: Vertical Slices

After Phase 0, build in slices:

1. Edition → Registration → Student dashboard
2. Quote creation → PDF → Download
3. Voucher → Apply to quote
4. Session selection with deadlines
5. Trajectory enrollment with electives
6. Admin dashboard
7. Attendance + completion

---

## References

- **Master Plan:** `docs/V4-PROJECT-PLAN master.md`
- **Phase 1.5 Spec:** `docs/phase-1_5-edition-session-layer.md`
- **ntdst-wp Skill:** Architecture patterns, anti-patterns
- **Existing Code:** Feature reference (not to be copied)

---

## Approved By

- Council Review: 2026-02-16
- User: 2026-02-16
