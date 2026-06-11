# Helder Tij — field inventory (placeholders used in templates)

Every PLACEHOLDER rendered by a Helder Tij template is logged here so the
missing data sources can be decided post-redesign. Suggested sources are
proposals only — no new data flow was added by the redesign tasks.

| Field | Surface (template:line) | Mockup source | Suggested source | Placeholder used |
|---|---|---|---|---|
| Learning outcomes ("Wat je leert" checklist, 3 items) | `single-vad_edition.php` — Omschrijving panel (`$learning_items`) | `Detail - Editie.dc.html` lines 76-84 | New course/edition meta `learning_outcomes` (repeater) on the course CPT | "Spanning en escalatie vroegtijdig herkennen" / "De-escalerend communiceren in moeilijke gesprekken" / "Grenzen stellen met behoud van de zorgrelatie" |
| Audience ("Voor wie?" well) | `single-vad_edition.php` — Omschrijving panel | `Detail - Editie.dc.html` line 85 | New course meta `audience` (textarea) | "Begeleiders, verpleegkundigen en onthaalmedewerkers in zorg en welzijn. Geen voorkennis nodig." |
| Inclusions ("Inbegrepen" card) | `single-vad_edition.php` — Praktisch panel | `Detail - Editie.dc.html` line 110 | New edition meta `included` (textarea) | "Lunch, koffie en cursusmateriaal. Je ontvangt achteraf een attest van deelname." |
| Cancellation policy ("Annuleren" card) | `single-vad_edition.php` — Praktisch panel | `Detail - Editie.dc.html` line 111 | Site-wide setting (Stride settings) with per-edition override | "Kosteloos tot 14 dagen vóór de eerste sessie. Daarna kan een collega je plaats overnemen." |
| Speaker name fallback (when `speakers` meta empty) | `single-vad_edition.php` — Lesgever panel (`$lesgever_name`) | `Detail - Editie.dc.html` line 120 | Existing edition meta `speakers` (already used when present) | "Lesgever nog te bevestigen" |
| Speaker role line | `single-vad_edition.php` — Lesgever panel | `Detail - Editie.dc.html` line 121 | New speaker entity/meta `speaker_role` | "Lesgever" |
| Speaker bio | `single-vad_edition.php` — Lesgever panel | `Detail - Editie.dc.html` line 122 | New speaker entity/meta `speaker_bio` | "Meer informatie over de lesgever volgt binnenkort." |
| Course duration ("± 2 uur") | `templates/course/header.php` — online meta dot-row | `Detail - Online opleiding.dc.html` line 49 | New course meta (e.g. `duration_minutes`), or summed per-lesson durations | omitted — segment not rendered (no fake data) |
| "met afsluitende toets" | `templates/course/header.php` — online meta dot-row | `Detail - Online opleiding.dc.html` line 51 | LearnDashHelper quiz-presence helper (course global quiz exists) | omitted — segment not rendered |
| Per-lesson duration ("20 min" … "30 min") | `templates/course/content.php` — lesson list rows | `Detail - Online opleiding.dc.html` lines 75-99 | New lesson meta (e.g. `lesson_duration_minutes`) exposed via `LearnDashHelper::getLessons()` | omitted — duration column not rendered (custom drip list; LD-native list untouched per INV-6) |
| Remaining time estimate ("± 55 min") | `templates/course/sidebar-online.php` enrolled state + `templates/course/mobile-cta.php` | `Detail - Online opleiding.dc.html` lines 118, 141 | Derived from per-lesson durations of incomplete lessons | omitted — "Nog X modules" rendered without time estimate |
| Benefits checklist copy ("Gratis voor de hele sector" / "Certificaat na de afsluitende toets" / "Je voortgang wordt automatisch bewaard") | `templates/course/sidebar-online.php` — card (enrolled + not-enrolled states) | `Detail - Online opleiding.dc.html` lines 125-127 | Site copy decision or per-course meta; copy must not claim "gratis" on paid courses | generic i18n'd rows kept: "Direct toegang" / "Leer in je eigen tempo" / "Certificaat na afronding" |
| `cta_price_includes` — price-includes line under the sidebar price | `single-vad_edition.php` — sidebar CTA card (price block) | `Detail - Editie.dc.html` line 133 | New edition meta `price_includes` (or derive from the same `included` meta as the "Inbegrepen" card) | "incl. lunch en cursusmateriaal" |
| `cta_quote_url` — "Offerte voor je team" ghost CTA target | `single-vad_edition.php` — sidebar CTA card (secondary button) | `Detail - Editie.dc.html` line 140 | Site-wide setting (Stride settings) pointing at a quote-request page/flow; no quote-request page exists today | links to `/contact/` |
| `cta_benefits` — edition benefits checklist ("Attest van deelname" / "Kosteloos annuleren tot 14 dagen vooraf") | `single-vad_edition.php` — sidebar CTA card (`$cta_benefits`) | `Detail - Editie.dc.html` lines 144-145 | Site-wide setting with per-edition override (cancellation copy must stay in sync with the "Annuleren" card) | "Attest van deelname" / "Kosteloos annuleren tot 14 dagen vooraf" |

## Follow-ups

- Herinner werkgever action (mockup Dashboard :241) — needs a stride-core handler (`stride_remind_employer`); button omitted until it exists.
- Segmented control (Wacht op mij/gebruiker/Meldingen) on 'Acties nodig' — omitted: `buildActionList()` returns a flat list; needs a stride-core bucket extension (mockup Dashboard :118-141).
- Progress-ring omitted from continue_course hero — mockup hero band shows no ring; baseline had one (confirm at shakeout).
- Hero band uniform teal (bg-badge-online-bg) for ALL hero types incl. action_required — per mockup; baseline used warning-orange for action_required (confirm at shakeout).
- Phase 10 cleanup list: dead `dashboardHome` openPanel/closePanel/panelOpen/activeEnrollment state in `src/main.js`; dead `.dash-card-hero` + `.safe-area-bottom` CSS in `components.css`.
