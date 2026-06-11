# Helder Tij — field inventory & override verification

Every PLACEHOLDER rendered by a Helder Tij template is logged here so the
missing data sources can be decided post-redesign. Suggested sources are
proposals only — **no new data flow was added by the redesign tasks** (hard
rule: any task that tempted a stride-core change was stubbed theme-side and
recorded here instead).

**How to wire a row:** each row is a content block currently rendered from a
hardcoded, i18n'd placeholder string (text-domain `stridence`). Wiring it up
means: add the suggested source (CPT field via the owning CPT's `getFields()`,
site option, or page meta), read it in the template at the listed line, and
keep the current placeholder as the empty-state fallback. All line numbers
verified against `feature/helder-tij-redesign` HEAD on 2026-06-11.

---

## Edition detail (`single-vad_edition.php`)

| Field | Surface (template:line) | Mockup source | Suggested source | Placeholder used |
|---|---|---|---|---|
| Learning outcomes ("Wat je leert" checklist, 3 items) | `single-vad_edition.php:355` (`$learning_items`, rendered :366) | `Detail - Editie.dc.html` :76-84 | New course/edition meta `learning_outcomes` (repeater) on the course CPT | "Spanning en escalatie vroegtijdig herkennen" / "De-escalerend communiceren in moeilijke gesprekken" / "Grenzen stellen met behoud van de zorgrelatie" |
| Audience ("Voor wie?" well) | `single-vad_edition.php:375` | `Detail - Editie.dc.html` :85 | New course meta `audience` (textarea) | "Begeleiders, verpleegkundigen en onthaalmedewerkers in zorg en welzijn. Geen voorkennis nodig." |
| Inclusions ("Inbegrepen" card) | `single-vad_edition.php:538` | `Detail - Editie.dc.html` :110 | New edition meta `included` (textarea) | "Lunch, koffie en cursusmateriaal. Je ontvangt achteraf een attest van deelname." |
| Cancellation policy ("Annuleren" card) | `single-vad_edition.php:545` | `Detail - Editie.dc.html` :111 | Site-wide setting (Stride settings) with per-edition override | "Kosteloos tot 14 dagen vóór de eerste sessie. Daarna kan een collega je plaats overnemen." |
| Speaker name fallback (when `speakers` meta empty) | `single-vad_edition.php:182` (`$lesgever_name`, rendered :561) | `Detail - Editie.dc.html` :120 | Existing edition meta `speakers` (already used when present) | "Lesgever nog te bevestigen" |
| Speaker role line | `single-vad_edition.php:562` | `Detail - Editie.dc.html` :121 | New speaker entity/meta `speaker_role` | "Lesgever" |
| Speaker bio | `single-vad_edition.php:564` | `Detail - Editie.dc.html` :122 | New speaker entity/meta `speaker_bio` | "Meer informatie over de lesgever volgt binnenkort." |
| `cta_price_includes` — price-includes line under the sidebar price | `single-vad_edition.php:589` | `Detail - Editie.dc.html` :133 | New edition meta `price_includes` (or derive from the same `included` meta as the "Inbegrepen" card) | "incl. lunch en cursusmateriaal" |
| `cta_quote_url` — "Offerte voor je team" ghost CTA target | `single-vad_edition.php:648` | `Detail - Editie.dc.html` :140 | Site-wide setting (Stride settings) pointing at a quote-request page/flow; no quote-request page exists today | links to `/contact/` |
| `cta_benefits` — edition benefits checklist | `single-vad_edition.php:238` (`$cta_benefits`, rendered :657) | `Detail - Editie.dc.html` :144-145 | Site-wide setting with per-edition override (cancellation copy must stay in sync with the "Annuleren" card) | "Attest van deelname" / "Kosteloos annuleren tot 14 dagen vooraf" |

## Online course detail (`templates/course/`)

