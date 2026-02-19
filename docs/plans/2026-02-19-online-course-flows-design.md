# Online Course User Flows - Design Document

**Date:** 2026-02-19
**Status:** Approved

## Overview

Complete the frontend user flows for online courses so users can browse, enroll, learn, manage quotes, download certificates, and update their profile.

## Key Decisions

1. **LearnDash handles course learning** - When users click "Start" or "Continue" on an online course, they go to the LearnDash course page. Stride handles enrollment and administration only.

2. **Certificates on course cards** - Download button appears directly on completed course cards in "My Courses" rather than a dedicated certificates page.

3. **PDF download placeholder** - Quote PDF download button will be added but without real PDF generation (future work).

## Scope

### 1. Quote Detail Enhancements

**File:** `templates/quote/detail.php`

Add three features to the existing quote detail page:

**A. Voucher Code Input**
- Add voucher input field below line items (for editable quotes)
- Validate via AJAX (`stride_apply_voucher_to_quote`)
- Update displayed totals on success

**B. Cancel Enrollment Button**
- Add cancel button (for editable quotes)
- Confirmation modal before cancelling
- AJAX handler updates quote status to Cancelled
- Optionally revokes LearnDash course access

**C. Download PDF Button**
- Add "Download PDF" button in actions area
- Placeholder link for now (shows "coming soon" notification)
- Will link to real PDF generation endpoint later

### 2. Certificate Download on Course Cards

**File:** `templates/dashboard/courses.php`

Add certificate download button to completed course cards:

- Check if course is complete (`$course['is_complete']`)
- Get certificate link via `CourseService::getCertificateLink($userId, $courseId)`
- If certificate exists, show download button
- Opens LearnDash certificate in new tab (PDF)

### 3. Profile Edit Form

**File:** `templates/dashboard/profile.php`

Convert read-only profile to editable form:

**Fields to include:**
- Personal: First name, Last name, Email, Phone
- Billing: Company/Organization, VAT number, Address, City, Postal code
- Preferences: Newsletter opt-in, Communication language (NL/FR/EN)

**Implementation:**
- Replace static display with form inputs
- Pre-fill with current user meta
- AJAX submission via `stride_update_profile` handler
- Save to user meta with consistent field names
- Show success/error feedback via UIkit notifications

## Backend Handlers Needed

### Existing (verify working)
- `stride_update_quote` - Update quote billing info

### New/Modified
- `stride_apply_voucher_to_quote` - Apply voucher to existing quote
- `stride_cancel_quote` - Cancel quote and optionally revoke access
- `stride_update_profile` - Update user profile fields

## File Changes Summary

| File | Change |
|------|--------|
| `templates/quote/detail.php` | Add voucher input, cancel button, PDF button |
| `templates/dashboard/courses.php` | Add certificate download button |
| `templates/dashboard/profile.php` | Convert to editable form |
| `Handlers/EnrollmentQuoteHandler.php` | Add voucher/cancel methods |
| `Handlers/ProfileHandler.php` | Add/verify profile update method |

## Out of Scope

- Real PDF generation for quotes (placeholder only)
- Custom course player (LearnDash handles this)
- Dedicated certificates page
- Email notifications for cancellations
