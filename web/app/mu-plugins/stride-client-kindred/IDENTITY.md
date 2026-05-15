# Kindred HR — Client Identity

Stride client identity for Kindred HR, a fictional HR training and development company
serving Dutch and Belgian organisations.

Source: `~/Downloads/stridelms brand.zip` brand-board.
Filled-in copy of `CLIENT-IDENTITY-TEMPLATE.md`.

---

## 1. Brand Positioning

Kindred helpt organisaties trainen, coachen en ontwikkelen — voor managers, teams en
HR-professionals die met mensen werken.

**Tagline:** "Trainingen voor mensen die werken met mensen."

---

## 2. Adjective Set

kalm · doordacht · helder · menselijk · zakelijk · betrouwbaar · editorial

---

## 3. Reference Brands

Scandinavische digital minimalism + Stripe's editorial calm + Linear's structural clarity.

**Specifically inspired by:**
- Stripe (editorial typography, calm IA, restraint)
- Linear (structural typography, mono accents)
- The New York Times' product design (serif-italic accents, editorial gravitas)

**Specifically NOT like:**
- Generic corporate HR (stock photo handshakes, "wij geloven dat"-openings)
- Motivational poster aesthetic (gradients, big arrows, exclamation points)
- Startup-saas (rounded everything, gradient buttons, illustrations of laptops)

---

## 4. Design Principles

### 01. Restraint over decoration
**Rule:** Every visual element must earn its place — no decorative shapes, no marketing
illustrations, no celebratory ornament.
**Consequence:** Page composition relies on typography, surface tone shifts, and
whitespace rhythm. Stock photography forbidden. Hero "decorations" are absent.

### 02. Editorial typography is the visual system
**Rule:** Type does the work that graphics would do in a louder brand.
**Consequence:** Mix Geist (sans) with Instrument Serif italic for editorial fragments
and Fraunces for display. Mono labels (Geist Mono) for eyebrows, meta, micro-copy. No
icon-heavy compositions.

### 03. Tonal layering, not hard borders
**Rule:** Section breaks use surface-colour shifts, not 1px lines, wherever possible.
**Consequence:** `--color-surface` → `--color-surface-alt` ladder defines section rhythm.
1px lines are reserved for editorial inner-grids (intro split, pillar dividers), not
section separation.

---

## 5. Avoid / Instead

| Avoid | Instead |
|---|---|
| Generic corporate blue | Moss green `#1F5B3D` on warm stone `#F1F2EF` |
| Heavy gradients | Single-tone surfaces; tonal shifts via `--color-surface*` ladder |
| Busy layouts | Editorial column-grid (130px label col + 1fr body) with generous whitespace |
| Playful startup feel | Editorial gravitas — Instrument Serif italic + restrained spacing |
| Fast bouncy motion | Slow premium motion — 180/320ms with `cubic-bezier(0.22, 0.61, 0.36, 1)`, never overshoot |
| Stock photography | Photography sparingly, only when documentary (real workshops, hands, environments) |

---

## 6. Logo & Mark

**Mark concept:** Abstract "S" curve formed by four paths inside a rounded square,
suggesting motion, connection, and a sheltering container.

**Wordmark personality:**
- Style: geometric sans (Geist)
- Tracking: tight, architectural (`-0.02em`)
- Case: lowercase or sentence case in long-form copy; uppercase in mono eyebrows only
- Weight: regular 400, never bold

**Lockup rules:**
- Icon-only used as favicon and small UI
- Icon and wordmark always horizontal in lockups
- Minimum icon height 24px

**Variants required:**
- [x] Primary mark (light backgrounds): `logo.svg` with `currentColor` rect + `#f9faf7` paths
- [x] Inverted: same SVG, parent text-color reversed
- [x] Icon-only: same SVG
- [ ] Horizontal lockup: not in this iteration
- [ ] Vertical lockup: not in this iteration

---

## 7. Color System

### Palette intent

Cool stone + moss. Calm, professional, vegetal. Stone is the breathing space; moss is
the single accent that does all the structural work.

### Primary palette (RGB triplets for use with `rgb(var(--token))`)

| Role | Token | Hex | RGB triplet |
|---|---|---|---|
| Primary brand | `--color-primary` | `#1F5B3D` | `31 91 61` |
| Primary hover | `--color-primary-hover` | `#0F3A25` | `15 58 37` |
| Primary subtle | `--color-primary-subtle` | `#BCD4C4` | `188 212 196` |
| Primary light | `--color-primary-light` | `#BCD4C4` | `188 212 196` |
| Primary dark | `--color-primary-dark` | `#0F3A25` | `15 58 37` |
| Accent | `--color-accent` | `#1F5B3D` | `31 91 61` |
| Accent light | `--color-accent-light` | `#BCD4C4` | `188 212 196` |

