# Landing Page Copy Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Update front-page.php copy from sales-pitch tone to collegial expert-led growth messaging for professional audience.

**Architecture:** Single file modification (front-page.php). All changes are copy/structure updates to existing sections. No new services or data models needed.

**Tech Stack:** PHP, WordPress template, UIkit 3 components

---

## Task 1: Update Hero Section

**Files:**
- Modify: `web/app/themes/stride/front-page.php:113-193`

**Step 1: Update tagline**

Change line 120 from:
```php
<?php esc_html_e('VAD Erkende Opleidingen', 'stride'); ?>
```
To:
```php
<?php echo esc_html(get_bloginfo('name') . ' ' . __('Opleidingen', 'stride')); ?>
```

**Step 2: Update headline**

Replace lines 123-127:
```php
<h1 class="stride-hero__title">
    <?php esc_html_e('Versterk je', 'stride'); ?>
    <span class="stride-hero__title-accent"><?php esc_html_e('Expertise', 'stride'); ?></span>
    <?php esc_html_e('in Verslavingszorg', 'stride'); ?>
</h1>
```
With:
```php
<h1 class="stride-hero__title">
    <?php esc_html_e('Blijf groeien', 'stride'); ?>
    <span class="stride-hero__title-accent"><?php esc_html_e('in je vak', 'stride'); ?></span>
</h1>
```

**Step 3: Update description**

Replace line 129-131:
```php
<p class="stride-hero__description">
    <?php esc_html_e('Hoogwaardige trainingen voor professionals in de verslavingszorg. Klassikaal of online, altijd praktijkgericht en door experts ontwikkeld.', 'stride'); ?>
</p>
```
With:
```php
<p class="stride-hero__description">
    <?php esc_html_e('Praktijkgerichte opleidingen ontwikkeld door experts uit het werkveld. Klassikaal of online, altijd toepasbaar in je dagelijkse praktijk.', 'stride'); ?>
</p>
```

**Step 4: Update stats**

Replace lines 144-161 (the stats div content):
```php
<div class="stride-hero__stat">
    <div class="stride-hero__stat-value stride-hero__stat-value--primary"><?php echo esc_html($totalEditions); ?>+</div>
    <div class="stride-hero__stat-label"><?php esc_html_e('Cursussen', 'stride'); ?></div>
</div>
<div class="stride-hero__stat">
    <div class="stride-hero__stat-value"><?php echo esc_html($classroomCount); ?></div>
    <div class="stride-hero__stat-label"><?php esc_html_e('Klassikaal', 'stride'); ?></div>
</div>
<div class="stride-hero__stat">
    <div class="stride-hero__stat-value"><?php echo esc_html($onlineCount); ?></div>
    <div class="stride-hero__stat-label"><?php esc_html_e('Online', 'stride'); ?></div>
</div>
<div class="stride-hero__stat">
    <div class="stride-hero__stat-value">500+</div>
    <div class="stride-hero__stat-label"><?php esc_html_e('Deelnemers', 'stride'); ?></div>
</div>
```
With:
```php
<div class="stride-hero__stat">
    <div class="stride-hero__stat-value stride-hero__stat-value--primary">500+</div>
    <div class="stride-hero__stat-label"><?php esc_html_e('Professionals', 'stride'); ?></div>
</div>
<div class="stride-hero__stat">
    <div class="stride-hero__stat-value"><?php echo esc_html($totalEditions); ?>+</div>
    <div class="stride-hero__stat-label"><?php esc_html_e('Cursussen', 'stride'); ?></div>
</div>
<div class="stride-hero__stat">
    <div class="stride-hero__stat-value">25+</div>
    <div class="stride-hero__stat-label"><?php esc_html_e('Jaar expertise', 'stride'); ?></div>
</div>
```

**Step 5: Commit**

```bash
git add web/app/themes/stride/front-page.php
git commit -m "feat(landing): update hero section copy for professional audience"
```

---

## Task 2: Update Course Catalog Header

**Files:**
- Modify: `web/app/themes/stride/front-page.php:198-204`

**Step 1: Update section header**

