# Client Identity Template

A fillable brand book for Stride client design systems.

Every section asks one question. Fill it in for a specific brand, hand the
result to Claude Design (paired with `STRIDENCE-TOKENS.md`), and the output
drops mechanically into a `stride-client-{name}/` mu-plugin.

Sections 1–13 capture the brand. Sections 14–17 are the technical contract
that makes the output droppable.

The reference implementation is `stride-client-carecommunity/`.

---

## File Structure (output target)

A complete client identity ships as:

```
stride-client-{name}/
├── stride-client-{name}.php       PHP plugin bootstrap
├── IDENTITY.md                    This document, filled in
├── assets/
│   ├── client.css                 Tokens + component overrides
│   └── client.js                  Optional — only if non-CSS interactions needed
└── templates/
    ├── front-page.php             Homepage shape (always)
    ├── header.php                 If header structure differs
    ├── footer.php                 If footer structure differs
    └── partials/                  Reusable shapes (hero, services-grid, etc.)
```

---

## 1. Brand Positioning

> One sentence. What this brand does, for whom, in plain language. Not a tagline.

_[your turn]_

**Tagline (optional):**
> _[short phrase used in hero / closer]_

---

## 2. Adjective Set

Pick **5–7 adjectives** that drive every design decision. Return to this list
whenever stuck.

> _[adjective · adjective · adjective · adjective · adjective · adjective · adjective]_

---

## 3. Reference Brands

The shortest possible brief: "this brand should feel like a mix of ___ + ___ +
___." Pick 3–5 real brands or aesthetic schools. Be specific enough that an
outsider could find them.

> _[e.g. "Scandinavian digital minimalism + Stripe's editorial calm + Linear's structural clarity"]_

**Specifically inspired by:**
- _[brand or product]_
- _[brand or product]_
- _[brand or product]_

**Specifically NOT like:**
- _[brand or aesthetic to avoid]_
- _[brand or aesthetic to avoid]_

---

## 4. Design Principles

3–5 numbered principles. Each has a **Rule** (one sentence) and a **Consequence**
(what it lets you do; what it forbids).

### 01. [Principle name]
**Rule:** _[one sentence]_
**Consequence:** _[concrete design implications]_

### 02. [Principle name]
**Rule:** _[one sentence]_
**Consequence:** _[concrete design implications]_

### 03. [Principle name]
**Rule:** _[one sentence]_
**Consequence:** _[concrete design implications]_

_(add more if needed; cap at 5)_

---

## 5. Avoid / Instead

A blunt list of what this brand is and isn't. The clearer the contrast, the
fewer creative arguments later.

| Avoid | Instead |
|---|---|
| _[generic corporate blue]_ | _[your color direction]_ |
| _[heavy gradients]_ | _[your surface treatment]_ |
| _[busy layouts]_ | _[your layout approach]_ |
| _[playful startup feel]_ | _[your tonal target]_ |
| _[fast bouncy motion]_ | _[your motion personality]_ |
| _[stock photography]_ | _[your imagery direction]_ |

---

## 6. Logo & Mark

**Mark concept:**
> _[one sentence — what does the icon represent metaphorically?]_

**Wordmark personality:**
- Style: _[geometric sans / serif / monospace / hand-drawn]_
- Tracking: _[tight / generous / architectural]_
- Case: _[uppercase / mixed / lowercase]_
- Weight: _[light / regular / bold]_

**Lockup rules:**
- _[e.g. icon and wordmark always horizontal]_
- _[e.g. minimum size 24px icon height]_
- _[e.g. clear space = lantern-room height on all sides]_

**Variants required:**
- [ ] Primary mark (light backgrounds)
- [ ] Inverted mark (dark backgrounds)
- [ ] Icon-only (small UI / favicon)
- [ ] Horizontal lockup
- [ ] Vertical lockup (optional)

---

## 7. Color System

This drives `--color-*` tokens in `client.css`. Use Stridence token vocabulary
verbatim (see `STRIDENCE-TOKENS.md`). Colors as RGB triplets, hex in comments.

### Palette intent
> _[one sentence describing the color story]_

### Primary palette