### Surface palette

| Role | Token | Hex | RGB triplet |
|---|---|---|---|
| Body background | `--color-surface` | `#F1F2EF` | `241 242 239` |
| Section shift | `--color-surface-alt` | `#E8EAE5` | `232 234 229` |
| Card surface | `--color-surface-card` | `#F9FAF7` | `249 250 247` |
| Border (ghost) | `--color-border` | `#E1E3DC` | `225 227 220` |
| Border (strong) | `--color-border-strong` | `#D4D7D0` | `212 215 208` |
| Text | `--color-text` | `#111312` | `17 19 18` |
| Text muted | `--color-text-muted` | `#6C706B` | `108 112 107` |

### Secondary palette

Not used. Single accent (moss) does all structural work; no decorative secondary.

### Accessibility rules

- [x] WCAG AA contrast on all text/background pairs (moss `#1F5B3D` on stone `#F1F2EF`
      hits 8.4:1; primary-hover `#0F3A25` hits 11.2:1)
- [x] No low-opacity text on tinted backgrounds
- [x] Focus states visible: 3px primary-subtle ring + border-color shift
- [x] Status communicated by colour + label text, never colour alone

---

## 8. Typography

### Primary typeface

**Family:** Geist
**Used for:** Body, UI, labels, H1–H4 (regular 400 / medium 500, never bold)
**Why this one:** Modern geometric sans with strong text-feature support (`ss01`, `cv11`).
Matches "editorial calm" while staying neutral enough for product UI.
**Fallbacks:** system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif

### Secondary typeface

**Family:** Instrument Serif
**Used for:** Editorial fragments — italicised phrases inside hero headlines, quote
blocks, hero intro paragraphs
**Used sparingly:** yes — one italic fragment per major heading max
**Fallbacks:** Georgia, "Times New Roman", serif

### Tertiary typeface (mono)

**Family:** Geist Mono
**Used for:** Eyebrows (`t-eyebrow`), meta strings, mono labels, code

### Display alt typeface

**Family:** Fraunces
**Used for:** Optional display headings via `.t-fraunces` utility — applied to front-page
H1/H2 for editorial weight without going to bold sans
**Used sparingly:** front-page only

### Token mapping

| Stridence token | Family | Role |
|---|---|---|
| `--font-sans` | Geist | Body, UI, labels |
| `--font-heading` | Geist | H1–H4 (paired with `.t-fraunces` utility for display) |
| `--font-serif` | Instrument Serif | Editorial fragments |
| `--font-label` | Geist Mono | Eyebrows, meta, micro |
| `--font-display-alt` | Fraunces | Front-page display |

### Google Fonts URL

```
https://fonts.googleapis.com/css2?family=Geist:wght@300..700&family=Geist+Mono:wght@400..600&family=Instrument+Serif:ital@0;1&family=Fraunces:opsz,wght@9..144,300..700&display=swap
```

Loaded via `overrideFontUrl()` in `stride-client-kindred.php`.

### Hierarchy

| Level | Family | Weight | Personality |
|---|---|---|---|
| H1 | Fraunces (`.t-fraunces`) | 400 | editorial |
| H2 | Fraunces (`.t-fraunces`) | 500 | structural |
| H3 | Geist | 500 | utilitarian |
| Body | Geist | 400 | readable |
| UI label | Geist Mono | 500 | crisp |

---

## 9. Shape, Surface & Elevation

### Radius

| Token | Value | Notes |
|---|---|---|
| `--radius-sm` | 4px | small inputs, tags |
| `--radius-md` | 8px | buttons, inputs |
| `--radius-lg` | 14px | cards, panels |
| `--radius-xl` | 22px | hero blocks |
| Button radius | 8px | never pill |
| Card radius | 14px | |

### Shadow language

**Personality:** minimal, ink-tinted, near-invisible defaults
**Blur range:** 2-50px
**Opacity range:** 4-14%
**Tint:** ink `#111312`, never pure black, never brand-tinted

### Surface ladder

| Token | Visual role |
|---|---|
| `--color-surface` | Page base (stone `#F1F2EF`) |
| `--color-surface-alt` | Section shift (`#E8EAE5`) |
| `--color-surface-card` | Cards lifted from page (`#F9FAF7`) |
| `--color-surface-container` | Emphasised panels (`#E8EAE5`) |
| `--color-surface-container-high` | Modals, dropdowns (`#E1E3DC`) |
| `--color-surface-container-highest` | Overlays, popovers (`#D4D7D0`) |