Replace lines 198-204:
```php
<div class="stride-section__header">
    <span class="stride-section__eyebrow"><?php esc_html_e('Cursusaanbod', 'stride'); ?></span>
    <h2 class="stride-section__title"><?php esc_html_e('Ontdek onze cursussen', 'stride'); ?></h2>
    <p class="stride-section__description">
        <?php esc_html_e('Kies uit ons brede aanbod van praktijkgerichte trainingen voor professionals in de verslavingszorg.', 'stride'); ?>
    </p>
</div>
```
With:
```php
<div class="stride-section__header">
    <span class="stride-section__eyebrow"><?php esc_html_e('Cursusaanbod', 'stride'); ?></span>
    <h2 class="stride-section__title"><?php esc_html_e('Actueel aanbod', 'stride'); ?></h2>
</div>
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/front-page.php
git commit -m "feat(landing): simplify course catalog header"
```

---

## Task 3: Replace "How It Works" with "What to Expect"

**Files:**
- Modify: `web/app/themes/stride/front-page.php:334-380`

**Step 1: Replace entire section**

Replace lines 334-380 (the entire HOW IT WORKS section):
```php
<!-- HOW IT WORKS SECTION -->
<section class="stride-section stride-section--muted">
    ...
</section>
```
With:
```php
<!-- WHAT TO EXPECT SECTION -->
<section class="stride-section stride-section--muted">
    <div class="uk-container">
        <div class="stride-section__header">
            <span class="stride-section__eyebrow"><?php esc_html_e('Ons aanbod', 'stride'); ?></span>
            <h2 class="stride-section__title"><?php esc_html_e('Wat je kunt verwachten', 'stride'); ?></h2>
        </div>

        <div class="uk-grid uk-grid-large uk-child-width-1-2@m" uk-grid>
            <!-- Losse cursussen -->
            <div>
                <div class="stride-expect-card">
                    <div class="stride-expect-card__icon">
                        <span uk-icon="icon: album; ratio: 1.5"></span>
                    </div>
                    <h3 class="stride-expect-card__title"><?php esc_html_e('Losse cursussen', 'stride'); ?></h3>
                    <ul class="stride-expect-card__list">
                        <li><?php esc_html_e('Praktijkgerichte inhoud door experts uit het veld', 'stride'); ?></li>
                        <li><?php esc_html_e('Flexibel: klassikaal, online of e-learning', 'stride'); ?></li>
                        <li><?php esc_html_e('Erkend certificaat na afronding', 'stride'); ?></li>
                    </ul>
                    <a href="#cursussen" class="uk-button uk-button-default">
                        <?php esc_html_e('Bekijk cursussen', 'stride'); ?>
                    </a>
                </div>
            </div>

            <!-- Leertrajecten -->
            <div>
                <div class="stride-expect-card">
                    <div class="stride-expect-card__icon stride-expect-card__icon--secondary">
                        <span uk-icon="icon: git-branch; ratio: 1.5"></span>
                    </div>
                    <h3 class="stride-expect-card__title"><?php esc_html_e('Leertrajecten', 'stride'); ?></h3>
                    <ul class="stride-expect-card__list">
                        <li><?php esc_html_e('Samengestelde routes voor gerichte verdieping', 'stride'); ?></li>
                        <li><?php esc_html_e('Meerdere cursussen die op elkaar aansluiten', 'stride'); ?></li>
                        <li><?php esc_html_e('Bouw stap voor stap aan je expertise', 'stride'); ?></li>
                    </ul>
                    <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="uk-button uk-button-default">
                        <?php esc_html_e('Bekijk trajecten', 'stride'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/front-page.php
git commit -m "feat(landing): replace how-it-works with what-to-expect section"
```

---

## Task 4: Add CSS for "What to Expect" Cards

**Files:**
- Modify: `web/app/themes/stride/assets/css/stride.css`

**Step 1: Add expect card styles**

