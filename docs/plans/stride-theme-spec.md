# Stride Theme Build Specification

> **Type:** Implementation specification for the Stridence theme
> **Stack:** Tailwind CSS + Alpine.js + Vite
> **Language:** Dutch (nl_BE) UI, English code
> **Target users:** Healthcare professionals browsing and enrolling in training courses

---

## Architecture Overview

```
┌─────────────────────────────────────────────────┐
│  WordPress Theme (this spec)                    │
│  ├── templates/        PHP page templates       │
│  ├── partials/         Reusable components      │
│  ├── assets/src/       Tailwind + Alpine + Vite │
│  └── functions.php     Theme setup + hooks      │
├─────────────────────────────────────────────────┤
│  Stride Core (mu-plugin — already built)        │
│  ├── Modules/Edition/     EditionService        │
│  ├── Modules/Enrollment/  EnrollmentService     │
│  ├── Modules/Invoicing/   QuoteService          │
│  ├── Modules/Trajectory/  TrajectoryService     │
│  ├── Modules/Attendance/  AttendanceService     │
│  ├── Modules/Completion/  CompletionService     │
│  └── Integrations/        LearnDash adapter     │
├─────────────────────────────────────────────────┤
│  WordPress + LearnDash + FluentForms            │
└─────────────────────────────────────────────────┘
```

### Accessing Stride Services from Theme Templates

```php
$editionService     = ntdst_get(\Stride\Modules\Edition\EditionService::class);
$sessionService     = ntdst_get(\Stride\Modules\Edition\SessionService::class);
$enrollmentService  = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
$quoteService       = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
$trajectoryService  = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);
$attendanceService  = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);
```

---

## Stride Data Model

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ TRAJECTORY (cohort-based learning path)                                     │
│ ├── enrollment_deadline                                                     │
│ ├── choice_available / choice_deadline                                      │
│ ├── courses[] with pick_count ← TYPICAL: user chooses from electives       │
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

**Key enrollment flows:**

1. **Online course** → Direct LearnDash enrollment (form or direct)
2. **In-person/hybrid course** → Enroll into Edition → Attend all sessions
3. **Trajectory** → Enroll → Choose elective courses → Enroll into each course

### Enrollment Flow Details

**Flow 1: Online Course (LearnDash-driven)**
```
User → Course page → [Enroll] → Form/Payment (if required) → LearnDash grants access
```

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

**Flow 3: Trajectory (cohort program)**
```
User → Trajectory page → [Enroll] (before enrollment_deadline)
  ↓
TrajectoryEnrollment created → Quote generated → Payment
  ↓
User completes all required courses → Trajectory completion
```

### Elective Selection (Dashboard — NOT enrollment form)

**Important:** Elective choices are NEVER made in the enrollment form. After enrollment, users make selections in their **profile dashboard**:

```
Dashboard → Mijn Trajecten / Mijn Inschrijvingen
  ↓
[Kies je vakken] or [Kies je sessies]
  ↓
Selection UI (before deadline) → Choices locked after deadline
```

---

## LearnDash Integration Boundary

Stride themes LearnDash — it does not replace it. LearnDash handles online course content delivery.

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

### LearnDash Content in Course Detail

```php
// single-sfwd-courses.php — main content area
<div class="course-content prose max-w-content">
    <?php
    // LearnDash renders: description, curriculum, materials, prerequisites
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
- "Take this course" button (for online courses)

**What Stride adds around it:**
- Page layout (header, breadcrumb, sidebar)
- Edition sidebar with dates, venue, price, enrollment CTA
- Tab navigation (Overzicht, Programma, Sprekers, Praktisch)
- Related courses section

### LearnDash CSS Theming

```css
/* src/css/learndash.css */
.learndash-wrapper { @apply text-text; }

.learndash-wrapper .ld-lesson-list .ld-lesson-item {
  @apply border-border rounded-lg mb-2;
}

.learndash-wrapper .ld-progress .ld-progress-bar {
  @apply bg-accent rounded-full;
}

.learndash-wrapper .wpProQuiz_content {
  @apply bg-surface-card rounded-xl p-6 shadow-card;
}

