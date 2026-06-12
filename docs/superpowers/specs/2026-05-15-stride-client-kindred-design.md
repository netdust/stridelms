# stride-client-kindred — Design Spec

**Date:** 2026-05-15
**Type:** New mu-plugin (Stride client identity)
**Source material:** `~/Downloads/stridelms brand.zip` (extracted to `/tmp/stridelms-brand/`)
**Reference implementation:** `web/app/mu-plugins/stride-client-safeandsound/`
**Identity template:** `/tmp/stridelms-brand/uploads/CLIENT-IDENTITY-TEMPLATE.md`

---

## 1. Goal

Convert the `stridelms brand.zip` brand board into a working Stride LMS client mu-plugin
named `stride-client-kindred` representing a fictional HR training company **Kindred HR**.

The mu-plugin re-skins the existing Stridence theme — it does **not** create a marketing
site. The brand-board's homepage / pricing / case-study JSX pages in the zip are out of
scope. We extract the brand's tokens, typography, logo, hero pattern, and pillars, and
apply them to Stride's existing LMS surfaces (dashboard, course pages, enrollment, etc.).

## 2. File Structure

```
web/app/mu-plugins/stride-client-kindred/
├── stride-client-kindred.php          PHP bootstrap (modeled on safeandsound)
├── IDENTITY.md                         Filled-in CLIENT-IDENTITY-TEMPLATE
├── assets/
│   ├── client.css                      Tokens + component re-skin (~600 lines)
│   └── logo.svg                        Lifted from zip uploads/logo.svg
├── templates/
│   ├── front-page.php                  Homepage (8 sections — see §5)
│   └── page-stub.php                   Long-form narrow page template
└── patterns/
    ├── about.php                       Block patterns category "kindred"
    ├── contact.php
    ├── faq.php
    ├── agenda.php                      Editions/trainings agenda pattern
    └── terms.php                       Legal page pattern
```

**Total:** 11 files, ~1500 lines.

## 3. PHP Bootstrap (`stride-client-kindred.php`)

Modeled exactly on `stride-client-safeandsound.php`:

- Plugin header: `Plugin Name: Stride Client — Kindred HR`
- Class: `StrideClientKindred`, namespace global, `declare(strict_types=1)`
- `init()` registers:
  - `\NTDST_Template_Loader::addPath($this->dir . '/templates')`
  - `add_filter('template_include', 'overridePageTemplate', 20)` — front-page override
  - `add_action('wp_enqueue_scripts', 'enqueueStyles', 100)` — load `client.css`
  - `add_filter('stridence_font_url', 'overrideFontUrl')` — Google Fonts swap
  - `add_action('init', 'registerPatterns')` — auto-load `/patterns/*.php`
  - `add_filter('theme_page_templates', 'registerPageTemplates')` — register `kindred-page-stub.php`
  - `add_filter('template_include', 'resolvePageTemplate', 25)` — resolve page-stub
- `overrideFontUrl()` returns:
  ```
  https://fonts.googleapis.com/css2
    ?family=Geist:wght@300..700
    &family=Geist+Mono:wght@400..600
    &family=Instrument+Serif:ital@0;1
    &family=Fraunces:opsz,wght@9..144,300..700
    &display=swap
  ```
- `registerPatterns()` registers category slug `kindred`, label "Kindred HR".

## 4. Token Mapping (`assets/client.css`)

The zip's `brand-board.css` uses its own token names (`--bg`, `--accent`, `--ink`).
`client.css` re-maps them to Stridence vocabulary verbatim (see `CLIENT-IDENTITY-TEMPLATE.md`
§7 + `STRIDENCE-TOKENS.md`).

### Color mapping

| Brand-board (zip) | Stridence token | Hex |
|---|---|---|
| `--accent` (moss) | `--color-primary` | `#1f5b3d` |
| `--accent-deep` | `--color-primary-hover` / `--color-primary-dark` | `#0f3a25` |
| `--accent-soft` | `--color-primary-subtle` / `--color-primary-light` | `#bcd4c4` |
| `--bg` (stone) | `--color-surface` | `#f1f2ef` |
| `--bg-alt` | `--color-surface-alt` | `#e8eae5` |
| `--surface` | `--color-surface-card` | `#f9faf7` |
| `--ink` | `--color-text` | `#111312` |
| `--ink-soft` | (secondary text contexts: links, captions) | `#363a36` |
| `--muted` | `--color-text-muted` | `#6c706b` |
| `--line` | `--color-border-strong` | `#d4d7d0` |
| `--line-soft` | `--color-border` | `#e1e3dc` |
| `--signal-ok` | `--color-success` | `#1f5b3d` |
| `--signal-warn` | `--color-warning` | `#8a6a1c` |
| `--signal-err` | `--color-error` | `#8a2a1c` |