Add after the `.stride-steps` styles (around line 2270):
```css
/* Expect Cards */
.stride-expect-card {
    background: var(--stride-surface);
    border-radius: var(--stride-radius-lg);
    padding: var(--stride-space-xl);
    box-shadow: var(--stride-shadow-sm);
    height: 100%;
    display: flex;
    flex-direction: column;
}

.stride-expect-card__icon {
    width: 56px;
    height: 56px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--stride-primary-light);
    color: var(--stride-primary);
    border-radius: var(--stride-radius-md);
    margin-bottom: var(--stride-space-lg);
}

.stride-expect-card__icon--secondary {
    background: var(--stride-secondary-light);
    color: var(--stride-secondary);
}

.stride-expect-card__title {
    font-size: var(--stride-font-size-xl);
    font-weight: 600;
    color: var(--stride-text);
    margin: 0 0 var(--stride-space-md);
}

.stride-expect-card__list {
    list-style: none;
    padding: 0;
    margin: 0 0 var(--stride-space-lg);
    flex: 1;
}

.stride-expect-card__list li {
    position: relative;
    padding-left: var(--stride-space-lg);
    margin-bottom: var(--stride-space-sm);
    color: var(--stride-text-muted);
    line-height: var(--stride-line-height-relaxed);
}

.stride-expect-card__list li::before {
    content: '';
    position: absolute;
    left: 0;
    top: 8px;
    width: 6px;
    height: 6px;
    background: var(--stride-primary);
    border-radius: 50%;
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/assets/css/stride.css
git commit -m "style(landing): add expect card component styles"
```

---

## Task 5: Replace Features with "Leren van vakgenoten"

**Files:**
- Modify: `web/app/themes/stride/front-page.php:382-455`

**Step 1: Replace features section**

Replace lines 382-455 (the entire FEATURES section):
```php
<!-- FEATURES SECTION -->
<section class="stride-section">
    ...
</section>
```
With:
```php
<!-- APPROACH SECTION -->
<section class="stride-section">
    <div class="uk-container">
        <div class="stride-section__header">
            <span class="stride-section__eyebrow"><?php esc_html_e('Onze aanpak', 'stride'); ?></span>
            <h2 class="stride-section__title"><?php esc_html_e('Leren van vakgenoten', 'stride'); ?></h2>
            <p class="stride-section__description">
                <?php esc_html_e('Onze trainers zijn zelf actief in het werkveld en delen hun praktijkervaring.', 'stride'); ?>
            </p>
        </div>

        <div class="stride-features stride-features--compact">
            <div class="stride-feature">
                <div class="stride-feature__icon">
                    <span uk-icon="icon: users; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Experts uit de praktijk', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Trainers met jarenlange ervaring in het werkveld.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-feature">
                <div class="stride-feature__icon stride-feature__icon--success">
                    <span uk-icon="icon: bolt; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Direct toepasbaar', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Kennis en vaardigheden die je morgen kunt inzetten.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-feature">
                <div class="stride-feature__icon stride-feature__icon--accent">
                    <span uk-icon="icon: file-text; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Erkende certificering', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Accreditatiepunten voor je professionele portfolio.', 'stride'); ?>
                </p>
            </div>

            <div class="stride-feature">
                <div class="stride-feature__icon stride-feature__icon--secondary">
                    <span uk-icon="icon: laptop; ratio: 1.5"></span>
                </div>
                <h3 class="stride-feature__title"><?php esc_html_e('Flexibele leervormen', 'stride'); ?></h3>
                <p class="stride-feature__description">
                    <?php esc_html_e('Klassikaal, online of in eigen tempo.', 'stride'); ?>
                </p>
            </div>
        </div>
    </div>
</section>
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/front-page.php
git commit -m "feat(landing): replace features with approach section"
```

---

## Task 6: Add CSS for 4-column Features Grid

**Files:**
- Modify: `web/app/themes/stride/assets/css/stride.css`

**Step 1: Add compact features modifier**

Add after existing `.stride-features` styles:
```css
/* Compact 4-column features grid */
.stride-features--compact {
    grid-template-columns: repeat(2, 1fr);
}

@media (min-width: 960px) {
    .stride-features--compact {
        grid-template-columns: repeat(4, 1fr);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/assets/css/stride.css
git commit -m "style(landing): add compact 4-column features grid"
```

---

## Task 7: Replace Testimonials with Trainers Section

**Files:**
- Modify: `web/app/themes/stride/front-page.php:457-530`

**Step 1: Replace testimonials section**

