# Stride LMS Theme — Build Skill

## Context

Stride is a custom WordPress LMS plugin for health sector training organisations in Flanders (Belgium). This skill covers building the **public-facing theme** that connects to Stride's service layer.

**Stack:** WordPress theme (Bedrock) + Tailwind CSS + Alpine.js + Vite
**Language:** Dutch (nl_BE) — all UI labels in Dutch, code in English
**Target users:** Healthcare professionals browsing and enrolling in training courses

---

## Architecture Overview

```
┌─────────────────────────────────────────────────┐
│  WordPress Theme (this skill)                   │
│  ├── templates/        PHP page templates        │
│  ├── partials/         Reusable components       │
│  ├── assets/src/       Tailwind + Alpine + Vite  │
│  └── functions.php     Theme setup + hooks       │
├─────────────────────────────────────────────────┤
│  Stride Core (mu-plugin — already built)         │
│  ├── Modules/Edition/     EditionService, SessionService  │
│  ├── Modules/Enrollment/  EnrollmentService, EnrollmentRouterService  │
│  ├── Modules/Invoicing/   QuoteService, VoucherService  │
│  ├── Modules/Trajectory/  TrajectoryService      │
│  ├── Modules/Attendance/  AttendanceService      │
│  ├── Modules/Completion/  CompletionService      │
│  ├── Modules/Audit/       AuditBridge (event logging)  │
│  ├── Domain/              Value objects & enums  │
│  └── Integrations/        LearnDash adapter      │
├─────────────────────────────────────────────────┤
│  WordPress + LearnDash + FluentForms + FluentCRM │
└─────────────────────────────────────────────────┘
```

### Accessing Stride Services from Theme Templates

```php
// All services available via ntdst_get() with full class name
$editionService     = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$sessionService     = ntdst_get(\Stride\Modules\Edition\SessionService::class);
$enrollmentService  = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$quoteService       = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
$trajectoryService  = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);
$attendanceService  = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
```

### Stride Data Model

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ TRAJECTORY (cohort-based learning path)                                     │
│ ├── enrollment_deadline                                                     │
│ ├── choice_available / choice_deadline                                      │
│ ├── courses[] with pick_count ← TYPICAL: user chooses from electives        │
│ └── TrajectoryEnrollment                                                    │
│         └── elective_choices[] → locked after deadline                      │
├─────────────────────────────────────────────────────────────────────────────┤
│ COURSE (LearnDash sfwd-courses)                                             │
│ ├── type: online → DIRECT enrollment (no Edition needed)                    │
│ │                                                                           │
│ └── type: in-person | hybrid | webinar → EDITION-based                      │
│           └── Edition (vad_edition)                                         │
│                 ├── sessions[] ← TYPICAL: 1-3 mandatory sessions            │
│                 ├── session_slots[] ← RARE: user picks from groups          │
│                 └── Registration (wp_vad_registrations)                     │
├─────────────────────────────────────────────────────────────────────────────┤
│ SESSION (vad_session)                                                       │
│ ├── slot_id (optional - only when part of a selection slot)                 │
│ ├── type: in_person | webinar | online | assignment                         │
│ └── optional flag                                                           │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Summary:**
- **Editions**: Usually have all mandatory sessions (no user selection). Session slots are rare.
- **Trajectories**: Usually have elective courses (user must choose which to follow).

**Key enrollment flows:**

1. **Online course** → Direct LearnDash enrollment (form or direct)
2. **In-person/hybrid course** → Enroll into Edition → Attend all sessions
3. **Trajectory** → Enroll → Choose elective courses → Enroll into each course

### Enrollment Flow Details

**Flow 1: Online Course (LearnDash-driven)**
```
User → Course page → [Enroll] → Form/Payment (if required) → LearnDash grants access
```
LearnDash course settings apply (price, access mode, drip content, etc.). Enrollment can be direct, via form, or require payment — all configured in LearnDash.

**Flow 2: Edition-based Course (in-person/hybrid/webinar)**
```
User → Course page (LD content + sidebar with Edition info) → [Enroll in Edition]
  ↓
Registration created → Quote generated → Payment → LearnDash access granted
  ↓
User attends sessions → Attendance tracked → Completion
```

**Course page layout:**
- **Main content:** LearnDash course info (description, curriculum, materials)
- **Sidebar:** Edition details (dates, venue, price, spots available, enroll button)

**Edition selection:** Usually there's only ONE open edition, so no choice needed. Design should support multiple editions (dropdown or list) for edge cases.

**Flow 3: Trajectory (cohort program)**
```
User → Trajectory page → [Enroll] (before enrollment_deadline)
  ↓
TrajectoryEnrollment created → Quote generated → Payment
  ↓
User completes all required courses → Trajectory completion
```

### Elective Selection (Step 2 — Dashboard)

**Important:** Elective choices are NEVER made in the enrollment form. After enrollment, users make selections in their **profile dashboard**:

```
Dashboard → Mijn Trajecten / Mijn Inschrijvingen
  ↓
[Kies je vakken] or [Kies je sessies]
  ↓
Selection UI (before deadline) → Choices locked after deadline
```

**For Trajectories:**
- User sees course groups with pick_count
- Selects which elective courses to follow
- Locked after `choice_deadline`

**For Editions with session slots (rare):**
- User sees session slots with pick_count
- Selects which sessions to attend
- Locked after `selection_deadline`

---

## Enrollment Form Structure

The enrollment form (FluentForms) is a multi-step form with conditional fields based on enrollment type. LearnDash course settings still apply (price, access mode, etc.).

### Step 0: Enrollment Type Selection

```
┌─────────────────────────────────────────────────────────────────┐
│ Voor wie is deze inschrijving?                                  │
│                                                                 │
│ ┌─────────────┐  ┌─────────────┐  ┌─────────────┐              │
│ │  [image]    │  │  [image]    │  │  [image]    │              │
│ │  Werknemer  │  │  Collega    │  │  Particulier│              │
│ │  ○          │  │  ○          │  │  ○          │              │
│ └─────────────┘  └─────────────┘  └─────────────┘              │
└─────────────────────────────────────────────────────────────────┘
```

**Options:**
- `werknemer` - For myself as employee of an organization
- `collega` - For a colleague of my organization
- `prive` - As a private person

**Hidden fields:** `course_id`

### Profile Type (Audience Segmentation)

`profile_type` identifies WHO the user is in the health sector. This is critical for:
- Tailoring communication
- Reporting and analytics
- Course recommendations
- Accreditation tracking

**Captured in:** Enrollment form (registration is handled by separate plugin).

**Example profile types** (organization-specific):
- Verpleegkundige (Nurse)
- Arts (Doctor)
- Zorgkundige (Care worker)
- Student
- Horeca medewerker
- Leidinggevende (Manager)
- Anders (Other)

