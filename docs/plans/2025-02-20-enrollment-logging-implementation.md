# Enrollment Flow Logging Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add inline logging to the enrollment and invoicing flow using NTDST_Logger for debugging.

**Architecture:** Add `ntdst_log()` calls at key milestones and error points in 5 files. Two channels: `enrollment` for registration flow, `invoicing` for quotes. INFO for success, WARNING for rejections, ERROR for failures.

**Tech Stack:** PHP 8.3, NTDST_Logger (`ntdst_log()` helper), WordPress

---

## Task 1: Add Logging to EnrollmentFormHandler

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php`

**Step 1: Add logging to handleSubmitEnrollment**

In `handleSubmitEnrollment()`, add INFO log at start and ERROR/WARNING on failure:

```php
public function handleSubmitEnrollment(array $params): array|WP_Error
{
    $userId = get_current_user_id();
    if (!$userId) {
        return new WP_Error('not_logged_in', __('Je moet ingelogd zijn om in te schrijven.', 'stride'));
    }

    $editionId = absint($params['edition_id'] ?? 0);
    if (!$editionId) {
        return new WP_Error('invalid_input', __('Geen editie opgegeven.', 'stride'));
    }

    ntdst_log('enrollment')->info('Enrollment form submitted', [
        'user_id' => $userId,
        'edition_id' => $editionId,
        'enrollment_type' => $params['enrollment_type'] ?? 'self',
    ]);

    $editions = ntdst_get(EditionService::class);
    if (!$editions->isEnrollmentOpen($editionId)) {
        return new WP_Error('enrollment_closed', __('Inschrijving is niet meer mogelijk voor deze editie.', 'stride'));
    }

    $enrollmentData = $this->sanitizeEnrollmentData($params, $userId, $editionId);

    $validation = $this->validateEnrollmentData($enrollmentData);
    if (is_wp_error($validation)) {
        ntdst_log('enrollment')->warning('Enrollment validation failed', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'error' => $validation->get_error_message(),
        ]);
        return $validation;
    }

    $enrollment = ntdst_get(EnrollmentService::class);
    $result = $enrollment->processEnrollment($enrollmentData);
    if (is_wp_error($result)) {
        ntdst_log('enrollment')->error('Enrollment submission failed', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'error' => $result->get_error_message(),
        ]);
        return $result;
    }

    return [
        'success' => true,
        'message' => __('Je inschrijving is succesvol verwerkt!', 'stride'),
        'registration_id' => $result['registration_id'] ?? null,
        'quote_id' => $result['quote_id'] ?? null,
        'redirect_url' => home_url('/mijn-account/mijn-cursussen/'),
    ];
}
```

**Step 2: Add logging to handleValidateVoucher**

In `handleValidateVoucher()`, add INFO on success, WARNING on failure:

```php
public function handleValidateVoucher(array $params): array|WP_Error
{
    $code = sanitize_text_field($params['code'] ?? '');
    $editionId = absint($params['edition_id'] ?? 0);

    if (empty($code)) {
        return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'));
    }

    $vouchers = ntdst_get(VoucherService::class);
    $editions = ntdst_get(EditionService::class);

    $validation = $vouchers->validateVoucher($code, $editionId, 0, 'edition');
    if (is_wp_error($validation)) {
        ntdst_log('enrollment')->warning('Voucher validation failed', [
            'edition_id' => $editionId,
            'code' => $code,
        ]);
        return new WP_Error('invalid_voucher', __('Vouchercode ongeldig of verlopen.', 'stride'));
    }

    $price = $editions->getPrice($editionId);
    $discount = $vouchers->calculateDiscount($validation, 'edition', $editionId, $price);

    ntdst_log('enrollment')->info('Voucher validated', [
        'edition_id' => $editionId,
        'code' => $code,
        'discount' => $discount,
    ]);

    return [
        'valid' => true,
        'discount' => $discount,
        'discount_formatted' => '€ ' . number_format($discount, 2, ',', '.'),
        'discount_type' => $validation['discount_type'],
        'message' => sprintf(__('Korting toegepast: -€ %s', 'stride'), number_format($discount, 2, ',', '.')),
    ];
}
```

**Step 3: Add logging to handleSaveSessionSelection**

In `handleSaveSessionSelection()`, add INFO on success, ERROR on failure:

```php
public function handleSaveSessionSelection(array $params): array|WP_Error
{
    $registrationId = absint($params['registration_id'] ?? 0);
    $sessionsJson = $params['sessions'] ?? '[]';
    $sessionIds = json_decode($sessionsJson, true) ?: [];

    if (!$registrationId) {
        return new WP_Error('invalid_input', __('Geen registratie opgegeven.', 'stride'));
    }

    $sessionSelection = ntdst_get(SessionSelectionService::class);
    if (!$sessionSelection) {
        return new WP_Error('service_unavailable', __('Service niet beschikbaar.', 'stride'));
    }

    $result = $sessionSelection->selectSessions($registrationId, array_map('intval', $sessionIds));
    if (is_wp_error($result)) {
        ntdst_log('enrollment')->error('Session selection failed', [
            'registration_id' => $registrationId,
            'session_ids' => $sessionIds,
            'error' => $result->get_error_message(),
        ]);
        return $result;
    }

    ntdst_log('enrollment')->info('Session selection saved', [
        'registration_id' => $registrationId,
        'session_ids' => $sessionIds,
    ]);

    return [
        'success' => true,
        'message' => __('Je sessiekeuze is opgeslagen.', 'stride'),
        'reload' => true,
    ];
}
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php
git commit -m "feat(logging): add logging to EnrollmentFormHandler"
```

---

## Task 2: Add Logging to EnrollmentService

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php`