Replace lines 457-530 (the entire TESTIMONIALS section):
```php
<!-- TESTIMONIALS SECTION -->
<section class="stride-section stride-section--muted">
    ...
</section>
```
With:
```php
<!-- TRAINERS SECTION -->
<section class="stride-section stride-section--muted">
    <div class="uk-container">
        <div class="stride-section__header">
            <span class="stride-section__eyebrow"><?php esc_html_e('Wie staat er voor je', 'stride'); ?></span>
            <h2 class="stride-section__title"><?php esc_html_e('Experts uit het werkveld', 'stride'); ?></h2>
            <p class="stride-section__description">
                <?php esc_html_e('Onze trainers combineren wetenschappelijke kennis met jarenlange praktijkervaring.', 'stride'); ?>
            </p>
        </div>

        <div class="stride-trainers">
            <!-- Trainer 1 - Placeholder -->
            <div class="stride-trainer">
                <div class="stride-trainer__avatar">
                    <span uk-icon="icon: user; ratio: 2"></span>
                </div>
                <h3 class="stride-trainer__name"><?php esc_html_e('Naam Trainer', 'stride'); ?></h3>
                <p class="stride-trainer__role"><?php esc_html_e('Functie, Organisatie', 'stride'); ?></p>
                <p class="stride-trainer__bio"><?php esc_html_e('Korte beschrijving van expertise en achtergrond.', 'stride'); ?></p>
            </div>

            <!-- Trainer 2 - Placeholder -->
            <div class="stride-trainer">
                <div class="stride-trainer__avatar">
                    <span uk-icon="icon: user; ratio: 2"></span>
                </div>
                <h3 class="stride-trainer__name"><?php esc_html_e('Naam Trainer', 'stride'); ?></h3>
                <p class="stride-trainer__role"><?php esc_html_e('Functie, Organisatie', 'stride'); ?></p>
                <p class="stride-trainer__bio"><?php esc_html_e('Korte beschrijving van expertise en achtergrond.', 'stride'); ?></p>
            </div>

            <!-- Trainer 3 - Placeholder -->
            <div class="stride-trainer">
                <div class="stride-trainer__avatar">
                    <span uk-icon="icon: user; ratio: 2"></span>
                </div>
                <h3 class="stride-trainer__name"><?php esc_html_e('Naam Trainer', 'stride'); ?></h3>
                <p class="stride-trainer__role"><?php esc_html_e('Functie, Organisatie', 'stride'); ?></p>
                <p class="stride-trainer__bio"><?php esc_html_e('Korte beschrijving van expertise en achtergrond.', 'stride'); ?></p>
            </div>
        </div>
    </div>
</section>
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/front-page.php
git commit -m "feat(landing): replace testimonials with trainers section"
```

---

## Task 8: Add CSS for Trainers Section

**Files:**
- Modify: `web/app/themes/stride/assets/css/stride.css`

**Step 1: Add trainer card styles**

Add after the testimonial styles:
```css
/* Trainers Grid */
.stride-trainers {
    display: grid;
    grid-template-columns: repeat(1, 1fr);
    gap: var(--stride-space-lg);
}

@media (min-width: 640px) {
    .stride-trainers {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (min-width: 960px) {
    .stride-trainers {
        grid-template-columns: repeat(3, 1fr);
    }
}

.stride-trainer {
    background: var(--stride-surface);
    border-radius: var(--stride-radius-lg);
    padding: var(--stride-space-xl);
    text-align: center;
    box-shadow: var(--stride-shadow-sm);
}

.stride-trainer__avatar {
    width: 80px;
    height: 80px;
    margin: 0 auto var(--stride-space-md);
    background: var(--stride-secondary-light);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--stride-secondary);
    overflow: hidden;
}

.stride-trainer__avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.stride-trainer__name {
    font-size: var(--stride-font-size-lg);
    font-weight: 600;
    color: var(--stride-text);
    margin: 0 0 var(--stride-space-xs);
}

.stride-trainer__role {
    font-size: var(--stride-font-size-sm);
    color: var(--stride-primary);
    font-weight: 500;
    margin: 0 0 var(--stride-space-sm);
}

.stride-trainer__bio {
    font-size: var(--stride-font-size-sm);
    color: var(--stride-text-muted);
    line-height: var(--stride-line-height-relaxed);
    margin: 0;
}
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/assets/css/stride.css
git commit -m "style(landing): add trainer card component styles"
```

---

## Task 9: Update CTA Section

**Files:**
- Modify: `web/app/themes/stride/front-page.php:532-546`

