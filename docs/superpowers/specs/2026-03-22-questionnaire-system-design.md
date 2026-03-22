# Questionnaire System — Design Spec

**Date:** 2026-03-22
**Status:** Draft
**Replaces:** EnrollmentFieldGroups + FieldGroupSettingsPage

---

## Summary

A unified question system that attaches configurable questions to editions at different stages of the registration lifecycle. Replaces the enrollment-only field group system with a module that serves all stages: interest, enrollment, intake, and evaluation.

Not a form builder. A question system attached to registration flow stages.

---

## Concepts

### Stages

A stage is a moment in the registration lifecycle where questions can be shown.

| Stage | When shown | Auth required | Creates/updates registration |
|-------|-----------|---------------|------------------------------|
| `interest` | Edition exists but has no sessions | No | Creates with status `interested` |
| `enrollment_personal` | Enrollment form, personal step | Yes | Creates with status `confirmed` |
| `enrollment_billing` | Enrollment form, billing step | Yes | Same registration |
| `intake` | Dashboard, before training starts | Yes | Updates existing registration |
| `evaluation` | Dashboard, after completion | Yes | Updates existing registration |

### Field Groups

A field group is a named set of questions assigned to one or more editions, scoped to a stage.

```json
{
  "id": "fg_1",
  "label": "Evaluatie docent",
  "stage": "evaluation",
  "assignments": [123, "_all_editions"],
  "fields": [
    { "type": "description", "label": "Beoordeel de docent op de volgende punten." },
    { "type": "scale", "label": "Kennis van het onderwerp", "name": "docent_kennis", "min": 1, "max": 5, "required": true },
    { "type": "radio", "label": "Zou je deze opleiding aanraden?", "name": "recommend", "options": "Ja, Nee, Misschien", "required": true },
    { "type": "textarea", "label": "Opmerkingen", "name": "comments", "required": false }
  ]
}
```

Stored in `wp_options` as `stride_questionnaire_field_groups`.

### Field Types

| Type | Purpose | Has `name` | Stores answer | Config |
|------|---------|-----------|---------------|--------|
| `text` | Short text input | Yes | Yes | label, name, required |
| `textarea` | Long text | Yes | Yes | label, name, required |
| `select` | Dropdown | Yes | Yes | label, name, options (CSV), required |
| `radio` | Single choice, visible options | Yes | Yes | label, name, options (CSV), required |
| `checkbox` | Toggle | Yes | Yes | label, name |
| `scale` | Numeric rating (1-5 or 1-10) | Yes | Yes | label, name, min, max, required |
| `description` | Static text between questions | No | No | label (used as content) |

### Answer Storage

All answers stored on the registration record in the `enrollment_data` JSON column, keyed by stage:

```json
{
  "interest": { "dietary": "Geen" },
  "enrollment_personal": { "big_nummer": "123456" },
  "enrollment_billing": { "po_number": "PO-789" },
  "intake": { "experience": "Beginner" },
  "evaluation": { "docent_kennis": 4, "recommend": "Ja" }
}
```

Each stage submission merges its key into the existing JSON. Stages never overwrite each other.

---

## Module Structure

New module: `Modules/Questionnaire/`

```
Modules/Questionnaire/
├── QuestionnaireService.php          # Service (admin_menu, admin_init, enqueue)
├── QuestionnaireRepository.php       # Read/write field groups from wp_options
├── QuestionnaireRenderer.php         # Renders field groups to HTML
├── QuestionnaireValidator.php        # Validates answers against field definitions
└── Admin/
    └── QuestionnaireSettingsPage.php  # Card-based admin builder
```

### Class Responsibilities

**QuestionnaireService** (implements `NTDST_Service_Meta`)
- Registers admin menu page
- Enqueues admin assets
- Only class that hooks into WordPress

**QuestionnaireRepository** (plain class, DI)
- `getAllGroups(): array`
- `saveGroups(array $groups): void`
- `getGroupsForEdition(int $editionId): array`
- `getGroupsForStage(int $editionId, string $stage): array`
- `getFlatFieldsForStage(int $editionId, string $stage): array`

**QuestionnaireRenderer** (plain class, DI)
- `renderFieldGroups(array $groups, string $modelPrefix = 'form.extra_fields'): string`
- Uses template partials, returns HTML string
- Shared by all shortcodes

**QuestionnaireValidator** (plain class, DI)
- `validate(array $submittedData, int $editionId, string $stage): true|WP_Error`
- Checks required fields, type constraints

