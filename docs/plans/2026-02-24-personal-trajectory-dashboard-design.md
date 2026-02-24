# Personal Trajectory Dashboard Design

**Date:** 2026-02-24
**Status:** Approved
**Author:** Claude (via brainstorming skill)

## Overview

A detailed dashboard page for enrolled trajectory participants, accessible at `/mijn-account/trajecten/{slug}/`. Shows progress, elective choices, materials, and supervisor messages.

## URL Structure & Routing

### URL Pattern
```
/mijn-account/trajecten/{trajectory-slug}/
/mijn-account/trajecten/{trajectory-slug}/?tab=keuzes
```

### Rewrite Rules
```php
// Pattern: mijn-account/trajecten/([^/]+)/?$
// Rewrite: index.php?pagename=mijn-account&trajectory_slug=$1
add_rewrite_rule(
    '^mijn-account/trajecten/([^/]+)/?$',
    'index.php?pagename=mijn-account&trajectory_slug=$matches[1]',
    'top'
);
```

### Access Control
- User must be logged in
- User must have active enrollment in the trajectory
- Non-enrolled users redirected to trajectory public page

### Query Var
Register `trajectory_slug` as query var for WordPress to recognize.

## Tab Structure

Four tabs with URL state via `?tab=xxx`:

| Tab | Slug | Purpose |
|-----|------|---------|
| Voortgang | `voortgang` (default) | Progress overview, course completion status |
| Keuzes | `keuzes` | Elective course selection during choice window |
| Materialen | `materialen` | Course materials from LearnDash |
| Berichten | `berichten` | Supervisor announcements (read-only) |

## Tab 1: Voortgang (Progress)

### Content
- Overall progress bar (completed/required courses)
- Visual course list showing:
  - Course name
  - Status: completed (green check), in-progress (yellow), not started (gray)
  - Edition dates (if cohort mode)
  - Link to course content (if enrolled)

### Data Sources
- `TrajectoryService::getTrajectory()` - trajectory structure
- `CompletionService::getCourseCompletion()` - completion status
- `RegistrationRepository::findEditionsByTrajectory()` - user's edition enrollments

## Tab 2: Keuzes (Elective Selection)

### States

**Choice Window Open:**
- List elective slots with available editions
- Radio/checkbox selection per slot
- Submit button to save choices
- Deadline countdown display

**Choice Window Closed (Before):**
- Message: "Keuzemoment opent op {date}"
- Preview of available electives (read-only)

**Choice Window Closed (After):**
- Display locked selections
- Status of each choice (confirmed, waitlisted)

### Data Sources
- `TrajectorySelection::isChoiceWindowOpen()`
- `TrajectorySelection::getSelections()`
- `TrajectorySelection::setSelections()` - via AJAX

### AJAX Endpoint
```php
// Action: stride_save_elective_choices
// Nonce: stride_trajectory_{registration_id}
// Params: registration_id, selections[]
```

## Tab 3: Materialen (Materials)

### Content
- Expandable panels per course
- Renders LearnDash "Course Materials" field (HTML)
- Only shows courses user has access to

### Data Source
- LearnDash `_sfwd-courses` meta → `course_materials` field

### Empty State
"Geen materialen beschikbaar voor dit traject."

### Future Extension
- Trajectory-level documents (stored on trajectory post)
- Upload functionality for supervisors

## Tab 4: Berichten (Messages)

### Content
One-way announcement board from supervisors to participants.

### Data Structure
Messages stored as JSON array on trajectory post meta:

```php
// Meta key: trajectory_messages
[
    [
        'type' => 'announcement',  // or 'faq', 'update'
        'content' => 'Message text...',
        'author' => 42,            // user ID
        'date' => '2026-02-24 10:30:00'
    ],
]
```

### Message Types
| Type | Icon | Purpose |
|------|------|---------|
| `announcement` | bell | General announcements |
| `faq` | help-circle | Common questions/answers |
| `update` | info | Schedule/content updates |

### Frontend Display
- Read-only timeline for participants
- Chronological order (newest first)
- Shows: type icon, date, content
- Empty state: "Geen berichten"

### Admin Side
Reuses existing notes UI pattern from `EditionAdminController`:
- Add message form with type selector
- Timeline display with delete capability
- Stored on trajectory post (visible to all enrolled users)

## Page Template Structure

### File: `page-mijn-account.php` (Modified)

Detect `trajectory_slug` query var and load trajectory dashboard instead of regular tabs:

```php
$trajectory_slug = get_query_var('trajectory_slug');
if ($trajectory_slug) {
    // Load trajectory dashboard template
    get_template_part('templates/trajectory/dashboard', null, [
        'trajectory_slug' => $trajectory_slug,
        'user' => $user,
    ]);
} else {
    // Existing tab-based dashboard
}
```

### File: `templates/trajectory/dashboard.php` (New)

Main trajectory dashboard shell:
- Validates enrollment
- Header with trajectory info + back link
- Tab navigation
- Loads active tab partial

### Tab Partials (New)
- `templates/trajectory/tab-voortgang.php`
- `templates/trajectory/tab-keuzes.php`
- `templates/trajectory/tab-materialen.php`
- `templates/trajectory/tab-berichten.php`

## Service Layer

### TrajectoryDashboardService (New, in theme)

Frontend service in `themes/stridence/services/frontend/`:

```php
namespace stride\services\frontend;

class TrajectoryDashboardService
{
    public function getEnrollmentForUser(int $userId, int $trajectoryId): ?array;
    public function getProgressData(int $userId, int $trajectoryId): array;
    public function getMaterials(int $trajectoryId, int $userId): array;
    public function getMessages(int $trajectoryId): array;
}
```

### Existing Services Used
- `TrajectoryService` - trajectory structure, courses
- `TrajectorySelection` - elective choices
- `CompletionService` - course completion
- `RegistrationRepository` - enrollment data

## Admin: Trajectory Messages

### Location
Add messages metabox to trajectory edit screen (similar to edition notes).

### Implementation
New admin controller or extend existing trajectory admin:
- `TrajectoryAdminController::renderMessagesMetabox()`
- `TrajectoryAdminController::saveMessages()`

Reuses UI pattern from `EditionAdminController` notes section.

## Mobile Considerations

- Tabs render as horizontal scroll on mobile
- Progress cards stack vertically
- Touch-friendly expandable panels
- Bottom nav hidden when in trajectory view (back to dashboard via header link)

## Implementation Order

1. **Routing** - Rewrite rules, query var, access control
2. **Dashboard shell** - Template structure, tab navigation
3. **Voortgang tab** - Progress display
4. **Berichten tab** - Read-only messages display
5. **Admin messages** - Metabox for adding messages
6. **Materialen tab** - Course materials aggregation
7. **Keuzes tab** - Elective selection UI + AJAX

## Success Criteria

- Enrolled users can view their trajectory progress
- Supervisors can post announcements visible to all participants
- Elective selection works within choice windows
- Course materials accessible per-course
- Mobile-friendly responsive design
- URL bookmarkable with tab state
