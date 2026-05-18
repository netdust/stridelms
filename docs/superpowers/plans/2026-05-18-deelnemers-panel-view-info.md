# Deelnemers panel — view-info pass — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Slim the deelnemers detail row to identity, add two server-rendered view-info modals (Inschrijvingsgegevens, Voltooiing) plus a "view quote" link, all reachable from action icons in the registrations table.

**Architecture:** New `RegistrationModalController` (manual-instantiation pattern matching `EditionAdminController`) registers a single `wp_ajax_stride_get_registration_modal` endpoint that server-renders one of two HTML partials. `EditionRegistrationMetabox::renderDetailRow` shrinks to identity-only; three action icons appear in the actions column. JS in `edition-admin.js` opens/closes a single modal `<div>` and fills it with the AJAX response. Server-rendered HTML keeps escaping in PHP. No new services, repositories, or DB columns.

**Tech Stack:** PHP 8.2, WordPress + Bedrock, jQuery (existing `edition-admin.js`), PHPUnit (Unit + Integration), Codeception (Acceptance).

**Spec:** `docs/superpowers/specs/2026-05-18-deelnemers-panel-view-info.md`

---

## File Structure

### New files

| Path | Responsibility |
|------|----------------|
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php` | AJAX endpoint + payload assembly + HTML rendering dispatch |
| `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php` | Modal 1 markup (4 collapsible sections) |
| `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-completion.php` | Modal 2 markup (tasks / LD / attendance / cert) |
| `tests/Unit/Edition/RegistrationModalControllerTest.php` | Unit tests for the controller |
| `tests/Integration/Edition/RegistrationModalIntegrationTest.php` | Integration test with seeded DB |
| `tests/acceptance/AdminEditionDeelnemersCest.php` | Acceptance test for the deelnemers UI |

### Modified files

| Path | Change |
|------|--------|
| `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php` | Slim `renderDetailRow`; add 3 action icons to row; emit `<div id="stride-registration-modal">` scaffold |
| `web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php` | Instantiate `RegistrationModalController` next to `EditionAdminController` |
| `web/app/mu-plugins/stride-core/assets/js/admin/edition-admin.js` | Wire icon clicks → AJAX → modal open/close |
| `web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css` | Modal styles + action-icon divider |

No changes to `plugin-config.php`, repositories, services, or DB.

---

## Task 1: Controller skeleton with capability + nonce guards

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`
- Test: `tests/Unit/Edition/RegistrationModalControllerTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Edition/RegistrationModalControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Edition;

use Stride\Modules\Edition\Admin\RegistrationModalController;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Tests\TestCase;

class RegistrationModalControllerTest extends TestCase
{
    private RegistrationModalController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $editionService = $this->createMock(EditionService::class);
        $sessionService = $this->createMock(SessionService::class);
        $sessionSelection = $this->createMock(SessionSelection::class);
        $registrations = $this->createMock(RegistrationRepository::class);

        $this->controller = $this->getMockBuilder(RegistrationModalController::class)
            ->setConstructorArgs([
                $editionService,
                $sessionService,
                $sessionSelection,
                $registrations,
            ])
            ->onlyMethods([])
            ->getMock();
    }

    public function testNonceConstantMatchesEditionAdminController(): void
    {
        self::assertSame(
            'stride_edition_admin',
            RegistrationModalController::NONCE_AJAX
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: FAIL with "Class RegistrationModalController not found"

- [ ] **Step 3: Write minimal implementation**

Create `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`:

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Server-renders enrollment-data and completion-data modals
 * for the deelnemers panel on a vad_edition post.
 */
final class RegistrationModalController
{
    public const NONCE_AJAX = 'stride_edition_admin';
    public const AJAX_ACTION = 'stride_get_registration_modal';

    public function __construct(
        private readonly EditionService $editionService,
        private readonly SessionService $sessionService,
        private readonly SessionSelection $sessionSelection,
        private readonly RegistrationRepository $registrations,
    ) {
        $this->init();
    }

    private function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajaxGetModal']);
    }

    public function ajaxGetModal(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Onvoldoende rechten.', 'stride')], 403);
        }

        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field((string) $_REQUEST['nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_AJAX)) {
            wp_send_json_error(['message' => __('Ongeldige sessie. Herlaad de pagina.', 'stride')], 403);
        }

        $registrationId = isset($_REQUEST['registration_id']) ? (int) $_REQUEST['registration_id'] : 0;
        $type = isset($_REQUEST['type']) ? sanitize_key((string) $_REQUEST['type']) : '';

        if ($registrationId <= 0 || !in_array($type, ['enrollment', 'completion'], true)) {
            wp_send_json_error(['message' => __('Ongeldige aanvraag.', 'stride')], 400);
        }

        wp_send_json_success(['html' => '', 'title' => '']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (1 test, 1 assertion)

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php tests/Unit/Edition/RegistrationModalControllerTest.php
git commit -m "feat(edition-admin): scaffold RegistrationModalController with guards"
```

---

## Task 2: Reject invalid input — registration not found

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`
- Modify: `tests/Unit/Edition/RegistrationModalControllerTest.php`

- [ ] **Step 1: Add the failing test**

Append to `tests/Unit/Edition/RegistrationModalControllerTest.php` (inside the class):

```php
public function testBuildPayloadReturnsErrorWhenRegistrationNotFound(): void
{
    $registrations = $this->createMock(RegistrationRepository::class);
    $registrations->method('find')->willReturn(null);

    $controller = new RegistrationModalController(
        $this->createMock(EditionService::class),
        $this->createMock(SessionService::class),
        $this->createMock(SessionSelection::class),
        $registrations,
    );

    $result = $controller->buildPayload(123, 'enrollment');

    self::assertInstanceOf(\WP_Error::class, $result);
    self::assertSame('registration_not_found', $result->get_error_code());
}
```

Note: the previous test's `setUp()` already creates mocks; this test makes its own controller with the not-found mock. Both styles coexist.

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: FAIL with "Call to undefined method RegistrationModalController::buildPayload"

- [ ] **Step 3: Add the method**

Append inside `RegistrationModalController` class in `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`:

```php
/**
 * Build the payload (title + html) for a modal, or a WP_Error.
 *
 * @return array{title: string, html: string}|\WP_Error
 */
public function buildPayload(int $registrationId, string $type): array|\WP_Error
{
    $registration = $this->registrations->find($registrationId);
    if (!$registration) {
        return new \WP_Error(
            'registration_not_found',
            __('Inschrijving niet gevonden.', 'stride'),
        );
    }

    $userId = (int) $registration->user_id;
    $anonymisedAt = (int) get_user_meta($userId, '_stride_anonymised_at', true);
    if ($anonymisedAt > 0) {
        return new \WP_Error(
            'user_unavailable',
            __('Gegevens van deze gebruiker zijn niet meer beschikbaar.', 'stride'),
        );
    }

    $user = get_userdata($userId);
    if (!$user) {
        return new \WP_Error(
            'user_unavailable',
            __('Gegevens van deze gebruiker zijn niet meer beschikbaar.', 'stride'),
        );
    }

    $editionId = (int) $registration->edition_id;
    $edition = $this->editionService->getEdition($editionId);
    $editionTitle = $edition instanceof \WP_Post ? $edition->post_title : '';

    return [
        'title' => $this->buildTitle($type, $user->display_name, $editionTitle),
        'html' => '',
    ];
}