`--color-accent` (separate role from `--color-primary` in Stridence) is left equal to
`--color-primary` — the brand has a single accent, no secondary accent palette.

### Typography

| Stridence token | Family | Used for |
|---|---|---|
| `--font-sans` | `Geist, "Söhne", -apple-system, sans-serif` | Body, UI, labels |
| `--font-heading` | `Geist` (weight 400-500) | H1–H4. Not bold; brand is editorial, not shouty. |
| `--font-serif` | `Instrument Serif` italic | Editorial fragments, hero accent quotes |
| `--font-label` | `Geist Mono` | Eyebrows, meta strings, mono micro-copy |
| `.t-fraunces` (utility class) | `Fraunces` | Optional display alt on front-page only |

### Shape

| Stridence token | Value |
|---|---|
| `--radius-sm` | `4px` |
| `--radius-md` | `8px` |
| `--radius-lg` | `14px` |
| `--radius-xl` | `22px` |

Buttons use `--radius-md` (no pill). Cards use `--radius-lg`. Hero blocks use `--radius-xl`.

### Component re-skins

`client.css` overrides each existing Stridence class the brand visually shifts:

- Buttons: `.btn-primary`, `.btn-secondary`, `.btn-accent`, `.btn-outline-dark`,
  `.btn-outline-light`, `.btn-ghost`, `.btn-danger`, `.btn-sm`, `.btn-lg`
- Cards: `.card`, `.card-bordered`, `.card-interactive`, `.dash-card`, `.dash-card-hero`,
  `.dash-card-interactive`, `.dash-panel`
- Surfaces: `.glass-nav`, `.dark-section`, `.hero-watermark`, `.prose-stride`
- Forms: text/select/textarea/checkbox/radio inputs (border-color shift on focus +
  subtle ring), error/success states
- Tags: `.tag-pill`, status badges (open/few/full/cancelled/online/free)
- Browser: `::selection` (moss bg, surface text), `a` (underline-on-hover, ink-soft color),
  `footer` (subtle line-top, mono micro-copy)
- Motion: `@media (prefers-reduced-motion: reduce) { *{ transition: none !important; animation: none !important; } }`

## 5. Front-page Sequence (`templates/front-page.php`)

8 sections, server-rendered first:

1. **Header** — inherits `glass-nav` from theme (Stridence default), client.css re-skins
2. **Cover/Hero** — `.bb-cover`-style layout
   - Eyebrow (Geist Mono 11px tracked): `KINDRED HR — TRAINING & DEVELOPMENT`
   - Title (Geist 400, clamp 44-96px, letter-spacing -0.035em, max 16ch):
     "Trainingen voor mensen die werken met mensen."
   - Right meta column (Geist Mono): `v2026.1 · NL/BE`
   - Intro split (130px label col + 1fr): one paragraph Instrument Serif italic, one
     paragraph Geist
3. **Pillars** — 5-column grid (`.bb-pillars`), numbered 01–05:
   - 01 Leiderschap · 02 Communicatie · 03 Welzijn · 04 Coaching · 05 Compliance
4. **Upcoming trainings** — server-rendered via `WP_Query` against `vad_edition` CPT
   (the safeandsound pattern). Query: 6 most recent published editions excluding
   `_ntdst_status` of draft/completed/archived. Card style: bordered tonal lift,
   icon-led, max 3-col on desktop. Each card links to the edition's permalink.
5. **Featured trajectory** — split 60-40, image left, copy right. `get_posts` against
   `sfwd-courses` (or trajectory CPT if available) for one featured item; if none,
   render a hand-written copy block pointing at `/vormingen`.
6. **Quote** — single Instrument Serif italic blockquote, large (clamp 24-40px),
   centered with author meta in Geist Mono.
7. **Closer / CTA** — sans heading "Klaar om te starten?" + primary button linking to
   `/vormingen`. Tonal shift to `--color-surface-alt`.
8. **Footer** — inherits theme footer, client.css re-skins (mono micro-copy, line-top,
   stone surface).

**Hard rules:**
- No sliders, marquees, parallax — violates "slow, premium, editorial" motion personality
- No stock photography — brand-board explicitly forbids
- Dynamic content via `WP_Query` / `get_posts` directly in the template — this matches
  the safeandsound reference pattern (no public `[stride_editions]` shortcode exists)
- Honors `prefers-reduced-motion`

## 6. IDENTITY.md content (highlights)

Filled-in copy of CLIENT-IDENTITY-TEMPLATE.md. Key sections:

