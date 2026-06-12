# Stride Dashboard UX/UI Redesign — Design Document

**Date:** 2026-03-10
**Status:** Approved
**Style:** Warm Notion — Swiss clarity, warm surfaces, indigo accents

---

## 1. Vision

Transform the Stride user dashboard from a tab-based data display into a Notion-style **work environment** where self-motivated professionals can manage their learning, track progress, access documents, and act on notifications — all from a sidebar-navigated workspace.

### Target User

Self-motivated professionals and students following e-learnings, blended courses, and trajectories for professional development. Most users follow e-learnings.

### Design Principles

1. **Content-first** — No decoration. Every pixel earns its place.
2. **Flat over nested** — Sidebar → flat list → detail page. No accordion-in-tab-in-page.
3. **Action-oriented** — The home screen answers "What do I need to do?"
4. **Warm but modern** — Warm gray surfaces + indigo accent. Professional, not corporate.
5. **Progressive disclosure** — Show what matters now, let users dig deeper on click.

---

## 2. Design System

### 2.1 Color Tokens

| Token | Value | Usage |
|-------|-------|-------|
| `--surface` | `#FAF8F6` | Page background |
| `--surface-card` | `#FFFFFF` | Cards, panels, sidebar |
| `--surface-elevated` | `#F5F2EE` | Hover states, active sidebar items |
| `--border` | `#E8E4DF` | Subtle borders, dividers |
| `--border-strong` | `#D4CFC8` | Active borders, focused inputs |
| `--text` | `#1C1917` | Primary text (stone-900) |
| `--text-secondary` | `#78716C` | Labels, descriptions (stone-500) |
| `--text-muted` | `#A8A29E` | Timestamps, placeholders (stone-400) |
| `--primary` | `#4F46E5` | Indigo — CTAs, active nav, links |
| `--primary-hover` | `#4338CA` | Indigo hover state |
| `--primary-subtle` | `#EEF2FF` | Indigo tint — selected states, badges |
| `--accent-teal` | `#0D9488` | Progress, online status, completion |
| `--accent-amber` | `#D97706` | Warnings, pending actions, deadlines |
| `--accent-green` | `#16A34A` | Success, certificates, done states |
| `--destructive` | `#DC2626` | Errors, cancellations |

### 2.2 Typography

| Role | Font | Weight | Size |
|------|------|--------|------|
| Page title | Inter | 600 | 24px |
| Section heading | Inter | 600 | 18px |
| Body | Inter | 400 | 14–15px |
| Labels | Inter | 500 | 13px |
| Small/meta | Inter | 400 | 12px |
| Monospace (IDs, codes) | JetBrains Mono | 400 | 13px |

Line-height: 1.5 for body, 1.3 for headings.

### 2.3 Spacing & Radius

- **Base unit:** 4px
- **Spacing scale:** 4, 8, 12, 16, 24, 32, 48px
- **Border radius:** 6px (cards), 4px (buttons, inputs), 12px (modals/panels)
- **Card shadow:** `0 1px 2px rgba(0,0,0,0.04)` — barely visible
- **No heavy elevation** — borders do the separation work

### 2.4 Icons

Continue using Feather icon set (38 SVGs already available). Add as needed from Lucide (Feather-compatible superset).

Render via `stridence_icon($name, $classes)` helper.

---

## 3. Layout & Navigation

### 3.1 Sidebar (Desktop ≥1024px)

**Width:** 240px expanded, 56px collapsed (icon-only)
**Position:** Fixed left, full viewport height
**Background:** White with right border (`--border`)
**Collapse:** Toggle button at sidebar bottom

**Structure (top to bottom):**

```
┌─────────────────────┐
│  Stride [logo]      │  Brand
│                     │
│  ● Home             │  Primary nav
│  ○ Mijn opleidingen │
│  ○ Trajecten        │
│  ○ Offertes         │
│                     │
│  ─────────────────  │  Divider
│                     │
│  ○ Meldingen    (3) │  Utility nav
│  ○ Downloads        │
│  ○ Certificaten     │
│                     │
│        [spacer]     │
│                     │
│  ─────────────────  │  Divider
│  👤 Jan Janssens    │  User footer
│  ○ Profiel          │
│  ○ Uitloggen        │
└─────────────────────┘
```

**States:**
- Default: `--text-secondary` text, no background
- Hover: `--surface-elevated` background
- Active: `--primary` text, `--primary-subtle` background pill
- Badge: Indigo count badge on Meldingen

**Conditional visibility:** Nav items only appear if the user has relevant data (same logic as current tab visibility).

### 3.2 Mobile Navigation (<1024px)

- Sidebar hidden
- **Bottom nav bar** (fixed, 5 items): Home, Opleidingen, Meldingen, Downloads, Profiel
- Secondary items (Trajecten, Offertes, Certificaten) accessible from Home or "Meer" overflow
- Bottom nav height: 56px + safe area inset
- Active item: indigo icon + label, others gray

