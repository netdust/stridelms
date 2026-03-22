# Questionnaire System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the enrollment-only field group system with a unified questionnaire module that serves interest, enrollment, intake, and evaluation stages.

**Architecture:** New `Modules/Questionnaire/` module with Repository, Validator, Renderer, Service, and Admin page. Shortcodes in `functions.php` updated to use the new module. Enrollment templates updated to use stage-keyed field groups.

**Tech Stack:** PHP 8.3, WordPress, NTDST DI container, jQuery + jQuery UI Sortable (admin), Alpine.js + Tailwind (frontend)

**Spec:** `docs/superpowers/specs/2026-03-22-questionnaire-system-design.md`

---

## File Map

### New Files (Questionnaire Module)

| File | Responsibility |
|------|---------------|
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireRepository.php` | Read/write field groups from `wp_options`, query by edition/stage |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireValidator.php` | Validate submitted answers against field definitions |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireRenderer.php` | Render field groups to HTML using template partials |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireService.php` | Service (hooks: admin_menu, admin_init, enqueue_scripts) |
| `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php` | Card-based admin builder page |
| `web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder.js` | jQuery admin UI: card repeater, sortable, Select2 |
| `web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder.css` | Admin card-based builder styles |

### New Files (Frontend Templates)

| File | Responsibility |
|------|---------------|
| `web/app/themes/stridence/templates/forms/fields/field-radio.php` | Radio button list (vertical options) |
| `web/app/themes/stridence/templates/forms/fields/field-scale.php` | Numbered pill row (1-5 / 1-10) |
| `web/app/themes/stridence/templates/forms/fields/field-description.php` | Static text paragraph |
| `web/app/themes/stridence/templates/forms/interest.php` | Interest form template (anonymous) |
| `web/app/themes/stridence/templates/forms/intake.php` | Intake form template |
| `web/app/themes/stridence/templates/forms/evaluation.php` | Evaluation form template |

### New Files (Tests)

| File | Responsibility |
|------|---------------|
| `tests/Unit/Questionnaire/QuestionnaireRepositoryTest.php` | Repository unit tests |
| `tests/Unit/Questionnaire/QuestionnaireValidatorTest.php` | Validator unit tests |
| `tests/acceptance/QuestionnaireCest.php` | Acceptance tests for admin builder and frontend forms |

### Modified Files

| File | Changes |
|------|---------|
| `web/app/mu-plugins/stride-core/plugin-config.php` | Add `QuestionnaireService` to services array |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php` | Make `user_id` nullable, migrate flat `enrollment_data` |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php` | Add `findByEmailAndEdition()`, allow null `user_id` for interest |
| `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php` | Replace `EnrollmentFieldGroups` with `QuestionnaireRepository` |
| `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php` | Use `QuestionnaireValidator`, remove `stride_register_interest`, store stage-keyed data |
| `web/app/themes/stridence/templates/forms/enrollment.php` | Replace `EnrollmentFieldGroups` with `QuestionnaireRepository`, stage-keyed filtering |
| `web/app/themes/stridence/templates/forms/fields/dynamic-field.php` | Add radio, scale, description type cases |
| `web/app/themes/stridence/functions.php` | Update shortcodes: `stride_enrollment`, `stride_interest`, add `stride_intake`, `stride_evaluation` |

### Deleted Files

| File | Reason |
|------|--------|
| `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentFieldGroups.php` | Replaced by `QuestionnaireRepository` |
| `web/app/mu-plugins/stride-core/Admin/FieldGroupSettingsPage.php` | Replaced by `QuestionnaireSettingsPage` |
| `web/app/mu-plugins/stride-core/assets/js/admin/field-groups.js` | Replaced by `questionnaire-builder.js` |
| `web/app/mu-plugins/stride-core/assets/css/admin/field-groups.css` | Replaced by `questionnaire-builder.css` |

---

## Phase 1: Data Layer (Repository + Validator + DB Migration)

### Task 1: QuestionnaireRepository

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireRepository.php`
- Test: `tests/Unit/Questionnaire/QuestionnaireRepositoryTest.php`

- [ ] **Step 1: Write failing test for `getAllGroups`**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Questionnaire;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Questionnaire\QuestionnaireRepository;

class QuestionnaireRepositoryTest extends TestCase
{
    private QuestionnaireRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        // Reset option before each test
        update_option(QuestionnaireRepository::OPTION_KEY, []);
        $this->repository = new QuestionnaireRepository();
    }

    public function testGetAllGroupsReturnsEmptyArrayWhenNoGroups(): void
    {
        delete_option(QuestionnaireRepository::OPTION_KEY);
        $this->assertSame([], $this->repository->getAllGroups());
    }

    public function testSaveAndRetrieveGroups(): void
    {
        $groups = [
            [
                'id' => 'fg_1',
                'label' => 'Test Group',
                'stage' => 'evaluation',
                'assignments' => [123],
                'fields' => [
                    ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => true],
                ],
            ],
        ];

        $this->repository->saveGroups($groups);
        $this->assertSame($groups, $this->repository->getAllGroups());
    }

    public function testGetGroupsForStageFiltersCorrectly(): void
    {
        $groups = [
            ['id' => 'fg_1', 'label' => 'Eval', 'stage' => 'evaluation', 'assignments' => [10, '_all_editions'], 'fields' => []],
            ['id' => 'fg_2', 'label' => 'Intake', 'stage' => 'intake', 'assignments' => ['_all_editions'], 'fields' => []],
            ['id' => 'fg_3', 'label' => 'Other', 'stage' => 'evaluation', 'assignments' => [99], 'fields' => []],
        ];
        $this->repository->saveGroups($groups);

        // Edition 10 should match fg_1 (direct) and fg_2 (wildcard) but not fg_3
        $evalGroups = $this->repository->getGroupsForStage(10, 'evaluation');
        $this->assertCount(1, $evalGroups);
        $this->assertSame('fg_1', $evalGroups[0]['id']);

        $intakeGroups = $this->repository->getGroupsForStage(10, 'intake');
        $this->assertCount(1, $intakeGroups);
        $this->assertSame('fg_2', $intakeGroups[0]['id']);
    }

    public function testGetFlatFieldsForStage(): void
    {
        $groups = [
            [
                'id' => 'fg_1', 'label' => 'G1', 'stage' => 'enrollment_personal',
                'assignments' => ['_all_editions'],
                'fields' => [
                    ['type' => 'text', 'label' => 'A', 'name' => 'a'],
                    ['type' => 'text', 'label' => 'B', 'name' => 'b'],
                ],
            ],
            [
                'id' => 'fg_2', 'label' => 'G2', 'stage' => 'enrollment_personal',
                'assignments' => ['_all_editions'],
                'fields' => [
                    ['type' => 'text', 'label' => 'C', 'name' => 'c'],
                ],
            ],
        ];
        $this->repository->saveGroups($groups);

        $fields = $this->repository->getFlatFieldsForStage(1, 'enrollment_personal');
        $this->assertCount(3, $fields);
        $this->assertSame('a', $fields[0]['name']);
        $this->assertSame('c', $fields[2]['name']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit tests/Unit/Questionnaire/QuestionnaireRepositoryTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement QuestionnaireRepository**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

/**
 * Questionnaire field group data access.
 *
 * Plain class — manages field group definitions stored in wp_options.
 * Groups can be assigned to specific editions/trajectories or to all of a type.
 */
final class QuestionnaireRepository
{
    public const OPTION_KEY = 'stride_questionnaire_field_groups';

    /**
     * Valid stage values.
     */
    public const STAGES = [
        'interest',
        'enrollment_personal',
        'enrollment_billing',
        'intake',
        'evaluation',
    ];

    public function getAllGroups(): array
    {
        $groups = get_option(self::OPTION_KEY, []);
        return is_array($groups) ? $groups : [];
    }

    public function saveGroups(array $groups): void
    {
        update_option(self::OPTION_KEY, $groups, false);
    }

    /**
     * Get all field groups assigned to a specific edition or trajectory.
     *
     * Matches on direct post ID assignment or wildcard (_all_editions / _all_trajectories).
     */
    public function getGroupsForPost(int $postId, string $postType = ''): array
    {
        if (!$postType) {
            $postType = get_post_type($postId) ?: '';
        }

        $wildcard = match ($postType) {
            'vad_edition' => '_all_editions',
            'vad_trajectory' => '_all_trajectories',
            default => '',
        };

        $matched = [];
        foreach ($this->getAllGroups() as $group) {
            $assignments = $group['assignments'] ?? [];
            if (in_array($postId, $assignments, true) || ($wildcard && in_array($wildcard, $assignments, true))) {
                $matched[] = $group;
            }
        }

        return $matched;
    }

    /**
     * Get field groups for a specific stage, assigned to a post.
     */
    public function getGroupsForStage(int $postId, string $stage, string $postType = 'vad_edition'): array
    {
        return array_values(array_filter(
            $this->getGroupsForPost($postId, $postType),
            fn(array $group) => ($group['stage'] ?? '') === $stage,
        ));
    }

    /**
     * Flat field list for a specific stage.
     */
    public function getFlatFieldsForStage(int $postId, string $stage, string $postType = 'vad_edition'): array
    {
        $fields = [];
        foreach ($this->getGroupsForStage($postId, $stage, $postType) as $group) {
            foreach ($group['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }
        return $fields;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit tests/Unit/Questionnaire/QuestionnaireRepositoryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireRepository.php tests/Unit/Questionnaire/QuestionnaireRepositoryTest.php
git commit -m "feat(questionnaire): add QuestionnaireRepository with tests"
```

