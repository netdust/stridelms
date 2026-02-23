# Stridence UX/UI Design

> **Design document for the Stridence theme** - Kadence child theme for Stride LMS

**Date:** 2026-02-23
**Status:** Approved
**Approach:** Full Page Structure (Approach B) - separate pages for each section

---

## Design Principles

- **Mobile-first:** All layouts designed for mobile, enhanced for desktop
- **Kadence-native:** Use Kadence blocks and patterns, minimal custom CSS
- **Touch-friendly:** Minimum 44px tap targets, swipeable elements
- **Card-based:** Consistent card components across all listings

---

## Page Structure

### Public Archives

| URL | Purpose |
|-----|---------|
| `/cursussen/` | All courses, filterable by type |
| `/cursussen/e-learning/` | Online courses only |
| `/cursussen/klassikaal/` | In-person courses with editions |
| `/trajecten/` | Learning path archives |

### Detail Pages

| URL | Purpose |
|-----|---------|
| `/cursussen/{course}/` | Course detail (e-learning: direct enroll, in-person: list editions) |
| `/cursussen/klassikaal/{edition}/` | Edition detail with sessions, location, pricing |
| `/traject/{trajectory}/` | Trajectory detail with visual progress path |

### User Dashboard (mobile-first, bottom nav)

| URL | Purpose |
|-----|---------|
| `/mijn-account/` | Overview: stats, next session, continue learning |
| `/mijn-account/cursussen/` | Enrolled courses with progress |
| `/mijn-account/kalender/` | List view (mobile) / calendar (desktop) |
| `/mijn-account/trajecten/` | Learning paths with visual progress |
| `/mijn-account/offertes/` | Quote history and status |
| `/mijn-account/profiel/` | Accordion sections: personal, company, billing, password, preferences |

### Forms (flexible per course)

| URL | Purpose |
|-----|---------|
| `/inschrijven/{edition\|trajectory}/` | Enrollment form (configurable via FluentForms) |
| `/interesse/{course\|edition\|trajectory}/` | Lead capture / waitlist / info request |

---

## Template Designs

### Course Card Component

```
┌─────────────────────────────┐
│ [Course Image]              │
│ ┌─────┐                     │
│ │TYPE │ ← Badge (E-LRN/KLAS)│
│ └─────┘                     │
├─────────────────────────────┤
│ Course Title                │
│ Short description...        │
│                             │
│ ⏱ Duration  │  €Price       │
│ 📍 Location (if in-person)  │
│ ⚠ Capacity warning          │
│                             │
│ [    Bekijk cursus    ]     │
└─────────────────────────────┘
```

**Grid:** 3-col desktop, 2-col tablet, 1-col mobile

---

### Course Detail (E-learning)

```
┌─────────────────────────────┐
│ [Hero Image]                │
│ ┌─────┐                     │
│ │E-LRN│                     │
│ └─────┘                     │
│ Course Title                │
│ ⏱ Duration  •  📚 Lessons   │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Wat leer je?                │
│ • Learning outcomes         │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Cursusinhoud (accordion)    │
│ ▼ Module 1                  │
│   ○ Lesson 1                │
│ ▶ Module 2                  │
└─────────────────────────────┘

┌─────────────────────────────┐  ← Sticky mobile
│ €Price                      │
│ [   Start nu met leren   ]  │
└─────────────────────────────┘
```

---

### Course Detail (In-person)

```
┌─────────────────────────────┐
│ [Hero Image]                │
│ ┌─────┐                     │
│ │KLAS │                     │
│ └─────┘                     │
│ Course Title                │
│ ⏱ Duration  •  👥 Max cap   │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Beschrijving / Wat leer je  │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Geplande sessies            │
│ ┌─────────────────────────┐ │
│ │ Dates                   │ │
│ │ 📍 Location             │ │
│ │ ⚠ Spots warning         │ │
│ │ €Price  [Inschrijven →] │ │
│ └─────────────────────────┘ │
│                             │
│ Geen passende datum?        │
│ [Interesse melden]          │
└─────────────────────────────┘
```

---

### Edition Detail

```
┌─────────────────────────────┐
│ ← Terug naar cursus         │
│ Course Title                │
│ ┌──────────────────┐        │
│ │ Date range       │        │
│ └──────────────────┘        │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Sessies                     │
│ ┌─────────────────────────┐ │
│ │ DAY Date                │ │
│ │ Time range              │ │
│ │ 📍 Location + address   │ │
│ └─────────────────────────┘ │
│ (repeat for each session)   │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Praktische info             │
│ 👥 Capacity                 │
│ 🎯 Certificate              │
│ ☕ Included                 │
└─────────────────────────────┘

┌─────────────────────────────┐  ← Sticky
│ €Price                      │
│ [    Schrijf je in    ]     │
│ [Interesse melden]          │
└─────────────────────────────┘
```