- **§1 Positioning:** "Kindred HR helpt organisaties trainen, coachen en ontwikkelen —
  voor managers, teams en HR-professionals die met mensen werken."
- **§2 Adjectives:** kalm · doordacht · helder · menselijk · zakelijk · betrouwbaar · editorial
- **§3 References:** Stripe editorial calm + Linear structural clarity + Scandinavian HR
  consultancies. Not like: corporate-stock-blue, motivational poster aesthetic, gradient
  startups.
- **§4 Design principles (3):**
  1. *Restraint over decoration* — every element earns its presence
  2. *Editorial typography is the visual system* — type does the work, not graphics
  3. *Tonal layering, no hard borders* — surface shifts (`--color-surface` ladder) instead
     of 1px lines wherever possible
- **§14 Voice:** Dutch (nl_BE), zakelijk-warm. No marketing-juich, no "wij geloven dat..."
  openings. CTAs imperative + concrete object ("Bekijk de vormingen", not "Klik hier").
- **§17 Components covered:** all buttons, cards, dash-cards, glass-nav, tag-pill, forms,
  status badges, `::selection`, footer, `prefers-reduced-motion`.
- **§18 Out of scope:** Admin dashboard, PDF templates, LearnDash content rendering,
  enrollment logic, email templates.

## 7. Patterns (5 files under `/patterns/`)

Same set as safeandsound, with Kindred HR copy. Each pattern file has the standard
header docblock (Title, Slug, Categories=`kindred`, Keywords, Viewport Width) and is
auto-loaded by `registerPatterns()`.

- `about.php` — "Wie is Kindred HR" block layout
- `contact.php` — Contact card + form callout (no embedded form; pattern is structure only)
- `faq.php` — Editorial Q&A list, alternating tonal shifts
- `agenda.php` — Manual editions list pattern (for pages where dynamic shortcode isn't wanted)
- `terms.php` — Long-form legal page scaffold

## 8. Activation

The plugin is a mu-plugin and loads automatically via bedrock-autoloader. To activate
only Kindred (with safeandsound + carecommunity inactive):
- `stride-client-carecommunity.php` is already `.off` — stays disabled
- `stride-client-safeandsound.php` is currently active — to swap brands, rename to
  `.off`. This is a runtime decision, not part of this spec.

Multiple client plugins active simultaneously would compete on `template_include`,
`stridence_font_url`, `wp_enqueue_scripts` — only one should run at a time.

## 9. Out of Scope

- Marketing pages from zip (`assets/site/case-app.jsx`, `pricing-app.jsx`,
  `homepage-app.jsx` / `homepage-sections.jsx`) — these are a standalone marketing site,
  not a Stride LMS skin
- Brand-board itself as a WP page (would belong in `docs/`, not a client plugin)
- Screen mockup PNGs (`assets/screens/`) — design reference, not shipping assets
- Admin dashboard skin, PDF templates, email templates (per IDENTITY §18)
- Removal of carecommunity / safeandsound — separate concern

## 10. Acceptance Criteria

1. `web/app/mu-plugins/stride-client-kindred/` exists with all 11 files listed in §2
2. With Kindred active and other clients disabled:
   - Site loads at `https://stride.ddev.site` without PHP errors
   - Homepage renders the 8 sections from §5
   - Header / footer / dashboard / course detail pages all show Kindred palette (moss
     primary, stone surface) and Geist typography
   - Google Fonts URL in `<head>` matches §3 (Geist + Geist Mono + Instrument Serif + Fraunces)
   - `prefers-reduced-motion: reduce` disables all transitions/animations
3. IDENTITY.md has zero `_[placeholders]_` remaining
4. Block patterns category "Kindred HR" visible in Gutenberg pattern picker, with 5
   patterns
5. `kindred-page-stub.php` selectable as page template in Page Attributes

## 11. Non-goals

- Pixel-perfect match of brand-board HTML preview (the brand-board is a design artifact,
  the LMS is a product — fidelity is at the token + component level, not at the
  page-composition level)
- Backwards compatibility shims for old client plugins
- Refactoring shared client-plugin patterns into a base class (YAGNI until 4+ clients)

## 12. Implementation Order (for the plan)

1. Scaffold directory + empty files + plugin header
2. Write `client.css` (tokens + component re-skin) — verify in DDEV with `?` cache-bust
3. Add logo + font URL filter — verify in browser
4. Write `front-page.php` — verify hero + pillars render correctly with Kindred CSS
5. Write 5 patterns — verify they appear in Gutenberg
6. Fill IDENTITY.md
7. Final visual QA on dashboard, course detail, enrollment, account pages
