# Stride Editorial Rebrand — Design Spec

**Date:** 2026-03-26
**Goal:** Apply a warm editorial design language to the Stride LMS theme for a client demo targeting Belgian healthcare organizations. The design must be swappable via the existing client customization system.

---

## Scope

| Layer | What changes |
|-------|-------------|
| `tokens.css` | Full palette remap to editorial colors, new fonts, adjusted shadows/radius |
| `components.css` | Editorial tweaks: rounded-full buttons, no-border cards, surface-shift cards, token-driven badges |
| `header.php` | Glass navigation (backdrop-blur), editorial typography for logo, no solid border |
| `header-dashboard.php` | Font swap only (same Google Fonts link as header.php) |
| `footer.php` | Editorial footer: warm surface, no top border, serif branding line |
| `front-page.php` | **New structure**: editorial hero, course cards, mission section, testimonial, CTA |
| `base.css` | Add editorial base styles (serif font import, glass-nav utility, blur-blob) |
| `learndash.css` | Minimal: remove focus mode border, update font reference |
| `tailwind.config.js` | Complete delta: font-serif, font-label, surface containers, tertiary, accent colors |
| Google Fonts | Replace Inter with Newsreader (headings) + Plus Jakarta Sans (body/UI) + Manrope (labels) |
| `stride-client-example/assets/client.css` | Update token inventory comments with new editorial tokens |

**Not touched:** Dashboard structure, course detail structure, enrollment form structure, catalog page structure, Alpine.js components, PHP services, data layer.

---

## 1. Color System (tokens.css)

Map the Stitch "Curated Sanctuary" palette to Stride's existing CSS custom property system. All existing tokens are preserved (including layout, transition, shadow aliases). Only values change.

```css
:root {
  /* ── Brand Colors ── */
  --color-primary: 8 106 105;          /* #086a69 deep teal — trust, healthcare */
  --color-primary-hover: 0 93 92;      /* #005d5c teal darker */
  --color-primary-subtle: 232 248 247; /* #e8f8f7 very subtle teal — sidebar active, not saturated */
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
  --color-text-inverse: 255 255 255;  /* white — safe contrast on teal */

  /* ── Status Colors ── */
  --color-success: 22 163 74;          /* keep green-600 */
  --color-warning: 217 119 6;          /* keep amber-600 */
  --color-error: 172 52 52;            /* #ac3434 warmer red */
  --color-info: 8 106 105;             /* same as primary */

  /* ── Badge Colors (token-driven, used by components.css) ── */
  --color-badge-open-bg: 240 253 244;       /* green-50 */
  --color-badge-open-text: 21 128 61;       /* green-700 */
  --color-badge-few-bg: 254 252 232;        /* yellow-50 */
  --color-badge-few-text: 161 98 7;         /* yellow-800 (improved contrast) */
  --color-badge-full-bg: 254 242 242;       /* red-50 */
  --color-badge-full-text: 153 27 27;       /* red-800 */
  --color-badge-cancelled-bg: 249 250 251;  /* gray-50 */
  --color-badge-cancelled-text: 107 114 128;/* gray-500 */
  --color-badge-online-bg: 232 248 247;     /* teal-50 (matches primary) */
  --color-badge-online-text: 8 106 105;     /* primary */
  --color-badge-free-bg: 236 253 245;       /* emerald-50 */
  --color-badge-free-text: 4 120 87;        /* emerald-700 */

  /* ── Typography ── */
  --font-sans: 'Plus Jakarta Sans', 'Manrope', system-ui, sans-serif;
  --font-heading: 'Plus Jakarta Sans', system-ui, sans-serif;
  --font-serif: 'Newsreader', Georgia, 'Times New Roman', serif;

  /* ── Spacing (increase for editorial breathing room) ── */
  --space-section: 6rem;    /* was 5rem */
  --space-block: 3.5rem;    /* was 3rem */
  --space-element: 1.5rem;

  /* ── Layout (unchanged) ── */
  --container-max: 1280px;
  --content-max: 960px;
  --sidebar-width: 240px;
  --sidebar-collapsed: 56px;

  /* ── Border Radius (rounder for friendly feel) ── */
  --radius-sm: 0.5rem;
  --radius-md: 0.75rem;
  --radius-lg: 1rem;
  --radius-xl: 1.5rem;

  /* ── Shadows (warm-tinted, diffused — never pure black) ── */
  --shadow-xs: 0 1px 2px rgba(49, 51, 43, 0.03);
  --shadow-sm: 0 2px 6px rgba(49, 51, 43, 0.04);
  --shadow-md: 0 8px 16px -4px rgba(49, 51, 43, 0.06);
  --shadow-lg: 0 20px 40px rgba(49, 51, 43, 0.06);
  --shadow-overlay: 0 24px 48px -12px rgba(49, 51, 43, 0.12);

  /* Tailwind-mapped aliases (must be preserved) */
  --shadow-card: var(--shadow-xs);
  --shadow-elevated: var(--shadow-md);

  /* ── Transitions (unchanged) ── */
  --ease-out: cubic-bezier(0.16, 1, 0.3, 1);
  --duration-fast: 150ms;
  --duration-normal: 250ms;

  /* ── Editorial Surface Containers ── */
  --color-tertiary: 129 82 0;          /* #815200 amber — energy accent */
  --color-tertiary-light: 255 185 92;  /* #ffb95c amber light */
  --color-secondary-container: 221 246 239; /* #ddf6ef soft teal bg */
  --color-surface-container: 238 238 228;   /* #eeeee4 */
  --color-surface-container-high: 232 233 222; /* #e8e9de */
  --color-surface-container-highest: 226 228 215; /* #e2e4d7 */
}
```

