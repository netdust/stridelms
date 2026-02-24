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

## LearnDash Integration Boundary

Stride themes LearnDash — it does not replace it. LearnDash handles online course content delivery (lessons, topics, quizzes). Stride handles everything around enrollment, editions, sessions, attendance, and invoicing.

### Template Ownership Map

| Page | Template | Owner | Notes |
|------|----------|-------|-------|
| Course archive | `archive-sfwd-courses.php` | **Stride theme** | Fully custom — merges LD courses with edition data |
| Course detail | `single-sfwd-courses.php` | **Stride theme** | Custom layout with LD content area + edition sidebar |
| Lesson page | `single-sfwd-lessons.php` | **LearnDash** | Styled via theme CSS only — no structural override |
| Topic page | `single-sfwd-topic.php` | **LearnDash** | Styled via theme CSS only |
| Quiz page | `single-sfwd-quiz.php` | **LearnDash** | Styled via theme CSS only |
| Course navigation | LD sidebar/content | **LearnDash** | Focus bar and course navigation are LD defaults, themed |
| Edition detail | `single-vad_edition.php` | **Stride theme** | Fully custom |
| Trajectory pages | `archive/single-vad_trajectory.php` | **Stride theme** | Fully custom |
| Dashboard | `page-mijn-account.php` | **Stride theme** | Fully custom — no LD profile page |
| Enrollment form | Shortcode page | **Stride theme** | FluentForms with custom rendering |
| Certificates | LD certificate system | **LearnDash** | Accessed via Stride dashboard, rendered by LD |

### LearnDash Content Integration in Course Detail

The course detail page wraps LearnDash content inside the Stride layout:

```php
// single-sfwd-courses.php — main content area
<div class="course-content prose max-w-content">
    <?php
    // LearnDash renders: description, curriculum, materials, prerequisites
    // This outputs LD's standard course content (lesson list, progress bar, etc.)
    the_content();
    ?>
</div>
```

**What LearnDash renders inside `the_content()`:**
- Course description (post content)
- Lesson/topic list with expand/collapse
- Progress bar (for enrolled users)
- Course materials (downloads)
- Prerequisites notice
- "Take this course" button (for LD-managed enrollment — online courses only)

**What Stride adds around it:**
- Page layout (header, breadcrumb, sidebar)
- Edition sidebar with dates, venue, price, enrollment CTA
- Tab navigation (Overzicht, Programma, Sprekers, Praktisch)
- Related courses section

### LearnDash CSS Theming

Style LD components without structural overrides. Target LD's default classes:

```css
/* src/css/learndash.css — imported in main.js */

/* Course content area */
.learndash-wrapper {
  @apply text-text;
}

/* Lesson list */
.learndash-wrapper .ld-lesson-list .ld-lesson-item {
  @apply border-border rounded-lg mb-2;
}

/* Progress bar */
.learndash-wrapper .ld-progress .ld-progress-bar {
  @apply bg-accent rounded-full;
}

/* Quiz elements */
.learndash-wrapper .wpProQuiz_content {
  @apply bg-surface-card rounded-xl p-6 shadow-card;
}

/* LD buttons — match Stride style */
.learndash-wrapper .ld-button,
.learndash-wrapper input[type="submit"] {
  @apply bg-primary text-white font-medium px-6 py-3 rounded-lg
         hover:bg-primary-dark transition-colors;
}

/* Focus mode header bar */
.learndash-wrapper .ld-focus .ld-focus-header {
  @apply bg-primary-dark;
}
```

### What NOT to Override

- LearnDash's focus mode navigation (lesson sidebar in focus mode)
- Quiz logic and question rendering
- Course progress tracking internals
- Drip content scheduling
- Certificate generation (PDF rendering)
- LD's REST API responses

**Rule:** If LearnDash provides a working UI for content consumption, style it — don't rebuild it. Stride's value is in everything *around* the content: enrollment, scheduling, attendance, invoicing.

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

  /* ── Transitions ── */
  --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
  --ease-in-out: cubic-bezier(0.45, 0, 0.55, 1);
  --duration-fast: 150ms;
  --duration-normal: 250ms;
  --duration-slow: 400ms;
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
      transitionTimingFunction: {
        'out': 'var(--ease-out)',
        'in-out': 'var(--ease-in-out)',
      },
    },
  },
}
```

---

## Responsive Strategy

### Breakpoints

Follow Tailwind defaults. Design mobile-first — base styles are mobile, progressively enhance.

| Breakpoint | Width | Layout context |
|------------|-------|----------------|
| Base | < 640px | Single column, stacked cards, bottom nav on dashboard |
| `sm` | ≥ 640px | Minor adjustments (wider padding, 2-col where helpful) |
| `md` | ≥ 768px | 2-column grids, sidebar starts appearing |
| `lg` | ≥ 1024px | Full layout — sidebar visible, 3-col grids, sticky elements |
| `xl` | ≥ 1280px | Max container width reached, content centered |

### Layout Patterns per Page

**Course Catalog:**
- Base: 1-col card stack, filters in collapsible sheet
- `md`: 2-col grid, filters visible
- `lg`: 3-col grid, domain tabs + filter bar

**Course Detail:**
- Base: Single column — content first, then edition CTA as sticky bottom bar
- `lg`: 2-col — content left + sticky sidebar right

**Dashboard:**
- Base: Bottom tab bar (fixed), single-column content
- `lg`: Side navigation (left rail), content fills remaining width

**Enrollment Form:**
- Always single column, max-width `--content-max` (768px), centered
- Step indicator adapts: horizontal on `md`+, vertical/compact on mobile

### Mobile-Specific Patterns

**Sticky Bottom CTA Bar (course detail, mobile):**
```html
<!-- Visible on mobile only when sidebar is not visible -->
<div class="fixed bottom-0 inset-x-0 bg-surface-card border-t border-border p-4 lg:hidden z-40">
  <div class="flex items-center justify-between max-w-content mx-auto">
    <div>
      <p class="text-lg font-bold"><?= stride_format_money($price) ?></p>
      <p class="text-xs text-text-muted"><?= stride_format_date($startDate) ?></p>
    </div>
    <a href="<?= stride_enrollment_url($editionId) ?>" class="btn-primary">
      Inschrijven
    </a>
  </div>
</div>
```

**Dashboard Bottom Tab Bar (mobile):**
```html
<nav class="fixed bottom-0 inset-x-0 bg-surface-card border-t border-border lg:hidden z-40">
  <div class="flex justify-around py-2">
    <a href="?tab=inschrijvingen" class="flex flex-col items-center gap-1 text-xs px-3 py-1">
      <?= stridence_icon('calendar', 'w-5 h-5') ?>
      <span>Opleidingen</span>
    </a>
    <a href="?tab=offertes" class="flex flex-col items-center gap-1 text-xs px-3 py-1">
      <?= stridence_icon('receipt', 'w-5 h-5') ?>
      <span>Offertes</span>
    </a>
    <a href="?tab=certificaten" class="flex flex-col items-center gap-1 text-xs px-3 py-1">
      <?= stridence_icon('award', 'w-5 h-5') ?>
      <span>Certificaten</span>
    </a>
    <a href="?tab=profiel" class="flex flex-col items-center gap-1 text-xs px-3 py-1">
      <?= stridence_icon('user', 'w-5 h-5') ?>
      <span>Profiel</span>
    </a>
  </div>
