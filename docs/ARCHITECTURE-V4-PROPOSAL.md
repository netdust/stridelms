# VAD Vormingen v4 - Fresh Start Architecture Proposal

**Date:** 2026-02-11
**Status:** Draft / Under Discussion

---

## Executive Summary

A comprehensive architectural review of VAD Vormingen v3 has identified significant simplification opportunities. This proposal outlines a fresh start based on the Netdust Core framework (from Rossi project), eliminating BuddyBoss and GetPaid in favor of lighter custom solutions.

### Key Decisions

| Component | Decision | Rationale |
|-----------|----------|-----------|
| **Framework** | Rossi NTDST Core | 2,000 lines vs 10,000+; lazy loading; service discovery |
| **LearnDash** | KEEP (enhanced) | Courses, groups, in-person via category - works well |
| **LearnDash Groups** | KEEP | Trajectories with optional modules - valuable flexibility |
| **In-Person Courses** | KEEP pattern | Category `'VAD vormingen'` + custom meta - smart design |
| **BuddyBoss** | REPLACE with **FluentCommunity** | Same ecosystem, lighter, feeds/forums/docs |
| **GetPaid** | REPLACE with **Quotes-Only System** | Exact Online = source of truth for invoices |
| **Vouchers** | SIMPLIFY | Keep discount logic, simpler implementation |
| **FluentCRM/Forms** | KEEP + DEEPEN | Deep integration, unified user profile view |
| **YOOtheme** | EVALUATE | Nice but maybe overkill for few pages |

### Critical Insight: Quotes Only

**Exact Online is the source of truth for invoices.** This massively simplifies the system:

- We only create QUOTES (not invoices)
- Admin sends quote to user
- Admin exports to Exact Online
- Exact Online handles actual invoicing
- No payment tracking needed in WordPress

### Impact Summary

| Metric | Current (v3) | Fresh Start (v4) |
|--------|--------------|------------------|
| Plugins | 8+ | 5 (WP, LearnDash, FluentCRM, FluentForms, FluentCommunity) |
| Framework Lines | ~10,000 | ~2,000 |
| Service Files | 50+ scattered | ~20 organized |
| Handler Files | 10+ | 3-4 consolidated |
| Invoicing | GetPaid (complex) | Quotes only → Exact Online |
| Community | BuddyBoss (heavy) | FluentCommunity (light) |
| Admin UX | Fragmented | Unified user profile view |

### What We KEEP from v3

- LearnDash for courses (online + in-person via category)
- LearnDash groups for trajectories (with optional modules)
- In-person course meta fields (`course_days`, `course_supervisors`, etc.)
- FluentCRM integration (deepened)
- FluentForms for enrollment
- Voucher/discount concept (simplified implementation)

---

## User Clarifications (2026-02-11)

These are direct answers from the project owner during the architecture review:

### On LearnDash
- **LearnDash friction is only for online courses** - in-person was added as custom layer on top
- **LearnDash has 0 admin functionality** - everything was rebuilt from scratch
- **Not going to rethink LearnDash** - just need better metadata, lean on it smartly
- **LearnDash groups** - "it's a philosophy/UX problem more than technical"

### On In-Person vs Online Courses
- **Online course can have a start date**, in-person has a programme - **2 places to handle dates**
- **In-person course benefits greatly from integrated online component**
- **Online course can benefit from scheduled in-person meet-up**
- Currently: "I just add metadata and let the front show correct info. Not more."

### On GetPaid / Invoicing
- **GetPaid removal OK**, but only create **QUOTES** - Exact Online handles invoices
- **Exact Online sync is a no-go** (too complex)
- **If online payment added**, user gets invoice directly (simpler flow)
- **Current payment flow is manual**: quote → accounting → invoice → user pays (not ideal)

### On BuddyBoss
- **BuddyBoss removal OK**, but some features are nice: feeds, forums, docs
- **Maybe FluentCommunity?** (same ecosystem)