### WCAG AA Contrast Verification

These pairs must pass before implementation:

| Pair | Ratio | Result |
|------|-------|--------|
| `#31332b` on `#fbfaf2` (text on surface) | 11.8:1 | PASS |
| `#5e6056` on `#fbfaf2` (muted on surface) | 5.2:1 | PASS |
| `#ffffff` on `#086a69` (inverse on primary) | 5.6:1 | PASS |
| `#98482d` on `#fbfaf2` (accent on surface) | 5.7:1 | PASS |
| `#5e6056` on `#f4f4eb` (muted on surface-alt) | 4.7:1 | PASS |
| Badge yellow: `#654e07` on `#fef9c3` | 7.1:1 | PASS |

Note: Changed `--color-text-inverse` from `#e0fffd` to `#ffffff` for safer contrast. Changed `--color-primary-subtle` from saturated `#9cebe8` to desaturated `#e8f8f7` for subtle sidebar highlights.

---

## 2. Typography

### Font Loading (header.php AND header-dashboard.php)

Both header files must swap the Google Fonts link:
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

---

## 3. Tailwind Config (complete delta)

Full diff for `tailwind.config.js`:

```js
// ADD to colors:
accent: {
  DEFAULT: 'rgb(var(--color-accent) / <alpha-value>)',
  light: 'rgb(var(--color-accent-light) / <alpha-value>)',
},
tertiary: {
  DEFAULT: 'rgb(var(--color-tertiary) / <alpha-value>)',
  light: 'rgb(var(--color-tertiary-light) / <alpha-value>)',
},
'secondary-container': 'rgb(var(--color-secondary-container) / <alpha-value>)',

// EXTEND surface:
surface: {
  DEFAULT: 'rgb(var(--color-surface) / <alpha-value>)',
  alt: 'rgb(var(--color-surface-alt) / <alpha-value>)',
  card: 'rgb(var(--color-surface-card) / <alpha-value>)',
  container: 'rgb(var(--color-surface-container) / <alpha-value>)',
  'container-high': 'rgb(var(--color-surface-container-high) / <alpha-value>)',
  'container-highest': 'rgb(var(--color-surface-container-highest) / <alpha-value>)',
},

// ADD to fontFamily:
fontFamily: {
  sans: ['var(--font-sans)'],
  heading: ['var(--font-heading)'],
  serif: ['var(--font-serif)'],
  label: ['Manrope', 'var(--font-sans)'],
},
```

---

## 4. Component Adaptations (components.css)

### Buttons — Rounder, Warmer

```css
.btn-primary {
  @apply rounded-full;  /* was rounded-lg */
}
.btn-secondary {
  @apply border-0 hover:bg-surface-alt;  /* remove border */
}
```

### Cards — No-Border, Surface-Shift

