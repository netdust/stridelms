# Dashboard Redesign Design

**Date:** 2026-02-20
**Branch:** feature/dashboard-redesign
**Status:** Approved

## Overview

Redesign the Stride LMS user dashboard to provide trainees with a clear, friendly, modern overview of their learning content with easy navigation to key sections.

## Design References

- **Continu LMS** — professional, clean dashboard UX
- **iSpring LMS** — polished enterprise aesthetic
- **Look & feel:** Friendly, clean, modern

## Current State

The existing dashboard (`templates/dashboard/home.php`) has:
- Time-based greeting with enrollment count
- Overall progress percentage (circular visualization)
- Upcoming sessions (3 cards)
- Active courses grid (6 cards)

**Problems:**
- Overall progress percentage is visually nice but not functionally meaningful
- Missing quick navigation to other dashboard sections
- No clear "continue where you left off" call-to-action

## New Design

### Layout Structure

**Desktop (960px+):**
```
┌─────────────────────────────────────────────────────────────────┐
│  Header (unchanged)                                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  Greeting                                    ┌─────────────────┐│
│  "Goedemiddag, Jan"                          │ [📚] Cursussen  ││
│  Je hebt 3 actieve cursussen                 │ [🎯] Trajecten  ││
│                                              │ [📄] Offertes   ││
│  ┌─────────────────────────────────────────┐ │ [👤] Profiel    ││
│  │  Continue Learning (hero card)          │ │ [📅] Kalender   ││
│  │  Course title + progress + Continue btn │ └─────────────────┘│
│  └─────────────────────────────────────────┘                    │
│                                                                 │
│  Aankomende sessies                                             │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐            │
│  │ Session 1    │ │ Session 2    │ │ Session 3    │            │
│  └──────────────┘ └──────────────┘ └──────────────┘            │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Mobile (<960px):**
```
┌─────────────────────────────┐
│  Header (unchanged)         │
├─────────────────────────────┤
│  Greeting                   │
│                             │
│  Continue Learning          │
│  (full width hero)          │
│                             │
│  Upcoming Sessions          │
│  (stacked cards)            │
│                             │
├─────────────────────────────┤
│  [📚] [🎯] [📄] [👤] [📅]   │  ← Bottom navbar
└─────────────────────────────┘
```

### Components

#### 1. Greeting Section
- Time-based greeting: Goedemorgen / Goedemiddag / Goedenavond
- User's first name
- Subtitle: "Je hebt X actieve cursussen"

#### 2. Navigation Panel (Desktop)
- Position: Top-right, alongside greeting and hero
- Style: UIkit card with subtle shadow/border
- Width: ~200px
- Content: 5 rows, each with icon + label
- Icons: UIkit icon set (book, target, file-text, user, calendar)
- Active state: Light orange background on current page
- Links to:
  - `/mijn-account/mijn-cursussen/` — Cursussen
  - `/mijn-account/mijn-trajecten/` — Trajecten
  - `/mijn-account/mijn-offertes/` — Offertes
  - `/mijn-account/mijn-profiel/` — Profiel
  - `/mijn-account/kalender/` — Kalender

#### 3. Bottom Navbar (Mobile)
- Position: Fixed to viewport bottom
- Style: 5 icons evenly spaced, no labels
- Safe area padding for notched phones
- Subtle top border or shadow
- Active icon: Primary color or filled variant

#### 4. Continue Learning Hero
- Layout: Card with thumbnail (left), content (right)
- Content:
  - Course title (prominent)
  - Next session date/time if applicable
  - Progress bar with percentage
  - Large "Doorgaan" button (primary color)
- Logic:
  - Shows most recently accessed course that isn't completed
  - If no active courses: empty state with "Ontdek cursussen" CTA
  - If all completed: congratulations + "Ontdek meer" link
- Mobile: Stacks vertically

#### 5. Upcoming Sessions
- Section header: "Aankomende sessies"
- Shows next 3 upcoming sessions
- Each card displays:
  - Date (day + month, prominent)
  - Time range
  - Course title (truncated if needed)
  - Location (city name) or "Online" badge
- Layout: 3 columns on desktop, 1 column on mobile
- Clicking card goes to course/edition detail
- Empty state: Small muted text "Geen sessies gepland"

### Navigation on All Dashboard Pages

The navigation panel (desktop) and bottom navbar (mobile) appear on ALL dashboard pages:
- Dashboard home
- Courses
- Trajectories
- Quotes
- Profile
- Calendar

Current page is highlighted in the navigation.

### Removed

- Overall progress percentage visualization (was not functionally meaningful)

### Unchanged

- Header
- Individual page content (tabs on Courses page, etc.)
- Existing color palette and styling
- Page templates for Courses, Trajectories, Quotes, Profile, Calendar (content unchanged, just add navigation)

## Technical Notes

### Files to Modify
- `templates/dashboard/home.php` — Main dashboard layout
- `templates/dashboard/courses.php` — Add navigation panel
- `templates/dashboard/trajectories.php` — Add navigation panel
- `templates/dashboard/quotes.php` — Add navigation panel
- `templates/dashboard/profile.php` — Add navigation panel
- `templates/dashboard/calendar.php` — Add navigation panel
- `assets/css/stride.css` — New styles for navigation panel, bottom navbar, hero card

### New CSS Components
- `.stride-nav-panel` — Desktop navigation panel
- `.stride-bottom-navbar` — Mobile bottom navbar
- `.stride-continue-hero` — Continue learning hero card

### Responsive Breakpoints
- Desktop: 960px+ (UIkit `@m`)
- Mobile: <960px

## Constraints

- UIkit 3 framework
- Keep existing header unchanged
- Dutch language (nl)