### On Admin Experience
- **Users have complex profiles**
- **Admins use too many tools**: LearnDash, FluentCRM, FluentForms, custom dashboard, GetPaid, WordPress
- Data exists but is **fragmented** - need unified view

### On FluentCRM
- **Deep integration from start** is essential
- FluentCRM is the audit trail - keep and deepen

### On YOOtheme
- **Maybe not necessary** - few pages could be hand-coded

### On Fresh Start
- **Fresh means fresh** - only migrate user data
- Use **Netdust Core from Rossi** as base
- **Goal: get a grip on code complexity** - project started 5 years ago and it shows
- **Goal: better UX solutions** for both users and admins

### On User Pain Points
- **Users are not IT friendly**
- **Enrollment is fine** - that flow works
- **Post-enrollment is the problem**: hard to find info needed
- **BuddyBoss profile section** is where they get lost

### On Admin Pain Points
- **Too many "things to remember"** to work with it
- **Specialized knowledge required** on how things work
- System is complex, not intuitive

### On YOOtheme Usage
5 pages use YOOtheme:
- Homepage
- Trajectory page
- FAQ
- Vormingen (in-person courses) page
- Online courses page

### On FluentCRM Automations
- **Current automations not really useful** - triggered by user actions
- **System is event-driven** - automations should respond to events
- Could add that event layer in v4

### On Exact Online Workflow
- **Weekly copy/paste** of invoice data
- Works but not pretty
- No API sync (too complex)

### On Volume
- **~1000 users/year** for in-person courses
- **~30 courses × ~30 users** = ~900 enrollments/year
- **Many online users** (higher volume)
- This is a heavily used platform

---

## Revised Understanding (2026-02-11)

### In-Person Courses
The current approach is smart and should be kept:
- LearnDash course + `'VAD vormingen'` category = in-person
- Custom meta: `course_days`, `course_supervisors`, `course_address`, etc.
- Status flags: `course_status_full`, `course_status_cancelled`, etc.

### LearnDash Groups (Trajectories)
Currently well-designed and should be kept:
- Groups contain mandatory + optional courses
- Optional modules via `ld_optional_enroll_groups` meta
- `no_invoice` flag for optional courses
- User can self-enroll in optional courses if allowed

### Invoicing Reality
**Exact Online is the source of truth.** We only need:
- Quote creation (not invoices)
- PDF generation
- Belgian OGM payment reference
- Export to Exact Online

### BuddyBoss Alternative
FluentCommunity is promising:
- Same ecosystem as FluentCRM/FluentForms
- Activity feeds, forums ("Spaces"), document sharing
- Lighter than BuddyBoss
- Migration tools available

### Admin Experience Gap
Data exists but is fragmented. Need unified user profile view showing:
- Profile + organization data
- Course history (LearnDash)
- Quotes
- FluentCRM notes and tags
- Everything in one place

---

## Core Requirements

1. **Users can subscribe** to online and in-person courses with easy access and overview
2. **Users can manage** their data (subscriptions, profile)
3. **Admins have CRM access** for user audit (via FluentCRM)
4. **Admins have course dashboard** for management
5. **Quote system** for flexible invoicing (admin workflow essential)
6. **Online payment** support (Mollie/Bancontact)
7. **Trajectory/cohort system** for grouped learning paths

---

## The Trajectory Concept

### Philosophy / UX Problem (Current System)

The current implementation technically works but has a **fundamental UX problem**:

**The system is course-centric, not trajectory-centric.**

When a user joins a trajectory, they see:
- A grid of course cards (not a learning path)
- "Verplichte modules" and "Keuze modules" as separate lists
- A timeline that's just an event log (newest first)
- An agenda that's just a session calendar

**What's missing: "Where am I on my journey?"**

| Current Approach | Should Feel Like |
|------------------|------------------|
| Grid of course cards | Visual learning path (A → B → C) |
| "4 mandatory, 2 optional" courses | "Complete 4 core + choose 2 of 3 electives" |
| Timeline = event log | Progress indicator ("you're 40% complete") |
| Separate mandatory/optional sections | Integrated path with choice points |
| No trajectory completion state | Clear "graduation" milestone |
| History view (newest first) | Journey view (start → now → finish) |