</nav>
```

**Filter Sheet (catalog, mobile):**
Filters collapse into a bottom sheet triggered by a "Filter" button. Uses Alpine `x-show` with slide-up transition. Overlay behind it. Scroll-locked body.

---

## Interaction Patterns

### URL State for Tabs and Filters

All tab and filter states are reflected in the URL using query parameters. This makes views bookmarkable, shareable, and back-button friendly.

```
/mijn-account/?tab=inschrijvingen
/mijn-account/?tab=offertes
/opleidingen/?domein=ouderenzorg&formaat=webinar
```

**Alpine implementation:**
```javascript
function dashboardTabs() {
    return {
        activeTab: new URLSearchParams(window.location.search).get('tab') || 'inschrijvingen',

        setTab(tab) {
            this.activeTab = tab;
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            history.pushState({}, '', url);
        },

        init() {
            window.addEventListener('popstate', () => {
                this.activeTab = new URLSearchParams(window.location.search).get('tab') || 'inschrijvingen';
            });
        }
    }
}
```

### Confirmation Dialogs

Destructive actions (cancel enrollment, cancel quote) use an inline confirmation pattern — not browser `confirm()` or a full modal. The action button transforms into a confirm/cancel pair.

```html
<div x-data="{ confirming: false }">
  <button
    x-show="!confirming"
    @click="confirming = true"
    class="text-sm text-error hover:underline"
  >
    Annuleren
  </button>
  <div x-show="confirming" x-transition class="flex items-center gap-2">
    <span class="text-sm text-text-muted">Zeker weten?</span>
    <button @click="cancelEnrollment()" class="text-sm font-medium text-error">Ja, annuleer</button>
    <button @click="confirming = false" class="text-sm text-text-muted">Nee</button>
  </div>
</div>
```

### Toast Notifications

For AJAX actions (profile save, voucher validation, session selection), show a temporary toast at the bottom-center. Auto-dismiss after 4 seconds. Max 1 toast visible at a time.

```html
<!-- In footer.php — global toast container -->
<div
  x-data="toastStore()"
  @toast.window="show($event.detail)"
  class="fixed bottom-20 lg:bottom-6 inset-x-0 flex justify-center z-50 pointer-events-none"
>
  <div
    x-show="visible"
    x-transition:enter="transition ease-out duration-normal"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-fast"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    :class="type === 'error' ? 'bg-error' : 'bg-primary-dark'"
    class="text-white text-sm px-5 py-3 rounded-lg shadow-overlay pointer-events-auto"
    x-text="message"
  ></div>
</div>

<script>
function toastStore() {
    return {
        visible: false,
        message: '',
        type: 'success',
        timeout: null,

        show({ message, type = 'success' }) {
            clearTimeout(this.timeout);
            this.message = message;
            this.type = type;
            this.visible = true;
            this.timeout = setTimeout(() => this.visible = false, 4000);
        }
    }
}
</script>
```

**Dispatching from anywhere:**
```javascript
this.$dispatch('toast', { message: 'Profiel opgeslagen', type: 'success' });
this.$dispatch('toast', { message: 'Kortingscode ongeldig', type: 'error' });
```

### Loading States in Alpine

All AJAX-driven actions follow a consistent loading pattern:

```javascript
{
    loading: false,

    async doAction() {
        this.loading = true;
        try {
            const response = await ntdstAPI.post('stride_action', { ... });
            if (response.success) {
                this.$dispatch('toast', { message: 'Gelukt!' });
            } else {
                this.$dispatch('toast', { message: response.data.message, type: 'error' });
            }
        } catch (e) {
            this.$dispatch('toast', { message: 'Verbinding mislukt. Probeer opnieuw.', type: 'error' });
        } finally {
            this.loading = false;
        }
    }
}
```

Buttons during loading:
```html
<button @click="doAction()" :disabled="loading" class="btn-primary">
  <span x-show="!loading">Opslaan</span>
  <span x-show="loading" class="flex items-center gap-2">
    <svg class="animate-spin h-4 w-4" ...></svg>
    Opslaan...
  </span>
</button>
```

### Expand/Collapse Inline Details

Enrollment cards in the dashboard expand inline to show session details, attendance, and actions. No page navigation for viewing enrollment details.

```html
<div x-data="{ open: false }">
  <button @click="open = !open" class="w-full text-left">
    <!-- Card summary row -->
  </button>
  <div x-show="open" x-collapse>
    <!-- Expanded detail: sessions, attendance, actions -->
  </div>
</div>
```

---

## Animation & Transition Tokens

Keep animations subtle and purposeful. Healthcare professionals are task-oriented — motion should aid comprehension, not decorate.

### Standard Transitions (Tailwind utilities)

```css
/* Applied via utility classes */
.transition-card     { @apply transition-shadow duration-normal ease-out; }
.transition-color    { @apply transition-colors duration-fast ease-out; }
.transition-reveal   { @apply transition-all duration-normal ease-out; }
```

### Alpine Transition Presets

Used consistently across all Alpine `x-show` / `x-transition` elements:

| Pattern | Use | Transition |
|---------|-----|------------|
| Fade | Toasts, overlays, badges | `opacity-0 → opacity-100`, 150ms |
| Slide-up | Bottom sheets, mobile filters | `translate-y-4 opacity-0 → translate-y-0 opacity-100`, 250ms |
| Collapse | Accordion, card expand | `x-collapse` (Alpine plugin), automatic height |
| Scale-fade | Dropdown menus | `scale-95 opacity-0 → scale-100 opacity-100`, 150ms |

### Where NOT to Animate

- Page-to-page navigation (no Barba.js or SPA transitions — keep it server-rendered)
- Data table sorting/filtering (instant update, no transition)
- Form step changes (instant swap, scroll to top)
- Progress bar changes (CSS `transition` on width is fine, no JavaScript animation)

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
│   ├── main.js                       # Entry point — imports CSS, Alpine, components
│   └── css/
│       ├── tokens.css                # Design tokens (custom properties)
│       ├── base.css                  # Reset, typography, prose defaults
│       ├── components.css            # btn-primary, card, badge utility classes
│       └── learndash.css             # LearnDash styling overrides
│
├── dist/                             # Vite build output
│   ├── .vite/manifest.json           # Asset manifest for production
│   └── [hashed files]
│
├── templates/                        # PHP templates
│   ├── dashboard/                    # User dashboard pages
│   │   ├── tab-inschrijvingen.php    # Enrollment list + detail
│   │   ├── tab-offertes.php          # Quote management
│   │   ├── tab-certificaten.php      # Certificate downloads
│   │   ├── tab-profiel.php           # Profile editing
│   │   └── partial-session-list.php  # Reusable session/attendance display
│   ├── course/                       # Course-related pages
│   ├── enrollment/                   # Enrollment flow pages
│   ├── trajectory/                   # Trajectory pages
│   └── homepage/                     # Homepage sections
│
├── partials/                         # Reusable components
│   ├── card-edition.php
│   ├── card-course.php
│   ├── card-trajectory.php
│   ├── edition-row.php
│   ├── badge-status.php
│   ├── badge-format.php
│   ├── accordion-faq.php
│   ├── sidebar-edition-cta.php
│   ├── course-program.php
│   ├── testimonial-carousel.php
│   ├── nav-mega-menu.php
│   ├── search-bar.php
│   ├── breadcrumb.php
│   ├── empty-state.php              # Reusable empty state
│   └── toast.php                     # Global toast notification
│
├── icons/                            # Inline SVG icons
│   ├── calendar.svg
│   ├── map-pin.svg
│   ├── users.svg
│   ├── receipt.svg
│   ├── award.svg
│   ├── user.svg
│   ├── clock.svg
│   ├── check-circle.svg
│   ├── x-circle.svg
│   ├── download.svg
│   ├── external-link.svg
│   └── chevron-down.svg
│
├── helpers/                          # PHP helper functions
│   └── StrideHelpers.php
├── header.php
├── footer.php
├── front-page.php
├── single-sfwd-courses.php
├── archive-sfwd-courses.php
├── single-vad_edition.php
├── archive-vad_trajectory.php
├── single-vad_trajectory.php
├── page-mijn-account.php
├── 404.php
├── search.php
├── index.php
├── functions.php
├── style.css
├── theme-config.php
├── tailwind.config.js
├── vite.config.js
├── postcss.config.js
└── package.json
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

## Navigation & Information Architecture

### User Journeys

**Journey 1: "I need a specific training" (search-driven)**
```
Homepage → Search bar → Results → Course detail → Edition sidebar → Enroll
```

**Journey 2: "What's available in my domain?" (browse-driven)**
```
Homepage → Quick category link (e.g., "Ouderenzorg") → Filtered catalog → Course detail → Enroll
```

**Journey 3: "My employer told me to enroll in X" (direct)**
```
Direct URL to course/edition → Edition sidebar → Enroll (often as 'werknemer')
```

**Journey 4: "I'm enrolled, when is my next session?" (returning user)**
```
Login → Dashboard (lands on Mijn Inschrijvingen) → Expand enrollment → See sessions
```

**Journey 5: "I need my certificate for accreditation" (returning user)**
```
Login → Dashboard → Certificaten tab → Download PDF
```

### Main Navigation

```
┌──────────────────────────────────────────────────────────────┐
│ [Logo]   Opleidingen ▾   Trajecten   Over ons   [Zoeken] [👤]│
└──────────────────────────────────────────────────────────────┘
```

**"Opleidingen" mega menu** (on hover/click):
```
┌─────────────────────────────────────────────────────────────┐
│  Per domein                    │  Per formaat               │
│  ─────────                     │  ──────────                │
│  Ouderenzorg                   │  Meerdaagse opleiding      │
│  Geestelijke gezondheidszorg   │  Studiedag                 │
│  Eerste lijn                   │  Webinar                   │
│  Ziekenhuiszorg                │  E-learning                │
│                                │                            │
│  → Bekijk alle opleidingen     │  → Agenda                  │
└─────────────────────────────────────────────────────────────┘
```

Data source: `stride_domain` and `stride_format` taxonomy terms.

**User menu** (logged in — icon top-right):
```
┌─────────────────┐
│ [Name]          │
│ ────────        │
│ Mijn account    │
│ Mijn offertes   │
│ ────────        │
│ Uitloggen       │
└─────────────────┘
```

**User menu** (not logged in):
```
┌─────────────────┐
│ Inloggen        │
│ Registreren     │
└─────────────────┘
```

### Search

Course search uses a text input that filters on course title and excerpt. Server-rendered results page (`search.php`) using WordPress default search, scoped to `sfwd-courses` post type.

```php
// search.php
$args = [
    'post_type'      => 'sfwd-courses',
    's'              => get_search_query(),
    'posts_per_page' => 12,
];
$query = new WP_Query($args);
```

For instant search (type-ahead), use a lightweight Alpine component that hits the WP REST API:

```html
<div x-data="courseSearch()" class="relative">
  <input type="text" x-model="query" @input.debounce.300ms="search()"
         placeholder="Zoek een opleiding..." class="input-search">
  <div x-show="results.length > 0" x-transition class="absolute top-full mt-1 ...">
    <template x-for="course in results" :key="course.id">
      <a :href="course.link" class="block px-4 py-3 hover:bg-surface-alt" x-text="course.title"></a>
    </template>
  </div>
