# Safe & Sound — Brand Identity

A festival-flyer prevention zine, in code form.

---

## 1. Brand Positioning

> Safe & Sound runs prevention workshops for teenagers heading into festival and concert season — teaching them how to spot trouble, look out for their friends, and still have the best night of their lives.

**Tagline:** *Look out. Loud out.*

---

## 2. Adjective Set

> **bold · honest · streetwise · warm · loud · caring · uncondescending**

The whole identity is calibrated to one thing: a 16-year-old shouldn't roll their eyes at it, and a venue manager shouldn't dismiss it. Festival energy with grown-up follow-through.

---

## 3. Reference Brands

A mix of **late-90s rave-flyer revival + Boiler Room's editorial confidence + Rough Trade's record-store warmth + a public-service info-poster school like TfL or Belgian SIDA-info wayfinding.**

**Specifically inspired by:**
- Boiler Room (mono captions, hot colour, no chrome)
- Risograph poster studios (overprint magenta + lime, paper feel)
- Festival wristbands and access lanyards (mono labels, sticker hierarchy)
- Public-info posters (clarity over cleverness)
- Brutalist zines from the '90s (asymmetric grid, big quotes)

**Specifically NOT like:**
- DARE-era anti-drug condescension
- Corporate health-org pastel "you're so brave" tone
- Generic youth orgs with stock smiling-teen photos
- TikTok-blue health-tech UI

---

## 4. Design Principles

### 01. Loud, never shouty
**Rule:** Big colour, big type, big quotes — but use one bold move per surface, not five.
**Consequence:** Magenta + lime appear together rarely and intentionally. Most sections are paper + ink + one accent. Hero gets the full chord; the rest pick a single note.

### 02. Treat teenagers like adults
**Rule:** No emoji-strewn cheerleading, no exclamation marks, no patronising "stay safe!!" copy.
**Consequence:** Voice is matter-of-fact. Headlines are statements, not slogans. "Your mate's having a hard time" not "Be a hero for your friend!"

### 03. Paper, not glass
**Rule:** Surfaces are warm cream, not white. Borders are deliberate ink lines, not 1px ghost greys. Shadows are flat offsets (sticker), not blurred glow.
**Consequence:** The whole thing feels like a poster you peeled off a wall — physical, printed, present. Glassmorphism is banned; gradient backgrounds are banned.

### 04. Mono labels do the heavy lifting
**Rule:** Every functional micro-UI element (eyebrow, badge, timestamp, ticket code) is JetBrains Mono uppercase tracked +0.08em.
**Consequence:** Cheap wristband / festival-pass / backstage-laminate energy comes for free everywhere — and it visually separates "information" from "voice" (Bricolage / Instrument Serif).

### 05. The sticker shadow is the signature
**Rule:** Buttons and interactive cards lift on a hard 3–4px offset shadow in ink — never a blurred drop.
**Consequence:** Hover states feel like peeling stickers off a flyer. This one motion belongs to Safe & Sound and nothing else in the Stride family.

---

## 5. Avoid / Instead

| Avoid | Instead |
|---|---|
| Corporate health-org pastel teal | Hot magenta + hi-vis lime on warm cream |
| Soft blurred drop shadows | Hard 3–4px offset shadows in deep ink |
| Stock smiling-teen photography | Risograph spot illustration, hand-printed posters, real festival crowd shots |
| "Be a hero, save your friend!" exclamation copy | "Your mate's quiet. Check in." — declarative, no theatre |
| Rounded-everything pillowy UI | Mixed radii: pill buttons, sharp 8px tags, 20px cards, 36px statement panels |
| Inter / Roboto / Arial | Bricolage Grotesque (display) + Hanken Grotesk (body) + JetBrains Mono (label) + Instrument Serif (italic moments) |
| Bouncy spring animation | Snappy 120ms / 220ms with one tasteful overshoot beat |
| Pure white card on grey page | Warm cream page, ink-bordered cards, no ghost greys |

---

## 6. Logo & Mark

**Mark concept:**
> Two concentric arcs — a soundwave bracketing a dot — that also reads as a friend's arm around a shoulder. Sound + safety in one glyph.

