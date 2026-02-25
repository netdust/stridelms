# Stride Marketing Site Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a static HTML marketing site (landing + features pages) that converts healthcare organizations into demo requests.

**Architecture:** Static HTML with Tailwind CSS, Alpine.js for interactivity. No build step required for HTML — just Tailwind CLI for CSS. Hosted in `/marketing` directory at project root.

**Tech Stack:** HTML5, Tailwind CSS 3.4, Alpine.js 3.x, Google Fonts (Satoshi + DM Sans)

---

## Aesthetic Direction: "Confident Dutch Professional"

This is NOT generic startup design. This is institutional confidence with human warmth.

| Element | Choice | Why |
|---------|--------|-----|
| Primary | Deep navy `#1a2744` | Institutional trust, not trendy teal |
| Accent | Warm copper `#c47d5d` | Human warmth, recalls terracotta/brick |
| Background | Cream `#faf8f5` | Warm paper feel, not clinical white |
| Surface | White `#ffffff` | Cards, contrast areas |
| Text | Slate `#334155` | Readable, not harsh black |
| Muted | `#64748b` | Secondary text |
| Headings | Satoshi (700) | Geometric sans with character |
| Body | DM Sans (400, 500) | Clean, highly readable |
| Corners | 8px (cards), 6px (buttons) | Confident, not pill-shaped |
| Shadows | Soft, warm-tinted | `0 4px 24px rgba(26,39,68,0.08)` |
| Grain | Subtle SVG noise overlay | Depth, tactile quality |

**Motion principles:**
- Scroll-triggered reveals (opacity + translateY)
- 600ms ease-out curves
- Staggered delays for groups (100ms increments)
- No bouncing, no elastic — just confident movement

---

## Task 1: Project Setup

**Files:**
- Create: `marketing/package.json`
- Create: `marketing/tailwind.config.js`
- Create: `marketing/src/styles.css`
- Create: `marketing/.gitignore`

**Step 1: Create directory structure**

```bash
mkdir -p marketing/{src,dist,images/screenshots}
cd marketing
```

**Step 2: Initialize package.json**

Create `marketing/package.json`:

```json
{
  "name": "stride-marketing",
  "version": "1.0.0",
  "scripts": {
    "dev": "npx tailwindcss -i ./src/styles.css -o ./dist/styles.css --watch",
    "build": "npx tailwindcss -i ./src/styles.css -o ./dist/styles.css --minify"
  },
  "devDependencies": {
    "tailwindcss": "^3.4.0"
  }
}
```

**Step 3: Create Tailwind config**

Create `marketing/tailwind.config.js`:

```js
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./**/*.html"],
  theme: {
    extend: {
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
        text: {
          DEFAULT: '#334155',
          muted: '#64748b',
          inverse: '#ffffff',
        },
      },
      fontFamily: {
        heading: ['Satoshi', 'system-ui', 'sans-serif'],
        body: ['DM Sans', 'system-ui', 'sans-serif'],
      },
      fontSize: {
        '5xl': ['3rem', { lineHeight: '1.1', letterSpacing: '-0.02em' }],
        '6xl': ['3.75rem', { lineHeight: '1.05', letterSpacing: '-0.02em' }],
        '7xl': ['4.5rem', { lineHeight: '1', letterSpacing: '-0.03em' }],
      },
      borderRadius: {
        DEFAULT: '8px',
        lg: '12px',
        xl: '16px',
      },
      boxShadow: {
        card: '0 4px 24px rgba(26,39,68,0.08)',
        hover: '0 8px 32px rgba(26,39,68,0.12)',
        button: '0 2px 8px rgba(26,39,68,0.15)',
      },
      animation: {
        'fade-up': 'fadeUp 0.6s ease-out forwards',
        'fade-in': 'fadeIn 0.6s ease-out forwards',
      },
      keyframes: {
        fadeUp: {
          '0%': { opacity: '0', transform: 'translateY(20px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
      },
    },
  },
  plugins: [],
}
```

**Step 4: Create source CSS**

Create `marketing/src/styles.css`:

```css
@import url('https://api.fontshare.com/v2/css?f[]=satoshi@700,900&display=swap');
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap');

@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  html {
    scroll-behavior: smooth;
  }

  body {
    @apply font-body text-text bg-cream antialiased;
  }

  h1, h2, h3, h4 {
    @apply font-heading;
  }
}

@layer components {
  .btn-primary {
    @apply inline-flex items-center justify-center px-6 py-3
           bg-navy text-text-inverse font-medium rounded
           shadow-button hover:bg-navy-light
           transition-all duration-200
           hover:-translate-y-0.5 hover:shadow-hover;
  }

  .btn-secondary {
    @apply inline-flex items-center justify-center px-6 py-3
           bg-transparent text-navy font-medium rounded
           border-2 border-navy
           transition-all duration-200
           hover:bg-navy hover:text-text-inverse;
  }

  .btn-ghost {
    @apply inline-flex items-center gap-2 text-navy font-medium
           hover:text-copper transition-colors duration-200;
  }

  .card {
    @apply bg-surface rounded-lg shadow-card p-6;
  }

  .section {
    @apply py-20 lg:py-28;
  }

  .container {
    @apply max-w-6xl mx-auto px-5 lg:px-8;
  }

  /* Grain overlay */
  .grain::before {
    content: '';
    @apply fixed inset-0 pointer-events-none z-50 opacity-[0.03];
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
  }

  /* Scroll reveal utility */
  .reveal {
    @apply opacity-0 translate-y-5;
  }

  .reveal.visible {
    @apply opacity-100 translate-y-0 transition-all duration-700 ease-out;
  }
}

@layer utilities {
  .text-balance {
    text-wrap: balance;
  }

  .delay-100 { transition-delay: 100ms; }
  .delay-200 { transition-delay: 200ms; }
  .delay-300 { transition-delay: 300ms; }
  .delay-400 { transition-delay: 400ms; }
}
```

**Step 5: Create .gitignore**

Create `marketing/.gitignore`:

```
node_modules/
```

**Step 6: Install dependencies and build**

```bash
cd marketing
npm install
npm run build
```

**Step 7: Commit**

```bash
git add marketing/
git commit -m "chore(marketing): initialize project with Tailwind config"
```

---

## Task 2: Landing Page HTML Structure

**Files:**
- Create: `marketing/index.html`

**Step 1: Create complete landing page**

Create `marketing/index.html`:

```html
<!DOCTYPE html>
<html lang="nl-BE">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stride — Opleidingsbeheer dat écht werkt</title>
  <meta name="description" content="Het enige platform voor in-person, online én hybride trainingen. Van cursusplanning tot certificaten, zonder de enterprise-prijskaart.">

  <!-- Fonts -->
  <link rel="preconnect" href="https://api.fontshare.com" crossorigin>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <!-- Styles -->
  <link rel="stylesheet" href="dist/styles.css">

  <!-- Alpine.js -->
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="grain">

  <!-- Header -->
  <header class="fixed top-0 inset-x-0 z-40 bg-cream/80 backdrop-blur-md border-b border-navy/5"
          x-data="{ open: false, scrolled: false }"
          @scroll.window="scrolled = window.scrollY > 20"
          :class="{ 'shadow-card': scrolled }">
    <div class="container">
      <nav class="flex items-center justify-between h-16 lg:h-20">
        <!-- Logo -->
        <a href="/" class="flex items-center gap-2">
          <div class="w-8 h-8 bg-navy rounded flex items-center justify-center">
            <span class="text-text-inverse font-heading font-bold text-lg">S</span>
          </div>
          <span class="font-heading font-bold text-xl text-navy">Stride</span>
        </a>

        <!-- Desktop Nav -->
        <div class="hidden lg:flex items-center gap-8">
          <a href="features.html" class="text-text hover:text-navy transition-colors">Functies</a>
          <a href="#pricing" class="text-text hover:text-navy transition-colors">Prijzen</a>
          <a href="#demo" class="btn-primary">Plan een demo</a>
        </div>

        <!-- Mobile Toggle -->
        <button @click="open = !open" class="lg:hidden p-2 -mr-2" aria-label="Menu">
          <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
          </svg>
          <svg x-show="open" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </nav>

      <!-- Mobile Menu -->
      <div x-show="open" x-cloak
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 -translate-y-2"
           x-transition:enter-end="opacity-100 translate-y-0"
           x-transition:leave="transition ease-in duration-150"
           x-transition:leave-start="opacity-100 translate-y-0"
           x-transition:leave-end="opacity-0 -translate-y-2"
           class="lg:hidden py-4 border-t border-navy/5">
        <div class="flex flex-col gap-4">
          <a href="features.html" class="text-text hover:text-navy py-2">Functies</a>
          <a href="#pricing" class="text-text hover:text-navy py-2">Prijzen</a>
          <a href="#demo" class="btn-primary text-center">Plan een demo</a>
        </div>
      </div>
    </div>
  </header>

  <main>
    <!-- Hero Section -->
    <section class="pt-32 lg:pt-40 pb-20 lg:pb-28">
      <div class="container">
        <div class="max-w-3xl">
          <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-navy text-balance reveal">
            Opleidingsbeheer<br>
            dat <span class="text-copper">écht</span> werkt.
          </h1>

          <p class="mt-6 text-lg lg:text-xl text-text-muted max-w-2xl reveal delay-100">
            Van cursusplanning tot certificaten — het enige platform voor in-person,
            online én hybride trainingen. Zonder de enterprise-prijskaart.
          </p>

          <div class="mt-8 flex flex-wrap gap-4 reveal delay-200">
            <a href="#demo" class="btn-primary">
              Plan een demo
              <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/>
              </svg>
            </a>
            <a href="features.html" class="btn-ghost">
              Bekijk functies
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
              </svg>
            </a>
          </div>
        </div>

        <!-- Hero Visual -->
        <div class="mt-16 lg:mt-20 reveal delay-300">
          <div class="relative">
            <!-- Dashboard mockup placeholder -->
            <div class="bg-surface rounded-xl shadow-hover border border-navy/10 overflow-hidden">
              <div class="bg-navy/5 px-4 py-3 flex items-center gap-2">
                <div class="flex gap-1.5">
                  <div class="w-3 h-3 rounded-full bg-copper/40"></div>
                  <div class="w-3 h-3 rounded-full bg-navy/20"></div>
                  <div class="w-3 h-3 rounded-full bg-navy/20"></div>
                </div>
                <div class="flex-1 text-center">
                  <span class="text-xs text-text-muted">stride.app/dashboard</span>
                </div>
              </div>
              <div class="aspect-[16/9] bg-gradient-to-br from-cream to-navy/5 flex items-center justify-center">
                <span class="text-text-muted">Dashboard Screenshot</span>
              </div>
            </div>

            <!-- Floating badge -->
            <div class="absolute -bottom-4 -right-4 lg:-bottom-6 lg:-right-6 bg-surface rounded-lg shadow-card px-4 py-3 border border-navy/10">
              <p class="text-sm font-medium text-navy">Gebouwd voor VAD</p>
              <p class="text-xs text-text-muted">Beschikbaar voor jou</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Problem Section -->
    <section class="section bg-surface">
      <div class="container">
        <div class="text-center max-w-2xl mx-auto mb-12">
          <h2 class="text-2xl lg:text-3xl font-bold text-navy reveal">
            Herkenbaar?
          </h2>
        </div>

        <div class="grid md:grid-cols-3 gap-6 mb-16">
          <div class="card border-l-4 border-copper/50 reveal delay-100">
            <div class="w-10 h-10 rounded bg-copper/10 flex items-center justify-center mb-4">
              <svg class="w-5 h-5 text-copper" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
              </svg>
            </div>
            <h3 class="font-heading font-bold text-navy mb-2">Excel-chaos</h3>
            <p class="text-text-muted text-sm">Eindeloze spreadsheets voor planning, deelnemers en aanwezigheid.</p>
          </div>

          <div class="card border-l-4 border-copper/50 reveal delay-200">
            <div class="w-10 h-10 rounded bg-copper/10 flex items-center justify-center mb-4">
              <svg class="w-5 h-5 text-copper" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
              </svg>
            </div>
            <h3 class="font-heading font-bold text-navy mb-2">Handmatige lijsten</h3>
            <p class="text-text-muted text-sm">Papieren aanwezigheidslijsten die niemand op tijd invult.</p>
          </div>

          <div class="card border-l-4 border-copper/50 reveal delay-300">
            <div class="w-10 h-10 rounded bg-copper/10 flex items-center justify-center mb-4">
              <svg class="w-5 h-5 text-copper" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
              </svg>
            </div>
            <h3 class="font-heading font-bold text-navy mb-2">Geen overzicht</h3>
            <p class="text-text-muted text-sm">Wie heeft welke opleiding gevolgd? Wanneer vervallen certificaten?</p>
          </div>
        </div>

        <!-- Transition -->
        <div class="text-center reveal">
          <div class="inline-flex items-center gap-4 px-6 py-3 bg-navy rounded-full">
            <span class="text-text-inverse font-medium">Eén platform. Alles geregeld.</span>
            <svg class="w-5 h-5 text-copper" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
            </svg>
          </div>
        </div>
      </div>
    </section>

    <!-- Features Grid -->
    <section class="section" id="features">
      <div class="container">
        <div class="text-center max-w-2xl mx-auto mb-12">
          <h2 class="text-2xl lg:text-3xl font-bold text-navy reveal">
            Wat Stride voor je regelt
          </h2>
        </div>

        <div class="grid md:grid-cols-2 gap-6 lg:gap-8">
          <div class="card group hover:shadow-hover transition-shadow reveal delay-100">
            <div class="flex items-start gap-4">
              <div class="w-12 h-12 rounded-lg bg-navy flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-text-inverse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
              </div>
              <div>
                <h3 class="font-heading font-bold text-lg text-navy mb-2">Sessiebeheer</h3>
                <p class="text-text-muted">Plan meerdaagse opleidingen met sessies, locaties en capaciteit. Automatische wachtlijsten wanneer vol.</p>
              </div>
            </div>
          </div>

          <div class="card group hover:shadow-hover transition-shadow reveal delay-200">
            <div class="flex items-start gap-4">
              <div class="w-12 h-12 rounded-lg bg-navy flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-text-inverse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
              </div>
              <div>
                <h3 class="font-heading font-bold text-lg text-navy mb-2">Voortgang & Certificaten</h3>
                <p class="text-text-muted">Realtime zicht op aanwezigheid, voortgang en certificaten. Automatische herinneringen bij vervaldatum.</p>
              </div>
            </div>
          </div>

          <div class="card group hover:shadow-hover transition-shadow reveal delay-300">
            <div class="flex items-start gap-4">
              <div class="w-12 h-12 rounded-lg bg-navy flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-text-inverse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
              </div>
              <div>
                <h3 class="font-heading font-bold text-lg text-navy mb-2">SCORM & Online</h3>
                <p class="text-text-muted">Importeer bestaande e-learning of combineer online modules met klassikale sessies.</p>
              </div>
            </div>
          </div>

          <div class="card group hover:shadow-hover transition-shadow reveal delay-400">
            <div class="flex items-start gap-4">
              <div class="w-12 h-12 rounded-lg bg-navy flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6 text-text-inverse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/>
                </svg>
              </div>
              <div>
                <h3 class="font-heading font-bold text-lg text-navy mb-2">Facturatie</h3>
                <p class="text-text-muted">Automatische offertes, kortingscodes en export naar je boekhouding (Exact Online).</p>
              </div>
            </div>
          </div>
        </div>

        <div class="text-center mt-10 reveal">
          <a href="features.html" class="btn-ghost">
            Alle functies bekijken
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
          </a>
        </div>
      </div>
    </section>

    <!-- Social Proof -->
    <section class="section bg-navy text-text-inverse">
      <div class="container">
        <div class="max-w-3xl mx-auto text-center">
          <blockquote class="reveal">
            <p class="text-xl lg:text-2xl font-medium mb-6 text-balance">
              "Stride vervangt onze Excel-chaos door een systeem waar onze 200+ medewerkers
              zelf hun opleidingen kunnen boeken en hun voortgang volgen."
            </p>
            <footer class="flex items-center justify-center gap-4">
              <div class="w-12 h-12 rounded-full bg-copper/20 flex items-center justify-center">
                <span class="font-heading font-bold text-copper">VAD</span>
              </div>
              <div class="text-left">
                <cite class="not-italic font-medium">VAD Vlaanderen</cite>
                <p class="text-sm text-text-inverse/70">Vlaamse expertisecentrum</p>
              </div>
            </footer>
          </blockquote>
        </div>
      </div>
    </section>

    <!-- Pricing / CTA -->
    <section class="section" id="pricing">
      <div class="container">
        <div class="max-w-2xl mx-auto text-center">
          <h2 class="text-2xl lg:text-3xl font-bold text-navy mb-4 reveal">
            Prijzen op maat
          </h2>
          <p class="text-text-muted text-lg mb-8 reveal delay-100">
            Elke organisatie is anders. We maken een voorstel op basis van
            je aantal medewerkers en specifieke wensen.
          </p>
          <div class="reveal delay-200">
            <a href="#demo" class="btn-primary text-lg px-8 py-4">
              Vraag een offerte aan
            </a>
          </div>
        </div>
      </div>
    </section>

    <!-- Demo CTA -->
    <section class="section bg-surface" id="demo">
      <div class="container">
        <div class="max-w-xl mx-auto">
          <div class="text-center mb-10">
            <h2 class="text-2xl lg:text-3xl font-bold text-navy mb-4 reveal">
              Klaar om te starten?
            </h2>
            <p class="text-text-muted reveal delay-100">
              Plan een vrijblijvend gesprek. We tonen je het platform en bespreken jouw situatie.
            </p>
          </div>

          <form class="card reveal delay-200" action="#" method="POST">
            <div class="space-y-4">
              <div>
                <label for="name" class="block text-sm font-medium text-navy mb-1">Naam *</label>
                <input type="text" id="name" name="name" required
                       class="w-full px-4 py-3 rounded border border-navy/20 focus:border-navy focus:ring-2 focus:ring-navy/10 outline-none transition-colors">
              </div>

              <div>
                <label for="organization" class="block text-sm font-medium text-navy mb-1">Organisatie *</label>
                <input type="text" id="organization" name="organization" required
                       class="w-full px-4 py-3 rounded border border-navy/20 focus:border-navy focus:ring-2 focus:ring-navy/10 outline-none transition-colors">
              </div>

              <div>
                <label for="email" class="block text-sm font-medium text-navy mb-1">E-mail *</label>
                <input type="email" id="email" name="email" required
                       class="w-full px-4 py-3 rounded border border-navy/20 focus:border-navy focus:ring-2 focus:ring-navy/10 outline-none transition-colors">
              </div>

              <div>
                <label for="phone" class="block text-sm font-medium text-navy mb-1">Telefoon <span class="text-text-muted font-normal">(optioneel)</span></label>
                <input type="tel" id="phone" name="phone"
                       class="w-full px-4 py-3 rounded border border-navy/20 focus:border-navy focus:ring-2 focus:ring-navy/10 outline-none transition-colors">
              </div>

              <div>
                <label for="message" class="block text-sm font-medium text-navy mb-1">Bericht <span class="text-text-muted font-normal">(optioneel)</span></label>
                <textarea id="message" name="message" rows="3"
                          class="w-full px-4 py-3 rounded border border-navy/20 focus:border-navy focus:ring-2 focus:ring-navy/10 outline-none transition-colors resize-none"></textarea>
              </div>

              <button type="submit" class="btn-primary w-full py-4">
                Verstuur aanvraag
              </button>
            </div>
          </form>

          <p class="text-center text-sm text-text-muted mt-6 reveal delay-300">
            Of mail direct: <a href="mailto:info@stride.be" class="text-navy hover:text-copper">info@stride.be</a>
          </p>
        </div>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer class="py-12 border-t border-navy/10">
    <div class="container">
      <div class="flex flex-col md:flex-row items-center justify-between gap-6">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 bg-navy rounded flex items-center justify-center">
            <span class="text-text-inverse font-heading font-bold text-sm">S</span>
          </div>
          <span class="font-heading font-bold text-navy">Stride</span>
        </div>

        <nav class="flex items-center gap-6 text-sm">
          <a href="features.html" class="text-text-muted hover:text-navy transition-colors">Functies</a>
          <a href="#demo" class="text-text-muted hover:text-navy transition-colors">Contact</a>
          <a href="#" class="text-text-muted hover:text-navy transition-colors">Privacy</a>
        </nav>

        <p class="text-sm text-text-muted">
          &copy; 2026 Stride. Alle rechten voorbehouden.
        </p>
      </div>
    </div>
  </footer>

  <!-- Scroll Reveal Script -->
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
          }
        });
      }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

      document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
    });
  </script>

</body>
</html>
```