.learndash-wrapper .ld-button,
.learndash-wrapper input[type="submit"] {
  @apply bg-primary text-white font-medium px-6 py-3 rounded-lg
         hover:bg-primary-dark transition-colors;
}
```

### What NOT to Override

- LearnDash's focus mode navigation
- Quiz logic and question rendering
- Course progress tracking internals
- Drip content scheduling
- Certificate generation (PDF rendering)
- LD's REST API responses

**Rule:** If LearnDash provides working UI for content, style it — don't rebuild it.

---

## Key CPT Fields

All fields use NTDST Data Manager with `_ntdst_` prefix.

### Edition (vad_edition)

| Field | Type | Description |
|-------|------|-------------|
| `course_id` | int | Parent LearnDash course |
| `start_date`, `end_date` | date | Edition dates |
| `capacity` | int | 0 = unlimited |
| `price` | int | Price in cents (members) |
| `price_non_member` | int | Price in cents (non-members) |
| `venue` | text | Location name |
| `description` | text | Edition-specific details |
| `speakers` | text | Speaker names/info |
| `status` | EditionStatus | Open, Full, Cancelled, etc. |
| `completion_mode` | CompletionMode | AttendAll, Percentage, Count |

### Session (vad_session)

| Field | Type | Description |
|-------|------|-------------|
| `edition_id` | int | Parent edition |
| `slot_id` | string | Only if part of selection group |
| `type` | SessionType | in_person, webinar, online, assignment |
| `date` | date | Session date |
| `start_time`, `end_time` | time | Time slot |
| `location` | text | Venue |
| `duration_hours` | float | Contact hours |
| `optional` | bool | Not required for completion |

### Trajectory (vad_trajectory)

| Field | Type | Description |
|-------|------|-------------|
| `mode` | TrajectoryMode | cohort, self_paced |
| `enrollment_deadline` | datetime | Last enrollment date |
| `choice_available` | datetime | When elective selection opens |
| `choice_deadline` | datetime | When selection locks |
| `courses` | JSON | Course groups with pick_count |
| `status` | TrajectoryStatus | Draft, Open, InProgress, Closed |

### Quote (vad_quote)

| Field | Type | Description |
|-------|------|-------------|
| `quote_number` | string | e.g., STRIDE-2026-001 |
| `user_id` | int | Student |
| `registration_id` | int | Linked registration |
| `status` | QuoteStatus | Draft, Sent, Exported, Cancelled |
| `subtotal`, `tax`, `total` | int | Amounts in cents |
| `discount` | int | Discount in cents |
| `voucher_id` | int | Applied voucher |
| `items` | JSON | Line items array |

### Voucher (vad_voucher)

| Field | Type | Description |
|-------|------|-------------|
| `code` | string | Unique discount code |
| `discount_type` | DiscountType | full, fixed, percentage |
| `discount_value` | int | Amount or percentage |
| `status` | VoucherStatus | Active, Exhausted, Expired, Disabled |
| `valid_from`, `valid_until` | date | Validity period |
| `max_uses` | int | 0 = unlimited |
| `edition_id` | int | Restrict to specific edition |

---

## Domain Enums

### RegistrationStatus
- `Confirmed` - Active enrollment
- `Completed` - Course finished
- `Cancelled` - User withdrew
- `Withdrawn` - Withdrawn after start
- `Waitlist` - No capacity
- `Interest` - Pre-registration

### EditionStatus
- `Open` - Accepting enrollments
- `Full` - Capacity reached
- `Cancelled` - No longer running
- `Postponed` - Delayed
- `Announcement` - Coming soon
- `Completed` - Finished

### AttendanceStatus
- `Present` - Attended
- `Absent` - Did not attend
- `Excused` - Absence excused

### SessionType
- `InPerson` - Physical classroom
- `Webinar` - Live online (scheduled)
- `Online` - Self-paced
- `Assignment` - Homework

### QuoteStatus
- `Draft` - Not sent
- `Sent` - Awaiting payment
- `Exported` - Sent to Exact Online
- `Cancelled` - No longer valid

### WordPress Taxonomies

```php
'stride_domain'    // Zorgdomein: Ouderenzorg, GGZ, Eerste lijn
'stride_format'    // Vormingstype: Meerdaagse, Webinar, E-learning
'stride_audience'  // Doelgroep: Verpleegkundigen, Artsen
'stride_location'  // Locatie: Antwerpen, Gent, Online
```

---

## Design Tokens

```css
:root {
  /* ── Brand ── */
  --color-primary: 29 78 137;       /* Deep blue */
  --color-primary-light: 59 130 187;
  --color-primary-dark: 15 52 96;
  --color-accent: 0 148 133;        /* Teal */
  --color-accent-light: 38 186 170;

  /* ── Neutral ── */
  --color-surface: 250 249 247;     /* Warm off-white */
  --color-surface-alt: 243 241 237;
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
  --color-badge-open: 22 163 74;    /* Green */
  --color-badge-few: 234 179 8;     /* Yellow */
  --color-badge-full: 220 38 38;    /* Red */
  --color-badge-online: 99 102 241; /* Indigo */
  --color-badge-free: 16 185 129;   /* Emerald */

  /* ── Typography ── */
  --font-sans: 'Inter', system-ui, sans-serif;
  --font-heading: 'Plus Jakarta Sans', var(--font-sans);

  /* ── Spacing ── */
  --space-section: 5rem;
  --space-block: 3rem;
  --space-element: 1.5rem;

  /* ── Layout ── */
  --container-max: 1280px;
  --content-max: 768px;
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
  --duration-fast: 150ms;
  --duration-normal: 250ms;
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

## Responsive Strategy

| Breakpoint | Width | Layout context |
|------------|-------|----------------|
| Base | < 640px | Single column, stacked cards, bottom nav |
| `sm` | ≥ 640px | Minor adjustments |
| `md` | ≥ 768px | 2-column grids, sidebar starts appearing |
| `lg` | ≥ 1024px | Full layout — sidebar visible, 3-col grids |
| `xl` | ≥ 1280px | Max container width |

### Layout Patterns per Page

**Course Catalog:**
- Base: 1-col card stack, filters in collapsible sheet
- `md`: 2-col grid, filters visible
- `lg`: 3-col grid, domain tabs + filter bar

**Course Detail:**
- Base: Single column — content first, sticky bottom CTA bar
- `lg`: 2-col — content left + sticky sidebar right

**Dashboard:**
- Base: Bottom tab bar (fixed), single-column content
- `lg`: Side navigation (left rail), content fills remaining width

**Enrollment Form:**
- Always single column, max-width 768px, centered

### Mobile-Specific Patterns

**Sticky Bottom CTA Bar (course detail, mobile):**
```html
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

---

## Theme Structure

```
stridence/
├── src/
│   ├── main.js                       # Entry point
│   └── css/
│       ├── tokens.css                # Design tokens
│       ├── base.css                  # Reset, typography
│       ├── components.css            # btn-primary, card, etc.
│       └── learndash.css             # LD styling overrides
│
├── dist/                             # Vite build output
│
├── templates/
│   ├── dashboard/
│   │   ├── tab-inschrijvingen.php
│   │   ├── tab-offertes.php
│   │   ├── tab-certificaten.php
│   │   ├── tab-profiel.php
│   │   └── partial-session-list.php
│   ├── course/
│   ├── enrollment/
│   ├── trajectory/
│   └── homepage/
│
├── partials/
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
│   ├── empty-state.php
│   ├── toast.php
│   ├── progress-bar.php
│   └── session-row.php
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
├── helpers/StrideHelpers.php
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
├── functions.php
├── style.css
├── theme-config.php
├── tailwind.config.js
├── vite.config.js
└── package.json
```

---

## Navigation & User Journeys

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

**User menu** (logged in):
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

---

## Page Specifications

### Page Map

```
/                               → Homepage (front-page.php)
/opleidingen/                   → Course Catalog (archive-sfwd-courses.php)
/opleidingen/{slug}/            → Course Detail (single-sfwd-courses.php)
/opleidingen/editie/{slug}/     → Edition Detail (single-vad_edition.php)
/trajecten/                     → Trajectory Catalog (archive-vad_trajectory.php)
/traject/{slug}/                → Trajectory Detail (single-vad_trajectory.php)

/inschrijven/{edition_slug}/    → Enrollment form (shortcode)
/mijn-account/                  → Dashboard (page-mijn-account.php)
/mijn-account/?tab=inschrijvingen
/mijn-account/?tab=offertes
/mijn-account/?tab=certificaten
/mijn-account/?tab=profiel

/zoeken/?s={query}              → Search Results (search.php)
```

### 1. Homepage (`front-page.php`)

**Purpose:** Entry point, highlight upcoming courses, build trust.

**Sections (top to bottom):**

| # | Section | Data Source | Alpine? |
|---|---------|-------------|---------|
| 1 | Hero | Static content + CTA | No |
| 2 | Quick category links | `stride_domain` taxonomy terms | No |
| 3 | Featured/upcoming editions | `EditionService` | No |
| 4 | Value proposition | 3 columns: accreditatie, praktijkgericht, netwerk | No |
| 5 | Newsletter signup | FluentForms shortcode | No |

**Key template code:**
```php
<?php
use Stride\Modules\Edition\EditionRepository;

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

**Empty state:** "Er zijn momenteel geen geplande opleidingen. Schrijf je in voor de nieuwsbrief om op de hoogte te blijven."

### 2. Course Catalog (`archive-sfwd-courses.php`)

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
│ [Meer laden]                               │
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
  <nav class="flex gap-2 mb-6 overflow-x-auto pb-2 -mx-4 px-4 lg:mx-0 lg:px-0">
    <template x-for="domain in domains">
      <button
        @click="setDomain(domain.slug)"
        :class="activeDomain === domain.slug ? 'bg-primary text-white' : 'bg-surface-alt text-text'"
        class="px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap"
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
    <p class="text-sm text-text-muted">Probeer andere filters.</p>
  </div>
</div>
```

**Sort order:** By next edition start date (soonest first). Courses without open edition appear last.

### 3. Course Detail (`single-sfwd-courses.php`)

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

**Mobile:** Content first (full width), then edition CTA as sticky bottom bar.

**Tab navigation:** Anchor-based (scroll to section), not content-swapping. Active tab highlights based on scroll position.

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
      <p class="text-xs font-medium text-text-muted uppercase mb-3">Andere edities</p>
      <?php foreach (array_slice($allEditions, 1, 3) as $alt) : ?>
        <a href="<?= get_permalink($alt) ?>" class="block py-2 text-sm hover:text-primary">
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

**No edition available:**
```html
<aside class="...">
  <div class="bg-surface-card rounded-xl shadow-card p-6 text-center">
    <p class="text-text-muted mb-3">Momenteel geen geplande editie</p>
    <a href="<?= stride_interest_url(get_the_ID()) ?>" class="btn-secondary w-full">
      Ik heb interesse
    </a>
    <p class="text-xs text-text-muted mt-2">We laten je weten wanneer een nieuwe editie gepland wordt.</p>
  </div>
</aside>
```

### 4. Trajectory Detail (`single-vad_trajectory.php`)

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
│ [Inschrijven voor dit traject]                  │
└─────────────────────────────────────────────────┘
```

### 5. Dashboard (`page-mijn-account.php`)

**Purpose:** Logged-in user area — enrollments, quotes, certificates, profile.

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
├──────────────────────────┤
│ [Opl] [Off] [Cert] [Pro]│  ← Fixed bottom tab bar
└──────────────────────────┘
```

**Left rail navigation (desktop):**
```php
<nav class="hidden lg:flex flex-col w-56 min-h-screen bg-surface-card border-r border-border">
  <div class="p-5 border-b border-border">
    <p class="font-heading font-semibold text-sm"><?= esc_html($currentUser->display_name) ?></p>
    <p class="text-xs text-text-muted"><?= esc_html($currentUser->user_email) ?></p>
  </div>

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
       class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm
              <?= $activeTab === $slug ? 'bg-primary/10 text-primary font-medium' : 'text-text hover:bg-surface-alt' ?>">
      <?= stridence_icon($tab['icon'], 'w-4 h-4') ?>
      <?= $tab['label'] ?>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="p-3 border-t border-border">
    <a href="<?= wp_logout_url(home_url()) ?>" class="flex items-center gap-3 px-3 py-2.5 text-sm text-text-muted hover:text-text">
      <?= stridence_icon('external-link', 'w-4 h-4') ?>
      Uitloggen
    </a>
  </div>
</nav>
```

---

## Dashboard Tabs

### Tab: Mijn Opleidingen (`tab-inschrijvingen.php`)

**Purpose:** Overview of all enrollments — active, completed, cancelled. Default landing tab.

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
│                                                 │
│ ── Afgerond ──────────────────────────────────  │
│ (collapsed by default)                          │
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
    $sessions = $sessionService->getSessionsForEdition($editionId);
    $attendance = $attendanceService->getAttendanceForUser($userId, $editionId);
    $progress = $completionService->getProgress($editionId, $userId);
    // Returns: ['attended' => 2, 'required' => 3, 'percentage' => 67]
}
```

**Grouping:**
1. **Actieve inschrijvingen** — status: `confirmed` (sorted by next session date)
2. **Afgerond** — status: `completed` (collapsed, sorted newest first)
3. **Geannuleerd** — status: `cancelled`, `withdrawn` (collapsed, only if any exist)

**Session attendance indicators:**
- `✓` Green check — present
- `✗` Red x — absent
- `~` Yellow dash — excused
- `○` Gray circle — upcoming

**Actions per enrollment:**
- "Toevoegen aan agenda" — downloads .ics file via `stride_download_ical`
- "Annuleren" — inline confirmation, calls `stride_cancel_enrollment`
- "Kies je sessies" — only if edition has session_slots and selection is open
- "Bekijk cursus" — link to course page

### Tab: Offertes (`tab-offertes.php`)

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
└─────────────────────────────────────────────────┘
```

**Quote statuses:**
- `Draft` → "Concept" (gray)
- `Sent` → "Verstuurd" (blue)
- `Exported` → "Verwerkt" (green)
- `Cancelled` → "Geannuleerd" (red, struck-through)

### Tab: Certificaten (`tab-certificaten.php`)

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
}
```

**Empty state:** "Je hebt nog geen certificaten. Certificaten worden beschikbaar na het afronden van een opleiding."

### Tab: Profiel (`tab-profiel.php`)

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
│ ── Wachtwoord wijzigen ──────────────────────   │
│ [Huidig wachtwoord  ]                           │
│ [Nieuw wachtwoord   ]                           │
│ [Bevestig wachtwoord]                           │
│ [Wachtwoord wijzigen]                           │
└─────────────────────────────────────────────────┘
```

**Sections are independent forms** — each has its own save button, submits via ntdstAPI.

**E-mail is read-only** — displayed but not editable.

---

## Enrollment Form Structure

Multi-step  form with conditional fields.

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
- `werknemer` - For myself as employee
- `collega` - For a colleague
- `prive` - As private person

### Step 1: User/Participant Info

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

### Step 2: Invoice/Billing

| Field | Werknemer | Collega | Prive |
|-------|-----------|---------|-------|
| invoice_organisation | ✓ | ✓ | ✓ |
| invoice_email | ✓ | ✓ | ✓ |
| facturatie (address) | ✓ | ✓ | ✓ |
| invoice_vat | ✓ | ✓ | ✗ |
| invoice_gln | ✓ | ✓ | ✗ |
| ordernumber | ✓ | ✓ | ✓ |
| voucher | ✓ | ✓ | ✓ |

### Step 3: Intake Questions (optional, varies per course)

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
If voor_wie = collega → Creates/updates User for colleague
  ↓
QuoteService → Creates Quote with line items
  ↓
If voucher provided → VoucherService validates & applies
  ↓
LearnDash access granted
```

---

## Interaction Patterns

### URL State for Tabs and Filters

All tab and filter states in URL query parameters — bookmarkable, back-button friendly.

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

### Inline Confirmation (Destructive Actions)

No browser `confirm()`. Button transforms into confirm/cancel pair.

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

For AJAX actions. Auto-dismiss after 4 seconds. Max 1 visible.

```html
<!-- In footer.php -->
<div
  x-data="toastStore()"
  @toast.window="show($event.detail)"
  class="fixed bottom-20 lg:bottom-6 inset-x-0 flex justify-center z-50 pointer-events-none"
>
  <div
    x-show="visible"
    x-transition
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

**Dispatching:**
```javascript
this.$dispatch('toast', { message: 'Profiel opgeslagen', type: 'success' });
this.$dispatch('toast', { message: 'Kortingscode ongeldig', type: 'error' });
```

### Loading States

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
            this.$dispatch('toast', { message: 'Verbinding mislukt.', type: 'error' });
        } finally {
            this.loading = false;
        }
    }
}
```

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

## Component Library

### Edition Card (`partials/card-edition.php`)

```php
<?php
use Stride\Domain\EditionStatus;

$edition = $args['edition'] ?? null;
$course  = $args['course'] ?? null;
if (!$edition || !$course) return;

$status = get_post_meta($edition->ID, '_vad_status', true);
$price = get_post_meta($edition->ID, '_vad_price', true);
$venue = get_post_meta($edition->ID, '_vad_venue', true);
$startDate = get_post_meta($edition->ID, '_vad_start_date', true);
$capacity = (int) get_post_meta($edition->ID, '_vad_capacity', true);

$editionRepo = ntdst_get(\Stride\Modules\Edition\EditionRepository::class);
$registeredCount = $editionRepo->getRegisteredCount($edition->ID);
$spots = $capacity > 0 ? $capacity - $registeredCount : null;

$domains = get_the_terms($course->ID, 'stride_domain');
?>

<article class="bg-surface-card rounded-xl shadow-card hover:shadow-elevated transition-shadow overflow-hidden flex flex-col">
  <div class="p-5 pb-0 flex gap-2 flex-wrap">
    <?php if ($domains) : ?>
      <span class="text-xs font-medium px-2.5 py-1 rounded-full bg-primary/10 text-primary">
        <?= esc_html($domains[0]->name) ?>
      </span>
    <?php endif; ?>
  </div>

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
  'open'         => ['label' => 'Beschikbaar',            'classes' => 'bg-green-100 text-green-800'],
  'few'          => ['label' => "Nog {$spots} plaatsen",  'classes' => 'bg-yellow-100 text-yellow-800'],
  'full'         => ['label' => 'Volzet',                 'classes' => 'bg-red-100 text-red-800'],
  'cancelled'    => ['label' => 'Geannuleerd',            'classes' => 'bg-gray-100 text-gray-500'],
  'postponed'    => ['label' => 'Uitgesteld',             'classes' => 'bg-orange-100 text-orange-700'],
  'announcement' => ['label' => 'Binnenkort',             'classes' => 'bg-blue-100 text-blue-700'],
  'completed'    => ['label' => 'Afgelopen',              'classes' => 'bg-gray-100 text-gray-500'],
];

// Auto-detect "few" when spots <= 5
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
    <div class="h-full bg-accent rounded-full transition-all"
         style="width: <?= $pct ?>%"></div>
  </div>
</div>
```

### Session Row (`partials/session-row.php`)

```php
<?php
$session    = $args['session'] ?? null;
$attendance = $args['attendance'] ?? null;
if (!$session) return;

$date      = get_post_meta($session->ID, '_vad_date', true);
$startTime = get_post_meta($session->ID, '_vad_start_time', true);
$endTime   = get_post_meta($session->ID, '_vad_end_time', true);
$location  = get_post_meta($session->ID, '_vad_location', true);
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
  <div class="mt-0.5 w-5 h-5 flex-shrink-0">
    <?php if ($attendanceIcon) : ?>
      <?= stridence_icon($attendanceIcon[0], 'w-5 h-5 ' . $attendanceIcon[1]) ?>
    <?php else : ?>
      <span class="block w-3 h-3 mt-1 ml-1 rounded-full border-2 border-border"></span>
    <?php endif; ?>
  </div>

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
</div>
```

### Empty State (`partials/empty-state.php`)

```php
<?php
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

| Context | Icon | Title | Message |
|---------|------|-------|---------|
| No enrollments | `calendar` | Nog geen inschrijvingen | Schrijf je in voor een opleiding. |
| No quotes | `receipt` | Geen offertes | Offertes worden aangemaakt bij inschrijving. |
| No certificates | `award` | Nog geen certificaten | Certificaten na afronden opleiding. |
| No search results | `search` | Geen resultaten | Probeer een andere zoekterm. |
| Catalog empty | `filter` | Geen opleidingen | Probeer andere filters. |

### Component States

**Edition Card:**

| State | Appearance |
|-------|------------|
| Default | Full card with title, date, venue, price |
| Hover | Elevated shadow |
| Cancelled | Muted colors, "Geannuleerd" badge |
| Full | "Volzet" badge, CTA → "Interesse melden" |
| Few spots | "Nog X plaatsen" badge (yellow) |
| Free | "Gratis" badge (emerald) |

**Enrollment Card (dashboard):**

| State | Appearance |
|-------|------------|
| Confirmed, upcoming | Active, progress bar, session list |
| Completed | Green accent, certificate link |
| Cancelled | Gray/muted, struck-through |
| Waitlist | Yellow, "Wachtlijst" badge |
| Expanding | `x-collapse` animation |

---

## CSS Component Classes

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
         transition-colors;
}

.btn-secondary {
  @apply inline-flex items-center justify-center gap-2
         bg-surface-alt text-text font-medium
         px-5 py-2.5 rounded-lg border border-border
         hover:bg-border/50
         focus:outline-none focus:ring-2 focus:ring-primary/50
         disabled:opacity-50 disabled:cursor-not-allowed
         transition-colors;
}

.btn-ghost {
  @apply inline-flex items-center justify-center gap-2
         text-primary font-medium
         px-5 py-2.5 rounded-lg
         hover:bg-primary/5
         focus:outline-none focus:ring-2 focus:ring-primary/50
         transition-colors;
}

.btn-danger {
  @apply inline-flex items-center justify-center gap-2
         text-error font-medium
         px-5 py-2.5 rounded-lg
         hover:bg-error/5
         transition-colors;
}

/* ── Form inputs ── */
.input-field {
  @apply w-full px-3.5 py-2.5 rounded-lg
         border border-border bg-surface-card text-text
         placeholder:text-text-muted
         focus:outline-none focus:ring-2 focus:ring-primary/50 focus:border-primary
         transition-colors;
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
  @apply card hover:shadow-elevated transition-shadow cursor-pointer;
}

/* ── Section spacing ── */
.section {
  @apply py-[var(--space-section)];
}

.section-alt {
  @apply section bg-surface-alt;
}

/* ── Prose ── */
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

## Services Reference

### EditionService

```php
$service = ntdst_get(\Stride\Modules\Edition\EditionService::class);

$service->hasAvailableSpots($editionId): bool
$service->getCapacity($editionId): int
$service->getRegisteredCount($editionId): int
$service->getStatus($editionId): EditionStatus
$service->getCourseId($editionId): ?int
$service->getPrice($editionId, $isMember = true): Money
$service->exists($editionId): bool
```

### SessionService

```php
$service = ntdst_get(\Stride\Modules\Edition\SessionService::class);

$service->getSessionsForEdition($editionId): array
$service->getSessionDuration($sessionId): float
$service->getTotalHours($editionId): float
$service->getTotalDurationForSessions($sessionIds): float
```

### EnrollmentService

```php
$service = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);