---

### Task 2: QuestionnaireValidator

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireValidator.php`
- Test: `tests/Unit/Questionnaire/QuestionnaireValidatorTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Questionnaire;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Modules\Questionnaire\QuestionnaireValidator;

class QuestionnaireValidatorTest extends TestCase
{
    private QuestionnaireValidator $validator;
    private QuestionnaireRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new QuestionnaireRepository();
        $this->validator = new QuestionnaireValidator($this->repository);
    }

    public function testValidDataPasses(): void
    {
        $this->repository->saveGroups([
            [
                'id' => 'fg_1', 'label' => 'Test', 'stage' => 'evaluation',
                'assignments' => ['_all_editions'],
                'fields' => [
                    ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => true],
                    ['type' => 'textarea', 'label' => 'Notes', 'name' => 'notes', 'required' => false],
                ],
            ],
        ]);

        $result = $this->validator->validate(['name' => 'John', 'notes' => ''], 1, 'evaluation');
        $this->assertTrue($result);
    }

    public function testMissingRequiredFieldFails(): void
    {
        $this->repository->saveGroups([
            [
                'id' => 'fg_1', 'label' => 'Test', 'stage' => 'evaluation',
                'assignments' => ['_all_editions'],
                'fields' => [
                    ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => true],
                ],
            ],
        ]);

        $result = $this->validator->validate([], 1, 'evaluation');
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testScaleOutOfRangeFails(): void
    {
        $this->repository->saveGroups([
            [
                'id' => 'fg_1', 'label' => 'Test', 'stage' => 'evaluation',
                'assignments' => ['_all_editions'],
                'fields' => [
                    ['type' => 'scale', 'label' => 'Rating', 'name' => 'rating', 'min' => 1, 'max' => 5, 'required' => true],
                ],
            ],
        ]);

        $result = $this->validator->validate(['rating' => 7], 1, 'evaluation');
        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function testScaleInRangePasses(): void
    {
        $this->repository->saveGroups([
            [
                'id' => 'fg_1', 'label' => 'Test', 'stage' => 'evaluation',
                'assignments' => ['_all_editions'],
                'fields' => [
                    ['type' => 'scale', 'label' => 'Rating', 'name' => 'rating', 'min' => 1, 'max' => 5, 'required' => true],
                ],
            ],
        ]);

        $result = $this->validator->validate(['rating' => 3], 1, 'evaluation');
        $this->assertTrue($result);
    }

    public function testDescriptionFieldIsSkipped(): void
    {
        $this->repository->saveGroups([
            [
                'id' => 'fg_1', 'label' => 'Test', 'stage' => 'evaluation',
                'assignments' => ['_all_editions'],
                'fields' => [
                    ['type' => 'description', 'label' => 'Some instructions'],
                    ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => true],
                ],
            ],
        ]);

        $result = $this->validator->validate(['name' => 'John'], 1, 'evaluation');
        $this->assertTrue($result);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit tests/Unit/Questionnaire/QuestionnaireValidatorTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Implement QuestionnaireValidator**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

use WP_Error;

/**
 * Validates submitted questionnaire answers against field definitions.
 */
final class QuestionnaireValidator
{
    public function __construct(
        private readonly QuestionnaireRepository $repository,
    ) {}

    /**
     * Validate submitted data for a stage.
     *
     * @param array<string, mixed> $data Submitted key-value pairs
     * @param int $postId Edition or trajectory ID
     * @param string $stage Stage name
     * @return true|WP_Error
     */
    public function validate(array $data, int $postId, string $stage, string $postType = 'vad_edition'): true|WP_Error
    {
        $fields = $this->repository->getFlatFieldsForStage($postId, $stage, $postType);
        $errors = [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? 'text';
            $name = $field['name'] ?? '';
            $required = !empty($field['required']);

            // Description fields have no input
            if ($type === 'description' || empty($name)) {
                continue;
            }

            $value = $data[$name] ?? null;

            // Required check
            if ($required && ($value === null || $value === '' || $value === false)) {
                $errors[] = sprintf(
                    __('%s is verplicht.', 'stride'),
                    $field['label'] ?? $name
                );
                continue;
            }

            // Skip further validation if empty and not required
            if ($value === null || $value === '' || $value === false) {
                continue;
            }

            // Type-specific validation
            if ($type === 'scale') {
                $min = (int) ($field['min'] ?? 1);
                $max = (int) ($field['max'] ?? 5);
                $intValue = (int) $value;

                if ($intValue < $min || $intValue > $max) {
                    $errors[] = sprintf(
                        __('%s moet tussen %d en %d liggen.', 'stride'),
                        $field['label'] ?? $name,
                        $min,
                        $max
                    );
                }
            }
        }

        if (!empty($errors)) {
            $error = new WP_Error('validation_failed', $errors[0]);
            for ($i = 1; $i < count($errors); $i++) {
                $error->add('validation_failed', $errors[$i]);
            }
            return $error;
        }

        return true;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `ddev exec vendor/bin/phpunit tests/Unit/Questionnaire/QuestionnaireValidatorTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireValidator.php tests/Unit/Questionnaire/QuestionnaireValidatorTest.php
git commit -m "feat(questionnaire): add QuestionnaireValidator with tests"
```

---

### Task 3: Registration Table & Repository Updates

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php:75-148` (migrate method)
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php:38-49` (create method)

- [ ] **Step 1: Add `user_id` nullable migration and `enrollment_data` stage-key migration to `RegistrationTable::migrate()`**

Add to the end of `RegistrationTable::migrate()`:

```php
// Make user_id nullable for anonymous interest registrations
$wpdb->query("ALTER TABLE {$table} MODIFY COLUMN user_id BIGINT UNSIGNED NULL");

// Migrate existing flat enrollment_data to stage-keyed format
$rows = $wpdb->get_results(
    "SELECT id, enrollment_data FROM {$table} WHERE enrollment_data IS NOT NULL AND enrollment_data != '' AND enrollment_data != 'null'"
);
foreach ($rows as $row) {
    $decoded = json_decode($row->enrollment_data, true);
    if (!is_array($decoded) || empty($decoded)) {
        continue;
    }
    // Skip if already stage-keyed (first key is a known stage)
    $firstKey = array_key_first($decoded);
    if (in_array($firstKey, ['interest', 'enrollment_personal', 'enrollment_billing', 'intake', 'evaluation'], true)) {
        continue;
    }
    // Wrap as enrollment_personal
    $wpdb->update(
        $table,
        ['enrollment_data' => wp_json_encode(['enrollment_personal' => $decoded])],
        ['id' => (int) $row->id]
    );
}
```

- [ ] **Step 2: Update `RegistrationRepository::create()` to allow null `user_id` for interest**

In `RegistrationRepository::create()`, change the `user_id` validation (around line 47-49):

```php
// Current:
if (empty($data['user_id'])) {
    return new WP_Error('missing_field', 'Required: user_id');
}

// Replace with:
$status = $data['status'] ?? 'confirmed';
if (empty($data['user_id']) && $status !== RegistrationStatus::Interest->value) {
    return new WP_Error('missing_field', 'Required: user_id (except for interest registrations)');
}
```

- [ ] **Step 3: Add `findByEmailAndEdition()` method to `RegistrationRepository`**

Add after the existing `findByUserAndEdition()` method:

```php
/**
 * Find an interest registration by email and edition.
 *
 * Searches enrollment_data JSON for interest.email match.
 */
public function findByEmailAndEdition(string $email, int $editionId): ?object
{
    global $wpdb;

    $table = $this->table();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table}
         WHERE edition_id = %d
         AND status = %s
         AND JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.interest.email')) = %s
         LIMIT 1",
        $editionId,
        RegistrationStatus::Interest->value,
        $email
    ));
}
```

- [ ] **Step 4: Run existing tests to verify nothing breaks**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationTable.php web/app/mu-plugins/stride-core/Modules/Enrollment/RegistrationRepository.php
git commit -m "feat(enrollment): allow null user_id for interest, add findByEmailAndEdition, migrate enrollment_data"
```

---

## Phase 2: Admin UI (Card-Based Builder)

### Task 4: QuestionnaireSettingsPage

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php`

- [ ] **Step 1: Create the settings page class**

Port the structure from `Admin/FieldGroupSettingsPage.php` but with these changes:
- `step` dropdown → `stage` dropdown with 5 options (interest, enrollment_personal, enrollment_billing, intake, evaluation)
- Table rows → card-based field layout (collapsible cards)
- Group blocks are collapsible (collapsed by default on load)
- Collapsed header shows: drag handle, label, stage badge, assignment count, expand toggle, delete
- Field types expanded: text, textarea, select, radio, checkbox, scale, description
- Type picker: horizontal row of type buttons instead of a dropdown in a table
- Card expanded state shows type-specific config fields
- Card collapsed state shows: drag handle, type pill badge, label preview, delete

Key method signatures:
```php
final class QuestionnaireSettingsPage
{
    private const PAGE_SLUG = 'stride-questionnaire';
    private const CAPABILITY = 'manage_options';
    private const NONCE_ACTION = 'stride_save_questionnaire';
    private const NONCE_FIELD = 'stride_questionnaire_nonce';

    public function __construct() { $this->init(); }
    private function init(): void { /* admin_menu, admin_init, admin_enqueue_scripts */ }
    public function registerPage(): void { /* submenu under stride-dashboard */ }
    public function enqueueAssets(string $hook): void { /* Select2, jQuery UI, custom JS/CSS */ }
    public function handleSave(): void { /* nonce check, sanitize, save */ }
    public function renderPage(): void { /* main page with group container */ }
    private function renderGroup($gi, array $group, array $fieldTypes, array $stages, array $assignments): void
    private function renderFieldCard($gi, $fi, array $field, array $fieldTypes): void
    private function sanitizeGroups($rawGroups): array
    private function getAssignmentOptions(): array  // Same as existing, from FieldGroupSettingsPage
    private function getUserMetaFieldNames(): array  // Same as existing
}
```

Stage dropdown options:
```php
$stages = [
    'interest' => __('Interesse', 'stride'),
    'enrollment_personal' => __('Inschrijving — Persoonlijk', 'stride'),
    'enrollment_billing' => __('Inschrijving — Facturatie', 'stride'),
    'intake' => __('Intake (voor opleiding)', 'stride'),
    'evaluation' => __('Evaluatie (na opleiding)', 'stride'),
];
```

Field types:
```php
$fieldTypes = [
    'text' => ['label' => __('Tekst', 'stride'), 'color' => '#2271b1'],
    'textarea' => ['label' => __('Tekstveld', 'stride'), 'color' => '#135e96'],
    'select' => ['label' => __('Selectie', 'stride'), 'color' => '#8c5e10'],
    'radio' => ['label' => __('Keuze', 'stride'), 'color' => '#6c3483'],
    'scale' => ['label' => __('Schaal', 'stride'), 'color' => '#0d7a3e'],
    'checkbox' => ['label' => __('Vinkje', 'stride'), 'color' => '#b26200'],
    'description' => ['label' => __('Beschrijving', 'stride'), 'color' => '#666'],
];
```

The `renderFieldCard()` method renders a card instead of a table row. The card body shows type-specific config fields:
- All types except description: Label input, Name input
- text/textarea: + Required checkbox
- select/radio: + Options CSV input, + Required checkbox
- scale: + Min input (default 1), Max input (default 5), + Required checkbox
- checkbox: (just label + name)
- description: Label becomes a textarea (content to display)

Sanitize method validates stage against `QuestionnaireRepository::STAGES` and field types against the allowed list (text, textarea, select, radio, checkbox, scale, description). For scale fields, sanitize min/max as integers.

- [ ] **Step 2: Verify page loads in browser**

Run: `ddev launch /wp/wp-admin/admin.php?page=stride-questionnaire`
Expected: Page loads, empty state with "Groep toevoegen" button

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php
git commit -m "feat(questionnaire): add card-based admin settings page"
```

---

### Task 5: Admin JS (Card Builder)

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder.js`

- [ ] **Step 1: Write the jQuery card builder**

Port from `assets/js/admin/field-groups.js` with these changes:

- Group blocks are collapsible. Click header toggles `.stride-group-body` visibility. Arrow icon toggles `▸` / `▾`.
- Collapsed group header shows: label (from input value), stage badge text, assignment count.
- Field cards replace table rows. Each card is a `div.stride-field-card` with a header and collapsible body.
- Card header click toggles body. Shows: drag handle, type pill, label preview, delete button.
- "+ Veld toevoegen" shows/hides a `.stride-type-picker` row of buttons. Click a type button → insert card expanded with that type, hide picker.
- Type-specific fields show/hide based on `data-type` attribute. Scale shows min/max inputs. Select/radio shows options input. Description shows textarea for label.
- Auto-generate name from label on blur (same as current).
- User meta warning on name field blur (same as current).
- Select2 for assignments (same as current).
- jQuery UI Sortable for both groups and cards within groups.

Template IDs used:
- `#stride-group-template` — group block HTML with `__GI__` placeholder
- `#stride-field-card-template` — card HTML with `__GI__` and `__FI__` placeholders

- [ ] **Step 2: Verify in browser: add group, add fields, reorder, save, reload**

Expected: Groups persist after save. Cards show correct type config. Reorder works.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/js/admin/questionnaire-builder.js
git commit -m "feat(questionnaire): add admin card builder JS"
```

---

### Task 6: Admin CSS (Card Styles)

**Files:**
- Create: `web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder.css`

- [ ] **Step 1: Write card-based styles**

Keep the general group block styles from `field-groups.css`. Replace table-based field styles with card styles:

- `.stride-group-block` — white card with border, collapsible
- `.stride-group-header` — flex row: drag handle, label input, stage badge (colored pill), assignment count, expand toggle, delete
- `.stride-group-body` — padding, hidden when collapsed
- `.stride-field-card` — white card within group, subtle border, margin-bottom
- `.stride-field-card-header` — flex row: drag handle, type pill (colored by type), label preview text, delete. Clickable.
- `.stride-field-card-body` — padding, hidden when collapsed. Grid layout for config fields.
- `.stride-type-picker` — horizontal flex row of type buttons, hidden by default
- `.stride-type-picker button` — pill button with type color as border/bg on hover
- `.stride-type-pill` — small colored pill showing type name in card header
- `.stride-stage-badge` — colored pill in group header showing stage label

- [ ] **Step 2: Verify visual appearance in browser**

Expected: Cards look clean, collapsing works, type pills are colored.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/css/admin/questionnaire-builder.css
git commit -m "feat(questionnaire): add admin card builder CSS"
```

---

### Task 7: QuestionnaireService + Wire Up

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireService.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`

- [ ] **Step 1: Create QuestionnaireService**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

use Stride\Infrastructure\AbstractService;
use Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage;

/**
 * Questionnaire module service.
 *
 * Bootstraps admin UI for the questionnaire builder.
 */
final class QuestionnaireService extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Questionnaire Service',
            'description' => 'Configurable questions for registration stages',
            'priority' => 12,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'questionnaire';
    }

    protected function init(): void
    {
        if (is_admin()) {
            new QuestionnaireSettingsPage();
        }
    }
}
```

- [ ] **Step 2: Register in plugin-config.php**

Add to the `services` array in `plugin-config.php`, after `EnrollmentService`:

```php
\Stride\Modules\Questionnaire\QuestionnaireService::class,
```

- [ ] **Step 3: Verify admin page loads**

Run: `ddev launch /wp/wp-admin/admin.php?page=stride-questionnaire`
Expected: Page loads under Stride Dashboard menu

- [ ] **Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireService.php web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(questionnaire): add QuestionnaireService, register in config"
```

---

### Task 8: Delete Old Field Group System

**Files:**
- Delete: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentFieldGroups.php`
- Delete: `web/app/mu-plugins/stride-core/Admin/FieldGroupSettingsPage.php`
- Delete: `web/app/mu-plugins/stride-core/assets/js/admin/field-groups.js`
- Delete: `web/app/mu-plugins/stride-core/assets/css/admin/field-groups.css`
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php` — remove `EnrollmentFieldGroups` and `FieldGroupSettingsPage` instantiation

- [ ] **Step 1: Find and remove references to `EnrollmentFieldGroups` in `EnrollmentService`**

Search `EnrollmentService.php` for `EnrollmentFieldGroups` and `FieldGroupSettingsPage` references. Remove the instantiation (likely in `init()` method). The enrollment form handler references will be updated in Phase 3.

- [ ] **Step 2: Delete the four old files**

```bash
rm web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentFieldGroups.php
rm web/app/mu-plugins/stride-core/Admin/FieldGroupSettingsPage.php
rm web/app/mu-plugins/stride-core/assets/js/admin/field-groups.js
rm web/app/mu-plugins/stride-core/assets/css/admin/field-groups.css
```

- [ ] **Step 3: Run unit tests to check for breakage**

Run: `ddev exec vendor/bin/phpunit --testsuite Unit`
Expected: PASS (or identify any tests that reference deleted classes and update them)

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "refactor(questionnaire): delete old EnrollmentFieldGroups and FieldGroupSettingsPage"
```

---

## Phase 3: Frontend Templates + Renderer

### Task 9: New Field Type Templates

**Files:**
- Create: `web/app/themes/stridence/templates/forms/fields/field-radio.php`
- Create: `web/app/themes/stridence/templates/forms/fields/field-scale.php`
- Create: `web/app/themes/stridence/templates/forms/fields/field-description.php`
- Modify: `web/app/themes/stridence/templates/forms/fields/dynamic-field.php`

- [ ] **Step 1: Create `field-radio.php`**

```php
<?php
/**
 * Radio Field Template
 *
 * @var array $field Field definition with keys: label, name, options, required
 */
$field = $args['field'] ?? $field ?? null;
if (empty($field) || empty($field['name'])) return;

$label = $field['label'] ?? '';
$name = $field['name'] ?? '';
$options = array_map('trim', explode(',', $field['options'] ?? ''));
$required = !empty($field['required']);
$inputId = 'extra_field_' . esc_attr($name);
$modelBinding = "form.extra_fields['{$name}']";
?>
<div class="stride-dynamic-field">
    <label class="input-label">
        <?= esc_html($label) ?>
        <?php if ($required) : ?><span class="text-error">*</span><?php endif; ?>
    </label>
    <div class="flex flex-col gap-2 mt-1">
        <?php foreach ($options as $i => $option) : ?>
            <label class="flex items-center gap-2 cursor-pointer text-sm">
                <input type="radio"
                       name="<?= esc_attr($inputId) ?>"
                       value="<?= esc_attr($option) ?>"
                       x-model="<?= esc_attr($modelBinding) ?>"
                       class="input-radio"
                       <?= ($required && $i === 0) ? 'required' : '' ?>>
                <span><?= esc_html($option) ?></span>
            </label>
        <?php endforeach; ?>
    </div>
</div>
```

- [ ] **Step 2: Create `field-scale.php`**

```php
<?php
/**
 * Scale Field Template — numbered pill selector
 *
 * @var array $field Field definition with keys: label, name, min, max, required
 */
$field = $args['field'] ?? $field ?? null;
if (empty($field) || empty($field['name'])) return;

$label = $field['label'] ?? '';
$name = $field['name'] ?? '';
$min = (int) ($field['min'] ?? 1);
$max = (int) ($field['max'] ?? 5);
$required = !empty($field['required']);
$modelBinding = "form.extra_fields['{$name}']";
?>
<div class="stride-dynamic-field">
    <label class="input-label">
        <?= esc_html($label) ?>
        <?php if ($required) : ?><span class="text-error">*</span><?php endif; ?>
    </label>
    <div class="flex gap-2 mt-1">
        <?php for ($i = $min; $i <= $max; $i++) : ?>
            <button type="button"
                    @click="<?= esc_attr($modelBinding) ?> = <?= $i ?>"
                    :class="<?= esc_attr($modelBinding) ?> === <?= $i ?> ? 'bg-primary text-white border-primary' : 'bg-white text-text border-border hover:border-primary'"
                    class="w-10 h-10 rounded-lg border-2 font-semibold text-sm transition-colors flex items-center justify-center">
                <?= $i ?>
            </button>
        <?php endfor; ?>
    </div>
    <?php if ($required) : ?>
        <input type="hidden" x-model="<?= esc_attr($modelBinding) ?>" <?= $required ? 'required' : '' ?>>
    <?php endif; ?>
</div>
```

- [ ] **Step 3: Create `field-description.php`**

```php
<?php
/**
 * Description Field Template — static text, no input
 *
 * @var array $field Field definition with key: label (used as content)
 */
$field = $args['field'] ?? $field ?? null;
if (empty($field)) return;

$content = $field['label'] ?? '';
if (empty($content)) return;
?>
<div class="stride-dynamic-field">
    <p class="text-sm text-text-muted leading-relaxed"><?= esc_html($content) ?></p>
</div>
```

- [ ] **Step 4: Update `dynamic-field.php` to handle new types**

Add radio, scale, and description cases. Currently the file has a switch on `$type` with `checkbox`, `textarea`, `select`, and default `text`. Add three new branches:

After the `select` elseif block and before the default `text` block, add:

```php
<?php elseif ($type === 'radio') : ?>
    <?php stridence_template_part('templates/forms/fields/field-radio', null, ['field' => $field]); ?>
    <?php return; // field-radio handles its own wrapper ?>

<?php elseif ($type === 'scale') : ?>
    <?php stridence_template_part('templates/forms/fields/field-scale', null, ['field' => $field]); ?>
    <?php return; ?>

<?php elseif ($type === 'description') : ?>
    <?php stridence_template_part('templates/forms/fields/field-description', null, ['field' => $field]); ?>
    <?php return; ?>
```

Note: Since radio/scale/description templates include their own wrapper div, use early return to avoid double-wrapping. The existing dynamic-field.php wraps everything in `<div class="stride-dynamic-field">`, so the new partials need to either: (a) not include the wrapper and fit inside the existing one, or (b) return early before the wrapper. Check the actual template structure and choose the cleanest approach.

- [ ] **Step 5: Commit**

```bash
git add web/app/themes/stridence/templates/forms/fields/
git commit -m "feat(questionnaire): add radio, scale, description field templates"
```

---

### Task 10: QuestionnaireRenderer

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireRenderer.php`

- [ ] **Step 1: Implement renderer**

```php
<?php
declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

/**
 * Renders questionnaire field groups to HTML.
 *
 * Uses theme template partials. Shared by all stage shortcodes.
 */
final class QuestionnaireRenderer
{
    /**
     * Render field groups as HTML.
     *
     * @param array $groups Field group definitions
     * @param string $modelPrefix Alpine model prefix (default: form.extra_fields)
     * @return string HTML output
     */
    public function render(array $groups, string $modelPrefix = 'form.extra_fields'): string
    {
        if (empty($groups)) {
            return '';
        }

        ob_start();
        foreach ($groups as $group) {
            stridence_template_part('templates/forms/fields/field-group', null, [
                'group' => $group,
                'model_prefix' => $modelPrefix,
            ]);
        }
        return ob_get_clean() ?: '';
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireRenderer.php
git commit -m "feat(questionnaire): add QuestionnaireRenderer"
```

---

### Task 11: Update Enrollment Template to Use QuestionnaireRepository

**Files:**
- Modify: `web/app/themes/stridence/templates/forms/enrollment.php:12-109`

- [ ] **Step 1: Replace `EnrollmentFieldGroups` with `QuestionnaireRepository`**

In `enrollment.php`:

Change the import (line 13):
```php
// Before:
use Stride\Modules\Enrollment\EnrollmentFieldGroups;
// After:
use Stride\Modules\Questionnaire\QuestionnaireRepository;
```

Change the field group fetching (lines 94-109):
```php
// Before:
$fieldsService   = ntdst_get(EnrollmentFieldGroups::class);
$field_groups    = $fieldsService->getFieldGroupsForPost($item_id, $post_type);
$personal_groups = array_values(array_filter($field_groups, fn($g) => ($g['step'] ?? 'personal') === 'personal'));
$billing_groups  = array_values(array_filter($field_groups, fn($g) => ($g['step'] ?? 'personal') === 'billing'));

// After:
$questionnaireRepo = ntdst_get(QuestionnaireRepository::class);
$personal_groups   = $questionnaireRepo->getGroupsForStage($item_id, 'enrollment_personal', $post_type);
$billing_groups    = $questionnaireRepo->getGroupsForStage($item_id, 'enrollment_billing', $post_type);
$field_groups      = array_merge($personal_groups, $billing_groups);
```

- [ ] **Step 2: Verify enrollment form still works in browser**

Run: `ddev launch /inschrijven/?editie=<some_edition_id>`
Expected: Enrollment form loads, any dynamic fields still render correctly

- [ ] **Step 3: Commit**

```bash
git add web/app/themes/stridence/templates/forms/enrollment.php
git commit -m "refactor(enrollment): use QuestionnaireRepository instead of EnrollmentFieldGroups"
```

---

## Phase 4: Update Enrollment Handler + Stage-Keyed Storage

### Task 12: Update EnrollmentFormHandler

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php`

- [ ] **Step 1: Remove `stride_register_interest` action from handler init**

In `EnrollmentFormHandler::init()` (line 35), remove:
```php
add_filter('ntdst/api_data/stride_register_interest', [$this, 'handleRegisterInterest'], 10, 2);
```

Also remove the entire `handleRegisterInterest()` method (lines 76-129).

- [ ] **Step 2: Update enrollment data storage to be stage-keyed**

In the enrollment processing methods, where `enrollment_data` is set, change from flat to stage-keyed. Find where `$enrollOptions['enrollment_data']` or `$data['enrollment_data']` is set and wrap:

```php
// Before (in EnrollmentService or handler):
$enrollOptions['enrollment_data'] = $courseFields;

// After:
$enrollOptions['enrollment_data'] = [
    'enrollment_personal' => $personalFields,
    'enrollment_billing' => $billingFields,
];
```

The exact location depends on how the current code splits personal/billing. Search for `enrollment_data` assignments in both `EnrollmentFormHandler.php` and `EnrollmentService.php`. The handler sanitizes extra_fields and passes them to the service. Update the handler to split by stage:

```php
// In handler, when building enrollment data:
$extraFields = $this->sanitizeExtraFields($params['extra_fields'] ?? []);

// Split into stages based on field group definitions
$questionnaireRepo = ntdst_get(QuestionnaireRepository::class);
$personalFieldNames = array_column(
    $questionnaireRepo->getFlatFieldsForStage($editionId, 'enrollment_personal'),
    'name'
);
$billingFieldNames = array_column(
    $questionnaireRepo->getFlatFieldsForStage($editionId, 'enrollment_billing'),
    'name'
);

$stageData = [];
foreach ($extraFields as $key => $value) {
    if (in_array($key, $personalFieldNames, true)) {
        $stageData['enrollment_personal'][$key] = $value;
    } elseif (in_array($key, $billingFieldNames, true)) {
        $stageData['enrollment_billing'][$key] = $value;
    } else {
        $stageData['enrollment_personal'][$key] = $value; // Default bucket
    }
}
```

- [ ] **Step 3: Use QuestionnaireValidator for field validation**

Replace inline extra field validation with:
```php
use Stride\Modules\Questionnaire\QuestionnaireValidator;

$validator = ntdst_get(QuestionnaireValidator::class);
$personalResult = $validator->validate(
    $stageData['enrollment_personal'] ?? [],
    $editionId,
    'enrollment_personal'
);
if (is_wp_error($personalResult)) {
    return $personalResult;
}

$billingResult = $validator->validate(
    $stageData['enrollment_billing'] ?? [],
    $editionId,
    'enrollment_billing'
);
if (is_wp_error($billingResult)) {
    return $billingResult;
}
```

- [ ] **Step 4: Run existing enrollment tests**

Run: `ddev exec vendor/bin/phpunit --filter Enrollment --testsuite Unit`
Expected: PASS (adjust any tests that reference the old structure)

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php
git commit -m "refactor(enrollment): stage-keyed enrollment_data, use QuestionnaireValidator"
```

---

## Phase 5: New Shortcodes + API Actions

### Task 13: Interest Form (Anonymous)

**Files:**
- Create: `web/app/themes/stridence/templates/forms/interest.php`
- Modify: `web/app/themes/stridence/functions.php:652-709` (stride_interest shortcode)

- [ ] **Step 1: Create interest form template**

```php
<?php
/**
 * Interest Form Template — Anonymous
 *
 * Shows name, email, and any interest-stage field groups for the edition.
 * Does NOT require login.
 *
 * @var int   $edition_id Edition ID
 * @var array $field_groups Interest stage field groups
 */
use Stride\Modules\Questionnaire\QuestionnaireRepository;

$edition_id = $args['edition_id'] ?? 0;
$edition = get_post($edition_id);
$course_id = $edition ? (int) get_post_meta($edition_id, '_ntdst_course_id', true) : 0;
$course_title = $course_id ? get_the_title($course_id) : ($edition ? $edition->post_title : '');

$questionnaireRepo = ntdst_get(QuestionnaireRepository::class);
$field_groups = $questionnaireRepo->getGroupsForStage($edition_id, 'interest');

$alpine_config = json_encode([
    'editionId' => $edition_id,
    'fieldGroups' => $field_groups,
]);
?>

<div class="container py-8 lg:py-12" x-data="strideInterestForm(<?= esc_attr($alpine_config) ?>)">
    <div class="card p-6 lg:p-8 max-w-lg mx-auto">
        <h2 class="text-xl font-bold mb-2"><?= esc_html__('Interesse melden', 'stridence') ?></h2>
        <p class="text-text-muted text-sm mb-6">
            <?= sprintf(esc_html__('Meld je interesse aan voor %s. We nemen contact op zodra er data gepland zijn.', 'stridence'), '<strong>' . esc_html($course_title) . '</strong>') ?>
        </p>

        <!-- Success state -->
        <template x-if="submitted">
            <div class="text-center py-4">
                <p class="text-success font-medium"><?= esc_html__('Bedankt! Je interesse is geregistreerd.', 'stridence') ?></p>
            </div>
        </template>

        <!-- Form -->
        <form x-show="!submitted" @submit.prevent="submit()">
            <div class="grid gap-4">
                <div>
                    <label class="input-label" for="interest_name">
                        <?= esc_html__('Naam', 'stridence') ?> <span class="text-error">*</span>
                    </label>
                    <input type="text" id="interest_name" x-model="form.name" class="input-text" required>
                </div>
                <div>
                    <label class="input-label" for="interest_email">
                        <?= esc_html__('E-mailadres', 'stridence') ?> <span class="text-error">*</span>
                    </label>
                    <input type="email" id="interest_email" x-model="form.email" class="input-text" required>
                </div>

                <?php foreach ($field_groups as $group) : ?>
                    <?php stridence_template_part('templates/forms/fields/field-group', null, ['group' => $group]); ?>
                <?php endforeach; ?>
            </div>

            <p x-show="error" x-text="error" class="text-error text-sm mt-4"></p>

            <button type="submit" class="btn btn-primary w-full mt-6" :disabled="loading">
                <span x-show="!loading"><?= esc_html__('Interesse melden', 'stridence') ?></span>
                <span x-show="loading"><?= esc_html__('Bezig...', 'stridence') ?></span>
            </button>
        </form>
    </div>
</div>

<script>
function strideInterestForm(config) {
    return {
        form: { name: '', email: '', extra_fields: {} },
        loading: false,
        submitted: false,
        error: '',
        init() {
            (config.fieldGroups || []).forEach(group => {
                (group.fields || []).forEach(field => {
                    if (field.name) {
                        this.form.extra_fields[field.name] = field.type === 'checkbox' ? false : (field.type === 'scale' ? null : '');
                    }
                });
            });
        },
        async submit() {
            this.loading = true;
            this.error = '';
            try {
                await ntdstAPI.call('stride_submit_interest', {
                    edition_id: config.editionId,
                    name: this.form.name,
                    email: this.form.email,
                    extra_fields: this.form.extra_fields,
                });
                this.submitted = true;
            } catch (e) {
                this.error = e.message || 'Er is een fout opgetreden.';
            } finally {
                this.loading = false;
            }
        }
    };
}
</script>
```

- [ ] **Step 2: Update `stride_interest` shortcode in `functions.php`**

Replace the existing `stride_interest` shortcode (lines 652-709) to use the new interest template. The key change: it now takes an `edition_id` parameter (from URL `?editie=<id>`) and renders the interest template. No login required.

```php
add_shortcode('stride_interest', function ($atts = []) {
    $edition_id = isset($_GET['editie']) ? absint($_GET['editie']) : 0;

    if (!$edition_id) {
        return stridence_render_error_state(
            'alert-circle',
            __('Geen editie geselecteerd', 'stridence'),
            __('Selecteer eerst een editie via de cursuspagina.', 'stridence'),
            __('Naar cursussen', 'stridence'),
            get_post_type_archive_link('sfwd-courses')
        );
    }

    $edition = get_post($edition_id);
    if (!$edition || $edition->post_type !== 'vad_edition') {
        return stridence_render_error_state(
            'alert-circle',
            __('Editie niet gevonden', 'stridence'),
            __('Deze editie bestaat niet of is verwijderd.', 'stridence'),
            __('Naar cursussen', 'stridence'),
            get_post_type_archive_link('sfwd-courses')
        );
    }

    ob_start();
    stridence_template_part('templates/forms/interest', null, [
        'edition_id' => $edition_id,
    ]);
    return ob_get_clean();
});
```

- [ ] **Step 3: Add `stride_submit_interest` API action**

Add to `QuestionnaireService::init()`:

```php
add_filter('ntdst/api_data/stride_submit_interest', [$this, 'handleSubmitInterest'], 10, 2);
add_filter('ntdst/api/public_actions', function (array $actions): array {
    $actions[] = 'stride_submit_interest';
    return $actions;
});
```

Add handler method to `QuestionnaireService`:

```php
public function handleSubmitInterest(mixed $data, array $params): array|WP_Error
{
    $editionId = absint($params['edition_id'] ?? 0);
    $name = sanitize_text_field($params['name'] ?? '');
    $email = sanitize_email($params['email'] ?? '');

    if (!$editionId || empty($name) || empty($email)) {
        return new WP_Error('validation_error', __('Naam, e-mailadres en editie zijn vereist.', 'stride'));
    }

    // Validate extra fields
    $extraFields = $this->sanitizeExtraFields($params['extra_fields'] ?? []);
    $validator = ntdst_get(QuestionnaireValidator::class);
    $validationResult = $validator->validate($extraFields, $editionId, 'interest');
    if (is_wp_error($validationResult)) {
        return $validationResult;
    }

    // Check for existing interest (upsert)
    $registrations = ntdst_get(RegistrationRepository::class);
    $existing = $registrations->findByEmailAndEdition($email, $editionId);

    $stageData = array_merge(['name' => $name, 'email' => $email], $extraFields);

    if ($existing) {
        // Update existing interest
        $registrations->update((int) $existing->id, [
            'enrollment_data' => wp_json_encode(['interest' => $stageData]),
        ]);
        $registrationId = (int) $existing->id;
    } else {
        // Create new interest registration
        $registrationId = $registrations->create([
            'user_id' => null,
            'edition_id' => $editionId,
            'status' => RegistrationStatus::Interest->value,
            'enrollment_path' => RegistrationRepository::PATH_INDIVIDUAL,
            'enrollment_data' => ['interest' => $stageData],
        ]);

        if (is_wp_error($registrationId)) {
            return $registrationId;
        }
    }

    // Notify admin
    $edition = get_post($editionId);
    $subject = sprintf(__('Nieuwe interesse: %s', 'stride'), $edition ? $edition->post_title : "Editie #{$editionId}");
    $message = sprintf(
        __("Naam: %s\nE-mail: %s\nEditie: %s", 'stride'),
        $name, $email, $edition ? $edition->post_title : "#{$editionId}"
    );
    wp_mail(get_option('admin_email'), $subject, $message);

    return [
        'success' => true,
        'message' => __('Je interesse is geregistreerd. We houden je op de hoogte!', 'stride'),
    ];
}

private function sanitizeExtraFields(array|string $fields): array
{
    if (is_string($fields)) {
        $fields = json_decode($fields, true) ?: [];
    }
    $sanitized = [];
    foreach ($fields as $key => $value) {
        $sanitized[sanitize_key($key)] = is_string($value) ? sanitize_text_field($value) : $value;
    }
    return $sanitized;
}
```

- [ ] **Step 4: Verify interest form in browser**

Expected: Form loads without login, submission creates interest registration, admin receives email.

- [ ] **Step 5: Commit**

```bash
git add web/app/themes/stridence/templates/forms/interest.php web/app/themes/stridence/functions.php web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireService.php
git commit -m "feat(questionnaire): add anonymous interest form with shortcode and API action"
```

---

### Task 14: Intake + Evaluation Shortcodes

**Files:**
- Create: `web/app/themes/stridence/templates/forms/intake.php`
- Create: `web/app/themes/stridence/templates/forms/evaluation.php`
- Modify: `web/app/themes/stridence/functions.php` — add `stride_intake` and `stride_evaluation` shortcodes
- Modify: `web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireService.php` — add intake/evaluation API handlers

- [ ] **Step 1: Create shared stage form template**

Both intake and evaluation follow the same pattern. Create a shared template `templates/forms/stage-form.php`:

```php
<?php
/**
 * Stage Form Template — Intake / Evaluation
 *
 * Renders field groups for a stage. Requires logged-in user with active registration.
 *
 * @var int    $edition_id Edition ID
 * @var string $stage      Stage name (intake / evaluation)
 * @var string $title      Form title
 * @var string $description Form description
 */
use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Modules\Enrollment\RegistrationRepository;

$edition_id = $args['edition_id'] ?? 0;
$stage = $args['stage'] ?? '';
$title = $args['title'] ?? '';
$description = $args['description'] ?? '';

if (!is_user_logged_in() || !$edition_id || !$stage) return;

$userId = get_current_user_id();
$registrations = ntdst_get(RegistrationRepository::class);
$registration = $registrations->findByUserAndEdition($userId, $edition_id);

if (!$registration) return;

// Check if already completed
$enrollmentData = json_decode($registration->enrollment_data ?? '{}', true) ?: [];
if (isset($enrollmentData[$stage])) {
    // Already submitted
    ?>
    <div class="card p-6 text-center">
        <p class="text-success font-medium"><?= esc_html__('Je hebt dit formulier al ingevuld. Bedankt!', 'stridence') ?></p>
    </div>
    <?php
    return;
}

$questionnaireRepo = ntdst_get(QuestionnaireRepository::class);
$field_groups = $questionnaireRepo->getGroupsForStage($edition_id, $stage);

if (empty($field_groups)) return;

$alpine_config = json_encode([
    'editionId' => $edition_id,
    'stage' => $stage,
    'fieldGroups' => $field_groups,
    'action' => 'stride_submit_' . $stage,
]);
?>

<div class="card p-6 lg:p-8" x-data="strideStageForm(<?= esc_attr($alpine_config) ?>)">
    <h3 class="text-lg font-bold mb-2"><?= esc_html($title) ?></h3>
    <?php if ($description) : ?>
        <p class="text-text-muted text-sm mb-6"><?= esc_html($description) ?></p>
    <?php endif; ?>

    <template x-if="submitted">
        <div class="text-center py-4">
            <p class="text-success font-medium"><?= esc_html__('Bedankt voor het invullen!', 'stridence') ?></p>
        </div>
    </template>

    <form x-show="!submitted" @submit.prevent="submit()">
        <div class="grid gap-4">
            <?php foreach ($field_groups as $group) : ?>
                <?php stridence_template_part('templates/forms/fields/field-group', null, ['group' => $group]); ?>
            <?php endforeach; ?>
        </div>

        <p x-show="error" x-text="error" class="text-error text-sm mt-4"></p>

        <button type="submit" class="btn btn-primary w-full mt-6" :disabled="loading">
            <span x-show="!loading"><?= esc_html__('Versturen', 'stridence') ?></span>
            <span x-show="loading"><?= esc_html__('Bezig...', 'stridence') ?></span>
        </button>
    </form>
</div>

<script>
function strideStageForm(config) {
    return {
        form: { extra_fields: {} },
        loading: false,
        submitted: false,
        error: '',
        init() {
            (config.fieldGroups || []).forEach(group => {
                (group.fields || []).forEach(field => {
                    if (field.name) {
                        this.form.extra_fields[field.name] = field.type === 'checkbox' ? false : (field.type === 'scale' ? null : '');
                    }
                });
            });
        },
        async submit() {
            this.loading = true;
            this.error = '';
            try {
                await ntdstAPI.call(config.action, {
                    edition_id: config.editionId,
                    extra_fields: this.form.extra_fields,
                });
                this.submitted = true;
            } catch (e) {
                this.error = e.message || 'Er is een fout opgetreden.';
            } finally {
                this.loading = false;
            }
        }
    };
}
</script>
```

- [ ] **Step 2: Create intake.php and evaluation.php as thin wrappers**

`templates/forms/intake.php`:
```php
<?php
stridence_template_part('templates/forms/stage-form', null, [
    'edition_id' => $args['edition_id'] ?? 0,
    'stage' => 'intake',
    'title' => __('Intake vragenlijst', 'stridence'),
    'description' => __('Vul deze vragen in voor aanvang van de opleiding.', 'stridence'),
]);
```

`templates/forms/evaluation.php`:
```php
<?php
stridence_template_part('templates/forms/stage-form', null, [
    'edition_id' => $args['edition_id'] ?? 0,
    'stage' => 'evaluation',
    'title' => __('Evaluatie', 'stridence'),
    'description' => __('Help ons verbeteren door deze evaluatie in te vullen.', 'stridence'),
]);
```

- [ ] **Step 3: Add shortcodes in `functions.php`**

```php
add_shortcode('stride_intake', function ($atts = []) {
    if (!is_user_logged_in()) return '';
    $edition_id = isset($_GET['editie']) ? absint($_GET['editie']) : 0;
    if (!$edition_id) return '';

    ob_start();
    stridence_template_part('templates/forms/intake', null, ['edition_id' => $edition_id]);
    return ob_get_clean();
});

add_shortcode('stride_evaluation', function ($atts = []) {
    if (!is_user_logged_in()) return '';
    $edition_id = isset($_GET['editie']) ? absint($_GET['editie']) : 0;
    if (!$edition_id) return '';

    ob_start();
    stridence_template_part('templates/forms/evaluation', null, ['edition_id' => $edition_id]);
    return ob_get_clean();
});
```

- [ ] **Step 4: Add API handlers in QuestionnaireService**

Register in `init()`:
```php
add_filter('ntdst/api_data/stride_submit_intake', [$this, 'handleSubmitStage'], 10, 2);
add_filter('ntdst/api_data/stride_submit_evaluation', [$this, 'handleSubmitStage'], 10, 2);
```

Handler (shared for intake/evaluation):
```php
public function handleSubmitStage(mixed $data, array $params): array|WP_Error
{
    $userId = get_current_user_id();
    if (!$userId) {
        return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
    }

    $editionId = absint($params['edition_id'] ?? 0);
    if (!$editionId) {
        return new WP_Error('invalid_input', __('Geen editie opgegeven.', 'stride'));
    }

    // Determine stage from the current filter
    $stage = str_contains(current_filter(), 'intake') ? 'intake' : 'evaluation';

    // Find existing registration
    $registrations = ntdst_get(RegistrationRepository::class);
    $registration = $registrations->findByUserAndEdition($userId, $editionId);
    if (!$registration) {
        return new WP_Error('no_registration', __('Geen inschrijving gevonden.', 'stride'));
    }

    // Validate
    $extraFields = $this->sanitizeExtraFields($params['extra_fields'] ?? []);
    $validator = ntdst_get(QuestionnaireValidator::class);
    $validationResult = $validator->validate($extraFields, $editionId, $stage);
    if (is_wp_error($validationResult)) {
        return $validationResult;
    }

    // Merge stage data into enrollment_data
    $existingData = json_decode($registration->enrollment_data ?? '{}', true) ?: [];
    $existingData[$stage] = $extraFields;

    $registrations->update((int) $registration->id, [
        'enrollment_data' => wp_json_encode($existingData),
    ]);

    return [
        'success' => true,
        'message' => __('Bedankt voor het invullen!', 'stride'),
    ];
}
```

- [ ] **Step 5: Commit**

```bash
git add web/app/themes/stridence/templates/forms/stage-form.php web/app/themes/stridence/templates/forms/intake.php web/app/themes/stridence/templates/forms/evaluation.php web/app/themes/stridence/functions.php web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireService.php
git commit -m "feat(questionnaire): add intake and evaluation shortcodes with shared stage form"
```

---

### Task 15: Interest → Enrollment Upgrade Path

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php`

- [ ] **Step 1: Add interest upgrade logic to the enrollment flow**

In `EnrollmentService::enroll()` (or wherever the registration is created during enrollment), before creating a new registration, check for an existing interest:

```php
// Check for existing interest registration to upgrade
$userEmail = $user->user_email ?? '';
if ($userEmail) {
    $existingInterest = $this->registrations->findByEmailAndEdition($userEmail, $editionId);
    if ($existingInterest && $existingInterest->status === RegistrationStatus::Interest->value) {
        // Upgrade: set user_id, merge enrollment data
        $existingData = json_decode($existingInterest->enrollment_data ?? '{}', true) ?: [];
        $existingData = array_merge($existingData, $enrollmentData);

        $this->registrations->update((int) $existingInterest->id, [
            'user_id' => $userId,
            'status' => RegistrationStatus::Confirmed->value,
            'enrollment_data' => wp_json_encode($existingData),
            'registered_at' => current_time('mysql'),
        ]);

        return (int) $existingInterest->id;
    }
}
```

The exact insertion point depends on the flow in `enroll()`. Place it after validation but before the `$this->registrations->create()` call.

- [ ] **Step 2: Verify upgrade path works**

Manual test: create an interest registration (anonymous), then log in and enroll for the same edition. The interest registration should be upgraded, not duplicated.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentService.php
git commit -m "feat(enrollment): upgrade interest registration on enrollment"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpcs --standard=PSR12 \
  web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireRepository.php \
  web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireValidator.php \
  web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireRenderer.php \
  web/app/mu-plugins/stride-core/Modules/Questionnaire/QuestionnaireService.php \
  web/app/mu-plugins/stride-core/Modules/Questionnaire/Admin/QuestionnaireSettingsPage.php
```

Expected: No errors. Fix all issues before proceeding.

### Stage V2: Unit Tests

**Test files:**
- `tests/Unit/Questionnaire/QuestionnaireRepositoryTest.php`
- `tests/Unit/Questionnaire/QuestionnaireValidatorTest.php`

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: ALL tests pass.

### Stage V3: Acceptance Tests (Browser)

**Test file to create:** `tests/acceptance/QuestionnaireCest.php`

**Scenarios to cover:**

```
ADMIN FLOW:
  SCENARIO: Admin can create a field group
    GIVEN: Admin is logged in, visits stride-questionnaire page
    WHEN: Creates a group with stage "evaluation", adds a scale field
    THEN: Group saves, page reloads showing the group

  SCENARIO: Admin can add all field types
    GIVEN: Admin has a field group
    WHEN: Adds text, textarea, select, radio, scale, checkbox, description fields
    THEN: All fields save with correct configuration

INTEREST FLOW:
  SCENARIO: Anonymous user submits interest form
    GIVEN: Edition exists without sessions
    WHEN: User fills in name, email, submits interest form
    THEN: Registration created with status "interest", admin gets email

  SCENARIO: Duplicate interest updates existing
    GIVEN: Interest registration exists for email + edition
    WHEN: Same email submits interest again
    THEN: Existing registration updated, no duplicate

ENROLLMENT FLOW:
  SCENARIO: Enrollment with custom fields saves stage-keyed data
    GIVEN: Field group assigned to edition with stage enrollment_personal
    WHEN: User enrolls and fills in custom fields
    THEN: enrollment_data has {"enrollment_personal": {...}}

  SCENARIO: Interest upgraded on enrollment
    GIVEN: Interest registration exists for email + edition
    WHEN: User with that email enrolls
    THEN: Interest registration upgraded to confirmed, data merged

EVALUATION FLOW:
  SCENARIO: Evaluation form shows after completion
    GIVEN: User has completed registration, evaluation fields exist
    WHEN: User visits evaluation form
    THEN: Form renders with correct fields

  SCENARIO: Completed evaluation shows confirmation
    GIVEN: User already submitted evaluation
    WHEN: User revisits evaluation form
    THEN: "Already completed" message shown, no form
```

```bash
ddev exec vendor/bin/codecept run acceptance QuestionnaireCest --steps
```

Expected: ALL acceptance tests pass.

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/codecept run
```

Expected: Zero failures across all suites.

### Stage V5: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: `/wp/wp-admin/admin.php?page=stride-questionnaire`
      Expected: Builder loads, can add group with stage, add fields, save
- [ ] Create evaluation group with scale + radio + textarea, assign to all editions
      Expected: Saves, reloads showing collapsed group with correct badge
- [ ] Visit: `/interesse/?editie=<id>` (not logged in)
      Expected: Interest form shows name, email, any interest fields
- [ ] Submit interest form
      Expected: Success message, registration in DB with status "interest"
- [ ] Visit: `/inschrijven/?editie=<id>` (logged in)
      Expected: Enrollment form shows, custom fields render correctly
- [ ] Complete enrollment with custom fields
      Expected: enrollment_data is stage-keyed JSON
- [ ] Visit: `/evaluatie/?editie=<id>` (completed registration)
      Expected: Evaluation form renders with correct fields
- [ ] Submit evaluation
      Expected: Success, enrollment_data gains "evaluation" key
- [ ] Revisit evaluation form
      Expected: "Already completed" message, no form
- [ ] Database: `ddev exec wp db query "SELECT enrollment_data FROM wp_vad_registrations WHERE id=<id>"`
      Expected: JSON has stage keys
```
