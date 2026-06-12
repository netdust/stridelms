# Stridence Token Dictionary

The contract every Stride client `client.css` must satisfy.

This is the **dictionary** for Claude Design (or any designer): exact token names,
exact format, and the component classes already in the theme. If a `client.css`
output uses different token names or a different format, it will silently fail —
the theme doesn't know what to do with it.

Pair this with `CLIENT-IDENTITY-TEMPLATE.md` (the brief format) and the
CareCommunity reference (`web/app/mu-plugins/stride-client-carecommunity/`) to
generate a complete client identity.

---

## Why this format (rationale)

### Why RGB triplets, not hex

Stridence stores colors as space-separated RGB components:
```css
--color-primary: 8 106 105;        /* not #086a69 */
```

And consumes them through Tailwind's modern `rgb()` syntax:
```css
background: rgb(var(--color-primary) / 0.85);   /* 85% opacity */
border: 1px solid rgb(var(--color-primary));
```

This lets every token participate in opacity composition. A `#086a69` hex string
can't be opacity-shifted at consumption time — you'd need a separate token for
every opacity level. The triplet form means one token works for solid fills,
glass effects, focus rings, hover tints, ghost borders, watermarks — all from
the same source value.

**Consequence for design output:** every color token must be three
space-separated integers (0-255). No `#hex`, no `rgb()`, no `hsl()`. The hex
goes in a `/* comment */` next to the triplet for human reference only.

### Why these specific token names

Stridence's tokens are consumed in two places:
1. The theme's CSS (`themes/stridence/src/css/`)
2. Tailwind config (`themes/stridence/tailwind.config.js`)

Tailwind maps `--color-primary` to utility class `bg-primary`, `--color-surface`
to `bg-surface`, etc. **Renaming a token breaks the Tailwind utility class
that consumes it.** This is why client overrides cannot invent new token names —
they must override the existing names.

A client `client.css` that defines `--c-ocean-500` does nothing. The theme
never reads that name. It must override `--color-primary`.

### Why the 5-tier surface ladder exists

Material Design 3's tonal layering — used for elevation without shadows.
Cards, panels, and modals stack by tonal shift rather than by `box-shadow`.
This is what makes "no hard borders" designs possible.

```
--color-surface                          (base — page bg)
--color-surface-alt                      (slight shift — section bg)
--color-surface-card                     (card bg — lifted from page)
--color-surface-container                (emphasized panel)
--color-surface-container-high           (modal, dropdown)
--color-surface-container-highest        (overlay, popover)
```

A design that wants flat surfaces still defines all six — just with closer
tonal distance between them.

---

## The token contract

### Brand colors

Every client `client.css` must override these.

