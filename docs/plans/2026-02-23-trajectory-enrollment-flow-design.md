# Trajectory Enrollment Flow Design

**Date:** 2026-02-23
**Status:** Approved
**Author:** Claude + User

## Overview

Implement complete frontend trajectory enrollment flow with E2E test coverage. Users can discover trajectories, enroll via clean URLs, make elective choices post-enrollment, and receive certificates on completion.

## Requirements

1. **Clean URLs:** Enrollment at `/{post-type}/{slug}/inschrijving/`
2. **Unified form:** Extend existing enrollment form for both editions and trajectories
3. **Elective choice:** After enrollment, not during (two-step process)
4. **Auto-certificate:** Generate when all required + chosen electives complete
5. **E2E tests:** Playwright tests covering full user journey

## User Flow

```
DISCOVERY
    User browses /trajecten/ catalog
    User clicks trajectory → /trajecten/{slug}/
                ↓
TRAJECTORY DETAIL
    User sees: description, required courses, elective groups, price
    CTA: "Start dit traject" → /trajecten/{slug}/inschrijving/
                ↓
ENROLLMENT FORM (requires login)
    - Redirect to login if not authenticated
    - Show trajectory details in sidebar
    - Billing information (pre-filled from profile)
    - Optional voucher code
    - Terms acceptance
    - Submit → creates enrollment + quote
    - NOTE: No elective choice here
                ↓
CONFIRMATION
    - Success message with quote number
    - Redirect to dashboard
    - Email sent with quote PDF
                ↓
DASHBOARD - MY TRAJECTORIES
    User sees enrolled trajectories with:
    - Progress indicator (X/Y courses completed)
    - "Kies keuzecursussen" button (if choice window open)
    - Course list with completion status
                ↓
ELECTIVE CHOICE (when choice window opens)
    URL: /mijn-account/mijn-trajecten/{enrollment_id}/keuze/
    - Show elective groups
    - User selects required number per group
    - Submit → locks choices, enrolls in selected courses
                ↓
COURSE COMPLETION
    User completes courses via LearnDash
    Progress tracked automatically
                ↓
TRAJECTORY COMPLETION
    When all required + chosen electives complete:
    - Auto-generate certificate
    - Mark trajectory as completed
    - Send congratulations email
```

## Architecture

### URL Routing (no rewrite rules needed)

Using `ntdst_router()` to intercept URLs at `template_include`:

```php
// EnrollmentRouterService.php
ntdst_router()->get('trajecten/:slug/inschrijving', [$this, 'handleTrajectoryEnrollment']);
ntdst_router()->get('cursussen/:slug/inschrijving', [$this, 'handleCourseEnrollment']);
```

### API Endpoints (NTDST pattern)

Using `ntdst/api_data/*` filters instead of `wp_ajax_*`:

| Action | Purpose |
|--------|---------|
| `stride_submit_enrollment` | Unified enrollment (edition or trajectory) |
| `stride_validate_voucher` | Voucher validation (supports both types) |
| `stride_save_elective_choices` | Save elective selections |

Frontend uses `ntdstAPI.call('action', params)` instead of raw `fetch()`.

### File Changes

**Extend existing files:**

| File | Changes |
|------|---------|
| `EnrollmentFormHandler.php` | Migrate to `ntdst/api_data/*` pattern, add trajectory branch |
| `EnrollmentService.php` | Add `enrollTrajectory()` method |
| `templates/enrollment/form.php` | Unified type detection, `ntdstAPI.call()` JS |

**New files:**

| File | Purpose |
|------|---------|
| `Modules/Enrollment/EnrollmentRouterService.php` | `ntdst_router()` URL handling |
| `templates/enrollment/partials/trajectory-sidebar.php` | Trajectory-specific sidebar |
| `templates/dashboard/trajectory-choices.php` | Elective selection UI |

### Form Template Logic