| Field | Surface (template:line) | Mockup source | Suggested source | Placeholder used |
|---|---|---|---|---|
| Course duration ("± 2 uur") | `templates/course/header.php:111` — online meta dot-row | `Detail - Online opleiding.dc.html` :49 | New course meta (e.g. `duration_minutes`), or summed per-lesson durations | omitted — segment not rendered (no fake data) |
| "met afsluitende toets" | `templates/course/header.php:111` — online meta dot-row | `Detail - Online opleiding.dc.html` :51 | LearnDashHelper quiz-presence helper (course global quiz exists) | omitted — segment not rendered |
| Per-lesson duration ("20 min" … "30 min") | `templates/course/content.php:152` — drip lesson list rows | `Detail - Online opleiding.dc.html` :75-99 | New lesson meta (e.g. `lesson_duration_minutes`) exposed via `LearnDashHelper::getLessons()` | omitted — duration column not rendered (custom drip list; LD-native list untouched per INV-6) |
| Remaining time estimate ("± 55 min") | `templates/course/sidebar-online.php:210` (enrolled state) + `templates/course/mobile-cta.php:85` | `Detail - Online opleiding.dc.html` :118, :141 | Derived from per-lesson durations of incomplete lessons | omitted — "Nog X modules" rendered without time estimate |
| Benefits checklist copy | `templates/course/sidebar-online.php:119` (rows :123-125, enrolled + not-enrolled states) | `Detail - Online opleiding.dc.html` :125-127 | Site copy decision or per-course meta; copy must not claim "gratis" on paid courses | generic i18n'd rows kept: "Direct toegang" / "Leer in je eigen tempo" / "Certificaat na afronding" |

## Homepage (`front-page.php`) — Task 9.1

| Field | Surface (template:line) | Mockup source | Suggested source | Placeholder used |
|---|---|---|---|---|
| `stats_trio` — "Waarom Stride" stats trio (value + label ×3) | `front-page.php:184` (`$stats_trio` :186) | `Homepage.dc.html` :125-129 | Site-wide setting (Stride settings, about/stats group) | "15 jaar ervaring" / "3.200+ deelnemers per jaar" / "9,1 gemiddelde score" |
| `waarom_foto` — "Waarom Stride" photo + caption | `front-page.php:201` — photo slot | `Homepage.dc.html` :131-133 | Media field (site-wide setting or front-page meta); caption replaced by a real photo + `alt` once one exists | repeating-gradient pattern div + i18n'd caption "foto: lesgever met groep, warm licht" |
| `cta_team_copy` — closing CTA heading + copy (in-company offer) | `front-page.php:215` — closing CTA band | `Homepage.dc.html` :141-142 | Site copy decision (static template copy or site-wide setting) | "Een opleiding voor je hele team?" / "We komen naar jouw organisatie, stemmen de inhoud af op jullie praktijk en regelen alles — van offerte tot attesten." |

## Contact (`page-contact.php`) — Task 9.2

| Field | Surface (template:line) | Mockup source | Suggested source | Placeholder used |
|---|---|---|---|---|
| `contact_intro` | `page-contact.php:33` — header band intro paragraph | `Contact.dc.html` :39 | Site option or page excerpt | "Een vraag over een opleiding, een offerte voor je team, of gewoon eens aftoetsen wat kan? We antwoorden binnen één werkdag — met een mens, niet met een ticketnummer." |
| `contact_persons` | `page-contact.php:58` — persons cluster (initials + blurb) | `Contact.dc.html` :50-54 | ACF repeater on contact page or site option | Initials: LD / EM / JV; blurb: "Lies, Eva en Jonas beantwoorden je bericht — zij kennen het aanbod door en door." |
| `contact_address` | `page-contact.php:81` — info card "Bezoek ons" block | `Contact.dc.html` :59-61 | Stride site settings or ACF option | "Vanderlindenstraat 15, 1030 Brussel — op 5 min wandelen van station Schaarbeek" |
| `contact_phone` | `page-contact.php:101` (tel-link TODO :111) | `Contact.dc.html` :65 | Stride site settings or ACF option | "+32 2 123 45 67" |
| `contact_hours` | `page-contact.php:101` — info card "Bel of mail" block | `Contact.dc.html` :65 | Stride site settings or ACF option | "werkdagen 9:00 – 17:00" |
| `contact_email` | `page-contact.php:101` (mailto-link TODO :114) | `Contact.dc.html` :65 | Stride site settings or ACF option | "info@stride.be" |
| `contact_vat` | `page-contact.php:124` — info card "Facturatie" block | `Contact.dc.html` :70 | Stride site settings or ACF option | "BTW BE 0123.456.789" |
| `contact_kmo` | `page-contact.php:124` — info card "Facturatie" block | `Contact.dc.html` :70 | Stride site settings or ACF option | "Erkend dienstverlener KMO-portefeuille" |
| `map_embed` | `page-contact.php:144` — map slot placeholder (caption :151) | `Contact.dc.html` :75-76 | Site option (Google Maps embed URL or iframe) | Striped placeholder with caption "kaart: locatie Schaarbeek" |