</div>
```

---

## Pages to Build

### Page Map

```
/                               → Homepage (front-page.php)
/opleidingen/                   → Course Catalog (archive-sfwd-courses.php, combined LD courses + Editions)
/opleidingen/{slug}/            → Course Detail (single-sfwd-courses.php)
/opleidingen/editie/{slug}/     → Edition Detail (single-vad_edition.php)
/trajecten/                     → Trajectory Catalog (archive-vad_trajectory.php)
/traject/{slug}/                → Trajectory Detail (single-vad_trajectory.php)

/inschrijven/{edition_slug|traject_slug}/    → Enrollment form (handled by shortcode)
/interesse/{edition_slug|traject_slug}/      → Interest form (handled by shortcode)
/mijn-account/                  → Dashboard (page-mijn-account.php) — requires login
/mijn-account/?tab=inschrijvingen  → My Enrollments
/mijn-account/?tab=offertes     → My Quotes (quotes can be updated/cancelled by user)
/mijn-account/?tab=certificaten → My Certificates
/mijn-account/?tab=profiel      → My Profile (profile can be updated by user)

/zoeken/?s={query}              → Search Results (search.php)
/* 404 */                       → Not Found (404.php)
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

**Empty state:** If no upcoming editions, show a friendly message: "Er zijn momenteel geen geplande opleidingen. Schrijf je in voor de nieuwsbrief om op de hoogte te blijven."

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

**Mobile layout:**
```
┌──────────────────────────┐
│ H1: Ons Aanbod           │
│                          │
│ [Alle] [Ouderenzorg] ... │  ← Horizontal scroll tabs
│                          │
│ [Filter ▾]               │  ← Opens bottom sheet
│                          │
│ ┌──────────────────────┐ │
│ │ Course Card          │ │  ← Single column
│ └──────────────────────┘ │
│ ┌──────────────────────┐ │
│ │ Course Card          │ │
│ └──────────────────────┘ │
│                          │
│ [Meer laden]             │
└──────────────────────────┘
```

**Alpine component:**
```html
<div x-data="courseCatalog()" x-init="init()">
  <!-- Domain tabs -->
  <nav class="flex gap-2 mb-6 overflow-x-auto pb-2 -mx-4 px-4 lg:mx-0 lg:px-0 lg:overflow-visible">
    <template x-for="domain in domains">
      <button
        @click="setDomain(domain.slug)"
        :class="activeDomain === domain.slug ? 'bg-primary text-white' : 'bg-surface-alt text-text'"
        class="px-4 py-2 rounded-lg text-sm font-medium transition-color whitespace-nowrap"
        x-text="domain.name"
      ></button>
    </template>
  </nav>

  <!-- Results count -->
  <p class="text-sm text-text-muted mb-4">
    <span x-text="filteredCourses.length"></span> opleidingen gevonden
  </p>

  <!-- Course grid -->
  <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
    <template x-for="course in filteredCourses" :key="course.id">
      <div class="card-course" x-html="course.cardHtml"></div>
    </template>
  </div>

  <!-- Empty state -->
  <div x-show="filteredCourses.length === 0" class="text-center py-16">
    <p class="text-text-muted text-lg mb-2">Geen opleidingen gevonden</p>
    <p class="text-sm text-text-muted">Probeer andere filters of bekijk <a href="/opleidingen/" class="text-primary underline">alle opleidingen</a>.</p>
  </div>
</div>
```

**Sort order:** By default, courses are sorted by next edition start date (soonest first). Courses without an open edition appear last. This is the "agenda" sort.

#### 3. Course Detail (`single-sfwd-courses.php`)

**Layout:**
```
┌────────────────────────────────────────────────────┐
│ Breadcrumb: Home > Opleidingen > [Title]           │
│                                                    │
│ ┌──────────────────────────┐ ┌──────────────────┐  │
│ │                          │ │ SIDEBAR           │  │
│ │ H1: Course Title         │ │                   │  │
│ │ Domain badge             │ │ Volgende editie:  │  │
│ │ Short description        │ │ 15 mrt 2026      │  │
│ │                          │ │ Antwerpen         │  │
│ │ ┌──────────────────────┐ │ │ € 450            │  │
│ │ │ [Overzicht]          │ │ │ 12/20 plaatsen   │  │
│ │ │ [Programma]          │ │ │                   │  │
│ │ │ [Sprekers]           │ │ │ [Inschrijven]    │  │
│ │ │ [Praktisch]          │ │ │                   │  │
│ │ │                      │ │ │ ── Meer edities ──│  │
│ │ │ LearnDash content    │ │ │ 22 apr - Gent     │  │
│ │ │ (the_content())      │ │ │ 10 mei - Online   │  │
│ │ │                      │ │ │                   │  │
│ │ │ [FAQ]                │ │ │ Accreditatie      │  │
│ │ └──────────────────────┘ │ │ RIZIV: 12u        │  │
│ └──────────────────────────┘ └──────────────────┘  │
│                                                    │
│ ── Gerelateerde opleidingen ─────────────────────  │
│ ┌──────────┐ ┌──────────┐ ┌──────────┐            │
│ │ Card     │ │ Card     │ │ Card     │            │
│ └──────────┘ └──────────┘ └──────────┘            │
└────────────────────────────────────────────────────┘
```