---

### Trajectory Detail

```
┌─────────────────────────────┐
│ [Hero illustration]         │
│ Trajectory Title            │
│ Subtitle                    │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Dit traject bevat           │
│                             │
│ ●───────○───────○───────○   │  ← Visual path
│                             │
│ [Swipeable course cards]    │
│ ┌─────────────────────────┐ │
│ │ 1. Course Name          │ │
│ │ Type • Duration         │ │
│ └─────────────────────────┘ │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Prijsoverzicht              │
│ Losse cursussen:    €X      │
│ Trajectprijs:       €Y      │
│ Je bespaart:        €Z      │
└─────────────────────────────┘

┌─────────────────────────────┐  ← Sticky
│ €Price                      │
│ [  Start dit traject  ]     │
└─────────────────────────────┘
```

---

### Dashboard Overview

```
MOBILE BOTTOM NAV:
│ 🏠    📚    📅    📄    👤  │
│ Home  Cours Kalen Offer Prof│

CONTENT:
┌─────────────────────────────┐
│ Hallo, Voornaam 👋          │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Volgende sessie             │
│ ┌─────────────────────────┐ │
│ │ 📅 Date/time            │ │
│ │ Course name             │ │
│ │ 📍 Location             │ │
│ │ [Details]  [📅 iCal]    │ │
│ └─────────────────────────┘ │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Jouw voortgang              │
│ ┌────┐ ┌────┐ ┌────┐ ┌────┐│
│ │ 3  │ │ 1  │ │ 12 │ │ 2  ││
│ │act │ │done│ │uur │ │cert││
│ └────┘ └────┘ └────┘ └────┘│
└─────────────────────────────┘

┌─────────────────────────────┐
│ Ga verder met leren         │
│ ┌─────────────────────────┐ │
│ │ Course                  │ │
│ │ ████████░░░░░ 45%       │ │
│ │ [    Verder leren    ]  │ │
│ └─────────────────────────┘ │
└─────────────────────────────┘
```

---

### My Courses

```
┌─────────────────────────────┐
│ Mijn cursussen              │
│ [Actief] [Voltooid] [Alle]  │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ┌─────────────────────────┐ │
│ │ [IMG] Course Title      │ │
│ │       Type              │ │
│ │       ████████░░ 65%    │ │
│ │       [Verder leren]    │ │
│ └─────────────────────────┘ │
│                             │
│ ┌─────────────────────────┐ │
│ │ [IMG] Course Title      │ │
│ │       Klassikaal        │ │
│ │       📅 Dates          │ │
│ │       📍 Location       │ │
│ │       Sessie 1/3        │ │
│ │       [Bekijk sessies]  │ │
│ └─────────────────────────┘ │
│                             │
│ ┌─────────────────────────┐ │
│ │ [ ✓ ] Course Title      │ │
│ │       Voltooid Date     │ │
│ │       [📜 Certificaat]  │ │
│ └─────────────────────────┘ │
└─────────────────────────────┘
```

---

### My Calendar

```
MOBILE (List view):
┌─────────────────────────────┐
│ Mijn agenda                 │
│ [Lijst ●] [Kalender ○]      │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Maart 2026                  │
│ ┌─────────────────────────┐ │
│ │ MA 3 │ Course name      │ │
│ │      │ Time • Location  │ │
│ └─────────────────────────┘ │
│ ┌─────────────────────────┐ │
│ │ DI 4 │ Course name      │ │
│ │      │ Time • Location  │ │
│ └─────────────────────────┘ │
└─────────────────────────────┘

┌─────────────────────────────┐
│ [📅 Exporteer naar agenda]  │
└─────────────────────────────┘

DESKTOP: Month calendar grid with session dots
```

---

### My Profile

```
┌─────────────────────────────┐
│ Mijn profiel                │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ▼ Persoonlijke gegevens     │
│   Form fields...            │
│   [Opslaan]                 │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ▶ Bedrijfsgegevens          │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ▶ Facturatiegegevens        │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ▶ Wachtwoord wijzigen       │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ▶ Voorkeuren                │
└─────────────────────────────┘
```

---

### My Quotes

```
┌─────────────────────────────┐
│ Mijn offertes               │
│ [Alle] [Open] [Betaald]     │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ┌─────────────────────────┐ │
│ │ OFF-2026-0042           │ │
│ │ Date                    │ │
│ │ Course/Edition          │ │
│ │ €Amount  [STATUS]       │ │
│ │ [Bekijk] [Download PDF] │ │
│ └─────────────────────────┘ │
└─────────────────────────────┘
```

