# Stride LMS — Visual Reskin Design Prompt

Prompt for a Claude design session. Paste everything below the line into the design session,
and **attach the current token file**: `web/app/themes/stridence/src/css/tokens.css`.
Grounded in the real theme inventory (stridence, 2026-06-11). Reskin only — no UX changes.

---

## The job

Redesign the visual layer of **Stride**, a Dutch-language (nl_BE) LMS for professional training. This is a **reskin, not a UX change**: every page keeps its current structure, information architecture, flows, and interaction behavior. You are redesigning how it *looks and feels* — color, type, spacing, surfaces, shadows, cards, states, micro-motion — not what it does or where things live.

**Mood:** modern, light, fresh, trustworthy, elegant, clean. App-like polish and density where the user works (dashboard, catalogs), but still a *place* — warm enough that you feel at home and want to spend time. Think "calm productivity app meets well-set magazine", not "enterprise admin panel" and not "startup landing page".

**Audience:** care/welfare professionals and their employers. Trust and clarity beat flash. The platform handles real enrollments, quotes, and certificates — it must look like it can be trusted with them.

## Current visual identity

The **attached `tokens.css`** is the authoritative starting point — read it first. It is the single source of truth for the current design system (consumed by Tailwind as CSS custom properties). For orientation: deep teal primary + terracotta accent on warm paper surfaces, warm near-black text (never pure black), Plus Jakarta Sans / Manrope for UI, Newsreader serif for editorial accents, warm-tinted shadows, and semantic badge-color pairs for enrollment statuses.

You may evolve the palette and type system — that's the point of the reskin — but keep the warmth, the light overall key, and the sense of trust. Your token proposal must keep the **same custom-property structure and naming** as the attached file (same groups, same semantic names, RGB-triplet color format) so it drops in as a replacement.

## Screens to design (10)

### A. Catalog overviews ×3 — `/trajecten/`, `/klassikaal/`, `/online/`

One shared design system, three instances (trajectory, in-person course, online course). Current structure per page:

1. Page header band on alt surface: H1 + one-line subtitle (e.g. "Online leren" / "Leer op je eigen tempo met onze e-learning modules en webinars")
2. Filter row: theme chips/tabs with per-chip counts + an "Alles" chip
3. Card grid — **one card per enrollable**. Card content today: format/status badges, title, meta lines (dates, venue, price, session count), enrolled-state badge ("Ingeschreven" / "X% voltooid" with progress), CTA
4. "Toon meer" load-more button with loading state
5. Empty state and error state (these exist as components — design them properly, they're seen often)

The three card types differ in meta: **trajectory cards** show a multi-course journey (steps/phases), **in-person edition cards** show dates + venue + capacity status, **online cards** show self-paced + duration/progress.

### B. Detail pages ×3

1. **In-person edition** (`/edities/<slug>/`): header with title, course link, dates, venue, price, status badge; tabbed content; **session rows** (date, time slot, location — multi-day editions list several); enrollment CTA panel (desktop sidebar) + sticky mobile CTA bar
2. **Online course** (`/opleidingen/<slug>/`): course header; body is LearnDash-rendered content (lesson lists, progress) — **style it via CSS, never re-implement it**; sidebar CTA panel ("Start deze opleiding" / editions list when editions exist); sticky mobile CTA
3. **Trajectory** (`/trajecten/<slug>/`): the journey view — a visual learning path of course groups in sequence, including elective "Kies N uit M" groups; overall progress; tabs: Voortgang / Keuzes / Materialen / Berichten

### C. User dashboard — `/mijn-account/` (structure stays exactly as-is)

- **Sidebar** (240px, collapsible to 56px): primary nav — Home, Opleidingen, Trajecten, Offertes; utility nav — Meldingen (unread-count badge), Downloads, Certificaten; user footer with initials avatar. Mobile gets a separate nav variant.
- **Tabs** (URL-driven `?tab=`): Home, Inschrijvingen, Trajecten, Offertes, Certificaten, Downloads, Meldingen, Profiel
- **Home tab composition:** hero action block ("what to do next"), stat cards row, the "Acties nodig" card with 3 internal tabs (Wacht op mij / Wacht op gebruiker / Meldingen), enrollment panels
- **Recurring dashboard components:** enrollment panel (course + status + completion checklist), progress bar + progress ring, notification list items, toasts, stat cards, profile form (two clearly separated sections: personal data vs billing data)

This is where "app-like" matters most: denser rhythm, crisp hierarchy, satisfying states — while staying on the same warm, light surface language as the rest of the site.

### D. Marketing pages ×3

1. **Homepage** (editorial): hero (currently light serif headline + label + dual CTA over soft decorative blobs), "Hoe wil je leren?" 3-card mode selector (Trajecten / Klassikaal / Online with live counts), featured offerings, trust/about section, closing CTA
2. **Contact**: contact details, form, practical info — make it feel personal, not corporate
3. **Over ons**: editorial long-read with the same serif voice as the homepage hero

## Shared component sheet

Design these once, consistently: buttons (primary / ghost / sizes), status badges (all 6 semantic states), breadcrumb, the 3 card types, session row, empty state, error state, progress bar + ring, tabs (page-level and in-card), form fields (text, select, checkbox, radio — the enrollment forms use them heavily), toast, sidebar nav items (default / active / collapsed), notification item, stat card.

## Hard constraints

1. **Reskin only.** Keep IA, page structure, flows, and component anatomy. Output must be expressible as Tailwind utility classes + CSS tokens over the existing markup.
2. **Token-driven.** Every color/font/radius/shadow flows from the custom properties in the attached `tokens.css`. Client brands re-skin the platform by overriding tokens only — so **no hardcoded hex in components**, and semantic token names (not `--teal-500`).
3. **All UI text in Dutch.** Use the real labels given above; invent Dutch (nl_BE) copy where needed, never English placeholders.
4. **Never one-sided borders on rounded cards** — use background shifts or shadows to separate regions instead.
5. **Server-render first.** No design that depends on JS to look right. Motion is garnish: 150–250ms, ease-out, respect `prefers-reduced-motion`.
6. **LearnDash content is styled, not replaced** — the online-course body must look native through CSS overrides only.
7. **Accessible:** AA contrast on all text and badge combinations, visible focus states, ≥44px touch targets, status never conveyed by color alone.
8. **Responsive:** every screen designed for mobile and desktop; the dashboard sidebar collapses; detail pages get the sticky mobile CTA.

## Deliverables

1. **Updated `tokens.css`** — a drop-in replacement for the attached file: same groups, same semantic property names, RGB-triplet color format; new values (and any new properties you need) with a short rationale per group.
2. **High-fidelity HTML + Tailwind mockups**, one file per screen (10 screens), desktop + mobile, using only the proposed tokens. Populate with realistic Dutch content (real course-ish titles, dates like "21 mei en 12 juni 2026", prices like "€ 245,00", venue like "Vanderlindenstraat 15, 1030 Brussel").
3. **Component sheet** — one page showing every shared component in all its states (default / hover / active / disabled / loading / empty / error).

Work screen by screen; establish the token set and component sheet first, then apply. When a tradeoff arises between "fresh" and "trustworthy", choose trustworthy.