**Mobile layout:** Content first (full width), then edition CTA as sticky bottom bar. No sidebar — edition info is part of the main flow before LearnDash content, plus the sticky bar.

**Tab navigation within course content:**
Tabs are anchor-based (scroll to section), not content-swapping. Each tab scrolls to its `<section id="...">`. Active tab highlights based on scroll position using Alpine + Intersection Observer.

**Sticky sidebar with edition CTA:**
```php
<?php
use Stride\Modules\Edition\EditionRepository;

$editionRepo = ntdst_get(EditionRepository::class);
$nextEdition = $editionRepo->getNextOpenForCourse(get_the_ID());
$allEditions = $editionRepo->getOpenForCourse(get_the_ID());

if ($nextEdition) :
    $capacity = (int) get_post_meta($nextEdition->ID, '_vad_capacity', true);
    $registeredCount = $editionRepo->getRegisteredCount($nextEdition->ID);
    $spots = $capacity > 0 ? $capacity - $registeredCount : null;
    $price = get_post_meta($nextEdition->ID, '_vad_price', true);
    $venue = get_post_meta($nextEdition->ID, '_vad_venue', true);
    $startDate = get_post_meta($nextEdition->ID, '_vad_start_date', true);
?>
<aside class="hidden lg:block lg:sticky lg:top-24 self-start">
  <div class="bg-surface-card rounded-xl shadow-card p-6 space-y-4">
    <h3 class="font-heading font-semibold text-lg">Volgende editie</h3>
    <div class="text-sm space-y-2">
      <p class="flex items-center gap-2">
        <?= stridence_icon('calendar', 'w-4 h-4 text-text-muted') ?>
        <?= stride_format_date($startDate) ?>
      </p>
      <p class="flex items-center gap-2">
        <?= stridence_icon('map-pin', 'w-4 h-4 text-text-muted') ?>
        <?= esc_html($venue) ?>
      </p>
      <p class="text-2xl font-bold mt-3"><?= stride_format_money($price) ?></p>
      <?php if ($spots !== null) : ?>
      <p class="text-sm text-text-muted">
        <?= $spots ?> van <?= $capacity ?> plaatsen beschikbaar
      </p>
      <?php endif; ?>
    </div>
    <a href="<?= stride_enrollment_url($nextEdition->ID) ?>"
       class="btn-primary w-full text-center block">
      Inschrijven
    </a>

    <?php if (count($allEditions) > 1) : ?>
    <div class="border-t border-border pt-4 mt-4">
      <p class="text-xs font-medium text-text-muted uppercase tracking-wide mb-3">Andere edities</p>
      <?php foreach (array_slice($allEditions, 1, 3) as $alt) : ?>
        <a href="<?= get_permalink($alt) ?>" class="block py-2 text-sm hover:text-primary transition-color">
          <?= stride_format_date(get_post_meta($alt->ID, '_vad_start_date', true)) ?>
          — <?= esc_html(get_post_meta($alt->ID, '_vad_venue', true)) ?>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</aside>
<?php endif; ?>
```

**No edition available:** If no open edition exists, sidebar shows:
```html
<aside class="...">
  <div class="bg-surface-card rounded-xl shadow-card p-6 text-center">
    <p class="text-text-muted mb-3">Momenteel geen geplande editie</p>
    <a href="<?= stride_interest_url(get_the_ID()) ?>" class="btn-secondary w-full text-center block">
      Ik heb interesse
    </a>
    <p class="text-xs text-text-muted mt-2">We laten je weten wanneer een nieuwe editie gepland wordt.</p>
  </div>
</aside>
```

#### 4. Trajectory Archive (`archive-vad_trajectory.php`)

**Purpose:** Browse learning paths (multi-course programs).

**Layout:** Similar to course catalog but simpler — no filtering needed (typically fewer trajectories). Cards show: title, short description, number of courses, enrollment deadline, price.

**Data source:**
```php
use Stride\Modules\Trajectory\TrajectoryRepository;

$trajectoryRepo = ntdst_get(TrajectoryRepository::class);
$trajectories = $trajectoryRepo->getActive();
```

**Sort order:** Active trajectories with soonest enrollment deadline first.

#### 5. Trajectory Detail (`single-vad_trajectory.php`)

**Layout:**
```
┌─────────────────────────────────────────────────┐
│ Breadcrumb: Home > Trajecten > [Title]          │
│                                                 │
│ H1: Trajectory Title                            │
│ Status badge + Enrollment deadline              │
│                                                 │
│ Description (prose)                             │
│                                                 │
│ ── Cursussen in dit traject ─────────────────   │
│                                                 │
│ Groep 1: Verplichte vakken                      │
│ ┌──────────┐ ┌──────────┐ ┌──────────┐         │
│ │ Course   │ │ Course   │ │ Course   │         │
│ └──────────┘ └──────────┘ └──────────┘         │
│                                                 │
│ Groep 2: Keuze (kies 2 van 5)                   │
│ ┌──────────┐ ┌──────────┐ ┌──────────┐         │
│ │ Course   │ │ Course   │ │ Course   │         │
│ └──────────┘ └──────────┘ └──────────┘         │
│ ┌──────────┐ ┌──────────┐                       │
│ │ Course   │ │ Course   │                       │
│ └──────────┘ └──────────┘                       │
│                                                 │
│ ── Praktisch ────────────────────────────────   │
│ Prijs: € 1.250                                  │
│ Deadline: 15 maart 2026                         │
│ Duur: 6 maanden                                 │
│                                                 │
│ [Inschrijven voor dit traject]                   │
└─────────────────────────────────────────────────┘
```

#### 6. Dashboard (`page-mijn-account.php`)

**Purpose:** Logged-in user area — enrollments, progress, quotes, certificates. Mobile-friendly. This is where enrolled users spend their time.

**Requires:** `is_user_logged_in()` — redirect to login if not authenticated.

**Layout:**
```
Desktop (lg+):
┌────────────────────────────────────────────────────┐
│ Header                                             │
├──────────┬─────────────────────────────────────────┤
│ Left     │                                         │
│ Rail     │  Tab Content Area                       │
│          │                                         │
│ [User]   │  (renders active tab)                   │
│ ──────   │                                         │
│ Opl.     │                                         │
│ Offert.  │                                         │
│ Cert.    │                                         │
│ Profiel  │                                         │
│          │                                         │
│          │                                         │
│ ──────   │                                         │
│ Uitlog.  │                                         │
├──────────┴─────────────────────────────────────────┤
│ Footer                                             │
└────────────────────────────────────────────────────┘

Mobile:
┌──────────────────────────┐
│ Header                   │
├──────────────────────────┤
│                          │
│  Welcome bar             │
│                          │
│  Tab Content Area        │
│  (full width)            │
│                          │
│                          │
│                          │
├──────────────────────────┤
│ [Opl] [Off] [Cert] [Pro]│  ← Fixed bottom tab bar
└──────────────────────────┘
```

**Data sources:**
```php
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteRepository;
use Stride\Modules\Attendance\AttendanceService;
use Stride\Modules\Completion\CompletionService;
use Stride\Integrations\LearnDash\LearnDashAdapter;

$userId = get_current_user_id();
$registrationRepo = ntdst_get(RegistrationRepository::class);
$quoteRepo = ntdst_get(QuoteRepository::class);
$attendanceService = ntdst_get(AttendanceService::class);
$completionService = ntdst_get(CompletionService::class);
$ldAdapter = ntdst_get(LearnDashAdapter::class);
```