**Wordmark personality:**
- Style: geometric grotesque (Bricolage Grotesque ExtraBold) with custom tightening on the &
- Tracking: tight (-0.03em)
- Case: title — "Safe & Sound"
- Weight: 800

**Lockup rules:**
- Mark and wordmark always horizontal on light surfaces
- Inverted lockup uses cream wordmark + lime accent dot on ink ground
- Minimum size: 24px mark height
- Clear space: equal to the mark height on all sides

**Variants required:**
- [x] Primary mark — ink on cream
- [x] Inverted mark — cream on ink (with lime accent dot)
- [x] Icon-only — square 1:1 for app / favicon / sticker
- [x] Horizontal lockup
- [ ] Vertical lockup (not required for v1)

---

## 7. Color System

### Palette intent
> Hot magenta does the inviting; hi-vis lime does the warning; deep ink anchors both. Warm cream paper holds the whole thing together so nothing feels digital or sterile.

### Primary palette

| Role | Token | Hex | RGB triplet |
|---|---|---|---|
| Primary brand | `--color-primary` | `#FF2D7C` | `255 45 124` |
| Primary hover | `--color-primary-hover` | `#E11469` | `225 20 105` |
| Primary subtle | `--color-primary-subtle` | `#FFE5EE` | `255 229 238` |
| Primary light | `--color-primary-light` | `#FFAFCC` | `255 175 204` |
| Primary dark | `--color-primary-dark` | `#0E1230` | `14 18 48` |
| Accent (lime) | `--color-accent` | `#C9F227` | `201 242 39` |
| Accent light | `--color-accent-light` | `#E4FB85` | `228 251 133` |

### Surface palette

| Role | Token | Hex | RGB triplet |
|---|---|---|---|
| Body background | `--color-surface` | `#FAF6EC` | `250 246 236` |
| Section shift | `--color-surface-alt` | `#F2EBD9` | `242 235 217` |
| Card surface | `--color-surface-card` | `#FFFFFF` | `255 255 255` |
| Border (ghost) | `--color-border` | `#D7CFBB` | `215 207 187` |
| Border (strong) | `--color-border-strong` | `#14172F` | `20 23 47` |
| Text | `--color-text` | `#14172F` | `20 23 47` |
| Text muted | `--color-text-muted` | `#5C5F7A` | `92 95 122` |

### Secondary palette (decorative — sparingly)

| Name | Hex | Usage |
|---|---|---|
| Sunset | `#EA8623` | "Few spots left" badges, warning states |
| Sky tint | `#DCEBFF` | Online / e-learning badge background |
| Bubblegum | `#FFAFCC` | Soft hover tints, illustration highlight |
| Grass | `#5BA231` | Confirmation states — reads next to lime without clashing |

