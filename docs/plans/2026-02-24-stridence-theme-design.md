# Stridence Theme Design

> **Date:** 2026-02-24
> **Status:** Approved
> **Approach:** Template-First (server-renders, Alpine enhances)

## Overview

Full build of the Stridence theme for Stride LMS. The foundation (build tooling, CSS, header/footer, homepage) is complete. This design covers all remaining pages and components.

## File Structure

```
stridence/
├── templates/
│   ├── dashboard/
│   │   ├── tab-inschrijvingen.php
│   │   ├── tab-offertes.php
│   │   ├── tab-certificaten.php
│   │   └── tab-profiel.php
│   ├── course/
│   │   └── sidebar-edition.php
│   ├── enrollment/
│   │   └── form-wrapper.php
│   └── trajectory/
│       └── course-groups.php
│
├── partials/
│   ├── card-edition.php
│   ├── card-course.php
│   ├── card-trajectory.php
│   ├── badge-status.php
│   ├── progress-bar.php
│   ├── session-row.php
│   ├── empty-state.php
│   └── breadcrumb.php
│
├── archive-sfwd-courses.php
├── single-sfwd-courses.php
├── archive-vad_trajectory.php
├── single-vad_trajectory.php
├── single-vad_edition.php
├── page-mijn-account.php
└── 404.php
```

## Partials

### card-edition.php
Edition card with course title, date, venue, price, status badge. Receives `$args['edition']` and `$args['course']`.

### card-course.php
Simple course card without edition context. Receives `$args['course']`.

### card-trajectory.php
Trajectory card with status, deadline, course count. Receives `$args['trajectory']`.

### badge-status.php
Dynamic status badge. Auto-detects "few spots" when spots ≤ 5. Receives `$args['status']` and `$args['spots']`.

### progress-bar.php
Attendance progress bar. Receives `$args['attended']` and `$args['required']`.

### session-row.php
Session with date, time, location, attendance icon. Receives `$args['session']` and `$args['attendance']`.

### empty-state.php
Configurable empty state. Receives `$args['icon']`, `$args['title']`, `$args['message']`, `$args['action']`, `$args['url']`.

### breadcrumb.php
Simple breadcrumb. Receives `$args['items']` array of `['label' => '', 'url' => '']`.

## Public Pages

### archive-sfwd-courses.php (Course Catalog)
- Domain tabs (horizontal scroll mobile)
- Filter dropdowns (formaat, locatie, prijs)
- 3-column grid of edition cards
- Pagination with "Meer laden"
- Empty state when no results

### single-sfwd-courses.php (Course Detail)
- Breadcrumb
- Two-column: content + sticky sidebar
- Content: `the_content()` for LearnDash
- Tab anchors (scroll-based): Overzicht, Programma, Sprekers, Praktisch
- Mobile: sticky bottom CTA bar
- Related courses section

### single-vad_edition.php (Edition Detail)
- Full edition details
- Session list
- Enrollment CTA

### archive-vad_trajectory.php (Trajectory Catalog)
- Grid of trajectory cards
- Filter by status

### single-vad_trajectory.php (Trajectory Detail)
- Description
- Course groups (required + electives)
- Practical info
- Enrollment CTA

## Dashboard

### page-mijn-account.php
- Requires login
- Desktop: left rail + content
- Mobile: bottom tab bar
- URL state: `?tab=xxx`

### tab-inschrijvingen.php
- Komende sessies (next 2-3)
- Actieve inschrijvingen (expandable cards with progress, sessions, actions)
- Afgerond (collapsed)
- Geannuleerd (collapsed, if any)

### tab-offertes.php
- Quote list with line items, status, PDF download
- Older quotes collapsed

### tab-certificaten.php
- Completed courses with certificate download
- Total contact hours

### tab-profiel.php
Three independent forms:
1. Persoonlijke gegevens
2. Facturatiegegevens
3. Wachtwoord wijzigen

## Alpine Components

### courseCatalog()
Domain tabs and filters with URL state. Server renders initial results, Alpine filters client-side.

### courseDetailTabs()
Scroll spy for anchor-based tab highlighting.

### profileForms()
Form submission with loading state and toast feedback.

## Data Patterns

- Partials receive data via `$args`, no service calls inside
- Use existing stride-core services where available
- Stub missing services with `// TODO: wire up service` markers
- All text in Dutch (nl_BE)
- Money stored in cents, formatted on display

## Not In Scope

- Enrollment form (FluentForms)
- Admin templates
- PDF generation
- Email templates
