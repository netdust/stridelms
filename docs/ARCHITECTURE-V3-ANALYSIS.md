# VAD Vormingen v3 - Architecture Analysis

**Date:** 2026-02-11
**Purpose:** Deep review of current architecture to inform v4 fresh start

---

## Summary of Findings

| Area | Status | Complexity | Simplification Potential |
|------|--------|------------|-------------------------|
| Enrollment Flow | Well-architected | High | Medium - consolidate handlers |
| Invoicing (GetPaid) | 80% interface-abstracted | Medium | High - replaceable |
| Admin Experience | Over-engineered | High | High - 9 exporters, only 4-5 used |
| User Frontend (BuddyBoss) | ~60% necessary | Medium | High - replaceable |
| Core Framework | Has bloat | Medium | 30-40% removable |

---

## 1. Enrollment Flow Analysis

### Architecture Pattern
- **Three-Layer**: Flows → Services → Handlers
- **Interface-based**: LearnDash swappable via `CourseServiceInterface`, `EnrollmentServiceInterface`

### Current Flow
```
Form Submission (FluentForm)
    ↓
EnrollmentFormSubmissionHandler::handle_submission()
    ↓
EnrollmentOrchestrationService::enrollUser()
    ↓
CourseEnrollmentFlow::run()
    ├── Pre-enrollment hooks
    │   ├── EnrollmentVoucherHandler (priority 5)
    │   └── EnrollmentProfileSyncHandler
    ├── LearnDash enrollment
    ├── Capacity update
    └── Post-enrollment hooks
        ├── EnrollmentInvoiceHandler (priority 10)
        ├── EnrollmentNotesHandler (priority 20)
        └── FluentCRM automations
```

### Complexity Sources
1. **Multi-path form handling** - URL referer detection is fragile
2. **Organization data parsing** - 2 paths (existing vs new org)
3. **Validation happens twice** - Orchestration + Flow
4. **5+ handler registration points** - Scattered, hard to trace

### Simplification Opportunities
- Explicit form field routing (not URL referer)
- Consolidate 5 handlers → 1 per domain
- Single validation layer

---

## 2. Invoicing Flow Analysis (GetPaid)

### Current Architecture
- 80% of logic is interface-abstracted (`InvoiceServiceInterface`)
- GetPaid provides: Invoice CRUD, discounts, quote-to-invoice, PDF, emails
- Custom: Belgian OGM, multiday voucher prorating

### Quote → Invoice Flow
```
Enrollment → Quote (wpi-quote-pending)
                ↓
    Admin converts or user accepts
                ↓
         Invoice (wpi-onhold)
                ↓
    Admin marks paid or payment received
                ↓
          Invoice (publish)
```

### GetPaid Dependency Assessment
| Feature | GetPaid Component | Replaceability |
|---------|-------------------|----------------|
| Invoice CRUD | `WPInv_Invoice` | Easy - wrapped |
| Discounts | `WPInv_Discount` | Easy - wrapped |
| Quote conversion | `Wpinv_Quotes_Converter` | Medium |
| PDF | DOMPDF (included) | Easy - standalone |
| Payment gateways | GetPaid system | Medium |

### Conclusion
Replaceable in 4-6 weeks with custom CPT-based solution

---

## 3. Admin Experience Analysis

### Admin Pages
- In-Person Dashboard (`vormingen-admin`)
- Online Dashboard (`online-vormingen`)
- Security Settings (`vorming-security`)

### Over-Engineering Found
1. **9 exporters** - Only 4-5 regularly used
2. **Quote conversion** - Deprecated (Exact integration replaced it)
3. **Redundant table components** - Similar structure, could share base class
4. **Thin wrapper action classes** - Could be helper functions

### What Admins Actually Use
- Course dashboard tables (daily)
- Student/invoice lists (daily)
- Status toggles (daily)
- Bulk email/materials (moderate)
- Exports: Clients, Invoices, Submissions, OnlineCourses (weekly)

### Audit Trail (Works Well)
- FluentCRM notes for every enrollment
- Colleague enrollment tracking
- Voucher usage tracking
- Profile change auditing

---

## 4. Frontend Experience Analysis (BuddyBoss)

### BuddyBoss Usage
- **Used**: User profiles, groups as classrooms, member pages
- **Not Used**: Activity feeds, social features, messaging (mostly)