**Left rail navigation (desktop):**
```php
<nav class="hidden lg:flex flex-col w-56 min-h-screen bg-surface-card border-r border-border">
  <!-- User greeting -->
  <div class="p-5 border-b border-border">
    <p class="font-heading font-semibold text-sm"><?= esc_html($currentUser->display_name) ?></p>
    <p class="text-xs text-text-muted"><?= esc_html($currentUser->user_email) ?></p>
  </div>

  <!-- Tabs -->
  <div class="flex-1 p-3 space-y-1">
    <?php
    $tabs = [
        'inschrijvingen' => ['label' => 'Mijn opleidingen', 'icon' => 'calendar'],
        'offertes'       => ['label' => 'Offertes',         'icon' => 'receipt'],
        'certificaten'   => ['label' => 'Certificaten',     'icon' => 'award'],
        'profiel'        => ['label' => 'Profiel',          'icon' => 'user'],
    ];
    foreach ($tabs as $slug => $tab) : ?>
    <a href="?tab=<?= $slug ?>"
       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-color
              <?= $activeTab === $slug ? 'bg-primary/10 text-primary font-medium' : 'text-text hover:bg-surface-alt' ?>">
      <?= stridence_icon($tab['icon'], 'w-4 h-4') ?>
      <?= $tab['label'] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Logout -->
  <div class="p-3 border-t border-border">
    <a href="<?= wp_logout_url(home_url()) ?>" class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-muted hover:text-text transition-color">
      <?= stridence_icon('external-link', 'w-4 h-4') ?>
      Uitloggen
    </a>
  </div>
</nav>
```

---

### Dashboard Tab: Mijn Opleidingen (`tab-inschrijvingen.php`)

**Purpose:** Overview of all enrollments — active, completed, and cancelled. This is the default/landing tab.

**Layout:**
```
┌─────────────────────────────────────────────────┐
│ Mijn Opleidingen                                │
│                                                 │
│ ── Komende sessies ───────────────────────────  │
│ (next 2-3 upcoming sessions across all enrollm.)│
│                                                 │
│ ┌─ 12 mrt ─────────────────────────────────┐    │
│ │ 09:00-16:00  Motiverende Gespreksvoering │    │
│ │ Locatie: Antwerpen │ Sessie 2 van 3      │    │
│ └──────────────────────────────────────────┘    │
│ ┌─ 20 mrt ─────────────────────────────────┐    │
│ │ 13:00-17:00  Palliatieve Zorg            │    │
│ │ Locatie: Online (webinar)                │    │
│ └──────────────────────────────────────────┘    │
│                                                 │
│ ── Actieve inschrijvingen ────────────────────  │
│                                                 │
│ ┌──────────────────────────────────────────┐    │
│ │ ▶ Motiverende Gespreksvoering            │    │
│ │   Editie: mrt-apr 2026 │ Antwerpen       │    │
│ │   ●●○ 2/3 sessies bijgewoond            │    │
│ │   Status: Bevestigd                      │    │
│ └──────────────────────────────────────────┘    │
│ ┌──────────────────────────────────────────┐    │
│ │ ▶ Palliatieve Zorg (expand for detail)   │    │
│ │   Editie: mrt 2026 │ Online              │    │
│ │   ○○ 0/2 sessies                         │    │
│ │   Status: Bevestigd                      │    │
│ └──────────────────────────────────────────┘    │
│                                                 │
│ ── Afgerond ──────────────────────────────────  │
│ (collapsed by default, show count)              │
│ 3 afgeronde opleidingen ▾                       │
└─────────────────────────────────────────────────┘
```

**Expanded enrollment card:**
```
┌──────────────────────────────────────────────────┐
│ ▼ Motiverende Gespreksvoering                    │
│   Editie: mrt-apr 2026 │ Antwerpen               │
│   Status: Bevestigd                              │
│                                                  │
│   ── Sessies ──────────────────────────────────  │
│   ✓  5 mrt 2026  09:00-16:00  Dag 1 - Inleiding │
│      Aanwezig │ Locatie: Campus Antwerpen        │
│   ✓  12 mrt   09:00-16:00  Dag 2 - Verdieping   │
│      Aanwezig │ Locatie: Campus Antwerpen        │
│   ○  19 mrt   09:00-16:00  Dag 3 - Praktijk     │
│      Gepland  │ Locatie: Campus Antwerpen        │
│                                                  │
│   Voortgang: 2 van 3 sessies (67%)              │
│   ████████████░░░░░░                             │
│                                                  │
│   [Toevoegen aan agenda ↓]   [Annuleren]         │
└──────────────────────────────────────────────────┘
```

**Key data per enrollment:**
```php
$registrations = $registrationRepo->getForUser($userId);

foreach ($registrations as $reg) {
    $editionId = $reg->edition_id;
    $courseId = get_post_meta($editionId, '_vad_course_id', true);
    $course = get_post($courseId);
    $edition = get_post($editionId);

    // Sessions for this edition
    $sessions = $sessionService->getSessionsForEdition($editionId);

    // Attendance per session
    $attendance = $attendanceService->getAttendanceForUser($userId, $editionId);

    // Progress
    $progress = $completionService->getProgress($editionId, $userId);
    // Returns: ['attended' => 2, 'required' => 3, 'percentage' => 67]
}
```

**Upcoming sessions widget** (top of tab): Aggregates next 3 sessions across all active enrollments, sorted by date. This gives the user an instant "what's next" view.

```php
$upcomingSessions = [];
foreach ($activeRegistrations as $reg) {
    $sessions = $sessionService->getSessionsForEdition($reg->edition_id);
    foreach ($sessions as $session) {
        $sessionDate = get_post_meta($session->ID, '_vad_date', true);
        if (strtotime($sessionDate) > time()) {
            $upcomingSessions[] = [
                'session' => $session,
                'registration' => $reg,
                'course' => get_post(get_post_meta($reg->edition_id, '_vad_course_id', true)),
            ];
        }
    }
}
usort($upcomingSessions, fn($a, $b) =>
    strtotime(get_post_meta($a['session']->ID, '_vad_date', true))
    <=> strtotime(get_post_meta($b['session']->ID, '_vad_date', true))
);
$upcomingSessions = array_slice($upcomingSessions, 0, 3);
```

**Grouping:** Enrollments are grouped into 3 sections:
1. **Actieve inschrijvingen** — status: `confirmed` (sorted by next session date)
2. **Afgerond** — status: `completed` (collapsed by default, sorted newest first)
3. **Geannuleerd/Ingetrokken** — status: `cancelled`, `withdrawn` (collapsed, only shown if any exist)

**Session attendance indicators:**
- `✓` Green check — present
- `✗` Red x — absent
- `~` Yellow dash — excused
- `○` Gray circle — upcoming/no data yet

**Actions per enrollment:**
- "Toevoegen aan agenda" — triggers `stride_download_ical` via ntdstAPI, downloads .ics file
- "Annuleren" — inline confirmation pattern (see Interaction Patterns), calls `stride_cancel_enrollment`
- "Kies je sessies" — only shown if edition has session_slots and selection is open
- "Bekijk cursus" — link to course page (for accessing LearnDash content)

---

### Dashboard Tab: Mijn Offertes (`tab-offertes.php`)

**Purpose:** View and manage quotes/invoices.

**Layout:**
```
┌─────────────────────────────────────────────────┐
│ Offertes                                        │
│                                                 │
│ ┌─ STRIDE-2026-042 ────────────────────────┐    │
│ │ Motiverende Gespreksvoering              │    │
│ │ Editie: mrt 2026                         │    │
│ │                                          │    │
│ │ Subtotaal:    € 450,00                   │    │
│ │ Korting:      - € 50,00  (voucher)       │    │
│ │ BTW (21%):    € 84,00                    │    │
│ │ ─────────────────────────                │    │
│ │ Totaal:       € 484,00                   │    │
│ │                                          │    │
│ │ Status: Verstuurd │ Geldig tot: 1 apr    │    │
│ │                                          │    │
│ │ [PDF downloaden]                         │    │
│ └──────────────────────────────────────────┘    │
│                                                 │
│ ── Eerdere offertes ──────────────────────────  │
│ STRIDE-2025-118  Palliatieve Zorg   € 320,00    │
│   Status: Geëxporteerd                          │
│ STRIDE-2025-089  Wondverzorging     € 0,00      │
│   Status: Geëxporteerd (gratis)                 │
└─────────────────────────────────────────────────┘
```