| Role | Token | Hex | RGB triplet |
|---|---|---|---|
| Primary brand | `--color-primary` | _#______ | ___ ___ ___ |
| Primary hover | `--color-primary-hover` | _#______ | ___ ___ ___ |
| Primary subtle | `--color-primary-subtle` | _#______ | ___ ___ ___ |
| Primary light | `--color-primary-light` | _#______ | ___ ___ ___ |
| Primary dark | `--color-primary-dark` | _#______ | ___ ___ ___ |
| Accent | `--color-accent` | _#______ | ___ ___ ___ |
| Accent light | `--color-accent-light` | _#______ | ___ ___ ___ |

### Surface palette

| Role | Token | Hex | RGB triplet |
|---|---|---|---|
| Body background | `--color-surface` | _#______ | ___ ___ ___ |
| Section shift | `--color-surface-alt` | _#______ | ___ ___ ___ |
| Card surface | `--color-surface-card` | _#______ | ___ ___ ___ |
| Border (ghost) | `--color-border` | _#______ | ___ ___ ___ |
| Border (strong) | `--color-border-strong` | _#______ | ___ ___ ___ |
| Text | `--color-text` | _#______ | ___ ___ ___ |
| Text muted | `--color-text-muted` | _#______ | ___ ___ ___ |

### Secondary palette (decorative)

Sparingly-used colors for illustration, accent moments, presentation slides.

| Name | Hex | Usage |
|---|---|---|
| _[Arctic Blue]_ | _#______ | _[where it shows up]_ |
| _[Sand]_ | _#______ | _[where it shows up]_ |
| _[Coral]_ | _#______ | _[where it shows up]_ |

### Accessibility rules
- [ ] WCAG AA contrast on all text/background pairs
- [ ] No low-opacity text on tinted backgrounds
- [ ] Focus states visible without color alone
- [ ] Status communicated by icon + color, not color alone

---

## 8. Typography

### Primary typeface

**Family:** _[e.g. Satoshi]_
**Used for:** _[headlines / UI / body — be specific]_
**Why this one:** _[one sentence of rationale]_
**Fallbacks:** _[e.g. Inter, Ubuntu, system-ui]_

### Secondary typeface

**Family:** _[e.g. Instrument Serif]_
**Used for:** _[editorial quotes / hero fragments / campaign headlines]_
**Used sparingly:** yes / no — _[when]_
**Fallbacks:** _[e.g. Georgia, Times New Roman]_

### Token mapping

| Stridence token | Family assigned | Role in this brand |
|---|---|---|
| `--font-sans` | _[family]_ | Body, UI, labels |
| `--font-heading` | _[family]_ | H1–H4 |
| `--font-serif` | _[family]_ | Editorial fragments |
| `--font-label` | _[family]_ | Forms, micro UI |

### Google Fonts URL
```
https://fonts.googleapis.com/css2?family=____________&display=swap
```
_(drops into `overrideFontUrl()` in the plugin file)_

### Hierarchy

| Level | Family | Weight | Personality (one word) |
|---|---|---|---|
| H1 | _________ | _____ | _[confident / quiet / editorial]_ |
| H2 | _________ | _____ | _[spacious / structural]_ |
| H3 | _________ | _____ | _[utilitarian / framing]_ |
| Body | _________ | _____ | _[readable / generous]_ |
| UI label | _________ | _____ | _[crisp / restrained]_ |

---

## 9. Shape, Surface & Elevation

### Radius

| Token | Value | Notes |
|---|---|---|
| `--radius-sm` | _____ | _[small inputs, tags]_ |
| `--radius-md` | _____ | _[buttons, fields]_ |
| `--radius-lg` | _____ | _[cards, panels]_ |
| `--radius-xl` | _____ | _[hero blocks]_ |
| Button radius | _____ | _[pill / rounded / square — overridden per-class if pill]_ |
| Card radius | _____ | |

### Shadow language

**Personality:** _[ambient and tinted / sharp and defined / minimal, no shadows / heavy and architectural]_
**Blur range:** _[e.g. 30–60px]_
**Opacity range:** _[e.g. 4–8%]_
**Tint:** _[pure neutral / warm / cool / brand-tinted — use which color?]_

### Surface ladder (5-tier tonal layering)

