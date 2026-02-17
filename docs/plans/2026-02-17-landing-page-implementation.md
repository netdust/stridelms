# Stride Landing Page Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a 3-page static marketing site for Stride LMS plugin using HTML + UIkit CDN.

**Architecture:** Three standalone HTML files with shared header/footer markup, UIkit for layout/components, minimal custom CSS for branding. No build process - pure static files.

**Tech Stack:** HTML5, UIkit 3 (CDN), Custom CSS, SVG icons

**Design Reference:** `docs/plans/2026-02-17-landing-page-design.md`

---

## Task 1: Project Setup

**Files:**
- Create: `stride-marketing/index.html`
- Create: `stride-marketing/assets/css/stride.css`
- Create: `stride-marketing/README.md`

**Step 1: Create project directory structure**

```bash
mkdir -p stride-marketing/assets/{css,js,images}
```

**Step 2: Create README for AI context**

Create `stride-marketing/README.md`:

```markdown
# Stride Marketing Site

Static HTML marketing site for Stride LMS plugin.

## Structure

- `index.html` - Landing page
- `features.html` - Detailed features
- `pricing.html` - Pricing tiers
- `assets/css/stride.css` - Custom styles
- `assets/images/` - Logos, illustrations

## Tech Stack

- UIkit 3.21 via CDN
- No build process - edit HTML directly

## Local Development

Open HTML files directly in browser, or use:

```bash
python -m http.server 8000
# Visit http://localhost:8000
```

## Updating

This site is designed to be easily updated via AI prompts. Each page is self-contained with inline comments marking sections.
```

**Step 3: Create base CSS file**

Create `stride-marketing/assets/css/stride.css`:

```css
/* Stride Marketing Site - Custom Styles */

:root {
    /* Colors */
    --stride-primary: #4a69bd;
    --stride-primary-dark: #3c5aa6;
    --stride-secondary: #f8f9fa;
    --stride-accent: #27ae60;
    --stride-text: #2d3436;
    --stride-text-muted: #636e72;
    --stride-border: #dfe6e9;

    /* Typography */
    --stride-font: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

body {
    font-family: var(--stride-font);
    color: var(--stride-text);
}

/* Header */
.stride-header {
    border-bottom: 1px solid var(--stride-border);
}

.stride-logo {
    font-weight: 700;
    font-size: 1.5rem;
    color: var(--stride-primary);
    text-decoration: none;
}

/* Hero */
.stride-hero {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 80px 0;
}

.stride-hero h1 {
    font-size: 3rem;
    font-weight: 700;
    line-height: 1.2;
    margin-bottom: 1.5rem;
}

.stride-hero .uk-text-lead {
    font-size: 1.25rem;
    color: var(--stride-text-muted);
}

/* Feature Cards */
.stride-feature-card {
    text-align: center;
    padding: 2rem;
}

.stride-feature-card .uk-icon {
    color: var(--stride-primary);
    margin-bottom: 1rem;
}

.stride-feature-card h3 {
    font-size: 1.25rem;
    margin-bottom: 0.75rem;
}

/* Problem/Solution Section */
.stride-problem {
    background: var(--stride-secondary);
}

.stride-problem-card {
    background: white;
    border: 1px solid var(--stride-border);
    border-radius: 8px;
    padding: 1.5rem;
    text-align: center;
}

/* Steps Section */
.stride-steps {
    counter-reset: step;
}

.stride-step {
    text-align: center;
}

.stride-step::before {
    counter-increment: step;
    content: counter(step);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 48px;
    height: 48px;
    background: var(--stride-primary);
    color: white;
    border-radius: 50%;
    font-size: 1.25rem;
    font-weight: 600;
    margin: 0 auto 1rem;
}

/* CTA Section */
.stride-cta {
    background: var(--stride-primary);
    color: white;
    text-align: center;
    padding: 60px 0;
}

.stride-cta h2 {
    color: white;
    margin-bottom: 1.5rem;
}

.stride-cta .uk-button-default {
    background: white;
    color: var(--stride-primary);
    border: none;
}

/* Pricing Cards */
.stride-pricing-card {
    border: 1px solid var(--stride-border);
    border-radius: 8px;
    padding: 2rem;
    text-align: center;
    height: 100%;
}

.stride-pricing-card.popular {
    border-color: var(--stride-primary);
    box-shadow: 0 4px 20px rgba(74, 105, 189, 0.15);
}

.stride-pricing-card .price {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--stride-primary);
    margin: 1rem 0;
}

.stride-pricing-card .price span {
    font-size: 1rem;
    font-weight: 400;
    color: var(--stride-text-muted);
}

.stride-pricing-card ul {
    text-align: left;
    list-style: none;
    padding: 0;
    margin: 1.5rem 0;
}

.stride-pricing-card li {
    padding: 0.5rem 0;
    border-bottom: 1px solid var(--stride-border);
}

.stride-pricing-card li:last-child {
    border-bottom: none;
}

/* Feature Detail Sections */
.stride-feature-section {
    padding: 60px 0;
}

.stride-feature-section:nth-child(even) {
    background: var(--stride-secondary);
}

.stride-feature-section h2 {
    margin-bottom: 1rem;
}

.stride-feature-section ul {
    margin-top: 1.5rem;
}

/* Integrations */
.stride-integrations {
    text-align: center;
    padding: 40px 0;
    background: var(--stride-secondary);
}

.stride-integrations img {
    height: 40px;
    margin: 0 1.5rem;
    opacity: 0.7;
    transition: opacity 0.2s;
}

.stride-integrations img:hover {
    opacity: 1;
}

/* Footer */
.stride-footer {
    background: var(--stride-text);
    color: white;
    padding: 40px 0;
}

.stride-footer a {
    color: rgba(255, 255, 255, 0.7);
}

.stride-footer a:hover {
    color: white;
    text-decoration: none;
}

/* Comparison Table */
.stride-comparison th {
    background: var(--stride-secondary);
}

.stride-comparison td {
    text-align: center;
}

.stride-comparison td:first-child {
    text-align: left;
    font-weight: 500;
}

/* FAQ */
.stride-faq .uk-accordion-title {
    font-size: 1rem;
    font-weight: 500;
}

/* Buttons */
.uk-button-primary {
    background: var(--stride-primary);
}

.uk-button-primary:hover {
    background: var(--stride-primary-dark);
}

/* Badge */
.stride-badge {
    display: inline-block;
    background: var(--stride-primary);
    color: white;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    margin-bottom: 0.5rem;
}

/* Responsive */
@media (max-width: 960px) {
    .stride-hero h1 {
        font-size: 2rem;
    }

    .stride-hero {
        padding: 40px 0;
    }
}
```