**Step 2: Build CSS and verify**

```bash
cd marketing
npm run build
```

Open `marketing/index.html` in browser to verify.

**Step 3: Commit**

```bash
git add marketing/index.html
git commit -m "feat(marketing): add landing page with hero, features, and demo form"
```

---

## Task 3: Features Page

**Files:**
- Create: `marketing/features.html`

**Step 1: Create features page**

Create `marketing/features.html`:

```html
<!DOCTYPE html>
<html lang="nl-BE">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Functies — Stride</title>
  <meta name="description" content="Ontdek alle functies van Stride: sessiebeheer, trajecten, SCORM-compatibiliteit, facturatie en meer.">

  <link rel="preconnect" href="https://api.fontshare.com" crossorigin>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="dist/styles.css">
  <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="grain">

  <!-- Header (same as landing) -->
  <header class="fixed top-0 inset-x-0 z-40 bg-cream/80 backdrop-blur-md border-b border-navy/5"
          x-data="{ open: false, scrolled: false }"
          @scroll.window="scrolled = window.scrollY > 20"
          :class="{ 'shadow-card': scrolled }">
    <div class="container">
      <nav class="flex items-center justify-between h-16 lg:h-20">
        <a href="index.html" class="flex items-center gap-2">
          <div class="w-8 h-8 bg-navy rounded flex items-center justify-center">
            <span class="text-text-inverse font-heading font-bold text-lg">S</span>
          </div>
          <span class="font-heading font-bold text-xl text-navy">Stride</span>
        </a>

        <div class="hidden lg:flex items-center gap-8">
          <a href="features.html" class="text-navy font-medium">Functies</a>
          <a href="index.html#pricing" class="text-text hover:text-navy transition-colors">Prijzen</a>
          <a href="index.html#demo" class="btn-primary">Plan een demo</a>
        </div>

        <button @click="open = !open" class="lg:hidden p-2 -mr-2" aria-label="Menu">
          <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
          </svg>
          <svg x-show="open" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
          </svg>
        </button>
      </nav>

      <div x-show="open" x-cloak
           x-transition:enter="transition ease-out duration-200"
           x-transition:enter-start="opacity-0 -translate-y-2"
           x-transition:enter-end="opacity-100 translate-y-0"
           class="lg:hidden py-4 border-t border-navy/5">
        <div class="flex flex-col gap-4">
          <a href="features.html" class="text-navy font-medium py-2">Functies</a>
          <a href="index.html#pricing" class="text-text py-2">Prijzen</a>
          <a href="index.html#demo" class="btn-primary text-center">Plan een demo</a>
        </div>
      </div>
    </div>
  </header>

  <main>
    <!-- Features Hero -->
    <section class="pt-32 lg:pt-40 pb-16">
      <div class="container">
        <div class="max-w-3xl mx-auto text-center">
          <h1 class="text-4xl sm:text-5xl lg:text-6xl font-bold text-navy text-balance reveal">
            Alles wat je nodig hebt.<br>
            <span class="text-copper">Niets</span> dat je niet nodig hebt.
          </h1>
          <p class="mt-6 text-lg text-text-muted reveal delay-100">
            Geen overbodige complexiteit. Gewoon de tools die je dagelijks gebruikt.
          </p>
        </div>
      </div>
    </section>

    <!-- Feature: Sessiebeheer -->
    <section class="section">
      <div class="container">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
          <div class="reveal">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-copper/10 rounded-full text-copper text-sm font-medium mb-4">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
              </svg>
              Sessiebeheer
            </div>
            <h2 class="text-2xl lg:text-3xl font-bold text-navy mb-4">
              Plan complexe opleidingen met gemak
            </h2>
            <p class="text-text-muted mb-6">
              Meerdaagse trainingen met verschillende sessies, locaties en tijdslots.
              Deelnemers kiezen zelf hun voorkeurssessies waar nodig.
            </p>
            <ul class="space-y-3">
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Sessies met datum, tijd en locatie</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Capaciteitsbeheer en wachtlijsten</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Aanwezigheidsregistratie per sessie</span>
              </li>
            </ul>
          </div>
          <div class="reveal delay-200">
            <div class="bg-surface rounded-xl shadow-card border border-navy/10 overflow-hidden">
              <div class="aspect-[4/3] bg-gradient-to-br from-cream to-navy/5 flex items-center justify-center">
                <span class="text-text-muted">Sessiebeheer Screenshot</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Feature: Trajecten -->
    <section class="section bg-surface">
      <div class="container">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
          <div class="order-2 lg:order-1 reveal delay-200">
            <div class="bg-cream rounded-xl shadow-card border border-navy/10 overflow-hidden">
              <div class="aspect-[4/3] bg-gradient-to-br from-surface to-navy/5 flex items-center justify-center">
                <span class="text-text-muted">Trajecten Screenshot</span>
              </div>
            </div>
          </div>
          <div class="order-1 lg:order-2 reveal">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-navy/10 rounded-full text-navy text-sm font-medium mb-4">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/>
              </svg>
              Trajecten
            </div>
            <h2 class="text-2xl lg:text-3xl font-bold text-navy mb-4">
              Meerjarige leertrajecten
            </h2>
            <p class="text-text-muted mb-6">
              Combineer verplichte en keuzevakken in samenhangende trajecten.
              Deelnemers kiezen hun eigen pad binnen de kaders die je stelt.
            </p>
            <ul class="space-y-3">
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Verplichte + keuzevakken</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Cohortbeheer met deadlines</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Visuele voortgangsweergave</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- Feature: Online & SCORM -->
    <section class="section">
      <div class="container">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
          <div class="reveal">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-copper/10 rounded-full text-copper text-sm font-medium mb-4">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
              </svg>
              Online & SCORM
            </div>
            <h2 class="text-2xl lg:text-3xl font-bold text-navy mb-4">
              E-learning naadloos geïntegreerd
            </h2>
            <p class="text-text-muted mb-6">
              Importeer SCORM-pakketten of gebruik de ingebouwde LearnDash-integratie.
              Combineer met klassikale sessies voor hybride opleidingen.
            </p>
            <ul class="space-y-3">
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">SCORM 1.2 en 2004 ondersteuning</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">LearnDash als content engine</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Hybride leertrajecten</span>
              </li>
            </ul>
          </div>
          <div class="reveal delay-200">
            <div class="bg-surface rounded-xl shadow-card border border-navy/10 overflow-hidden">
              <div class="aspect-[4/3] bg-gradient-to-br from-cream to-navy/5 flex items-center justify-center">
                <span class="text-text-muted">E-learning Screenshot</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Feature: Facturatie -->
    <section class="section bg-surface">
      <div class="container">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
          <div class="order-2 lg:order-1 reveal delay-200">
            <div class="bg-cream rounded-xl shadow-card border border-navy/10 overflow-hidden">
              <div class="aspect-[4/3] bg-gradient-to-br from-surface to-navy/5 flex items-center justify-center">
                <span class="text-text-muted">Facturatie Screenshot</span>
              </div>
            </div>
          </div>
          <div class="order-1 lg:order-2 reveal">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-navy/10 rounded-full text-navy text-sm font-medium mb-4">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z"/>
              </svg>
              Facturatie
            </div>
            <h2 class="text-2xl lg:text-3xl font-bold text-navy mb-4">
              Offertes en facturen automatisch
            </h2>
            <p class="text-text-muted mb-6">
              Van inschrijving naar offerte in één klik. Exporteer naar Exact Online
              of je eigen boekhoudsysteem.
            </p>
            <ul class="space-y-3">
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Automatische offertegeneratie</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Kortingscodes en vouchers</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Exact Online export</span>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </section>

    <!-- Feature: Dashboard -->
    <section class="section">
      <div class="container">
        <div class="grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
          <div class="reveal">
            <div class="inline-flex items-center gap-2 px-3 py-1.5 bg-copper/10 rounded-full text-copper text-sm font-medium mb-4">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
              </svg>
              Dashboard & Rapportage
            </div>
            <h2 class="text-2xl lg:text-3xl font-bold text-navy mb-4">
              Altijd zicht op voortgang
            </h2>
            <p class="text-text-muted mb-6">
              Wie heeft wat gevolgd? Welke certificaten verlopen binnenkort?
              Realtime inzichten zonder handmatig zoekwerk.
            </p>
            <ul class="space-y-3">
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Persoonlijk dashboard per medewerker</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Organisatie-overzichten voor HR</span>
              </li>
              <li class="flex items-start gap-3">
                <svg class="w-5 h-5 text-copper flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <span class="text-text">Certificaatbeheer met herinneringen</span>
              </li>
            </ul>
          </div>
          <div class="reveal delay-200">
            <div class="bg-surface rounded-xl shadow-card border border-navy/10 overflow-hidden">
              <div class="aspect-[4/3] bg-gradient-to-br from-cream to-navy/5 flex items-center justify-center">
                <span class="text-text-muted">Dashboard Screenshot</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Integrations -->
    <section class="section bg-navy">
      <div class="container">
        <div class="text-center max-w-2xl mx-auto mb-12">
          <h2 class="text-2xl lg:text-3xl font-bold text-text-inverse reveal">
            Werkt met je bestaande tools
          </h2>
          <p class="mt-4 text-text-inverse/70 reveal delay-100">
            Gebouwd op WordPress. Integreert met de tools die je al gebruikt.
          </p>
        </div>

        <div class="flex flex-wrap justify-center items-center gap-8 lg:gap-12 reveal delay-200">
          <div class="flex flex-col items-center gap-2 text-text-inverse/70">
            <div class="w-16 h-16 bg-white/10 rounded-lg flex items-center justify-center">
              <span class="font-heading font-bold text-lg text-text-inverse">LD</span>
            </div>
            <span class="text-sm">LearnDash</span>
          </div>
          <div class="flex flex-col items-center gap-2 text-text-inverse/70">
            <div class="w-16 h-16 bg-white/10 rounded-lg flex items-center justify-center">
              <span class="font-heading font-bold text-sm text-text-inverse">SCORM</span>
            </div>
            <span class="text-sm">SCORM</span>
          </div>
          <div class="flex flex-col items-center gap-2 text-text-inverse/70">
            <div class="w-16 h-16 bg-white/10 rounded-lg flex items-center justify-center">
              <span class="font-heading font-bold text-lg text-text-inverse">WP</span>
            </div>
            <span class="text-sm">WordPress</span>
          </div>
          <div class="flex flex-col items-center gap-2 text-text-inverse/70">
            <div class="w-16 h-16 bg-white/10 rounded-lg flex items-center justify-center">
              <span class="font-heading font-bold text-sm text-text-inverse">EO</span>
            </div>
            <span class="text-sm">Exact Online</span>
          </div>
          <div class="flex flex-col items-center gap-2 text-text-inverse/70">
            <div class="w-16 h-16 bg-white/10 rounded-lg flex items-center justify-center">
              <span class="font-heading font-bold text-sm text-text-inverse">FC</span>
            </div>
            <span class="text-sm">FluentCRM</span>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA -->
    <section class="section" id="cta">
      <div class="container">
        <div class="max-w-2xl mx-auto text-center">
          <h2 class="text-2xl lg:text-3xl font-bold text-navy mb-4 reveal">
            Klaar om te starten?
          </h2>
          <p class="text-text-muted text-lg mb-8 reveal delay-100">
            Plan een vrijblijvend gesprek. We tonen je het platform en bespreken jouw situatie.
          </p>
          <div class="flex flex-wrap justify-center gap-4 reveal delay-200">
            <a href="index.html#demo" class="btn-primary text-lg px-8 py-4">
              Plan een demo
            </a>
            <a href="mailto:info@stride.be" class="btn-secondary text-lg px-8 py-4">
              Mail ons
            </a>
          </div>
        </div>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer class="py-12 border-t border-navy/10">
    <div class="container">
      <div class="flex flex-col md:flex-row items-center justify-between gap-6">
        <div class="flex items-center gap-2">
          <div class="w-6 h-6 bg-navy rounded flex items-center justify-center">
            <span class="text-text-inverse font-heading font-bold text-sm">S</span>
          </div>
          <span class="font-heading font-bold text-navy">Stride</span>
        </div>

        <nav class="flex items-center gap-6 text-sm">
          <a href="features.html" class="text-text-muted hover:text-navy transition-colors">Functies</a>
          <a href="index.html#demo" class="text-text-muted hover:text-navy transition-colors">Contact</a>
          <a href="#" class="text-text-muted hover:text-navy transition-colors">Privacy</a>
        </nav>

        <p class="text-sm text-text-muted">
          &copy; 2026 Stride. Alle rechten voorbehouden.
        </p>
      </div>
    </div>
  </footer>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
          }
        });
      }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

      document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
    });
  </script>

</body>
</html>
```

