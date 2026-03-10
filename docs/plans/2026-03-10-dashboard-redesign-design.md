# Dashboard Redesign: Personal Learning Space

**Date:** 2026-03-10
**Status:** Approved
**Scope:** User dashboard (mijn-account) — layout, navigation, home screen, visual design, sidepanels

---

## Problem

The current dashboard is functional but feels like an admin panel. Five tabs of lists. Users land on a wall of enrollments regardless of whether they have one e-learning or twenty trajectory courses. It doesn't answer the two questions users actually have: **"What should I do next?"** and **"Where do I need to be?"**

## Vision

A calm, adaptive personal learning space. App-like, warm, generous whitespace. The dashboard assembles itself from what matters to each user right now. E-learners see almost nothing — just their course and certificate. In-person users see their next session and pending tasks. Trajectory users see their journey progress. Everyone gets a home screen that feels like a personal assistant, not a filing cabinet.

---

## User Segments

| Segment | Size | Needs | Dashboard weight |
|---------|------|-------|-----------------|
| **E-learning** | Largest | Access course, complete, get certificate, maybe next course | Light — 1-2 cards max |
| **In-person/classroom** | Medium | Session schedule, documents, blended learning, forms, notifications | Medium — hero action + active enrollments |
| **Trajectory** | Smallest | Progress across many courses, elective management, trajectory dashboard | Rich — progress overview + trajectory cards |

---

## Layout Structure

### Content Area
- Centered `max-w-4xl` with generous padding (`px-6 lg:px-8`)
- Full-width content — no sidebar eating into it
- Cream/warm off-white page background

### Floating Dock (Navigation)
- Positioned `fixed` in the left viewport margin, outside the content container
- Icons-only by default (56px wide), expands to ~180px with labels on hover
- `rounded-2xl`, white background, soft shadow — feels like a floating widget
- Vertically centered on the viewport
- Below `lg` breakpoint: transforms to bottom tab bar

### Dock Items (Adaptive)

Items appear/disappear based on the user's actual data:

| Item | Icon | Shows when |
|------|------|-----------|
| **Home** | house | Always |
| **Opleidingen** | book-open | Has any enrollment |
| **Trajecten** | layers | Has trajectory enrollment |
| **Agenda** | calendar | Has in-person sessions |
| **Offertes** | file-text | Has any quote |
| **Certificaten** | award | Has any completion |
| **Profiel** | user | Always (pinned to bottom) |

An e-learner with one completed course sees: Home, Opleidingen, Certificaten, Profiel (4 items).

### Mobile Bottom Tab Bar
- Fixed bottom, `safe-area-inset-bottom` aware
- Same adaptive items as dock
- If >5 items: last slot becomes "Meer" overflow
- Active tab: primary color fill
- Inactive: warm charcoal

---

## Home Screen — Adaptive Blocks

Blocks stack vertically with `space-y-8` between sections. Each block only renders if the user has relevant data.

### Block 1: Greeting (always)

```
Goedemorgen, Sarah
Dinsdag 14 maart
```

- Time-of-day greeting: Goedemorgen / Goedemiddag / Goedenavond
- First name, warm charcoal, `text-2xl font-medium`
- Date line in `text-sm text-muted`
- Avatar with colored initials fallback (deterministic color from name hash)
- No heavy header bar — just text breathing on the page

### Block 2: Hero Action (max 1, context-dependent)

The single most important thing right now. Large warm card, `rounded-2xl`, subtle primary-tinted background.

| Priority | State | Hero content | CTA |
|----------|-------|-------------|-----|
| 1 | Session today/tomorrow | Date, time, location prominently | "Bekijk details" |
| 2 | Pending action items | Task description | "Taken bekijken" |
| 3 | In-progress e-learning | Course name + progress ring | "Ga verder" |
| 4 | Certificate ready | Course name + completion message | "Download certificaat" |
| 5 | Unsigned quote | Quote number + status | "Bekijk offerte" |
| 6 | Nothing active | Friendly empty message | "Bekijk aanbod" |