### What Gets Deleted

- `Modules/Enrollment/EnrollmentFieldGroups.php` — replaced by `QuestionnaireRepository`
- `Admin/FieldGroupSettingsPage.php` — replaced by `QuestionnaireSettingsPage`
- `assets/js/admin/field-groups.js` — rewritten for card-based UI
- `assets/css/admin/field-groups.css` — rewritten for card-based UI

### What Gets Updated

- `EnrollmentService` — uses `QuestionnaireRepository` instead of `EnrollmentFieldGroups`
- `EnrollmentFormHandler` — uses `QuestionnaireValidator` instead of inline validation
- `plugin-config.php` — register `QuestionnaireService`, remove old field group references
- Enrollment templates — update import path for field groups

---

## Admin UI — Card-Based Builder

### Page Location

Same menu position: Settings > Formuliervelden (under Stride Dashboard).

### Group Block

**Collapsed (default on page load):**
```
┌─────────────────────────────────────────────────────────┐
│ ☰  "Evaluatie docent"   [evaluation]  3 edities  ▸  ✕  │
└─────────────────────────────────────────────────────────┘
```

Shows: drag handle, label, stage badge, assignment count, expand toggle, delete.

**Expanded:**
```
┌─────────────────────────────────────────────────────────┐
│ ☰  "Evaluatie docent"   [evaluation]  3 edities  ▾  ✕  │
│─────────────────────────────────────────────────────────│
│  Label: [Evaluatie docent                       ]       │
│  Fase:  [Evaluation ▾]                                  │
│  Toegewezen aan: [Select2 multi-select          ]       │
│                                                         │
│  ┌─ field card ──────────────────────────────────┐      │
│  │ ☰  Scale   "Kennis van het onderwerp"      ✕  │      │
│  └────────────────────────────────────────────────┘      │
│  ┌─ field card ──────────────────────────────────┐      │
│  │ ☰  Radio   "Zou je aanraden?"              ✕  │      │
│  └────────────────────────────────────────────────┘      │
│                                                         │
│  + Veld toevoegen                                       │
└─────────────────────────────────────────────────────────┘
```

### Stage Dropdown Options

| Value | Label |
|-------|-------|
| `interest` | Interesse |
| `enrollment_personal` | Inschrijving — Persoonlijk |
| `enrollment_billing` | Inschrijving — Facturatie |
| `intake` | Intake (voor opleiding) |
| `evaluation` | Evaluatie (na opleiding) |

### Field Cards

**Collapsed (default):**
```
┌──────────────────────────────────────────────────┐
│ ☰  ⬤ Scale    "Kennis van het onderwerp"      ✕ │
└──────────────────────────────────────────────────┘
```

Type shown as colored pill badge. Label as preview text.

**Expanded (click to toggle):**
```
┌──────────────────────────────────────────────────┐
│ ☰  ⬤ Scale    "Kennis van het onderwerp"      ✕ │
│──────────────────────────────────────────────────│
│  Label: [Kennis van het onderwerp        ]       │
│  Name:  [docent_kennis                   ]       │
│  Schaal: [1] tot [5]         ☑ Verplicht         │
└──────────────────────────────────────────────────┘
```

New field cards open expanded. Cards collapse after initial configuration.

### Type Picker

"+ Veld toevoegen" shows a horizontal row of type options:

```
[ Tekst ] [ Tekstveld ] [ Selectie ] [ Keuze ] [ Schaal ] [ Vinkje ] [ Beschrijving ]
```

Click one → card inserted expanded with that type pre-selected.

### Type-Specific Config

| Type | Fields shown in expanded card |
|------|------------------------------|
| `text` | Label, Name, Required |
| `textarea` | Label, Name, Required |
| `select` | Label, Name, Options (CSV), Required |
| `radio` | Label, Name, Options (CSV), Required |
| `checkbox` | Label, Name |
| `scale` | Label, Name, Min (default 1), Max (default 5), Required |
| `description` | Label (textarea, used as display text) |

### Auto-Generate Name

Same behavior as current: on label blur, if name is empty, generate from label (lowercase, underscores).

### User Meta Warning

Same behavior as current: if name matches a known user meta key, show warning.

### Tech Stack

jQuery + jQuery UI Sortable. Same as current admin pages. No Alpine in WP admin.

---

## Frontend — Shortcodes

Each stage surface is a shortcode. All share the same renderer.

### Shortcode List