The available options are configurable per organization — not hardcoded.

**In the form:** Show as select/radio in Step 1 if user doesn't have a profile_type yet. Pre-fill from user meta if already set.

### Step 1: User/Participant Info (user_step)

| Field | Werknemer | Collega | Prive |
|-------|-----------|---------|-------|
| profile_type (if not set) | ✓ | ✓ | ✓ |
| organisations (select) | ✓ | ✓ | ✗ |
| department | ✓ | ✓ | ✗ |
| function_user | ✓ | ✗ | ✓ |
| function_colleague | ✗ | ✓ | ✗ |
| names (colleague) | ✗ | ✓ | ✗ |
| email (colleague) | ✗ | ✓ | ✗ |
| phone_user | ✓ | ✗ | ✓ |
| phone_colleague | ✗ | ✓ | ✗ |

**Logic:** When enrolling a colleague (`collega`), you provide THEIR info (name, email, phone, function). The logged-in user's info is used for the enrolling person.

### Step 2: Invoice/Billing (member_step)

| Field | Werknemer | Collega | Prive |
|-------|-----------|---------|-------|
| invoice_organisation | ✓ | ✓ | ✓ |
| invoice_email | ✓ | ✓ | ✓ |
| facturatie (address) | ✓ | ✓ | ✓ |
| invoice_vat | ✓ | ✓ | ✗ |
| invoice_gln | ✓ | ✓ | ✗ |
| ordernumber | ✓ | ✓ | ✓ |
| voucher | ✓ | ✓ | ✓ |

**Logic:** Private persons (`prive`) don't see VAT/GLN fields (not applicable for individuals).

### Step 3: Intake Questions

Optional intake questions (may vary per course):
- Years in current position
- Current function
- Education background
- Expectations from the course
- Experience with the topic
- Challenges/bottlenecks experienced

### Step 4: Confirmation

- GDPR agreement checkbox
- Submit

### Form Data Flow

```
FluentForm submission
  ↓
EnrollmentFormHandler receives data
  ↓
Creates Registration (wp_vad_registrations)
  ↓
If voor_wie = collega → Creates/updates User for the colleague
  ↓
QuoteService → Creates Quote with line items
  ↓
If voucher provided → VoucherService validates & applies discount
  ↓
LearnDash access granted (based on course settings)
```

---

### Key CPT Fields

All fields use NTDST Data Manager with `_ntdst_` prefix in database.

**Edition (vad_edition):**
- `course_id` (int) — parent LearnDash course
- `start_date`, `end_date` (date)
- `capacity` (int, 0 = unlimited)
- `price` (int) — price in cents for members
- `price_non_member` (int) — price in cents for non-members
- `venue` (text) — location name
- `description` (text) — edition-specific details
- `speakers` (text) — speaker names/info
- `status` (EditionStatus enum)
- `completion_mode` (CompletionMode enum) — how completion is evaluated
- `session_slots` (JSON) — groups of sessions with pick_count (rare)
- `selection_deadline` (datetime) — deadline for session selection (if slots)

**Session (vad_session):**
- `edition_id` (int) — parent edition
- `slot_id` (string) — slot identifier (only if part of selection group)
- `type` (SessionType enum: in_person|webinar|online|assignment)
- `date` (date) — session date (note: not `session_date`)
- `start_time`, `end_time` (time)
- `location` (text)
- `duration_hours` (float) — contact hours
- `capacity` (int) — session-level capacity (optional)
- `optional` (bool) — not required for completion

**Trajectory (vad_trajectory):**
- `mode` (TrajectoryMode enum: cohort|self_paced)
- `enrollment_deadline` (datetime)
- `choice_available` (datetime) — when elective selection opens
- `choice_deadline` (datetime) — when elective selection locks
- `courses` (JSON) — array of course groups with pick_count
- `status` (TrajectoryStatus enum)

**Quote (vad_quote):**
- `quote_number` (string) — formatted code (e.g., STRIDE-2026-001)
- `user_id` (int) — student
- `registration_id` (int) — linked registration
- `status` (QuoteStatus enum)
- `subtotal`, `tax`, `total` (int) — amounts in cents
- `discount` (int) — discount amount in cents
- `voucher_id` (int) — applied voucher (if any)
- `items` (JSON) — line items array
- `valid_until` (date) — expiration date
- `exported_at` (datetime) — when sent to Exact Online

**Voucher (vad_voucher):**
- `code` (string) — unique discount code
- `discount_type` (DiscountType enum: full|fixed|percentage)
- `discount_value` (int) — amount (cents) or percentage
- `status` (VoucherStatus enum)
- `valid_from`, `valid_until` (date) — validity period
- `max_uses` (int) — usage limit (0 = unlimited)
- `used_count` (int) — current usage count
- `edition_id` (int) — restrict to specific edition (optional)

### WordPress Taxonomies on Courses

```php
// Register in theme or plugin — used for filtering
'stride_domain'    → Zorgdomein (e.g., "Ouderenzorg", "Geestelijke gezondheidszorg", "Eerste lijn")
'stride_format'    → Vormingstype (e.g., "Meerdaagse opleiding", "Webinar", "E-learning", "Studiedag")
'stride_audience'  → Doelgroep (e.g., "Verpleegkundigen", "Artsen", "Paramedici", "Leidinggevenden")
'stride_location'  → Locatie (e.g., "Antwerpen", "Gent", "Leuven", "Online")
```

---

## Domain Layer (Value Objects & Enums)

All enums are backed by string values for database storage. Each enum has helper methods like `label()` for Dutch display text.

### Registration & Enrollment

**RegistrationStatus:**
- `Confirmed` - Active enrollment
- `Completed` - Course finished
- `Cancelled` - User withdrew (by user or admin)
- `Withdrawn` - Withdrawn after start
- `Waitlist` - No capacity available
- `Interest` - Pre-registration interest

**AttendanceStatus:**
- `Present` - Attended the session
- `Absent` - Did not attend
- `Excused` - Absence excused (still counts for some completion modes)
- Methods: `countsAsAttended()`, `wasMissed()`, `label()`

### Edition & Session

**EditionStatus:**
- `Open` - Accepting enrollments
- `Full` - Capacity reached
- `Cancelled` - No longer running
- `Postponed` - Delayed indefinitely
- `Announcement` - Coming soon (not yet enrollable)
- `Completed` - Course finished
- Methods: `allowsEnrollment()`, `isActive()`, `label()`

**SessionType:**
- `InPerson` - Physical classroom meeting
- `Webinar` - Live online session (scheduled time)
- `Online` - Self-paced online content (LearnDash lesson)
- `Assignment` - Homework/task with deadline
- Methods: `requiresAttendance()`, `label()`

