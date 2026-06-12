# Enrollment Field Groups — Settings Page Design

**Date:** 2026-02-27
**Status:** Approved

## Problem

Enrollment field groups were stored per-course via a LearnDash metabox. This doesn't work for trajectories (different CPT, no LearnDash) and creates duplication when multiple editions need the same fields.

## Solution

Reusable field group templates managed from a central settings page under the Stride menu. Each group is defined once and assigned to any combination of editions and trajectories.

## Data Model

**Option key:** `stride_enrollment_field_groups`

```php
[
    [
        'id'          => 'fg_1',                    // Stable ID for references
        'label'       => 'Medische gegevens',
        'step'        => 'personal',                // 'personal' | 'billing'
        'assignments' => [123, 456, '_all_editions', '_all_trajectories'],
        'fields'      => [
            ['label' => 'BIG-nummer', 'name' => 'big_nummer', 'type' => 'text', 'options' => '', 'required' => true],
            ['label' => 'Specialisme', 'name' => 'specialisme', 'type' => 'select', 'options' => 'Huisarts,Specialist', 'required' => false],
        ],
    ],
]
```

Assignment values:
- Integer post IDs (edition or trajectory)
- `'_all_editions'` — applies to every edition
- `'_all_trajectories'` — applies to every trajectory

## Admin UI

**Location:** Stride > Formuliervelden (submenu page)

Single-page jQuery repeater with two levels:
1. Group level: label, step selector, assignment multi-select, delete button
2. Field level: label, name, type, options, required checkbox, delete button

Assignment widget uses Select2 multi-select with optgroups (Edities, Trajecten, Wildcards).

## Service API

`EnrollmentFieldGroupService` replaces `CourseEnrollmentFieldsService`:

- `getAllGroups(): array` — raw option data
- `getFieldGroupsForPost(int $postId, string $postType): array` — groups assigned to a post (direct + wildcard)
- `getFieldGroupsForStep(int $postId, string $postType, string $step): array` — filtered by step
- `getEnrollmentFieldsForEdition(int $editionId): array` — backward-compat flat list
- `getEnrollmentFieldsForPost(int $postId, string $postType): array` — flat list for any post

## Migration

On first load, if `_stride_enrollment_field_groups` postmeta exists on any course, auto-migrate:
- Create one field group per course's groups
- Assign to all editions linked to that course
- Clean up legacy meta

## Files Changed

| File | Action |
|------|--------|
| `Course/CourseEnrollmentFieldsService.php` | Replace → `EnrollmentFieldGroupService.php` |
| `Course/LearnDashEnrollmentFieldsMetabox.php` | Delete |
| `Admin/FieldGroupSettingsPage.php` | New — settings page + save handler |
| `assets/css/admin/field-groups.css` | New |
| `assets/js/admin/field-groups.js` | New |
| `templates/forms/enrollment.php` | Update service call |
| `plugin-config.php` | Swap service registration |