**Data:**
```php
$quotes = $quoteRepo->getForUser($userId);
// Sorted: pending/sent first, then exported, then cancelled
```

**Quote statuses displayed:**
- `Draft` → "Concept" (gray)
- `Sent` → "Verstuurd" (blue) — action: user can request cancellation
- `Exported` → "Verwerkt" (green) — final, no actions
- `Cancelled` → "Geannuleerd" (red, struck-through)

**Actions:**
- PDF download (always available)
- Cancel request (only for `sent` status) — inline confirmation

---

### Dashboard Tab: Certificaten (`tab-certificaten.php`)

**Purpose:** Download certificates for completed courses.

**Layout:**
```
┌─────────────────────────────────────────────────┐
│ Certificaten                                    │
│                                                 │
│ ┌──────────────────────────────────────────┐    │
│ │ 🏅 Motiverende Gespreksvoering           │    │
│ │    Afgerond op 19 maart 2026             │    │
│ │    12 contacturen │ RIZIV geaccrediteerd │    │
│ │                                          │    │
│ │    [Certificaat downloaden ↓]            │    │
│ └──────────────────────────────────────────┘    │
│ ┌──────────────────────────────────────────┐    │
│ │ 🏅 Basisopleiding Wondverzorging         │    │
│ │    Afgerond op 4 januari 2026            │    │
│ │    24 contacturen                        │    │
│ │                                          │    │
│ │    [Certificaat downloaden ↓]            │    │
│ └──────────────────────────────────────────┘    │
│                                                 │
│ Totaal: 36 contacturen behaald in 2026          │
└─────────────────────────────────────────────────┘
```

**Data:**
```php
$completedRegistrations = $registrationRepo->getCompletedForUser($userId);

foreach ($completedRegistrations as $reg) {
    $courseId = get_post_meta($reg->edition_id, '_vad_course_id', true);
    $certLink = $ldAdapter->getCertificateLink($userId, $courseId);
    $hours = $sessionService->getTotalHours($reg->edition_id);
    // $reg->completed_at for completion date
}
```

**Certificate download:** Links to LearnDash's certificate PDF generation. Stride does not generate its own certificates.

**Empty state:** "Je hebt nog geen certificaten. Certificaten worden beschikbaar na het afronden van een opleiding."

**Summary line:** Show total contact hours earned in current year. Useful for healthcare professionals tracking accreditation requirements.

---

### Dashboard Tab: Profiel (`tab-profiel.php`)

**Purpose:** View and edit personal and billing information.

**Layout:**
```
┌─────────────────────────────────────────────────┐
│ Profiel                                         │
│                                                 │
│ ── Persoonlijke gegevens ─────────────────────  │
│                                                 │
│ Naam:         [Stefan Jeebens          ]        │
│ E-mail:       stefan@netdust.be (niet wijzigb.) │
│ Telefoon:     [0475 12 34 56           ]        │
│ Functie:      [Verpleegkundige     ▾   ]        │
│ Profiel:      [Leidinggevende      ▾   ]        │
│ Organisatie:  [WZC De Linde        ▾   ]        │
│ Afdeling:     [Geriatrie               ]        │
│                                                 │
│ [Opslaan]                                       │
│                                                 │
│ ── Facturatiegegevens ────────────────────────  │
│                                                 │
│ Organisatie:  [WZC De Linde            ]        │
│ E-mail fact.: [facturen@wzc-delinde.be ]        │
│ BTW-nummer:   [BE0123.456.789          ]        │
│ GLN-nummer:   [5412345678901           ]        │
│ Adres:        [Lindestraat 42          ]        │
│               [2000 Antwerpen          ]        │
│                                                 │
│ [Opslaan]                                       │
│                                                 │
│ ── Wachtwoord wijzigen ──────────────────────  │
│ [Huidig wachtwoord  ]                          │
│ [Nieuw wachtwoord   ]                          │
│ [Bevestig wachtwoord]                          │
│ [Wachtwoord wijzigen]                          │
└─────────────────────────────────────────────────┘
```

**Sections are independent forms** — each has its own save button and submits independently via ntdstAPI to `stride_update_profile`.

**E-mail is read-only** — displayed but not editable (WordPress manages email changes separately).

**Profile type and organisation** are dropdowns populated from WordPress options/taxonomies.

**Save behavior:** Submit via ntdstAPI → toast notification on success/error. No page reload.

```html
<form x-data="profileForm()" @submit.prevent="save('personal')">
  <!-- Fields -->
  <button type="submit" :disabled="loading" class="btn-primary">
    <span x-show="!loading">Opslaan</span>
    <span x-show="loading">Opslaan...</span>
  </button>
</form>
```

---

### Login & Registration

**Login** is handled by WordPress default login (`wp-login.php`), styled to match the theme. Override via `login_enqueue_scripts` hook to inject theme CSS.

```php
add_action('login_enqueue_scripts', function () {
    wp_enqueue_style('stride-login', get_theme_file_uri('dist/login.css'));
});
```