**CompletionMode:** (how edition completion is evaluated)
- `AttendAll` - Must attend all required sessions
- `Percentage` - Must attend X% of sessions
- `Count` - Must attend at least X sessions
- Methods: `label()`, `description()`

### Trajectory

**TrajectoryStatus:**
- `Draft` - Not yet published
- `Open` - Accepting enrollments
- `InProgress` - Active cohort, no new enrollments
- `Closed` - Completed, archived
- `Archived` - Historical record
- Methods: `allowsEnrollment()`, `isActive()`, `label()`

**TrajectoryMode:**
- `Cohort` - Group-based with fixed deadlines
- `SelfPaced` - User progresses at own pace
- Methods: `label()`, `requiresEditionChoice()`

### Invoicing

**QuoteStatus:**
- `Draft` - Not yet sent
- `Sent` - Sent to customer, awaiting payment
- `Exported` - Sent to Exact Online
- `Cancelled` - No longer valid
- Methods: `isPending()`, `isFinal()`, `label()`

**VoucherStatus:**
- `Active` - Can be used
- `Exhausted` - All uses consumed
- `Expired` - Validity date has passed
- `Disabled` - Manually deactivated
- Methods: `canBeUsed()`, `label()`

**DiscountType:**
- `Full` - 100% discount (free)
- `Fixed` - Fixed amount off (e.g., €50)
- `Percentage` - Percentage off (e.g., 10%)
- Methods: `label()`, `calculate(Money $price, int $value): Money`

### Value Objects

**Money:**
- Stores amounts in cents (int) to avoid float precision
- Supports EUR currency (extensible)
- Immutable - all operations return new instances
- Factory: `Money::eur(45.00)`, `Money::cents(4500)`, `Money::zero()`
- Operations: `add()`, `subtract()`, `multiply()`, `isZero()`, `isGreaterThan()`
- Formatting: `format()` → "€ 45,00"

**DateRange:**
- Represents a start/end date pair
- Factory: `DateRange::from($start, $end)`
- Methods: `contains(DateTime $date)`, `overlaps(DateRange $other)`, `days(): int`

---

## Design Tokens

All theming goes through CSS custom properties. Tailwind reads from these via `theme.extend`.

```css
/* assets/src/css/tokens.css */
:root {
  /* ── Brand ── */
  --color-primary: 29 78 137;       /* Deep blue — trust, healthcare, professional */
  --color-primary-light: 59 130 187;
  --color-primary-dark: 15 52 96;
  --color-accent: 0 148 133;        /* Teal — health, vitality */
  --color-accent-light: 38 186 170;

  /* ── Neutral ── */
  --color-surface: 250 249 247;     /* Warm off-white (not pure white) */
  --color-surface-alt: 243 241 237; /* Slightly darker for alternating sections */
  --color-surface-card: 255 255 255;
  --color-border: 226 222 215;
  --color-text: 41 37 36;           /* Warm charcoal */
  --color-text-muted: 120 113 108;
  --color-text-inverse: 255 255 255;

  /* ── Status ── */
  --color-success: 22 163 74;
  --color-warning: 234 179 8;
  --color-error: 220 38 38;
  --color-info: 59 130 246;

  /* ── Badges ── */
  --color-badge-open: 22 163 74;       /* Green — spots available */
  --color-badge-few: 234 179 8;        /* Yellow — bijna volzet */
  --color-badge-full: 220 38 38;       /* Red — volzet */
  --color-badge-online: 99 102 241;    /* Indigo — online */
  --color-badge-free: 16 185 129;      /* Emerald — gratis */

  /* ── Typography ── */
  --font-sans: 'Inter', system-ui, -apple-system, sans-serif;
  --font-heading: 'Plus Jakarta Sans', var(--font-sans);

  /* ── Spacing scale (used in Tailwind) ── */
  --space-section: 5rem;        /* Between major page sections */
  --space-block: 3rem;          /* Between content blocks */
  --space-element: 1.5rem;      /* Between elements within block */

  /* ── Layout ── */
  --container-max: 1280px;
  --content-max: 768px;         /* For prose/reading width */
  --sidebar-width: 320px;

  /* ── Radius ── */
  --radius-sm: 0.375rem;
  --radius-md: 0.5rem;
  --radius-lg: 0.75rem;
  --radius-xl: 1rem;

  /* ── Shadow ── */
  --shadow-card: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
  --shadow-elevated: 0 4px 6px rgba(0,0,0,0.07), 0 2px 4px rgba(0,0,0,0.06);
  --shadow-overlay: 0 10px 25px rgba(0,0,0,0.12);
}
```

### Tailwind Config Extension

```js
// tailwind.config.js
module.exports = {
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: 'rgb(var(--color-primary) / <alpha-value>)',
          light: 'rgb(var(--color-primary-light) / <alpha-value>)',
          dark: 'rgb(var(--color-primary-dark) / <alpha-value>)',
        },
        accent: {
          DEFAULT: 'rgb(var(--color-accent) / <alpha-value>)',
          light: 'rgb(var(--color-accent-light) / <alpha-value>)',
        },
        surface: {
          DEFAULT: 'rgb(var(--color-surface) / <alpha-value>)',
          alt: 'rgb(var(--color-surface-alt) / <alpha-value>)',
          card: 'rgb(var(--color-surface-card) / <alpha-value>)',
        },
      },
      fontFamily: {
        sans: ['var(--font-sans)'],
        heading: ['var(--font-heading)'],
      },
      maxWidth: {
        content: 'var(--content-max)',
      },
      boxShadow: {
        card: 'var(--shadow-card)',
        elevated: 'var(--shadow-elevated)',
        overlay: 'var(--shadow-overlay)',
      },
    },
  },
}
```

---

## Theme Structure

### Active Theme: Stridence

**Location:** `web/app/themes/stridence/`

**Stack:**
- **CSS:** Tailwind CSS 3.4.1
- **Build:** Vite 5.1.4
- **JavaScript:** Alpine.js 3.13.5
- **PostCSS:** AutoPrefixer for browser compatibility

```
stridence/
├── src/                              # Vite source files
│   └── main.js                       # Entry point
│
├── dist/                             # Vite build output
│   ├── .vite/manifest.json           # Asset manifest for production
│   └── [hashed files]
│
├── templates/                        # PHP templates
│   ├── dashboard/                    # User dashboard pages
│   ├── course/                       # Course-related pages
│   ├── enrollment/                   # Enrollment flow pages
│   ├── trajectory/                   # Trajectory pages
│   └── homepage/                     # Homepage sections
│
├── helpers/                          # PHP helper functions
├── header.php                        # Theme header
├── footer.php                        # Theme footer
├── front-page.php                    # Homepage
├── archive-vad_trajectory.php        # Trajectory archive
├── page-mijn-account.php             # Dashboard page
├── index.php                         # Fallback template
├── functions.php                     # Theme bootstrap & hooks
├── style.css                         # Theme metadata
├── tailwind.config.js                # Tailwind configuration
├── vite.config.js                    # Vite build config
├── postcss.config.js                 # PostCSS config
└── package.json                      # npm dependencies
```