## Over ons (`page-over-ons.php`) — Task 9.3

| Field | Surface (template:line) | Mockup source | Suggested source | Placeholder used |
|---|---|---|---|---|
| `over_ons_eyebrow` — eyebrow label above h1 | `page-over-ons.php:43` (renders `get_the_title()` :47) | `Over ons.dc.html` :37 | `get_the_title()` (current) or page meta `over_ons_eyebrow` | `get_the_title()` → "Over ons" |
| `over_ons_headline` — serif light editorial h1 | `page-over-ons.php:55` — editorial hero | `Over ons.dc.html` :38 | Page meta field `over_ons_headline` (text) | "We begonnen in een leefgroep, niet in een leslokaal." |
| `over_ons_lede` — italic serif hero sub-headline | `page-over-ons.php:69` — editorial hero | `Over ons.dc.html` :39 | Page meta field `over_ons_lede` (textarea) | "Stride ontstond in 2011, toen twee jeugdzorgbegeleiders…" |
| `over_ons_pullquote` — pull-quote text | `page-over-ons.php:102` — pull-quote section | `Over ons.dc.html` :51 | Page meta field `over_ons_pullquote` (textarea) | "Een goede opleiding voelt niet als een dag weg van het werk…" |
| `over_ons_photo` — 21:9 team/office photo | `page-over-ons.php:123` (striped div :133) | `Over ons.dc.html` :56 | Page meta field `over_ons_photo` (image attachment) | Striped CSS placeholder |
| `over_ons_photo_caption` — caption below 21:9 photo | `page-over-ons.php:123` (caption :135) | `Over ons.dc.html` :57 | Page meta field `over_ons_photo_caption` (text) | "foto: het team, kantoor Schaarbeek" |
| `over_ons_values[]` — values repeater (3 items) | `page-over-ons.php:157` ("Waar we voor staan", items :166) | `Over ons.dc.html` :62-64 | Page meta repeater `over_ons_values[]` → `title` + `description` per item | "Praktijk eerst" / "Veilig oefenen" / "Geen blabla" |
| `over_ons_team[]` — team members (3 named + overflow card) | `page-over-ons.php:208` ("Het team", members :217) | `Over ons.dc.html` :69-72 | CPT `stride_trainer` or page meta repeater `over_ons_team[]` → `initials`, `name`, `role` | Lies De Smet / Jonas Verhulst / Eva Maerten / "+9 lesgevers" |
| `over_ons_cta_heading` — closing CTA heading | `page-over-ons.php:273` — closing CTA section | `Over ons.dc.html` :80 | Page meta field `over_ons_cta_heading` (text) | "Benieuwd of we bij jouw organisatie passen?" |

## Dashboard (`templates/dashboard/`)

No placeholder *content* fields were stubbed on the dashboard. Two mockup
features were omitted because they need stride-core support (out of scope per
the no-core-change rule):

| Field | Surface (template:line) | Mockup source | Suggested source | Placeholder used |
|---|---|---|---|---|
| "Herinner werkgever" action button | `templates/dashboard/tab-offertes.php` — quote row actions | `Dashboard - Mijn account.dc.html` :241 | New stride-core AJAX handler `stride_remind_employer` (zero references exist at HEAD — verified 2026-06-11) | omitted — button not rendered until the handler exists |
| Segmented control (Wacht op mij / Wacht op gebruiker / Meldingen) on "Acties nodig" | `templates/dashboard/tab-home.php:103` — "Acties nodig" card | `Dashboard - Mijn account.dc.html` :118-141 | `UserDashboardService::buildActionList()` (`stride-core/Modules/User/UserDashboardService.php:190`) returns a flat list — needs a bucket extension in stride-core | omitted — flat action list rendered without segments |

---

## Follow-ups

### Product gaps (stride-core owned — found during the redesign, NOT introduced by it)

All verified against HEAD on 2026-06-11:

- **Inert keuzes form / no confirm endpoint** — `templates/trajectory/tab-keuzes.php:142`
  (`#elective-selection-form`, submit button :185) has no `action` attribute and no
  JS handler binds the form anywhere in the theme (restyle preserved the form id +
  input names verbatim; the submit path did not exist before the redesign either).
  Needs a stride-core endpoint + theme wiring.