**Step 4: Commit setup**

```bash
git add stride-marketing/
git commit -m "chore: initial marketing site setup with CSS"
```

---

## Task 2: Landing Page - Header & Hero

**Files:**
- Create: `stride-marketing/index.html`

**Step 1: Create landing page with header and hero**

Create `stride-marketing/index.html`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stride - The LMS Layer for In-Person Training</title>
    <meta name="description" content="Schedule editions, manage enrollments, track attendance, invoice clients — all integrated with LearnDash.">

    <!-- UIkit CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/css/uikit.min.css" />

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/stride.css">
</head>
<body>

    <!-- HEADER -->
    <header class="stride-header">
        <nav class="uk-navbar-container uk-navbar-transparent" uk-navbar>
            <div class="uk-container">
                <div class="uk-navbar-left">
                    <a href="index.html" class="stride-logo">Stride</a>
                </div>
                <div class="uk-navbar-right">
                    <ul class="uk-navbar-nav uk-visible@m">
                        <li><a href="features.html">Features</a></li>
                        <li><a href="pricing.html">Pricing</a></li>
                    </ul>
                    <a href="pricing.html" class="uk-button uk-button-primary uk-margin-left">Get Started</a>
                </div>
            </div>
        </nav>
    </header>

    <!-- HERO -->
    <section class="stride-hero">
        <div class="uk-container">
            <div class="uk-grid uk-grid-large uk-flex-middle" uk-grid>
                <div class="uk-width-1-2@m">
                    <h1>The LMS layer for in-person training</h1>
                    <p class="uk-text-lead">
                        Schedule editions, manage enrollments, track attendance, invoice clients — all integrated with LearnDash.
                    </p>
                    <div class="uk-margin-medium-top">
                        <a href="features.html" class="uk-button uk-button-default uk-margin-right">View Features</a>
                        <a href="pricing.html" class="uk-button uk-button-primary">See Pricing</a>
                    </div>
                </div>
                <div class="uk-width-1-2@m uk-visible@m">
                    <!-- Hero illustration placeholder -->
                    <div class="uk-placeholder uk-text-center" style="height: 300px; display: flex; align-items: center; justify-content: center;">
                        <span uk-icon="icon: calendar; ratio: 4"></span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Remaining sections will be added in next tasks -->

    <!-- FOOTER -->
    <footer class="stride-footer">
        <div class="uk-container">
            <div class="uk-grid uk-child-width-1-3@m" uk-grid>
                <div>
                    <a href="index.html" class="stride-logo" style="color: white;">Stride</a>
                    <p class="uk-margin-small-top" style="color: rgba(255,255,255,0.7);">
                        The LMS layer for in-person training.
                    </p>
                </div>
                <div>
                    <h4 style="color: white;">Links</h4>
                    <ul class="uk-list">
                        <li><a href="features.html">Features</a></li>
                        <li><a href="pricing.html">Pricing</a></li>
                    </ul>
                </div>
                <div>
                    <h4 style="color: white;">Contact</h4>
                    <p style="color: rgba(255,255,255,0.7);">
                        hello@stride-lms.com
                    </p>
                </div>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1);">
            <p class="uk-text-center uk-text-small" style="color: rgba(255,255,255,0.5);">
                &copy; 2026 Stride. All rights reserved.
            </p>
        </div>
    </footer>

    <!-- UIkit JS -->
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit-icons.min.js"></script>