### Legacy Theme: Stride (UIkit-based)

**Location:** `web/app/themes/stride/`

**Stack:**
- **CSS:** UIkit 3.21.6 (CDN)
- **JavaScript:** UIkit + custom stride.js

**Key Files:**
- `theme-config.php` - Configuration for features, image sizes, menus, invoicing
- `services/frontend/` - Dashboard and shortcode services
- `templates/` - Dashboard, course, invoice, email, PDF templates

---

## Pages to Build

### Page Map

```
/                               → Homepage (front-page.php)
/opleidingen/                   → Course Catalog (archive-sfwd-courses.php, combined LD courses + Editions )
/opleidingen/{slug}/            → Course Detail (single-sfwd-courses.php)
/opleidingen/editie/{slug}/     → Edition Detail (single-vad_edition.php) 
/trajecten/                     → Trajectory Catalog (archive-vad_trajectory.php)
/traject/{slug}/                → Trajectory Detail (single-vad_trajectory.php)

/inschrijven/{edition_slug|traject_slug}/    → Enrollment form (handled by shortcode)
/interesse/{edition_slug|traject_slug}/      → Intrest form (handled by shortcode)
/mijn-account/                  → Dashboard (page-mijn-account.php) — requires login
/mijn-account/inschrijvingen/   → My Enrollments 
/mijn-account/offertes/         → My Quotes ( quotes can be updated/cancelled by user )
/mijn-account/certificaten/     → My Certificates
/mijn-account/profiel/          → My Profile ( profile can be updated by user )
```

### Page Specifications

#### 1. Homepage (`front-page.php`)

**Purpose:** Entry point, highlight upcoming courses, build trust.

**Sections (top to bottom):**

| # | Section | Data Source | Alpine? |
|---|---------|------------|---------|
| 1 | Hero | Static content + CTA | No |
| 2 | Quick category links | `stride_domain` taxonomy terms | No |
| 3 | Featured/upcoming editions | `EditionService` | No |
| 4 | Value proposition | 3 columns: accreditatie, praktijkgericht, netwerk | No |
| 5 | Newsletter signup | FluentForms shortcode | No |

**Key template code:**
```php
<?php
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\EditionRepository;

// Section 3: Upcoming editions
$editionRepo = ntdst_get(EditionRepository::class);
$editions = $editionRepo->getUpcoming(6);

foreach ($editions as $edition) :
    $courseId = get_post_meta($edition->ID, '_vad_course_id', true);
    $course = get_post($courseId);
    get_template_part('partials/card', 'edition', [
        'edition' => $edition,
        'course'  => $course,
    ]);
endforeach;
```

#### 2. Course Catalog (`archive-sfwd-courses.php`)

**Purpose:** Browse and filter all available courses/editions.

**Layout:**
```
┌────────────────────────────────────────────┐
│ H1: Ons Aanbod                             │
│                                            │
│ [Alle] [Ouderenzorg] [GGZ] [Eerste lijn]  │  ← Domain tabs
│                                            │
│ Filters: [Formaat ▾] [Locatie ▾] [Prijs ▾] │  ← Alpine dropdowns
│                                            │
│ ┌──────────┐ ┌──────────┐ ┌──────────┐    │
│ │ Course   │ │ Course   │ │ Course   │    │  ← 3-col grid
│ │ Card     │ │ Card     │ │ Card     │    │
│ └──────────┘ └──────────┘ └──────────┘    │
│                                            │
│ [Meer laden]                               │  ← Load more or pagination
└────────────────────────────────────────────┘
```

**Alpine component:**
```html
<div x-data="courseCatalog()" x-init="init()">
  <!-- Domain tabs -->
  <nav class="flex gap-2 mb-6">
    <template x-for="domain in domains">
      <button
        @click="setDomain(domain.slug)"
        :class="activeDomain === domain.slug ? 'bg-primary text-white' : 'bg-surface-alt text-text'"
        class="px-4 py-2 rounded-lg text-sm font-medium transition"
        x-text="domain.name"
      ></button>
    </template>
  </nav>

  <!-- Course grid -->
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <template x-for="course in filteredCourses" :key="course.id">
      <div class="card-course" x-html="course.cardHtml"></div>
    </template>
  </div>
</div>
```

#### 3. Course Detail (`single-sfwd-courses.php`)

**Layout — inspired by MMI pattern:**
```
┌────────────────────────────────────────────┐
│ Breadcrumb: Home > Opleidingen > [Title]   │
│                                            │
│ ┌──────────────────────┐ ┌──────────────┐  │
│ │                      │ │ SIDEBAR      │  │
│ │ H1: Course Title     │ │              │  │
│ │ Domain badge         │ │ Volgende     │  │
│ │ Short description    │ │ editie:      │  │
│ │                      │ │ 15 mrt 2026  │  │
│ │ ┌──────────────────┐ │ │ Antwerpen    │  │
│ │ │ [Overzicht]      │ │ │ € 450       │  │
│ │ │ [Programma]      │ │ │ 12/20 pltsn │  │
│ │ │ [Sprekers]       │ │ │              │  │
│ │ │ [Praktisch]      │ │ │ [Inschrijven]│  │
│ │ │                  │ │ │              │  │
│ │ │ [FAQ]            │ │ │ Accreditatie │  │
│ │ └──────────────────┘ │ │ RIZIV: 12u   │  │
│ └──────────────────────┘ └──────────────┘  │
└────────────────────────────────────────────┘
```

