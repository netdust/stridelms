# Stride Editorial Rebrand — Design Spec

**Date:** 2026-03-26
**Goal:** Apply a warm editorial design language to the Stride LMS theme for a client demo targeting Belgian healthcare organizations. The design must be swappable via the existing client customization system.

---

## Scope

| Layer | What changes |
|-------|-------------|
| `tokens.css` | Full palette remap to editorial colors, new fonts, adjusted shadows/radius |
| `components.css` | Editorial tweaks: rounded-full buttons, no-border cards, glass nav, surface-shift cards |
| `header.php` | Glass navigation (backdrop-blur), editorial typography for logo, no solid border |
| `footer.php` | Editorial footer: warm surface, no top border, serif branding line |
| `front-page.php` | **New structure**: editorial hero, course cards, mission section, testimonial, CTA |
| `base.css` | Add editorial base styles (serif font import, glass-nav utility, blur-blob) |
| `tailwind.config.js` | Add `font-serif` family, surface container variants |
| Google Fonts | Replace Inter with Newsreader (headings) + Plus Jakarta Sans (body/UI) |

**Not touched:** Dashboard structure, course detail structure, enrollment form structure, catalog page structure, Alpine.js components, PHP services, data layer.

---

## 1. Color System (tokens.css)

Map the Stitch "Curated Sanctuary" palette to Stride's existing CSS custom property system.

```css
:root {
  /* ── Brand Colors ── */
  --color-primary: 8 106 105;          /* #086a69 deep teal — trust, healthcare */
  --color-primary-hover: 0 93 92;      /* #005d5c teal darker */
  --color-primary-subtle: 156 235 232; /* #9cebe8 teal light bg */
  --color-primary-light: 142 220 218;  /* #8edcda teal medium */
  --color-primary-dark: 0 88 87;       /* #005857 teal darkest */
  --color-accent: 152 72 45;          /* #98482d terracotta — warm, human */
  --color-accent-light: 244 145 113;  /* #f49171 terracotta light */

  /* ── Neutral Colors (warm paper) ── */
  --color-surface: 251 250 242;        /* #fbfaf2 warm paper */
  --color-surface-alt: 244 244 235;    /* #f4f4eb container-low */
  --color-surface-card: 255 255 255;   /* #ffffff container-lowest */
  --color-border: 177 179 167;         /* #b1b3a7 outline-variant — ghost borders only */
  --color-border-strong: 121 124 113;  /* #797c71 outline */
  --color-text: 49 51 43;             /* #31332b warm dark — never pure black */
  --color-text-muted: 94 96 86;       /* #5e6056 on-surface-variant */
  --color-text-inverse: 224 255 253;  /* #e0fffd on-primary */

  /* ── Status Colors ── */
  --color-success: 22 163 74;          /* keep green-600 */
  --color-warning: 217 119 6;          /* keep amber-600 */
  --color-error: 172 52 52;            /* #ac3434 warmer red */
  --color-info: 8 106 105;             /* same as primary */

  /* ── Badge Colors ── */
  --color-badge-open: 22 163 74;
  --color-badge-few: 234 179 8;
  --color-badge-full: 172 52 52;
  --color-badge-online: 8 106 105;     /* primary instead of indigo */
  --color-badge-free: 16 185 129;

  /* ── Typography ── */
  --font-sans: 'Plus Jakarta Sans', 'Manrope', system-ui, sans-serif;
  --font-heading: 'Plus Jakarta Sans', system-ui, sans-serif;
  --font-serif: 'Newsreader', Georgia, 'Times New Roman', serif;

  /* ── Spacing (increase for editorial breathing room) ── */
  --space-section: 6rem;    /* was 5rem */
  --space-block: 3.5rem;    /* was 3rem */
  --space-element: 1.5rem;

  /* ── Border Radius (rounder for friendly feel) ── */
  --radius-sm: 0.5rem;      /* was 0.375rem */
  --radius-md: 0.75rem;     /* was 0.5rem */
  --radius-lg: 1rem;        /* was 0.75rem */
  --radius-xl: 1.5rem;      /* was 1rem */

  /* ── Shadows (warm-tinted, diffused) ── */
  --shadow-xs: 0 1px 2px rgba(49, 51, 43, 0.03);
  --shadow-sm: 0 2px 6px rgba(49, 51, 43, 0.04);
  --shadow-md: 0 8px 16px -4px rgba(49, 51, 43, 0.06);
  --shadow-lg: 0 20px 40px rgba(49, 51, 43, 0.06);
  --shadow-overlay: 0 24px 48px -12px rgba(49, 51, 43, 0.12);

  /* ── Editorial Additions ── */
  --color-tertiary: 129 82 0;          /* #815200 amber — energy accent */
  --color-tertiary-light: 255 185 92;  /* #ffb95c amber light */
  --color-secondary-container: 221 246 239; /* #ddf6ef soft teal bg */
  --color-surface-container: 238 238 228;   /* #eeeee4 */
  --color-surface-container-high: 232 233 222; /* #e8e9de */
  --color-surface-container-highest: 226 228 215; /* #e2e4d7 */
}
```