$service->enroll($userId, $editionId, $options): int|WP_Error
$service->cancel($registrationId): bool|WP_Error
$service->isEnrolled($userId, $editionId): bool
$service->processEnrollment($data): array|WP_Error
$service->resolveParticipant($data): int|WP_Error
```

### QuoteService

```php
$service = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);

$service->createQuote($registrationId): Quote
$service->applyVoucher($quoteId, $code): bool
$service->export($quoteId): bool
$service->getForUser($userId): array
$service->getForRegistration($registrationId): ?Quote
```

### VoucherService

```php
$service = ntdst_get(\Stride\Modules\Invoicing\VoucherService::class);

$service->validateCode($code, $editionId = null): Voucher|WP_Error
$service->applyDiscount($quoteId, $voucherId): Money
$service->markExhausted($voucherId): void
$service->isValidFor($voucher, $editionId): bool
```

### AttendanceService

```php
$service = ntdst_get(\Stride\Modules\Attendance\AttendanceService::class);

$service->markPresent($sessionId, $userId, $markedBy = null): void
$service->markAbsent($sessionId, $userId): void
$service->markExcused($sessionId, $userId): void
$service->getAttendance($sessionId): array
$service->getAttendanceForUser($userId, $editionId): array
$service->getHoursAttended($userId, $editionId): float
```

### CompletionService

```php
$service = ntdst_get(\Stride\Modules\Completion\CompletionService::class);

