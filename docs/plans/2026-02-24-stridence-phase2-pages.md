# Stridence Phase 2: Page Templates

> **For Claude:** Use superpowers:executing-plans to implement this plan.
> **Prerequisite:** Complete Phase 1 (Partials) first.

**Goal:** Create all public-facing page templates.

**Pattern:** Server-render complete HTML. Use partials via `get_template_part()`. Stub service calls with `// TODO: wire up service` markers.

---

## Task 2.1: Create template directories

```bash
mkdir -p web/app/themes/stridence/templates/course
mkdir -p web/app/themes/stridence/templates/trajectory
git add . && git commit -m "chore: create template directories"
```

---

## Task 2.2: archive-sfwd-courses.php (Course Catalog)

**File:** `archive-sfwd-courses.php`

**Structure:**
1. Header section with title "Opleidingen" and subtitle
2. Domain tabs (horizontal scroll on mobile) from `stride_domain` taxonomy
3. Filter dropdowns: formaat, locatie (form with onchange submit)
4. 3-column grid using `partials/card-course.php`
5. Pagination
6. Empty state if no results

**URL params:** `?domein=`, `?formaat=`, `?locatie=`

**Query:** `WP_Query` on `sfwd-courses` with tax_query for domain filter

---

## Task 2.3: templates/course/sidebar-edition.php

**File:** `templates/course/sidebar-edition.php`

**Args:**
- `editions` - Array of edition objects
- `course_id` - int

**Structure:**
- Sticky card with "Geplande sessies" heading
- List of editions: date, venue, price, status badge, "Inschrijven" button
- Empty state if no editions

---

## Task 2.4: single-sfwd-courses.php (Course Detail)

**File:** `single-sfwd-courses.php`

**Structure:**
1. Header: breadcrumb, title, excerpt
2. Sticky tab bar: Overzicht, Programma, Sprekers, Praktisch (uses Alpine `courseDetailTabs()`)
3. Two-column layout:
   - Left (2/3): Content sections with anchor IDs
   - Right (1/3): sidebar-edition.php
4. Mobile sticky CTA bar (fixed bottom, lg:hidden)

**Sections:**
- `#overzicht` - `the_content()`
- `#programma` - LearnDash shortcode `[course_content]`
- `#sprekers` - Placeholder
- `#praktisch` - Info cards (doelgroep, accreditatie)

---

## Task 2.5: single-vad_edition.php (Edition Detail)

**File:** `single-vad_edition.php`

**Structure:**
1. Header: breadcrumb, course title, status badge, date/venue/price meta
2. Two-column layout:
   - Left: Sessions list using `partials/session-row.php`, course description
   - Right: Sticky enrollment card with price summary and CTA

**Meta fields:** `_course_id`, `_start_date`, `_venue`, `_price`, `_status`, `_spots_remaining`

---

## Task 2.6: archive-vad_trajectory.php (Trajectory Catalog)

**File:** `archive-vad_trajectory.php`

**Structure:**
1. Header: title "Trajecten" and subtitle
2. Status filter dropdown
3. 3-column grid using `partials/card-trajectory.php`
4. Pagination
5. Empty state

**URL params:** `?status=`

---

## Task 2.7: templates/trajectory/course-groups.php

**File:** `templates/trajectory/course-groups.php`

**Args:**
- `required_courses` - Array of WP_Post
- `elective_courses` - Array of WP_Post
- `electives_required` - int

**Structure:**
- "Verplichte cursussen" section with check-circle icon
- "Keuzecursussen" section with list icon and "(kies er X)" label
- Each course: thumbnail, title, type label, chevron-right

---

## Task 2.8: single-vad_trajectory.php (Trajectory Detail)

**File:** `single-vad_trajectory.php`

**Structure:**
1. Header: breadcrumb, title, status badge, deadline
2. Two-column layout:
   - Left: `the_content()`, course-groups.php
   - Right: Sticky contact card

**Meta fields:** `_trajectory_deadline`, `_trajectory_status`, `_electives_required`

---

## Task 2.9: 404.php

**File:** `404.php`

**Structure:**
- Centered empty-state partial with "Pagina niet gevonden" message
- Link back to homepage

---

## Commit

After all templates complete:
```bash
git add web/app/themes/stridence/
git commit -m "feat(stridence): add public page templates"
```