### The Mental Model Gap

**Users think:** *"I'm on a path to become a certified prevention professional"*

**System shows:** *"Here are some courses in a group"*

LearnDash Groups don't naturally convey:
- Progress toward a goal
- What comes next
- When am I "done"?
- Why do these courses belong together?

### Target UX: Trajectories as Journeys

```
┌─────────────────────────────────────────────────────────────┐
│  Traject: CGG Preventiewerker TAD                           │
│  ────────────────────────────────────────────────────────── │
│                                                             │
│  Your Progress: ████████░░░░░░░░ 45%                       │
│                                                             │
│  ┌─────┐    ┌─────┐    ┌─────┐    ┌─────┐                  │
│  │  ✓  │───▶│  ✓  │───▶│ NOW │───▶│  ○  │                  │
│  │Day 1│    │Day 2│    │Day 3│    │Day 4│                  │
│  └─────┘    └─────┘    └─────┘    └─────┘                  │
│                                                             │
│  Next Up: Neurobiologie - March 14 @ VAD                   │
│  [Add to Calendar]                                          │
│                                                             │
│  ┌── Choose 2 of 3 Electives ──────────────────────────┐   │
│  │ [ ] EUPC Training (3 days)                          │   │
│  │ [✓] Motiverende Gespreksvoering (online) COMPLETED  │   │
│  │ [ ] Coachen van organisaties (2 days)               │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  Upon completion: Certificate + FluentCRM tag applied      │
└─────────────────────────────────────────────────────────────┘
```

### What This Requires

1. **Trajectory as first-class concept** (not just a LearnDash group wrapper)
2. **Progress tracking at trajectory level** (not just per-course)
3. **Clear completion criteria** (X mandatory + Y of Z optional)
4. **Visual journey representation** (path visualization, not grid)
5. **Next action prominence** ("What do I do now?")
6. **Trajectory completion milestone** (certificate, tag, notification)

### Current Implementation (Technical)

The current v3 implementation stores optional courses via:
- `ld_optional_enroll_groups` meta on LearnDash groups
- Structure: `[{checked, title, desc, selected_ids[], no_invoice}]`
- Works technically but doesn't solve the UX problem

### Recommendation

**Keep LearnDash groups** for the underlying data model (courses, enrollments), but **build a trajectory UX layer on top** that:
- Shows progress as a journey, not a course list
- Makes "what's next" immediately obvious
- Tracks and celebrates trajectory completion
- Guides elective selection with context

---

### Data Model

```
TRAJECTORY (Custom Post Type: vad_trajectory)
├── Title: "Vorming Drugs 2025 - Groep A"
├── Type: mandatory | self-paced | mixed
├── Courses: [course_id_1, course_id_2, course_id_3]  ← LearnDash courses
├── Start Date: 2025-03-01
├── Cohort Size: 20
├── Status: open | in_progress | completed
└── Participants: [user_ids]
```

### User Experience
- User enrolls in trajectory → automatically gets access to all courses
- Dashboard shows trajectory progress (% courses completed)
- Optional: cohort features (shared forum, group email)
- No BuddyBoss overhead

### Admin Experience
- Create trajectory, attach courses
- Enroll users (individually or bulk)
- Track cohort progress
- Send cohort communications via FluentCRM

---

## The Quote System (Simplified)

**Key insight: Exact Online is the source of truth for invoices.**

We only need quotes in WordPress. Actual invoicing happens in Exact Online.

### Data Model

```
QUOTE (Custom Post Type: vad_quote)
├── Number: VADQ-2025-00123
├── User ID
├── Status: draft | sent | exported | cancelled
├── Items: [{course_id, price, quantity}]
├── Discount: {voucher_id, amount, percentage}
├── Total / Tax (BTW)
├── Valid Until
├── Payment Reference: +++650/0012/34597+++ (Belgian OGM)
├── Exported Date (when sent to Exact)
├── Exact Reference (optional, from Exact)
└── PDF URL
```