**Step 2: Verify both pages**

```bash
cd marketing
npm run build
```

Open both pages in browser and test:
- Navigation between pages
- Mobile menu toggle
- Scroll reveal animations
- Form fields

**Step 3: Commit**

```bash
git add marketing/features.html
git commit -m "feat(marketing): add features page with all product sections"
```

---

## Task 4: Add Logo SVG

**Files:**
- Create: `marketing/images/logo.svg`

**Step 1: Create simple logo**

Create `marketing/images/logo.svg`:

```svg
<svg width="32" height="32" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
  <rect width="32" height="32" rx="6" fill="#1a2744"/>
  <path d="M10.5 21.5C10.5 21.5 11.5 19 16 19C20.5 19 21.5 21.5 21.5 21.5" stroke="white" stroke-width="2" stroke-linecap="round"/>
  <path d="M10 14L13 11L16 14L19 11L22 14" stroke="#c47d5d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
</svg>
```

**Step 2: Update HTML to use logo file**

In both `index.html` and `features.html`, replace the inline logo with:

```html
<a href="/" class="flex items-center gap-2">
  <img src="images/logo.svg" alt="Stride" class="w-8 h-8">
  <span class="font-heading font-bold text-xl text-navy">Stride</span>
</a>
```

**Step 3: Commit**