private function buildTitle(string $type, string $userName, string $editionTitle): string
{
    if ($type === 'completion') {
        return sprintf(
            /* translators: %s: user display name */
            __('Voltooiing — %s', 'stride'),
            $userName,
        );
    }

    return sprintf(
        /* translators: 1: user display name, 2: edition title */
        __('Inschrijving — %1$s — %2$s', 'stride'),
        $userName,
        $editionTitle,
    );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (2 tests, 2 assertions)

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php tests/Unit/Edition/RegistrationModalControllerTest.php
git commit -m "feat(edition-admin): add buildPayload + not-found guard"
```

---

## Task 3: Reject anonymised user

**Files:**
- Modify: `tests/Unit/Edition/RegistrationModalControllerTest.php`

(Implementation is already in place from Task 2 — this task only adds a regression test that locks the behaviour.)

- [ ] **Step 1: Add the failing test**

Append to the class in `tests/Unit/Edition/RegistrationModalControllerTest.php`:

```php
public function testBuildPayloadReturnsErrorForAnonymisedUser(): void
{
    $reg = (object) ['id' => 1, 'user_id' => 42, 'edition_id' => 99];

    $registrations = $this->createMock(RegistrationRepository::class);
    $registrations->method('find')->willReturn($reg);

    // Stride\Tests\Stubs::set_user_meta to simulate anonymised user
    \update_user_meta(42, '_stride_anonymised_at', time());

    $controller = new RegistrationModalController(
        $this->createMock(EditionService::class),
        $this->createMock(SessionService::class),
        $this->createMock(SessionSelection::class),
        $registrations,
    );

    $result = $controller->buildPayload(1, 'enrollment');

    self::assertInstanceOf(\WP_Error::class, $result);
    self::assertSame('user_unavailable', $result->get_error_code());

    \delete_user_meta(42, '_stride_anonymised_at');
}
```

- [ ] **Step 2: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (3 tests)

If the user-meta stubs aren't available in unit context, the test will instead error — check `tests/Stubs/` for `update_user_meta`. If absent, add minimal in-memory stubs to `tests/Stubs/user-meta.php` and require it in `tests/bootstrap.php`. (Look at `tests/Stubs/` first; do NOT add stubs if the helpers already exist.)

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Edition/RegistrationModalControllerTest.php
git commit -m "test(edition-admin): lock anonymised-user guard for modal payload"
```

---

## Task 4: Wire AJAX endpoint to buildPayload

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`

- [ ] **Step 1: Update ajaxGetModal to call buildPayload**

In `RegistrationModalController::ajaxGetModal`, replace the final `wp_send_json_success(['html' => '', 'title' => '']);` line with:

```php
$payload = $this->buildPayload($registrationId, $type);

if ($payload instanceof \WP_Error) {
    wp_send_json_error(
        ['message' => $payload->get_error_message()],
        404,
    );
}

wp_send_json_success($payload);
```

- [ ] **Step 2: Run all unit tests to confirm no regressions**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (3 tests)

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php
git commit -m "feat(edition-admin): wire AJAX endpoint to buildPayload"
```

---

## Task 5: Register controller from EditionService::init

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php:42-66`

- [ ] **Step 1: Instantiate the controller**

In `EditionService::init()`, after the `new Admin\EditionAdminController(...)` call (around line 65), add:

```php
new Admin\RegistrationModalController(
    $this,
    $sessionService,
    ntdst_get(\Stride\Modules\Edition\SessionSelection::class),
    ntdst_get(\Stride\Modules\Enrollment\RegistrationRepository::class),
);
```

- [ ] **Step 2: Smoke-test the bootstrap**

Run:

```bash
ddev exec wp eval "echo class_exists('\\Stride\\Modules\\Edition\\Admin\\RegistrationModalController') ? 'CLASS_OK' : 'FAIL';"
```

Expected: `CLASS_OK`

Run:

```bash
ddev exec wp eval "do_action('admin_init'); echo has_action('wp_ajax_stride_get_registration_modal') ? 'HOOK_OK' : 'FAIL';"
```

Expected: `HOOK_OK`

If `SessionSelection` is not yet container-bound, check `Stride\Modules\Edition\EditionService::init` — verify it (and `RegistrationRepository`) is reachable via `ntdst_get`. If not, instantiate directly in the same way as `EditionAdminController` receives its dependencies. (Look at how `EnrollmentService` registers `RegistrationRepository` and how `SessionSelection` is constructed before deciding.)

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/EditionService.php
git commit -m "feat(edition-admin): bootstrap RegistrationModalController"
```

---

## Task 6: Inschrijvingsgegevens partial — Inschrijvingsformulier section

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`
- Modify: `tests/Unit/Edition/RegistrationModalControllerTest.php`

- [ ] **Step 1: Add the failing test**

Append to the class in `tests/Unit/Edition/RegistrationModalControllerTest.php`:

```php
public function testEnrollmentModalRendersFormSection(): void
{
    $reg = (object) [
        'id' => 1,
        'user_id' => 42,
        'edition_id' => 99,
        'enrollment_data' => wp_json_encode(['phone_secondary' => '+32 123', 'organisation' => 'X']),
        'completion_tasks' => '{}',
    ];

    $registrations = $this->createMock(RegistrationRepository::class);
    $registrations->method('find')->willReturn($reg);

    $editionService = $this->createMock(EditionService::class);
    $editionService->method('getEdition')->willReturn((object) ['post_title' => 'My Edition']);

    $controller = new RegistrationModalController(
        $editionService,
        $this->createMock(SessionService::class),
        $this->createMock(SessionSelection::class),
        $registrations,
    );

    $result = $controller->buildPayload(1, 'enrollment');

    self::assertIsArray($result);
    self::assertStringContainsString('Inschrijvingsformulier', $result['html']);
    self::assertStringContainsString('+32 123', $result['html']);
    // Identity fields (organisation) MUST be skipped — already shown inline
    self::assertStringNotContainsString('class="stride-form-row" data-key="organisation"', $result['html']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: FAIL — `html` is empty.

- [ ] **Step 3: Create the partial**

Create `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php`:

```php
<?php
/**
 * @var array $enrollmentData
 * @var array $sessionSelections   // [['slot_label' => ?string, 'session' => ?array]]
 * @var array $questionnaireAnswers // [question stem => answer]
 * @var array $documents           // [['filename', 'size', 'uploaded_at', 'url']]
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$skipKeys = ['organisation', 'department'];
?>
<div class="stride-modal-body">
    <section class="stride-modal-section" data-section="form" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Inschrijvingsformulier', 'stride'); ?></h3>
        <?php if (empty($enrollmentData)): ?>
            <p class="stride-modal-empty"><?php esc_html_e('Geen inschrijvingsformulier voor deze editie.', 'stride'); ?></p>
        <?php else: ?>
            <dl class="stride-modal-dl">
                <?php foreach ($enrollmentData as $key => $value): ?>
                    <?php if (in_array($key, $skipKeys, true) || $value === '' || $value === false || $value === null): continue; endif; ?>
                    <div class="stride-form-row" data-key="<?php echo esc_attr((string) $key); ?>">
                        <dt><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) $key))); ?></dt>
                        <dd>
                            <?php
                            if (is_array($value)) {
                                echo esc_html(wp_json_encode($value));
                            } else {
                                echo esc_html($value === true ? __('Ja', 'stride') : (string) $value);
                            }
                            ?>
                        </dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        <?php endif; ?>
    </section>

    <section class="stride-modal-section" data-section="sessions" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Sessiekeuzes', 'stride'); ?></h3>
        <p class="stride-modal-empty"><?php esc_html_e('Geen sessiekeuze van toepassing.', 'stride'); ?></p>
    </section>

    <section class="stride-modal-section" data-section="questionnaire" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Vragenlijst', 'stride'); ?></h3>
        <p class="stride-modal-empty"><?php esc_html_e('Geen vragenlijst voor deze editie.', 'stride'); ?></p>
    </section>

    <section class="stride-modal-section" data-section="documents" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Documenten', 'stride'); ?></h3>
        <p class="stride-modal-empty"><?php esc_html_e('Geen documenten geüpload.', 'stride'); ?></p>
    </section>
</div>
```

(Sessions, questionnaire, documents sections render as empty in this task; later tasks fill them in.)

- [ ] **Step 4: Render the partial from the controller**

In `RegistrationModalController`, replace the final `return [...]` in `buildPayload` with:

```php
return [
    'title' => $this->buildTitle($type, $user->display_name, $editionTitle),
    'html'  => $this->renderHtml($type, $registration),
];
```

Then add these methods inside the class:

```php
private function renderHtml(string $type, object $registration): string
{
    if ($type === 'completion') {
        return $this->renderCompletion($registration);
    }
    return $this->renderEnrollment($registration);
}

private function renderEnrollment(object $registration): string
{
    $enrollmentData = $this->decodeJson($registration->enrollment_data ?? '');
    $sessionSelections = []; // Filled in Task 7
    $questionnaireAnswers = []; // Filled in Task 8
    $documents = []; // Filled in Task 9

    ob_start();
    $partialPath = dirname(__DIR__, 3) . '/templates/admin/partials/registration-modal-enrollment.php';
    include $partialPath;
    return (string) ob_get_clean();
}

private function renderCompletion(object $registration): string
{
    return ''; // Implemented in Task 10
}

private function decodeJson(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && $value !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (4 tests)

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php tests/Unit/Edition/RegistrationModalControllerTest.php
git commit -m "feat(edition-admin): render enrollment-form section in modal"
```

---

## Task 7: Sessiekeuzes section

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`
- Modify: `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php`
- Modify: `tests/Unit/Edition/RegistrationModalControllerTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
public function testEnrollmentModalRendersSessionSelections(): void
{
    $reg = (object) [
        'id' => 1, 'user_id' => 42, 'edition_id' => 99,
        'enrollment_data' => '{}',
        'completion_tasks' => '{}',
    ];

    $registrations = $this->createMock(RegistrationRepository::class);
    $registrations->method('find')->willReturn($reg);

    $editionService = $this->createMock(EditionService::class);
    $editionService->method('getEdition')->willReturn((object) ['post_title' => 'E']);

    $sessionSelection = $this->createMock(SessionSelection::class);
    $sessionSelection->method('getSelections')->with(1)->willReturn([501]);
    $sessionSelection->method('getSlotConfig')->with(99)->willReturn([
        ['slot' => 'a', 'label' => 'Module 1 — Kies 1 uit 2'],
    ]);

    $sessionService = $this->createMock(SessionService::class);
    $sessionService->method('getSession')->with(501)->willReturn([
        'id' => 501, 'date' => '2026-06-01', 'start_time' => '09:00',
        'slot' => 'a', 'location' => 'Brussel',
    ]);
    $sessionService->method('getSessionsForEdition')->with(99)->willReturn([
        ['id' => 501, 'date' => '2026-06-01', 'start_time' => '09:00', 'slot' => 'a', 'location' => 'Brussel'],
    ]);

    $controller = new RegistrationModalController($editionService, $sessionService, $sessionSelection, $registrations);
    $result = $controller->buildPayload(1, 'enrollment');

    self::assertStringContainsString('Module 1 — Kies 1 uit 2', $result['html']);
    self::assertStringContainsString('Brussel', $result['html']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: FAIL — slot label not found in html.

- [ ] **Step 3: Compute selections in renderEnrollment**

In `RegistrationModalController::renderEnrollment`, replace `$sessionSelections = [];` with:

```php
$sessionSelections = $this->buildSessionSelections(
    (int) $registration->id,
    (int) $registration->edition_id,
);
```

Then add this method to the class:

```php
/**
 * @return array<int, array{slot_label: ?string, session: ?array}>
 */
private function buildSessionSelections(int $registrationId, int $editionId): array
{
    $selectedIds = $this->sessionSelection->getSelections($registrationId);
    if (empty($selectedIds)) {
        return [];
    }

    $slotConfig = $this->sessionSelection->getSlotConfig($editionId);
    $slotLabelByKey = [];
    foreach ($slotConfig as $slot) {
        $key = (string) ($slot['slot'] ?? '');
        $slotLabelByKey[$key] = (string) ($slot['label'] ?? $key);
    }

    $rows = [];
    foreach ($selectedIds as $sessionId) {
        $session = $this->sessionService->getSession((int) $sessionId);
        if (!$session) {
            continue;
        }
        $slotKey = (string) ($session['slot'] ?? '');
        $rows[] = [
            'slot_label' => $slotKey !== '' ? ($slotLabelByKey[$slotKey] ?? __('Verplichte sessie', 'stride')) : null,
            'session' => $session,
        ];
    }

    return $rows;
}
```

- [ ] **Step 4: Render selections in the partial**

In `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php`, replace the "sessions" section with:

```php
<section class="stride-modal-section" data-section="sessions" data-open="1">
    <h3 class="stride-modal-section-title"><?php esc_html_e('Sessiekeuzes', 'stride'); ?></h3>
    <?php if (empty($sessionSelections)): ?>
        <p class="stride-modal-empty"><?php esc_html_e('Geen sessiekeuze van toepassing.', 'stride'); ?></p>
    <?php else: ?>
        <ul class="stride-modal-sessions">
            <?php foreach ($sessionSelections as $row): ?>
                <?php $session = $row['session']; ?>
                <li class="stride-modal-session">
                    <?php if (!empty($row['slot_label'])): ?>
                        <span class="stride-modal-slot-label"><?php echo esc_html($row['slot_label']); ?></span>
                    <?php endif; ?>
                    <span class="stride-modal-session-date">
                        <?php echo esc_html(date_i18n('j M Y', strtotime((string) ($session['date'] ?? '')))); ?>
                    </span>
                    <?php if (!empty($session['start_time'])): ?>
                        <span class="stride-modal-session-time"><?php echo esc_html((string) $session['start_time']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($session['location'])): ?>
                        <span class="stride-modal-session-loc"><?php echo esc_html((string) $session['location']); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php tests/Unit/Edition/RegistrationModalControllerTest.php
git commit -m "feat(edition-admin): render session selections in modal"
```

---

## Task 8: Vragenlijst section

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`
- Modify: `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php`
- Modify: `tests/Unit/Edition/RegistrationModalControllerTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
public function testEnrollmentModalRendersQuestionnaireAnswers(): void
{
    $reg = (object) [
        'id' => 1, 'user_id' => 42, 'edition_id' => 99,
        'enrollment_data' => '{}',
        'completion_tasks' => wp_json_encode([
            'questionnaire' => [
                'status' => 'completed',
                'data' => ['answers' => ['Wat is uw ervaring?' => 'Veel']],
            ],
        ]),
    ];

    $registrations = $this->createMock(RegistrationRepository::class);
    $registrations->method('find')->willReturn($reg);

    $editionService = $this->createMock(EditionService::class);
    $editionService->method('getEdition')->willReturn((object) ['post_title' => 'E']);

    $controller = new RegistrationModalController(
        $editionService,
        $this->createMock(SessionService::class),
        $this->createMock(SessionSelection::class),
        $registrations,
    );
    $result = $controller->buildPayload(1, 'enrollment');

    self::assertStringContainsString('Wat is uw ervaring?', $result['html']);
    self::assertStringContainsString('Veel', $result['html']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: FAIL — answer not in html.

- [ ] **Step 3: Extract answers in renderEnrollment**

In `RegistrationModalController::renderEnrollment`, replace `$questionnaireAnswers = [];` with:

```php
$tasks = $this->decodeJson($registration->completion_tasks ?? '');
$questionnaireAnswers = is_array($tasks['questionnaire']['data']['answers'] ?? null)
    ? $tasks['questionnaire']['data']['answers']
    : [];
```

- [ ] **Step 4: Render answers in the partial**

Replace the "questionnaire" section in `templates/admin/partials/registration-modal-enrollment.php` with:

```php
<section class="stride-modal-section" data-section="questionnaire" data-open="1">
    <h3 class="stride-modal-section-title"><?php esc_html_e('Vragenlijst', 'stride'); ?></h3>
    <?php if (empty($questionnaireAnswers)): ?>
        <p class="stride-modal-empty"><?php esc_html_e('Geen vragenlijst voor deze editie.', 'stride'); ?></p>
    <?php else: ?>
        <ol class="stride-modal-qa">
            <?php foreach ($questionnaireAnswers as $question => $answer): ?>
                <li class="stride-modal-qa-item">
                    <div class="stride-modal-qa-q"><?php echo esc_html((string) $question); ?></div>
                    <div class="stride-modal-qa-a">
                        <?php echo esc_html(is_string($answer) ? $answer : (string) wp_json_encode($answer)); ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php endif; ?>
</section>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (6 tests)

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php tests/Unit/Edition/RegistrationModalControllerTest.php
git commit -m "feat(edition-admin): render questionnaire answers in modal"
```

---

## Task 9: Documenten section

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`
- Modify: `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php`
- Modify: `tests/Unit/Edition/RegistrationModalControllerTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
public function testEnrollmentModalRendersDocuments(): void
{
    $reg = (object) [
        'id' => 1, 'user_id' => 42, 'edition_id' => 99,
        'enrollment_data' => '{}',
        'completion_tasks' => wp_json_encode([
            'documents' => ['status' => 'completed', 'data' => ['files' => [123]]],
        ]),
    ];

    $registrations = $this->createMock(RegistrationRepository::class);
    $registrations->method('find')->willReturn($reg);

    $editionService = $this->createMock(EditionService::class);
    $editionService->method('getEdition')->willReturn((object) ['post_title' => 'E']);

    $controller = new RegistrationModalController(
        $editionService,
        $this->createMock(SessionService::class),
        $this->createMock(SessionSelection::class),
        $registrations,
    );
    $result = $controller->buildPayload(1, 'enrollment');

    // The unit test does not stub wp_get_attachment_url; just verify the section
    // renders something other than the empty-state when files exist.
    self::assertStringNotContainsString(
        'Geen documenten geüpload',
        $result['html'],
    );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: FAIL — empty-state is still rendered.

- [ ] **Step 3: Extract documents in renderEnrollment**

In `RegistrationModalController::renderEnrollment`, replace `$documents = [];` with:

```php
$documents = $this->buildDocuments($tasks);
```

Then add this method:

```php
/**
 * @return array<int, array{id:int, filename:string, url:string, uploaded_at:?string}>
 */
private function buildDocuments(array $tasks): array
{
    $docs = [];
    foreach (['documents', 'post_documents'] as $taskKey) {
        $files = $tasks[$taskKey]['data']['files'] ?? null;
        if (!is_array($files)) {
            continue;
        }
        foreach ($files as $fileId) {
            $id = (int) $fileId;
            if ($id <= 0) {
                continue;
            }
            $path = get_attached_file($id);
            $docs[] = [
                'id' => $id,
                'filename' => $path ? basename((string) $path) : sprintf(__('Bestand #%d', 'stride'), $id),
                'url' => (string) wp_get_attachment_url($id),
                'uploaded_at' => get_post_field('post_date', $id) ?: null,
            ];
        }
    }
    return $docs;
}
```

- [ ] **Step 4: Render documents in the partial**

Replace the "documents" section in `templates/admin/partials/registration-modal-enrollment.php` with:

```php
<section class="stride-modal-section" data-section="documents" data-open="1">
    <h3 class="stride-modal-section-title"><?php esc_html_e('Documenten', 'stride'); ?></h3>
    <?php if (empty($documents)): ?>
        <p class="stride-modal-empty"><?php esc_html_e('Geen documenten geüpload.', 'stride'); ?></p>
    <?php else: ?>
        <ul class="stride-modal-docs">
            <?php foreach ($documents as $doc): ?>
                <li class="stride-modal-doc">
                    <?php if (!empty($doc['url'])): ?>
                        <a href="<?php echo esc_url($doc['url']); ?>" target="_blank" rel="noopener">
                            <span class="dashicons dashicons-media-default"></span>
                            <?php echo esc_html($doc['filename']); ?>
                        </a>
                    <?php else: ?>
                        <span><?php echo esc_html($doc['filename']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($doc['uploaded_at'])): ?>
                        <span class="stride-modal-doc-date">
                            <?php echo esc_html(date_i18n('j M Y', strtotime((string) $doc['uploaded_at']))); ?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (7 tests)

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-enrollment.php tests/Unit/Edition/RegistrationModalControllerTest.php
git commit -m "feat(edition-admin): render documents section in modal"
```

---

## Task 10: Voltooiingsdata modal

**Files:**
- Create: `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-completion.php`
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php`
- Modify: `tests/Unit/Edition/RegistrationModalControllerTest.php`

- [ ] **Step 1: Add the failing test**

Append:

```php
public function testCompletionModalRendersTasksAndProgress(): void
{
    $reg = (object) [
        'id' => 1, 'user_id' => 42, 'edition_id' => 99,
        'enrollment_data' => '{}',
        'completion_tasks' => wp_json_encode([
            'questionnaire' => ['status' => 'completed', 'completed_at' => '2026-05-01 10:00:00'],
            'documents'     => ['status' => 'pending'],
        ]),
    ];

    $registrations = $this->createMock(RegistrationRepository::class);
    $registrations->method('find')->willReturn($reg);

    $editionService = $this->createMock(EditionService::class);
    $editionService->method('getEdition')->willReturn((object) ['post_title' => 'E']);
    $editionService->method('getCourseId')->willReturn(777);

    $controller = new RegistrationModalController(
        $editionService,
        $this->createMock(SessionService::class),
        $this->createMock(SessionSelection::class),
        $registrations,
    );
    $result = $controller->buildPayload(1, 'completion');

    self::assertStringContainsString('Voltooiing', $result['title']);
    self::assertStringContainsString('Vragenlijst', $result['html']);
    self::assertStringContainsString('Documenten', $result['html']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: FAIL — completion html is empty (`renderCompletion` returns `''`).

- [ ] **Step 3: Create the completion partial**

Create `web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-completion.php`:

```php
<?php
/**
 * @var array<string, array{status:string,label:string,completed_at:?string,completed_by:?string}> $taskRows
 * @var int $ldProgress         // 0–100
 * @var ?string $ldCompletionDate
 * @var float $hoursAttended
 * @var float $hoursTotal
 * @var string $certificateUrl  // '' if none
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="stride-modal-body">
    <section class="stride-modal-section" data-section="tasks" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Status van taken', 'stride'); ?></h3>
        <?php if (empty($taskRows)): ?>
            <p class="stride-modal-empty"><?php esc_html_e('Geen taken voor deze inschrijving.', 'stride'); ?></p>
        <?php else: ?>
            <table class="stride-modal-task-table">
                <thead><tr>
                    <th><?php esc_html_e('Taak', 'stride'); ?></th>
                    <th><?php esc_html_e('Status', 'stride'); ?></th>
                    <th><?php esc_html_e('Voltooid op', 'stride'); ?></th>
                </tr></thead>
                <tbody>
                    <?php foreach ($taskRows as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['label']); ?></td>
                            <td>
                                <span class="stride-status-badge <?php echo esc_attr($row['status']); ?>">
                                    <?php echo esc_html($row['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $row['completed_at']
                                    ? esc_html(date_i18n('j M Y H:i', strtotime((string) $row['completed_at'])))
                                    : '—'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="stride-modal-section" data-section="ld" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('LearnDash voortgang', 'stride'); ?></h3>
        <div class="stride-modal-progress">
            <div class="stride-modal-progress-bar">
                <div class="stride-modal-progress-fill" style="width: <?php echo esc_attr((string) (int) $ldProgress); ?>%;"></div>
            </div>
            <span class="stride-modal-progress-pct"><?php echo esc_html((int) $ldProgress . '%'); ?></span>
        </div>
        <?php if (!empty($ldCompletionDate)): ?>
            <p class="stride-modal-ld-date">
                <?php echo esc_html(sprintf(
                    /* translators: %s: completion date */
                    __('Voltooid op %s', 'stride'),
                    date_i18n('j M Y', strtotime((string) $ldCompletionDate)),
                )); ?>
            </p>
        <?php endif; ?>
    </section>

    <section class="stride-modal-section" data-section="attendance" data-open="1">
        <h3 class="stride-modal-section-title"><?php esc_html_e('Aanwezigheid', 'stride'); ?></h3>
        <p>
            <?php echo esc_html(sprintf(
                /* translators: 1: hours attended, 2: hours required */
                __('%1$s / %2$s uur', 'stride'),
                number_format_i18n($hoursAttended, 1),
                number_format_i18n($hoursTotal, 1),
            )); ?>
        </p>
    </section>

    <?php if (!empty($certificateUrl)): ?>
        <section class="stride-modal-section" data-section="cert" data-open="1">
            <h3 class="stride-modal-section-title"><?php esc_html_e('Certificaat', 'stride'); ?></h3>
            <a href="<?php echo esc_url($certificateUrl); ?>" target="_blank" rel="noopener" class="button button-secondary">
                <?php esc_html_e('Bekijk certificaat', 'stride'); ?>
            </a>
        </section>
    <?php endif; ?>
</div>
```

- [ ] **Step 4: Implement renderCompletion**

Replace the stub `renderCompletion` in `RegistrationModalController` with:

```php
private function renderCompletion(object $registration): string
{
    $taskRows = $this->buildTaskRows(
        $this->decodeJson($registration->completion_tasks ?? ''),
    );

    $editionId = (int) $registration->edition_id;
    $userId = (int) $registration->user_id;
    $courseId = (int) ($this->editionService->getCourseId($editionId) ?? 0);

    $ldProgress = 0;
    $ldCompletionDate = null;
    $certificateUrl = '';

    if ($courseId > 0 && class_exists(\Stride\Integrations\LearnDash\LearnDashHelper::class)) {
        $ldProgress = \Stride\Integrations\LearnDash\LearnDashHelper::getProgress($courseId, $userId);
        $completionTs = \Stride\Integrations\LearnDash\LearnDashHelper::getCompletionDate($courseId, $userId);
        $ldCompletionDate = $completionTs ? date('Y-m-d H:i:s', $completionTs) : null;
        if (\Stride\Integrations\LearnDash\LearnDashHelper::isComplete($courseId, $userId)) {
            $certificateUrl = \Stride\Integrations\LearnDash\LearnDashHelper::getCertificateLink($courseId, $userId);
        }
    }

    $hoursAttended = 0.0;
    $hoursTotal = 0.0;
    if (method_exists($this->sessionService, 'getTotalHours')) {
        $hoursTotal = $this->sessionService->getTotalHours($editionId);
    }
    // Hours-attended: reuse SessionService if a public getter exists, else 0.
    // (Spec says "from SessionService::getHoursAttended" — confirm in implementation;
    //  if absent, fall back to AttendanceRepository::countAttended × average session length.)
    if (method_exists($this->sessionService, 'getHoursAttended')) {
        $hoursAttended = $this->sessionService->getHoursAttended($userId, $editionId);
    }

    ob_start();
    $partialPath = dirname(__DIR__, 3) . '/templates/admin/partials/registration-modal-completion.php';
    include $partialPath;
    return (string) ob_get_clean();
}

/**
 * @return array<int, array{status:string,label:string,completed_at:?string,completed_by:?string}>
 */
private function buildTaskRows(array $tasks): array
{
    $labels = [
        'questionnaire'     => __('Vragenlijst', 'stride'),
        'documents'         => __('Documenten', 'stride'),
        'approval'          => __('Goedkeuring', 'stride'),
        'session_selection' => __('Sessiekeuze', 'stride'),
        'post_evaluation'   => __('Evaluatie', 'stride'),
        'post_documents'    => __('Documenten (na afloop)', 'stride'),
        'post_approval'     => __('Goedkeuring (na afloop)', 'stride'),
    ];

    $rows = [];
    foreach ($tasks as $taskKey => $task) {
        if (!is_array($task)) {
            continue;
        }
        $rows[] = [
            'status'       => (string) ($task['status'] ?? 'pending'),
            'label'        => $labels[$taskKey] ?? ucfirst(str_replace('_', ' ', (string) $taskKey)),
            'completed_at' => isset($task['completed_at']) ? (string) $task['completed_at'] : null,
            'completed_by' => isset($task['completed_by']) ? (string) $task['completed_by'] : null,
        ];
    }
    return $rows;
}
```

If `SessionService::getHoursAttended` does not exist, the rendered hours-attended will be `0`. That's acceptable for the first ship — the spec calls out a fallback; do not add a new public method on `SessionService` unless required to make the integration test pass (Task 12).

- [ ] **Step 5: Run test to verify it passes**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalControllerTest --testsuite Unit`
Expected: PASS (8 tests)

- [ ] **Step 6: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/RegistrationModalController.php web/app/mu-plugins/stride-core/templates/admin/partials/registration-modal-completion.php tests/Unit/Edition/RegistrationModalControllerTest.php
git commit -m "feat(edition-admin): render completion-data modal"
```

---

## Task 11: Metabox — slim detail row, add action icons + modal scaffold

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php`

- [ ] **Step 1: Slim renderDetailRow**

In `EditionRegistrationMetabox::renderDetailRow` (currently around lines 283–401), replace the *whole* method body with:

```php
private function renderDetailRow(\WP_User $user, array $meta, array $registration, array $completionTasks, ?array $quote = null): void
{
    $phone = $meta['phone'] ?? '';
    $organisation = $meta['organisation'] ?? '';
    $department = $meta['department'] ?? '';
    $company = $meta['billing_company'] ?? '';
    $vatNumber = $meta['billing_vat'] ?? '';
    $notes = $registration['notes'] ?? '';

    $hasContent = ($phone || $organisation || $department || $vatNumber || $notes
        || ($company && $company !== $organisation));
    ?>
    <div class="stride-detail-panels">
        <dl class="stride-detail-dl">
            <?php if ($phone): ?>
                <dt><?php esc_html_e('Telefoon', 'stride'); ?></dt>
                <dd><?php echo esc_html($phone); ?></dd>
            <?php endif; ?>
            <?php if ($organisation): ?>
                <dt><?php esc_html_e('Organisatie', 'stride'); ?></dt>
                <dd><?php echo esc_html($organisation); ?></dd>
            <?php endif; ?>
            <?php if ($department): ?>
                <dt><?php esc_html_e('Afdeling', 'stride'); ?></dt>
                <dd><?php echo esc_html($department); ?></dd>
            <?php endif; ?>
            <?php if ($company && $company !== $organisation): ?>
                <dt><?php esc_html_e('Facturatie bedrijf', 'stride'); ?></dt>
                <dd><?php echo esc_html($company); ?></dd>
            <?php endif; ?>
            <?php if ($vatNumber): ?>
                <dt><?php esc_html_e('BTW-nummer', 'stride'); ?></dt>
                <dd><?php echo esc_html($vatNumber); ?></dd>
            <?php endif; ?>
            <?php if ($notes): ?>
                <dt><?php esc_html_e('Opmerking', 'stride'); ?></dt>
                <dd><?php echo esc_html($notes); ?></dd>
            <?php endif; ?>
            <?php if (!$hasContent): ?>
                <dd class="stride-detail-empty"><?php esc_html_e('Geen aanvullende gegevens.', 'stride'); ?></dd>
            <?php endif; ?>
        </dl>
    </div>
    <?php
}
```

This drops the enrollment-data dump, completion-task list, and inline quote summary — those are now in modals or action links.

- [ ] **Step 2: Add the action-icon strip in the row**

Find the `<td class="column-actions">` block inside `renderRegistrationsTab` (lines 256–270 in the current file). Replace its body with:

```php
<?php if ($showApproveReject): ?>
    <button type="button" class="button-link stride-confirm-reg" title="<?php esc_attr_e('Goedkeuren', 'stride'); ?>">
        <span class="dashicons dashicons-yes-alt"></span>
    </button>
    <button type="button" class="button-link stride-reject-reg" title="<?php esc_attr_e('Afwijzen', 'stride'); ?>">
        <span class="dashicons dashicons-dismiss"></span>
    </button>
<?php endif; ?>
<?php if ($showPostApprove): ?>
    <button type="button" class="button-link stride-approve-post-course" title="<?php esc_attr_e('Aftekenen', 'stride'); ?>">
        <span class="dashicons dashicons-yes-alt" style="color: #2271b1;"></span>
    </button>
<?php endif; ?>

<span class="stride-action-divider" aria-hidden="true"></span>

<button type="button"
        class="button-link stride-view-enrollment"
        data-reg-id="<?php echo esc_attr((string) $regId); ?>"
        title="<?php esc_attr_e('Inschrijvingsgegevens bekijken', 'stride'); ?>">
    <span class="dashicons dashicons-clipboard"></span>
</button>
<button type="button"
        class="button-link stride-view-completion"
        data-reg-id="<?php echo esc_attr((string) $regId); ?>"
        title="<?php esc_attr_e('Voltooiingsdata bekijken', 'stride'); ?>">
    <span class="dashicons dashicons-yes"></span>
</button>
<?php if (!empty($quotes[$regId]['id'])): ?>
    <a href="<?php echo esc_url((string) get_edit_post_link((int) $quotes[$regId]['id'])); ?>"
       class="button-link stride-view-quote"
       title="<?php esc_attr_e('Offerte bekijken', 'stride'); ?>"
       target="_blank" rel="noopener">
        <span class="dashicons dashicons-media-text"></span>
    </a>
<?php else: ?>
    <span class="button-link stride-view-quote disabled"
          title="<?php esc_attr_e('Geen offerte', 'stride'); ?>"
          aria-disabled="true">
        <span class="dashicons dashicons-media-text"></span>
    </span>
<?php endif; ?>
```

- [ ] **Step 3: Emit the modal scaffold once at the bottom of the metabox**

At the very end of the outer `<div class="stride-edition-admin stride-registration-metabox">` in `render()` (just before its closing `</div>`), append:

```php
<div id="stride-registration-modal" class="stride-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="stride-registration-modal-title">
    <div class="stride-modal-backdrop" data-stride-modal-close></div>
    <div class="stride-modal-dialog">
        <header class="stride-modal-header">
            <h2 id="stride-registration-modal-title" class="stride-modal-title"></h2>
            <button type="button" class="stride-modal-close" data-stride-modal-close aria-label="<?php esc_attr_e('Sluiten', 'stride'); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </header>
        <div class="stride-modal-content"></div>
        <div class="stride-modal-skeleton" hidden>
            <p><?php esc_html_e('Laden…', 'stride'); ?></p>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Smoke-test the rendered markup**

Run:

```bash
ddev exec wp eval "echo ((bool) get_posts(['post_type'=>'vad_edition','posts_per_page'=>1])) ? 'EDITION_OK' : 'NO_EDITION';"
```

Expected: `EDITION_OK` (if there are seeded editions). If `NO_EDITION`, run `ddev exec wp eval-file scripts/seed.php` first.

Then open `https://stride.ddev.site/wp/wp-admin/edit.php?post_type=vad_edition`, edit a seeded edition, scroll to the "Deelnemers & Aanwezigheid" metabox, and confirm:
- The action column shows: approve/reject (if pending) + a divider + clipboard + check + media-text icons.
- Expanding a row shows identity only (no enrollment-data dump, no task list).
- A hidden `<div id="stride-registration-modal">` is in the DOM.

- [ ] **Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionRegistrationMetabox.php
git commit -m "feat(edition-admin): slim detail row + add view-info action icons"
```

---

## Task 12: Integration test for the AJAX endpoint

**Files:**
- Create: `tests/Integration/Edition/RegistrationModalIntegrationTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Stride\Tests\Integration\Edition;

use Stride\Modules\Edition\Admin\RegistrationModalController;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_UnitTestCase;

class RegistrationModalIntegrationTest extends WP_UnitTestCase
{
    public function testEnrollmentModalRendersForSeededRegistration(): void
    {
        // Seed a minimal edition + registration.
        $userId = $this->factory->user->create([
            'role' => 'subscriber',
            'display_name' => 'Test User',
        ]);
        $editionId = $this->factory->post->create([
            'post_type' => 'vad_edition',
            'post_title' => 'Test Edition',
            'post_status' => 'publish',
        ]);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id'         => $userId,
            'edition_id'      => $editionId,
            'status'          => 'confirmed',
            'enrollment_data' => wp_json_encode(['phone_secondary' => '+32 444']),
            'completion_tasks'=> wp_json_encode([
                'questionnaire' => ['status' => 'completed', 'data' => ['answers' => ['Q1' => 'A1']]],
            ]),
            'registered_at'   => current_time('mysql'),
        ]);
        $registrationId = (int) $wpdb->insert_id;

        $controller = new RegistrationModalController(
            ntdst_get(EditionService::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionSelection::class),
            ntdst_get(RegistrationRepository::class),
        );

        $payload = $controller->buildPayload($registrationId, 'enrollment');

        self::assertIsArray($payload);
        self::assertStringContainsString('Test Edition', $payload['title']);
        self::assertStringContainsString('Test User', $payload['title']);
        self::assertStringContainsString('+32 444', $payload['html']);
        self::assertStringContainsString('Q1', $payload['html']);
        self::assertStringContainsString('A1', $payload['html']);
    }

    public function testCompletionModalRendersForSeededRegistration(): void
    {
        $userId = $this->factory->user->create(['role' => 'subscriber']);
        $editionId = $this->factory->post->create([
            'post_type' => 'vad_edition', 'post_status' => 'publish',
        ]);

        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'vad_registrations', [
            'user_id' => $userId, 'edition_id' => $editionId,
            'status' => 'confirmed',
            'enrollment_data' => '{}',
            'completion_tasks' => wp_json_encode([
                'questionnaire' => ['status' => 'completed', 'completed_at' => '2026-05-01 10:00:00'],
            ]),
            'registered_at' => current_time('mysql'),
        ]);
        $registrationId = (int) $wpdb->insert_id;

        $controller = new RegistrationModalController(
            ntdst_get(EditionService::class),
            ntdst_get(SessionService::class),
            ntdst_get(SessionSelection::class),
            ntdst_get(RegistrationRepository::class),
        );

        $payload = $controller->buildPayload($registrationId, 'completion');

        self::assertStringContainsString('Voltooiing', $payload['title']);
        self::assertStringContainsString('Vragenlijst', $payload['html']);
    }
}
```

- [ ] **Step 2: Run the test**

Run: `ddev exec vendor/bin/phpunit --filter RegistrationModalIntegrationTest --testsuite Integration`
Expected: PASS (2 tests)

If the `RegistrationRepository`, `SessionSelection`, or `SessionService` is not container-resolvable in the test environment, instantiate them directly (`new RegistrationRepository()`) instead of `ntdst_get`. Mirror how `EnrollmentServiceIntegrationTest` resolves its dependencies.

- [ ] **Step 3: Commit**

```bash
git add tests/Integration/Edition/RegistrationModalIntegrationTest.php
git commit -m "test(edition-admin): integration test for registration modal endpoint"
```

---

## Task 13: JS — open/close modal + AJAX fetch

**Files:**
- Modify: `web/app/mu-plugins/stride-core/assets/js/admin/edition-admin.js`

- [ ] **Step 1: Add the click handlers + open/close**

At the bottom of `edition-admin.js` (inside the existing IIFE if any; otherwise at module scope), append:

```javascript
(function ($) {
    'use strict';

    var $modal = null;

    function ensureModal() {
        if ($modal && $modal.length) return $modal;
        $modal = $('#stride-registration-modal');
        if ($modal.length) {
            $modal.on('click', '[data-stride-modal-close]', closeModal);
            $(document).on('keydown.strideModal', function (e) {
                if (e.key === 'Escape' && !$modal.attr('hidden')) closeModal();
            });
        }
        return $modal;
    }

    function openModal(title, html) {
        var $m = ensureModal();
        if (!$m.length) return;
        $m.find('.stride-modal-title').text(title || '');
        $m.find('.stride-modal-content').html(html || '');
        $m.removeAttr('hidden').attr('aria-hidden', 'false');
        $('body').addClass('stride-modal-open');
    }

    function showSkeleton() {
        var $m = ensureModal();
        if (!$m.length) return;
        $m.find('.stride-modal-content').empty();
        $m.find('.stride-modal-skeleton').removeAttr('hidden');
        $m.removeAttr('hidden').attr('aria-hidden', 'false');
        $('body').addClass('stride-modal-open');
    }

    function hideSkeleton() {
        ensureModal().find('.stride-modal-skeleton').attr('hidden', 'hidden');
    }

    function closeModal() {
        var $m = ensureModal();
        if (!$m.length) return;
        $m.attr('hidden', 'hidden').attr('aria-hidden', 'true');
        $m.find('.stride-modal-content').empty();
        $('body').removeClass('stride-modal-open');
    }

    function fetchAndOpen(regId, type) {
        showSkeleton();
        $.post(window.strideEditionAdmin.ajaxurl, {
            action: 'stride_get_registration_modal',
            nonce: window.strideEditionAdmin.nonce,
            registration_id: regId,
            type: type,
        }).done(function (resp) {
            hideSkeleton();
            if (resp && resp.success) {
                openModal(resp.data.title, resp.data.html);
            } else {
                var msg = (resp && resp.data && resp.data.message) || window.strideEditionAdmin.i18n.error;
                openModal('', '<p class="stride-modal-error">' + msg + '</p>');
            }
        }).fail(function () {
            hideSkeleton();
            openModal('', '<p class="stride-modal-error">' + window.strideEditionAdmin.i18n.error + '</p>');
        });
    }

    $(document).on('click', '.stride-view-enrollment', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var regId = $(this).data('reg-id');
        if (regId) fetchAndOpen(regId, 'enrollment');
    });

    $(document).on('click', '.stride-view-completion', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var regId = $(this).data('reg-id');
        if (regId) fetchAndOpen(regId, 'completion');
    });
})(jQuery);
```

`e.stopPropagation()` matters: the row itself is `stride-toggle-detail`, and we don't want clicking the modal-trigger icon to also toggle the row.

- [ ] **Step 2: Manual smoke test**

1. Open an edition in admin.
2. Click clipboard icon next to a registration → modal slides in with Inschrijvingsgegevens content. Close with ✕ or Esc or backdrop.
3. Click check icon → modal shows Voltooiingsdata content.
4. Verify that clicking the icon does NOT also toggle the inline detail row.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/js/admin/edition-admin.js
git commit -m "feat(edition-admin): wire JS for view-info modals"
```

---

## Task 14: CSS — modal + action-icon styles

**Files:**
- Modify: `web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css`

- [ ] **Step 1: Append modal + action styles**

Append at the end of `edition-admin.css`:

```css
/* ============ Deelnemers — action icons ============ */

.stride-registration-table .column-actions .button-link {
    margin-right: 4px;
    color: #50575e;
}
.stride-registration-table .column-actions .button-link:hover,
.stride-registration-table .column-actions .button-link:focus { color: #2271b1; }

.stride-registration-table .column-actions .button-link.disabled,
.stride-registration-table .column-actions [aria-disabled="true"] {
    color: #c3c4c7;
    cursor: not-allowed;
    pointer-events: none;
}

.stride-action-divider {
    display: inline-block;
    width: 1px;
    height: 14px;
    background: #dcdcde;
    vertical-align: middle;
    margin: 0 6px;
}

/* ============ Modal ============ */

body.stride-modal-open { overflow: hidden; }

.stride-modal[hidden] { display: none; }

.stride-modal {
    position: fixed;
    inset: 0;
    z-index: 100100;
}

.stride-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
}

.stride-modal-dialog {
    position: relative;
    margin: 5vh auto;
    width: min(880px, calc(100% - 32px));
    max-height: 90vh;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    display: flex;
    flex-direction: column;
}

.stride-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    border-bottom: 1px solid #dcdcde;
}

.stride-modal-title { margin: 0; font-size: 16px; }

.stride-modal-close {
    background: none;
    border: 0;
    cursor: pointer;
    padding: 4px;
    color: #50575e;
}

.stride-modal-content {
    padding: 16px 20px;
    overflow-y: auto;
}

.stride-modal-skeleton {
    padding: 24px 20px;
    color: #646970;
    font-style: italic;
}

.stride-modal-error { color: #b32d2e; }

.stride-modal-section + .stride-modal-section { margin-top: 20px; }

.stride-modal-section-title {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #1d2327;
    margin: 0 0 8px;
}

.stride-modal-dl { margin: 0; }
.stride-modal-dl .stride-form-row { display: flex; gap: 12px; padding: 4px 0; }
.stride-modal-dl dt { min-width: 180px; font-weight: 600; color: #1d2327; }
.stride-modal-dl dd { margin: 0; color: #1d2327; }

.stride-modal-sessions { list-style: none; margin: 0; padding: 0; }
.stride-modal-session { padding: 6px 0; border-bottom: 1px solid #f0f0f1; display: flex; gap: 12px; align-items: baseline; }
.stride-modal-slot-label { font-weight: 600; color: #50575e; }

.stride-modal-qa { padding-left: 18px; }
.stride-modal-qa-item + .stride-modal-qa-item { margin-top: 10px; }
.stride-modal-qa-q { font-weight: 600; }
.stride-modal-qa-a { color: #50575e; }

.stride-modal-docs { list-style: none; margin: 0; padding: 0; }
.stride-modal-doc { padding: 6px 0; border-bottom: 1px solid #f0f0f1; }
.stride-modal-doc a .dashicons { vertical-align: middle; margin-right: 4px; }
.stride-modal-doc-date { color: #8c8f94; margin-left: 8px; font-size: 12px; }

.stride-modal-task-table { width: 100%; border-collapse: collapse; }
.stride-modal-task-table th, .stride-modal-task-table td {
    padding: 6px 8px; text-align: left; border-bottom: 1px solid #f0f0f1;
}
.stride-modal-progress { display: flex; align-items: center; gap: 12px; }
.stride-modal-progress-bar { flex: 1; height: 8px; background: #f0f0f1; border-radius: 4px; overflow: hidden; }
.stride-modal-progress-fill { height: 100%; background: #2271b1; }

.stride-modal-empty { color: #8c8f94; font-style: italic; margin: 0; }
```

- [ ] **Step 2: Manual smoke test**

Open the modal in browser, verify:
- Backdrop dims the page; modal centred; scrollable when content overflows.
- Action divider appears between approve/reject and the new icons.
- Disabled "view quote" icon is grey and not clickable.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/assets/css/admin/edition-admin.css
git commit -m "style(edition-admin): modal + action-icon styles for view-info"
```

---

## Task 15: Acceptance test (Cest)

**Files:**
- Create: `tests/acceptance/AdminEditionDeelnemersCest.php`

- [ ] **Step 1: Write the Cest**

```php
<?php

declare(strict_types=1);

use Stride\Tests\AcceptanceTester;

class AdminEditionDeelnemersCest
{
    public function adminCanOpenInschrijvingsgegevensModal(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();

        // Assumes seed.php has been run; pick the first seeded edition that has registrations.
        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_edition');
        $I->click('.row-title');

        $I->waitForElementVisible('.stride-registration-table', 5);
        $I->seeElement('.stride-view-enrollment');

        // Open the enrollment modal
        $I->click('.stride-registration-table tr.registration-row:first-child .stride-view-enrollment');
        $I->waitForElementVisible('#stride-registration-modal:not([hidden])', 5);
        $I->see('Inschrijvingsformulier', '#stride-registration-modal');

        // Close
        $I->click('.stride-modal-close');
        $I->waitForElementNotVisible('#stride-registration-modal .stride-modal-dialog', 3);
    }

    public function adminCanOpenVoltooiingsModal(AcceptanceTester $I): void
    {
        $I->loginAsAdmin();
        $I->amOnPage('/wp/wp-admin/edit.php?post_type=vad_edition');
        $I->click('.row-title');

        $I->waitForElementVisible('.stride-registration-table', 5);
        $I->click('.stride-registration-table tr.registration-row:first-child .stride-view-completion');
        $I->waitForElementVisible('#stride-registration-modal:not([hidden])', 5);
        $I->see('LearnDash voortgang', '#stride-registration-modal');
    }
}
```

- [ ] **Step 2: Run the acceptance suite**

Run:

```bash
ddev exec vendor/bin/codecept run acceptance AdminEditionDeelnemersCest
```

Expected: 2 passing tests. If the seeded edition has no registrations, ensure `scripts/seed.php` ran first (`ddev exec wp eval-file scripts/seed.php`).

- [ ] **Step 3: Commit**

```bash
git add tests/acceptance/AdminEditionDeelnemersCest.php
git commit -m "test(edition-admin): acceptance test for deelnemers view-info modals"
```

---

## Task 16: Full test-suite sanity + final commit

- [ ] **Step 1: Run all suites**

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
ddev exec vendor/bin/phpunit --testsuite Integration
ddev exec vendor/bin/codecept run acceptance
```

Expected: all suites green.

- [ ] **Step 2: Manual visual sweep**

1. Open three different editions: one with pending registrations, one with confirmed registrations, one with no registrations.
2. Confirm the row layout, action icons, and disabled quote icon for registrations without quotes.
3. Open both modals on a registration that has a questionnaire + documents seeded.
4. Verify keyboard: Tab into the icons, Enter opens, Esc closes.

- [ ] **Step 3: Memory note (if anything surprising was discovered)**

If during implementation you discovered an undocumented behaviour, add a one-line entry to `~/.claude/projects/-home-ntdst-Sites-stride/memory/MEMORY.md` linking a new memory file. Skip if nothing was surprising.

- [ ] **Step 4: Final summary commit (only if any post-merge fixes accumulated)**

If individual commits already covered everything, skip. Otherwise:

```bash
git commit -m "chore(edition-admin): finalize deelnemers view-info pass"
```

---

## Out of scope (reminder)

- No mutating actions (`view as`, `contact`, `unsubscribe`, `switch user`) — separate design pass.
- No changes to Aanwezigheid tab, registration DB, services, repositories, or `plugin-config.php` services array.
- No JS framework changes (no Alpine, no Vue). Keep jQuery for parity with the rest of `edition-admin.js`.