</body>
</html>
```

**Step 2: Test in browser**

```bash
cd stride-marketing && python -m http.server 8000
# Open http://localhost:8000 in browser
# Verify: header displays, hero section shows, footer at bottom
```

**Step 3: Commit**

```bash
git add stride-marketing/index.html
git commit -m "feat: add landing page header, hero, and footer"
```

---

## Task 3: Landing Page - Problem/Solution & Features

**Files:**
- Modify: `stride-marketing/index.html`

**Step 1: Add problem/solution section after hero**

Insert after `</section>` (hero) and before `<!-- FOOTER -->`:

```html
    <!-- PROBLEM/SOLUTION -->
    <section class="stride-problem uk-section">
        <div class="uk-container uk-container-small uk-text-center">
            <h2>LearnDash is great for online courses.<br>But in-person training needs more.</h2>
            <div class="uk-grid uk-child-width-1-3@m uk-margin-medium-top" uk-grid>
                <div>
                    <div class="stride-problem-card">
                        <span uk-icon="icon: close; ratio: 2" style="color: #e74c3c;"></span>
                        <p class="uk-margin-small-top">No session scheduling</p>
                    </div>
                </div>
                <div>
                    <div class="stride-problem-card">
                        <span uk-icon="icon: close; ratio: 2" style="color: #e74c3c;"></span>
                        <p class="uk-margin-small-top">No venue management</p>
                    </div>
                </div>
                <div>
                    <div class="stride-problem-card">
                        <span uk-icon="icon: close; ratio: 2" style="color: #e74c3c;"></span>
                        <p class="uk-margin-small-top">No group invoicing</p>
                    </div>
                </div>
            </div>
            <p class="uk-margin-medium-top uk-text-lead"><strong>Stride fills the gap.</strong></p>
        </div>
    </section>

    <!-- FEATURES -->
    <section class="uk-section">
        <div class="uk-container">
            <h2 class="uk-text-center uk-margin-medium-bottom">Everything you need</h2>
            <div class="uk-grid uk-child-width-1-3@m uk-grid-match" uk-grid>
                <div>
                    <div class="stride-feature-card uk-card uk-card-default uk-card-body">
                        <span uk-icon="icon: calendar; ratio: 2"></span>
                        <h3>Editions & Sessions</h3>
                        <p>Schedule multiple runs of the same course with different dates, venues, and capacities.</p>
                    </div>
                </div>
                <div>
                    <div class="stride-feature-card uk-card uk-card-default uk-card-body">
                        <span uk-icon="icon: users; ratio: 2"></span>
                        <h3>Smart Enrollment</h3>
                        <p>Individual, colleague groups, or full trajectory enrollment paths with capacity management.</p>
                    </div>
                </div>
                <div>
                    <div class="stride-feature-card uk-card uk-card-default uk-card-body">
                        <span uk-icon="icon: credit-card; ratio: 2"></span>
                        <h3>Quotes & Vouchers</h3>
                        <p>Auto-create quotes on enrollment. Apply member, action, or speaker vouchers.</p>
                    </div>
                </div>
                <div>
                    <div class="stride-feature-card uk-card uk-card-default uk-card-body">
                        <span uk-icon="icon: check; ratio: 2"></span>
                        <h3>Attendance Tracking</h3>
                        <p>Per-session check-in with hours calculation and configurable completion rules.</p>
                    </div>
                </div>
                <div>
                    <div class="stride-feature-card uk-card uk-card-default uk-card-body">
                        <span uk-icon="icon: settings; ratio: 2"></span>
                        <h3>Admin Dashboard</h3>
                        <p>Full overview with student lists, attendance panels, bulk actions, and CSV/PDF exports.</p>
                    </div>
                </div>
                <div>
                    <div class="stride-feature-card uk-card uk-card-default uk-card-body">
                        <span uk-icon="icon: git-fork; ratio: 2"></span>
                        <h3>Trajectories</h3>
                        <p>Multi-year learning paths with required and elective courses, visual progress tracking.</p>
                    </div>
                </div>
            </div>
            <div class="uk-text-center uk-margin-medium-top">
                <a href="features.html" class="uk-button uk-button-text">See all features &rarr;</a>
            </div>
        </div>
    </section>