- **`trajectory_messages` unregistered in `TrajectoryCPT`** —
  `TrajectoryCPT::getFields()` registers `trajectory_details` / `trajectory_deadlines` /
  `trajectory_courses` / `trajectory_pricing` only, while `TrajectoryRepository.php:201`
  and `TrajectoryAdminController.php` read/write `trajectory_messages`. INV-3 violation
  (each CPT's `getFields()` is the field-name source of truth) — fix in stride-core.
- **Dead `stride_quote_pdf` admin-ajax handler** — the theme links to
  `admin-ajax.php?action=stride_quote_pdf` (`templates/dashboard/tab-offertes.php:134`,
  `templates/dashboard/tab-downloads.php:233`) but no `wp_ajax_stride_quote_pdf`
  handler exists anywhere in stride-core (grep: zero hits; `QuotePDFGenerator` exists
  but registers no such action). The links return admin-ajax's `0`/400. Needs a
  stride-core handler or the links re-pointed at the real download path.
- **`stride_remind_employer` missing handler** — see Dashboard table above.
- **Segmented-control data need** — see Dashboard table above
  (`buildActionList()` bucket extension).
- **Email templates restyle** — `templates/emails/` does NOT live in the theme
  (CLAUDE.md tree was stale); email templates are stride-core owned, so restyling
  them to Helder Tij is a stride-core follow-up, out of scope for this branch.

### Shake-out confirmations (design-intentional changes to confirm with Stefan)

- Progress-ring omitted from the `continue_course` dashboard hero — the mockup hero
  band shows no ring; the baseline had one.
- Hero band uniform teal (`bg-badge-online-bg`) for ALL hero types incl.
  `action_required` — per mockup; baseline used warning-orange for `action_required`.

### Phase-10 cleanup status (re-verified at HEAD, 2026-06-11)

- Dead `dashboardHome` openPanel/closePanel/panelOpen/activeEnrollment state in
  `src/main.js` — **already removed** during Phase 10 (the remaining
  `openPanel` in `main.js:244` belongs to the live `slidePanel` factory).
- `.dash-card-hero` (`src/css/components.css:205`) — **still dead** (zero template
  usage); safe to delete in a later cleanup pass.
- `.safe-area-bottom` (`src/css/components.css:537`) — **no longer dead**: used by
  `single-vad_trajectory.php:230` and `:236`. Keep it.

### Post-deploy template assignment (run on staging/prod after deploy)

These commands assign the correct page templates to the Contact and Over ons
pages. Local page IDs are fixed; run these via WP-CLI on each environment after
deploying the branch (page IDs may differ on staging/prod — verify first with
`wp post list --post_type=page --name=contact --field=ID`).

```bash
# Local (DDEV) IDs — confirmed 2026-06-11:
#   contact  → 14543
#   over-ons → 14545
wp post meta update 14543 _wp_page_template page-contact.php
wp post meta update 14545 _wp_page_template page-over-ons.php
```

### Dropped homepage content (design-intentional — confirm at shakeout)

The following blocks were present in the previous homepage but are absent from
the Helder Tij `front-page.php`. Removal is intentional per the mockup. Confirm
with Stefan at shakeout that each omission is deliberate before closing Phase 10.

| Removed block | Previous location | Suggested destination |
|---|---|---|
| Sarah Janssens testimonial blockquote | Homepage — social proof / testimonials section | Could move to `page-over-ons.php` alongside the pull-quote if a real testimonials section is wanted |
| Dr. Els Van den Broeck mission quote card | Homepage — mission/vision statement section | Could move to `page-over-ons.php` as a second pull-quote or values-section context |
| "Klaar om te starten?" CTA band | Homepage — closing CTA (bottom of page) | Replaced by the new in-company offer CTA band (`cta_team_copy`); restore if Stefan wants a second CTA |

---

## Verification record (Task 10.4 — 2026-06-11)

### Client-override smoke (locked decision 1, adapted)

`stride-client-vad`'s main loader is deleted in the local working tree
(pre-existing state, not restored). The override CHAIN was verified live with
`stride-client-kindred` instead (loader temporarily enabled by copying the
gitignored `stride-client-kindred.php.off` → `.php`, removed after; it loads
via the Bedrock mu-plugin autoloader — `wp eval class_exists` confirmed).

| # | Check | Result |
|---|---|---|
| a | Token override: client CSS enqueues AFTER theme + LD styles (`wp_enqueue_scripts` p100, deps on `learndash-front`/`ld30-modern` when registered). Live link order: `stridence-0-css` → `learndash-front-css` → `stride-client-css`. Every theme token kindred's `:root` overrides exists in v2 `tokens.css` (the only non-v2 names are kindred's own additions `--kindred-*` / `--font-display-alt` and LearnDash `--ld-*` skin vars) — v2 is a superset, confirmed. | **PASS** |
| b | `stridence_font_url` filter: present in BOTH header templates (`header.php:18`, `header-dashboard.php:21`). Live with kindred active, the Google Fonts URL switched from the Helder Tij default (Hanken Grotesk + Newsreader) to kindred's Geist/Instrument Serif/Fraunces URL; reverted to default after deactivation. | **PASS** |
| c | Template override: kindred's `front-page.php` won via `template_include` (p20) — live front page rendered kindred markup. `NTDST_Template_Loader::addPath()` priority ordering unchanged on this branch: `git diff --stat staging..HEAD -- web/app/mu-plugins/` → **empty**. | **PASS** |
| d | `?tab=` URLs: with kindred active, logged-out `GET /mijn-account/?tab=certificaten` → 302 to `/aanmelden?redirect_to=…` (F9 E2 gate preserved). Logged-in tab behavior (all 8 tabs reachable, invalid `?tab=` → home allow-list) verified in Phases 7-8 (plan Tasks "dashboard app shell" Step 4 + F9 acceptance row). | **PASS** |

