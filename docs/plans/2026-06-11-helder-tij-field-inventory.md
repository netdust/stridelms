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

| `contact_intro` | `page-contact.php` — header band intro paragraph | `Contact.dc.html` line 39 | Site option or page excerpt | "Een vraag over een opleiding, een offerte voor je team, of gewoon eens aftoetsen wat kan? We antwoorden binnen één werkdag — met een mens, niet met een ticketnummer." |
| `contact_persons` | `page-contact.php` — persons cluster (initials + blurb) | `Contact.dc.html` lines 50-54 | ACF repeater on contact page or site option | Initials: LD / EM / JV; blurb: "Lies, Eva en Jonas beantwoorden je bericht — zij kennen het aanbod door en door." |
| `contact_address` | `page-contact.php` — info card "Bezoek ons" block | `Contact.dc.html` lines 59-61 | Stride site settings or ACF option | "Vanderlindenstraat 15, 1030 Brussel — op 5 min wandelen van station Schaarbeek" |
| `contact_phone` | `page-contact.php` — info card "Bel of mail" block | `Contact.dc.html` line 65 | Stride site settings or ACF option | "+32 2 123 45 67" |
| `contact_hours` | `page-contact.php` — info card "Bel of mail" block | `Contact.dc.html` line 65 | Stride site settings or ACF option | "werkdagen 9:00 – 17:00" |
| `contact_email` | `page-contact.php` — info card "Bel of mail" block | `Contact.dc.html` line 65 | Stride site settings or ACF option | "info@stride.be" |
| `contact_vat` | `page-contact.php` — info card "Facturatie" block | `Contact.dc.html` line 70 | Stride site settings or ACF option | "BTW BE 0123.456.789" |
| `contact_kmo` | `page-contact.php` — info card "Facturatie" block | `Contact.dc.html` line 70 | Stride site settings or ACF option | "Erkend dienstverlener KMO-portefeuille" |
| `map_embed` | `page-contact.php` — map slot placeholder | `Contact.dc.html` line 75-76 | Site option (Google Maps embed URL or iframe) | Striped placeholder with caption "kaart: locatie Schaarbeek" |

## Over ons page (`page-over-ons.php`) — Task 9.3

| Field | Surface (template:line) | Mockup source | Suggested source | Placeholder used |
|---|---|---|---|---|
| `over_ons_eyebrow` — eyebrow label above h1 | `page-over-ons.php` — editorial hero | `Over ons.dc.html` line 37 | `get_the_title()` (current) or page meta `over_ons_eyebrow` | `get_the_title()` → "Over ons" |
| `over_ons_headline` — serif light editorial h1 | `page-over-ons.php` — editorial hero | `Over ons.dc.html` line 38 | Page meta field `over_ons_headline` (text) | "We begonnen in een leefgroep, niet in een leslokaal." |
| `over_ons_lede` — italic serif hero sub-headline | `page-over-ons.php` — editorial hero | `Over ons.dc.html` line 39 | Page meta field `over_ons_lede` (textarea) | "Stride ontstond in 2011, toen twee jeugdzorgbegeleiders…" |
| `over_ons_pullquote` — pull-quote text | `page-over-ons.php` — pull-quote section | `Over ons.dc.html` line 51 | Page meta field `over_ons_pullquote` (textarea) | "Een goede opleiding voelt niet als een dag weg van het werk…" |
| `over_ons_photo` — 21:9 team/office photo | `page-over-ons.php` — photo slot section | `Over ons.dc.html` line 56 | Page meta field `over_ons_photo` (image attachment) | Striped CSS placeholder |
| `over_ons_photo_caption` — caption below 21:9 photo | `page-over-ons.php` — photo slot section | `Over ons.dc.html` line 57 | Page meta field `over_ons_photo_caption` (text) | "foto: het team, kantoor Schaarbeek" |
| `over_ons_values[]` — values repeater (3 items) | `page-over-ons.php` — "Waar we voor staan" section | `Over ons.dc.html` lines 62-64 | Page meta repeater `over_ons_values[]` → `title` + `description` per item | "Praktijk eerst" / "Veilig oefenen" / "Geen blabla" |
| `over_ons_team[]` — team members (3 named + overflow card) | `page-over-ons.php` — "Het team" section | `Over ons.dc.html` lines 69-72 | CPT `stride_trainer` or page meta repeater `over_ons_team[]` → `initials`, `name`, `role` | Lies De Smet / Jonas Verhulst / Eva Maerten / "+9 lesgevers" |
| `over_ons_cta_heading` — closing CTA heading | `page-over-ons.php` — closing CTA section | `Over ons.dc.html` line 80 | Page meta field `over_ons_cta_heading` (text) | "Benieuwd of we bij jouw organisatie passen?" |

## Follow-ups

- Herinner werkgever action (mockup Dashboard :241) — needs a stride-core handler (`stride_remind_employer`); button omitted until it exists.
- Segmented control (Wacht op mij/gebruiker/Meldingen) on 'Acties nodig' — omitted: `buildActionList()` returns a flat list; needs a stride-core bucket extension (mockup Dashboard :118-141).
- Progress-ring omitted from continue_course hero — mockup hero band shows no ring; baseline had one (confirm at shakeout).
- Hero band uniform teal (bg-badge-online-bg) for ALL hero types incl. action_required — per mockup; baseline used warning-orange for action_required (confirm at shakeout).
- Phase 10 cleanup list: dead `dashboardHome` openPanel/closePanel/panelOpen/activeEnrollment state in `src/main.js`; dead `.dash-card-hero` + `.safe-area-bottom` CSS in `components.css`.