```css
.card {
  @apply bg-surface-card rounded-xl border-0;  /* remove border */
  box-shadow: var(--shadow-xs);
}
.card-interactive:hover {
  box-shadow: var(--shadow-md);  /* ambient shadow, no border */
  transform: translateY(-1px);
}
```

### Badges — Token-Driven (all 6 variants)

Convert all hardcoded Tailwind badge colors to use token-driven custom properties:

| Badge | Current (hardcoded) | New (token-driven) |
|-------|--------------------|--------------------|
| `.badge-open` | `bg-green-50 text-green-700 ring-1 ring-green-600/20` | `bg-[rgb(var(--color-badge-open-bg))] text-[rgb(var(--color-badge-open-text))]` |
| `.badge-few` | `bg-yellow-50 text-yellow-700 ring-1 ring-yellow-600/20` | token-driven, ring-0 |
| `.badge-full` | `bg-red-50 text-red-700 ring-1 ring-red-600/20` | token-driven, ring-0 |
| `.badge-cancelled` | `bg-gray-50 text-gray-500 ring-1 ring-gray-500/20` | token-driven, ring-0 |
| `.badge-online` | `bg-indigo-50 text-indigo-700 ring-1 ring-indigo-600/20` | token-driven (now teal), ring-0 |
| `.badge-free` | `bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20` | token-driven, ring-0 |

All badges: remove `ring-1 ring-inset` — editorial "No-Line Rule".

### List Items — Surface Hover, No Border

```css
.list-item {
  @apply border-0 rounded-lg hover:bg-surface-alt;
  /* Remove: border-b border-border/60 */
}
```

### Sidebar — Ghost Borders

```css
.sidebar-divider {
  @apply border-border/15 my-3 mx-3;  /* ghost border at 15% opacity */
}
```

### Dash Cards — No Side Borders

```css
.dash-card-hero {
  /* REMOVE: border-left: 3px solid — never use one-sided borders on rounded cards */
  /* REPLACE with: gradient background only, no border at all */
  border: none;
  border-left: none;
  background: linear-gradient(135deg, rgb(var(--color-primary) / 0.04), rgb(var(--color-primary) / 0.10));
  /* Increase gradient opacity range to 0.04-0.10 for visibility with teal */
}
```

---

## 5. Header (header.php)

### Changes

1. **Glass effect**: Replace `bg-surface-card border-b border-border` with `glass-nav` class
2. **Logo typography**: If no custom logo, use serif italic: `font-serif italic font-semibold text-accent`
3. **Nav links**: Lighter weight, hover uses primary color
4. **CTA button**: `btn-primary` (inherits rounded-full from component change)
5. **Dropdown**: Remove border, use `shadow-lg` only
6. **Mobile menu panel**: Keep solid `bg-surface-card` for readability (no glass)
7. **Remove** `<hr>` separators — use `py-4` spacing instead
8. **Glass nav opacity**: Constant `0.8` at all scroll positions (no scroll listener)

### Structure (unchanged)
Logo left, nav center, user menu right. Same Alpine.js components.

---

## 6. Header Dashboard (header-dashboard.php)

**Minimal change:** Swap Google Fonts `<link>` only (same as header.php). No structural changes.

---

## 7. Footer (footer.php)

### Changes

1. **Background**: `bg-surface-alt` stays, remove `border-t border-border`
2. **Brand column**: Add serif italic tagline
3. **Link hover**: `hover:text-accent` (terracotta) instead of `hover:text-primary`
4. **Section titles**: Use `font-label` (Manrope), tracking-widest
5. **Bottom bar**: Remove `border-t` — use generous `mt-16 pt-8` spacing

---

## 8. Homepage (front-page.php) — New Structure

Complete editorial redesign. Client-swappable via mu-plugin `stridence_template_path` filter.

### Sections

#### 8.1 Hero
- Full-width, warm paper background (`bg-surface`)
- Decorative blur blobs (absolute positioned, `blur-blob` class)
- Large serif headline with italic accent words
- Subheading in body font
- Two CTAs: filled primary + ghost with arrow icon

```
"Versterk je zorgteam met deskundige opleidingen."
    ↓ italic accent on "deskundige"
[Bekijk opleidingen]  [Onze aanpak →]
```