| Token | Default (Stridence) | Role |
|---|---|---|
| `--color-primary` | `8 106 105` (#086a69 deep teal) | Primary buttons, active states, focused inputs |
| `--color-primary-hover` | `0 93 92` (#005d5c) | Primary button hover |
| `--color-primary-subtle` | `232 248 247` (#e8f8f7) | Tinted backgrounds, focus rings, soft accents |
| `--color-primary-light` | `142 220 218` (#8edcda) | Decorative accent, illustration fills |
| `--color-primary-dark` | `0 88 87` (#005857) | Dark sections (`.dark-section`), inverted surfaces |
| `--color-accent` | `152 72 45` (#98482d terracotta) | Links, progress bars, secondary emphasis |
| `--color-accent-light` | `244 145 113` (#f49171) | Accent hover, illustration highlight |

### Neutral colors

| Token | Default | Role |
|---|---|---|
| `--color-surface` | `251 250 242` (#fbfaf2 warm paper) | Body background |
| `--color-surface-alt` | `244 244 235` (#f4f4eb) | Section background shifts |
| `--color-surface-card` | `255 255 255` (#ffffff) | Card / panel surfaces |
| `--color-border` | `177 179 167` (#b1b3a7) | Ghost borders — use sparingly |
| `--color-border-strong` | `121 124 113` (#797c71) | Form fields, deliberate dividers |
| `--color-text` | `49 51 43` (#31332b) | Body text — **never pure black** |
| `--color-text-muted` | `94 96 86` (#5e6056) | Secondary text, meta, captions |
| `--color-text-inverse` | `255 255 255` | Text on dark surfaces |

### 5-tier surface ladder

| Token | Default | Role |
|---|---|---|
| `--color-surface-container` | `238 238 228` | Emphasized panels |
| `--color-surface-container-high` | `232 233 222` | Modals, dropdowns |
| `--color-surface-container-highest` | `226 228 215` | Overlays, popovers |
| `--color-tertiary` | `129 82 0` | Tertiary accent (rare) |
| `--color-tertiary-light` | `255 185 92` | Tertiary highlight |
| `--color-secondary-container` | `221 246 239` | Secondary container fills |

### Status colors

| Token | Default | Role |
|---|---|---|
| `--color-success` | `22 163 74` | Confirmations, "open", "available" |
| `--color-warning` | `217 119 6` | Soft warnings, "few seats", "pending" |
| `--color-error` | `172 52 52` (warmer than pure red) | Errors, "full", "cancelled" |
| `--color-info` | `8 106 105` (matches primary by default) | Informational |

### Badge colors (state-specific)

These drive registration/edition status badges. Each has a `-bg` and `-text` pair.

| State | `-bg` token | `-text` token |
|---|---|---|
| Open | `--color-badge-open-bg` | `--color-badge-open-text` |
| Few seats | `--color-badge-few-bg` | `--color-badge-few-text` |
| Full | `--color-badge-full-bg` | `--color-badge-full-text` |
| Cancelled | `--color-badge-cancelled-bg` | `--color-badge-cancelled-text` |
| Online | `--color-badge-online-bg` | `--color-badge-online-text` |
| Free | `--color-badge-free-bg` | `--color-badge-free-text` |

Always tune these to the brand palette — defaults are tinted green/amber/red,
but a teal-only palette wants its open/online states tinted teal, etc.

### Typography

| Token | Default | Role |
|---|---|---|
| `--font-sans` | `'Plus Jakarta Sans', 'Manrope', system-ui, ...` | Body, UI, labels |
| `--font-heading` | `'Plus Jakarta Sans', system-ui, sans-serif` | H1–H4 |
| `--font-serif` | `'Newsreader', Georgia, ...` | Editorial fragments, pull quotes |
| `--font-label` | `'Manrope', system-ui, sans-serif` | Forms, micro UI |

**Rationale for four font tokens:** lets a brand have one font for everything
(sans=heading=label) or maximum differentiation (different family per role). Most
brands use two distinct families and assign them across the four tokens.

The Google Fonts URL is swapped separately via the
`stridence_font_url` filter — see the CareCommunity plugin file for the pattern.

### Spacing

| Token | Default | Role |
|---|---|---|
| `--space-section` | `6rem` | Vertical padding between major sections |
| `--space-block` | `3.5rem` | Inner blocks within a section |
| `--space-element` | `1.5rem` | Between related elements (heading→body) |

Stridence does **not** ship a full numeric scale (`--s-1` through `--s-32`).
Tailwind utilities (`p-4`, `gap-6`) handle micro-spacing. The three space tokens
above only control macro section rhythm.

### Layout (do not override unless you mean it)

| Token | Default |
|---|---|
| `--container-max` | `1280px` |
| `--content-max` | `960px` |
| `--sidebar-width` | `240px` |
| `--sidebar-collapsed` | `56px` |

### Border radius

| Token | Default | Role |
|---|---|---|
| `--radius-sm` | `0.5rem` | Small inputs, tags |
| `--radius-md` | `0.75rem` | Buttons, fields |
| `--radius-lg` | `1rem` | Cards, panels |
| `--radius-xl` | `1.5rem` | Hero blocks, large surfaces |

**Pill buttons:** brands wanting round-pill buttons override individual classes
(`.btn-primary { border-radius: 9999px; }`) rather than the token — the token
should keep its scale meaning. See CareCommunity for the pattern.

### Shadows

| Token | Default | Role |
|---|---|---|
| `--shadow-xs` | `0 1px 2px rgba(49, 51, 43, 0.03)` | Cards at rest |
| `--shadow-sm` | `0 2px 6px rgba(49, 51, 43, 0.04)` | Hover lift |
| `--shadow-md` | `0 8px 16px -4px rgba(49, 51, 43, 0.06)` | Elevated surfaces |
| `--shadow-lg` | `0 20px 40px rgba(49, 51, 43, 0.06)` | Modals |
| `--shadow-overlay` | `0 24px 48px -12px rgba(49, 51, 43, 0.12)` | Dropdowns, popovers |
| `--shadow-card` | `var(--shadow-xs)` | Alias for card default |
| `--shadow-elevated` | `var(--shadow-md)` | Alias for hover/active card |

**Rationale for warm-tinted shadows:** the default RGB `49, 51, 43` is the body
text color (`--color-text`), giving every shadow a warm undertone matching the
paper-white surface. A teal-palette brand should retint shadows with its own
text color so depth feels coherent. Pure-black shadows on a warm palette look
foreign.

### Motion

| Token | Default | Role |
|---|---|---|
| `--ease-out` | `cubic-bezier(0.16, 1, 0.3, 1)` | Default curve |
| `--duration-fast` | `150ms` | Hover, focus, color |
| `--duration-normal` | `250ms` | Reveals, dismissals, content swap |

**Rationale:** Stridence defaults are snappy. A "compassionate" or "premium"
brand should slow these to 200ms/350ms+. See CareCommunity for the pattern.

---

## Component class inventory

These classes exist in the Stridence theme. A `client.css` re-skins them by
re-declaring rules; it does not need to define them from scratch. Most brands
will override only the ones their identity actually shifts.

### Buttons
- `.btn-primary` — primary action (uses `--color-primary`)
- `.btn-secondary` — secondary action
- `.btn-accent` — accent action (uses `--color-accent`)
- `.btn-ghost` — minimal, link-like
- `.btn-danger` — destructive
- `.btn-sm` / `.btn-lg` — size modifiers
- `.btn-outline-dark` / `.btn-outline-light` — outline variants

### Cards & surfaces
- `.card` — base card
- `.card-bordered` — bordered variant
- `.card-interactive` — hoverable card (lifts on hover)
- `.dash-card` — dashboard card (admin surfaces)
- `.dash-card-hero` — featured dashboard card
- `.dash-card-interactive` — hoverable dashboard card
- `.dash-panel` — admin panel container
- `.dash-heading` / `.dash-subheading` — admin typography

### Navigation & layout
- `.glass-nav` — sticky frosted-glass navigation
- `.dark-section` — inverted (dark) section block
- `.hero-watermark` — hero decoration layer

### Marketing surfaces
- `.tag-pill` — small pill/badge
- `.stat-card-lime` — stat highlight card (lime accent by default; rename or
  retint per brand)
- `.prose-stride` — editorial body content (used for long-form text)

### Selectors that often need overrides
- `::selection` — text selection color
- `a` — link color and transition
- `footer` — bottom edge of pages (border-top behavior)
- `@media (prefers-reduced-motion: reduce)` — honor user motion preference

---

## Output requirements for `client.css`

A `client.css` produced from this dictionary must:

1. **Start with a `:root { ... }` block** that overrides every token the brand
   shifts. Tokens not overridden inherit Stridence defaults.

2. **Use the exact token names above.** No invented names (`--c-ocean`,
   `--brand-teal`, etc.). The theme cannot consume them.

3. **Use RGB-triplet format for every color token.** Hex in `/* comment */`
   only.

4. **Override component classes by re-declaring rules** that target the
   existing class names. Do not invent new component classes — the theme's
   markup uses the names above.

5. **Be a single file.** No `@import`. The mu-plugin loads one CSS file via
   `wp_enqueue_style`. Multiple files would require plugin changes.

6. **Respect `prefers-reduced-motion`** if the brand introduces slower or richer
   transitions than the defaults.

---

## Reference

- **Authoritative token source:** `web/app/themes/stridence/src/css/tokens.css`
- **Working override example:** `web/app/mu-plugins/stride-client-carecommunity/assets/client.css`
- **Plugin scaffold to copy:** `web/app/mu-plugins/stride-client-carecommunity/stride-client-carecommunity.php`
- **Brief format:** `docs/CLIENT-IDENTITY-TEMPLATE.md`