**No invoice CPT needed** - Exact Online handles that.

### Simplified Flow

```
Enrollment Form → Quote Created (draft)
                        ↓
                Admin Reviews Quote
                        ↓
              Admin Sends to User (status: sent)
                        ↓
              Admin Exports to Exact Online (status: exported)
                        ↓
              Exact Online creates real invoice
                        ↓
              User pays via Exact Online / Bank
                        ↓
              (Optional) Mark quote as paid in WP for reference
```

### What This Eliminates

- ❌ Invoice CPT
- ❌ Payment status tracking
- ❌ Payment gateway integration (Mollie/Stripe)
- ❌ Invoice emails from WordPress
- ❌ Quote-to-invoice conversion logic

### What We Still Need

- ✅ Quote creation with items
- ✅ Voucher/discount application
- ✅ Belgian OGM payment reference
- ✅ PDF generation
- ✅ Export to Exact Online (CSV or API)
- ✅ User can view their quotes

---

## FluentCommunity as BuddyBoss Replacement

### Why FluentCommunity

| Feature | FluentCommunity | BuddyBoss |
|---------|-----------------|-----------|
| Activity Feeds | ✅ Built-in | ✅ |
| Forums/Discussions | ✅ "Spaces" | ✅ |
| Documents | ✅ File sharing | ✅ |
| Performance | **Light** | Heavy |
| Ecosystem | FluentCRM/Forms | Separate |
| Pricing | Free core / ~$199/yr Pro | $228/yr |

### Features Needed

- [ ] Activity feeds (course progress, enrollment updates)
- [ ] Discussions per trajectory/course
- [ ] Document sharing for course materials
- [ ] Integration with LearnDash (can coexist)

### Migration Path

1. Install FluentCommunity alongside BuddyBoss
2. Use migration tools to import data
3. Test on staging
4. Gradually phase out BuddyBoss

### Alternative: Minimal Stack

If FluentCommunity doesn't fit:
- bbPress for forums
- wpDiscuz for enhanced comments
- Document Library for materials
- Custom activity via FluentCRM notes

---

## Unified Admin Experience

### The Problem

Data exists but is fragmented:
- Course data in LearnDash admin
- User data in FluentCRM
- Quotes scattered
- Notes not visible in context

### The Solution: User Profile Dashboard

When admin clicks a user (from any context), show everything:

```
┌─────────────────────────────────────────────────────────────────┐
│  USER: Jan Janssens                              [Edit in CRM]  │
├─────────────────────────────────────────────────────────────────┤
│  📋 PROFILE              │  🏢 ORGANIZATION                     │
│  jan@example.be          │  VAD Partner vzw                     │
│  +32 123 456 789         │  Department: Preventie               │
│  Preventiewerker         │  VAD Member: ✅                      │
├─────────────────────────────────────────────────────────────────┤
│  📚 COURSES                                                     │
│  • Vorming Drugs Basis     │ In-Person │ ✅ Completed │ 03/24   │
│  • Traject 2024 Groep A    │ Traject   │ 🔄 Active    │         │
│    └─ Module 1 (mandatory) │           │ ✅           │         │
│    └─ Module 2 (optional)  │           │ Enrolled     │         │
│  • Online Intro            │ Online    │ 75%          │         │
├─────────────────────────────────────────────────────────────────┤
│  💰 QUOTES                                                      │
│  • VADQ-0123 │ Vorming Drugs │ €250 │ Exported to Exact        │
│  • VADQ-0089 │ Traject 2024  │ €450 │ Sent                     │
├─────────────────────────────────────────────────────────────────┤
│  📝 RECENT NOTES                                                │
│  • 2024-03-15: Ingeschreven voor Vorming Drugs Basis            │
│  • 2024-03-15: Offerte VADQ-0123 aangemaakt                     │
│  • 2024-02-01: Voucher toegepast (€50 korting)                  │
├─────────────────────────────────────────────────────────────────┤
│  🏷️ TAGS: VAD Lid, Bestelbon Nodig, Actief 2024                │
└─────────────────────────────────────────────────────────────────┘
```