```php
// Unified item detection at top of form.php
$item = $item ?? null;  // From ntdst_response()
$type = $type ?? null;

if (!$type) {
    // Fallback to query params
    $trajectoryId = absint($_GET['trajectory'] ?? 0);
    $editionId = absint($_GET['edition'] ?? 0);

    if ($trajectoryId) {
        $type = 'trajectory';
        $item = get_post($trajectoryId);
    } elseif ($editionId) {
        $type = 'edition';
        $item = $editionService->getEdition($editionId);
    }
}

// Type-specific logic
if ($type === 'trajectory') {
    $canEnroll = $trajectoryService->isEnrollmentOpen($itemId);
    $alreadyEnrolled = $trajectoryService->isUserEnrolled($userId, $itemId);
    // ...
} else {
    $canEnroll = $editionService->canEnroll($itemId);
    $alreadyEnrolled = $enrollmentService->isEnrolled($userId, $itemId);
    // ...
}
```

### JavaScript (ntdstAPI pattern)

```javascript
// Voucher validation
document.getElementById('apply-voucher')?.addEventListener('click', async function() {
    const code = document.getElementById('voucher_code').value.trim();
    if (!code) return;

    try {
        const result = await ntdstAPI.call('stride_validate_voucher', {
            code: code,
            item_id: itemId,
            item_type: itemType
        });
        showVoucherResult(true, result.message);
        updatePrices(result);
    } catch (error) {
        showVoucherResult(false, error.message);
    }
});

// Form submission
form?.addEventListener('submit', async function(e) {
    e.preventDefault();
    if (!validateForm()) return;

    try {
        const params = Object.fromEntries(new FormData(form).entries());
        params.item_id = itemId;
        params.item_type = itemType;

        const result = await ntdstAPI.call('stride_submit_enrollment', params);

        if (result.redirect_url) {
            window.location.href = result.redirect_url;
        }
    } catch (error) {
        UIkit.notification({ message: error.message, status: 'danger' });
    }
});
```

## E2E Test Coverage

**Test file:** `tests/frontend/enrollment/trajectory-enrollment.spec.ts`

### Test Scenarios

| Category | Scenario |
|----------|----------|
| **Discovery** | Browse catalog, view detail, enrollment button links correctly |
| **Auth Gate** | Unauthenticated redirect to login, login returns to enrollment |
| **Form** | Pre-filled data, voucher validation, terms required, successful submit |
| **Already Enrolled** | Shows message instead of form |
| **Elective Choice** | Access choice page, select and save electives |
| **Completion** | Completed trajectory shows certificate link |

### Test Data (seed script)

Required seed data for tests:
- `seed_student1@seed.test` - Clean user for enrollment tests
- `seed_enrolled_user@seed.test` - Already enrolled in test trajectory
- `seed_completed_user@seed.test` - Completed trajectory with certificate
- `test-trajectory` - Open trajectory with electives
- `SEEDVOUCHER10` - Valid 10% voucher

## Error Handling

| Error Case | Handling |
|------------|----------|
| Not logged in | Redirect to login with return URL |
| Already enrolled | Show message with dashboard link |
| Enrollment closed | Show closed message, hide form |
| Invalid voucher | Inline validation error |
| Choice window not open | Show "choices available from {date}" |
| Choice window passed | Choices locked, cannot modify |
| Missing required fields | Client-side + server-side validation |

## Dependencies

- `TrajectoryService` - Enrollment status, course lists, completion tracking
- `TrajectorySelectionService` - Elective choice management
- `EnrollmentService` - Core enrollment logic (extended)
- `QuoteService` - Quote generation
- `CompletionService` - Certificate generation on completion

## Out of Scope

- Interest form (`/interesse/`) - Future feature
- Group enrollment for trajectories - Not in current requirements
- Partial refunds on cancellation - Handled by Exact Online

## Decisions

1. **No rewrite rules:** `ntdst_router()` handles URL matching at `template_include`
2. **Unified form:** Single template with type branching, not separate templates
3. **NTDST API:** Use `ntdst/api_data/*` pattern, not `wp_ajax_*`
4. **Electives post-enrollment:** Two-step process, not during initial enrollment
5. **Auto-certificate:** Triggered by completion hooks, not manual
