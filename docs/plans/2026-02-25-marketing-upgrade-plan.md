# Stride Marketing Website Upgrade Plan

**Date:** 2026-02-25
**Based on:** Research analysis of TrainerCentral & TalentLMS
**Goal:** Transform Stride marketing from functional placeholder to conversion-focused, visually compelling site

---

## Vision Statement

> **From:** Clean but generic B2B landing page
> **To:** Confident, warm, Flemish-rooted training platform that feels both trustworthy and approachable

**Design Direction:** Blend TrainerCentral's warmth and energy with TalentLMS's trust and clarity. Own the "local, practical, no-nonsense" position that neither competitor can claim.

---

## Phase 1: Foundation & Trust (Week 1-2)

### 1.1 Visual Identity Refresh

**Typography Scale Expansion**
```css
/* Add to tailwind.config.js */
fontSize: {
  '7xl': ['4.5rem', { lineHeight: '1', letterSpacing: '-0.03em' }],
  '8xl': ['5.5rem', { lineHeight: '0.95', letterSpacing: '-0.03em' }],
}
```

**Color Palette Enhancement**
```javascript
colors: {
  navy: {
    DEFAULT: '#1a2744',
    light: '#243352',
    dark: '#131d33',
  },
  copper: {
    DEFAULT: '#c47d5d',
    light: '#d4967a',
    dark: '#a86544',
  },
  cream: '#faf8f5',
  surface: '#ffffff',
  // NEW
  success: {
    DEFAULT: '#22c55e',
    light: '#86efac',
  },
  accent: {
    DEFAULT: '#3b82f6',  // Links, secondary
    light: '#93c5fd',
  },
}
```

**Gradient Definitions**
```css
.bg-gradient-hero {
  background: linear-gradient(135deg, #1a2744 0%, #243352 50%, #c47d5d 100%);
}
.bg-gradient-cta {
  background: linear-gradient(90deg, #1a2744 0%, #131d33 100%);
}
```

### 1.2 Social Proof Bar

Add immediately after hero:

```html
<!-- Trust bar - after hero -->
<section class="py-8 border-b border-navy/5">
  <div class="container">
    <p class="text-center text-sm text-text-muted mb-6">Vertrouwd door opleidingsteams in heel Vlaanderen</p>
    <div class="flex flex-wrap justify-center items-center gap-8 lg:gap-12 opacity-60 grayscale hover:opacity-100 hover:grayscale-0 transition-all">
      <!-- Customer logos here -->
      <img src="images/logos/vad.svg" alt="VAD" class="h-8">
      <img src="images/logos/customer-2.svg" alt="Customer 2" class="h-8">
      <img src="images/logos/customer-3.svg" alt="Customer 3" class="h-8">
      <!-- etc -->
    </div>
  </div>
</section>
```

### 1.3 Testimonial Enhancement

**Current:** Single static quote
**Upgrade to:** Testimonial section with multiple quotes

```html
<section class="section bg-navy text-text-inverse">
  <div class="container">
    <div x-data="{ current: 0, testimonials: [...] }" class="max-w-4xl mx-auto">
      <!-- Testimonial carousel -->
      <template x-for="(t, i) in testimonials">
        <blockquote x-show="current === i" x-transition class="text-center">
          <p class="text-xl lg:text-2xl font-medium mb-8">
            "<span x-text="t.quote"></span>"
          </p>
          <footer class="flex items-center justify-center gap-4">
            <img :src="t.photo" :alt="t.name" class="w-14 h-14 rounded-full">
            <div class="text-left">
              <cite class="not-italic font-medium" x-text="t.name"></cite>
              <p class="text-sm text-text-inverse/70" x-text="t.role"></p>
            </div>
          </footer>
        </blockquote>
      </template>
      <!-- Dots -->
      <div class="flex justify-center gap-2 mt-8">
        <template x-for="(t, i) in testimonials">
          <button @click="current = i"
                  :class="current === i ? 'bg-copper' : 'bg-white/30'"
                  class="w-2 h-2 rounded-full transition-colors"></button>
        </template>
      </div>
    </div>
  </div>
</section>
```

### 1.4 Metric Highlights

Add stat cards to build credibility:

```html
<section class="py-12 bg-surface">
  <div class="container">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-6">
      <div class="text-center">
        <div class="text-4xl lg:text-5xl font-bold text-navy">200+</div>
        <div class="text-text-muted mt-1">Actieve gebruikers bij VAD</div>
      </div>
      <div class="text-center">
        <div class="text-4xl lg:text-5xl font-bold text-navy">5000+</div>
        <div class="text-text-muted mt-1">Inschrijvingen verwerkt</div>
      </div>
      <div class="text-center">
        <div class="text-4xl lg:text-5xl font-bold text-navy">98%</div>
        <div class="text-text-muted mt-1">Minder Excel-werk</div>
      </div>
      <div class="text-center">
        <div class="text-4xl lg:text-5xl font-bold text-navy">< 5 min</div>
        <div class="text-text-muted mt-1">Nieuwe opleiding aanmaken</div>
      </div>
    </div>
  </div>
</section>
```