$service->isComplete($editionId, $userId): bool
$service->getProgress($editionId, $userId): array  // ['attended' => 2, 'required' => 3]
$service->markComplete($registrationId): void
$service->evaluateCompletion($editionId, $userId): bool
```

### LearnDashAdapter

```php
$adapter = ntdst_get(\Stride\Integrations\LearnDash\LearnDashAdapter::class);

$adapter->grantAccess($userId, $courseId)
$adapter->revokeAccess($userId, $courseId)
$adapter->isComplete($userId, $courseId): bool
$adapter->getCertificateLink($userId, $courseId): ?string
```

---

## AJAX Handlers

**Location:** `Stride\Handlers\`

### Available Endpoints

| Action | Purpose | Auth |
|--------|---------|------|
| `stride_submit_enrollment` | Submit enrollment form | logged-in |
| `stride_validate_voucher` | Validate discount code | public |
| `stride_update_profile` | Update user profile | logged-in |
| `stride_download_ical` | Generate .ics file | logged-in |
| `stride_update_quote_status` | Quote status changes | logged-in |

### ntdstAPI Usage

**Always use `ntdstAPI`** for AJAX — never raw `fetch()`.

```javascript
const response = await ntdstAPI.post('stride_validate_voucher', {
    code: voucherCode,
    edition_id: editionId
});

