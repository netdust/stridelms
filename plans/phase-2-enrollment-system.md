# Phase 2: Enrollment System (Revised)

## Overview

Build a streamlined enrollment system that handles 4 enrollment paths, form processing, and post-enrollment workflows. Leverages existing Phase 1 services directly.

**Complexity:** MINIMAL (2 files, ~300 LOC)

---

## Architecture

### Component Structure (Simplified)

```
services/enrollment/
├── EnrollmentService.php       # Main orchestrator with inline sync logic
└── FormSubmissionHandler.php   # FluentForms → Enrollment bridge
```

**Removed from original plan:**
- ~~EnrollmentValidator.php~~ → Delegates to `CourseService::canUserEnroll()`
- ~~EnrollmentHandlerInterface.php~~ → No shared behavior to enforce
- ~~EnrollmentData.php~~ → Use documented arrays
- ~~ProfileSyncHandler.php~~ → Inline in EnrollmentService
- ~~OrganizationSyncHandler.php~~ → Inline in EnrollmentService
- ~~NotesHandler.php~~ → Inline in EnrollmentService
- ~~QuoteHandler.php~~ → Add in Phase 3
- ~~VoucherHandler.php~~ → Add in Phase 4
- ~~ManagedEnrollmentHelper.php~~ → Simple user meta, defer complex queries

### Data Flow

```
FluentForms Submission
        │
        ▼
┌─────────────────────────┐
│  FormSubmissionHandler  │  ← Parses form, detects path, finds/creates user
└───────────┬─────────────┘
            │
            ▼
┌─────────────────────────┐
│   EnrollmentService     │
│   ┌───────────────────┐ │
│   │ CourseService     │ │  ← Validation via canUserEnroll()
│   │ .canUserEnroll()  │ │
│   └───────────────────┘ │
│           │             │
│           ▼             │
│   ┌───────────────────┐ │
│   │ Sync profile &    │ │  ← Inline methods
│   │ organization      │ │
│   └───────────────────┘ │
│           │             │
│           ▼             │
│   ┌───────────────────┐ │
│   │ CourseService     │ │  ← LearnDash enrollment
│   │ .enrollUser()     │ │
│   └───────────────────┘ │
│           │             │
│           ▼             │
│   ┌───────────────────┐ │
│   │ Create CRM note   │ │  ← Inline method
│   └───────────────────┘ │
│           │             │
│           ▼             │
│   do_action('stride/    │  ← Single hook for future Phase 3/4
│   enrollment/completed')│
└─────────────────────────┘
```

---

## Implementation

### File 1: EnrollmentService.php

**File:** `services/enrollment/EnrollmentService.php`