**Sticky sidebar with edition CTA:**
```php
<?php
use Stride\Modules\Edition\EditionRepository;
use Stride\Domain\EditionStatus;

$editionRepo = ntdst_get(EditionRepository::class);
$nextEdition = $editionRepo->getNextOpenForCourse(get_the_ID());

if ($nextEdition) :
    $capacity = (int) get_post_meta($nextEdition->ID, '_vad_capacity', true);
    $registeredCount = $editionRepo->getRegisteredCount($nextEdition->ID);
    $spots = $capacity > 0 ? $capacity - $registeredCount : null;
    $price = get_post_meta($nextEdition->ID, '_vad_price', true);
    $venue = get_post_meta($nextEdition->ID, '_vad_venue', true);
    $startDate = get_post_meta($nextEdition->ID, '_vad_start_date', true);
?>
<aside class="lg:sticky lg:top-24">
  <div class="bg-surface-card rounded-xl shadow-card p-6 space-y-4">
    <h3 class="font-heading font-semibold text-lg">Volgende editie</h3>
    <div class="text-sm space-y-2">
      <p><?= stride_format_date($startDate) ?></p>
      <p><?= esc_html($venue) ?></p>
      <p class="text-2xl font-bold">€ <?= number_format($price / 100, 2, ',', '.') ?></p>
      <?php if ($spots !== null) : ?>
      <p class="text-sm text-text-muted">
        <?= $spots ?> van <?= $capacity ?> plaatsen beschikbaar
      </p>
      <?php endif; ?>
    </div>
    <a href="<?= stride_enrollment_url($nextEdition->ID) ?>"
       class="btn-primary w-full text-center">
      Inschrijven
    </a>
  </div>
</aside>
<?php endif; ?>
```

#### 4. Trajectory Archive (`archive-vad_trajectory.php`)

**Purpose:** Browse learning paths (multi-course programs).

**Data source:**
```php
use Stride\Modules\Trajectory\TrajectoryRepository;

$trajectoryRepo = ntdst_get(TrajectoryRepository::class);
$trajectories = $trajectoryRepo->getActive();
```

#### 5. Dashboard (`page-mijn-account.php`)

**Purpose:** Logged-in user area — enrollments, progress, quotes, certificates. Mobile friendly, this is where enrolled user spent their time

**Tabs:** Mijn inschrijvingen | Mijn offertes | Certificaten | Profiel

**Data source:**
```php
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteRepository;

$registrationRepo = ntdst_get(RegistrationRepository::class);
$quoteRepo = ntdst_get(QuoteRepository::class);

$userId = get_current_user_id();
$registrations = $registrationRepo->getForUser($userId);
$quotes = $quoteRepo->getForUser($userId);
```

---

## Component Library

### Partials Directory Structure

```
partials/
├── card-edition.php          ← Course card with edition info
├── card-course.php           ← Simpler course-only card
├── card-trajectory.php       ← Trajectory card
├── edition-row.php           ← Table-style edition listing
├── badge-status.php          ← open/full/cancelled badges
├── badge-format.php          ← classroom/online/hybrid badges
├── accordion-faq.php         ← FAQ with Alpine x-data
├── sidebar-edition-cta.php   ← Sticky enrollment sidebar
├── course-program.php        ← Session list per edition
├── testimonial-carousel.php  ← Alpine carousel
├── nav-mega-menu.php         ← Main navigation with course categories
├── search-bar.php            ← Course search
└── breadcrumb.php            ← Breadcrumb trail
```

### Edition Card Component (`partials/card-edition.php`)

```php
<?php
/**
 * Edition Card — used on catalog, homepage, agenda
 *
 * @param WP_Post $args['edition']  The edition post
 * @param WP_Post $args['course']   The parent course
 */
use Stride\Domain\EditionStatus;

$edition = $args['edition'] ?? null;
$course  = $args['course'] ?? null;
if (!$edition || !$course) return;

$status = get_post_meta($edition->ID, '_vad_status', true);
$price = get_post_meta($edition->ID, '_vad_price', true);
$venue = get_post_meta($edition->ID, '_vad_venue', true);
$startDate = get_post_meta($edition->ID, '_vad_start_date', true);
$capacity = (int) get_post_meta($edition->ID, '_vad_capacity', true);

// Calculate spots
$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$registeredCount = $editionRepo->getRegisteredCount($edition->ID);
$spots = $capacity > 0 ? $capacity - $registeredCount : null;

$domains = get_the_terms($course->ID, 'stride_domain');
$formats = get_the_terms($course->ID, 'stride_format');
?>

<article class="bg-surface-card rounded-xl shadow-card hover:shadow-elevated transition-shadow duration-200 overflow-hidden flex flex-col">
  <!-- Top: badges -->
  <div class="p-5 pb-0 flex gap-2 flex-wrap">
    <?php if ($domains) : ?>
      <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-primary/10 text-primary">
        <?= esc_html($domains[0]->name) ?>
      </span>
    <?php endif; ?>
    <?php if ($formats) : ?>
      <?php get_template_part('partials/badge', 'format', ['term' => $formats[0]]); ?>
    <?php endif; ?>
  </div>

  <!-- Middle: content -->
  <div class="p-5 flex-1">
    <h3 class="font-heading font-semibold text-lg mb-2 line-clamp-2">
      <a href="<?= get_permalink($course) ?>" class="hover:text-primary transition-colors">
        <?= esc_html($course->post_title) ?>
      </a>
    </h3>
    <div class="text-sm text-text-muted space-y-1">
      <p><?= stride_format_date($startDate) ?></p>
      <?php if ($venue) : ?><p><?= esc_html($venue) ?></p><?php endif; ?>
    </div>
  </div>

  <!-- Bottom: price + status -->
  <div class="px-5 pb-5 flex items-center justify-between border-t border-border pt-4">
    <span class="text-xl font-bold">€ <?= number_format($price / 100, 2, ',', '.') ?></span>
    <?php get_template_part('partials/badge', 'status', ['status' => $status, 'spots' => $spots]); ?>
  </div>
</article>
```

### Status Badge (`partials/badge-status.php`)

```php
<?php
use Stride\Domain\EditionStatus;

$status = $args['status'] ?? EditionStatus::Open->value;
$spots  = $args['spots'] ?? null;

$config = [
  'open'         => ['label' => 'Beschikbaar',          'classes' => 'bg-green-100 text-green-800'],
  'few'          => ['label' => "Nog {$spots} plaatsen", 'classes' => 'bg-yellow-100 text-yellow-800'],
  'full'         => ['label' => 'Volzet',                'classes' => 'bg-red-100 text-red-800'],
  'cancelled'    => ['label' => 'Geannuleerd',           'classes' => 'bg-gray-100 text-gray-500'],
  'postponed'    => ['label' => 'Uitgesteld',            'classes' => 'bg-orange-100 text-orange-700'],
  'announcement' => ['label' => 'Binnenkort',            'classes' => 'bg-blue-100 text-blue-700'],
  'completed'    => ['label' => 'Afgelopen',             'classes' => 'bg-gray-100 text-gray-500'],
];

// Auto-detect "few" status
if ($status === 'open' && $spots !== null && $spots <= 5 && $spots > 0) {
    $status = 'few';
}

$badge = $config[$status] ?? $config['open'];
?>
<span class="text-xs font-medium px-2.5 py-1 rounded-full <?= $badge['classes'] ?>">
  <?= $badge['label'] ?>
</span>
```

---

## Vite Build Configuration

