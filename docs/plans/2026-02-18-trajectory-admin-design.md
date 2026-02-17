# Trajectory Admin Controller Design

**Date:** 2026-02-18
**Status:** Approved
**Reference:** v4.5 TrajectoryAdminController (ported and extended)

## Overview

Create a full-featured admin interface for managing Trajectories (multi-course programs). Ports the v4.5 tabbed structure and adds missing features: course builder, pricing, capacity, and enrollments view.

## Architecture

### Files

| File | Action | Purpose |
|------|--------|---------|
| `Modules/Trajectory/Admin/TrajectoryAdminController.php` | Create | Main controller with inline rendering |
| `Modules/Trajectory/TrajectoryCPT.php` | Modify | Add `auto_metabox => false` |
| `plugin-config.php` | Modify | Register TrajectoryAdminController |
| `themes/stride/assets/css/admin/trajectory-admin.css` | Create | Tab styles, course builder styles |
| `themes/stride/assets/js/admin/trajectory-admin.js` | Create | Tab switching, course builder, AJAX |

### Dependencies

- Select2 (CDN) - for course search dropdowns
- TrajectoryService, TrajectoryRepository
- TrajectoryEnrollmentRepository - for enrollments tab

## Metabox Structure

### 1. Main Metabox (normal, high)

Tabbed interface with 4 tabs:

#### Tab 1: General (Algemeen)

Fields:
- **Mode** (select): Cohort / Zelfgestuurd
  - Shows mode-specific description text
- **Beschrijving** (textarea): Optional trajectory description
- **Capaciteit** (number): Max participants
- **Prijs leden** (number): Member price in EUR
- **Prijs niet-leden** (number): Non-member price in EUR

#### Tab 2: Courses (Cursussen)

Course builder with two sections:

**Required Courses (Verplichte Cursussen):**
- Select2 dropdown to search/add LearnDash courses
- List of added courses with remove button
- All must be completed by participant

**Elective Groups (Keuzevakken):**
- "New Group" button to create elective groups
- Each group has:
  - Group name (text input)
  - Pick count (number - how many to choose)
  - List of courses in group
  - Select2 to add courses
  - Delete group button

**Data Structure (JSON in `courses` field):**
```php
[
    ['course_id' => 123, 'required' => true],
    ['course_id' => 124, 'required' => false, 'group' => 'Specialisatie', 'pick_count' => 2],
]
```

#### Tab 3: Deadlines

**Mode-dependent display:**

When mode = Cohort:
- Inschrijvingsdeadline (date)
- Keuzes beschikbaar vanaf (date)
- Keuzedeadline (date)
- Linked editions table (course → edition mapping)

When mode = Self-paced:
- Deadline maanden (number)
- Info message explaining self-paced mode

**Linked Editions Data Structure (JSON in `linked_editions` field):**
```php
[
    ['course_id' => 123, 'edition_id' => 456],
    ['course_id' => 124, 'edition_id' => 789],
]
```

#### Tab 4: Enrollments (Inschrijvingen)

- Search input (filter by name)
- Status filter dropdown (All/Active/Paused/Completed/Cancelled)
- Table with columns: Deelnemer, Status, Voortgang (progress bar), Ingeschreven
- Pagination for large lists
- Empty state for new trajectories

### 2. Sidebar Metabox (side, high)

- Status dropdown (Draft/Open/InProgress/Closed/Archived)
- Quick stats:
  - Modus
  - Cursussen count
  - Actief (enrolled) count
  - Voltooid count
- Mode-specific dates display

## AJAX Endpoints

| Action | Purpose |
|--------|---------|
| `stride_search_courses` | Search LearnDash courses for Select2 |
| `stride_get_course_editions` | Get editions for a course (cohort linking) |
| `stride_get_trajectory_enrollments` | Paginated enrollment list with filters |

## Save Logic

On `save_post_vad_trajectory`:

1. Verify nonce
2. Check autosave and permissions
3. Process fields:
   - mode, status (validate against enums)
   - description (sanitize_textarea_field)
   - capacity, price, price_non_member (convert to cents)
   - deadline_months (absint)
   - enrollment_deadline, choice_available_date, choice_deadline (dates)
   - courses (JSON array, sanitize course_ids)
   - linked_editions (JSON array, sanitize IDs)

## UI Patterns

- Follow v4.5 inline styles pattern
- Tab navigation with JS switching (no page reload)
- Mode toggle shows/hides relevant sections dynamically
- Select2 for course search with AJAX
- Consistent with Edition admin styling

## Domain Enums Used

- `TrajectoryMode`: Cohort, SelfPaced
- `TrajectoryStatus`: Draft, Open, InProgress, Closed, Archived