Only the highest-priority state shows. One card, one CTA.

### Block 3: Acties (only if pending tasks)

Compact list of things needing attention. Styled as gentle nudges, not alerts.

```
┌──────────────────────────────────────────────────┐
│ 🔵 │ Sessie 2 is morgen om 14:00 in Antwerpen   │
│ 🟠 │ Offerte #2024-031 wacht op akkoord          │
│ 🟢 │ Certificaat Motiverende Gespreksvoering     │
└──────────────────────────────────────────────────┘
```

- Colored left-border indicators (blue = upcoming, amber = needs action, green = positive)
- Each item is clickable — opens relevant sidepanel
- Disappears entirely when no pending items

### Block 4: Mijn Opleidingen (only if active enrollments)

Cards in a 2-column grid (desktop), stacked on mobile.

Each card:
- Course title (`text-lg font-medium`)
- Type badge: subtle pill ("Klassikaal", "E-learning", "Webinar")
- Progress ring (small, top-right) OR next session date
- Single CTA: "Ga verder" or "Bekijk"
- Click card → opens sidepanel with details

**Only active/in-progress items.** Completed items are NOT shown here. Home is forward-looking.

### Block 5: Mijn Trajecten (only if trajectory enrollments)

Compact trajectory card(s):
- Trajectory name
- Segmented progress bar (one segment per course group)
- Counter: "X van Y cursussen afgerond"
- Click → opens trajectory sidepanel with summary
- "Naar dashboard" link for full trajectory page

### Block 6: Certificaten (only if recent completions, max 3)

Small "Recent behaald" row:
- Course name + download icon
- Quick-access shortcut for e-learners who came back just for this
- "Alle certificaten" link to full certificates page

---

## Sidepanel Pattern

Click a card on Home → sidepanel slides in from right with contextual info + actions. Most users never need to leave the Home screen.

### Sidepanel Specs
- Width: `max-w-md` (~28rem)
- Slides from right, `rounded-l-2xl`
- Warm white background, matching card aesthetic
- Overlay: 5% opacity warm black (subtle dim, not dark)
- Close: click overlay, X button, or Escape key
- Scrollable content area inside panel

### Sidepanel Content Per Type

**Edition/Course Panel:**
- Course title + type badge
- Progress indicator (ring or bar)
- Next session: date, time, location with address
- Attendance status for past sessions
- Quick links: course materials, forms, blended learning
- Certificate download (if complete)
- Footer: "Ga verder" (primary) or "Bekijk cursus" (to full page)

**Quote Panel** (existing, refined):
- Quote number + status badge
- Price breakdown (subtotal, discount, tax, total)
- Line items
- Editable billing info (for draft/sent)
- Voucher code input
- Footer: Download PDF, Cancel quote

**Trajectory Panel:**
- Trajectory name + mode badge (Cohort/Zelfgestuurd)
- Progress bar (segmented)
- Course count: completed / in-progress / total
- Next step highlighted
- Footer: "Naar mijn dashboard" (to full trajectory page)

---

## Visual Design System

### Color Palette — Warm Shift

| Token | Value | Purpose |
|-------|-------|---------|
| `surface` | `#FAF9F7` | Page background (warm off-white / cream) |
| `surface-card` | `#FFFFFF` | Card backgrounds (pure white, pops against cream) |
| `surface-alt` | `#F3F1EE` | Alternate surface (warm gray) |
| `text` | `#2D2A26` | Primary text (warm charcoal, not pure black) |
| `text-muted` | `#8C8680` | Secondary text (warm gray) |
| `border` | `#E8E4DF` | Borders (subtle warm) |
| `primary` | Keep existing | Used sparingly as accent |
| Status: blue | `#3B82F6` | Upcoming/info |
| Status: amber | `#F59E0B` | Needs attention |
| Status: green | `#10B981` | Complete/positive |

