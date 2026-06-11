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