### Implementation Options

1. **FluentCRM Panel** - Embed in FluentCRM contact view
2. **Standalone Admin Page** - `/wp-admin/admin.php?page=vad-user&id=123`
3. **Both** - Panel for quick view, page for full details

### Data Sources

All data already exists, just needs orchestration:
- Profile: FluentCRM subscriber + xProfile
- Organization: FluentCRM company link
- Courses: LearnDash `ld_get_mycourses()` + progress
- Quotes: Custom quote CPT
- Notes: FluentCRM `fc_subscriber_notes`
- Tags: FluentCRM tags

---

## Proposed Directory Structure

Based on Rossi's NTDST Core pattern:

```
app/content/
├── mu-plugins/
│   ├── ntdst-core/                    ← Port from Rossi (as-is)
│   │   ├── core/
│   │   │   ├── Container.php          ← DI container with autowiring
│   │   │   ├── Bootstrap.php          ← 3-phase service lifecycle
│   │   │   ├── Router.php             ← URL routing + template hooks
│   │   │   ├── Theme.php              ← Theme bootstrap
│   │   │   └── SectorRegistry.php     ← Repurpose for learning tiers
│   │   └── api/
│   │       ├── Data.php               ← Minimal ORM for CPTs
│   │       ├── Response.php           ← Template rendering
│   │       └── Endpoints.php          ← REST API with rate limiting
│   └── ntdst-coreloader.php
│
└── themes/
    └── vad-theme/
        ├── theme-config.php           ← All VAD configuration
        ├── functions.php
        │
        ├── services/                  ← Auto-discovered services
        │   │
        │   ├── core/                  ← Always loaded
        │   │   ├── CourseService.php          ← LearnDash wrapper
        │   │   ├── SubscriberService.php      ← FluentCRM wrapper
        │   │   └── MailService.php
        │   │
        │   ├── enrollment/            ← Enrollment domain
        │   │   ├── EnrollmentService.php
        │   │   ├── EnrollmentFlow.php
        │   │   └── EnrollmentHandler.php      ← Single consolidated handler
        │   │
        │   ├── trajectory/            ← Replaces BuddyBoss groups
        │   │   ├── TrajectoryService.php
        │   │   ├── TrajectoryRepository.php
        │   │   └── CohortManager.php
        │   │
        │   ├── invoicing/             ← Replaces GetPaid
        │   │   ├── QuoteService.php
        │   │   ├── InvoiceService.php
        │   │   ├── QuoteRepository.php
        │   │   ├── InvoiceRepository.php
        │   │   ├── PaymentGateway.php         ← Mollie/Stripe
        │   │   ├── BelgianOGM.php             ← Payment reference generator
        │   │   └── PdfGenerator.php           ← DOMPDF wrapper
        │   │
        │   ├── voucher/
        │   │   ├── VoucherService.php
        │   │   └── VoucherRepository.php
        │   │
        │   ├── dashboard/             ← Replaces BuddyBoss frontend
        │   │   ├── UserDashboardService.php
        │   │   ├── AdminDashboardService.php
        │   │   └── ProfileService.php
        │   │
        │   ├── admin/
        │   │   ├── CourseAdminService.php
        │   │   ├── InvoiceAdminService.php
        │   │   ├── ExportService.php          ← Consolidated exporters
        │   │   └── AttendanceService.php
        │   │
        │   ├── integrations/
        │   │   ├── LearnDashService.php       ← Metaboxes, hooks
        │   │   └── FluentCRMService.php       ← Triggers, sync
        │   │
        │   └── yootheme/
        │       └── ...
        │
        └── templates/
            ├── dashboard/
            │   ├── user-home.php
            │   ├── my-courses.php
            │   ├── my-trajectories.php
            │   ├── my-invoices.php
            │   └── my-profile.php
            ├── invoice/
            │   ├── quote-view.php
            │   ├── invoice-view.php
            │   └── payment-success.php
            ├── admin/
            │   ├── course-dashboard.php
            │   └── invoice-dashboard.php
            ├── pdf/
            │   ├── quote.php
            │   ├── invoice.php
            │   └── certificate.php
            └── emails/
                ├── quote-sent.php
                ├── invoice-sent.php
                └── payment-received.php
```