```js
// vite.config.js
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [tailwindcss()],
  build: {
    outDir: 'dist',
    manifest: true,
    rollupOptions: {
      input: {
        main: 'src/main.js',
      },
    },
  },
  server: {
    origin: 'http://localhost:5173',
  },
});
```

### Asset Loading via NTDST Theme

The NTDST Core framework provides configuration-driven asset management through `NTDST_Theme`. Assets are defined in `theme-config.php` and automatically enqueued.

#### Configuration-Driven Approach (theme-config.php)

```php
// theme-config.php
return [
    'assets' => [
        'styles' => [
            'stridence-style' => [
                'src' => fn() => stridence_vite_asset('src/main.js')['css'],
                'deps' => [],
                'version' => null,
                'enabled' => fn() => !stridence_is_vite_dev(),
            ],
        ],
        'scripts' => [
            'vite-client' => [
                'src' => 'http://localhost:5173/@vite/client',
                'deps' => [],
                'in_footer' => false,
                'enabled' => fn() => stridence_is_vite_dev(),
                'attrs' => ['type' => 'module'],
            ],
            'stridence-main' => [
                'src' => fn() => stridence_vite_asset('src/main.js')['js'],
                'deps' => [],
                'in_footer' => true,
                'attrs' => ['defer' => true, 'type' => 'module'],
            ],
            'alpinejs' => [
                'src' => 'https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js',
                'deps' => [],
                'version' => '3',
                'in_footer' => true,
                'attrs' => ['defer' => true],
            ],
        ],
    ],

    // Localized script data for ntdstAPI
    'script_data' => [
        'stridence-main' => [
            'object_name' => 'strideConfig',
            'data' => fn() => [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('stride_frontend'),
                'strings' => [
                    'saving' => __('Opslaan...', 'stride'),
                    'error' => __('Er is een fout opgetreden', 'stride'),
                ],
            ],
        ],
    ],
];
```

#### Vite Helpers (defined in functions.php)

```php
/**
 * Check if Vite dev server is running (cached)
 */
function stridence_is_vite_dev(): bool {
    if (defined('WP_ENV') && WP_ENV === 'production') {
        return false;
    }
    static $result = null;
    return $result ??= @file_get_contents('http://localhost:5173/@vite/client') !== false;
}

/**
 * Get Vite asset URLs from manifest (cached)
 */
function stridence_vite_asset(string $entry): array {
    if (stridence_is_vite_dev()) {
        return [
            'js' => 'http://localhost:5173/' . $entry,
            'css' => null,
        ];
    }

    static $manifest = null;
    $manifest ??= json_decode(
        @file_get_contents(get_theme_file_path('dist/.vite/manifest.json')) ?: '{}',
        true
    );

    $chunk = $manifest[$entry] ?? null;
    if (!$chunk) {
        return ['js' => null, 'css' => null];
    }

    return [
        'js' => get_theme_file_uri('dist/' . $chunk['file']),
        'css' => isset($chunk['css'][0]) ? get_theme_file_uri('dist/' . $chunk['css'][0]) : null,
    ];
}
```

#### Theme Bootstrap (functions.php)

```php
add_action('after_setup_theme', function () {
    $config = require get_stylesheet_directory() . '/theme-config.php';

    // NTDST_Theme handles all asset enqueueing automatically
    $theme = new NTDST_Theme($config);

    // Register for global access
    ntdst_set(NTDST_Theme::class, $theme);
}, 10);

/**
 * Get theme instance
 */
function stride_theme(): ?NTDST_Theme {
    return ntdst_get(NTDST_Theme::class);
}
```

#### Conditional Assets with Theme Fluent API

For page-specific or conditional assets:

```php
add_action('after_setup_theme', function () {
    stride_theme()
        ->on('wp_enqueue_scripts', function () {
            if (is_singular('sfwd-courses')) {
                wp_enqueue_style(
                    'stride-course',
                    get_theme_file_uri('dist/course.css'),
                    ['stridence-style']
                );
            }
        }, 20);
});
```

---

## Service Classes Reference

### EditionService
**Location:** `Stride\Modules\Edition\EditionService`

- Manages scheduled course offerings
- Implements `EditionQueryInterface` for cross-module availability checks

**Key methods:**
- `hasAvailableSpots(editionId): bool`
- `getCapacity(editionId): int`
- `getRegisteredCount(editionId): int`
- `getStatus(editionId): EditionStatus`
- `getCourseId(editionId): ?int`
- `getPrice(editionId, isMember = true): Money`
- `exists(editionId): bool`

### SessionService
**Location:** `Stride\Modules\Edition\SessionService`

- Manages individual meeting days within editions

**Key methods:**
- `getSessionsForEdition(editionId): array`
- `getSessionDuration(sessionId): float` - Hours for single session
- `getTotalHours(editionId): float` - Total contact hours
- `getTotalDurationForSessions(sessionIds): float`

### EnrollmentService
**Location:** `Stride\Modules\Enrollment\EnrollmentService`

- Core enrollment business logic
- Grants LearnDash course access on confirmation
- Handles colleague enrollments (creates users)

**Key methods:**
- `enroll(userId, editionId, options): int|WP_Error` - Create registration
- `cancel(registrationId): bool|WP_Error`
- `isEnrolled(userId, editionId): bool`
- `processEnrollment(data): array|WP_Error` - Main form submission handler
- `resolveParticipant(data): int|WP_Error` - Get/create user for enrollment
- `updateUserProfile(userId, data): void` - Update user meta from form
- `storePendingBilling(userId, data): void` - Store billing data in transient

### EnrollmentRouterService
**Location:** `Stride\Modules\Enrollment\EnrollmentRouterService`

- Clean URL routing for enrollment forms
- Handles: `/trajecten/{slug}/inschrijving/` and `/vormingen/{slug}/inschrijving/`

**Key methods:**
- `getEnrollmentUrl(type, id): string`
- `parseRequest(): ?array` - Extract edition/trajectory from URL

### QuoteService
**Location:** `Stride\Modules\Invoicing\QuoteService`

- Creates, updates, and manages quotes
- Integrates with VoucherService for discount application

**Key methods:**
- `createQuote(registrationId): Quote`
- `applyVoucher(quoteId, code): bool`
- `export(quoteId): bool` - Mark as exported to Exact Online
- `getForUser(userId): array`
- `getForRegistration(registrationId): ?Quote`

### VoucherService
**Location:** `Stride\Modules\Invoicing\VoucherService`

- Creates and validates discount codes
- Supports percentage, fixed, and full discount types (DiscountType enum)

**Key methods:**
- `validateCode(code, editionId = null): Voucher|WP_Error`
- `applyDiscount(quoteId, voucherId): Money`
- `markExhausted(voucherId): void`
- `isValidFor(voucher, editionId): bool`