**Step 1: Add logging to enroll() method**

Add WARNING logs for rejection cases, INFO log after successful enrollment:

```php
public function enroll(int $userId, int $editionId, array $options = []): int|WP_Error
{
    // Validate edition exists
    if (!$this->editions->exists($editionId)) {
        return new WP_Error('invalid_edition', 'Edition does not exist');
    }

    // Check enrollment allowed
    $status = $this->editions->getStatus($editionId);
    if (!$status->allowsEnrollment()) {
        if ($status === \Stride\Domain\EditionStatus::Full) {
            ntdst_log('enrollment')->warning('Enrollment rejected: edition full', [
                'user_id' => $userId,
                'edition_id' => $editionId,
            ]);
            return new WP_Error('edition_full', 'This edition is full');
        }

        ntdst_log('enrollment')->warning('Enrollment rejected: enrollment closed', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'status' => $status->value,
        ]);
        return new WP_Error('enrollment_closed', 'Enrollment is not open for this edition');
    }

    // Check capacity
    if (!$this->editions->hasAvailableSpots($editionId)) {
        ntdst_log('enrollment')->warning('Enrollment rejected: edition full', [
            'user_id' => $userId,
            'edition_id' => $editionId,
        ]);
        return new WP_Error('edition_full', 'This edition is full');
    }

    // Check not already enrolled
    if ($this->isEnrolled($userId, $editionId)) {
        ntdst_log('enrollment')->warning('Enrollment rejected: already enrolled', [
            'user_id' => $userId,
            'edition_id' => $editionId,
        ]);
        return new WP_Error('already_enrolled', 'User is already enrolled in this edition');
    }

    // Create registration
    $registrationId = $this->registrations->create([
        'user_id' => $userId,
        'edition_id' => $editionId,
        'status' => RegistrationStatus::Confirmed->value,
        'enrollment_path' => $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
        'enrolled_by' => $options['enrolled_by'] ?? null,
        'voucher_code' => $options['voucher_code'] ?? null,
        'notes' => $options['notes'] ?? null,
    ]);

    if (is_wp_error($registrationId)) {
        return $registrationId;
    }

    // Grant LMS access
    $courseId = $this->editions->getCourseId($editionId);
    if ($courseId) {
        $this->lms->grantAccess($userId, $courseId);
    }

    ntdst_log('enrollment')->info('Enrollment created', [
        'user_id' => $userId,
        'edition_id' => $editionId,
        'registration_id' => $registrationId,
        'enrollment_path' => $options['enrollment_path'] ?? RegistrationRepository::PATH_INDIVIDUAL,
    ]);

    // Fire event
    $this->dispatch('registration/created', [
        'registration_id' => $registrationId,
        'user_id' => $userId,
        'edition_id' => $editionId,
        'enrolled_by' => $options['enrolled_by'] ?? null,
    ]);

    return $registrationId;
}
```

**Step 2: Add logging to cancel() method**

Add INFO on success, ERROR on failure:

```php
public function cancel(int $registrationId): bool|WP_Error
{
    $registration = $this->registrations->find($registrationId);

    if (is_wp_error($registration)) {
        return $registration;
    }

    // Update status
    $result = $this->registrations->cancel($registrationId);

    if (!$result) {
        ntdst_log('enrollment')->error('Enrollment cancellation failed', [
            'registration_id' => $registrationId,
            'user_id' => (int) $registration->user_id,
            'edition_id' => (int) $registration->edition_id,
        ]);
        return new WP_Error('cancel_failed', 'Failed to cancel registration');
    }

    // Revoke LMS access
    $courseId = $this->editions->getCourseId((int) $registration->edition_id);
    if ($courseId) {
        $this->lms->revokeAccess((int) $registration->user_id, $courseId);
    }

    ntdst_log('enrollment')->info('Enrollment cancelled', [
        'registration_id' => $registrationId,
        'user_id' => (int) $registration->user_id,
        'edition_id' => (int) $registration->edition_id,
    ]);

    // Fire event
    $this->dispatch('registration/cancelled', [
        'registration_id' => $registrationId,
        'user_id' => (int) $registration->user_id,
        'edition_id' => (int) $registration->edition_id,
    ]);

    return true;
}
```

**Step 3: Add logging to processEnrollment() method**

Add INFO at start and when colleague user is created:

```php
public function processEnrollment(array $data): array|WP_Error
{
    $editionId = (int) ($data['edition_id'] ?? 0);
    $currentUserId = (int) ($data['user_id'] ?? 0);
    $enrollmentType = $data['enrollment_type'] ?? 'self';

    ntdst_log('enrollment')->info('Processing enrollment', [
        'user_id' => $currentUserId,
        'edition_id' => $editionId,
        'enrollment_type' => $enrollmentType,
    ]);

    // Determine participant and enrollment path
    if ($enrollmentType === 'colleague') {
        // Colleague enrollment: find or create user by email
        $participantId = $this->resolveParticipant(
            $data['email'],
            $data['first_name'],
            $data['last_name']
        );

        if (is_wp_error($participantId)) {
            return $participantId;
        }

        // Log if new user was created (ID didn't exist before)
        $existingUser = get_user_by('email', $data['email']);
        if ($existingUser && $existingUser->ID === $participantId) {
            // User existed
        } else {
            ntdst_log('enrollment')->info('Colleague user created', [
                'participant_id' => $participantId,
                'email' => $data['email'],
                'enrolled_by' => $currentUserId,
            ]);
        }

        $enrollmentPath = RegistrationRepository::PATH_COLLEAGUE;
        $enrolledBy = $currentUserId;
    } else {
        // Self enrollment
        $participantId = $currentUserId;
        $enrollmentPath = RegistrationRepository::PATH_INDIVIDUAL;
        $enrolledBy = null;

        // Update current user's profile with form data
        $this->updateUserProfile($currentUserId, $data);
    }

    // ... rest of method unchanged
```

Note: The colleague user creation logging needs adjustment. Better approach - check before calling resolveParticipant:

```php
if ($enrollmentType === 'colleague') {
    $existingUser = get_user_by('email', $data['email']);

    $participantId = $this->resolveParticipant(
        $data['email'],
        $data['first_name'],
        $data['last_name']
    );

    if (is_wp_error($participantId)) {
        return $participantId;
    }

    if (!$existingUser) {
        ntdst_log('enrollment')->info('Colleague user created', [
            'participant_id' => $participantId,
            'email' => $data['email'],
            'enrolled_by' => $currentUserId,
        ]);
    }

    $enrollmentPath = RegistrationRepository::PATH_COLLEAGUE;
    $enrolledBy = $currentUserId;
}
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php
git commit -m "feat(logging): add logging to EnrollmentService"
```

---

