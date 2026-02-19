# Course Landing Page Design

**Date:** 2026-02-19
**Status:** Approved

## Overview

Replace the broken LearnDash default course template with a custom Stride course landing page. The page shows course info, progress for enrolled users, and a clear CTA to enter focus mode.

## Layout: Hero + Accordion

### Hero Section

```
┌─────────────────────────────────────────────────────────┐
│  [Course Thumbnail - 16:9 ratio, rounded corners]       │
│                                                         │
│  E-LEARNING                          ⏱ 4 lessen        │
│  ─────────────────────────────────────────────────────  │
│  Course Title Here                                      │
│  Short description text goes here, 1-2 lines max.       │
│                                                         │
│  ┌─────────────────────┐                                │
│  │   ▶ Start Cursus    │   (primary orange button)     │
│  └─────────────────────┘                                │
└─────────────────────────────────────────────────────────┘
```

**For enrolled users with progress:**
```
┌─────────────────────────────────────────────────────────┐
│  [Same hero as above]                                   │
│                                                         │
│  ┌───────────────────────────────────────────────────┐  │
│  │  Je voortgang: 45%                                │  │
│  │  ████████████░░░░░░░░░░░░░░░░░░                   │  │
│  │                                                   │  │
│  │  ┌─────────────────────┐                          │  │
│  │  │   ▶ Doorgaan        │                          │  │
│  │  └─────────────────────┘                          │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

**Elements:**
- Badge: "E-LEARNING" or "KLASSIKAAL" based on course type
- Lesson count with clock icon
- Primary CTA: "Start Cursus" (new) or "Doorgaan" (in progress)
- Clicking CTA enters LearnDash focus mode

### Content Accordions

```
┌─────────────────────────────────────────────────────────┐
│  ▼ Lessen (4)                                           │
│  ─────────────────────────────────────────────────────  │
│  │  ○ 1. Introductie                                    │
│  │  ○ 2. Theorie                                        │
│  │  ✓ 3. Praktijk                    (completed)        │
│  │  ○ 4. Evaluatie                                      │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  ▶ Wat je leert                                         │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  ▶ Certificaat                                          │
└─────────────────────────────────────────────────────────┘
```

**Behavior:**
- "Lessen" accordion open by default, others collapsed
- Lessons show completion status (checkmark = done, circle = pending)
- Clicking a lesson title jumps directly to that lesson in focus mode
- "Wat je leert" shows learning objectives from course content
- "Certificaat" shows certificate info if course has one

**Styling:**
- UIkit accordion component (`uk-accordion`)
- Stride card styling with subtle borders
- Progress checkmarks use `--stride-success` color

## Technical Implementation

### Files

| File | Action |
|------|--------|
| `templates/course/single.php` | Create - new course landing template |
| `single-sfwd-courses.php` | Modify - use new template instead of shortcode |
| `assets/css/course-single.css` | Create - page-specific styles |

### Data Flow

```
single-sfwd-courses.php
    └── templates/course/single.php
            ├── LearnDash API: learndash_get_course_lessons_list()
            ├── LearnDash API: learndash_course_progress()
            ├── LearnDash API: learndash_get_course_certificate_link()
            └── Click "Start/Doorgaan" → First lesson URL (focus mode)
```

### LearnDash APIs Used

- `sfwd_lms_has_access($course_id, $user_id)` - check enrollment
- `learndash_get_course_lessons_list($course_id)` - get lessons
- `learndash_course_status($course_id, $user_id)` - completion status
- `learndash_lesson_completed($user_id, $lesson_id)` - per-lesson status
- `learndash_get_course_certificate_link($course_id, $user_id)` - certificate URL
- `learndash_get_next_lesson_redirect($course_id)` - resume URL

### No New Services

All data comes from LearnDash APIs. No new Stride services required.

## User States

| State | Hero CTA | Progress | Lessons |
|-------|----------|----------|---------|
| Not logged in | "Start Cursus" | Hidden | Show list, no status |
| Logged in, not enrolled | "Start Cursus" | Hidden | Show list, no status |
| Enrolled, 0% | "Start Cursus" | Show 0% | Show list with circles |
| Enrolled, in progress | "Doorgaan" | Show X% | Show checkmarks/circles |
| Completed | "Bekijk Cursus" | Show 100% + certificate link | All checkmarks |