**Step 1: Replace CTA section**

Replace lines 532-546:
```php
<!-- CTA SECTION -->
<section class="stride-section stride-section--primary">
    <div class="uk-container">
        <div class="stride-cta">
            <h2 class="stride-cta__title"><?php esc_html_e('Klaar om te starten?', 'stride'); ?></h2>
            <p class="stride-cta__description">
                <?php esc_html_e('Schrijf je vandaag nog in en zet de volgende stap in je professionele ontwikkeling.', 'stride'); ?>
            </p>
            <a href="#cursussen" class="uk-button stride-cta__button">
                <?php esc_html_e('Bekijk cursusaanbod', 'stride'); ?>
                <span uk-icon="icon: arrow-up; ratio: 0.9"></span>
            </a>
        </div>
    </div>
</section>
```
With:
```php
<!-- CTA SECTION -->
<section class="stride-section stride-section--primary">
    <div class="uk-container">
        <div class="stride-cta">
            <h2 class="stride-cta__title"><?php esc_html_e('Aan de slag', 'stride'); ?></h2>
            <p class="stride-cta__description">
                <?php esc_html_e('Bekijk welke cursussen en trajecten aansluiten bij jouw ontwikkeldoelen.', 'stride'); ?>
            </p>
            <div class="stride-cta__actions">
                <a href="#cursussen" class="uk-button stride-cta__button">
                    <?php esc_html_e('Bekijk cursussen', 'stride'); ?>
                    <span uk-icon="icon: arrow-up; ratio: 0.9"></span>
                </a>
                <a href="<?php echo esc_url(home_url('/trajecten/')); ?>" class="uk-button stride-cta__button stride-cta__button--outline">
                    <?php esc_html_e('Bekijk trajecten', 'stride'); ?>
                </a>
            </div>
        </div>
    </div>
</section>
```

**Step 2: Add CTA button outline style**

Add to stride.css after existing CTA styles:
```css
.stride-cta__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--stride-space-md);
    justify-content: center;
}

.stride-cta__button--outline {
    background: transparent;
    border: 2px solid rgba(255, 255, 255, 0.5);
}

.stride-cta__button--outline:hover {
    background: rgba(255, 255, 255, 0.1);
    border-color: rgba(255, 255, 255, 0.8);
}
```

**Step 3: Commit**

```bash
git add web/app/themes/stride/front-page.php web/app/themes/stride/assets/css/stride.css
git commit -m "feat(landing): update CTA section with dual actions"
```

---

## Task 10: Update Newsletter Section

**Files:**
- Modify: `web/app/themes/stride/front-page.php:548-566`

**Step 1: Update newsletter copy**

Replace lines 552-554:
```php
<h3 class="stride-newsletter__title"><?php esc_html_e('Blijf op de hoogte', 'stride'); ?></h3>
<p class="stride-newsletter__description">
    <?php esc_html_e('Ontvang updates over nieuwe cursussen en opleidingsmogelijkheden.', 'stride'); ?>
</p>
```
With:
```php
<h3 class="stride-newsletter__title"><?php esc_html_e('Op de hoogte blijven', 'stride'); ?></h3>
<p class="stride-newsletter__description">
    <?php esc_html_e('Ontvang updates over nieuwe cursussen en trajecten in je vakgebied.', 'stride'); ?>
</p>
```

**Step 2: Commit**

```bash
git add web/app/themes/stride/front-page.php
git commit -m "feat(landing): update newsletter section copy"
```

---

## Task 11: Visual Review

**Step 1: Start local server**

Run: `ddev launch`

**Step 2: Visual check**

Review the landing page at https://stride.ddev.site and verify:
- [ ] Hero: tagline uses site name, headline is "Blijf groeien in je vak"
- [ ] Hero: stats show "Professionals", "Cursussen", "Jaar expertise"
- [ ] Course catalog: header says "Actueel aanbod", no description
- [ ] What to expect: two cards for courses and trajectories
- [ ] Approach: 4-column grid with expert-led messaging
- [ ] Trainers: placeholder cards with icons
- [ ] CTA: dual buttons for courses and trajectories
- [ ] Newsletter: updated copy

**Step 3: Final commit if any fixes needed**

```bash
git add -A
git commit -m "fix(landing): address visual review feedback"
```