if (response.success) {
    console.log(response.data);
} else {
    console.error(response.data.message);
}
```

---

## Helper Functions

```php
// Format date in Dutch
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

// Format money (cents to EUR)
function stride_format_money(int $cents): string {
    return '€ ' . number_format($cents / 100, 2, ',', '.');
}

// Get enrollment URL
function stride_enrollment_url(int $editionId): string {
    return home_url('/inschrijven/?editie=' . $editionId);
}

// Get interest URL
function stride_interest_url(int $courseId): string {
    return home_url('/interesse/?cursus=' . $courseId);
}

// Render inline SVG icon (cached)
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

---

## Accessibility Requirements

- **WCAG 2.1 AA compliance**
- **Color contrast** — all text 4.5:1 ratio minimum
- **Focus indicators** — visible `focus:ring-2 focus:ring-primary/50`
- **Keyboard navigation** — all interactive elements via Tab, Escape to close
- **Screen reader labels** — `aria-label` on icon-only buttons
- **Form labels** — every input has visible `<label>`
- **Error announcements** — `role="alert"` for form errors
- **Reduced motion:**

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

1. **All dates in Dutch** — use `stride_format_date()`, never raw `date()`
2. **All UI text in Dutch** — labels, buttons, errors, empty states
3. **Money in cents** — all prices stored as int, format on display
4. **Edition is the enrollable unit** — users enroll in editions, not courses
5. **Server-render first** — pages must work without JS
6. **Alpine for UI state only** — menus, tabs, filters (no business logic)
7. **Dashboard tabs use URL state** — `?tab=xxx` for bookmarkability
8. **Toast for AJAX feedback** — dispatch `toast` event after async actions
9. **Inline confirmation for destructive actions** — never browser `confirm()`
10. **LearnDash content via `the_content()`** — never re-implement LD rendering
11. **Style LearnDash, don't replace** — CSS overrides only for lessons/quizzes
12. **Use `ntdst_get()` with full class names** — for service access
13. **Use `ntdstAPI` for AJAX** — never raw fetch() for WP endpoints
14. **Status badges are dynamic** — auto-calculate from spots (open → few → full)
15. **No skeleton loaders** — server renders real content
16. **Respect reduced motion** — `prefers-reduced-motion` disables transitions
17. **Never cache service calls in loops** — use repository methods

---

## Development Commands

```bash
# Start environment
ddev start && ddev launch

# Theme development
cd web/app/themes/stridence
npm run dev     # Vite dev server (localhost:5173)
npm run build   # Production build

# Seed test data
ddev exec wp eval-file scripts/seed.php

# Test credentials
# Password: seedpass123
# Admin: seed_admin@seed.test
# Students: seed_student1@seed.test through seed_student5@seed.test

# WP-CLI
ddev exec wp cache flush
ddev exec wp plugin list
```