### Cards
- `rounded-2xl` (16px corners)
- Shadow: `0 1px 3px rgba(0,0,0,0.04)` — barely there
- Hover: `0 4px 12px rgba(0,0,0,0.06)` + `translateY(-1px)`
- No borders — depth from cream-to-white contrast + shadow
- Internal padding: `p-6`
- Gap between cards: `gap-5`

### Typography
- Greeting: `text-2xl font-medium` (warm, not bold)
- Card titles: `text-lg font-medium`
- Body: `text-base` (16px)
- Secondary: `text-sm text-muted`
- Line height: `leading-relaxed`

### Spacing Philosophy
- Page padding: `px-6 lg:px-8`
- Between sections: `space-y-8` (32px)
- Between cards in grid: `gap-5` (20px)
- Inside cards: `p-6` (24px)
- Between elements in a card: `space-y-3`

Everything gets more space than feels necessary. The whitespace IS the design.

### Micro-interactions
- Card hover: lift + shadow (200ms ease-out)
- Progress rings: animate 0 → value on mount (700ms ease-out)
- Dock hover: label slides out (200ms ease)
- Sidepanel: slide in from right (300ms ease-out)
- Buttons: `active:scale-[0.98]` for tactile press feel
- Skeleton loading: warm gray pulse while data loads
- Section fade-in on mount: `opacity-0 → 1` + slight `translateY` (500ms)

---

## Existing Tabs → Pages

The current 5 tabs become full pages accessible from the dock. Each page retains its current functionality but gets the warm visual treatment:

| Current Tab | Becomes Page | Changes |
|-------------|-------------|---------|
| Inschrijvingen | **Opleidingen** | Renamed. Shows all enrollments (active, completed, cancelled). Cards open in sidepanel. |
| Trajecten | **Trajecten** | Same content. Cards open trajectory sidepanel. Full trajectory dashboard stays as separate page. |
| Offertes | **Offertes** | Same. Sidepanel already exists. |
| Certificaten | **Certificaten** | Same. Grid of certificates with download. |
| Profiel | **Profiel** | Same. Inline edit sections with warm styling. |

New addition: **Home** — the adaptive overview screen described above. This is the default landing page.

---

## Responsive Behavior

| Breakpoint | Layout |
|-----------|--------|
| `lg` (1024px+) | Floating dock in left margin + centered content |
| `md` (768-1023px) | No dock, bottom tab bar + full-width content with padding |
| `sm` (< 768px) | Bottom tab bar + edge-to-edge cards with small padding |

### Mobile Specifics
- Hero action card: full width, slightly less padding
- Card grid: single column
- Sidepanel: full-screen slide-up (not side) on mobile
- Bottom tab bar: max 5 items, "Meer" overflow if needed

---

## Data Requirements

The Home screen needs a single service method that returns the user's full dashboard state:

```php
UserDashboardService::getHomeData(int $userId): array
```

Returns:
```php
[
    'user' => ['name' => string, 'initials' => string, 'avatar_url' => ?string],
    'hero' => ['type' => string, 'data' => array] | null,
    'actions' => [['type' => string, 'label' => string, 'url' => string, 'color' => string], ...],
    'active_enrollments' => [...],  // existing getEnrollmentData subset
    'active_trajectories' => [...], // existing trajectory data subset
    'recent_certificates' => [...], // max 3 recent completions
    'nav_items' => ['opleidingen' => bool, 'trajecten' => bool, 'agenda' => bool, 'offertes' => bool, 'certificaten' => bool],
]
```

The `nav_items` flags drive the adaptive dock rendering.

---

## Out of Scope

- Gamification (streaks, badges, XP) — keep it calm, not gamified
- Command palette (Ctrl+K) — nice-to-have for later
- Notification system — existing action items cover this
- Course catalog/discovery on the dashboard — separate page
- Profile photo upload — initials fallback is sufficient