```php
<?php
namespace stride\services\enrollment;

use stride\services\core\CourseService;
use stride\services\core\SubscriberService;
use stride\services\sync\UserDataSync;
use stride\services\sync\FieldRegistry;
use WP_Error;

class EnrollmentService implements \NTDST_Service_Meta
{
    private CourseService $courseService;
    private SubscriberService $subscriberService;
    private UserDataSync $userDataSync;

    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Service',
            'description' => 'Handles course and trajectory enrollments',
            'priority' => 10,
        ];
    }

    public function __construct(
        ?CourseService $courseService = null,
        ?SubscriberService $subscriberService = null,
        ?UserDataSync $userDataSync = null
    ) {
        $this->courseService = $courseService ?? ntdst_get(CourseService::class);
        $this->subscriberService = $subscriberService ?? ntdst_get(SubscriberService::class);
        $this->userDataSync = $userDataSync ?? ntdst_get(UserDataSync::class);
    }

    /**
     * Enroll a user in a course
     *
     * @param int $userId
     * @param int $courseId
     * @param array{
     *   first_name?: string,
     *   last_name?: string,
     *   phone?: string,
     *   profile_type?: string,
     *   department?: string,
     *   company_id?: int,
     *   invoice_org_name?: string,
     *   invoice_address?: string,
     *   invoice_city?: string,
     *   invoice_postal_code?: string,
     *   invoice_vat?: string,
     *   invoice_gln?: string,
     *   invoice_email?: string,
     *   enrolled_by_user_id?: int,
     *   enrollment_path?: string
     * } $data
     * @return true|WP_Error
     */
    public function enrollUser(int $userId, int $courseId, array $data = []): true|WP_Error
    {
        // 1. Validate via existing CourseService
        $canEnroll = $this->courseService->canUserEnroll($userId, $courseId);
        if (is_wp_error($canEnroll)) {
            return $canEnroll;
        }

        // 2. Allow pre-enrollment modification/abort
        $data = apply_filters('stride/enrollment/before_enroll', $data, $userId, $courseId);
        if (is_wp_error($data)) {
            return $data;
        }

        // 3. Sync profile fields
        $this->syncProfile($userId, $data);

        // 4. Sync organization
        $this->syncOrganization($userId, $data);

        // 5. Perform LearnDash enrollment
        $result = $this->courseService->enrollUser($userId, $courseId);
        if (is_wp_error($result)) {
            return $result;
        }

        // 6. Track manager relationship if applicable
        $this->trackManagedEnrollment($userId, $courseId, $data);

        // 7. Create CRM audit note
        $this->createEnrollmentNote($userId, $courseId, $data);

        // 8. Fire completion hook for Phase 3/4 extensions
        do_action('stride/enrollment/completed', $userId, $courseId, $data);

        return true;
    }

    /**
     * Enroll a user in a LearnDash group (trajectory)
     */
    public function enrollUserInGroup(int $userId, int $groupId, array $data = []): true|WP_Error
    {
        // Validate group exists
        $group = get_post($groupId);
        if (!$group || $group->post_type !== 'groups') {
            return new WP_Error('invalid_group', __('Ongeldig traject.', 'stride'));
        }

        // Allow pre-enrollment modification/abort
        $data = apply_filters('stride/enrollment/before_group_enroll', $data, $userId, $groupId);
        if (is_wp_error($data)) {
            return $data;
        }

        // Sync profile and organization
        $this->syncProfile($userId, $data);
        $this->syncOrganization($userId, $data);

        // Perform LearnDash group enrollment
        ld_update_group_access($userId, $groupId);

        // Track manager relationship
        $this->trackManagedEnrollment($userId, $groupId, $data, 'group');

        // Create CRM note
        $this->createGroupEnrollmentNote($userId, $groupId, $data);

        // Fire completion hook
        do_action('stride/enrollment/group_completed', $userId, $groupId, $data);

        return true;
    }

    /**
     * Unenroll a user from a course
     */
    public function unenrollUser(int $userId, int $courseId): true|WP_Error
    {
        $result = $this->courseService->unenrollUser($userId, $courseId);

        if (is_wp_error($result)) {
            return $result;
        }

        // Clean up manager tracking
        delete_user_meta($userId, "stride_enrolled_by_{$courseId}");

        do_action('stride/enrollment/unenrolled', $userId, $courseId);

        return true;
    }

    /**
     * Sync profile fields from enrollment data
     */
    private function syncProfile(int $userId, array $data): void
    {
        $fields = array_filter([
            FieldRegistry::FIELD_FIRST_NAME => $data['first_name'] ?? null,
            FieldRegistry::FIELD_LAST_NAME => $data['last_name'] ?? null,
            FieldRegistry::FIELD_PHONE => $data['phone'] ?? null,
            FieldRegistry::SUBSCRIBER_PROFILE_TYPE => $data['profile_type'] ?? null,
            FieldRegistry::SUBSCRIBER_DEPARTMENT => $data['department'] ?? null,
        ]);

        if (!empty($fields)) {
            $this->userDataSync->setFields($userId, $fields);
        }
    }

    /**
     * Sync organization data - either link to existing company or store invoice data
     */
    private function syncOrganization(int $userId, array $data): void
    {
        if (!empty($data['company_id'])) {
            // Link to existing FluentCRM company
            $this->subscriberService->linkToCompany($userId, (int) $data['company_id']);
        } elseif (!empty($data['invoice_org_name'])) {
            // Store invoice data on subscriber (new/typed organization)
            $invoiceFields = array_filter([
                FieldRegistry::SUBSCRIBER_INVOICE_ORG_NAME => $data['invoice_org_name'] ?? null,
                FieldRegistry::SUBSCRIBER_INVOICE_ADDRESS => $data['invoice_address'] ?? null,
                FieldRegistry::SUBSCRIBER_INVOICE_CITY => $data['invoice_city'] ?? null,
                FieldRegistry::SUBSCRIBER_INVOICE_POSTAL_CODE => $data['invoice_postal_code'] ?? null,
                FieldRegistry::SUBSCRIBER_VAT_NUMBER => $data['invoice_vat'] ?? null,
                FieldRegistry::SUBSCRIBER_GLN_NUMBER => $data['invoice_gln'] ?? null,
                FieldRegistry::SUBSCRIBER_INVOICE_EMAIL => $data['invoice_email'] ?? null,
            ]);

            if (!empty($invoiceFields)) {
                $this->userDataSync->setFields($userId, $invoiceFields);
            }
        }
    }

    /**
     * Track who enrolled whom (for colleague enrollments)
     */
    private function trackManagedEnrollment(int $userId, int $targetId, array $data, string $type = 'course'): void
    {
        $enrolledByUserId = $data['enrolled_by_user_id'] ?? get_current_user_id();

        // Only track if enrolled by someone else
        if ($enrolledByUserId && $enrolledByUserId !== $userId) {
            $metaKey = $type === 'group'
                ? "stride_enrolled_by_group_{$targetId}"
                : "stride_enrolled_by_{$targetId}";

            update_user_meta($userId, $metaKey, $enrolledByUserId);
        }
    }

    /**
     * Create CRM audit note for course enrollment
     */
    private function createEnrollmentNote(int $userId, int $courseId, array $data): void
    {
        $courseTitle = get_the_title($courseId);
        $note = sprintf(__('Ingeschreven voor: %s', 'stride'), $courseTitle);

        $enrolledByUserId = $data['enrolled_by_user_id'] ?? null;
        if ($enrolledByUserId && $enrolledByUserId !== $userId) {
            $manager = get_userdata($enrolledByUserId);
            $note .= sprintf(' (door %s)', $manager->user_email ?? 'onbekend');
        }

        $path = $data['enrollment_path'] ?? 'individual';
        $note .= sprintf(' [%s]', $path);

        $this->subscriberService->createNote($userId, $note);
    }

    /**
     * Create CRM audit note for group/trajectory enrollment
     */
    private function createGroupEnrollmentNote(int $userId, int $groupId, array $data): void
    {
        $groupTitle = get_the_title($groupId);
        $note = sprintf(__('Ingeschreven voor traject: %s', 'stride'), $groupTitle);

        $enrolledByUserId = $data['enrolled_by_user_id'] ?? null;
        if ($enrolledByUserId && $enrolledByUserId !== $userId) {
            $manager = get_userdata($enrolledByUserId);
            $note .= sprintf(' (door %s)', $manager->user_email ?? 'onbekend');
        }

        $this->subscriberService->createNote($userId, $note);
    }

    /**
     * Get who enrolled a user in a course (for manager tracking)
     */
    public function getEnrollingManager(int $userId, int $courseId): ?int
    {
        $managerId = get_user_meta($userId, "stride_enrolled_by_{$courseId}", true);
        return $managerId ? (int) $managerId : null;
    }

    /**
     * Check if user was enrolled by someone else
     */
    public function isManaged(int $userId, int $courseId): bool
    {
        return $this->getEnrollingManager($userId, $courseId) !== null;
    }
}
```