### TrajectoryService
**Location:** `Stride\Modules\Trajectory\TrajectoryService`

- Manages multi-course learning paths (cohorts)

**Key methods:**
- `getTrajectory(id): ?WP_Post`
- `enrollUser(userId, trajectoryId): int|WP_Error`
- `getEnrolledUsers(trajectoryId): array`
- `getCourseGroups(trajectoryId): array` - Get course groups with pick_count
- `saveElectiveChoices(enrollmentId, choices): bool`
- `lockChoices(enrollmentId): bool`

### AttendanceService
**Location:** `Stride\Modules\Attendance\AttendanceService`

- Tracks session attendance

**Key methods:**
- `markPresent(sessionId, userId, markedBy = null): void`
- `markAbsent(sessionId, userId): void`
- `markExcused(sessionId, userId): void`
- `getAttendance(sessionId): array`
- `getAttendanceForUser(userId, editionId): array`
- `getHoursAttended(userId, editionId): float`

### CompletionService
**Location:** `Stride\Modules\Completion\CompletionService`

- Determines course/edition completion based on CompletionMode

**Key methods:**
- `isComplete(editionId, userId): bool`
- `getProgress(editionId, userId): array` - Returns attended/required counts
- `markComplete(registrationId): void`
- `evaluateCompletion(editionId, userId): bool` - Check and update status

### AuditBridge
**Location:** `Stride\Modules\Audit\AuditBridge`

- Bridges Stride events to NTDST Audit plugin for activity logging

**Events logged:**
- `registration/created` - New enrollment
- `registration/cancelled` - Enrollment cancelled
- `attendance/marked` - Session attendance recorded
- `learndash_course_completed` - LearnDash course completion

### LearnDashAdapter
**Location:** `Stride\Integrations\LearnDash\LearnDashAdapter`

**4 integration points only:**
- `grantAccess(userId, courseId)` - On registration confirmation
- `revokeAccess(userId, courseId)` - On registration cancellation
- `isComplete(userId, courseId): bool` - Check completion
- `getCertificateLink(userId, courseId): ?string` - For user dashboard

---

## Handlers (AJAX/API)

**Location:** `Stride\Handlers\`

All handlers follow the **thin handler pattern:**
- No constructor DI
- Use `ntdst_get()` inside methods for service access
- Register via NTDST API filter: `ntdst/api_data/{action_name}`

### EnrollmentFormHandler
**Actions:**
- `stride_submit_enrollment` - Submit edition/trajectory enrollment
- `stride_validate_voucher` - Validate discount code (public)
- `stride_save_session_selection` - Save selected sessions

### QuoteUpdateHandler
**Actions:**
- `stride_update_quote_status` - Quote status changes (draft → sent → exported)

### EnrollmentQuoteHandler
**Purpose:** Event bridge - automatically creates quote when registration is confirmed.

**Listens to:**
- `stride/registration/confirmed` - Creates quote for new registration

### ProfileHandler
**Actions:**
- `stride_update_profile` - Update user profile data
- Routes: personal info, billing info, notification preferences

### ICalHandler
**Actions:**
- `stride_download_ical` - Generate .ics file for user's sessions
- Returns: iCalendar file with all enrolled sessions

---

## Frontend-Backend Communication (ntdstAPI)

**For any JavaScript that needs to communicate with PHP/backend, use `ntdstAPI`.**

The `ntdstAPI` JavaScript utility is provided by the NTDST Core framework and handles all AJAX communication with WordPress and Stride endpoints. It manages nonces, error handling, and provides a consistent interface.

### Basic Usage

```javascript
// ntdstAPI is globally available after assets are enqueued
ntdstAPI.post('stride_validate_voucher', {
    code: voucherCode,
    edition_id: editionId
})
.then(response => {
    if (response.success) {
        // Handle success
        console.log(response.data);
    } else {
        // Handle error
        console.error(response.data.message);
    }
})
.catch(error => {
    console.error('Request failed:', error);
});
```

### Available Stride Endpoints

The stride-core plugin registers these AJAX actions (see Handlers above):

| Action | Purpose | Auth |
|--------|---------|------|
| `stride_submit_enrollment` | Submit enrollment form | logged-in |
| `stride_validate_voucher` | Validate discount code | public |
| `stride_save_session_selection` | Save session choices | logged-in |
| `stride_update_profile` | Update user profile | logged-in |
| `stride_export_ical` | Generate .ics file | logged-in |

### Alpine.js Integration

When using Alpine.js components that need backend data:

```html
<div x-data="voucherValidator()">
    <input type="text" x-model="code" @blur="validate()">
    <span x-show="discount" x-text="discountText"></span>
    <span x-show="error" class="text-error" x-text="error"></span>
</div>

<script>
function voucherValidator() {
    return {
        code: '',
        discount: null,
        error: null,

        async validate() {
            if (!this.code) return;

            try {
                const response = await ntdstAPI.post('stride_validate_voucher', {
                    code: this.code,
                    edition_id: this.$el.dataset.editionId
                });

                if (response.success) {
                    this.discount = response.data.discount;
                    this.error = null;
                } else {
                    this.discount = null;
                    this.error = response.data.message;
                }
            } catch (e) {
                this.error = 'Verbinding mislukt';
            }
        },

        get discountText() {
            if (!this.discount) return '';
            return `-€ ${(this.discount / 100).toFixed(2).replace('.', ',')}`;
        }
    }
}
</script>
```

### Critical Rules for ntdstAPI

- **Always use `ntdstAPI`** — never use raw `fetch()` or `$.ajax()` for WordPress/Stride endpoints
- **Nonces are handled automatically** — ntdstAPI injects the correct nonce
- **Response format** — always check `response.success` before accessing `response.data`
- **Error messages in Dutch** — backend returns Dutch error messages, display them directly
- **Loading states** — use Alpine's `:disabled` and loading indicators during requests

---

## Helper Functions

The NTDST Core framework provides two patterns for helper functions:

### Pattern 1: Global `stride_*()` Helpers

Following the `ntdst_*()` pattern, define global helper functions in `functions.php`. These provide convenient shortcuts for common operations.

```php
// functions.php - Core helpers

/**
 * Get theme instance
 */
function stride_theme(): ?NTDST_Theme {
    return ntdst_get(NTDST_Theme::class);
}

/**
 * Get service from DI container
 */
function stride_service(string $class): mixed {
    return ntdst_get($class);
}

/**
 * Format date in Dutch (Flemish style)
 */
function stride_format_date(string $date, string $format = 'j F Y'): string {
    $timestamp = strtotime($date);
    $months = ['januari','februari','maart','april','mei','juni',
               'juli','augustus','september','oktober','november','december'];
    $formatted = date($format, $timestamp);
    return str_replace(
        array_map(fn($m) => ucfirst($m), $months),
        $months,
        $formatted
    );
}

