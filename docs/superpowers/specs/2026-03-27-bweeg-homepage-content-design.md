# BWEEG Academy — Homepage Content Design

**Date:** 2026-03-27
**Status:** Approved
**Scope:** Replace generic Stride LMS demo content with fictional Flemish health organization "BWEEG"

---

## The Organization

**BWEEG vzw** — Vlaams expertisecentrum voor beweging, voeding en welzijn bij jongeren (12-25 jaar). ~50 medewerkers: trainers, diëtisten, sportwetenschappers, pedagogen. Gesubsidieerd door Vlaams Agentschap Opgroeien. Opgericht 2009. Gevestigd in Gent.

**BWEEG Academy** is the training/education arm of BWEEG vzw. The main organization does research, policy advice, publications, campaigns, and school programs. This platform is their academy — comparable to "Sensoa Academy" or Gezond Leven's vormingsaanbod.

### Target Audience (primary → secondary)
1. **Jeugdwerkers & sportcoaches** — youth workers, sports coaches, youth movement leaders
2. **Schoolpersoneel** — teachers (LO), CLB staff, school canteen coordinators
3. **Breed** — dietitians, GPs, pediatricians, local health policy staff

### Brand Identity
- **Name:** BWEEG Academy
- **Tagline:** "Beweeg mee met gezonde jeugd"
- **Closing tagline:** "BWEEG Academy — kennis die beweegt."
- **Tone:** Warm, energetic, evidence-based but accessible. Positive and action-oriented.

---

## Content Changes

### 1. Hero Section

| Element | Current | New |
|---------|---------|-----|
| Badge | "Professionele Ontwikkeling in de Zorg" | "Het leerplatform van BWEEG vzw" |
| Headline | "Versterk je zorgteam met *deskundige* opleidingen." | "Beweeg mee met *gezonde* jeugd." |
| Subtext | Generic learning platform copy | "BWEEG Academy is het opleidingsplatform van BWEEG vzw, Vlaams expertisecentrum voor beweging, voeding en welzijn bij jongeren. Hier vind je praktijkgerichte cursussen, trajecten en webinars voor jeugdwerkers, sportcoaches en leerkrachten." |
| CTA Primary | "Bekijk opleidingen" | "Bekijk opleidingen" (unchanged) |
| CTA Secondary | "Onze aanpak" → `/over-ons/` | "Over BWEEG" → `/over-ons/` (URL unchanged, page assumed to exist) |

### 2. Learning Mode Selector

| Mode | Current Description | New Description |
|------|-------------------|-----------------|
| Trajecten | "Volg een leertraject met meerdere cursussen en begeleiding" | "Volg een leertraject en word expert in jeugdgezondheid" |
| Klassikaal | "Leer samen met anderen onder begeleiding van ervaren docenten" | "Leer samen met collega's onder begeleiding van onze trainers" |
| Online | "Leer op je eigen tempo met e-learning en webinars" | "Volg e-learnings en webinars op je eigen tempo" |

Section heading and subheading remain unchanged ("Hoe wil je leren?" / "Kies het format dat bij jou past").

### 3. Featured Courses Section

Heading and subheading updates:

| Element | Current | New |
|---------|---------|-----|
| Heading | "Binnenkort gepland" | "Binnenkort gepland" (unchanged) |
| Subheading | "Onze cursussen worden samengesteld door ervaren professionals uit de zorgsector." | "Onze cursussen worden ontwikkeld door sportwetenschappers, diëtisten en pedagogen met jarenlange ervaring." |

**Seed courses to create/replace** (LearnDash `sfwd-courses`):

Replace existing addiction-themed seed courses with BWEEG-themed courses. Old seed courses are removed by `unseed.php` and re-created by `seed.php`.

| # | Title | Excerpt | Format |
|---|-------|---------|--------|
| 1 | Motiverende gespreksvoering rond voeding bij jongeren | Leer hoe je jongeren op een niet-veroordelende manier motiveert om gezondere eetgewoontes te ontwikkelen. Met rollenspelen en praktijkcasussen. | klassikaal |
| 2 | Sportblessures voorkomen: van warm-up tot cool-down | Een evidence-based opleiding over blessurepreventie bij jongeren. Ideaal voor sportcoaches en leerkrachten LO. | klassikaal |
| 3 | Gezonde tussendoortjes in de jeugdwerking | Praktische workshop: hoe organiseer je gezonde snacks tijdens activiteiten zonder dat jongeren afhaken? Tips, recepten en budgetvriendelijke ideeën. | klassikaal |
| 4 | Mentale veerkracht bij jonge sporters | Herken signalen van prestatiedruk en leer technieken om mentale weerbaarheid te versterken bij competitieve jongeren. | online (webinar) |
| 5 | Beweegbeleid op school: van visie tot actie | Stappenplan om een actief beweegbeleid uit te bouwen op jouw school. Van draagvlak creëren tot concrete acties op de speelplaats. | klassikaal |
| 6 | Eetproblemen herkennen en bespreekbaar maken | Leer de vroege signalen van eetproblemen herkennen bij jongeren en hoe je het gesprek aangaat — zonder te diagnosticeren. | online (e-learning) |