### New surface tokens for Tailwind

Add to `tailwind.config.js`:
```js
surface: {
  DEFAULT: '...',
  alt: '...',      // maps to container-low
  card: '...',     // maps to container-lowest (white)
  container: 'rgb(var(--color-surface-container) / <alpha-value>)',
  'container-high': 'rgb(var(--color-surface-container-high) / <alpha-value>)',
  'container-highest': 'rgb(var(--color-surface-container-highest) / <alpha-value>)',
},
```

---

## 2. Typography

### Font Loading (header.php)

Replace current Google Fonts link:
```html
<!-- Old -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700&display=swap" rel="stylesheet">

<!-- New -->
<link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,300..800;1,6..72,300..800&family=Plus+Jakarta+Sans:wght@300..800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
```

### Usage Rules

| Element | Font | Weight | Notes |
|---------|------|--------|-------|
| Display headlines (hero) | Newsreader | 300-400 (light) | Italic for emphasis words |
| Section headings | Plus Jakarta Sans | 600-700 | Clean, authoritative |
| Body text | Plus Jakarta Sans | 400 | Line-height 1.6 |
| Labels, metadata | Manrope | 500 | Small, tracking-wide |
| UI elements (buttons, nav) | Plus Jakarta Sans | 500-600 | |

### Tailwind Config Addition

```js
fontFamily: {
  sans: ['var(--font-sans)'],
  heading: ['var(--font-heading)'],
  serif: ['var(--font-serif)'],     // NEW
  label: ['Manrope', 'var(--font-sans)'],  // NEW
},
```

---

## 3. Component Adaptations (components.css)

### Buttons — Rounder, Warmer

```css
.btn-primary {
  /* Change: rounded-lg → rounded-full, add gradient option */
  @apply rounded-full;
}

.btn-secondary {
  /* Change: remove border, use ghost style */
  @apply border-0 hover:bg-surface-alt;
}

.btn-ghost {
  /* Add tertiary underline variant */
}
```

### Cards — No-Border, Surface-Shift

```css
.card {
  /* Remove: border border-border/60 */
  /* Add: just shadow and bg-surface-card on surface-alt sections */
  @apply bg-surface-card rounded-xl border-0;
  box-shadow: var(--shadow-xs);
}

.card-interactive:hover {
  /* Warmer hover: ambient shadow, no border change */
  box-shadow: var(--shadow-md);
}
```

### Navigation — Glass Effect

```css
/* Header: glass nav instead of solid border */
header {
  backdrop-filter: blur(20px);
  background: rgb(var(--color-surface) / 0.8);
  border-bottom: none;  /* No-Line Rule */
}
```

### Badges — Softer Tones

Badges use `secondary-container` style instead of ring-based:
```css
.badge-open {
  @apply bg-green-50/80 text-green-800 ring-0;
}
```

### List Items — Surface Hover, No Border

```css
.list-item {
  /* Remove: border-b border-border/60 */
  /* Add: surface-shift hover, generous padding */
  @apply border-0 rounded-lg hover:bg-surface-container-high;
}
```

---

## 4. Header (header.php)

### Changes

1. **Glass effect**: Replace `bg-surface-card border-b border-border` with glass nav
2. **Logo typography**: If no custom logo, use serif italic style: `font-serif italic font-semibold text-accent`
3. **Nav links**: Lighter weight, hover uses primary color (not bg change)
4. **CTA button**: Primary button with rounded-full
5. **Dropdown**: Remove border, use shadow-lg only
6. **Remove** `<hr>` in mobile menu — use spacing instead

