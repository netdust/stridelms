# Stride Marketing Site Design

> **Type:** Static HTML marketing site
> **Target:** Healthcare organizations (HR/training coordinators)
> **Goal:** Demo/consultation requests
> **Pages:** Landing page + Features page

---

## Context

Stride is an LMS built for VAD (Vlaamse Alcoholvoorziening) that manages in-person, online, and hybrid training. The platform is now being offered to other healthcare organizations.

**Key differentiators:**
- In-person training management (rare — most LMS are e-learning only)
- Session scheduling, attendance tracking, certificates
- SCORM compliance for e-learning content
- Affordable vs €30K+ enterprise alternatives
- Built and proven at VAD

---

## Design Direction

**"Professional Trust with Human Warmth"**

| Element | Direction |
|---------|-----------|
| Primary color | Deep teal or blue (trust, healthcare) |
| Accent | Warm amber or soft green (growth, approachability) |
| Typography | Clean sans-serif (Inter or similar), generous whitespace |
| Imagery | Real training photos where possible, minimal illustrations |
| Tone | Direct, confident, slightly warm — Dutch professional |

**Anti-patterns to avoid:**
- Startup fluff ("revolutionize", "empower", "seamless")
- Generic stock photos of people pointing at screens
- Feature overload — focus on core value props
- Hidden pricing games — be upfront that quotes are custom

---

## Page Structure

### Landing Page (`index.html`)

#### 1. Header
- Logo (left)
- Nav: Functies, Prijzen (anchor to CTA), Demo button
- Sticky on scroll

#### 2. Hero Section
```
Opleidingsbeheer
dat écht werkt.

Van cursusplanning tot certificaten —
het enige platform voor in-person, online én hybride
trainingen. Zonder de enterprise-prijskaart.

[Plan een demo]  [Bekijk functies →]

[Dashboard screenshot/mockup]

"Gebouwd voor VAD. Beschikbaar voor jou."
```

#### 3. Problem Section
Three pain points in cards:
- Excel-planning chaos
- Handmatige aanwezigheidslijsten
- Geen zicht op voortgang

Transition: "Eén platform. Alles geregeld."

#### 4. Features Grid (4 cards)
| Feature | Description |
|---------|-------------|
| Sessiebeheer | Plan meerdaagse opleidingen met sessies, locaties, capaciteit |
| Voortgang | Realtime zicht op aanwezigheid en certificaten |
| SCORM & Online | Importeer e-learning of maak hybride trajecten |
| Facturatie | Automatische offertes en export naar boekhouding |

#### 5. Social Proof
- VAD testimonial quote
- VAD logo
- Optional: "X medewerkers opgeleid" stat

#### 6. CTA Section
```
Klaar om je opleidingsbeheer te vereenvoudigen?

Plan een vrijblijvend gesprek. We tonen je het platform
en bespreken jouw situatie.

[Plan een demo]

Of mail direct: info@stride.be
```

#### 7. Footer
- Logo
- Links: Functies, Contact, Privacy
- Copyright

---

### Features Page (`features.html`)

#### 1. Header (same as landing)

#### 2. Features Hero
```
Alles wat je nodig hebt.
Niets dat je niet nodig hebt.
```

#### 3. Feature Sections (alternating left/right layout)

**Sessiebeheer**
- Multi-day training planning
- Session dates, times, locations
- Capacity management
- Waitlists
- Screenshot: edition/session admin

**Trajecten**
- Multi-year learning paths
- Required + elective courses
- Cohort management
- Deadline tracking
- Screenshot: trajectory dashboard

**Online & SCORM**
- SCORM package import
- LearnDash integration
- Hybrid courses (online + in-person)
- Progress tracking
- Screenshot: course content

**Facturatie & Offertes**
- Automatic quote generation
- Voucher/discount codes
- Exact Online export
- VAT handling
- Screenshot: quote PDF or admin

**Dashboard & Rapportage**
- User progress overview
- Certificate management
- Attendance reports
- Organization-level views
- Screenshot: dashboard

#### 4. Integrations Section
Logos/icons for:
- LearnDash
- SCORM
- FluentCRM
- Exact Online
- WordPress

#### 5. Pricing Approach
```
Prijzen op maat

Elke organisatie is anders. We maken een voorstel
op basis van je aantal medewerkers en wensen.

[Vraag een offerte aan]
```

#### 6. CTA Section (same as landing)

#### 7. Footer (same as landing)

---

## Technical Approach

**Static HTML + Tailwind CSS**

- No WordPress dependency for marketing pages
- Single HTML file per page (or minimal templating)
- Tailwind for styling (can share config with Stridence theme)
- Alpine.js for mobile menu toggle only
- Host on same domain or separate marketing domain

**File structure:**
```
marketing/
├── index.html
├── features.html
├── dist/
│   ├── styles.css      (Tailwind build)
│   └── main.js         (Alpine + minimal JS)
├── images/
│   ├── logo.svg
│   ├── screenshots/
│   └── hero-image.webp
└── src/
    ├── styles.css      (Tailwind source)
    └── main.js
```

**Build:**
- Tailwind CLI or Vite for CSS build
- Can be simple `npx tailwindcss` script

---

## Copy Guidelines

**Voice:** Direct, confident, slightly warm
**Language:** Dutch (nl_BE)
**Avoid:** Jargon, superlatives, passive voice

**Example transformations:**
| Don't say | Say instead |
|-----------|-------------|
| "Revolutionize your training" | "Opleidingsbeheer dat écht werkt" |
| "Empower your team" | "Geef je team toegang tot hun opleidingen" |
| "Seamless integration" | "Werkt met LearnDash en SCORM" |
| "Best-in-class" | (just show the feature) |

---

## Demo Request Flow

Primary CTA leads to:
1. Simple form: Name, Organization, Email, Phone (optional), Message (optional)
2. Or: Calendly embed for direct scheduling
3. Confirmation page/message

Decision needed: Calendly integration vs simple form?

---

## Open Questions

1. **Screenshots:** Need actual dashboard/admin screenshots. Placeholder approach?
2. **VAD testimonial:** Exact quote and permission to use?
3. **Domain:** Same domain (stride.be/marketing) or separate (getstride.be)?
4. **Demo scheduling:** Calendly or form submission?

---

## Success Metrics

- Demo requests per month
- Time on page
- Scroll depth (are people reading features?)
- Form abandonment rate