```

**Step 2: Test in browser**

Refresh http://localhost:8000
- Verify: problem cards with X icons display
- Verify: 6 feature cards in 3-column grid
- Verify: "See all features" link at bottom

**Step 3: Commit**

```bash
git add stride-marketing/index.html
git commit -m "feat: add problem/solution and features sections"
```

---

## Task 4: Landing Page - How It Works & CTA

**Files:**
- Modify: `stride-marketing/index.html`

**Step 1: Add how it works and CTA sections**

Insert after features `</section>` and before `<!-- FOOTER -->`:

```html
    <!-- HOW IT WORKS -->
    <section class="uk-section uk-section-muted">
        <div class="uk-container uk-container-small">
            <h2 class="uk-text-center uk-margin-medium-bottom">How it works</h2>
            <div class="uk-grid uk-child-width-1-3@m stride-steps" uk-grid>
                <div class="stride-step">
                    <h4>Create course in LearnDash</h4>
                    <p>Build your content — lessons, quizzes, certificates — in LearnDash as usual.</p>
                </div>
                <div class="stride-step">
                    <h4>Schedule editions</h4>
                    <p>Create editions with specific dates, venues, capacity, and pricing.</p>
                </div>
                <div class="stride-step">
                    <h4>Manage & invoice</h4>
                    <p>Track enrollments, attendance, and quotes from one unified dashboard.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="stride-cta">
        <div class="uk-container uk-container-small">
            <h2>Ready to streamline your training?</h2>
            <a href="pricing.html" class="uk-button uk-button-default uk-button-large">View Pricing</a>
        </div>
    </section>
