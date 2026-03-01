# Online Course Enrollment Flow — Design

**Date:** 2026-03-01
**Status:** Approved

## Problem

Online courses bypass Stride's enrollment infrastructure entirely. Admins manage in-person editions (registrations, quotes, status) in a unified workflow, but online courses live only in LearnDash with no admin visibility in Stride. When an online course is set to "closed" in LD, there's no way to require an enrollment form (consent, custom fields) before granting access.

## Design

### Core Concept

Every course (online or in-person) **can** have an edition (`vad_edition`) for unified admin management. The edition is the admin's workspace — registrations, enrollment status, and form configuration all live here regardless of format.

- **Edition exists** → Stride manages enrollment (form, registration tracking)
- **No edition** → pure LearnDash behavior (unchanged)

### Format Detection

The edition does NOT store its own format. It derives format from its linked LearnDash course via the `ld_course_category` taxonomy:

```php
$course_id = $edition->fields['course_id'];
$categories = get_the_terms($course_id, 'ld_course_category');
$is_online = /* check for 'online', 'webinar', 'e-learning' slugs */;
```

This is already used throughout the codebase (`single-sfwd-courses.php`, dashboard tabs, certificates). LearnDash course is the data store — the edition reads from it, never duplicates it.

### Pricing

LearnDash is the data source for pricing. A course with a price is always set to "closed" access mode in LD — the price is entered in LD course settings.

The edition **inherits** the LD price by default but can **override** it:

| Field | Source | Purpose |
|---|---|---|
| LD course `course_price` | LearnDash settings | Base price (source of truth) |
| `_ntdst_price` on edition | Edition meta (optional) | Override price for this specific edition |

Resolution: `EditionService::getPrice($editionId)` checks edition override first, falls back to LD course price.

For **online editions**, the LD price drives LearnDash's native payment flow. For **in-person editions**, the resolved price drives the Stride quote flow.

### Form Selector

All editions (online AND in-person) get a **form selector** field:

| Field | Type | Purpose |
|---|---|---|
| `_ntdst_enrollment_form` | string key or empty | Which enrollment form to render |

Available forms:

| Key | Label | Used by |
|---|---|---|
| `default` | Standaard inschrijvingsformulier | In-person editions (current 4-step form) |
| (empty) | Geen formulier | Direct enrollment, no form shown |
| (future) | Custom per-course forms | Online or specialized editions |

In-person editions default to `default`. Online editions default to empty (no form).

### Enrollment Logic

What happens when a user clicks "Enroll" depends on edition config and format:

| Format | Form selected | Price | Behavior |
|---|---|---|---|
| Online | Yes | Any | Enrollment form → registration → access |
| Online | No | Yes | LearnDash payment system (native LD) |
| Online | No | No | Direct enroll → registration recorded |
| In-person | Yes (default) | Yes | Enrollment form → registration → quote → Exact Online |
| In-person | Yes (default) | No | Enrollment form → registration (no quote) |
| Any | — | — | No edition → pure LearnDash, no Stride involvement |

**Key distinction:** Online courses use LearnDash's payment system. In-person editions use Stride's quote flow. Stride never handles online payments.

### Admin Changes

**Edition list:**
- Format filter column/tab: Online / Klassikaal / Alle
- Format derived from linked course category (not a separate field)

**Edition edit screen:**
- Form selector metabox (on all editions)
- Online editions: date/venue/session metaboxes hidden
- Online editions: read-only metabox showing relevant LD course settings (access mode, price, capacity) for context

### Frontend: Course Page CTA

In `single-sfwd-courses.php`, for a course with an online edition:

1. **Edition has form** → CTA "Inschrijven" → `/vormingen/{edition-slug}/inschrijving/`
2. **Edition no form, has LD price** → LearnDash payment buttons (existing LD behavior)
3. **Edition no form, no price** → direct enroll button, registration recorded silently
4. **No edition** → current LearnDash default behavior

### Frontend: Enrollment Form

Same route `/vormingen/{slug}/inschrijving/`, same `EnrollmentRouterService`.

The form template detects the edition's course format and renders accordingly:

- **Online edition** → simplified form:
  - Custom field groups (consent, per-course questions)
  - Confirm step
  - No billing, no type selection (self only), no session selection
  - Sidebar shows course info (no dates/venue/sessions)

- **In-person edition** → current 4-step form (unchanged):
  - Type (self/colleague), Personal, Billing, Confirm

### Backend

**No changes needed to:**
- `EnrollmentService::processEnrollment()` / `enroll()`
- `RegistrationRepository`
- `QuoteService` / `EnrollmentQuoteHandler`

**Changes needed:**
- `EditionCPT` — add `_ntdst_enrollment_form` field definition
- `EditionService` — add `isOnline(int $editionId): bool` helper (reads from course category)
- `EditionAdminController` — format filter in admin list, form selector metabox
- `EnrollmentRouterService` — pass format info to form template
- `enrollment.php` template — branch on format for simplified vs full form
- `single-sfwd-courses.php` — check for online edition in CTA logic
- Direct enrollment AJAX handler — for no-form online editions

## Out of Scope

- Online payment integration (LearnDash handles this natively)
- Auto-creation of online editions (admin creates manually)
- Cohort-based online courses (future enhancement)