**Registration** is handled by a separate plugin (not Stride's responsibility). The theme provides a styled login page and redirect logic:

```php
// Redirect non-logged-in users away from dashboard
add_action('template_redirect', function () {
    if (is_page('mijn-account') && !is_user_logged_in()) {
        wp_redirect(wp_login_url(get_permalink()));
        exit;
    }
});

// Redirect to dashboard after login
add_filter('login_redirect', function ($redirect_to, $requested, $user) {
    if (!is_wp_error($user) && !$requested) {
        return home_url('/mijn-account/');
    }
    return $redirect_to;
}, 10, 3);
```

---

### 404 Page (`404.php`)

```
┌────────────────────────────────────────────┐
│                                            │
│     Pagina niet gevonden                   │
│                                            │
│     De pagina die je zoekt bestaat niet     │
│     of is verplaatst.                      │
│                                            │
│     [Naar de homepage]  [Bekijk aanbod]    │
│                                            │
└────────────────────────────────────────────┘
```

Minimal, centered layout. Two CTA buttons. No sidebar.

### Search Results (`search.php`)

Same layout as course catalog but with search query displayed and results from WordPress search scoped to `sfwd-courses`. Shows "X resultaten voor '{query}'" header.

**No results:** "Geen resultaten voor '{query}'. Probeer een andere zoekterm of bekijk ons [volledig aanbod](/opleidingen/)."

---

## Component States

Every component should handle these states. Server-render the appropriate state — Alpine enhances interactivity but the correct state is always visible without JS.

### Edition Card (`card-edition.php`)

| State | Appearance |
|-------|------------|
| **Default** | Full card with title, date, venue, price, status badge |
| **Hover** | Elevated shadow (`shadow-card → shadow-elevated`) |
| **No edition data** | Don't render (guard clause at top of partial) |
| **Cancelled edition** | Muted colors, "Geannuleerd" badge, no enrollment link |
| **Full** | "Volzet" badge (red), CTA changes to "Interesse melden" |
| **Few spots** | "Nog X plaatsen" badge (yellow), creates urgency |
| **Free** | "Gratis" badge (emerald), price shows "€ 0,00" or hidden |

### Status Badge (`badge-status.php`)

Already handles all states — see component code above. Auto-detects "few" when spots ≤ 5.

### Enrollment Card (dashboard)

| State | Appearance |
|-------|------------|
| **Confirmed, sessions upcoming** | Active card, progress bar, session list |
| **Confirmed, all sessions done** | Awaiting completion evaluation |
| **Completed** | Green accent, completion date, certificate link if available |
| **Cancelled** | Gray/muted, struck-through title, "Geannuleerd" badge |
| **Waitlist** | Yellow accent, "Wachtlijst" badge, no session details |
| **Expanding** | Smooth `x-collapse` animation, sessions load inline |
| **Performing action** | Button shows spinner, other actions disabled |

### Empty States (`partials/empty-state.php`)

Reusable empty state component:

```php
<?php
/**
 * @param string $args['icon']     Icon name (from icons/)
 * @param string $args['title']    Main message
 * @param string $args['message']  Supporting text (optional)
 * @param string $args['action']   CTA button text (optional)
 * @param string $args['url']      CTA URL (optional)
 */
$icon    = $args['icon'] ?? 'search';
$title   = $args['title'] ?? 'Niets gevonden';
$message = $args['message'] ?? null;
$action  = $args['action'] ?? null;
$url     = $args['url'] ?? null;
?>
<div class="text-center py-12 px-6">
  <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-surface-alt text-text-muted mb-4">
    <?= stridence_icon($icon, 'w-6 h-6') ?>
  </div>
  <p class="text-text font-medium mb-1"><?= esc_html($title) ?></p>
  <?php if ($message) : ?>
    <p class="text-sm text-text-muted max-w-sm mx-auto"><?= $message ?></p>
  <?php endif; ?>
  <?php if ($action && $url) : ?>
    <a href="<?= esc_url($url) ?>" class="btn-secondary mt-4 inline-block"><?= esc_html($action) ?></a>
  <?php endif; ?>
</div>
```

**Usage per context:**

| Context | Icon | Title | Message | Action |
|---------|------|-------|---------|--------|
| No enrollments | `calendar` | Nog geen inschrijvingen | Schrijf je in voor een opleiding om hier je voortgang te volgen. | Bekijk aanbod → /opleidingen/ |
| No quotes | `receipt` | Geen offertes | Offertes worden aangemaakt bij inschrijving. | — |
| No certificates | `award` | Nog geen certificaten | Certificaten worden beschikbaar na het afronden van een opleiding. | — |
| No search results | `search` | Geen resultaten | Probeer een andere zoekterm. | Bekijk alle opleidingen → /opleidingen/ |
| No upcoming editions | `calendar` | Geen geplande edities | Er zijn momenteel geen geplande edities voor deze opleiding. | Ik heb interesse → interest URL |
| Catalog empty filter | `filter` | Geen opleidingen gevonden | Probeer andere filters of bekijk alle opleidingen. | Filters wissen |

### Loading States

For server-rendered pages, there's no loading skeleton needed — the page renders complete. Loading states only apply to:

1. **AJAX actions** (save, cancel, validate) — button spinner + disabled state
2. **Alpine filter changes** — instant filter on client-side data (no loading needed)
3. **iCal download** — brief spinner on button, then browser download dialog

No skeleton loaders. No shimmer effects. Keep it simple — the server renders the real content.

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
├── search-bar.php            ← Course search with type-ahead
├── breadcrumb.php            ← Breadcrumb trail
├── empty-state.php           ← Reusable empty state
├── toast.php                 ← Global toast notification
├── progress-bar.php          ← Enrollment progress indicator
└── session-row.php           ← Single session with attendance status
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
      <p class="flex items-center gap-1.5">
        <?= stridence_icon('calendar', 'w-3.5 h-3.5') ?>
        <?= stride_format_date($startDate) ?>
      </p>
      <?php if ($venue) : ?>
      <p class="flex items-center gap-1.5">
        <?= stridence_icon('map-pin', 'w-3.5 h-3.5') ?>
        <?= esc_html($venue) ?>
      </p>
      <?php endif; ?>
    </div>
  </div>

  <!-- Bottom: price + status -->
  <div class="px-5 pb-5 flex items-center justify-between border-t border-border pt-4">
    <span class="text-xl font-bold"><?= stride_format_money($price) ?></span>
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

### Progress Bar (`partials/progress-bar.php`)

```php
<?php
/**
 * @param int $args['attended']   Sessions attended
 * @param int $args['required']   Total required sessions
 */
$attended = $args['attended'] ?? 0;
$required = $args['required'] ?? 1;
$pct = $required > 0 ? round(($attended / $required) * 100) : 0;
$pct = min($pct, 100);
?>
<div class="space-y-1">
  <div class="flex justify-between text-xs text-text-muted">
    <span><?= $attended ?> van <?= $required ?> sessies</span>
    <span><?= $pct ?>%</span>
  </div>
  <div class="h-2 bg-surface-alt rounded-full overflow-hidden">
    <div class="h-full bg-accent rounded-full transition-all duration-slow"
         style="width: <?= $pct ?>%"></div>
  </div>
</div>
```

### Session Row (`partials/session-row.php`)

```php
<?php
/**
 * @param WP_Post $args['session']    Session post
 * @param string  $args['attendance'] AttendanceStatus value or null
 */
$session    = $args['session'] ?? null;
$attendance = $args['attendance'] ?? null;
if (!$session) return;

$date      = get_post_meta($session->ID, '_vad_date', true);
$startTime = get_post_meta($session->ID, '_vad_start_time', true);
$endTime   = get_post_meta($session->ID, '_vad_end_time', true);
$location  = get_post_meta($session->ID, '_vad_location', true);
$type      = get_post_meta($session->ID, '_vad_type', true);
$optional  = get_post_meta($session->ID, '_vad_optional', true);

$isPast = strtotime($date) < strtotime('today');

$icons = [
    'present' => ['check-circle', 'text-green-600'],
    'absent'  => ['x-circle', 'text-red-500'],
    'excused' => ['clock', 'text-yellow-600'],
];
$attendanceIcon = $icons[$attendance] ?? null;
?>
<div class="flex items-start gap-3 py-3 <?= $isPast && !$attendance ? 'opacity-50' : '' ?>">
  <!-- Attendance indicator -->
  <div class="mt-0.5 w-5 h-5 flex-shrink-0">
    <?php if ($attendanceIcon) : ?>
      <?= stridence_icon($attendanceIcon[0], 'w-5 h-5 ' . $attendanceIcon[1]) ?>
    <?php else : ?>
      <span class="block w-3 h-3 mt-1 ml-1 rounded-full border-2 border-border"></span>
    <?php endif; ?>
  </div>

  <!-- Session info -->
  <div class="flex-1 min-w-0">
    <p class="text-sm font-medium">
      <?= stride_format_date($date) ?>
      <span class="text-text-muted font-normal"><?= esc_html($startTime) ?>–<?= esc_html($endTime) ?></span>
    </p>
    <p class="text-xs text-text-muted">
      <?= esc_html($location) ?>
      <?php if ($optional) : ?> · <span class="italic">Optioneel</span><?php endif; ?>
    </p>
  </div>

  <!-- Session type badge -->
  <span class="text-xs text-text-muted"><?= esc_html(ucfirst($type)) ?></span>
</div>
```

---

## CSS Component Classes

Define reusable component classes in `src/css/components.css`:

```css
/* src/css/components.css */

/* ── Buttons ── */
.btn-primary {
  @apply inline-flex items-center justify-center gap-2
         bg-primary text-white font-medium
         px-5 py-2.5 rounded-lg
         hover:bg-primary-dark
         focus:outline-none focus:ring-2 focus:ring-primary/50
         disabled:opacity-50 disabled:cursor-not-allowed
         transition-colors duration-fast;
}

.btn-secondary {
  @apply inline-flex items-center justify-center gap-2
         bg-surface-alt text-text font-medium
         px-5 py-2.5 rounded-lg border border-border
         hover:bg-border/50
         focus:outline-none focus:ring-2 focus:ring-primary/50
         disabled:opacity-50 disabled:cursor-not-allowed
         transition-colors duration-fast;
}

.btn-ghost {
  @apply inline-flex items-center justify-center gap-2
         text-primary font-medium
         px-5 py-2.5 rounded-lg
         hover:bg-primary/5
         focus:outline-none focus:ring-2 focus:ring-primary/50
         transition-colors duration-fast;
}

.btn-danger {
  @apply inline-flex items-center justify-center gap-2
         text-error font-medium
         px-5 py-2.5 rounded-lg
         hover:bg-error/5
         focus:outline-none focus:ring-2 focus:ring-error/50
         transition-colors duration-fast;
}

/* ── Form inputs ── */
.input-field {
  @apply w-full px-3.5 py-2.5 rounded-lg
         border border-border bg-surface-card text-text
         placeholder:text-text-muted
         focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary
         transition-colors duration-fast;
}

.input-select {
  @apply input-field appearance-none
         bg-[url('data:image/svg+xml,...')] bg-no-repeat bg-right-3;
}

.input-label {
  @apply block text-sm font-medium text-text mb-1.5;
}

.input-error {
  @apply text-xs text-error mt-1;
}

/* ── Cards ── */
.card {
  @apply bg-surface-card rounded-xl shadow-card;
}

.card-interactive {
  @apply card hover:shadow-elevated transition-shadow duration-normal cursor-pointer;
}

/* ── Section spacing ── */
.section {
  @apply py-[var(--space-section)];
}

.section-alt {
  @apply section bg-surface-alt;
}

/* ── Prose (for LearnDash content & descriptions) ── */
.prose-stride {
  @apply text-text leading-relaxed;
}
.prose-stride h2 { @apply font-heading text-2xl font-bold mt-10 mb-4; }
.prose-stride h3 { @apply font-heading text-xl font-semibold mt-8 mb-3; }
.prose-stride p  { @apply mb-4; }
.prose-stride ul { @apply list-disc pl-5 mb-4 space-y-1; }
.prose-stride ol { @apply list-decimal pl-5 mb-4 space-y-1; }
.prose-stride a  { @apply text-primary underline hover:text-primary-dark; }
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

### main.js Entry Point

```js
// src/main.js

// CSS
import './css/tokens.css';
import './css/base.css';
import './css/components.css';
import './css/learndash.css';

// Alpine.js — loaded via CDN (see theme-config.php)
// Alpine components registered globally here if needed

// Utility: dispatch toast from anywhere
window.strideToast = (message, type = 'success') => {
    window.dispatchEvent(new CustomEvent('toast', { detail: { message, type } }));
};
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
        console.log(response.data);
    } else {
        console.error(response.data.message);
    }
})
.catch(error => {
    console.error('Request failed:', error);
});
```

### Available Stride Endpoints

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
    <input type="text" x-model="code" @blur="validate()" class="input-field">
    <span x-show="discount" class="text-sm text-green-700" x-text="discountText"></span>
    <span x-show="error" class="text-sm text-error" x-text="error"></span>
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

```php
// functions.php - Core helpers