---

## Phase 2: Hero & First Impression (Week 2-3)

### 2.1 Hero Visual Upgrade

**Current:** Placeholder mockup
**Target:** Polished dashboard visual with floating elements

```html
<section class="pt-32 lg:pt-40 pb-20 lg:pb-28 overflow-hidden">
  <div class="container">
    <div class="grid lg:grid-cols-2 gap-12 items-center">
      <!-- Left: Copy -->
      <div>
        <div class="inline-flex items-center gap-2 px-4 py-2 bg-copper/10 rounded-full text-copper text-sm font-medium mb-6 reveal">
          <span class="w-2 h-2 bg-copper rounded-full animate-pulse"></span>
          Nieuw: Partner API voor organisaties
        </div>

        <h1 class="text-4xl sm:text-5xl lg:text-6xl xl:text-7xl font-bold text-navy text-balance reveal">
          Opleidingsbeheer<br>
          dat <span class="text-copper">écht</span> werkt.
        </h1>

        <p class="mt-6 text-lg lg:text-xl text-text-muted max-w-xl reveal delay-100">
          Van cursusplanning tot certificaten — het enige platform voor in-person,
          online én hybride trainingen. Klaar binnen een week.
        </p>

        <div class="mt-8 flex flex-wrap gap-4 reveal delay-200">
          <a href="#demo" class="btn-primary text-lg px-8 py-4">
            Plan een demo
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
            </svg>
          </a>
          <a href="features.html" class="btn-secondary text-lg px-8 py-4">
            Bekijk functies
          </a>
        </div>

        <!-- Speed promise -->
        <p class="mt-6 text-sm text-text-muted reveal delay-300">
          <svg class="w-4 h-4 inline mr-1 text-success" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>
          </svg>
          Operationeel binnen 1 week — niet maanden
        </p>
      </div>

      <!-- Right: Visual -->
      <div class="relative reveal delay-300">
        <!-- Main dashboard mockup -->
        <div class="bg-surface rounded-2xl shadow-hover border border-navy/10 overflow-hidden">
          <div class="bg-navy/5 px-4 py-3 flex items-center gap-2">
            <div class="flex gap-1.5">
              <div class="w-3 h-3 rounded-full bg-red-400/60"></div>
              <div class="w-3 h-3 rounded-full bg-yellow-400/60"></div>
              <div class="w-3 h-3 rounded-full bg-green-400/60"></div>
            </div>
          </div>
          <img src="images/hero/dashboard-screenshot.png" alt="Stride Dashboard" class="w-full">
        </div>

        <!-- Floating cards -->
        <div class="absolute -top-4 -right-4 bg-surface rounded-lg shadow-card p-4 border border-navy/10 animate-float">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-success/10 flex items-center justify-center">
              <svg class="w-5 h-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
              </svg>
            </div>
            <div>
              <p class="text-sm font-medium text-navy">Certificaat behaald</p>
              <p class="text-xs text-text-muted">Jan De Vries - EHBO Basis</p>
            </div>
          </div>
        </div>

        <div class="absolute -bottom-6 -left-6 bg-surface rounded-lg shadow-card p-4 border border-navy/10 animate-float-delayed">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-copper/10 flex items-center justify-center">
              <svg class="w-5 h-5 text-copper" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>
              </svg>
            </div>
            <div>
              <p class="text-sm font-medium text-navy">Nieuwe inschrijving</p>
              <p class="text-xs text-text-muted">Marie Peeters - Leidinggeven</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
```

### 2.2 Float Animation CSS

```css
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-10px); }
}
.animate-float {
  animation: float 4s ease-in-out infinite;
}
.animate-float-delayed {
  animation: float 4s ease-in-out infinite 2s;
}
```

---

## Phase 3: Feature Presentation (Week 3-4)

### 3.1 "Why Stride" Section

Add between hero and features:

```html
<section class="section">
  <div class="container">
    <div class="text-center max-w-2xl mx-auto mb-16">
      <h2 class="text-3xl lg:text-4xl font-bold text-navy reveal">
        Waarom Stride?
      </h2>
      <p class="mt-4 text-lg text-text-muted reveal delay-100">
        Gebouwd door trainingsexperts, niet door techneuten.
      </p>
    </div>

    <div class="grid md:grid-cols-3 gap-8">
      <!-- Simplicity -->
      <div class="text-center reveal delay-100">
        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-copper/20 to-copper/5 flex items-center justify-center mx-auto mb-6">
          <svg class="w-8 h-8 text-copper" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
          </svg>
        </div>
        <h3 class="font-heading font-bold text-xl text-navy mb-3">Snel opstarten</h3>
        <p class="text-text-muted">Geen maandenlange implementatie. Importeer je data en start binnen een week.</p>
      </div>

      <!-- Hybrid -->
      <div class="text-center reveal delay-200">
        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-navy/20 to-navy/5 flex items-center justify-center mx-auto mb-6">
          <svg class="w-8 h-8 text-navy" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/>
          </svg>
        </div>
        <h3 class="font-heading font-bold text-xl text-navy mb-3">Hybride training</h3>
        <p class="text-text-muted">Klassikaal, online of gecombineerd. Alle vormen in één systeem.</p>
      </div>

      <!-- Local -->
      <div class="text-center reveal delay-300">
        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-copper/20 to-copper/5 flex items-center justify-center mx-auto mb-6">
          <svg class="w-8 h-8 text-copper" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/>
          </svg>
        </div>
        <h3 class="font-heading font-bold text-xl text-navy mb-3">Vlaams & praktisch</h3>
        <p class="text-text-muted">Gebouwd in België, voor Belgische organisaties. Nederlandse interface, lokale support.</p>
      </div>
    </div>
  </div>
</section>
```

### 3.2 Feature Sections with Real Screenshots

Each feature section needs:
1. Real screenshot (not placeholder)
2. Benefit-focused headline
3. 3 bullet points with checkmarks
4. Optional "Learn more" link

---

## Phase 4: Audience Segmentation (Week 4-5)

### 4.1 "Who Uses Stride" Section

```html
<section class="section bg-surface">
  <div class="container">
    <div class="text-center max-w-2xl mx-auto mb-12">
      <h2 class="text-3xl lg:text-4xl font-bold text-navy reveal">
        Voor iedereen in je organisatie
      </h2>
    </div>

    <div class="grid lg:grid-cols-3 gap-6" x-data="{ active: 0 }">
      <!-- Tab buttons -->
      <div class="lg:col-span-3 flex justify-center gap-4 mb-8">
        <button @click="active = 0" :class="active === 0 ? 'bg-navy text-white' : 'bg-navy/5'" class="px-6 py-3 rounded-full font-medium transition-colors">
          Trainingscoördinator
        </button>
        <button @click="active = 1" :class="active === 1 ? 'bg-navy text-white' : 'bg-navy/5'" class="px-6 py-3 rounded-full font-medium transition-colors">
          Manager
        </button>
        <button @click="active = 2" :class="active === 2 ? 'bg-navy text-white' : 'bg-navy/5'" class="px-6 py-3 rounded-full font-medium transition-colors">
          Medewerker
        </button>
      </div>

      <!-- Content panels -->
      <div class="lg:col-span-3 grid lg:grid-cols-2 gap-8 items-center">
        <!-- Coordinator -->
        <template x-if="active === 0">
          <div class="space-y-6">
            <h3 class="text-2xl font-bold text-navy">Eindelijk overzicht over alle opleidingen</h3>
            <ul class="space-y-3">
              <li class="flex gap-3"><svg>...</svg> Beheer alle edities vanuit één dashboard</li>
              <li class="flex gap-3"><svg>...</svg> Automatische aanwezigheidslijsten</li>
              <li class="flex gap-3"><svg>...</svg> Certificaten met één klik</li>
            </ul>
          </div>
        </template>
        <!-- etc for other tabs -->

        <div class="bg-cream rounded-xl p-4">
          <img x-show="active === 0" src="images/personas/coordinator-view.png" alt="Coördinator dashboard">
          <img x-show="active === 1" src="images/personas/manager-view.png" alt="Manager dashboard">
          <img x-show="active === 2" src="images/personas/employee-view.png" alt="Medewerker dashboard">
        </div>
      </div>
    </div>
  </div>
</section>
```

---

## Phase 5: New Pages (Week 5-6)

### 5.1 Pricing Page

Even with custom pricing, provide transparency:

```
/pricing.html
├── Hero: "Transparante prijzen"
├── Approach explanation: "We maken een voorstel op maat"
├── What's included in all plans (feature list)
├── Example packages:
│   ├── "Klein team" (< 50 users): vanaf €X/maand
│   ├── "Groeiend" (50-200 users): vanaf €Y/maand
│   └── "Enterprise": op aanvraag
├── FAQ: "Wat kost het écht?"
├── ROI calculator (optional)
└── Demo CTA
```

### 5.2 About Page