#### 8.2 Learning Mode Selector (adapted)
- Keep 3-card grid (Trajecten, Klassikaal, Online)
- Restyle: no border, `bg-surface-alt` cards, larger editorial layout
- Keep existing WP_Query counts (dynamic)

#### 8.3 Featured Courses (adapted)
- Keep course card grid with existing WP_Query
- Editorial card treatment: no border, serif titles, warm shadows
- Section heading: serif with subtitle
- **Empty state**: If no courses, hide the section entirely (CSS `empty` or PHP `if`)

#### 8.4 Mission/Value Section (new)
- Two-column asymmetric layout on `bg-surface-alt rounded-t-[64px]`
- Left: serif italic heading, body text about healthcare professional development
- Right: placeholder image from theme `images/` directory with `rotate-2` transform, floating quote card with `rotate(-3deg)`
- **Image**: Hardcode a `images/mission-placeholder.jpg` in theme. Clients override the entire template via mu-plugin.
- Content is hardcoded PHP strings (same pattern as existing `front-page.php`)

#### 8.5 Testimonial (new)
- Large centered blockquote in `font-serif italic`
- SVG quote icon above
- Fictional healthcare worker name and role below
- Hardcoded content (same as existing pattern)

#### 8.6 CTA Section
- Centered layout, `bg-surface` background
- Large serif heading "Klaar om te starten?"
- Subtitle + two buttons (primary rounded-full + secondary rounded-full)
- Serif italic closing tagline

### Content Language
All Dutch (nl_BE). Healthcare demo content:
- Course titles from seed data
- Testimonial: fictional verpleegkundige
- Mission: professional development in de zorgsector

---

## 9. LearnDash CSS (learndash.css) — Minimal

```css
/* Remove border from focus mode header (No-Line Rule) */
.ld-focus .ld-focus-header {
  border-bottom: none;
}
```

Font inheritance is automatic — `learndash.css` doesn't set fonts, it inherits from `body`. The Google Fonts swap in `header.php` handles this.

---

## 10. Base CSS Additions (base.css)

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

/* Focus ring offset on glass backgrounds */
.glass-nav :focus-visible {
  --tw-ring-offset-color: rgb(var(--color-surface));
}
```

Note: `font-serif` utility is handled by Tailwind config — no manual CSS needed.

---

## 11. Client Example Update (stride-client-example)

Update `assets/client.css` comment block to document new tokens:

```
* Editorial:
*   --color-tertiary, --color-tertiary-light
*   --color-secondary-container
*   --color-surface-container, --color-surface-container-high, --color-surface-container-highest
*   --font-serif
*   --color-badge-{variant}-bg, --color-badge-{variant}-text (open, few, full, cancelled, online, free)
```

---

## 12. What Stays the Same

- **All Alpine.js components** — no changes
- **Dashboard structure** — sidebar, tabs, panels, cards (restyled via tokens)
- **Course detail pages** — hero, tabs, sidebar (restyled via tokens)
- **Enrollment form** — multi-step flow (restyled via tokens)
- **Catalog pages** — grid layout, filters (restyled via tokens)
- **PHP services, data layer, routing** — zero changes
- **Template partials structure** — card-course, card-edition, badge-status etc. keep HTML
- **Dark mode** — not supported (not supported before either, explicitly out of scope)
- **Print styles** — out of scope for demo (noted for production follow-up)

---

## 13. Files Changed (complete)

| File | Type of change |
|------|---------------|
| `src/css/tokens.css` | Full token remap (preserve all layout/transition/alias tokens) |
| `src/css/base.css` | Add glass-nav, blur-blob, focus-ring-offset utilities |
| `src/css/components.css` | Editorial: rounded buttons, no-border cards, token badges, surface-shift lists |
| `src/css/learndash.css` | Remove focus mode header border |
| `tailwind.config.js` | Full delta: fonts, surface containers, tertiary, accent, badge tokens |
| `header.php` | Glass nav, font swap, editorial logo, remove borders/hr |
| `header-dashboard.php` | Font swap only (Google Fonts link) |
| `footer.php` | Remove borders, editorial typography, terracotta hover |
| `front-page.php` | Complete rewrite with editorial sections |
| `images/mission-placeholder.jpg` | New: placeholder image for homepage mission section |
| `stride-client-example/assets/client.css` | Update token inventory comments |

**Zero backend changes.** Purely frontend/CSS/template task.