function stride_theme(): ?NTDST_Theme {
    return ntdst_get(NTDST_Theme::class);
}

function stride_service(string $class): mixed {
    return ntdst_get($class);
}

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

function stride_format_money(int $cents): string {
    return '€ ' . number_format($cents / 100, 2, ',', '.');
}

function stride_enrollment_url(int $editionId): string {
    return home_url('/inschrijven/?editie=' . $editionId);
}

function stride_interest_url(int $courseId): string {
    return home_url('/interesse/?cursus=' . $courseId);
}

function stride_get_courses_by_domain(int $termId, int $limit = 4): array {
    return ntdst_data()->get('sfwd-courses')
        ->where('stride_domain', $termId)
        ->limit($limit)
        ->all();
}

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

For helpers that benefit from theme instance access:

```php
// helpers/StrideHelpers.php
namespace Stride\Helpers;

class StrideHelpers
{
    public function formatDate(string $date, string $format = 'j F Y'): string {
        return stride_format_date($date, $format);
    }

    public function formatMoney(int $cents): string {
        return stride_format_money($cents);
    }

    public function enrollmentUrl(int $editionId): string {
        return stride_enrollment_url($editionId);
    }

    public function icon(string $name, string $class = ''): string {
        return stridence_icon($name, $class);
    }
}
```

Register in `functions.php`:

```php
add_action('after_setup_theme', function () {
    $theme = stride_theme();

    $theme->mixin(new \Stride\Helpers\StrideHelpers());
    $theme->mixin('editions', ntdst_get(\Stride\Modules\Edition\EditionService::class));
    $theme->mixin('quotes', ntdst_get(\Stride\Modules\Invoicing\QuoteService::class));
}, 20);
```

### Which Pattern to Use?

| Use Case | Pattern |
|----------|---------|
| Simple utilities (formatting, URLs) | Global `stride_*()` helpers |
| Template-focused helpers | Theme mixin methods |
| Service shortcuts in templates | Theme mixin service proxies |
| Framework integration (router, data, mail) | Already wired via `ntdst_*()` |

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
ddev launch
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
ddev exec wp eval-file scripts/seed.php
ddev exec bash -c 'FORCE_UNSEED=1 wp eval-file scripts/unseed.php'
```

Test credentials after seeding:
- All seed users have password: `seedpass123`
- Admin: `seed_admin@seed.test`
- Students: `seed_student1@seed.test` through `seed_student5@seed.test`

---

## Accessibility Requirements

Healthcare professionals may be older, have varying tech literacy, and use the platform under time pressure. Accessibility is not optional.

- **WCAG 2.1 AA compliance** — minimum standard
- **Color contrast** — all text meets 4.5:1 ratio (tokens are designed for this)
- **Focus indicators** — visible focus ring on all interactive elements (`focus:ring-2 focus:ring-primary/50`)
- **Keyboard navigation** — all interactive elements reachable via Tab, Alpine components handle Escape to close
- **Screen reader labels** — `aria-label` on icon-only buttons, `aria-current="page"` on active nav items
- **Form labels** — every input has a visible `<label>` (no placeholder-only inputs)
- **Error announcements** — form errors use `role="alert"` for screen reader announcement
- **Reduced motion** — respect `prefers-reduced-motion` by disabling transitions:

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
```

---

## Critical Rules

- **Never inline Stride service calls in template loops without caching** — use repository methods
- **All dates in Dutch** — use `stride_format_date()`, never raw PHP `date()`
- **All user-facing text in Dutch** — labels, buttons, empty states, error messages
- **Alpine for UI state only** — menus, tabs, filters, accordions. No business logic in JS.
- **Server-render first** — pages must work without JS. Alpine enhances, not replaces.
- **Forms handle enrollment** — the theme never processes enrollment directly
- **Edition is the enrollable unit** — users enroll in editions, not courses
- **Status badges are dynamic** — auto-calculate from spots remaining (open → few → full)
- **Courses/editions in lists are always sorted** — sorting in meaningful way, like agenda or last enrolled
- **Money in cents** — all prices stored as integers in cents, format on display
- **Use ntdst_get() with full class names** — e.g., `ntdst_get(\Stride\Modules\Edition\EditionService::class)`
- **LearnDash content via the_content()** — never re-implement LD's course/lesson rendering
- **Style LearnDash, don't replace it** — use CSS overrides on LD classes, not template overrides for lessons/quizzes
- **Dashboard tabs use URL state** — `?tab=xxx` for bookmarkable, back-button-friendly navigation
- **Every empty state needs a message** — use `partials/empty-state.php` with appropriate icon, title, message, and CTA
- **Toast for AJAX feedback** — dispatch `toast` event after every async action
- **Inline confirmation for destructive actions** — never use browser `confirm()` dialogs
- **Mobile-first responsive** — base styles are mobile, enhance with `md:` and `lg:` breakpoints
- **No skeleton loaders** — server renders real content. Loading states only on AJAX buttons.
- **Respect reduced motion** — `prefers-reduced-motion` disables all transitions