---

## Key Service Implementations

### TrajectoryService

```php
<?php
class TrajectoryService implements NTDST_Service_Meta {

    public static function metadata(): array {
        return [
            'name' => 'Trajectory Service',
            'priority' => 15,
        ];
    }

    public function enrollUserInTrajectory(int $userId, int $trajectoryId): bool {
        $trajectory = $this->getTrajectory($trajectoryId);

        // Add user to trajectory participants
        $participants = get_post_meta($trajectoryId, '_participants', true) ?: [];
        if (!in_array($userId, $participants)) {
            $participants[] = $userId;
            update_post_meta($trajectoryId, '_participants', $participants);
        }

        // Enroll user in ALL trajectory courses
        $courses = get_post_meta($trajectoryId, '_courses', true) ?: [];
        foreach ($courses as $courseId) {
            ntdst_get(EnrollmentService::class)->enroll($userId, $courseId);
        }

        // FluentCRM note
        ntdst_get(SubscriberService::class)->createNote(
            $userId,
            "Ingeschreven in traject: {$trajectory->post_title}"
        );

        do_action('vad:trajectory_enrolled', $userId, $trajectoryId);

        return true;
    }

    public function getUserProgress(int $userId, int $trajectoryId): array {
        $courses = get_post_meta($trajectoryId, '_courses', true) ?: [];
        $completed = 0;

        foreach ($courses as $courseId) {
            if (learndash_course_completed($userId, $courseId)) {
                $completed++;
            }
        }

        return [
            'total' => count($courses),
            'completed' => $completed,
            'percentage' => count($courses) > 0
                ? round(($completed / count($courses)) * 100)
                : 0,
        ];
    }
}
```

### InvoiceService

```php
<?php
class InvoiceService implements NTDST_Service_Meta {

    public static function metadata(): array {
        return [
            'name' => 'Invoice Service',
            'priority' => 15,
        ];
    }

    public function createQuote(int $userId, array $items, array $meta = []): int {
        $quote = ntdst_get(QuoteRepository::class)->create([
            'user_id' => $userId,
            'items' => $items,
            'status' => 'draft',
            'discount' => $meta['voucher'] ?? null,
            'valid_until' => date('Y-m-d', strtotime('+30 days')),
        ]);

        ntdst_get(SubscriberService::class)->createNote(
            $userId,
            "Offerte {$quote->number} aangemaakt"
        );

        return $quote->id;
    }

    public function convertQuoteToInvoice(int $quoteId): int {
        $quote = ntdst_get(QuoteRepository::class)->get($quoteId);

        $invoice = ntdst_get(InvoiceRepository::class)->create([
            'user_id' => $quote->user_id,
            'quote_id' => $quoteId,
            'items' => $quote->items,
            'discount' => $quote->discount,
            'status' => 'sent',
            'payment_reference' => ntdst_get(BelgianOGM::class)->generate(),
        ]);

        $quote->update(['status' => 'converted']);

        do_action('vad:invoice_created', $invoice);

        return $invoice->id;
    }

    public function initiateOnlinePayment(int $invoiceId): string {
        $invoice = ntdst_get(InvoiceRepository::class)->get($invoiceId);

        return ntdst_get(PaymentGateway::class)->createPayment([
            'amount' => $invoice->total,
            'description' => "VAD Factuur {$invoice->number}",
            'redirectUrl' => home_url("/dashboard/payment-complete/{$invoiceId}"),
            'webhookUrl' => rest_url('vad/v1/payment-webhook'),
            'metadata' => ['invoice_id' => $invoiceId],
        ]);
    }
}
```

