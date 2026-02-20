# Enrollment Flow Logging Design

**Date:** 2025-02-20
**Branch:** feature/enrollment-logging
**Status:** Approved

## Overview

Add comprehensive logging using NTDST_Logger to the entire enrollment flow for debugging purposes. Logs capture key milestones and errors to help trace issues when something goes wrong.

## Design Decisions

- **Approach:** Inline logging — `ntdst_log()` calls directly in service/handler methods
- **Verbosity:** Key events — errors + major milestones at INFO level
- **Channels:** Separate channels for `enrollment` and `invoicing`

## Channels & Levels

### Channels

| Channel | Files |
|---------|-------|
| `enrollment` | EnrollmentFormHandler, EnrollmentService |
| `invoicing` | EnrollmentQuoteHandler, QuoteService, QuoteUpdateHandler |

### Level Usage

| Level | When to use |
|-------|-------------|
| `info` | Successful milestones (enrollment created, quote created, voucher applied) |
| `warning` | Suspicious but non-fatal (validation failed, already enrolled, quote not editable) |
| `error` | Failures that block the operation (WP_Error returns, exceptions) |

### Context Conventions

Always include relevant IDs for traceability:

```php
[
    'user_id' => $userId,
    'edition_id' => $editionId,
    'registration_id' => $registrationId,  // when available
    'quote_id' => $quoteId,                 // when available
]
```

## Logging Specification

### EnrollmentFormHandler (channel: `enrollment`)

| Method | Level | Message |
|--------|-------|---------|
| `handleSubmitEnrollment` | info | "Enrollment form submitted" (start) |
| `handleSubmitEnrollment` | warning | "Enrollment validation failed" (on validation error) |
| `handleSubmitEnrollment` | error | "Enrollment submission failed" (on WP_Error from service) |
| `handleValidateVoucher` | info | "Voucher validated" |
| `handleValidateVoucher` | warning | "Voucher validation failed" |
| `handleSaveSessionSelection` | info | "Session selection saved" |
| `handleSaveSessionSelection` | error | "Session selection failed" |

### EnrollmentService (channel: `enrollment`)

| Method | Level | Message |
|--------|-------|---------|
| `enroll` | info | "Enrollment created" (after registration + LMS access) |
| `enroll` | warning | "Enrollment rejected: edition full" / "already enrolled" / "enrollment closed" |
| `cancel` | info | "Enrollment cancelled" |
| `cancel` | error | "Enrollment cancellation failed" |
| `processEnrollment` | info | "Processing enrollment" (start, with enrollment_type) |
| `processEnrollment` | info | "Colleague user created" (when new user created) |

### EnrollmentQuoteHandler (channel: `invoicing`)

| Method | Level | Message |
|--------|-------|---------|
| `onRegistrationCreated` | info | "Quote created for registration" |
| `onRegistrationCreated` | info | "Skipping quote: free edition" (when price is zero) |
| `onRegistrationCreated` | warning | "Quote already exists for registration" |
| `onRegistrationCreated` | warning | "Skipping quote: edition not found" |

### QuoteService (channel: `invoicing`)

| Method | Level | Message |
|--------|-------|---------|
| `createQuote` | info | "Quote created" |
| `createQuote` | error | "Quote creation failed" |
| `markAsSent` | info | "Quote marked as sent" |
| `markAsSent` | warning | "Cannot send: invalid status" |
| `cancel` | info | "Quote cancelled" |
| `cancel` | warning | "Cannot cancel: quote exported" |
| `applyVoucher` | info | "Voucher applied to quote" |
| `applyVoucher` | warning | "Voucher application failed: invalid status" |
| `applyVoucher` | error | "Voucher application failed" (voucher validation or redemption error) |

### QuoteUpdateHandler (channel: `invoicing`)

| Method | Level | Message |
|--------|-------|---------|
| `handleUpdateQuote` | info | "Quote billing updated" |
| `handleUpdateQuote` | warning | "Quote update rejected: access denied / not editable" |
| `handleApplyVoucher` | info | "Voucher applied via handler" |
| `handleApplyVoucher` | warning | "Voucher application rejected" |
| `handleCancelQuote` | info | "Quote cancellation requested" |
| `handleCancelQuote` | warning | "Quote cancellation rejected" |
| `handleCancelQuote` | error | "Quote cancellation failed" |

## Files to Modify

1. `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php`
2. `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php`
3. `web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php`
4. `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php`
5. `web/app/mu-plugins/stride-core/Handlers/QuoteUpdateHandler.php`

## Log Output

Logs will be written to:
- **File:** `wp-content/logs/enrollment-YYYY-MM-DD.log` and `wp-content/logs/invoicing-YYYY-MM-DD.log`
- **Database:** Only ERROR and CRITICAL levels (via NTDST_Logger default handlers)
- **PHP error_log:** Only ERROR and CRITICAL levels