Describe what lives on each tier. Some brands collapse this to 3 tiers, some
use all 6.

| Token | Visual role in this brand |
|---|---|
| `--color-surface` | _[page base]_ |
| `--color-surface-alt` | _[section shift]_ |
| `--color-surface-card` | _[cards lifted from page]_ |
| `--color-surface-container` | _[emphasized panels]_ |
| `--color-surface-container-high` | _[modals, dropdowns]_ |
| `--color-surface-container-highest` | _[overlays, popovers]_ |

---

## 10. Motion

| Token | Value | When |
|---|---|---|
| `--duration-fast` | _____ | _[hover, focus, color shift]_ |
| `--duration-normal` | _____ | _[reveal, dismiss, content swap]_ |
| `--ease-out` | `cubic-bezier(__, __, __, __)` | Default curve |

**Motion personality (one sentence):**
> _[e.g. "slow enough to feel premium; never bouncy"]_

**Preferred motion types:**
- _[fade]_
- _[soft slide]_
- _[gentle scale]_
- _[layer reveal]_

**Avoid:**
- _[bounce / spring physics]_
- _[fast snappy transitions]_
- _[parallax]_

**Reduced motion:** must honor `prefers-reduced-motion: reduce`.

---

## 11. Imagery

### Photography

**Allowed:** yes / no / sparingly
**Style:** _[real / staged / documentary / lifestyle / abstract]_
**Subjects:** _[real interaction, teams, hands, environments — be specific]_
**Treatment:** _[natural color / duotone in primary / desaturated / high contrast]_
**Sources:** _[commissioned / brand library / stock — and which]_

**Avoid:**
- _[staged corporate smiles]_
- _[obvious stock]_
- _[cliché diversity poses]_
- _[technology clichés (hands on laptop, gears)]_

**Instead:**
- _[quiet moments]_
- _[real workshops]_
- _[human-device interaction]_

### Illustration

**Style:** _[minimal line / geometric / hand-drawn / 3D / none]_
**Stroke:** _[thin consistent / variable / brush]_
**Color:** _[`currentColor` so it inherits / fixed brand palette / monochrome]_
**Subject scope:** _[icons only / spot illustrations / full scenes / hero artwork]_

### Iconography

**Style:** _[outline / filled / two-tone / branded set]_
**Stroke weight:** _[1.5px / 1.8px / 2px]_
**Corner treatment:** _[rounded / sharp]_
**Reference libraries:** _[e.g. Phosphor, Lucide, Streamline — or "custom"]_

### Data visualization

**Color usage:** _[brand palette / monochrome accent ramp / mixed]_
**Chart style:** _[minimal grid / editorial / dashboard-utility]_

---

## 12. Layout & Composition

### Grid philosophy
> _[one sentence — e.g. "Editorial asymmetry on a modular grid, generous whitespace"]_

### Layout traits
- Whitespace: _[generous / efficient / dense]_
- Alignment: _[strict grid / asymmetric balance / editorial flow]_
- Rhythm: _[uniform / varied per section / cinematic]_
- Sections: _[divided by tonal shift / 1px line / negative space only]_

### Avoid
- _[overcrowded sections]_
- _[too many card styles]_
- _[heavy shadows on every element]_
- _[excessive animation]_

---

## 13. Pattern Policies

This is the part most design briefs leave implicit. Be explicit — these drive
the PHP templates in `templates/`.

### Hero
- **Layout:** _[centered stack / split 60-40 / full-bleed / asymmetric grid]_
- **Imagery:** _[illustration only / photography only / abstract pattern / none]_
- **Headline style:** _[sans bold / serif italic editorial / display weight] — max ___ words_
- **Lede:** _[sans body / serif] — max ___ characters wide_
- **CTA:** _[single primary / primary + secondary] — verb pattern: ___ _
- **Animation:** _[lighthouse beam / fade-in / parallax / none]_
- **Never:** _[stock photography / centered photo / video bg / marquee]_

### Homepage section sequence

Tick the sections this homepage uses, in order:

1. [ ] Hero
2. [ ] What you solve / value prop
3. [ ] Why this matters (problem framing)
4. [ ] Services / features (grid)
5. [ ] Proof / case studies
6. [ ] Process / how it works
7. [ ] Pricing
8. [ ] FAQ
9. [ ] CTA / closer
10. [ ] Other: _____________

### Services / feature grid
- **Layout:** _[3-col / 2-col / asymmetric / staggered / list]_
- **Card style:** _[bordered / borderless tonal lift / shadow-only / icon-led / number-led]_
- **Card body:** _[icon + title + 1-line / illustration + title + 2-line / no icon, editorial only]_

### Section rhythm
- **Section spacing:** _[e.g. 6–7rem top/bottom]_
- **Section dividers:** _[tonal shift / 1px line / negative space only]_
- **Section heading style:** _[number eyebrow + serif title / all-caps eyebrow + sans / no eyebrow]_

### Forms
- **Field style:** _[bordered / underline only / filled tonal]_
- **Field radius:** _[var(--radius-md) / var(--radius-sm) / pill]_
- **Focus state:** _[ring shadow / border color shift / both]_
- **Error language:** _[gentle / direct / inline icon + text]_

---

## 14. Voice

### Tone traits
_[5–7 adjectives — e.g. expert, approachable, calm, precise, helpful, strategic, human]_

### Messaging principles
- **Speak clearly:** _[short sentence on language complexity]_
- **Frame:** _[educate vs sell, guide vs lecture, etc.]_
- **Confidence level:** _[quiet authority / bold claims / humble]_

### We sound like
- _[example sentence]_
- _[example sentence]_
- _[example sentence]_
- _[example sentence]_

### We don't sound like
- _[example sentence — the kind to avoid]_
- _[example sentence]_
- _[example sentence]_
- _[example sentence]_

### Microcopy patterns

**CTA verb pattern:**
> _[e.g. "Imperative + concrete object: 'Start with an audit', not 'Click here'"]_

**Empty state pattern:**
> _[e.g. "Acknowledge + invite next action: 'No editions yet. Add the first one →'"]_

**Error message pattern:**
> _[e.g. "Plain description + recovery hint, no apology theater"]_

**Sample headlines (3–5):**
- _[headline]_
- _[headline]_
- _[headline]_

---

## 15. Brand Applications

Where does this identity show up? Tick all, then note any per-surface deviation.

- [ ] **Website** — main public-facing surface
- [ ] **Web app / product UI** — dashboards, forms, in-app surfaces
- [ ] **LinkedIn / social** — _[which platforms]_
- [ ] **Email templates** — transactional and marketing
- [ ] **Slide decks / presentations**
- [ ] **PDF documents** — _[reports / audits / quotes / invoices]_
- [ ] **Workshops / printed materials**
- [ ] **Other:** _____________

**Per-surface notes:**
- _[e.g. "Presentations use larger type scale and Instrument Serif throughout"]_
- _[e.g. "Audit reports use only primary + neutrals — no secondary palette"]_

---

## 16. Technical Contract — Token Coverage

The `client.css` must override every token below that the brand actually
shifts. Tick what's covered; tokens not ticked inherit Stridence defaults.

### Required (every brand defines these)
- [ ] `--color-primary` + `-hover` + `-subtle` + `-light` + `-dark`
- [ ] `--color-accent` + `-light`
- [ ] `--color-surface` + `-alt` + `-card`
- [ ] `--color-text` + `-muted`
- [ ] `--color-border` + `-strong`
- [ ] `--font-sans`, `--font-heading`, `--font-serif`, `--font-label`

### Optional (override if brand differs from Stridence default)
- [ ] Status colors (`--color-success`, `-warning`, `-error`, `-info`)
- [ ] Badge color pairs (open / few / full / cancelled / online / free)
- [ ] 5-tier surface ladder (`--color-surface-container*`, `--color-secondary-container`)
- [ ] Tertiary palette (`--color-tertiary`, `-light`)
- [ ] Radii (`--radius-sm/md/lg/xl`)
- [ ] Shadows (`--shadow-xs/sm/md/lg/overlay`)
- [ ] Motion (`--duration-fast`, `-normal`, `--ease-out`)
- [ ] Macro spacing (`--space-section`, `-block`, `-element`)