## Task 3: Add Logging to EnrollmentQuoteHandler

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php`

**Step 1: Add logging to onRegistrationCreated**

Add INFO/WARNING logs at key decision points:

```php
public function onRegistrationCreated(array $data): void
{
    $registrationId = $data['registration_id'] ?? 0;
    $userId = $data['user_id'] ?? 0;
    $editionId = $data['edition_id'] ?? 0;
    $enrolledBy = $data['enrolled_by'] ?? null;

    if (!$registrationId || !$userId || !$editionId) {
        return;
    }

    // For colleague enrollments, quote goes to the enrolling user (the one who pays)
    $quoteUserId = $enrolledBy ?: $userId;

    $quotes = ntdst_get(QuoteService::class);

    // Check if quote already exists
    $existing = $quotes->getQuoteByRegistration($registrationId);
    if ($existing) {
        ntdst_log('invoicing')->warning('Quote already exists for registration', [
            'registration_id' => $registrationId,
            'quote_id' => $existing['id'] ?? $existing['ID'] ?? null,
        ]);
        return;
    }

    // Get edition details
    $edition = get_post($editionId);
    if (!$edition) {
        ntdst_log('invoicing')->warning('Skipping quote: edition not found', [
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
        ]);
        return;
    }

    // Get price
    $price = $this->getEditionPrice($editionId, $userId);

    // Skip free editions
    if ($price->isZero()) {
        ntdst_log('invoicing')->info('Skipping quote: free edition', [
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'user_id' => $userId,
        ]);
        return;
    }

    // Build items array
    $items = [
        [
            'title' => $edition->post_title,
            'quantity' => 1,
            'unit_price' => $price,
            'type' => 'edition',
        ],
    ];

    // Check for pending billing from enrollment form
    $pendingBilling = $this->consumePendingBilling($quoteUserId, $editionId);
    $billing = $pendingBilling ?: $this->getUserBilling($quoteUserId);

    // Get voucher code and calculate discount if provided
    $voucherCode = $pendingBilling['voucher_code'] ?? null;
    $discount = null;

    if ($voucherCode) {
        $voucherService = ntdst_get(VoucherService::class);
        $voucher = $voucherService->validateVoucher($voucherCode, $editionId);
        if (!is_wp_error($voucher)) {
            $discount = $voucherService->calculateDiscount($voucher, $price);
        }
    }

    // Create quote for the billing user
    $quoteId = $quotes->createQuote(
        userId: $quoteUserId,
        registrationId: $registrationId,
        editionId: $editionId,
        items: $items,
        billing: $billing,
        voucherCode: $voucherCode,
        discount: $discount,
    );

    if (!is_wp_error($quoteId)) {
        ntdst_log('invoicing')->info('Quote created for registration', [
            'registration_id' => $registrationId,
            'quote_id' => $quoteId,
            'user_id' => $quoteUserId,
            'edition_id' => $editionId,
            'amount' => $price->inCents(),
        ]);
    }
}
```

**Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentQuoteHandler.php
git commit -m "feat(logging): add logging to EnrollmentQuoteHandler"
```

---

