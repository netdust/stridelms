# Dynamic Enrollment Fields - Design Document

**Date:** 2026-02-24
**Status:** Approved

## Overview

Allow courses to define extra enrollment fields (e.g., BIG-nummer, dietary preferences) that appear dynamically in the enrollment form. Implemented as a toggleable NTDST service.

## Requirements

- **Field types:** text, textarea, select, checkbox
- **Toggle:** Service-level enable/disable via metadata
- **Storage:** Registration meta (JSON in `extra_fields` column)
- **Validation:** Required/optional toggle only

## Architecture

### Service

**Location:** `stride-core/Modules/Course/CourseEnrollmentFieldsService.php`

```php
final class CourseEnrollmentFieldsService extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Course Enrollment Fields',
            'description' => 'Adds custom enrollment fields to LearnDash courses',
            'enabled' => true,
            'priority' => 10,
        ];
    }

    protected function init(): void
    {
        add_action('add_meta_boxes', [$this, 'registerMetabox']);
        add_action('save_post_sfwd-courses', [$this, 'saveMetabox'], 10, 2);
    }

    public function getEnrollmentFields(int $courseId): array
    {
        $fields = get_post_meta($courseId, '_stride_enrollment_fields', true);
        return is_array($fields) ? $fields : [];
    }
}
```

### Admin Metabox

Uses `NTDST_MetaboxGenerator` with repeater field on `sfwd-courses`.

**Field structure per row:**

| Field | Type | Purpose |
|-------|------|---------|
| `label` | text | Field label (e.g., "BIG-nummer") |
| `name` | text | Machine name for storage |
| `type` | select | text, textarea, select, checkbox |
| `options` | text | Comma-separated (for select only) |
| `description` | textarea | Helper text explaining why needed |
| `required` | boolean | Whether field is required |

**Meta key:** `_stride_enrollment_fields`

### Frontend Integration

**Template partial:** `templates/forms/fields/dynamic-field.php`

Renders a single field based on type. Called from enrollment form after personal info section.

**Alpine integration:**
- Extra fields added to `form.extra_fields` object
- Submitted with enrollment data
- Saved to registration record

### Data Flow

```
Course Editor (Admin)
    ↓
Define fields in metabox (repeater)
    ↓
Stored in course meta (_stride_enrollment_fields)
    ↓
User visits enrollment form
    ↓
Service reads course meta via edition→course relationship
    ↓
Dynamic fields rendered in "Aanvullende informatie" section
    ↓
User submits form
    ↓
Extra fields saved to registration.extra_fields (JSON)
```

### Storage Schema

**Registration table column:** `extra_fields` (JSON)

```json
{
  "big_nummer": "12345678",
  "dietary": "vegetarisch",
  "tshirt_size": "L"
}
```

## Files to Create/Modify

### New Files

1. `stride-core/Modules/Course/CourseEnrollmentFieldsService.php` - Main service
2. `stridence/templates/forms/fields/dynamic-field.php` - Field renderer partial

### Modified Files

1. `stride-core/plugin-config.php` - Register service
2. `stridence/templates/forms/enrollment.php` - Add extra fields section
3. `stridence/src/main.js` - Update enrollmentForm component for extra_fields

## Verification

1. Create a test course with 2-3 extra fields
2. Create an edition for that course
3. Visit enrollment form - verify extra fields appear
4. Submit enrollment - verify data saved to registration
5. Disable service in metadata - verify fields don't appear