```

**Step 2: Test in browser**

Refresh http://localhost:8000
- Verify: 3 numbered steps display
- Verify: blue CTA section with white button

**Step 3: Commit**

```bash
git add stride-marketing/index.html
git commit -m "feat: add how it works and CTA sections to landing page"
```

---

## Task 5: Features Page

**Files:**
- Create: `stride-marketing/features.html`

**Step 1: Create features page**

Create `stride-marketing/features.html`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Features - Stride</title>
    <meta name="description" content="Everything you need to manage in-person training with LearnDash.">

    <!-- UIkit CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/css/uikit.min.css" />

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/stride.css">
</head>
<body>

    <!-- HEADER -->
    <header class="stride-header">
        <nav class="uk-navbar-container uk-navbar-transparent" uk-navbar>
            <div class="uk-container">
                <div class="uk-navbar-left">
                    <a href="index.html" class="stride-logo">Stride</a>
                </div>
                <div class="uk-navbar-right">
                    <ul class="uk-navbar-nav uk-visible@m">
                        <li class="uk-active"><a href="features.html">Features</a></li>
                        <li><a href="pricing.html">Pricing</a></li>
                    </ul>
                    <a href="pricing.html" class="uk-button uk-button-primary uk-margin-left">Get Started</a>
                </div>
            </div>
        </nav>
    </header>

    <!-- HERO -->
    <section class="uk-section uk-section-muted">
        <div class="uk-container uk-text-center">
            <h1>Features</h1>
            <p class="uk-text-lead">Everything you need to manage in-person training</p>
        </div>
    </section>

    <!-- EDITIONS & SESSIONS -->
    <section class="stride-feature-section">
        <div class="uk-container">
            <div class="uk-grid uk-grid-large uk-flex-middle" uk-grid>
                <div class="uk-width-1-2@m">
                    <div class="uk-placeholder uk-text-center" style="height: 250px; display: flex; align-items: center; justify-content: center;">
                        <span uk-icon="icon: calendar; ratio: 4"></span>
                    </div>
                </div>
                <div class="uk-width-1-2@m">
                    <h2>Editions & Sessions</h2>
                    <p class="uk-text-lead">One course, many editions. Schedule the same training multiple times with different dates, venues, and capacities.</p>
                    <ul class="uk-list uk-list-bullet">
                        <li>Multiple sessions per edition</li>
                        <li>Venue & capacity management</li>
                        <li>Status: open, full, cancelled, postponed</li>
                        <li>Speaker assignments</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ENROLLMENT PATHS -->
    <section class="stride-feature-section">
        <div class="uk-container">
            <div class="uk-grid uk-grid-large uk-flex-middle" uk-grid>
                <div class="uk-width-1-2@m uk-flex-last@m">
                    <div class="uk-placeholder uk-text-center" style="height: 250px; display: flex; align-items: center; justify-content: center;">
                        <span uk-icon="icon: users; ratio: 4"></span>
                    </div>
                </div>
                <div class="uk-width-1-2@m">
                    <h2>Smart Enrollment</h2>
                    <p class="uk-text-lead">Three ways to enroll:</p>
                    <p><strong>Individual</strong> — Self-service signup<br>
                    <strong>Colleague</strong> — Manager enrolls team members<br>
                    <strong>Trajectory</strong> — Multi-course program enrollment</p>
                    <ul class="uk-list uk-list-bullet">
                        <li>FluentForms integration</li>
                        <li>Automatic capacity updates</li>
                        <li>Waitlist support</li>
                        <li>Cancellation rules (14-day policy)</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- QUOTES & VOUCHERS -->
    <section class="stride-feature-section">
        <div class="uk-container">
            <div class="uk-grid uk-grid-large uk-flex-middle" uk-grid>
                <div class="uk-width-1-2@m">
                    <div class="uk-placeholder uk-text-center" style="height: 250px; display: flex; align-items: center; justify-content: center;">
                        <span uk-icon="icon: credit-card; ratio: 4"></span>
                    </div>
                </div>
                <div class="uk-width-1-2@m">
                    <h2>Quotes & Vouchers</h2>
                    <p class="uk-text-lead">Auto-generate quotes on enrollment. Apply vouchers. Export to accounting.</p>
                    <ul class="uk-list uk-list-bullet">
                        <li>PDF quotes with your branding</li>
                        <li>Member, action, speaker vouchers</li>
                        <li>Exact Online CSV export</li>
                        <li>VAT validation</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ATTENDANCE & COMPLETION -->
    <section class="stride-feature-section">
        <div class="uk-container">
            <div class="uk-grid uk-grid-large uk-flex-middle" uk-grid>
                <div class="uk-width-1-2@m uk-flex-last@m">
                    <div class="uk-placeholder uk-text-center" style="height: 250px; display: flex; align-items: center; justify-content: center;">
                        <span uk-icon="icon: check; ratio: 4"></span>
                    </div>
                </div>
                <div class="uk-width-1-2@m">
                    <h2>Attendance & Completion</h2>
                    <p class="uk-text-lead">Track who showed up. Trigger certificates automatically.</p>
                    <ul class="uk-list uk-list-bullet">
                        <li>Per-session attendance</li>
                        <li>Hours calculation</li>
                        <li>Completion rules (%, count, all)</li>
                        <li>LearnDash certificate integration</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- TWO DASHBOARDS -->
    <section class="stride-feature-section">
        <div class="uk-container">
            <h2 class="uk-text-center uk-margin-medium-bottom">Two Dashboards</h2>
            <div class="uk-grid uk-child-width-1-2@m uk-grid-match" uk-grid>
                <div>
                    <div class="uk-card uk-card-default uk-card-body">
                        <h3><span uk-icon="icon: user"></span> User Dashboard</h3>
                        <ul class="uk-list uk-list-bullet">
                            <li>My courses</li>
                            <li>My quotes</li>
                            <li>Trajectory progress</li>
                            <li>Calendar view</li>
                            <li>Profile management</li>
                        </ul>
                    </div>
                </div>
                <div>
                    <div class="uk-card uk-card-default uk-card-body">
                        <h3><span uk-icon="icon: settings"></span> Admin Dashboard</h3>
                        <ul class="uk-list uk-list-bullet">
                            <li>Edition overview</li>
                            <li>Student list</li>
                            <li>Attendance panel</li>
                            <li>Bulk actions</li>
                            <li>Exports (CSV, PDF)</li>
                            <li>Email panel</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- TRAJECTORIES -->
    <section class="stride-feature-section">
        <div class="uk-container">
            <div class="uk-grid uk-grid-large uk-flex-middle" uk-grid>
                <div class="uk-width-1-2@m">
                    <div class="uk-placeholder uk-text-center" style="height: 250px; display: flex; align-items: center; justify-content: center;">
                        <span uk-icon="icon: git-fork; ratio: 4"></span>
                    </div>
                </div>
                <div class="uk-width-1-2@m">
                    <h2>Trajectories</h2>
                    <p class="uk-text-lead">Multi-year learning paths. Required + elective courses. Visual progress tracking.</p>
                    <ul class="uk-list uk-list-bullet">
                        <li>Group courses into programs</li>
                        <li>Set completion requirements</li>
                        <li>Track progress over years</li>
                        <li>Auto-graduate on completion</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- INTEGRATIONS -->
    <section class="stride-integrations">
        <div class="uk-container">
            <h3 class="uk-margin-medium-bottom">Built to integrate</h3>
            <div class="uk-flex uk-flex-center uk-flex-wrap uk-flex-middle" uk-grid>
                <div class="uk-text-center uk-padding-small">
                    <span class="uk-text-muted">LearnDash</span>
                </div>
                <div class="uk-text-center uk-padding-small">
                    <span class="uk-text-muted">FluentCRM</span>
                </div>
                <div class="uk-text-center uk-padding-small">
                    <span class="uk-text-muted">FluentForms</span>
                </div>
                <div class="uk-text-center uk-padding-small">
                    <span class="uk-text-muted">Exact Online</span>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="stride-cta">
        <div class="uk-container uk-container-small">
            <h2>See what it costs</h2>
            <a href="pricing.html" class="uk-button uk-button-default uk-button-large">View Pricing</a>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="stride-footer">
        <div class="uk-container">
            <div class="uk-grid uk-child-width-1-3@m" uk-grid>
                <div>
                    <a href="index.html" class="stride-logo" style="color: white;">Stride</a>
                    <p class="uk-margin-small-top" style="color: rgba(255,255,255,0.7);">
                        The LMS layer for in-person training.
                    </p>
                </div>
                <div>
                    <h4 style="color: white;">Links</h4>
                    <ul class="uk-list">
                        <li><a href="features.html">Features</a></li>
                        <li><a href="pricing.html">Pricing</a></li>
                    </ul>
                </div>
                <div>
                    <h4 style="color: white;">Contact</h4>
                    <p style="color: rgba(255,255,255,0.7);">
                        hello@stride-lms.com
                    </p>
                </div>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1);">
            <p class="uk-text-center uk-text-small" style="color: rgba(255,255,255,0.5);">
                &copy; 2026 Stride. All rights reserved.
            </p>
        </div>
    </footer>

    <!-- UIkit JS -->
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit-icons.min.js"></script>

</body>
</html>
```