### File 2: FormSubmissionHandler.php

**File:** `services/enrollment/FormSubmissionHandler.php`

```php
<?php
namespace stride\services\enrollment;

use stride\services\sync\UserDataSync;
use WP_Error;

class FormSubmissionHandler implements \NTDST_Service_Meta
{
    private EnrollmentService $enrollmentService;
    private UserDataSync $userDataSync;

    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Form Handler',
            'description' => 'Handles FluentForms enrollment submissions',
            'priority' => 15,
        ];
    }

    public function __construct(
        ?EnrollmentService $enrollmentService = null,
        ?UserDataSync $userDataSync = null
    ) {
        $this->enrollmentService = $enrollmentService ?? ntdst_get(EnrollmentService::class);
        $this->userDataSync = $userDataSync ?? ntdst_get(UserDataSync::class);

        $this->register();
    }

    private function register(): void
    {
        add_action('fluentform/before_insert_submission', [$this, 'handleSubmission'], 10, 3);
    }

    /**
     * Handle FluentForms submission
     */
    public function handleSubmission($insertData, $data, $form): void
    {
        $path = $this->detectEnrollmentPath($form->id, $data);

        if (!$path) {
            return; // Not an enrollment form
        }

        $result = match ($path) {
            'individual' => $this->handleIndividual($data),
            'colleague' => $this->handleColleague($data),
            'trajectory' => $this->handleTrajectory($data),
            'interest' => $this->handleInterest($data),
            default => null,
        };

        // Store result for form confirmation message
        if (is_wp_error($result)) {
            // Log error, form will show generic error
            ntdst_log()->error('Enrollment failed', [
                'error' => $result->get_error_message(),
                'code' => $result->get_error_code(),
                'form_id' => $form->id,
            ]);
        }
    }

    /**
     * Detect enrollment path from form ID and data
     */
    private function detectEnrollmentPath(int $formId, array $data): ?string
    {
        // Check for trajectory (group_id in hidden field or URL)
        if (!empty($data['group_id'])) {
            return 'trajectory';
        }

        // Check for colleague enrollment (repeater field present)
        if (!empty($data['collegas']) || !empty($data['repeater_field_collegas'])) {
            return 'colleague';
        }

        // Check for interest form (no course_id, or specific form type)
        if (empty($data['course_id']) && empty($data['cursus_id'])) {
            // Could be interest form - check form settings or specific field
            if (!empty($data['interesse']) || !empty($data['interest_course_id'])) {
                return 'interest';
            }
            return null; // Not an enrollment form
        }

        // Default: individual enrollment
        return 'individual';
    }

    /**
     * Handle individual course enrollment
     */
    private function handleIndividual(array $formData): true|WP_Error
    {
        $courseId = $this->extractCourseId($formData);
        if (!$courseId) {
            return new WP_Error('missing_course', __('Geen cursus gevonden.', 'stride'));
        }

        // Find or create user
        $email = $this->extractEmail($formData);
        if (!$email) {
            return new WP_Error('missing_email', __('E-mailadres is verplicht.', 'stride'));
        }

        $userResult = $this->userDataSync->findOrCreateUser($email, [
            'first_name' => $formData['voornaam'] ?? $formData['first_name'] ?? '',
            'last_name' => $formData['achternaam'] ?? $formData['last_name'] ?? '',
        ]);

        if (is_wp_error($userResult)) {
            return $userResult;
        }

        $userId = $userResult['user_id'];

        // Build enrollment data from form
        $enrollmentData = $this->buildEnrollmentData($formData, 'individual');

        return $this->enrollmentService->enrollUser($userId, $courseId, $enrollmentData);
    }

    /**
     * Handle colleague/group enrollment (repeater field)
     */
    private function handleColleague(array $formData): true|WP_Error
    {
        $courseId = $this->extractCourseId($formData);
        if (!$courseId) {
            return new WP_Error('missing_course', __('Geen cursus gevonden.', 'stride'));
        }

        // Get colleagues from repeater field
        $colleagues = $formData['collegas'] ?? $formData['repeater_field_collegas'] ?? [];
        if (empty($colleagues)) {
            return new WP_Error('no_colleagues', __('Geen collega\'s opgegeven.', 'stride'));
        }

        // Validate unique emails
        $emails = array_column($colleagues, 'email');
        if (count($emails) !== count(array_unique($emails))) {
            return new WP_Error('duplicate_emails', __('Dubbele e-mailadressen gevonden.', 'stride'));
        }

        // Get manager (form submitter)
        $managerEmail = $this->extractEmail($formData);
        $managerResult = $this->userDataSync->findOrCreateUser($managerEmail, [
            'first_name' => $formData['voornaam'] ?? '',
            'last_name' => $formData['achternaam'] ?? '',
        ]);

        if (is_wp_error($managerResult)) {
            return $managerResult;
        }

        $managerId = $managerResult['user_id'];
        $errors = [];
        $successes = 0;

        // Enroll each colleague
        foreach ($colleagues as $colleague) {
            $colleagueEmail = $colleague['email'] ?? '';
            if (empty($colleagueEmail)) {
                continue;
            }

            $userResult = $this->userDataSync->findOrCreateUser($colleagueEmail, [
                'first_name' => $colleague['voornaam'] ?? $colleague['first_name'] ?? '',
                'last_name' => $colleague['achternaam'] ?? $colleague['last_name'] ?? '',
            ]);

            if (is_wp_error($userResult)) {
                $errors[] = sprintf('%s: %s', $colleagueEmail, $userResult->get_error_message());
                continue;
            }

            $enrollmentData = $this->buildEnrollmentData($formData, 'colleague');
            $enrollmentData['enrolled_by_user_id'] = $managerId;
            $enrollmentData['first_name'] = $colleague['voornaam'] ?? $colleague['first_name'] ?? '';
            $enrollmentData['last_name'] = $colleague['achternaam'] ?? $colleague['last_name'] ?? '';

            $result = $this->enrollmentService->enrollUser(
                $userResult['user_id'],
                $courseId,
                $enrollmentData
            );

            if (is_wp_error($result)) {
                $errors[] = sprintf('%s: %s', $colleagueEmail, $result->get_error_message());
            } else {
                $successes++;
            }
        }

        if ($successes === 0 && !empty($errors)) {
            return new WP_Error('all_failed', implode('; ', $errors));
        }

        return true;
    }

    /**
     * Handle trajectory enrollment
     */
    private function handleTrajectory(array $formData): true|WP_Error
    {
        $groupId = (int) ($formData['group_id'] ?? $formData['traject_id'] ?? 0);
        if (!$groupId) {
            return new WP_Error('missing_group', __('Geen traject gevonden.', 'stride'));
        }

        $email = $this->extractEmail($formData);
        if (!$email) {
            return new WP_Error('missing_email', __('E-mailadres is verplicht.', 'stride'));
        }

        $userResult = $this->userDataSync->findOrCreateUser($email, [
            'first_name' => $formData['voornaam'] ?? '',
            'last_name' => $formData['achternaam'] ?? '',
        ]);

        if (is_wp_error($userResult)) {
            return $userResult;
        }

        $enrollmentData = $this->buildEnrollmentData($formData, 'trajectory');

        return $this->enrollmentService->enrollUserInGroup(
            $userResult['user_id'],
            $groupId,
            $enrollmentData
        );
    }

    /**
     * Handle interest/waitlist form (no enrollment)
     */
    private function handleInterest(array $formData): true|WP_Error
    {
        $email = $this->extractEmail($formData);
        if (!$email) {
            return new WP_Error('missing_email', __('E-mailadres is verplicht.', 'stride'));
        }

        // Find or create user
        $userResult = $this->userDataSync->findOrCreateUser($email, [
            'first_name' => $formData['voornaam'] ?? '',
            'last_name' => $formData['achternaam'] ?? '',
        ]);

        if (is_wp_error($userResult)) {
            return $userResult;
        }

        // Fire hook for interest tracking (no enrollment)
        $courseId = $this->extractCourseId($formData) ?: ($formData['interest_course_id'] ?? 0);

        do_action('stride/enrollment/interest_registered', $userResult['user_id'], $courseId, $formData);

        return true;
    }

    /**
     * Extract course ID from form data (multiple field name conventions)
     */
    private function extractCourseId(array $formData): ?int
    {
        $courseId = $formData['course_id']
            ?? $formData['cursus_id']
            ?? $formData['vorming_id']
            ?? $formData['hidden_course_id']
            ?? null;

        return $courseId ? (int) $courseId : null;
    }

    /**
     * Extract email from form data
     */
    private function extractEmail(array $formData): ?string
    {
        return $formData['email']
            ?? $formData['e-mail']
            ?? $formData['email_address']
            ?? null;
    }

    /**
     * Build enrollment data array from form data
     */
    private function buildEnrollmentData(array $formData, string $path): array
    {
        // Parse organization field - numeric = company ID, string = new org name
        $orgField = $formData['organisations'] ?? $formData['organisatie'] ?? '';
        $companyId = is_numeric($orgField) ? (int) $orgField : null;
        $isNewOrg = !$companyId && !empty($orgField);

        return [
            'first_name' => $formData['voornaam'] ?? $formData['first_name'] ?? '',
            'last_name' => $formData['achternaam'] ?? $formData['last_name'] ?? '',
            'phone' => $formData['telefoon'] ?? $formData['phone'] ?? '',
            'profile_type' => $formData['profiel_type'] ?? $formData['profile_type'] ?? '',
            'department' => $formData['afdeling'] ?? $formData['department'] ?? '',

            // Organization
            'company_id' => $companyId,
            'invoice_org_name' => $isNewOrg ? $orgField : ($formData['facturatie_naam'] ?? ''),
            'invoice_address' => $formData['facturatie_adres'] ?? $formData['invoice_address'] ?? '',
            'invoice_city' => $formData['facturatie_gemeente'] ?? $formData['invoice_city'] ?? '',
            'invoice_postal_code' => $formData['facturatie_postcode'] ?? $formData['invoice_postal_code'] ?? '',
            'invoice_vat' => $formData['btw_nummer'] ?? $formData['vat_number'] ?? '',
            'invoice_gln' => $formData['gln_nummer'] ?? $formData['gln_number'] ?? '',
            'invoice_email' => $formData['facturatie_email'] ?? $formData['invoice_email'] ?? '',

            // Context
            'enrollment_path' => $path,
            'enrolled_by_user_id' => get_current_user_id() ?: null,
        ];
    }
}
```

