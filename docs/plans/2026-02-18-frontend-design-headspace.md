# Stride Frontend Design - Headspace-Inspired

**Date:** 2026-02-18
**Status:** Approved
**Approach:** Hybrid - Custom shell + styled LearnDash Focus Mode

## Overview

Mobile-first, PWA-ready frontend with calm + motivating aesthetic inspired by Headspace. Custom shell for navigation and discovery, LearnDash Focus Mode for course consumption.

### Key Decisions

| Decision | Choice |
|----------|--------|
| Course player | LearnDash Focus Mode (styled) |
| Design feel | Calm base + subtle motivation |
| Navigation | Bottom nav (mobile), top nav (desktop) |
| Framework | UIkit 3 (existing) |
| PWA | Yes - installable, cached shell |

---

## 1. Design System

### Color Palette

```css
:root {
  /* Primary - Warm Orange */
  --stride-primary: #FF8C42;
  --stride-primary-hover: #E67A35;
  --stride-primary-light: #FFF5EB;

  /* Secondary - Calm Navy */
  --stride-secondary: #2D3E50;
  --stride-secondary-hover: #1E2D3D;
  --stride-secondary-light: #E8ECF1;

  /* Success - Teal (Headspace-like) */
  --stride-success: #4ECDC4;
  --stride-success-light: #E0F7F5;

  /* Accent - Mint */
  --stride-accent: #A8E6CF;

  /* Backgrounds */
  --stride-bg: #FAFBFC;
  --stride-surface: #FFFFFF;

  /* Text */
  --stride-text: #2D3E50;
  --stride-text-muted: #6B7C8F;

  /* Borders */
  --stride-border: #E5E9ED;
}
```

### Typography

- **Font:** Inter (Google Fonts)
- **Weights:** 400 (body), 500 (medium), 600 (semibold), 700 (bold)

| Element | Mobile | Desktop |
|---------|--------|---------|
| H1 | 28px/36px | 36px/44px |
| H2 | 22px/28px | 28px/36px |
| H3 | 18px/24px | 22px/28px |
| Body | 16px/24px | 16px/24px |
| Small | 14px/20px | 14px/20px |

### Spacing Scale

4, 8, 12, 16, 24, 32, 48, 64px

### Border Radius

- Cards: 12px
- Buttons: 8px
- Pills/badges: 24px
- Inputs: 8px

### Shadows

```css
--shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.06);
--shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
--shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.10);
```

### Motion

- Transitions: 200-300ms ease-out
- Progress animations: gentle fills
- Celebrations: subtle confetti on completion

---

## 2. Mobile-First Shell

### Mobile Layout (< 768px)

```
┌─────────────────────────────────┐
│  ☰  Stride              🔔  👤  │  ← Header (48px)
├─────────────────────────────────┤
│                                 │
│         Page Content            │  ← Scrollable
│                                 │
├─────────────────────────────────┤
│  🏠    📚    🎯    📅    👤    │  ← Bottom nav (56px)
│ Home  Courses Path  Cal  Profile│
└─────────────────────────────────┘
```

### Bottom Navigation Items

| Icon | Label | Route |
|------|-------|-------|
| Home | Home | /mijn-account/ |
| Cursussen | Courses | /cursussen/ |
| Traject | Path | /mijn-account/trajecten/ |
| Agenda | Calendar | /mijn-account/agenda/ |
| Profiel | Profile | /mijn-account/profiel/ |

### Header Behavior

- Scrolls away on scroll-down
- Reappears on scroll-up
- Contains: hamburger, logo, notification bell, avatar

### Desktop Layout (≥ 768px)

- Horizontal top navigation (no sidebar)
- Sticky header with shadow on scroll
- Avatar dropdown for profile/settings/logout
- Max content width: 1200px, centered

---

## 3. Page Designs

### 3.1 Dashboard Home

**Components:**
- Greeting (time-aware: Goedemorgen/middag/avond)
- Progress card (trajectory % or courses completed)
- Upcoming sessions (next 2-3)
- Continue learning (resume in-progress course)
- Empty state with "Ontdek cursussen" CTA

### 3.2 Course Catalog

**Components:**
- Search bar (sticky)
- Filter chips: Alle, Online, Klassikaal, Trajecten, Category dropdown
- 2-column grid of course cards
- Infinite scroll or "Meer laden" button

**Course Card:**
- Thumbnail (or gradient placeholder)
- Title
- Type badge (Online / Klassikaal)
- Price (member)
- Next date (for klassikaal)

### 3.3 Course Detail

**Layout:**
- Hero image with title overlay
- Tab navigation: Over, Programma, Data
- Sticky CTA bottom bar

**Edition Picker (klassikaal):**
- Radio selection of available dates
- Location and capacity shown
- Sold out state for full editions