### Conditional Loading
- ReadyLaunch only hijacks `/lms/` URLs
- YOOtheme handles landing pages cleanly
- BuddyBoss is plugin-in module, not core

### Independence Assessment
Core enrollment works WITHOUT BuddyBoss:
- Course filtering ✓
- Course detail pages ✓
- Enrollment form submission ✓
- Invoice generation ✓
- Certificate generation ✓
- Email notifications ✓

### Replacement Estimate
2-3 weeks for custom user dashboard if:
- Simple dashboards suffice
- Groups become "trajectories"
- No social features required

---

## 5. Framework Analysis (ntdst_platform)

### Current Components
- DI Container (DI52) - **Essential**
- ApplicationProvider lifecycle - **Essential**
- Configuration loading - **Good**
- Template rendering - **Well-designed**
- Router - **Minimally used**
- Logger - **Partially used**
- Service classes (Post, Block, Role, etc.) - **Mixed usage**

### Bloat Identified
- Vite service - Disabled
- Block registration - Not used
- Role management - Not used
- Advanced router features - Not used
- Multiple logger backends - Only file needed

### Rossi Core Comparison
| Aspect | ntdst_platform | Rossi Core |
|--------|----------------|------------|
| DI Container | ~500 lines, DI52-based | 352 lines, reflection-based |
| Bootstrap | Eager loading | 3-phase lazy loading |
| Service Discovery | Manual registration | Auto-discovery |
| ORM | None | Built-in Data layer |
| Router | Complex classes | Simple URL+template |
| Total Lines | ~10,000 | ~2,000 |

---

## 6. Key Files Reference

### Enrollment
- `app/lib/Handlers/EnrollmentFormSubmissionHandler.php`
- `app/lib/Services/EnrollmentOrchestrationService.php`
- `app/lib/Flows/Enrollment/CourseEnrollmentFlow.php`

### Invoicing
- `services/GetPaid/Invoices/GetPaidInvoiceService.php`
- `app/lib/Services/InvoiceOrchestrationService.php`
- `app/lib/Flows/Invoices/InvoiceCreationFlow.php`

### Admin
- `config/admin.php`
- `app/lib/Services/AdminPages/`
- `app/lib/Services/AdminPages/Exporters/`

### Frontend
- `services/Buddy/VAD_BuddyBoss.php`
- `services/Buddy/ReadyLaunch/Handlers/`
- `app/tpl/readylaunch/`

### Framework
- `ntdst_platform/lib/Core/`
- `ntdst_platform/lib/Service/`
- `ntdst_services/services/`

---

## 7. Service Bindings (Current)

```php
// Core Interfaces
CourseServiceInterface        → LearnDashCourseService
EnrollmentServiceInterface    → LearnDashEnrollmentService
GroupEnrollmentServiceInterface → LearnDashGroupEnrollmentService
InvoiceServiceInterface       → GetPaidInvoiceService
VoucherServiceInterface       → GetPaidVoucherService
SubscriberServiceInterface    → FluentSubscriberService

// Orchestration Services
EnrollmentOrchestrationService
InvoiceOrchestrationService
VoucherOrchestrationService
ProfileOrchestrationService
```

---

## 8. Hook System (Current)

### Enrollment Hooks
```
vad_vormingen:before_user_enroll (filter, priority 5)
  → EnrollmentVoucherHandler

vad_vormingen:pre_user_enroll (action)
  → EnrollmentProfileSyncHandler

vad_vormingen:after_user_enroll (action)
  → EnrollmentInvoiceHandler (priority 10)
  → EnrollmentNotesHandler (priority 20)
```

### Course Status Hooks
```
vad_vormingen:course_status_changed
vad_vormingen:course_cancelled
vad_vormingen:course_postponed
```

---

## 9. Conclusions

### Preserve
- Interface-based service architecture
- Flow orchestration pattern
- FluentCRM integration for audit trail
- LearnDash abstraction layer

### Simplify
- Handler consolidation (5 → 1 per domain)
- Exporter reduction (9 → 4)
- Single validation layer
- Explicit form routing

### Replace
- GetPaid → Custom invoice CPTs
- BuddyBoss → Custom trajectory system + dashboard
- ntdst_platform → Rossi NTDST Core

### Remove
- Unused framework components
- Deprecated quote conversion code
- Unused exporters
- BuddyBoss social features