---

## Enrollment Paths Detail

### Path 1: Individual Course Enrollment

**Trigger:** Form with `course_id`, no repeater field
**Flow:**
1. Extract email → `UserDataSync::findOrCreateUser()`
2. Build enrollment data from form fields
3. `EnrollmentService::enrollUser()` handles validation, sync, enrollment, note

### Path 2: Colleague/Group Enrollment

**Trigger:** Form with `collegas` repeater field
**Flow:**
1. Extract manager email → find/create manager user
2. Validate unique emails in repeater
3. Loop colleagues:
   - Find/create user per email
   - `EnrollmentService::enrollUser()` with `enrolled_by_user_id`
4. Errors collected, partial success allowed

### Path 3: Trajectory Enrollment

**Trigger:** Form with `group_id`
**Flow:**
1. Extract email → find/create user
2. `EnrollmentService::enrollUserInGroup()`
3. LearnDash auto-enrolls in associated courses

### Path 4: Interest/Waitlist Form

**Trigger:** Interest form (no course_id, has `interesse` field)
**Flow:**
1. Find/create user
2. Fire `stride/enrollment/interest_registered` hook
3. No enrollment, admin reviews later

---

## Acceptance Criteria

### Enrollment Service
- [x] Delegates validation to `CourseService::canUserEnroll()`
- [x] Returns `WP_Error` with Dutch messages on failure
- [x] Syncs profile and organization data inline
- [x] Tracks manager relationship via simple user meta
- [x] Creates CRM audit note
- [x] Fires `stride/enrollment/completed` hook for Phase 3/4