### 3.3 Content Area

- **Top bar:** Page title (left) + search icon (right). Thin, no background color.
- **Content max-width:** 960px, centered in available space
- **Padding:** 32px desktop, 16px mobile
- **Background:** `--surface` (warm off-white)

---

## 4. Pages

### 4.1 Home (Dashboard)

The home screen answers: **"What do I need to do, and what's happening?"**

**Sections (top to bottom, all adaptive — only render with data):**

#### Greeting + Status Line
```
Goedemorgen, Jan
Je hebt 3 acties die aandacht nodig hebben
```
Lightweight. No hero banner. Time-of-day greeting + one-line summary.

#### Stat Cards (inline row)
Small metric cards in a horizontal row:
- `2 Lopende opleidingen`
- `1 Actie vereist`
- `3 Certificaten`

Subtle border, no heavy styling. Just number + label.

#### Acties (Action List)
The most important section. Colored left-border list items:
- **Blue** (`--primary`): Upcoming session, new course access
- **Amber** (`--accent-amber`): Action required (profile incomplete, pending quote)
- **Green** (`--accent-green`): Certificate available for download

Each row is clickable → navigates to relevant page. Max 6 items.

#### Verder Leren (Continue Learning)
2-column grid of in-progress course cards (max 4):
- Course title
- Type badge (E-learning / Klassikaal)
- Thin progress bar with percentage
- Single CTA: "Doorgaan" / "Starten"

#### Recent Behaald (Recently Achieved)
Compact list (not cards), max 3 items:
- Checkmark icon + course title + download link
- Date of completion

#### Empty State
If no data at all: Welcome message + "Bekijk het aanbod" CTA.

### 4.2 Mijn Opleidingen (My Courses)

Flat list replacing current expandable-card pattern.

**Active section:**
Each row shows:
- Course title + type badge (E-learning / Klassikaal / Blended)
- E-learning: progress bar + percentage + "Doorgaan →" CTA
- Klassikaal: session count + next session date/time + "Details →" CTA

Click row → navigates to course/edition detail page.

**Afgerond (Completed) section:**
Collapsible group. Compact rows: checkmark + title + completion date.

**Geannuleerd (Cancelled) section:**
Collapsible group if any exist. Compact rows.

### 4.3 Trajecten

Same flat-list approach:
- Title + mode badge (Cohort / Eigen tempo)
- Progress: "4/6 opleidingen afgerond"
- Progress bar
- CTA: "Bekijk traject →"

Click → trajectory detail page (existing `single-vad_trajectory.php`).

### 4.4 Offertes

Keep current slide-panel pattern — it works well:
- List of quotes as clickable rows (quote number, status badge, total, date)
- Click opens right-side panel with price breakdown, line items, billing fields, voucher, PDF download
- Mobile: bottom sheet instead of side panel
- Restyle with new design tokens

### 4.5 Meldingen (Notifications) — NEW

**Notification sources (server-side, generated on page load):**
- Upcoming session reminder (1 day before)
- New certificate available
- Quote status change
- Course access granted
- Deadline approaching (access expiry, drip-feed unlock)
- Profile incomplete nudge

**UI:**
- Grouped by "Vandaag" / "Eerder" (today / earlier)
- Unread: small indigo dot left of item
- Click → navigates to relevant page
- "Alles gelezen" (mark all read) link top-right
- Sidebar badge shows unread count

**Implementation:** No real-time push. Server-side query on page load. Store read/unread state in user meta. Simple and reliable.

### 4.6 Downloads — NEW

Single page aggregating all downloadable documents.

**Grouped by type:**
- **Certificaten:** Certificate PDFs with completion date
- **Offertes:** Quote PDFs with creation date
- **Facturen:** Invoice PDFs (future, from Exact Online bridge)

Each row: document icon + name + date + download button.
Empty groups: "Nog geen [type] beschikbaar" message.

### 4.7 Certificaten

Keep current 2-column card grid. Restyle with new tokens.
- Small gradient header (keep — adds visual interest)
- Completion date + download button
- "Wordt gegenereerd..." state if not yet available

### 4.8 Profiel

Same inline-edit pattern, restyled:
- Sections as **flat groups** with section label (not accordion)
- Edit button per section toggles inline editing
- Inputs: warm borders, `--primary` focus ring
- Three sections: Persoonlijke gegevens, Facturatiegegevens, Voorkeuren
- Logout at bottom, visually separated (destructive nav separation)

---

## 5. Interaction Patterns

### 5.1 Unified Patterns

| Pattern | Implementation |
|---------|---------------|
| **Navigation** | Sidebar click → full page load (WordPress routing) |
| **Flat list rows** | Click row → navigate to detail page |
| **Slide panel** | Quotes only — Alpine `x-data="slidePanel()"` |
| **Inline edit** | Profile, billing — Alpine toggle edit/display |
| **Confirmation** | Destructive actions — Alpine modal with subtle backdrop blur |
| **Toast** | Success/error feedback — auto-dismiss 4s, bottom-right |
| **Skeleton loading** | Async sections show skeleton placeholder |
| **Empty states** | Icon + message + CTA where applicable |