---

### My Trajectories

```
┌─────────────────────────────┐
│ Mijn trajecten              │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ┌─────────────────────────┐ │
│ │ Trajectory Title        │ │
│ │ ●━━━●━━━○━━━○           │ │
│ │ 2/4 cursussen voltooid  │ │
│ │ Volgende: Course name   │ │
│ │ [Ga verder]             │ │
│ └─────────────────────────┘ │
│                             │
│ ┌─────────────────────────┐ │
│ │ Trajectory Title        │ │
│ │ ●━━━●━━━●━━━●           │ │
│ │ ✓ Voltooid              │ │
│ │ [📜 Certificaat]        │ │
│ └─────────────────────────┘ │
└─────────────────────────────┘
```

---

### Enrollment Form

```
┌─────────────────────────────┐
│ ← Terug                     │
│ Inschrijven                 │
│ Course/Trajectory Title     │
│ 📅 Dates • 📍 Location      │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Voor wie schrijf je in?     │
│ ○ Mezelf                    │
│ ○ Iemand anders             │
│ ○ Meerdere deelnemers       │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Deelnemer                   │
│ [Form fields]               │
│ [+ Nog een toevoegen]       │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Facturatie                  │
│ ○ Particulier  ● Bedrijf    │
│ [Form fields]               │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Voucher                     │
│ [Code] [Toepassen]          │
│ ✓ Applied discount          │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Overzicht                   │
│ Item              €Amount   │
│ Voucher          -€Discount │
│ Totaal excl BTW   €Subtotal │
│ BTW 21%           €VAT      │
│ Totaal            €Total    │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ☑ Algemene voorwaarden      │
│ ☑ Nieuwsbrief               │
└─────────────────────────────┘

┌─────────────────────────────┐  ← Sticky
│ [  Inschrijving afronden  ] │
└─────────────────────────────┘
```

**Form flexibility:**
- Each course/edition can specify `enrollment_form_id` meta
- Default forms as fallback
- FluentForms integration

---

### Interest Form

```
┌─────────────────────────────┐
│ ← Terug                     │
│ Interesse melden            │
│ Course Title                │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Waar ben je geïnteresseerd? │
│ ☑ Nieuwe data               │
│ ☐ Incompany training        │
│ ☐ Meer informatie           │
│ ☐ Teruggebeld worden        │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Jouw gegevens               │
│ [Form fields]               │
└─────────────────────────────┘

┌─────────────────────────────┐
│ Opmerkingen                 │
│ [Textarea]                  │
└─────────────────────────────┘

┌─────────────────────────────┐
│ ☑ Op de hoogte blijven      │
└─────────────────────────────┘

┌─────────────────────────────┐
│ [      Verstuur      ]      │
└─────────────────────────────┘
```

---

### Homepage Sections

1. **Hero:** Tagline, subtitle, 2 CTAs, trust badges
2. **Course Types:** E-learning vs Klassikaal cards
3. **Featured Courses:** 3-card horizontal scroll (mobile)
4. **Trajectories:** Featured trajectory with visual path
5. **Upcoming Sessions:** List of next 3 in-person sessions
6. **Why Choose Us:** 6 benefit cards (3x2 grid)
7. **Testimonials:** Swipeable quotes
8. **CTA:** Final conversion section
9. **Footer:** Links, contact, social

---

## Technical Notes

### Kadence Integration
- Use Kadence blocks for static content
- Custom templates only where dynamic data needed
- Kadence global styles for typography, colors, spacing

### Mobile Navigation
- Dashboard: Bottom bar with 5 icons (Home, Courses, Calendar, Quotes, Profile)
- Public: Standard header menu
- Sticky CTAs on detail pages and forms

### Data Integration
- EditionService for scheduled offerings
- SessionService for meeting days
- RegistrationRepository for enrollments
- QuoteService for invoicing
- FluentForms for flexible enrollment forms

### CSS Variables (from learndash.css)
```css
--str-primary: #6366f1;
--str-primary-hover: #4f46e5;
--str-success: #22c55e;
--str-text: #1e293b;
--str-text-muted: #64748b;
--str-border: #e2e8f0;
--str-background: #f8fafc;
--str-radius: 12px;
```

---

## Next Steps

1. Create implementation plan with writing-plans skill
2. Build in phases:
   - Phase 1: Theme structure, navigation, base templates
   - Phase 2: Public archives and detail pages
   - Phase 3: User dashboard
   - Phase 4: Forms and enrollment flow
   - Phase 5: Homepage