```bash
git add marketing/images/logo.svg marketing/index.html marketing/features.html
git commit -m "feat(marketing): add logo SVG and update pages"
```

---

## Task 5: Final Build and Verification

**Step 1: Final CSS build**

```bash
cd marketing
npm run build
```

**Step 2: Test checklist**

- [ ] Both pages load without errors
- [ ] Mobile menu opens/closes
- [ ] Navigation links work between pages
- [ ] Scroll reveal animations trigger
- [ ] Form inputs are focusable
- [ ] All buttons have hover states
- [ ] Colors match design spec
- [ ] Fonts load (Satoshi + DM Sans)

**Step 3: Final commit**

```bash
git add -A
git commit -m "chore(marketing): finalize static marketing site"
```

---

## Summary

| File | Purpose |
|------|---------|
| `marketing/package.json` | NPM scripts for Tailwind |
| `marketing/tailwind.config.js` | Custom theme: colors, fonts, animations |
| `marketing/src/styles.css` | Tailwind source with components |
| `marketing/dist/styles.css` | Built CSS (gitignored if preferred) |
| `marketing/index.html` | Landing page with hero, features, CTA |
| `marketing/features.html` | Full features breakdown |
| `marketing/images/logo.svg` | Simple logo |

**Next steps after implementation:**
1. Add real screenshots to `/images/screenshots/`
2. Connect demo form to backend (FluentForms or Formspree)
3. Deploy to hosting (Netlify, Vercel, or same server)
4. Set up proper domain/subdomain
