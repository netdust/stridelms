# Stridence Phase 1: Partials

> **For Claude:** Use superpowers:executing-plans to implement this plan.

**Goal:** Create all reusable partial components for the Stridence theme.

**Location:** `web/app/themes/stridence/partials/`

**Pattern:** Partials receive data via `$args`, no service calls inside. Use `get_template_part()` to include.

---

## Task 1.1: Create partials directory

```bash
mkdir -p web/app/themes/stridence/partials
git add . && git commit -m "chore: create partials directory"
```

---

## Task 1.2: badge-status.php

**File:** `partials/badge-status.php`

**Args:**
- `status` - Status key: open, vol, few_spots, cancelled, completed, confirmed, pending
- `spots` - Optional int for auto-detecting "few spots" (≤5)

**Behavior:**
- Map status to badge class (badge-success, badge-warning, badge-error, badge-muted, badge-info)
- Auto-detect few_spots when status=open and spots≤5
- Labels in Dutch: Beschikbaar, Nog X plaatsen, Volzet, Geannuleerd, Afgerond, Bevestigd, In behandeling

---

## Task 1.3: progress-bar.php

**File:** `partials/progress-bar.php`

**Args:**
- `attended` - int hours/sessions attended
- `required` - int total required
- `label` - Optional string (default: "Aanwezigheid")

**Behavior:**
- Calculate percentage, cap at 100%
- Show X/Y with checkmark icon when complete
- Colored bar: bg-primary normally, bg-success when complete

---

## Task 1.4: empty-state.php

**File:** `partials/empty-state.php`

**Args:**
- `icon` - Icon name (default: search)
- `title` - Heading text
- `message` - Description text
- `action` - Button label (optional)
- `url` - Button URL (optional)

**Behavior:**
- Centered layout with icon in circle, title, message, optional CTA button

---

## Task 1.5: breadcrumb.php

**File:** `partials/breadcrumb.php`

**Args:**
- `items` - Array of `['label' => '', 'url' => '']`

**Behavior:**
- Always starts with Home link
- Last item has no link (current page)
- Chevron separators

---

## Task 1.6: session-row.php

**File:** `partials/session-row.php`

**Args:**
- `session` - Object with date, start_time, end_time, location
- `attendance` - Status: present, absent, pending, null

**Behavior:**
- Date block (day + month), time range, location with map-pin icon
- Attendance icon: check-circle (green), x-circle (red), clock (muted)

---

## Task 1.7: card-course.php

**File:** `partials/card-course.php`

**Args:**
- `course` - WP_Post object

**Behavior:**
- Thumbnail (aspect-video), title (line-clamp-2), excerpt (line-clamp-2)
- "Meer info" button linking to course permalink

---

## Task 1.8: card-edition.php

**File:** `partials/card-edition.php`

**Args:**
- `edition` - Edition object/array with id, start_date, venue, price, spots_remaining, status
- `course` - WP_Post (optional)

**Behavior:**
- Thumbnail with status badge overlay
- Course title, date (calendar icon), venue (map-pin icon), price (credit-card icon)
- "Inschrijven" button if status=open/few_spots, else disabled "Niet beschikbaar"

---

## Task 1.9: card-trajectory.php

**File:** `partials/card-trajectory.php`

**Args:**
- `trajectory` - WP_Post or object

**Behavior:**
- Title with status badge, excerpt
- Course count (book-open icon), deadline (clock icon)
- "Bekijk traject" ghost button

---

## Commit

After all partials complete:
```bash
git add web/app/themes/stridence/partials/
git commit -m "feat(stridence): add all reusable partials"
```
