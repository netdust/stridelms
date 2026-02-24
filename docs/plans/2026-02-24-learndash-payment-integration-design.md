# LearnDash Payment Integration for Edition Enrollments

**Date:** 2026-02-24
**Status:** Approved
**Scope:** Integrate LearnDash payment system with Stride edition enrollments

## Overview

Enable online payments for edition enrollments by leveraging LearnDash's built-in payment system (Stripe/PayPal). When a course is set to "buy now" mode in LearnDash, users must pay before gaining access.

## Key Decisions

- **Editions work with two LD modes only:** `closed` (normal quote flow) and `buy now` (payment required)
- **Payment via LearnDash:** Use LD's Registration Page redirect, not custom payment integration
- **Quote status:** Add `paid` status for payments completed online
- **100% voucher discount:** Skips payment flow entirely
- **Mollie:** Not in initial scope; requires third-party LD plugin (~€49)

## Flow Diagrams

### Course Mode: `closed` (unchanged)

```
Edition form → Submit → Registration CONFIRMED → Course access → Quote DRAFT
```

### Course Mode: `buy now` (new)

```
Edition form → Submit → Check voucher
                            ↓
        100% discount? → YES → Registration CONFIRMED → Quote PAID
                            ↓
                         NO → Registration AWAITING_PAYMENT
                            ↓
                         Redirect to /registration/?ld_register_id={course_id}
                            ↓
                         User clicks Pay → Stripe checkout
                            ↓
                         Payment SUCCESS → LD grants course access
                            ↓
                         Hook: learndash_update_course_access
                            ↓
                         Confirm registration → Create Quote PAID
```

## Registration Status Changes

### New Status: `awaiting_payment`

| Status | Meaning |
|--------|---------|
| `pending` | Form submitted, awaiting manual confirmation |
| `awaiting_payment` | Form submitted, waiting for online payment |
| `confirmed` | Enrollment confirmed (payment received or manual) |
| `cancelled` | Enrollment cancelled |

### Status Transitions

```
[form submit, closed course]     → confirmed
[form submit, buy now course]    → awaiting_payment
[100% voucher discount]          → confirmed (skip payment)
[payment success]                → awaiting_payment → confirmed
[manual cancellation]            → any → cancelled
```

## Quote Status Changes

### New Status: `paid`

| Status | Meaning |
|--------|---------|
| `draft` | Quote created, not yet sent to customer |
| `sent` | Quote sent to customer |
| `paid` | Payment received online (new) |
| `exported` | Exported to Exact Online |
| `cancelled` | Quote cancelled |

### Quote Creation by Course Mode

| Course Mode | Quote Created When | Initial Status |
|-------------|-------------------|----------------|
| `closed` | After registration confirmed | `draft` |
| `buy now` | After payment confirmed | `paid` |

## Technical Implementation

### 1. Payment Confirmation Handler (new)

Listen to LearnDash hook when course access is granted:

```php
// PaymentConfirmationHandler.php
add_action('learndash_update_course_access', function($userId, $courseId, $accessList, $remove) {
    if ($remove) return; // Access removed, not granted

    $regRepo = ntdst_get(RegistrationRepository::class);
    $registration = $regRepo->findAwaitingPaymentForCourse($userId, $courseId);

    if (!$registration) return; // Not a Stride enrollment

    // Confirm registration
    $registration->setStatus(RegistrationStatus::CONFIRMED);
    $regRepo->save($registration);

    // Create paid quote
    $quoteService = ntdst_get(QuoteService::class);
    $quoteService->createQuote($registration, QuoteStatus::PAID);

    do_action('stride/registration/payment_confirmed', $registration);
}, 10, 4);
```

### 2. Enrollment Service Changes

Check course mode and redirect to payment if needed:

```php
// In EnrollmentService::processEnrollment()

$courseId = $edition->getCourseId();
$priceType = learndash_get_course_price_type($courseId);

if ($priceType === 'paynow') {
    // Check for 100% voucher discount
    if ($this->hasFullVoucherDiscount($enrollment)) {
        // Skip payment, confirm immediately
        $registration = $this->createConfirmedRegistration($enrollment);
        $this->quoteService->createQuote($registration, QuoteStatus::PAID);
        return $this->redirectToSuccess($registration);
    }

    // Create awaiting_payment registration
    $registration = $this->createAwaitingPaymentRegistration($enrollment);

    // Redirect to LD registration page
    $registrationPageId = learndash_registration_page_get_id();
    $paymentUrl = add_query_arg('ld_register_id', $courseId, get_permalink($registrationPageId));

    return $this->redirectToPayment($paymentUrl);
}

// Normal flow for 'closed' courses
$registration = $this->createConfirmedRegistration($enrollment);
// ... existing flow
```

### 3. Registration Repository Changes

Add method to find awaiting payment registrations:

```php
// RegistrationRepository.php

public function findAwaitingPaymentForCourse(int $userId, int $courseId): ?Registration
{
    // Get all editions for this course
    $editionService = ntdst_get(EditionService::class);
    $editionIds = $editionService->getEditionIdsForCourse($courseId);

    if (empty($editionIds)) return null;

    return $this->query()
        ->where('user_id', $userId)
        ->whereIn('edition_id', $editionIds)
        ->where('status', RegistrationStatus::AWAITING_PAYMENT->value)
        ->orderBy('created_at', 'DESC')
        ->first();
}
```

### 4. Quote Service Changes

Support creating quotes with specific status:

```php
// QuoteService.php

public function createQuote(Registration $registration, QuoteStatus $status = QuoteStatus::DRAFT): Quote
{
    // ... existing quote creation logic

    $quote->setStatus($status);

    // ... save and return
}
```

### 5. EnrollmentQuoteHandler Changes

Skip automatic quote creation for awaiting_payment registrations:

```php
// EnrollmentQuoteHandler.php

public function handleRegistrationCreated(Registration $registration): void
{
    // Skip if awaiting payment - quote created after payment
    if ($registration->getStatus() === RegistrationStatus::AWAITING_PAYMENT) {
        return;
    }

    // ... existing quote creation logic
}
```

## LearnDash Configuration

1. **Registration Page:** Configure at `LearnDash > Settings > Payments > Registration Page`
2. **Stripe:** Configure at `LearnDash > Settings > Stripe`
3. **Course Price:** Set on each course under `Settings > Access Settings > Course Price Type = Buy Now`

## Edge Cases

| Scenario | Handling |
|----------|----------|
| User abandons payment | Registration stays `awaiting_payment`. Manual cleanup or future cron job. |
| User retries enrollment | Check for existing `awaiting_payment` before creating new registration |
| 100% voucher discount | Skip payment redirect, create confirmed registration + paid quote |
| Refund requested | Manual process: cancel registration, revoke access, update quote |
| Multiple editions same course | `findAwaitingPaymentForCourse` returns most recent |

## Components Changed

| Component | Change |
|-----------|--------|
| `RegistrationStatus` | Add `AWAITING_PAYMENT` |
| `RegistrationRepository` | Add `findAwaitingPaymentForCourse()` |
| `QuoteStatus` | Add `PAID` |
| `QuoteService` | Accept status parameter in `createQuote()` |
| `EnrollmentService` | Check course mode, redirect to LD payment |
| `EnrollmentQuoteHandler` | Skip quote for awaiting_payment |
| `PaymentConfirmationHandler` | New handler for LD hook |

## Out of Scope

- **Mollie integration:** Requires third-party LearnDash plugin
- **Abandoned payment cleanup:** Cron job for stale `awaiting_payment` registrations
- **Refund automation:** Manual process for now
- **Payment retry page:** Users go back to edition page to restart

## Future Enhancements

1. Add Mollie via "LearnDash Mollie Integration" plugin
2. Cron job to cancel abandoned registrations after X days
3. Payment status dashboard for admins
4. Email notifications for payment success/failure