**Step 2: Test in browser**

Visit http://localhost:8000/features.html
- Verify: all 7 feature sections display with alternating layouts
- Verify: navigation shows "Features" as active
- Verify: links to pricing work

**Step 3: Commit**

```bash
git add stride-marketing/features.html
git commit -m "feat: add features page with all sections"
```

---

## Task 6: Pricing Page

**Files:**
- Create: `stride-marketing/pricing.html`

**Step 1: Create pricing page**

Create `stride-marketing/pricing.html`:

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing - Stride</title>
    <meta name="description" content="Simple pricing for training organizations. Choose the plan that fits your needs.">

    <!-- UIkit CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/css/uikit.min.css" />

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/stride.css">
</head>
<body>

    <!-- HEADER -->
    <header class="stride-header">
        <nav class="uk-navbar-container uk-navbar-transparent" uk-navbar>
            <div class="uk-container">
                <div class="uk-navbar-left">
                    <a href="index.html" class="stride-logo">Stride</a>
                </div>
                <div class="uk-navbar-right">
                    <ul class="uk-navbar-nav uk-visible@m">
                        <li><a href="features.html">Features</a></li>
                        <li class="uk-active"><a href="pricing.html">Pricing</a></li>
                    </ul>
                    <a href="pricing.html" class="uk-button uk-button-primary uk-margin-left">Get Started</a>
                </div>
            </div>
        </nav>
    </header>

    <!-- HERO -->
    <section class="uk-section uk-section-muted">
        <div class="uk-container uk-text-center">
            <h1>Pricing</h1>
            <p class="uk-text-lead">Simple pricing for training organizations</p>
        </div>
    </section>

    <!-- PRICING TIERS -->
    <section class="uk-section">
        <div class="uk-container">
            <div class="uk-grid uk-child-width-1-3@m uk-grid-match" uk-grid>
                <!-- Starter -->
                <div>
                    <div class="stride-pricing-card">
                        <h3>Starter</h3>
                        <p class="uk-text-muted">For small training operations</p>
                        <div class="price">€299<span>/year</span></div>
                        <ul>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Editions & sessions</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Enrollment management</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Attendance tracking</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Basic quotes</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> User dashboard</li>
                            <li class="uk-text-muted"><span uk-icon="icon: minus; ratio: 0.8"></span> Trajectories</li>
                            <li class="uk-text-muted"><span uk-icon="icon: minus; ratio: 0.8"></span> Voucher system</li>
                            <li class="uk-text-muted"><span uk-icon="icon: minus; ratio: 0.8"></span> Admin dashboard</li>
                        </ul>
                        <p class="uk-text-small uk-text-muted">Up to 5 editions/year</p>
                        <a href="#" class="uk-button uk-button-default uk-width-1-1">Get Started</a>
                    </div>
                </div>

                <!-- Pro -->
                <div>
                    <div class="stride-pricing-card popular">
                        <span class="stride-badge">Popular</span>
                        <h3>Pro</h3>
                        <p class="uk-text-muted">For growing organizations</p>
                        <div class="price">€599<span>/year</span></div>
                        <ul>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Everything in Starter</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Trajectories</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Voucher system</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Advanced invoicing</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> CSV/PDF exports</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Admin dashboard</li>
                            <li class="uk-text-muted"><span uk-icon="icon: minus; ratio: 0.8"></span> Multi-site</li>
                            <li class="uk-text-muted"><span uk-icon="icon: minus; ratio: 0.8"></span> Priority support</li>
                        </ul>
                        <p class="uk-text-small uk-text-muted">Up to 50 editions/year</p>
                        <a href="#" class="uk-button uk-button-primary uk-width-1-1">Get Started</a>
                    </div>
                </div>

                <!-- Enterprise -->
                <div>
                    <div class="stride-pricing-card">
                        <h3>Enterprise</h3>
                        <p class="uk-text-muted">For large institutions</p>
                        <div class="price">Custom</div>
                        <ul>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Everything in Pro</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Multi-site support</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Custom integrations</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Priority support</li>
                            <li><span uk-icon="icon: check; ratio: 0.8" style="color: var(--stride-accent);"></span> Dedicated onboarding</li>
                            <li>&nbsp;</li>
                            <li>&nbsp;</li>
                            <li>&nbsp;</li>
                        </ul>
                        <p class="uk-text-small uk-text-muted">Unlimited editions</p>
                        <a href="#" class="uk-button uk-button-default uk-width-1-1">Contact Us</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- COMPARISON TABLE -->
    <section class="uk-section uk-section-muted">
        <div class="uk-container">
            <h2 class="uk-text-center uk-margin-medium-bottom">Compare plans</h2>
            <div class="uk-overflow-auto">
                <table class="uk-table uk-table-striped stride-comparison">
                    <thead>
                        <tr>
                            <th>Feature</th>
                            <th>Starter</th>
                            <th>Pro</th>
                            <th>Enterprise</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Editions per year</td>
                            <td>5</td>
                            <td>50</td>
                            <td>Unlimited</td>
                        </tr>
                        <tr>
                            <td>Sessions per edition</td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Enrollment paths</td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Attendance tracking</td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>User dashboard</td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Basic quotes</td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Trajectories</td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Voucher system</td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Advanced invoicing</td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>CSV/PDF exports</td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Admin dashboard</td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Multi-site</td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Custom integrations</td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                        <tr>
                            <td>Priority support</td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: minus" style="color: #ccc;"></span></td>
                            <td><span uk-icon="icon: check" style="color: var(--stride-accent);"></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="uk-section">
        <div class="uk-container uk-container-small">
            <h2 class="uk-text-center uk-margin-medium-bottom">Frequently asked</h2>
            <ul class="stride-faq" uk-accordion>
                <li>
                    <a class="uk-accordion-title" href>What do I need to run Stride?</a>
                    <div class="uk-accordion-content">
                        <p>You need a WordPress site with LearnDash installed. Stride works alongside LearnDash to add scheduling, enrollment, and invoicing features for in-person training.</p>
                    </div>
                </li>
                <li>
                    <a class="uk-accordion-title" href>Is LearnDash included?</a>
                    <div class="uk-accordion-content">
                        <p>No, LearnDash is sold separately. You'll need an active LearnDash license to use Stride. We integrate with LearnDash but don't include it in our pricing.</p>
                    </div>
                </li>
                <li>
                    <a class="uk-accordion-title" href>Can I upgrade later?</a>
                    <div class="uk-accordion-content">
                        <p>Yes! You can upgrade your plan at any time. When you upgrade, we'll prorate the remaining time on your current plan toward your new subscription.</p>
                    </div>
                </li>
                <li>
                    <a class="uk-accordion-title" href>Do you offer refunds?</a>
                    <div class="uk-accordion-content">
                        <p>We offer a 14-day money-back guarantee. If Stride doesn't meet your needs within the first 14 days, contact us for a full refund.</p>
                    </div>
                </li>
                <li>
                    <a class="uk-accordion-title" href>What about updates and support?</a>
                    <div class="uk-accordion-content">
                        <p>All plans include one year of updates and support. Starter and Pro plans include email support with 48-hour response time. Enterprise plans include priority support with same-day response.</p>
                    </div>
                </li>
            </ul>
        </div>
    </section>

    <!-- CTA -->
    <section class="stride-cta">
        <div class="uk-container uk-container-small">
            <h2>Questions? Get in touch.</h2>
            <a href="mailto:hello@stride-lms.com" class="uk-button uk-button-default uk-button-large">Contact Us</a>
            <p class="uk-margin-small-top" style="opacity: 0.8;">or email hello@stride-lms.com</p>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="stride-footer">
        <div class="uk-container">
            <div class="uk-grid uk-child-width-1-3@m" uk-grid>
                <div>
                    <a href="index.html" class="stride-logo" style="color: white;">Stride</a>
                    <p class="uk-margin-small-top" style="color: rgba(255,255,255,0.7);">
                        The LMS layer for in-person training.
                    </p>
                </div>
                <div>
                    <h4 style="color: white;">Links</h4>
                    <ul class="uk-list">
                        <li><a href="features.html">Features</a></li>
                        <li><a href="pricing.html">Pricing</a></li>
                    </ul>
                </div>
                <div>
                    <h4 style="color: white;">Contact</h4>
                    <p style="color: rgba(255,255,255,0.7);">
                        hello@stride-lms.com
                    </p>
                </div>
            </div>
            <hr style="border-color: rgba(255,255,255,0.1);">
            <p class="uk-text-center uk-text-small" style="color: rgba(255,255,255,0.5);">
                &copy; 2026 Stride. All rights reserved.
            </p>
        </div>
    </footer>

    <!-- UIkit JS -->
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.6/dist/js/uikit-icons.min.js"></script>