No breakage found — the base theme stays override-friendly; no theme fix needed.

### Drift sweeps

| Sweep | Command (theme scope, excl. `dist/`/`node_modules/`) | Result |
|---|---|---|
| SSA-5 raw token consumption | `grep -rn "var(--color" …` | **PASS** — 42 distinct `--color-*` names referenced; all 42 exist in v2 `src/css/tokens.css`; zero misses |
| SSA-6 detail-tabs factories | `grep -rn "editionDetailTabs\|trajectoryDetailTabs\|courseDetailTabs" …` | **PASS** — `editionDetailTabs`: zero usages (one historical comment `main.js:117` noting its removal). `courseDetailTabs`: factory `main.js:116` + sole consumer `templates/course/tabs.php:17` (klassikaal `!$is_online` branch). `trajectoryDetailTabs`: factory `main.js:118` + sole consumer `templates/trajectory/tabs.php:17` (public trajectory). No strays |
| SSA-1 Google Fonts | `grep -rn "fonts.googleapis" … --include="*.php"` | **PASS** — exactly 2 call sites (`header.php`, `header-dashboard.php`), both Hanken Grotesk + Newsreader behind the `stridence_font_url` filter (+ matching preconnects) |
| Old fonts | `grep -rn "Plus Jakarta\|Manrope" …` | **PASS** — only 3 historical comment lines in `tokens.css` (:14, :76-77) documenting the retirement |
| stride-core untouched | `git diff --stat staging..HEAD -- web/app/mu-plugins/` | **PASS** — empty |
| Language files untouched | `git log staging..HEAD --oneline -- web/app/languages/` | **PASS** — empty |

### Regression gate

| Suite | Result |
|---|---|
| `ddev exec vendor/bin/phpunit --testsuite Unit` | **OK (987 tests, 2509 assertions)** |
| `ddev exec vendor/bin/phpunit -c phpunit-integration.xml.dist` | 458 tests, **18 errors + 135 failures + 1 skipped** — **pre-existing environmental, unrelated to this branch**: all failing classes are stride-core domain tests (enrollment/cascade/registrations/quotes/partner-API). Root cause: the local DB (re-ported; now `stride_`-prefixed) lacks the `parent_registration_id` + `initial_selection` columns on `stride_vad_registrations` that current `RegistrationRepository` writes (`:300`, `:41`; schema source `RegistrationTable.php` dbDelta) → every test registration insert returns `WP_Error('Failed to create registration')` and cascades. The branch's integration surface is byte-identical to staging (`git diff staging..HEAD -- tests/ phpunit-integration.xml.dist web/app/mu-plugins/` → only one new **unit** test file, `tests/Unit/Theme/BadgeStatusPartialTest.php`), so the same failures occur on `staging` against this DB. Fix is environmental: re-run the stride-core table migration (dbDelta) against the ported DB. |
| `npm run test:unit` (theme) | **22 passed (22)** — 4 files |
| `npm run build` (theme) | **green** — `dist/main.BdZXqvYd.css` 81.69 kB / `dist/main.p7UbscCZ.js` 55.70 kB |