---

## 10. Motion

| Token | Value | When |
|---|---|---|
| `--duration-fast` | 180ms | hover, focus, color shift |
| `--duration-normal` | 320ms | reveal, dismiss, content swap |
| `--ease-out` | `cubic-bezier(0.22, 0.61, 0.36, 1)` | Default — never overshoot |

**Motion personality:** Slow enough to feel premium; never bouncy.

**Preferred motion types:**
- fade
- soft slide (8–16px translation max)
- gentle scale (0.98 ↔ 1)

**Avoid:**
- bounce / spring physics
- fast snappy transitions (<150ms)
- parallax

**Reduced motion:** honors `prefers-reduced-motion: reduce` — all transitions and
animations reduced to 0.01ms.

---

## 11. Imagery

### Photography

**Allowed:** sparingly
**Style:** documentary, real
**Subjects:** real workshops, hands writing, environment shots — never staged smiles
**Treatment:** natural colour, slight desaturation; no duotones
**Sources:** commissioned or brand library

**Avoid:**
- staged corporate smiles
- obvious stock
- "diversity poses"
- technology clichés (hands on laptop, gears, lightbulbs)

### Illustration

**Style:** none / minimal line if needed
**Color:** `currentColor` so it inherits

### Iconography

**Style:** outline, 1.5px stroke
**Reference libraries:** Phosphor or Lucide

### Data visualization

**Color usage:** monochrome moss ramp; no rainbow palettes
**Chart style:** editorial — minimal grid, mono labels

---

## 12. Layout & Composition

### Grid philosophy

Editorial column grid: 130px label column + 1fr body. Generous whitespace.

### Layout traits

- Whitespace: generous
- Alignment: strict editorial grid with asymmetric balance
- Rhythm: varied per section (cover, pillars, agenda, closer have distinct rhythms)
- Sections: divided by tonal shift (surface ↔ surface-alt), not 1px lines

### Avoid

- overcrowded sections
- too many card styles
- heavy shadows on every element
- decorative animation

---

## 13. Pattern Policies

### Hero

- Layout: editorial split (130px label + 1fr title + auto meta)
- Imagery: none — typography is the hero
- Headline style: Fraunces 400 + Instrument Serif italic fragment, max 12 words
- Lede: Instrument Serif italic 24–30px + Geist body paragraph
- CTA: single primary, verb pattern: imperative + concrete object ("Bekijk de vormingen")
- Animation: none on first paint; gentle fade-in on scroll only if used
- Never: stock photography, centered photo, video background

### Homepage section sequence

1. [x] Hero (cover)
2. [x] Why this matters (intro paragraph in hero)
3. [x] Services / features (5-pillar grid)
4. [x] Editions / agenda
5. [x] Featured trajectory (split)
6. [x] Quote / testimonial
7. [x] CTA / closer
8. [ ] Pricing — not in front-page
9. [ ] FAQ — not in front-page (own page via pattern)

### Services / feature grid

- Layout: 5-col on desktop, 2-col tablet, 1-col mobile
- Card style: borderless tonal divider — column-rule 1px line, no card shadows
- Card body: number eyebrow + label + 1-line description

### Section rhythm

- Section spacing: clamp(48px, 8vw, 110px) padding
- Section dividers: tonal shift (`--color-surface` ↔ `--color-surface-alt`)
- Section heading style: number eyebrow + Fraunces title

### Forms

- Field style: bordered
- Field radius: `--radius-md` (8px)
- Focus state: border-color shift to moss + 3px primary-subtle ring
- Error language: direct, recovery-oriented ("Vul je e-mailadres in om verder te gaan.")

---

## 14. Voice

### Tone traits

Doordacht · zakelijk · warm · helder · respectvol · niet pushend · editorial

### Messaging principles

- **Speak clearly:** Korte zinnen. Geen jargon. Concrete werkwoorden.
- **Frame:** Educate, niet verkoop. Help kiezen, geen druk uitoefenen.
- **Confidence level:** Quiet authority — we weten wat we doen, we juichen het niet uit.

### We sound like

- "Geen frontale lessen. Praktische trainingen die in het werk landen."
- "We werken in groepen van 6 tot 20, met follow-up tussen sessies."
- "Inschrijving sluit een week voor aanvang."
- "Annulering tot 14 dagen vooraf: kosteloos."

### We don't sound like