### 3.4 Trajectory Catalog

**Components:**
- Full-width trajectory cards
- Illustration/gradient backgrounds
- Stats: course count, hours, price
- "Bekijk traject" CTA

### 3.5 Trajectory Journey View (Headspace-style)

**Vertical path visualization:**
- Nodes: Completed (✓ teal), Current (→ orange pulse), Locked (🔒 gray)
- Connecting lines: solid (completed), dashed (upcoming)
- Elective branches with pick count
- Certificate at end (finish line motivation)

**Interactions:**
- Tap completed → see details/certificate
- Tap current → resume course
- Tap locked → see unlock requirements

### 3.6 My Courses

**Tabs:** Actief, Voltooid, Alle

**Active Course Card:**
- Progress bar
- Module count (X van Y)
- Type + deadline/date
- Resume button

**Upcoming Sessions Section:**
- Date, time, location
- Calendar add button

### 3.7 Calendar

**Components:**
- Month picker
- Mini calendar with dots for session days
- Session list below selected date
- iCal export per session
- Online deadlines shown too

### 3.8 Profile

**Tabs:** Persoonlijk, Facturatie, Instellingen

**Quick Links:**
- Mijn offertes (with count)
- Certificaten (with count)

### 3.9 Quotes

**Tabs:** Openstaand, Betaald, Alle

**Quote Card:**
- Quote number
- Course/trajectory name
- Amount
- Status badge
- Action: View/Edit

---

## 4. LearnDash Focus Mode Styling

### Color Overrides

| Element | LD Default | Stride Override |
|---------|-----------|-----------------|
| Sidebar bg | #2a2e30 | #2D3E50 |
| Accent | #00b4db | #FF8C42 |
| Progress | green | #4ECDC4 |
| Content bg | white | #FAFBFC |

### Sidebar Redesign

- Back button → Stride dashboard
- Course title prominent
- Progress bar (teal)
- Lesson list with status icons
- Softer typography (Inter)

### Content Area

- Generous padding (48px)
- Max width: 720px
- Video player: rounded corners, subtle shadow
- Navigation: Stride button styles

### Quiz Interface

- Large touch targets (56px min)
- Selected: orange border + light bg
- Correct: teal highlight + subtle animation
- Incorrect: gentle red, encouraging message

### Completion Celebration

- Brief confetti animation (3s)
- Encouraging message
- Progress update
- Clear next action CTA

### Mobile Focus Mode

- Sidebar becomes slide-out drawer
- Sticky bottom prev/next navigation
- Full-width video
- Touch-friendly quiz answers

---

## 5. PWA Setup

### Manifest

```json
{
  "name": "Stride LMS",
  "short_name": "Stride",
  "start_url": "/mijn-account/",
  "display": "standalone",
  "theme_color": "#2D3E50",
  "background_color": "#FAFBFC"
}
```

### Icons Required

- 192x192 (Android homescreen)
- 512x512 (splash, Play Store)
- 180x180 (iOS apple-touch-icon)
- Maskable variant

### Service Worker Strategy

**Network-first with fallback:**
- Cache: App shell (CSS, JS, fonts), static images, recently viewed pages
- Fresh: Dynamic content (progress, calendar), LearnDash course content

### Offline Indicator

Subtle top banner when offline, dismissible.

### Install Prompt

Custom prompt after 2nd visit:
- "Installeer Stride" modal
- iOS: manual instructions for Safari

### Performance Targets

| Metric | Target |
|--------|--------|
| FCP | < 1.5s |
| LCP | < 2.5s |
| TTI | < 3s |
| Lighthouse PWA | > 90 |

---

## Implementation Notes

### Files to Create/Modify

| File | Action |
|------|--------|
| `assets/css/stride-v2.css` | New design system CSS |
| `assets/css/focus-mode-overrides.css` | LD Focus Mode styling |
| `assets/js/pwa.js` | Service worker registration, install prompt |
| `manifest.json` | PWA manifest |
| `sw.js` | Service worker |
| `templates/shell/header.php` | New mobile header |
| `templates/shell/bottom-nav.php` | Bottom navigation |
| `templates/shell/footer.php` | Simplified footer |

### Dependencies

- Inter font (Google Fonts or self-hosted)
- Workbox (service worker library) - optional

### LearnDash Integration

Focus Mode theme override via:
```php
add_filter('learndash_focus_mode_template', 'stride_focus_mode_template');
```

Or CSS overrides via:
```php
add_action('wp_enqueue_scripts', function() {
    if (is_singular(['sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz'])) {
        wp_enqueue_style('stride-focus-mode', ...);
    }
});
```

---

## Future Considerations (v2+)

- Push notifications for reminders
- Offline course viewing (download lessons)
- Dark mode toggle
- More celebration animations
- Gamification elements (streaks, badges)