</body>
</html>
```

**Step 2: Test in browser**

Visit http://localhost:8000/pricing.html
- Verify: 3 pricing cards display with Pro highlighted
- Verify: comparison table shows all features
- Verify: FAQ accordion expands/collapses

**Step 3: Commit**

```bash
git add stride-marketing/pricing.html
git commit -m "feat: add pricing page with tiers, comparison, and FAQ"
```

---

## Task 7: Final Polish & Commit

**Files:**
- All files in `stride-marketing/`

**Step 1: Test all pages**

```bash
cd stride-marketing && python -m http.server 8000
```

Visit and verify:
- http://localhost:8000 - Landing page
- http://localhost:8000/features.html - Features page
- http://localhost:8000/pricing.html - Pricing page

Check:
- [ ] All links work between pages
- [ ] Responsive on mobile (resize browser)
- [ ] No console errors

**Step 2: Final commit**

```bash
git add stride-marketing/
git commit -m "feat: complete Stride marketing site (3 pages)"
```

---

## Summary

| Task | Description | Files |
|------|-------------|-------|
| 1 | Project setup | README, CSS |
| 2 | Landing: header + hero | index.html |
| 3 | Landing: problem + features | index.html |
| 4 | Landing: how it works + CTA | index.html |
| 5 | Features page | features.html |
| 6 | Pricing page | pricing.html |
| 7 | Final polish | all |

**Total: 7 tasks, ~7 commits**

---

## Future Enhancements (not in scope)

- [ ] Add real logo SVG
- [ ] Add illustrations/screenshots
- [ ] Add actual integration logos
- [ ] Set up hosting (Netlify/Vercel)
- [ ] Add contact form
- [ ] Add analytics