### Form Handler
- [x] Detects enrollment path from form data
- [x] Handles individual, colleague, trajectory, interest paths
- [x] Validates repeater field for duplicate emails
- [x] Creates users via `UserDataSync::findOrCreateUser()`
- [x] Maps multiple form field naming conventions

### Hooks for Future Extension
| Hook | Phase | Purpose |
|------|-------|---------|
| `stride/enrollment/before_enroll` | Filter | Modify data or abort (return WP_Error) |
| `stride/enrollment/completed` | Action | Phase 3: Quote creation |
| `stride/enrollment/group_completed` | Action | Phase 3: Trajectory quote |
| `stride/enrollment/interest_registered` | Action | Waitlist tracking |

---

## Integration Points

### Uses from Phase 1
| Service | Method | Purpose |
|---------|--------|---------|
| `CourseService` | `canUserEnroll()` | Validation |
| `CourseService` | `enrollUser()` | LearnDash enrollment |
| `CourseService` | `unenrollUser()` | Removal |
| `SubscriberService` | `linkToCompany()` | Company linking |
| `SubscriberService` | `createNote()` | CRM audit trail |
| `UserDataSync` | `findOrCreateUser()` | User creation |
| `UserDataSync` | `setFields()` | Profile sync |
| `FieldRegistry` | Constants | Field name consistency |

### FluentForms Integration
- Hook: `fluentform/before_insert_submission`
- Supports multiple field naming conventions (Dutch/English)

---

## File Creation Order

1. `services/enrollment/EnrollmentService.php` - Main orchestrator
2. `services/enrollment/FormSubmissionHandler.php` - FluentForms bridge

---

## Testing Strategy

1. **Unit test** - EnrollmentService with mocked CourseService, SubscriberService
2. **Integration test** - Full enrollment flow with real services
3. **Manual test** - FluentForms submission end-to-end

---

## Comparison: Original vs Revised

| Metric | Original | Revised |
|--------|----------|---------|
| Files | 11 | 2 |
| LOC (estimated) | ~500 | ~300 |
| Abstractions | DTO, Interface, Handlers | None (inline) |
| Validation | Duplicate of CourseService | Delegates to CourseService |
| Placeholder files | 2 (Quote, Voucher) | 0 (add in their phases) |

---

## References

- Phase 1 CourseService: `services/core/CourseService.php`
- Phase 1 SubscriberService: `services/core/SubscriberService.php`
- Phase 1 UserDataSync: `services/sync/UserDataSync.php`
- Phase 1 FieldRegistry: `services/sync/FieldRegistry.php`
