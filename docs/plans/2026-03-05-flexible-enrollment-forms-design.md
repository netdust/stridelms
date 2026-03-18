# Flexible Enrollment Forms Design

**Date:** 2026-03-05
**Status:** Approved

## Problem

Enrollment forms are currently one-size-fits-all ("default" form with type/personal/billing/confirm steps). Online courses need a lighter form (no type step, no billing, no org fields). Admins need to optionally add field groups to any form.

## Design

### Two Form Types

The edition `enrollment_form` dropdown gets a new option:

| Value | Label | Steps |
|-------|-------|-------|
| `""` | Geen (directe inschrijving) | No form |
| `minimal` | Minimaal formulier | personal, confirm |
| `default` | Standaard formulier | type, personal, billing, confirm |

- **Minimal**: naam + email + telefoon + confirm. No type step, no billing, no org fields.
- **Default**: current behavior unchanged. Type step determines org field visibility, billing step included.

### Field Groups (additive)

Field groups assigned to an edition render in their configured step:

- Groups with `step: personal` show in the personal step of both form types.
- Groups with `step: billing` show in the billing step of the default form only (minimal has no billing step).

No changes to the EnrollmentFieldGroups service or settings page. Assignment works as-is (per-edition or wildcard).

### Edition Admin UX

Below the form type dropdown, show a read-only list of active field groups for this edition (resolved from EnrollmentFieldGroups service). Links to the settings page for editing.

## Changes

1. **Edition admin dropdown** — add `minimal` option to `renderRequirementsSection()`
2. **enrollment.js** — handle `formType` config: minimal = steps [1, 3], default = steps [0, 1, 2, 3]
3. **enrollment/form.php** + **enrollment.php** — pass `form_type` through to Alpine config
4. **step-personal.php** — wrap org/afdeling block in `formType !== 'minimal'` check
5. **Edition admin sidebar** — show active field groups (read-only) below dropdown

## Not Changed

- EnrollmentFieldGroups service
- Field group rendering (field-group.php, dynamic-field.php)
- Field group settings page
- Default form conditional logic (type-dependent org fields)
- EnrollmentRouter