| Shortcode | Stage(s) | Auth | Location |
|-----------|----------|------|----------|
| `[stride_interest_form]` | `interest` | No | Edition page |
| `[stride_enrollment_form]` | `enrollment_personal`, `enrollment_billing` | Yes | Already exists |
| `[stride_intake_form]` | `intake` | Yes | Dashboard |
| `[stride_evaluation_form]` | `evaluation` | Yes | Dashboard |

### Shortcode Classes

New shortcode classes in `themes/stridence/services/frontend/shortcodes/`:

- `InterestShortcodes` — `[stride_interest_form]`
- `IntakeShortcodes` — `[stride_intake_form]`
- `EvaluationShortcodes` — `[stride_evaluation_form]`

Existing `EnrollmentShortcodes` updated to use `QuestionnaireRepository`.

All use `ShortcodeBase` trait. All call `QuestionnaireRenderer` for field output.

### Interest Form (Anonymous)

Renders:
1. Name field (hardcoded, always present)
2. Email field (hardcoded, always present)
3. Any `interest` stage field groups for the edition
4. Submit button

On submit:
1. Validate name + email + required interest fields
2. Create registration: `user_id = null`, `status = interested`
3. Store answers in `enrollment_data.interest` + name/email
4. Send `wp_mail` to admin
5. Show confirmation message

When user later enrolls in that edition: match on email, upgrade the `interested` registration (set user_id, change status, merge enrollment answers).

### Intake / Evaluation Forms

Renders:
1. Stage-specific field groups for the edition
2. Submit button

Requires existing registration. On submit:
1. Validate required fields
2. Merge stage answers into `enrollment_data`
3. Show confirmation

### Frontend Templates

```
templates/forms/fields/
├── field-group.php          # Group wrapper (existing, updated)
├── dynamic-field.php        # Type switch (existing, updated)
├── field-text.php           # text input
├── field-textarea.php       # textarea
├── field-select.php         # select dropdown
├── field-radio.php          # NEW — radio button list
├── field-checkbox.php       # checkbox
├── field-scale.php          # NEW — numbered pill row
└── field-description.php    # NEW — static paragraph
```

All fields use Alpine.js `x-model` binding to `form.extra_fields['field_name']`.

Scale renders as clickable pills with Tailwind:
```
[ 1 ] [ 2 ] [ 3 ] [④] [ 5 ]
```

Radio renders as vertical option list:
```
◉ Ja
○ Nee
○ Misschien
```

Description renders as a styled paragraph, no input.

---

## Submission — API Actions

| Action | Stage | Auth | Handler |
|--------|-------|------|---------|
| `stride_submit_interest` | interest | Public | New handler in Questionnaire module |
| `stride_submit_enrollment` | enrollment_* | Protected | Existing EnrollmentFormHandler (updated) |
| `stride_submit_intake` | intake | Protected | New handler in Questionnaire module |
| `stride_submit_evaluation` | evaluation | Protected | New handler in Questionnaire module |

Interest action added to public API actions list via `ntdst/api/public_actions` filter.

All handlers follow the same pattern:
1. Sanitize input
2. Validate via `QuestionnaireValidator`
3. Create or update registration
4. Return success/error

---

## Interest → Enrollment Upgrade Path

1. Anonymous user submits interest → registration created with `status = interested`, `user_id = null`, email stored in `enrollment_data.interest.email`
2. Admin adds sessions to edition → edition is now planned
3. Admin notifies interested users (manual action, sends email with enrollment link)
4. User creates account, visits enrollment page
5. Enrollment handler checks for existing `interested` registration matching email + edition
6. If found: upgrades registration (sets `user_id`, changes status to `confirmed`, merges enrollment answers)
7. If not found: creates new registration as normal

---

## Registration Table Changes

The table already has:
- `status` ENUM including `interest` and `pending`
- `enrollment_data` JSON column
- `user_id` — needs to allow NULL for anonymous interest

**Migration needed:**
- Make `user_id` nullable: `ALTER TABLE ... MODIFY COLUMN user_id BIGINT UNSIGNED NULL`
- Update `RegistrationTable::migrate()` with this change
- Update `RegistrationRepository::create()` to allow null `user_id` when status is `interested`

---

## What Is NOT In Scope

- Conditional logic / branching
- Field duplication in admin
- Drag between groups
- Live preview in admin
- Import/export of question sets
- Reusable templates (groups are already reusable via assignments)
- Reporting / aggregation of answers
- Automatic notification when edition gets sessions (manual for now)