## Task 4: Add Logging to QuoteService

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php`

**Step 1: Add logging to createQuote()**

Add INFO on success, ERROR on failure:

```php
public function createQuote(
    int $userId,
    int $registrationId,
    int $editionId,
    array $items,
    array $billing = [],
    ?string $voucherCode = null,
    ?Money $discount = null,
): int|WP_Error {
    // Calculate totals
    $totals = QuoteCalculator::calculateTotals($items, $discount);

    // Generate quote number
    $quoteNumber = $this->repository->generateQuoteNumber();

    // ... existing code ...

    // Create quote
    $result = $this->repository->create([
        'title' => $title,
        'user_id' => $userId,
        'registration_id' => $registrationId,
        'edition_id' => $editionId,
        'quote_number' => $quoteNumber,
        'status' => QuoteStatus::Draft->value,
        'items' => $storedItems,
        'subtotal' => $totals['subtotal']->inCents(),
        'discount' => $totals['discount']->inCents(),
        'tax' => $totals['tax']->inCents(),
        'total' => $totals['total']->inCents(),
        'billing' => $billing,
        'voucher_code' => $voucherCode,
        'valid_until' => date('Y-m-d', strtotime('+30 days')),
    ]);

    if (is_wp_error($result)) {
        ntdst_log('invoicing')->error('Quote creation failed', [
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'edition_id' => $editionId,
            'error' => $result->get_error_message(),
        ]);
        return $result;
    }

    $quoteId = $result->ID;

    ntdst_log('invoicing')->info('Quote created', [
        'quote_id' => $quoteId,
        'quote_number' => $quoteNumber,
        'user_id' => $userId,
        'registration_id' => $registrationId,
        'total' => $totals['total']->inCents(),
    ]);

    // Fire event
    $this->dispatch('quote/created', [
        'quote_id' => $quoteId,
        'user_id' => $userId,
        'registration_id' => $registrationId,
        'edition_id' => $editionId,
        'total' => $totals['total']->inCents(),
    ]);

    return $quoteId;
}
```

**Step 2: Add logging to markAsSent()**

```php
public function markAsSent(int $quoteId): bool|WP_Error
{
    $quote = $this->repository->find($quoteId);

    if (is_wp_error($quote)) {
        return $quote;
    }

    $status = QuoteStatus::tryFrom($quote->status ?? '');

    if ($status !== QuoteStatus::Draft) {
        ntdst_log('invoicing')->warning('Cannot send: invalid status', [
            'quote_id' => $quoteId,
            'current_status' => $status?->value,
        ]);
        return new WP_Error('invalid_status', 'Only draft quotes can be sent');
    }

    $result = $this->repository->updateStatus($quoteId, QuoteStatus::Sent);

    if ($result) {
        ntdst_log('invoicing')->info('Quote marked as sent', [
            'quote_id' => $quoteId,
        ]);
        $this->dispatch('quote/sent', ['quote_id' => $quoteId]);
    }

    return $result;
}
```

**Step 3: Add logging to cancel()**

```php
public function cancel(int $quoteId): bool|WP_Error
{
    $quote = $this->repository->find($quoteId);

    if (is_wp_error($quote)) {
        return $quote;
    }

    $status = QuoteStatus::tryFrom($quote->status ?? '');

    if ($status === QuoteStatus::Exported) {
        ntdst_log('invoicing')->warning('Cannot cancel: quote exported', [
            'quote_id' => $quoteId,
        ]);
        return new WP_Error('cannot_cancel', 'Exported quotes cannot be cancelled');
    }

    $result = $this->repository->updateStatus($quoteId, QuoteStatus::Cancelled);

    if ($result) {
        ntdst_log('invoicing')->info('Quote cancelled', [
            'quote_id' => $quoteId,
        ]);
        $this->dispatch('quote/cancelled', ['quote_id' => $quoteId]);
    }

    return $result;
}
```

**Step 4: Add logging to applyVoucher()**

```php
public function applyVoucher(int $quoteId, string $voucherCode): bool|WP_Error
{
    $quote = $this->repository->find($quoteId);

    if (is_wp_error($quote)) {
        return $quote;
    }

    $meta = $quote->meta ?? [];
    $status = QuoteStatus::tryFrom($meta['status'] ?? '');

    if ($status !== QuoteStatus::Draft) {
        ntdst_log('invoicing')->warning('Voucher application failed: invalid status', [
            'quote_id' => $quoteId,
            'current_status' => $status?->value,
        ]);
        return new WP_Error('invalid_status', 'Alleen concept-offertes kunnen worden aangepast');
    }

    // Validate and get voucher through VoucherService
    $voucherService = ntdst_get(VoucherService::class);
    $editionId = (int) ($meta['edition_id'] ?? 0);
    $voucher = $voucherService->validateVoucher($voucherCode, $editionId);

    if (is_wp_error($voucher)) {
        ntdst_log('invoicing')->error('Voucher application failed', [
            'quote_id' => $quoteId,
            'voucher_code' => $voucherCode,
            'error' => $voucher->get_error_message(),
        ]);
        return $voucher;
    }

    // ... calculation code ...

    // Update quote
    $result = $this->repository->updateMeta($quoteId, [
        'voucher_code' => $voucherCode,
        'discount' => $newDiscount->inCents(),
        'tax' => $newTax->inCents(),
        'total' => $newTotal->inCents(),
    ]);

    if (!$result) {
        ntdst_log('invoicing')->error('Voucher application failed', [
            'quote_id' => $quoteId,
            'voucher_code' => $voucherCode,
            'error' => 'Failed to update quote',
        ]);
        return new WP_Error('update_failed', 'Kon offerte niet bijwerken');
    }

    // Redeem the voucher
    $userId = (int) ($meta['user_id'] ?? 0);
    $redemption = $voucherService->redeemVoucher($voucherCode, $userId, $quoteId);

    if (is_wp_error($redemption)) {
        ntdst_log('invoicing')->error('Voucher application failed', [
            'quote_id' => $quoteId,
            'voucher_code' => $voucherCode,
            'error' => $redemption->get_error_message(),
        ]);
        return $redemption;
    }

    ntdst_log('invoicing')->info('Voucher applied to quote', [
        'quote_id' => $quoteId,
        'voucher_code' => $voucherCode,
        'discount' => $newDiscount->inCents(),
    ]);

    $this->dispatch('quote/voucher_applied', [
        'quote_id' => $quoteId,
        'voucher_code' => $voucherCode,
        'discount' => $newDiscount->inCents(),
    ]);

    return true;
}
```

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Invoicing/QuoteService.php
git commit -m "feat(logging): add logging to QuoteService"
```

---

