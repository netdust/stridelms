# Dashboard "Mijn opleidingen" Redesign

**Date:** 2026-02-28
**Status:** Approved

## Problem

The dashboard "Inschrijvingen" tab only shows edition-based registrations (classroom courses with dates/sessions/attendance). Online courses where the user has direct LearnDash access are completely invisible. The certificates tab has the same blind spot — it only shows certificates from completed editions.

## Decision

Rename "Inschrijvingen" to **"Mijn opleidingen"** and rebuild the tab to show both classroom editions and online courses. Fix the certificates tab to include online course certificates.

## Design

### Tab Rename & Navigation

- Rename label from "Inschrijvingen" to "Mijn opleidingen" in sidebar and mobile nav
- Change icon from `calendar` to `book-open`
- URL slug stays `inschrijvingen` for backward compatibility

### Tab Layout — Three Sections

**"Komende sessies"** stays at top (unchanged, edition-only).

**Section A: Klassikale opleidingen**
- Existing expandable edition cards (sessions, attendance, progress, venue, dates, status badge)
- Completion checklist for pending registrations
- Only renders if user has active edition registrations

**Section B: Online cursussen**
- Simple non-expandable cards: title, format badge (Online/E-learning/Webinar), progress bar ("3 van 8 lessen"), "Verder leren" button
- Progress from `LMSAdapter::getProgress()`
- "Verder leren" links to LearnDash course resume URL
- Only courses NOT linked to any active edition (avoids duplicates with Section A)
- Only renders if user has active online courses

**Section C: Afgerond**
- Collapsible section with count badge
- Merges completed editions AND completed online courses
- Each row: title, format badge (klassikaal/online), completion date, certificate link
- Sorted by completion date (newest first)

### LMSAdapter Interface Changes

Add 3 methods to `LMSAdapterInterface` and `LearnDashAdapter`:

```php
getEnrolledCourses(int $userId): array    // All LD course IDs
getProgress(int $userId, int $courseId): int  // 0-100 percentage
getCompletionDate(int $userId, int $courseId): ?int  // Timestamp or null
```

Brings adapter from 4 to 7 methods. Existing methods unchanged.

**Deduplication logic** lives in the template: fetch all LD enrolled courses, subtract course IDs linked to active edition registrations. Remainder = standalone online courses.

### Certificates Tab Fix

Change data source from edition-only to merged:
1. Get edition-based certificates (current logic — completed registrations)
2. Get ALL enrolled courses via `LMSAdapter::getEnrolledCourses()`
3. For each course not covered by an edition, check completion + certificate
4. Merge, deduplicate by course_id, sort by completion date

Same card design, broader data source.

## Files Changed

| File | Change |
|------|--------|
| `Contracts/LMSAdapterInterface.php` | Add 3 methods |
| `Integrations/LearnDash/LearnDashAdapter.php` | Implement 3 methods |
| `templates/dashboard/nav-sidebar.php` | Rename tab, change icon |
| `templates/dashboard/nav-mobile.php` | Rename tab, change icon |
| `templates/dashboard/tab-inschrijvingen.php` | Rebuild with 3 sections |
| `templates/dashboard/tab-certificaten.php` | Add online course certificates |

No new files. No service layer changes beyond the adapter.