- "Wij geloven dat elke mens uniek is en wij maken het verschil!" (marketing-juich)
- "Boost je impact 🚀" (emoji-startup)
- "Click here to learn more" (CTA-templates)
- "Onze missie is om de wereld een betere plek te maken." (lege grandiositeit)

### Microcopy patterns

**CTA verb pattern:** Imperatief + concrete object. "Bekijk de vormingen", "Plan een gesprek", "Download de programmabrochure". Never "Klik hier" or "Lees meer".

**Empty state pattern:** "Geen geplande trainingen op dit moment. Volg ons of meld je aan voor de nieuwsbrief."

**Error message pattern:** "Vul je e-mailadres in om verder te gaan." Plain description + recovery hint, geen excuses.

**Sample headlines:**
- "Trainingen voor mensen die werken met mensen."
- "Vijf domeinen waar onze trainingen verschil maken."
- "Eerstvolgende trainingen."
- "Klaar om te starten?"

---

## 15. Brand Applications

- [x] Website (Stride LMS frontend) — primary surface
- [x] Web app / product UI (dashboard, course pages, enrollment) — same skin
- [ ] LinkedIn / social — out of scope for this plugin
- [ ] Email templates — handled separately by theme
- [ ] Slide decks — out of scope
- [ ] PDF documents (quotes, invoices) — uses Stride core PDF templates, not client-skinned
- [ ] Workshop / printed materials — out of scope

---

## 16. Technical Contract — Token Coverage

### Required

- [x] `--color-primary` + `-hover` + `-subtle` + `-light` + `-dark`
- [x] `--color-accent` + `-light`
- [x] `--color-surface` + `-alt` + `-card`
- [x] `--color-text` + `-muted`
- [x] `--color-border` + `-strong`
- [x] `--font-sans`, `--font-heading`, `--font-serif`, `--font-label`

### Optional

- [x] Status colors (`--color-success`, `-warning`, `-error`, `-info`)
- [x] Badge color pairs (open / few / full / cancelled / online / free)
- [x] 5-tier surface ladder
- [ ] Tertiary palette (single accent, no tertiary)
- [x] Radii
- [x] Shadows
- [x] Motion
- [x] Macro spacing (`--space-section`, `-block`, `-element`)

---

## 17. Technical Contract — Component & Template Coverage

### Components

- [x] `.btn-primary` / `.btn-secondary` / `.btn-accent`
- [x] `.btn-outline-dark` / `.btn-outline-light`
- [x] `.btn-ghost` / `.btn-danger`
- [x] `.btn-sm` / `.btn-lg`
- [x] `.card` / `.card-bordered` / `.card-interactive`
- [x] `.dash-card` / `.dash-card-hero` / `.dash-card-interactive` / `.dash-panel`
- [x] `.glass-nav`
- [x] `.dark-section`
- [x] `.hero-watermark`
- [x] `.tag-pill`
- [x] `.prose-stride`
- [x] Form inputs
- [x] Status badges
- [x] `::selection`
- [x] `a` (links)
- [x] `footer`
- [x] `@media (prefers-reduced-motion: reduce)`

### Templates

- [x] `templates/front-page.php` — required for identity swap
- [ ] `templates/header.php` — not needed, glass-nav re-skin via CSS suffices
- [ ] `templates/footer.php` — not needed, footer re-skin via CSS suffices
- [x] `templates/page-stub.php` — narrow long-form layout for stub pages

---

## 18. Out of Scope

By default this identity does not touch:

- Admin dashboard (`stride-core/Admin/`)
- PDF templates (invoices, quotes)
- LearnDash course content rendering
- Enrollment / checkout flow logic
- Email templates

---

## 19. Deliverable Phases — status

### Phase 1: Foundation
- [x] Logo system
- [x] Color tokens
- [x] Typography tokens
- [x] Shape & motion tokens
- [x] IDENTITY.md complete

### Phase 2: Surfaces
- [x] `client.css` — tokens + component re-skin
- [x] `templates/front-page.php`
- [x] `templates/page-stub.php`
- [x] `stride-client-kindred.php`

### Phase 3: Extensions
- [ ] Email templates — out of scope
- [ ] Slide templates — out of scope
- [ ] Illustration system — none required

---

## 20. Reference

- **Reference implementation:** `stride-client-safeandsound/`
- **Token contract:** `docs/STRIDENCE-TOKENS.md` (if present)
- **Brand source:** `~/Downloads/stridelms brand.zip` (extracted to `/tmp/stridelms-brand/`)
- **Design spec:** `docs/superpowers/specs/2026-05-15-stride-client-kindred-design.md`