### 5.2 Transitions

- **Duration:** 150ms for micro-interactions, 200ms for panel open/close
- **Easing:** `ease-out` for enter, `ease-in` for exit
- **Respect:** `prefers-reduced-motion` — disable animations when set
- **No layout-shifting animations** — use transform/opacity only

### 5.3 Responsive Breakpoints

| Breakpoint | Layout |
|------------|--------|
| `<640px` | Single column, bottom nav, 16px padding |
| `640–1023px` | Single column, bottom nav, 24px padding |
| `≥1024px` | Sidebar + content area, 32px padding |
| `≥1440px` | Same, content stays max-width 960px |

---

## 6. What Changes vs. Current

| Aspect | Current | New |
|--------|---------|-----|
| **Navigation** | Horizontal tabs on page | Fixed sidebar (desktop) / bottom nav (mobile) |
| **Page structure** | Single page with tab switching | Multiple WordPress pages, one per section |
| **Home screen** | Big hero block + sections | Lightweight greeting + action list + course cards |
| **Course list** | Expandable accordion cards | Flat list rows → click to detail page |
| **Notifications** | None | New sidebar section with badge count |
| **Downloads** | Scattered across tabs | Centralized downloads page |
| **Color palette** | Navy primary, warm surface | Indigo primary, warm surface (modernized) |
| **Nesting depth** | Tabs → accordions → nested content | Sidebar → list → detail page |
| **Loading states** | None | Skeleton placeholders |
| **Feedback** | Basic | Toast notifications |

---

## 7. What Stays the Same

- **Tech stack:** Tailwind CSS + Alpine.js + Vite (no framework change)
- **Typography:** Inter (already in use, just tighter weight discipline)
- **Icons:** Feather set via `stridence_icon()` helper
- **AJAX:** `ntdstAPI.call()` for form submissions
- **Data layer:** All existing services (UserDashboardService, EditionService, etc.)
- **Dutch UI language** throughout
- **Quote slide panel** — works well, just restyled
- **Profile inline editing** — works well, just restyled
- **Adaptive sections** — only render when user has data

---

## 8. New Components Needed

| Component | Purpose |
|-----------|---------|
| `sidebar.php` | Sidebar navigation partial |
| `bottom-nav.php` | Mobile bottom navigation partial |
| `toast.php` + Alpine | Toast notification system |
| `skeleton.php` | Skeleton loading placeholders |
| `stat-card.php` | Small metric card for home dashboard |
| `notification-item.php` | Single notification row |
| `download-item.php` | Single download row |

---

## 9. New Pages/Templates Needed

| Page | URL | Template |
|------|-----|----------|
| Notifications | `/mijn-account/meldingen/` | `templates/dashboard/page-meldingen.php` |
| Downloads | `/mijn-account/downloads/` | `templates/dashboard/page-downloads.php` |

Existing tab templates convert to standalone pages routed by WordPress.

---

## 10. Data Requirements

### Notifications Service (new)

```php
class NotificationService {
    public function getNotifications(int $userId): array;
    public function getUnreadCount(int $userId): int;
    public function markAllRead(int $userId): void;
    public function markRead(int $userId, string $notificationId): void;
}
```

Notifications are **computed from existing data** on page load:
- Upcoming sessions → query SessionService
- New certificates → query LearnDashHelper
- Quote changes → query QuoteService
- Pending actions → query UserDashboardService

Read state stored in user meta (`_stride_notifications_read`).

### Downloads Aggregation

No new service needed. Aggregate from existing:
- Certificates: `LearnDashHelper::getCertificateLink()`
- Quotes: existing quote PDF endpoint
- Invoices: future Exact Online bridge

---

## 11. Anti-Patterns to Avoid

- **No emoji as icons** — use Feather/Lucide SVGs only
- **No heavy shadows** — borders + spacing for hierarchy
- **No nested accordions** — flat list → detail page
- **No color-only indicators** — always include icon + text
- **No decorative animations** — motion must convey meaning
- **No placeholder-only labels** — visible labels on all inputs
- **No horizontal scroll** on any viewport
- **No mixing flat + elevated styles** — pick one per component type

---

## 12. Accessibility Checklist

- [ ] Color contrast ≥4.5:1 for all text
- [ ] Focus rings (2px indigo) on all interactive elements
- [ ] Skip-to-content link
- [ ] Keyboard navigation: tab order matches visual order
- [ ] Sidebar collapse/expand accessible via keyboard
- [ ] Bottom nav items have `aria-label`
- [ ] Notification badge announced to screen readers (`aria-live`)
- [ ] Toast uses `aria-live="polite"`
- [ ] `prefers-reduced-motion` respected
- [ ] Semantic HTML: `<nav>`, `<main>`, `<aside>`, headings hierarchy