Build trust with human element:

```
/about.html
├── Hero: "Gebouwd door trainingsexperts"
├── Origin story: VAD journey, problems solved
├── Team: Photos, names, roles (even 2-3 people)
├── Values: What we believe about training
├── Timeline: Key milestones
├── Partners/integrations
└── Contact CTA
```

### 5.3 Use Cases Page

```
/use-cases.html
├── Hero: "Stride voor jouw situatie"
├── Use case cards:
│   ├── Zorgorganisaties
│   ├── Overheid & non-profit
│   ├── Opleidingscentra
│   └── Bedrijven met verplichte trainingen
└── Case study teasers
```

---

## Phase 6: Visual Assets (Ongoing)

### Required Assets

| Asset | Description | Priority |
|-------|-------------|----------|
| `hero/dashboard-screenshot.png` | Main dashboard view, polished | P0 |
| `hero/floating-notification-1.png` | Certificate notification | P0 |
| `hero/floating-notification-2.png` | Enrollment notification | P0 |
| `features/sessions.png` | Session management view | P1 |
| `features/trajectories.png` | Trajectory/learning path view | P1 |
| `features/attendance.png` | Attendance tracking view | P1 |
| `features/certificates.png` | Certificate management | P1 |
| `features/reports.png` | Reporting dashboard | P1 |
| `logos/vad.svg` | VAD logo (with permission) | P0 |
| `logos/customer-*.svg` | 4-6 customer logos | P1 |
| `testimonials/person-1.jpg` | Testimonial headshot | P1 |
| `personas/coordinator-view.png` | Coordinator dashboard | P2 |
| `personas/manager-view.png` | Manager view | P2 |
| `personas/employee-view.png` | Employee view | P2 |

### Screenshot Guidelines

- Use real Stride UI (staging/demo environment)
- Consistent browser chrome (Safari preferred)
- Remove any sensitive/test data
- 2x resolution for retina
- Consistent shadow/border treatment

---

## Phase 7: Messaging Refinements

### Homepage Copy Updates

**Current Hero:**
> Opleidingsbeheer dat écht werkt.

**Updated Hero:**
> Opleidingsbeheer dat écht werkt.
> *Van chaos naar controle — binnen één week.*

**Current Subhead:**
> Van cursusplanning tot certificaten — het enige platform voor in-person, online én hybride trainingen. Zonder de enterprise-prijskaart.

**Updated Subhead:**
> Van cursusplanning tot certificaten — alles wat je nodig hebt voor klassikale, online én hybride trainingen. Klaar in dagen, niet maanden.

### New Section Headlines

| Section | Current | Proposed |
|---------|---------|----------|
| Problems | "Herkenbaar?" | "Klinkt dit bekend?" |
| Features | "Wat Stride voor je regelt" | "Alles wat je nodig hebt" |
| Testimonial | (none) | "Wat onze klanten zeggen" |
| Pricing | "Prijzen op maat" | "Transparante prijzen, zonder verrassingen" |
| CTA | "Klaar om te starten?" | "Zie het zelf" |

### Speed/Ease Messaging (Add Throughout)

- "Operationeel binnen 1 week"
- "Nieuwe opleiding aanmaken in 5 minuten"
- "Geen IT-afdeling nodig"
- "Import je bestaande data met één klik"

---

## Implementation Priority

### Must-Have (Before Launch)

- [ ] Real dashboard screenshot for hero
- [ ] Customer logo bar (minimum 4 logos)
- [ ] At least 2 testimonials with photos
- [ ] Stats section with real numbers
- [ ] Speed/ease messaging throughout
- [ ] Updated color palette & typography

### Should-Have (Month 1)

- [ ] Feature screenshots for all sections
- [ ] Pricing page with transparency
- [ ] About page with team
- [ ] Testimonial carousel
- [ ] Floating hero elements

### Nice-to-Have (Month 2-3)

- [ ] Use cases page
- [ ] Case studies (2-3 detailed)
- [ ] Persona-based content tabs
- [ ] Video demo integration
- [ ] ROI calculator
- [ ] Resource/blog section

---

## Success Metrics

| Metric | Current | Target |
|--------|---------|--------|
| Time on page | Unknown | > 2 min |
| Bounce rate | Unknown | < 50% |
| Demo form submissions | Unknown | +50% vs baseline |
| Feature page visits | Unknown | > 30% of visitors |
| Mobile experience | Basic | Fully polished |

---

## Next Steps

1. **Review this plan** — prioritize and adjust scope
2. **Gather visual assets** — screenshots from staging
3. **Get customer permission** — logos, testimonials, photos
4. **Phase 1 implementation** — trust foundation
5. **Design review** — iterate based on feedback
