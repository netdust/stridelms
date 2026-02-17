# Stride Landing Page Design

**Date:** 2026-02-17
**Status:** Approved
**Approach:** Static HTML pages with UIkit CDN

---

## Overview

Marketing site for Stride LMS plugin, targeting training organizations that need to manage in-person courses with LearnDash.

### Key Decisions

| Decision | Choice |
|----------|--------|
| Audience | Training organizations |
| Pricing model | 3 tiers (Starter, Pro, Enterprise) |
| Visual style | LearnDash-inspired (clean SaaS) |
| Tech stack | Static HTML + UIkit CDN |
| Pages | Landing, Features, Pricing |
| Hosting | Separate marketing site |

### Key Features to Highlight

1. **Editions & Sessions** - Schedule multiple runs of the same course
2. **Smart Enrollment** - Individual, colleague, trajectory paths
3. **Quotes & Vouchers** - Auto-invoicing with discount codes
4. **Attendance Tracking** - Per-session check-in, completion rules
5. **User Dashboard** - Course overview, quotes, trajectory progress
6. **Admin Dashboard** - Full management, exports, reporting
7. **Trajectories** - Multi-year learning paths

---

## Project Structure

```
stride-marketing/
├── index.html          # Landing page
├── features.html       # Detailed features
├── pricing.html        # Pricing tiers
├── assets/
│   ├── css/
│   │   └── stride.css  # Custom overrides
│   ├── js/
│   │   └── stride.js   # Minimal JS if needed
│   └── images/
│       ├── logo.svg
│       ├── hero-illustration.svg
│       └── feature-*.svg
└── README.md           # For AI context on updates
```

---

## Shared Components

### Header
- Logo (left)
- Nav: Features | Pricing
- CTA button: "Get Started" (right)

### Footer
- Logo + tagline
- Links: Features, Pricing, Contact
- Copyright

---

## Page 1: Landing Page (index.html)

### Section 1 - Hero
- **Headline:** "The LMS layer for in-person training"
- **Subhead:** Schedule editions, manage enrollments, track attendance, invoice clients — all integrated with LearnDash.
- **CTAs:** [View Features] [See Pricing]
- **Visual:** Illustration (calendar + people icons)

### Section 2 - Problem/Solution
- **Message:** LearnDash is great for online courses. But in-person training needs more.
- **Pain points (3 cards):**
  - No session scheduling
  - No venue management
  - No group invoicing
- **Resolution:** Stride fills the gap.

### Section 3 - Key Features (6 cards)
| Feature | Description |
|---------|-------------|
| Editions & Sessions | Schedule multiple runs of same course |
| Smart Enrollment | Individual, colleague, or full trajectory |
| Quotes & Vouchers | Auto-create quotes, apply discounts |
| Attendance Tracking | Per-session check-in, completion rules |
| Admin Dashboard | Full overview, exports, reporting |
| Trajectories | Multi-year learning paths with progress |

- **CTA:** [See all features →]

### Section 4 - How It Works (3 steps)
1. Create course in LearnDash
2. Schedule editions with dates/venue
3. Manage & invoice from one dashboard

### Section 5 - CTA
- **Headline:** Ready to streamline your training?
- **CTA:** [View Pricing]

---

## Page 2: Features Page (features.html)

### Section 1 - Hero (compact)
- **Headline:** Features
- **Subhead:** Everything you need to manage in-person training

### Section 2 - Editions & Sessions
- **Layout:** Image left, text right
- **Key points:**
  - One course, many editions
  - Multiple sessions per edition
  - Venue & capacity management
  - Status: open, full, cancelled
  - Speaker assignments

### Section 3 - Enrollment Paths
- **Layout:** Text left, image right
- **Three paths:** Individual, Colleague, Trajectory
- **Key points:**
  - FluentForms integration
  - Automatic capacity updates
  - Waitlist support
  - Cancellation rules (14-day policy)

### Section 4 - Quotes & Vouchers
- **Layout:** Image left, text right
- **Key points:**
  - Auto-generate quotes on enrollment
  - PDF quotes with branding
  - Member/action/speaker vouchers
  - Exact Online CSV export
  - VAT validation