### PaymentGateway (Mollie)

```php
<?php
class PaymentGateway implements NTDST_Service_Meta {

    private \Mollie\Api\MollieApiClient $mollie;

    public static function metadata(): array {
        return [
            'name' => 'Payment Gateway',
            'priority' => 10,
        ];
    }

    public function __construct() {
        $this->mollie = new \Mollie\Api\MollieApiClient();
        $this->mollie->setApiKey(get_option('vad_mollie_api_key'));
    }

    public function createPayment(array $data): string {
        $payment = $this->mollie->payments->create([
            'amount' => [
                'currency' => 'EUR',
                'value' => number_format($data['amount'], 2, '.', ''),
            ],
            'description' => $data['description'],
            'redirectUrl' => $data['redirectUrl'],
            'webhookUrl' => $data['webhookUrl'],
            'metadata' => $data['metadata'],
            'method' => ['bancontact', 'ideal', 'creditcard'],
        ]);

        return $payment->getCheckoutUrl();
    }
}
```

### UserDashboardService

```php
<?php
class UserDashboardService implements NTDST_Service_Meta {

    public static function metadata(): array {
        return [
            'name' => 'User Dashboard',
            'priority' => 20,
        ];
    }

    public function __construct() {
        ntdst_router()->get('/mijn-account', [$this, 'dashboard']);
        ntdst_router()->get('/mijn-account/cursussen', [$this, 'courses']);
        ntdst_router()->get('/mijn-account/trajecten', [$this, 'trajectories']);
        ntdst_router()->get('/mijn-account/facturen', [$this, 'invoices']);
        ntdst_router()->get('/mijn-account/profiel', [$this, 'profile']);
    }

    public function dashboard(): NTDST_Response {
        $userId = get_current_user_id();

        return ntdst_response()
            ->with('courses', $this->getUserCourses($userId))
            ->with('trajectories', $this->getUserTrajectories($userId))
            ->with('pending_invoices', $this->getPendingInvoices($userId))
            ->template('dashboard/user-home');
    }
}
```

---

## Migration Approach

### What We Keep (No Migration Needed)

| Data | Location | Notes |
|------|----------|-------|
| WordPress Users | `wp_users` | Core user accounts |
| User Meta | `wp_usermeta` | Profile data |
| FluentCRM Contacts | `fc_subscribers` | CRM data |
| FluentCRM Notes | `fc_subscriber_notes` | Audit trail |
| LearnDash Progress | `wp_usermeta` (ld_*) | Course progress |
| LearnDash Courses | `sfwd-courses` CPT | Course content |

### What We Don't Migrate

| Data | Reason |
|------|--------|
| GetPaid invoices | Fresh start - clean slate |
| BuddyBoss groups | Replaced with trajectories |
| BuddyBoss profiles | FluentCRM has all data |
| Old vouchers | New voucher system |

### Migration Script

```php
<?php
class VAD_Migration {

    public function run(): void {
        // 1. Verify user data intact
        $this->verifyUsers();

        // 2. Verify FluentCRM contacts
        $this->verifyFluentContacts();

        // 3. Verify LearnDash enrollments
        $this->verifyLearnDashAccess();

        // 4. Create trajectories from existing LearnDash groups
        $this->createTrajectoriesFromGroups();

        // 5. Deactivate old plugins
        $this->deactivateOldPlugins();
    }

    private function createTrajectoriesFromGroups(): void {
        $ldGroups = get_posts(['post_type' => 'groups', 'numberposts' => -1]);

        foreach ($ldGroups as $group) {
            $trajectoryId = wp_insert_post([
                'post_type' => 'vad_trajectory',
                'post_title' => $group->post_title,
                'post_status' => 'publish',
            ]);

            $courses = learndash_group_enrolled_courses($group->ID);
            update_post_meta($trajectoryId, '_courses', $courses);

            $users = learndash_get_groups_users($group->ID);
            update_post_meta($trajectoryId, '_participants', wp_list_pluck($users, 'ID'));
        }
    }

    private function deactivateOldPlugins(): void {
        deactivate_plugins([
            'buddyboss-platform/bp-loader.php',
            'buddyboss-theme/buddyboss-theme.php',
            'wpinv-quotes/wpinv-quotes.php',
            'invoicing/invoicing.php',
        ]);
    }
}
```