See `STRIDENCE-TOKENS.md` for exact names, defaults, and required format
(RGB triplets, not hex).

---

## 17. Technical Contract — Component & Template Coverage

### Components

The `client.css` must re-skin each class the brand visually shifts. Components
not ticked inherit theme defaults.

- [ ] `.btn-primary` / `.btn-secondary` / `.btn-accent`
- [ ] `.btn-outline-dark` / `.btn-outline-light`
- [ ] `.btn-ghost` / `.btn-danger`
- [ ] `.btn-sm` / `.btn-lg`
- [ ] `.card` / `.card-bordered` / `.card-interactive`
- [ ] `.dash-card` / `.dash-card-hero` / `.dash-card-interactive` / `.dash-panel`
- [ ] `.glass-nav`
- [ ] `.dark-section`
- [ ] `.hero-watermark`
- [ ] `.tag-pill`
- [ ] `.stat-card-lime` (rename or retint if brand has no lime)
- [ ] `.prose-stride`
- [ ] Form inputs (text, select, textarea, checkbox, radio)
- [ ] Status badges (success/warning/error/info)
- [ ] `::selection`
- [ ] `a` (links)
- [ ] `footer`
- [ ] `@media (prefers-reduced-motion: reduce)`

### Templates

PHP overrides for shape changes the markup can't handle via CSS alone.

- [ ] `templates/front-page.php` — **always required** for a real identity swap
- [ ] `templates/header.php` — only if nav structure differs
- [ ] `templates/footer.php` — only if footer structure differs
- [ ] `templates/partials/hero.php` — if hero reused on inner pages
- [ ] `templates/partials/services-grid.php` — if pattern reused
- [ ] `templates/single-edition.php` — only if course detail page restructured

For each shipped template, note the **pattern policy rule** (section 13) that
forces the override:
- _[e.g. "front-page.php overridden because hero is split 60-40 with illustration, theme default is centered stack"]_

---

## 18. Out of Scope

By default this identity does **not** touch:
- Admin dashboard (`stride-core/Admin/` — internal staff UI)
- PDF templates (invoices, quotes)
- LearnDash course content rendering
- Enrollment / checkout flow logic (visual surface only)
- Email templates (handled separately — `themes/stridence/templates/emails/`)

If this identity *does* extend to one of the above, document why:
- _[surface]_: _[reason]_

---

## 19. Deliverable Phases

Optional — useful when scoping a new client engagement.

### Phase 1: Foundation
- [ ] Logo system
- [ ] Color tokens
- [ ] Typography tokens
- [ ] Shape & motion tokens
- [ ] `IDENTITY.md` complete

### Phase 2: Surfaces
- [ ] `client.css` — token overrides + component re-skin
- [ ] `templates/front-page.php`
- [ ] Required additional templates (section 17)
- [ ] Stride plugin file (`stride-client-{name}.php`)

### Phase 3: Extensions
- [ ] Email templates (if in scope)
- [ ] Slide / presentation templates (if in scope)
- [ ] Illustration system documentation
- [ ] Motion library beyond defaults

---

## 20. Round-Trip Brief (paste this to Claude Design)

> Produce a Stride client design system. Output must include:
>
> 1. **`client.css`** — using Stridence token vocabulary verbatim (see `STRIDENCE-TOKENS.md`). RGB triplets, exact token names like `--color-primary` / `--font-heading` / `--radius-md`. Override the component classes listed in section 17.
> 2. **`IDENTITY.md`** — filled-in copy of this template, every section completed, no `_[placeholders]_` left.
> 3. **`templates/front-page.php`** — WordPress front-page demonstrating the hero, section sequence, and footer patterns from section 13.
> 4. Any additional templates required by section 17.
> 5. **`stride-client-{name}.php`** — plugin bootstrap modeled on `stride-client-carecommunity.php`.
>
> Reference implementation: `web/app/mu-plugins/stride-client-carecommunity/`.
> Token contract: `docs/STRIDENCE-TOKENS.md`.

---

## Reference Implementations

- **CareCommunity** — `web/app/mu-plugins/stride-client-carecommunity/`
  Compassionate editorial. Teal, Noto Serif + Inter, tonal layering, no hard borders.
- _Add more as they ship._