/**
 * Format money (cents to EUR display)
 */
function stride_format_money(int $cents): string {
    return '€ ' . number_format($cents / 100, 2, ',', '.');
}

/**
 * Get enrollment URL for an edition
 */
function stride_enrollment_url(int $editionId): string {
    return home_url('/inschrijven/?editie=' . $editionId);
}

/**
 * Get courses by domain taxonomy (cached)
 */
function stride_get_courses_by_domain(int $termId, int $limit = 4): array {
    return ntdst_data()->get('sfwd-courses')
        ->where('stride_domain', $termId)
        ->limit($limit)
        ->all();
}

/**
 * Render inline SVG icon (cached)
 */
function stridence_icon(string $name, string $class = ''): string {
    static $cache = [];
    $key = $name . '|' . $class;

    if (!isset($cache[$key])) {
        $path = get_theme_file_path("icons/{$name}.svg");
        if (!file_exists($path)) {
            $cache[$key] = '';
        } else {
            $svg = file_get_contents($path);
            if ($class) {
                $svg = str_replace('<svg', '<svg class="' . esc_attr($class) . '"', $svg);
            }
            $cache[$key] = $svg;
        }
    }

    return $cache[$key];
}
```

### Pattern 2: Theme Mixin (Method Injection)

For helpers that benefit from theme instance access, use the mixin pattern. This allows `$theme->method()` syntax.

```php
// helpers/StrideHelpers.php
namespace Stride\Helpers;

class StrideHelpers
{
    /**
     * Format date in Dutch
     */
    public function formatDate(string $date, string $format = 'j F Y'): string
    {
        return stride_format_date($date, $format);
    }

    /**
     * Format price
     */
    public function formatMoney(int $cents): string
    {
        return stride_format_money($cents);
    }

    /**
     * Get enrollment URL
     */
    public function enrollmentUrl(int $editionId): string
    {
        return stride_enrollment_url($editionId);
    }

    /**
     * Render SVG icon
     */
    public function icon(string $name, string $class = ''): string
    {
        return stridence_icon($name, $class);
    }
}
```

Register the mixin in `functions.php`:

```php
add_action('after_setup_theme', function () {
    $theme = stride_theme();

    // Pattern 2a: Method injection - all public methods become $theme->method()
    $theme->mixin(new \Stride\Helpers\StrideHelpers());

    // Pattern 2b: Named instance proxying - access as $theme->editions()
    $theme->mixin('editions', ntdst_get(\Stride\Modules\Edition\EditionService::class));
    $theme->mixin('quotes', ntdst_get(\Stride\Modules\Invoicing\QuoteService::class));
}, 20);
```

Usage in templates:

```php
// Global helper
<?= stride_format_date($edition->start_date) ?>

// Theme mixin method
<?= stride_theme()->formatDate($edition->start_date) ?>

// Theme mixin service proxy
<?php $spots = stride_theme()->editions()->getAvailableSpots($editionId); ?>
```

### Which Pattern to Use?

| Use Case | Pattern |
|----------|---------|
| Simple utilities (formatting, URLs) | Global `stride_*()` helpers |
| Template-focused helpers | Theme mixin methods |
| Service shortcuts in templates | Theme mixin service proxies |
| Framework integration (router, data, mail) | Already wired via `ntdst_*()` |

**Best practice:** Define global helpers for commonly used functions, use mixins sparingly for template-specific shortcuts. Always prefer service access via `ntdst_get()` for business logic.

---

## Custom Tables

### Registration Table
**Name:** `wp_vad_registrations`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | bigint | WordPress user (participant) |
| `edition_id` | bigint | NULL if trajectory enrollment |
| `trajectory_id` | bigint | NULL if edition enrollment |
| `status` | varchar | RegistrationStatus: confirmed, completed, cancelled, withdrawn, waitlist |
| `enrollment_path` | varchar | individual, colleague, trajectory |
| `selections` | JSON | Session IDs (edition) or elective course IDs (trajectory) |
| `selections_locked_at` | datetime | When selections were locked |
| `quote_id` | bigint | Associated quote |
| `enrolled_by` | bigint | User who created enrollment (for colleague enrollments) |
| `registered_at` | datetime | Registration timestamp |
| `completed_at` | datetime | Completion timestamp |
| `cancelled_at` | datetime | Cancellation timestamp |
| `notes` | text | Registration notes |

**Indexes:** `user_id`, `edition_id`, `trajectory_id`, `status`

### Attendance Table
**Name:** `wp_vad_attendance`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Primary key |
| `user_id` | bigint | Attendee |
| `session_id` | bigint | Session attended |
| `edition_id` | bigint | Edition reference (denormalized for queries) |
| `status` | varchar | AttendanceStatus: present, absent, excused |
| `marked_at` | datetime | When attendance was recorded |
| `marked_by` | bigint | User who marked attendance (admin/instructor) |

**Indexes:** `user_id`, `session_id`, `edition_id`

---

## Development Workflow

### Local Development
```bash
cd /home/ntdst/Sites/stride
ddev start
ddev launch           # Open site in browser
```

### Theme Development (Stridence)
```bash
cd web/app/themes/stridence
npm run dev           # Start Vite dev server (localhost:5173)
npm run build         # Build for production
```

### WP-CLI Commands
```bash
ddev exec wp plugin list
ddev exec wp cache flush
```

### Seed/Unseed Development Data
```bash
# Seed the database with test data
ddev exec wp eval-file scripts/seed.php

# Remove all seed data
ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'
```

Test credentials after seeding:
- All seed users have password: `seedpass123`
- Admin: `seed_admin@seed.test`
- Students: `seed_student1@seed.test` through `seed_student5@seed.test`

---

## Critical Rules

- **Never inline Stride service calls in template loops without caching** — use repository methods
- **All dates in Dutch** — use `stride_format_date()`, never raw PHP `date()`
- **All user-facing text in Dutch** — labels, buttons, empty states, error messages
- **Alpine for UI state only** — menus, tabs, filters, accordions. No business logic in JS.
- **Server-render first** — pages must work without JS. Alpine enhances, not replaces.
- **Forms handles enrollment** — the theme never processes enrollment directly
- **Edition is the enrollable unit** — users enroll in editions, not courses
- **Status badges are dynamic** — auto-calculate from spots remaining (open → few → full)
- **Courses/editions in lists are always sorted** — sorting in meaningfull way, like agenda or last enrolled
- **Money in cents** — all prices stored as integers in cents, format on display
- **Use ntdst_get() with full class names** — e.g., `ntdst_get(\Stride\Modules\Edition\EditionService::class)`