### Structure (unchanged)

- Logo left, nav center, user menu right
- Mobile hamburger toggle
- Same Alpine.js components

---

## 5. Footer (footer.php)

### Changes

1. **Background**: `bg-surface-alt` stays, but remove `border-t border-border`
2. **Brand column**: Add serif italic tagline
3. **Link hover**: `hover:text-accent` (terracotta) instead of `hover:text-primary`
4. **Section titles**: Use `font-label` (Manrope), smaller, tracking-widest
5. **Bottom bar**: Remove `border-t` — use generous spacing instead

---

## 6. Homepage (front-page.php) — New Structure

The homepage gets a complete editorial redesign. This is a content page and is client-swappable via the mu-plugin system.

### Sections

#### 6.1 Hero
- Full-width, warm paper background
- Decorative blur blobs (subtle, behind content)
- Large serif headline with italic accent words
- Subheading in body font
- Two CTAs: filled primary + ghost with arrow

```
"Versterk je zorgteam met deskundige opleidingen."
    ↓ italic accent on "deskundige"
[Bekijk opleidingen]  [Onze aanpak →]
```

#### 6.2 Learning Mode Selector (adapted from current)
- Keep the 3-card grid (Trajecten, Klassikaal, Online)
- Restyle cards: no border, surface-container-low bg, serif card titles
- Larger, more editorial card layout

#### 6.3 Featured Courses (adapted from current)
- Keep course card grid
- Restyle with editorial card treatment: tonal backgrounds, serif titles
- Navigation arrows (from Stitch design)
- Section heading: serif, with subtitle

#### 6.4 Mission/Value Section (new, from Stitch)
- Two-column asymmetric layout
- Left: serif heading, body text about the organization
- Right: image with slight rotation, floating quote card
- Uses surface-container-low background with rounded top corners

#### 6.5 Testimonial (new, from Stitch)
- Large centered blockquote in serif italic
- Quote icon above
- Student/participant name and role below
- Clean, spacious section

#### 6.6 CTA Section
- Simple centered layout
- Large serif heading "Klaar om te starten?"
- Subtitle + two buttons (primary + secondary)
- Serif italic closing tagline

### Content Language
All in Dutch (nl_BE). Healthcare-appropriate demo content:
- Course titles: "Palliatieve Zorg", "Eerste Hulp bij Psychische Problemen", "Communicatie in de Zorg"
- Testimonial from a fictional healthcare worker
- Mission text about professional development in healthcare

---

## 7. Base CSS Additions (base.css)

```css
/* Editorial utilities */
.glass-nav {
  backdrop-filter: blur(20px);
  background: rgb(var(--color-surface) / 0.8);
}

.blur-blob {
  filter: blur(80px);
  opacity: 0.12;
  pointer-events: none;
}

/* Serif headline utility */
.font-serif {
  font-family: var(--font-serif);
}
```

---

## 8. What Stays the Same

- **All Alpine.js components** — no changes
- **Dashboard structure** — sidebar, tabs, panels, cards (just restyled via tokens)
- **Course detail pages** — hero, tabs, sidebar (restyled via tokens)
- **Enrollment form** — multi-step flow (restyled via tokens)
- **Catalog pages** — grid layout, filters (restyled via tokens)
- **PHP services, data layer, routing** — zero changes
- **Template partials structure** — card-course, card-edition, badge-status etc. keep their HTML

---

## 9. Client Customization Proof

The demo proves swappability by showing that:
1. All visual changes flow through `tokens.css` custom properties
2. A client mu-plugin can override tokens via `client.css`
3. Content pages (homepage) can be overridden via `stridence_template_path` filter
4. The existing `stride-client-example` pattern works for full rebranding

---

## 10. Files Changed

| File | Type of change |
|------|---------------|
| `src/css/tokens.css` | Full token remap |
| `src/css/base.css` | Add editorial utilities (glass-nav, blur-blob, font-serif) |
| `src/css/components.css` | Editorial component adaptations |
| `tailwind.config.js` | Add font-serif, surface container variants, tertiary color |
| `header.php` | Glass nav, font swap, editorial logo styling |
| `footer.php` | Remove borders, editorial typography |
| `front-page.php` | Complete rewrite with editorial structure |

**Zero backend changes.** This is purely a frontend/CSS/template task.