**Taxonomy terms to update** in seed: replace addiction-themed `stride_theme` terms with BWEEG-relevant terms: `beweging`, `voeding`, `welzijn`, `sportblessures`, `jeugdwerk`, `schoolbeleid`.

**Company details** in `seedCompanyDetails()`: update from "VAD vzw" to "BWEEG vzw", address: Sportstraat 42, 9000 Gent, email: info@bweeg.be.

### 4. Mission Section

| Element | Current | New |
|---------|---------|-----|
| Heading | "Kwaliteitsvolle nascholing voor de volgende generatie zorgverleners." | "Elke jongere verdient een gezonde start — en elke professional de juiste tools." |
| Paragraph 1 | Generic professional growth copy | "BWEEG zet zich al sinds 2009 in voor gezonde jongeren in Vlaanderen — via onderzoek, beleidsadvies, campagnes en tools voor professionals. BWEEG Academy vertaalt die expertise naar praktijkgerichte opleidingen." |
| Paragraph 2 | Independent training center copy | "Van sportfederaties en jeugddiensten tot scholen en lokale besturen: wij ondersteunen iedereen die dagelijks met jongeren werkt. Wetenschappelijk onderbouwd, altijd dicht bij de praktijk." |
| Quote text | "Zorg voor anderen begint met investeren in jezelf." | "Jongeren bereik je niet met regels, maar met enthousiasme." |
| Quote attribution | "Dr. Els Van den Broeck" | "Lien De Smedt, sportpedagoge & BWEEG-trainer" |

### 5. Testimonial Section

| Element | Current | New |
|---------|---------|-----|
| Quote | Palliative care training testimonial | "Na de opleiding 'beweegbeleid op school' hebben we ons volledige middagpauze-aanbod omgegooid. De leerlingen bewegen nu drie keer meer — en wij als `<em>`leerkrachten`</em>` ook." |
| Name | "Sarah Janssens" | "Tom Vercauteren" |
| Title | "Verpleegkundige & Alumna 2024" | "Leerkracht LO & Sportcoördinator, Sint-Jozefscollege Aalst, 2025" |

### 6. CTA Section

| Element | Current | New |
|---------|---------|-----|
| Heading | "Klaar om te starten?" | "Klaar om het verschil te maken?" |
| Subtext | Generic enrollment copy | "Ontdek ons aanbod en schrijf je in. Versterk je aanpak met opleidingen die jongeren echt in beweging brengen." |
| CTA Primary | "Bekijk alle opleidingen" | "Bekijk alle opleidingen" (unchanged) |
| CTA Secondary | "Neem contact op" | "Neem contact op" (unchanged) |
| Tagline | "Een oase van groei voor zorgprofessionals." | "BWEEG Academy — kennis die beweegt." |

### 7. WordPress Settings

| Setting | Current | New |
|---------|---------|-----|
| Site Title (blogname) | "Stride LMS" (or similar) | "BWEEG Academy" |
| Tagline (blogdescription) | Generic | "Het opleidingsplatform van BWEEG vzw" |

```bash
ddev exec wp option update blogname "BWEEG Academy"
ddev exec wp option update blogdescription "Het opleidingsplatform van BWEEG vzw"
```

### 8. Footer

No template changes needed — footer pulls from `bloginfo('name')` and `bloginfo('description')`, so WordPress Settings update covers it.

---

## Files to Modify

1. **`web/app/themes/stridence/front-page.php`** — All hardcoded content strings
2. **`scripts/seed.php`** — Add/update BWEEG-themed course seed data (titles, excerpts)
3. **WordPress database** — Site title and tagline via WP-CLI

## Files NOT Modified

- `tokens.css` — No color/brand changes
- `header.php` / `footer.php` — Already use `bloginfo()`, no changes needed
- `theme-config.php` — Company settings are for invoicing, not public branding
- Component CSS — No design changes

---

### 9. Mission Icon

The mission section currently uses a `heart` icon. Replace with a more fitting icon for youth/sports context. Use `activity` (pulse line) or keep `heart` if no suitable alternative exists in the icon set.

---

## Implementation Notes

- **Seed replacement strategy:** The seed script's `unseed.php` removes all seed data. Update `seed.php` to create BWEEG courses instead of addiction courses. Running unseed + seed gives a clean BWEEG demo.
- **"Alle edities" link** in Featured Courses section: leave as-is (it's a valid label for the listing page).
- **Footer `/over-ons/` link:** stays unchanged — same as Hero CTA target.

---

## Out of Scope

- Logo design / custom imagery
- Color palette changes
- Course content (lessons, quizzes) — only titles and excerpts for the listing
- Editions/sessions seed data structure (dates, venues — keep existing pattern, just re-label)
- Main BWEEG website (fictional, referenced but not built)
- Inner page content updates (`/klassikaal/`, `/online/`, dashboard) — homepage only