### Section 5 - Attendance & Completion
- **Layout:** Text left, image right
- **Key points:**
  - Per-session attendance
  - Hours calculation
  - Completion rules (%, count, all)
  - LearnDash certificate integration

### Section 6 - Two Dashboards (side by side)
| User Dashboard | Admin Dashboard |
|----------------|-----------------|
| My courses | Edition overview |
| My quotes | Student list |
| Trajectory progress | Attendance panel |
| Calendar view | Bulk actions |
| Profile management | Exports (CSV, PDF) |
| | Email panel |

### Section 7 - Trajectories
- **Layout:** Image left, text right
- **Key points:**
  - Multi-year learning paths
  - Required + elective courses
  - Visual progress tracking
  - Auto-graduate on completion

### Section 8 - Integrations
- **Logos:** LearnDash, FluentCRM, FluentForms, Exact Online

### Section 9 - CTA
- **Headline:** See what it costs
- **CTA:** [View Pricing]

---

## Page 3: Pricing Page (pricing.html)

### Section 1 - Hero (compact)
- **Headline:** Pricing
- **Subhead:** Simple pricing for training organizations

### Section 2 - Pricing Tiers (3 columns)

| | Starter | Pro (Popular) | Enterprise |
|---|---------|---------------|------------|
| **Price** | €XXX/yr | €XXX/yr | Contact us |
| **For** | Small training ops | Growing organizations | Large institutions |
| **Editions/year** | 5 | 50 | Unlimited |
| **Editions & sessions** | ✓ | ✓ | ✓ |
| **Enrollments** | ✓ | ✓ | ✓ |
| **Attendance** | ✓ | ✓ | ✓ |
| **Basic quotes** | ✓ | ✓ | ✓ |
| **User dashboard** | ✓ | ✓ | ✓ |
| **Trajectories** | — | ✓ | ✓ |
| **Voucher system** | — | ✓ | ✓ |
| **Advanced invoicing** | — | ✓ | ✓ |
| **CSV exports** | — | ✓ | ✓ |
| **Admin dashboard** | — | ✓ | ✓ |
| **Multi-site** | — | — | ✓ |
| **Custom integrations** | — | — | ✓ |
| **Priority support** | — | — | ✓ |
| **Onboarding** | — | — | ✓ |

### Section 3 - Feature Comparison Table
Full comparison table (same data as above in table format)

### Section 4 - FAQ (accordion)
1. What do I need to run Stride?
2. Is LearnDash included?
3. Can I upgrade later?
4. Do you offer refunds?
5. What about updates and support?

### Section 5 - CTA
- **Headline:** Questions? Get in touch.
- **CTA:** [Contact Us]
- **Email:** hello@stride-lms.com

---

## Visual Style Guide

### Colors (TBD - derive from LearnDash/brand)
- Primary: Blue (LearnDash-inspired)
- Secondary: Light gray backgrounds
- Accent: Green for checkmarks/success
- Text: Dark gray

### Typography
- Headings: System font stack or Inter
- Body: System font stack
- Weights: 400 (body), 600 (headings), 700 (CTAs)

### Components (UIkit)
- `uk-section` for page sections
- `uk-container` for content width
- `uk-grid` for layouts
- `uk-card` for feature cards
- `uk-button` for CTAs
- `uk-accordion` for FAQ
- `uk-table` for comparison

---

## Assets Needed

### Images/Illustrations
- [ ] Logo (SVG)
- [ ] Hero illustration (calendar + people)
- [ ] Feature icons (6x)
- [ ] Screenshots/mockups for features page
- [ ] Integration logos (LearnDash, FluentCRM, FluentForms, Exact)

### Copy
- [ ] Final headline/tagline
- [ ] Feature descriptions
- [ ] Pricing (actual numbers)
- [ ] FAQ answers

---

## Next Steps

1. Create implementation plan
2. Set up project structure
3. Build shared components (header, footer)
4. Build landing page
5. Build features page
6. Build pricing page
7. Add images/illustrations
8. Final copy pass
9. Deploy to hosting