### Accessibility rules
- [x] WCAG AA on all body text / surface pairs (ink #14172F on cream #FAF6EC = 15.4:1)
- [x] Magenta is never used for body text — only buttons, links, eyebrows
- [x] Lime is never paired with white text — always ink
- [x] Focus states show a 4px magenta ring AND a border-color shift (not colour alone)
- [x] Status badges combine background + icon + text, never colour alone

---

## 8. Typography

### Primary typeface
**Family:** Bricolage Grotesque
**Used for:** display headlines, hero, section headings (H1–H3)
**Why this one:** Variable display grotesque with optical sizing, soft corners, and just enough character to feel alive without becoming a costume. Sits between Druk's confidence and a sticker font's friendliness.
**Fallbacks:** Hanken Grotesk, system-ui, sans-serif

### Secondary typeface
**Family:** Hanken Grotesk
**Used for:** body, UI labels, paragraphs, buttons
**Why this one:** A workhorse grotesque with humanist breathing room. Quietly readable at 14px, doesn't compete with Bricolage above it.
**Fallbacks:** system-ui, Segoe UI, sans-serif

### Tertiary — editorial italic
**Family:** Instrument Serif (italic)
**Used for:** the *one* italic word in a hero, pull-quotes, "look out" zine moments
**Used sparingly:** yes — never set a paragraph in this; only emphasis fragments
**Fallbacks:** Georgia, Times New Roman, serif

### Quaternary — wristband mono
**Family:** JetBrains Mono
**Used for:** eyebrow labels, badge text, timestamps, ticket / edition codes, micro-UI
**Why this one:** Sharper and more contemporary than the obvious mono choices, and it visually says "this is data, not voice."
**Fallbacks:** ui-monospace, SF Mono, Menlo, monospace

### Token mapping

| Stridence token | Family assigned | Role |
|---|---|---|
| `--font-sans` | Hanken Grotesk | Body, UI, paragraphs, buttons |
| `--font-heading` | Bricolage Grotesque | H1–H4 |
| `--font-serif` | Instrument Serif | Italic editorial fragments |
| `--font-label` | JetBrains Mono | Eyebrows, badges, codes, micro-UI |

### Google Fonts URL
```
https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Hanken+Grotesk:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@500;600&display=swap
```

### Hierarchy

| Level | Family | Weight | Personality |
|---|---|---|---|
| H1 (display) | Bricolage Grotesque | 800 | confident |
| H2 | Bricolage Grotesque | 700 | structural |
| H3 | Bricolage Grotesque | 700 | framing |
| Body | Hanken Grotesk | 400 | readable |
| Strong body | Hanken Grotesk | 600 | emphatic |
| UI label / eyebrow | JetBrains Mono | 600 | wristband-mono |
| Editorial italic | Instrument Serif | 400 italic | quiet authority |

---

## 9. Shape, Surface & Elevation

### Radius

| Token | Value | Notes |
|---|---|---|
| `--radius-sm` | `0.5rem` (8px) | inputs, tags |
| `--radius-md` | `0.75rem` (12px) | form fields |
| `--radius-lg` | `1.25rem` (20px) | cards |
| `--radius-xl` | `2.25rem` (36px) | hero blocks, big statement panels |
| Button radius | `9999px` (pill) | always pill — overridden per-class |
| Card radius | `1.25rem` (20px) | |

### Shadow language

**Personality:** Flat hard sticker offsets are the signature. Soft ambient shadows exist as a quiet fallback for non-interactive surfaces.
**Blur range:** 0px (sticker), or 8–48px (ambient)
**Opacity range:** 100% (sticker, ink-coloured) or 4–12% (ambient)
**Tint:** ink (`#14172F`), never pure black

### Surface ladder

| Token | Visual role |
|---|---|
| `--color-surface` | Page base — warm cream paper |
| `--color-surface-alt` | Section shift — slightly deeper cream |
| `--color-surface-card` | White cards lifted from cream |
| `--color-surface-container` | Emphasised cream panels |
| `--color-surface-container-high` | Modals, dropdowns |
| `--color-surface-container-highest` | Overlays, popovers |

---

## 10. Motion

| Token | Value | When |
|---|---|---|
| `--duration-fast` | `120ms` | hover, focus, sticker-shadow nudge |
| `--duration-normal` | `220ms` | reveal, dismiss, content swap |
| `--ease-out` | `cubic-bezier(0.2, 0.9, 0.3, 1.2)` | default — one tasteful overshoot beat |

**Motion personality:**
> Snappy with a slight pop. Buttons "peel" 1–2px on hover. Nothing premium-slow; nothing rave-bouncy either.

**Preferred motion:**
- Sticker peel (translate 1–2px + shadow grow)
- Fade-in on reveal
- Marquee for "next-up" event ticker
- Soft slide on modal in

**Avoid:**
- Spring bounce / wobble
- Parallax
- Anything that takes longer than 300ms

**Reduced motion:** honoured — all transforms cancelled, durations clamped to ~0ms.

---

## 11. Imagery

### Photography
**Allowed:** yes — sparingly, never as decoration
**Style:** documentary, slightly overexposed festival crowd shots, low-light venue moments, hand-printed posters propped against walls
**Subjects:** real workshops in session, hands holding a wristband, crowd from behind (never staged front-on smile)
**Treatment:** Natural colour, OR duotone in primary magenta + ink for hero moments
**Sources:** commissioned + workshop documentation

**Avoid:**
- Stock smiling diverse-teens-at-laptop
- Staged "concerned parent" headshots
- Festival cliché silhouettes-with-light-rays
- AI-generated crowd scenes

**Instead:**
- Real workshop photography
- Risograph spot illustration
- Hand-drawn posters as background texture

### Illustration
**Style:** flat risograph — two-colour overprint feel (magenta + ink, or lime + ink), grain texture, slight registration offset
**Stroke:** chunky 3–4px, slightly imperfect
**Color:** brand palette only, never neutral greys
**Subject scope:** spot illustrations for service cards, decorative section dividers, headers on PDFs

### Iconography
**Style:** rounded outline, custom weight 2px, occasionally filled with lime accent
**Stroke weight:** 2px
**Corner treatment:** rounded (matches Bricolage's softness)
**Reference libraries:** Phosphor (rounded variant) as a baseline, with custom safety-specific glyphs commissioned (ear-protection, wristband, water-bottle)

### Data viz
**Color usage:** primary magenta as default series, lime accent for highlight series, ink for the axis. Never more than 3 colours per chart.
**Chart style:** editorial — thick strokes, large labels in JetBrains Mono, no gridlines unless essential

---

## 12. Layout & Composition

### Grid philosophy
> Asymmetric 12-col with deliberate broken alignment — the editorial side of a festival programme, not a corporate template.

### Traits
- Whitespace: generous on hero and quotes, dense on schedule / programme blocks
- Alignment: strict left rail with occasional intentional break-out
- Rhythm: varied per section — a dense bento block followed by a quiet quote
- Sections: divided by tonal shift (cream → cream-alt → ink) and the occasional 2px ink rule

### Avoid
- All-cards-look-the-same homepage grids
- Centred everything
- Heavy drop shadows on every element
- More than 2 fonts per surface (Bricolage + Hanken always; Mono adds, Serif adds rarely)

---

## 13. Pattern Policies

### Hero
- **Layout:** asymmetric — big display headline left, image / sticker collage right; or full-width display headline with one italic word
- **Imagery:** sticker/poster collage, risograph illustration, or a single duotone festival photo — never centered stock
- **Headline:** Bricolage 800, up to 4 lines, with *one* italic Instrument Serif word per hero
- **Lede:** Hanken Grotesk 18–20px, max 540px wide
- **CTA:** Primary magenta pill (sticker shadow) + outline-dark pill — verb pattern: "Book a workshop", "See the next session"
- **Animation:** subtle fade-in on load; lime accent shape rotates slowly on hover
- **Never:** centered stock photo, video background, gradient overlay

### Homepage section sequence
1. [x] Hero
2. [x] What we do (3-up workshop block)
3. [x] Why it matters — quote / stat moment
4. [x] Upcoming sessions (programme card grid)
5. [x] How a workshop runs (process)
6. [x] Testimonial — from a teen, not a parent
7. [x] CTA — book a workshop / become a peer educator

### Services / feature grid
- **Layout:** 3-col bento (one large + two stacked, alternating per section)
- **Card style:** ink border, white surface, sticker shadow on hover, mono eyebrow
- **Card body:** mono eyebrow + Bricolage title + Hanken lede + lime arrow icon

### Section rhythm
- **Spacing:** `var(--space-section)` = 6rem top/bottom
- **Dividers:** tonal shift or a 2px ink rule with mono label
- **Heading style:** mono eyebrow ("01 / WORKSHOPS") + Bricolage title — no decorative bullets

### Forms
- **Field style:** filled white card on cream, 2px ink border, 12px radius
- **Focus:** border shifts to magenta + 4px magenta-tinted ring
- **Error language:** direct, plain — "We need an email to send the booking confirmation."

---

## 14. Voice

### Tone traits
**direct · warm · informed · uncondescending · streetwise · plain-spoken · funny when it earns it**

### Messaging principles
- **Speak clearly:** short sentences. One idea per sentence. No jargon ("safeguarding", "wellbeing journey") — say what you mean.
- **Frame:** peer-to-peer, not authority-to-kid. We are *with* the audience, not above them.
- **Confidence:** quiet authority. We know the venues, we know the substances, we know the dynamics. We don't perform expertise.

### We sound like
- "Your mate is quiet at the back. You go check on them. That's the whole workshop."
- "We don't tell you not to drink. We tell you what naloxone is and where to find one."
- "Festival staff can spot a struggling teenager from across a field. So can you, after this."
- "Half the people in this workshop will end up running the next one."

### We don't sound like
- "Be a hero — save your friend's life!"
- "Join the journey to wellbeing and self-empowerment."
- "Substance abuse is a serious issue affecting today's youth."
- "Click here to stay safe!"

### Microcopy patterns

**CTA verb pattern:**
> Imperative + concrete next step. "Book a workshop," "Find the next session," "Get the festival kit." Never "Learn more," never "Click here."

**Empty state pattern:**
> "No sessions in your area yet — drop us a line and we'll come to you."

**Error message pattern:**
> "We need an email to send the confirmation — try again?" (Plain, recovery-first, no apology theatre.)

**Sample headlines:**
- "Look out for *each other.*"
- "The night doesn't have to *end early.*"
- "It's not a lecture. It's a *plan.*"
- "Festival-ready in *90 minutes.*"
- "Your friend is the safest *thing* at the gig."

---

## 15. Brand Applications

- [x] **Website** — main public-facing surface
- [x] **Web app / product UI** — workshop booking, peer-educator dashboard
- [x] **Social** — Instagram + TikTok, posters as carousel slides
- [x] **Workshop printed materials** — posters, wristbands, info cards
- [ ] Email templates — phase 2
- [ ] Slide decks — phase 2 (uses same display type at presentation scale)

**Per-surface notes:**
- Printed posters use only ink + magenta OR ink + lime (never all three) — riso-ready
- Social carousels: mono eyebrow + Bricolage headline + ink ground, swipe rhythm
- Wristbands: magenta + mono text only — must read at 6mm height

---

## 16. Technical Contract — Token Coverage

### Required
- [x] `--color-primary` + `-hover` + `-subtle` + `-light` + `-dark`
- [x] `--color-accent` + `-light`
- [x] `--color-surface` + `-alt` + `-card`
- [x] `--color-text` + `-muted`
- [x] `--color-border` + `-strong`
- [x] `--font-sans`, `--font-heading`, `--font-serif`, `--font-label`

### Optional (overridden)
- [x] Status colours (success, warning, error, info)
- [x] Badge colour pairs (open/few/full/cancelled/online/free)
- [x] 5-tier surface ladder
- [x] Tertiary palette
- [x] Radii
- [x] Shadows (signature: sticker offset)
- [x] Motion (snappier: 120ms / 220ms)
- [x] Macro spacing (default — no shift)

---

## 17. Technical Contract — Component Coverage

- [x] `.btn-primary` / `.btn-secondary` / `.btn-accent` — pill + sticker shadow
- [x] `.btn-outline-dark` / `.btn-outline-light`
- [x] `.btn-ghost`
- [x] `.card` / `.card-bordered` / `.card-interactive` — sticker hover
- [x] `.dash-card` / `.dash-card-hero` / `.dash-card-interactive` / `.dash-panel`
- [x] `.glass-nav`
- [x] `.dark-section`
- [x] `.hero-watermark`
- [x] `.tag-pill` — mono uppercase
- [x] `.stat-card-lime` — kept (lime fits!), ink-bordered
- [x] `.prose-stride`
- [x] Form inputs (text, select, textarea)
- [x] Status badges (mono uppercase)
- [x] `::selection` — magenta tint
- [x] `a` — magenta link, hover deeper
- [x] `footer` — ink, no border
- [x] `@media (prefers-reduced-motion: reduce)`

### Custom (added beyond the contract)
- `.label-mono` — wristband micro-UI utility
- `.sticker-shadow` / `.sticker-shadow-lime` — signature elevation

### Templates
- [x] `templates/front-page.php` — required (asymmetric hero, programme grid, peer testimonial, ink CTA)

---

## 18. Out of Scope (v1)

- Admin dashboard
- PDF templates (invoices/quotes)
- LearnDash course content rendering internals
- Enrolment/checkout flow logic
- Email templates (phase 2)

---

## 19. Phases

### Phase 1 — Foundation (shipped)
- [x] Logo concept + mark direction
- [x] Colour tokens
- [x] Typography tokens (4 families)
- [x] Shape + motion tokens
- [x] `IDENTITY.md`

### Phase 2 — Surfaces (shipped)
- [x] `client.css`
- [x] `templates/front-page.php`
- [x] `stride-client-safeandsound.php`

### Phase 3 — Extensions (later)
- [ ] Email templates
- [ ] Slide / presentation templates
- [ ] Illustration system documentation
- [ ] Workshop-pack printed-material library