## Task 5: Add Logging to QuoteUpdateHandler

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/QuoteUpdateHandler.php`

**Step 1: Add logging to handleUpdateQuote()**

```php
public function handleUpdateQuote(mixed $data, array $params): array|WP_Error
{
    $userId = get_current_user_id();
    if (!$userId) {
        return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
    }

    $quoteId = absint($params['quote_id'] ?? 0);
    if (!$quoteId) {
        return new WP_Error('invalid_input', __('Geen offerte opgegeven.', 'stride'));
    }

    $validation = $this->validateQuoteAccess($quoteId, $userId);
    if (is_wp_error($validation)) {
        ntdst_log('invoicing')->warning('Quote update rejected: access denied', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'error' => $validation->get_error_message(),
        ]);
        return $validation;
    }

    $billing = $this->sanitizeBilling($params['billing'] ?? []);
    if (!empty($billing)) {
        $quoteRepo = ntdst_get(QuoteRepository::class);
        $quoteRepo->updateMeta($quoteId, ['billing' => $billing]);

        ntdst_log('invoicing')->info('Quote billing updated', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
        ]);
    }

    return [
        'success' => true,
        'message' => __('Offerte bijgewerkt.', 'stride'),
        'redirect_url' => home_url('/mijn-account/mijn-offertes/'),
    ];
}
```

**Step 2: Add logging to handleApplyVoucher()**

```php
public function handleApplyVoucher(mixed $data, array $params): array|WP_Error
{
    $userId = get_current_user_id();
    if (!$userId) {
        return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
    }

    $quoteId = absint($params['quote_id'] ?? 0);
    $voucherCode = sanitize_text_field($params['voucher_code'] ?? '');

    if (!$quoteId || !$voucherCode) {
        return new WP_Error('invalid_input', __('Ongeldige invoer.', 'stride'));
    }

    $validation = $this->validateQuoteAccess($quoteId, $userId);
    if (is_wp_error($validation)) {
        ntdst_log('invoicing')->warning('Voucher application rejected', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'error' => $validation->get_error_message(),
        ]);
        return $validation;
    }

    $quotes = ntdst_get(QuoteService::class);
    $result = $quotes->applyVoucher($quoteId, $voucherCode);
    if (is_wp_error($result)) {
        return $result;
    }

    ntdst_log('invoicing')->info('Voucher applied via handler', [
        'quote_id' => $quoteId,
        'user_id' => $userId,
        'voucher_code' => $voucherCode,
    ]);

    return [
        'success' => true,
        'message' => __('Voucher toegepast!', 'stride'),
    ];
}
```

**Step 3: Add logging to handleCancelQuote()**

```php
public function handleCancelQuote(mixed $data, array $params): array|WP_Error
{
    $userId = get_current_user_id();
    if (!$userId) {
        return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
    }

    $quoteId = absint($params['quote_id'] ?? 0);
    if (!$quoteId) {
        return new WP_Error('invalid_input', __('Geen offerte opgegeven.', 'stride'));
    }

    ntdst_log('invoicing')->info('Quote cancellation requested', [
        'quote_id' => $quoteId,
        'user_id' => $userId,
    ]);

    $validation = $this->validateQuoteOwnership($quoteId, $userId);
    if (is_wp_error($validation)) {
        ntdst_log('invoicing')->warning('Quote cancellation rejected', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'error' => $validation->get_error_message(),
        ]);
        return $validation;
    }

    $quoteService = ntdst_get(QuoteService::class);
    $result = $quoteService->cancel($quoteId);

    if (is_wp_error($result)) {
        ntdst_log('invoicing')->error('Quote cancellation failed', [
            'quote_id' => $quoteId,
            'user_id' => $userId,
            'error' => $result->get_error_message(),
        ]);
        return $result;
    }

    return [
        'success' => true,
        'message' => __('Inschrijving geannuleerd.', 'stride'),
        'redirect_url' => home_url('/mijn-account/mijn-offertes/'),
    ];
}
```

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/QuoteUpdateHandler.php
git commit -m "feat(logging): add logging to QuoteUpdateHandler"
```

---

## Task 6: Final Commit and Verification

**Step 1: Verify all files are committed**

```bash
git status
git log --oneline -5
```

**Step 2: Test logging works**

```bash
ddev exec wp eval "ntdst_log('enrollment')->info('Test log', ['test' => true]);"
ddev exec cat wp-content/logs/enrollment-$(date +%Y-%m-%d).log
```

**Step 3: Squash commits (optional)**

If desired, squash into single commit:

```bash
git rebase -i HEAD~5
# Mark all but first as 'squash', save
git push -u origin feature/enrollment-logging
```