---

## Timeline (Revised)

| Phase | Duration | Focus |
|-------|----------|-------|
| **1. Foundation** | 1 week | Port Rossi Core, theme structure, config |
| **2. Core Services** | 1 week | CourseService (wrap LearnDash), SubscriberService (wrap FluentCRM) |
| **3. Quote System** | 2 weeks | CPT, PDF, Belgian OGM, Exact export |
| **4. Voucher System** | 1 week | Simplified discounts |
| **5. Unified Admin Profile** | 2 weeks | User profile view with all data |
| **6. Course Admin Dashboard** | 2 weeks | Rebuild admin tables, attendance, exports |
| **7. FluentCommunity Setup** | 1 week | Install, configure, test with LearnDash |
| **8. Migration & Polish** | 2 weeks | BuddyBoss data migration, testing, styling |

**Total: ~12 weeks**

### What's Faster Now

- ❌ No invoice system (Exact Online handles it)
- ❌ No payment gateway integration
- ❌ No trajectory CPT (keep LearnDash groups)
- ✅ Keep existing LearnDash metaboxes (just port them)

---

## Technology Stack (Final)

```
┌─────────────────────────────────────────────────────┐
│                    WordPress                         │
├──────────────┬──────────────┬───────────────────────┤
│  LearnDash   │  FluentCRM   │  FluentForms          │
│  (courses,   │  (CRM,       │  (enrollment          │
│  progress,   │  automation, │  forms)               │
│  certs)      │  audit)      │                       │
├──────────────┴──────────────┴───────────────────────┤
│              NTDST Core (from Rossi)                │
│  DI Container | Service Discovery | Router | ORM    │
├─────────────────────────────────────────────────────┤
│              VAD Theme Services                      │
│  ├── Trajectory System (replaces BuddyBoss groups)  │
│  ├── Quote/Invoice System (replaces GetPaid)        │
│  ├── Mollie Payment Gateway                         │
│  ├── User Dashboard (replaces BuddyBoss frontend)   │
│  └── Admin Dashboard (simplified)                   │
└─────────────────────────────────────────────────────┘
```

---

## Open Questions

1. **YOOtheme**: How many pages actually need it? Could they be hand-coded templates?
2. **Exact Online Integration**: API or CSV export? What data format? Manual or automatic export?
3. **FluentCommunity**: Which features needed? Test on development first?
4. **Admin User Profile**: FluentCRM panel, standalone page, or both?
5. ~~**LearnDash Groups**: Keep current implementation as-is, or simplify optional modules?~~
   → **RESOLVED**: Keep LearnDash groups as data layer, but build a trajectory UX layer on top that shows progress as a journey, not a course grid. The technical implementation is fine; the problem is philosophical/UX.
6. **Hybrid Courses**: In-person courses could benefit from online components, and vice versa. How to handle dates/schedules for both types in one unified view?

---

## Next Steps

1. [ ] Finalize technology decisions
2. [ ] Set up new theme structure
3. [ ] Port NTDST Core from Rossi
4. [ ] Begin Phase 1: Foundation

---

## References

- Rossi NTDST Core: `/home/ntdst/Sites/rossi/app/content/mu-plugins/ntdst-core/`
- Current VAD v3: `/home/ntdst/Sites/vad-vormingen/app/content/plugins/vad-vormingen-v3.0/`
- Current Framework: `/home/ntdst/Sites/vad-vormingen/app/content/plugins/ntdst_platform/`
