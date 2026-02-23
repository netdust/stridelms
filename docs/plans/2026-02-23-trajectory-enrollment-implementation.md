# Trajectory Enrollment Flow Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Implement complete frontend trajectory enrollment with clean URLs, unified form, and E2E test coverage.

**Architecture:** Extend existing enrollment infrastructure with trajectory support. Use `ntdst_router()` for clean URLs without rewrite rules. Migrate handlers to NTDST API pattern. Playwright E2E tests cover full user journey.

**Tech Stack:** PHP 8.3, NTDST Framework, UIkit 3, Playwright, WordPress/LearnDash

---

## Task 1: Create EnrollmentRouterService

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentRouterService.php`
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php` (add service registration)

**Step 1: Write the service file**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Enrollment;

use Stride\Modules\Trajectory\TrajectoryService;
use NTDST_Service_Meta;

/**
 * Routes enrollment URLs via ntdst_router().
 *
 * Handles:
 * - /trajecten/{slug}/inschrijving/
 * - /cursussen/{slug}/inschrijving/
 */
final class EnrollmentRouterService implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Enrollment Router',
            'description' => 'Routes enrollment URLs via ntdst_router',
            'priority' => 5,
        ];
    }

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // Trajectory enrollment: /trajecten/{slug}/inschrijving/
        ntdst_router()->get('trajecten/:slug/inschrijving', [$this, 'handleTrajectoryEnrollment']);

        // Course enrollment: /cursussen/{slug}/inschrijving/ (future)
        ntdst_router()->get('cursussen/:slug/inschrijving', [$this, 'handleCourseEnrollment']);
    }

    /**
     * Handle trajectory enrollment page.
     *
     * @param array{slug: string} $params
     * @return \NTDST_Response|false
     */
    public function handleTrajectoryEnrollment(array $params)
    {
        $slug = sanitize_title($params['slug'] ?? '');

        $trajectory = get_page_by_path($slug, OBJECT, 'vad_trajectory');

        if (!$trajectory) {
            return false; // Continue to 404
        }

        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url($_SERVER['REQUEST_URI']));
            exit;
        }

        // Check enrollment status
        $trajectoryService = ntdst_get(TrajectoryService::class);
        $userId = get_current_user_id();

        return ntdst_response()
            ->with('item', $trajectory)
            ->with('type', 'trajectory')
            ->with('canEnroll', $trajectoryService->isEnrollmentOpen($trajectory->ID))
            ->with('alreadyEnrolled', $trajectoryService->isUserEnrolled($userId, $trajectory->ID))
            ->template('enrollment/form');
    }

    /**
     * Handle course/edition enrollment page.
     *
     * @param array{slug: string} $params
     * @return \NTDST_Response|false
     */
    public function handleCourseEnrollment(array $params)
    {
        $slug = sanitize_title($params['slug'] ?? '');

        $course = get_page_by_path($slug, OBJECT, 'sfwd-courses');

        if (!$course) {
            return false;
        }

        if (!is_user_logged_in()) {
            wp_redirect(wp_login_url($_SERVER['REQUEST_URI']));
            exit;
        }

        return ntdst_response()
            ->with('item', $course)
            ->with('type', 'course')
            ->template('enrollment/form');
    }
}
```

**Step 2: Register service in plugin-config.php**

Add to the services array in `web/app/mu-plugins/stride-core/plugin-config.php`:

```php
\Stride\Modules\Enrollment\EnrollmentRouterService::class,
```

**Step 3: Verify service loads**

Run:
```bash
ddev exec wp eval "echo class_exists('\Stride\Modules\Enrollment\EnrollmentRouterService') ? 'OK' : 'FAIL';"
```
Expected: `OK`

**Step 4: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentRouterService.php
git add web/app/mu-plugins/stride-core/plugin-config.php
git commit -m "feat(enrollment): add EnrollmentRouterService for clean URLs"
```

---

## Task 2: Add isUserEnrolled to TrajectoryService

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryService.php`
- Test: `tests/Unit/TrajectoryServiceTest.php`

**Step 1: Write the failing test**

Add to `tests/Unit/TrajectoryServiceTest.php`:

```php
/**
 * @test
 */
public function it_checks_if_user_is_enrolled_in_trajectory(): void
{
    $service = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);

    // User not enrolled
    $result = $service->isUserEnrolled(99999, 1);
    $this->assertFalse($result);
}
```

**Step 2: Run test to verify it fails**

Run:
```bash
ddev exec vendor/bin/codecept run unit TrajectoryServiceTest:it_checks_if_user_is_enrolled_in_trajectory
```
Expected: FAIL with "method isUserEnrolled not found" or similar

**Step 3: Add isUserEnrolled method**

Add to `TrajectoryService.php`:

```php
/**
 * Check if user is enrolled in trajectory.
 */
public function isUserEnrolled(int $userId, int $trajectoryId): bool
{
    $repo = ntdst_get(TrajectoryEnrollmentRepository::class);
    $enrollment = $repo->findByUserAndTrajectory($userId, $trajectoryId);

    return $enrollment !== null;
}
```

**Step 4: Run test to verify it passes**

Run:
```bash
ddev exec vendor/bin/codecept run unit TrajectoryServiceTest:it_checks_if_user_is_enrolled_in_trajectory
```
Expected: PASS

**Step 5: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Trajectory/TrajectoryService.php
git add tests/Unit/TrajectoryServiceTest.php
git commit -m "feat(trajectory): add isUserEnrolled method"
```

---

## Task 3: Migrate EnrollmentFormHandler to NTDST API Pattern

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php`

**Step 1: Update init() to use NTDST API filters**

Replace the `init()` method:

```php
private function init(): void
{
    // NTDST API pattern (primary)
    add_filter('ntdst/api_data/stride_submit_enrollment', [$this, 'handleSubmitEnrollment'], 10, 2);
    add_filter('ntdst/api_data/stride_validate_voucher', [$this, 'handleValidateVoucher'], 10, 2);
    add_filter('ntdst/api_data/stride_save_session_selection', [$this, 'handleSaveSessionSelection'], 10, 2);

    // AJAX fallback for compatibility
    add_action('wp_ajax_stride_submit_enrollment', [$this, 'ajaxSubmitEnrollment']);
    add_action('wp_ajax_stride_validate_voucher', [$this, 'ajaxValidateVoucher']);
    add_action('wp_ajax_stride_save_session_selection', [$this, 'ajaxSaveSessionSelection']);
}
```

**Step 2: Update handleSubmitEnrollment signature**

Change method signature to accept NTDST API params:

```php
/**
 * Handle enrollment submission via NTDST API.
 *
 * @param mixed $data Existing data (unused)
 * @param array<string, mixed> $params Request parameters
 * @return array<string, mixed>|WP_Error
 */
public function handleSubmitEnrollment(mixed $data, array $params): array|WP_Error
{
    $userId = get_current_user_id();
    if (!$userId) {
        return new WP_Error('not_logged_in', __('Je moet ingelogd zijn om in te schrijven.', 'stride'));
    }

    $itemType = sanitize_text_field($params['item_type'] ?? 'edition');
    $itemId = absint($params['item_id'] ?? $params['edition_id'] ?? 0);

    ntdst_log('enrollment')->info('Enrollment form submitted', [
        'user_id' => $userId,
        'item_id' => $itemId,
        'item_type' => $itemType,
    ]);

    if (!$itemId) {
        return new WP_Error('invalid_input', __('Geen item opgegeven.', 'stride'));
    }

    // Route to appropriate handler
    if ($itemType === 'trajectory') {
        return $this->processTrajectoryEnrollment($userId, $itemId, $params);
    }

    // Default: edition enrollment (existing logic)
    return $this->processEditionEnrollment($userId, $itemId, $params);
}
```

**Step 3: Extract edition enrollment to separate method**

Add new private method:

```php
/**
 * Process edition enrollment (existing logic).
 */
private function processEditionEnrollment(int $userId, int $editionId, array $params): array|WP_Error
{
    $editions = ntdst_get(EditionService::class);
    if (!$editions->isEnrollmentOpen($editionId)) {
        return new WP_Error('enrollment_closed', __('Inschrijving is niet meer mogelijk voor deze editie.', 'stride'));
    }

    $enrollmentData = $this->sanitizeEnrollmentData($params, $userId, $editionId);

    $validation = $this->validateEnrollmentData($enrollmentData);
    if (is_wp_error($validation)) {
        return $validation;
    }

    $enrollment = ntdst_get(EnrollmentService::class);
    $result = $enrollment->processEnrollment($enrollmentData);

    if (is_wp_error($result)) {
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

**Step 4: Add trajectory enrollment method**

```php
/**
 * Process trajectory enrollment.
 */
private function processTrajectoryEnrollment(int $userId, int $trajectoryId, array $params): array|WP_Error
{
    $trajectoryService = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);

    if (!$trajectoryService->isEnrollmentOpen($trajectoryId)) {
        return new WP_Error('enrollment_closed', __('Inschrijving is niet meer mogelijk voor dit traject.', 'stride'));
    }

    if ($trajectoryService->isUserEnrolled($userId, $trajectoryId)) {
        return new WP_Error('already_enrolled', __('Je bent al ingeschreven voor dit traject.', 'stride'));
    }

    // Sanitize billing data
    $billingData = [
        'first_name' => sanitize_text_field($params['first_name'] ?? ''),
        'last_name' => sanitize_text_field($params['last_name'] ?? ''),
        'email' => sanitize_email($params['email'] ?? ''),
        'company' => sanitize_text_field($params['company'] ?? ''),
        'vat_number' => sanitize_text_field($params['vat_number'] ?? ''),
        'address' => sanitize_text_field($params['address'] ?? ''),
        'postal_code' => sanitize_text_field($params['postal_code'] ?? ''),
        'city' => sanitize_text_field($params['city'] ?? ''),
        'voucher_code' => sanitize_text_field($params['voucher_code'] ?? ''),
    ];

    // Validate required fields
    if (empty($billingData['first_name']) || empty($billingData['last_name'])) {
        return new WP_Error('validation_error', __('Voornaam en achternaam zijn vereist.', 'stride'));
    }

    if (empty($billingData['email'])) {
        return new WP_Error('validation_error', __('E-mailadres is vereist.', 'stride'));
    }

    $termsAccepted = (bool) ($params['terms_accepted'] ?? false);
    if (!$termsAccepted) {
        return new WP_Error('validation_error', __('Je moet akkoord gaan met de voorwaarden.', 'stride'));
    }

    // Create trajectory enrollment
    $selectionService = ntdst_get(\Stride\Modules\Trajectory\TrajectorySelectionService::class);
    $enrollmentId = $selectionService->enroll($userId, $trajectoryId);

    if (is_wp_error($enrollmentId)) {
        ntdst_log('enrollment')->error('Trajectory enrollment failed', [
            'user_id' => $userId,
            'trajectory_id' => $trajectoryId,
            'error' => $enrollmentId->get_error_message(),
        ]);
        return $enrollmentId;
    }

    // Update user profile with billing data
    $this->updateUserBilling($userId, $billingData);

    // Create quote for trajectory
    $quoteService = ntdst_get(\Stride\Modules\Invoicing\QuoteService::class);
    $quoteId = $quoteService->createForTrajectory($trajectoryId, $userId, $billingData);

    ntdst_log('enrollment')->info('Trajectory enrollment successful', [
        'user_id' => $userId,
        'trajectory_id' => $trajectoryId,
        'enrollment_id' => $enrollmentId,
        'quote_id' => $quoteId,
    ]);

    return [
        'success' => true,
        'message' => __('Je inschrijving voor het traject is succesvol verwerkt!', 'stride'),
        'enrollment_id' => $enrollmentId,
        'quote_id' => $quoteId,
        'redirect_url' => home_url('/mijn-account/mijn-trajecten/'),
    ];
}

/**
 * Update user billing information.
 */
private function updateUserBilling(int $userId, array $data): void
{
    $metaFields = [
        'company' => 'company',
        'vat_number' => 'vat_number',
        'address' => 'billing_address',
        'postal_code' => 'billing_postal_code',
        'city' => 'billing_city',
    ];

    foreach ($metaFields as $dataKey => $metaKey) {
        if (!empty($data[$dataKey])) {
            update_user_meta($userId, $metaKey, $data[$dataKey]);
        }
    }

    if (!empty($data['first_name']) || !empty($data['last_name'])) {
        wp_update_user([
            'ID' => $userId,
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
        ]);
    }
}
```

**Step 5: Update handleValidateVoucher for unified type support**

```php
/**
 * Handle voucher validation via NTDST API.
 *
 * @param mixed $data Existing data (unused)
 * @param array<string, mixed> $params Request parameters
 * @return array<string, mixed>|WP_Error
 */
public function handleValidateVoucher(mixed $data, array $params): array|WP_Error
{
    $code = sanitize_text_field($params['code'] ?? '');
    $itemId = absint($params['item_id'] ?? $params['edition_id'] ?? 0);
    $itemType = sanitize_text_field($params['item_type'] ?? 'edition');

    if (empty($code)) {
        return new WP_Error('invalid_input', __('Vouchercode is vereist.', 'stride'));
    }

    $vouchers = ntdst_get(VoucherService::class);

    $validation = $vouchers->validateVoucher($code, $itemId, 0, $itemType);
    if (is_wp_error($validation)) {
        return new WP_Error('invalid_voucher', __('Vouchercode ongeldig of verlopen.', 'stride'));
    }

    // Get price based on type
    if ($itemType === 'trajectory') {
        $trajectoryService = ntdst_get(\Stride\Modules\Trajectory\TrajectoryService::class);
        $price = $trajectoryService->getPrice($itemId);
    } else {
        $editions = ntdst_get(EditionService::class);
        $price = $editions->getPrice($itemId);
    }

    $discount = $vouchers->calculateDiscount($validation, $itemType, $itemId, $price);

    return [
        'valid' => true,
        'discount' => $discount,
        'discount_formatted' => '€ ' . number_format($discount, 2, ',', '.'),
        'discount_type' => $validation['discount_type'],
        'message' => sprintf(__('Korting toegepast: -€ %s', 'stride'), number_format($discount, 2, ',', '.')),
    ];
}
```

**Step 6: Verify handler loads**

Run:
```bash
ddev exec wp eval "echo has_filter('ntdst/api_data/stride_submit_enrollment') ? 'OK' : 'FAIL';"
```
Expected: `OK`

**Step 7: Commit**

```bash
git add web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php
git commit -m "refactor(enrollment): migrate handler to NTDST API pattern with trajectory support"
```

---

## Task 4: Update Enrollment Form Template for Unified Support

**Files:**
- Modify: `web/app/themes/stride/templates/enrollment/form.php`
- Create: `web/app/themes/stride/templates/enrollment/partials/trajectory-sidebar.php`

**Step 1: Update form.php header for unified type detection**

Replace lines 1-90 of `templates/enrollment/form.php`:

```php
<?php
/**
 * Unified Enrollment Form Template
 *
 * Supports both edition and trajectory enrollment.
 * Two-column layout: Form fields | Item details sidebar
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Trajectory\TrajectoryService;

// Services
$editionService = ntdst_get(EditionService::class);
$sessionService = ntdst_get(SessionService::class);
$enrollmentService = ntdst_get(EnrollmentService::class);
$trajectoryService = ntdst_get(TrajectoryService::class);

// User info
$user = wp_get_current_user();
$userId = $user->ID;

// ─────────────────────────────────────────────────────────────
// UNIFIED ITEM DETECTION
// ─────────────────────────────────────────────────────────────

// Check if passed via ntdst_response (from router)
$item = $item ?? null;
$type = $type ?? null;
$canEnroll = $canEnroll ?? null;
$alreadyEnrolled = $alreadyEnrolled ?? null;

// Fallback to query params (for direct page access)
if (!$type) {
    $trajectoryId = absint($_GET['trajectory'] ?? 0);
    $editionId = absint($_GET['edition'] ?? 0);

    if ($trajectoryId) {
        $type = 'trajectory';
        $item = get_post($trajectoryId);
        $canEnroll = $trajectoryService ? $trajectoryService->isEnrollmentOpen($trajectoryId) : false;
        $alreadyEnrolled = $trajectoryService ? $trajectoryService->isUserEnrolled($userId, $trajectoryId) : false;
    } elseif ($editionId) {
        $type = 'edition';
        $item = $editionService ? $editionService->getEdition($editionId) : null;
        $canEnroll = $editionService ? $editionService->canEnroll($editionId) : false;
        $alreadyEnrolled = $enrollmentService ? $enrollmentService->isEnrolled($userId, $editionId) : false;
    }
}

// Validate we have an item
$itemId = 0;
$itemTitle = '';

if ($item) {
    if (is_object($item) && isset($item->ID)) {
        $itemId = $item->ID;
        $itemTitle = $item->post_title ?? '';
    } elseif (is_array($item)) {
        $itemId = $item['id'] ?? 0;
        $itemTitle = $item['title'] ?? '';
    }
}

// Type-specific data
if ($type === 'trajectory') {
    $backUrl = get_permalink($itemId);
    $backLabel = __('Terug naar traject', 'stride');
    $catalogUrl = home_url('/trajecten/');
    $catalogLabel = __('Bekijk trajecten', 'stride');
    $successUrl = home_url('/mijn-account/mijn-trajecten/');

    // Trajectory details
    $trajectoryData = $trajectoryService ? $trajectoryService->getTrajectory($itemId) : null;
    $price = $trajectoryData['price'] ?? 0;
    $requiredCourses = $trajectoryService ? $trajectoryService->getRequiredCourses($itemId) : [];
    $electiveGroups = $trajectoryService ? $trajectoryService->getElectiveGroups($itemId) : [];
    $courseCount = $trajectoryService ? $trajectoryService->getCourseCount($itemId) : 0;
} else {
    $backUrl = get_permalink($itemId);
    $backLabel = __('Terug naar cursus', 'stride');
    $catalogUrl = home_url('/cursussen/');
    $catalogLabel = __('Bekijk cursussen', 'stride');
    $successUrl = home_url('/mijn-account/mijn-cursussen/');

    // Edition details (existing logic)
    $courseId = $editionService ? $editionService->getCourseId($itemId) : null;
    $price = $editionService ? $editionService->getPrice($itemId, true) : null;
    $sessions = $sessionService ? $sessionService->getSessionsForEdition($itemId) : [];
}

// ─────────────────────────────────────────────────────────────
// AUTH CHECK
// ─────────────────────────────────────────────────────────────

if (!is_user_logged_in()) {
    ?>
    <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
        <div class="stride-card uk-text-center uk-padding-large">
            <div class="stride-empty-state__icon uk-margin-bottom">
                <span uk-icon="icon: lock; ratio: 2"></span>
            </div>
            <h2><?php esc_html_e('Log in om in te schrijven', 'stride'); ?></h2>
            <p class="uk-text-muted uk-margin-bottom">
                <?php esc_html_e('Je moet ingelogd zijn om je in te schrijven.', 'stride'); ?>
            </p>
            <a href="<?php echo esc_url(wp_login_url($_SERVER['REQUEST_URI'])); ?>" class="uk-button uk-button-primary uk-button-large">
                <?php esc_html_e('Inloggen', 'stride'); ?>
            </a>
        </div>
    </div>
    <?php
    return;
}

// ─────────────────────────────────────────────────────────────
// ITEM VALIDATION
// ─────────────────────────────────────────────────────────────

if (!$itemId) {
    ?>
    <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
        <div class="stride-card uk-text-center uk-padding-large">
            <div class="stride-empty-state__icon uk-margin-bottom">
                <span uk-icon="icon: warning; ratio: 2"></span>
            </div>
            <h2><?php esc_html_e('Geen item geselecteerd', 'stride'); ?></h2>
            <p class="uk-text-muted uk-margin-bottom">
                <?php esc_html_e('Selecteer eerst een cursus of traject om je in te schrijven.', 'stride'); ?>
            </p>
            <a href="<?php echo esc_url($catalogUrl); ?>" class="uk-button uk-button-primary">
                <?php echo esc_html($catalogLabel); ?>
            </a>
        </div>
    </div>
    <?php
    return;
}

// ─────────────────────────────────────────────────────────────
// ALREADY ENROLLED CHECK
// ─────────────────────────────────────────────────────────────

if ($alreadyEnrolled) {
    ?>
    <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
        <div class="stride-card uk-text-center uk-padding-large">
            <div class="stride-empty-state__icon uk-margin-bottom">
                <span uk-icon="icon: check; ratio: 2" class="uk-text-success"></span>
            </div>
            <h2><?php esc_html_e('Je bent al ingeschreven', 'stride'); ?></h2>
            <p class="uk-text-muted uk-margin-bottom">
                <?php esc_html_e('Je bent al ingeschreven voor dit item.', 'stride'); ?>
            </p>
            <a href="<?php echo esc_url($successUrl); ?>" class="uk-button uk-button-primary">
                <?php esc_html_e('Bekijk mijn inschrijvingen', 'stride'); ?>
            </a>
        </div>
    </div>
    <?php
    return;
}

// ─────────────────────────────────────────────────────────────
// ENROLLMENT CLOSED CHECK
// ─────────────────────────────────────────────────────────────

if (!$canEnroll) {
    ?>
    <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
        <div class="stride-card uk-text-center uk-padding-large">
            <div class="stride-empty-state__icon uk-margin-bottom">
                <span uk-icon="icon: ban; ratio: 2" class="uk-text-danger"></span>
            </div>
            <h2><?php esc_html_e('Inschrijving niet mogelijk', 'stride'); ?></h2>
            <p class="uk-text-muted uk-margin-bottom">
                <?php esc_html_e('Inschrijving is momenteel niet mogelijk. De inschrijving is gesloten of volzet.', 'stride'); ?>
            </p>
            <a href="<?php echo esc_url($catalogUrl); ?>" class="uk-button uk-button-default">
                <?php echo esc_html($catalogLabel); ?>
            </a>
        </div>
    </div>
    <?php
    return;
}

// ─────────────────────────────────────────────────────────────
// USER BILLING DATA (pre-fill form)
// ─────────────────────────────────────────────────────────────

$firstName = $user->first_name;
$lastName = $user->last_name;
$email = $user->user_email;
$company = get_user_meta($userId, 'company', true);
$vatNumber = get_user_meta($userId, 'vat_number', true);
$address = get_user_meta($userId, 'billing_address', true);
$city = get_user_meta($userId, 'billing_city', true);
$postalCode = get_user_meta($userId, 'billing_postal_code', true);

// Hero image
$heroImage = get_the_post_thumbnail_url($itemId, 'medium');
?>
```

**Step 2: Create trajectory sidebar partial**

Create `templates/enrollment/partials/trajectory-sidebar.php`:

```php
<?php
/**
 * Trajectory Sidebar Partial
 *
 * Shows trajectory details in enrollment form sidebar.
 *
 * @var int $itemId
 * @var string $itemTitle
 * @var array $trajectoryData
 * @var array $requiredCourses
 * @var array $electiveGroups
 * @var int $courseCount
 * @var float|int $price
 * @var string|null $heroImage
 */

defined('ABSPATH') || exit;

$requiredCount = count($requiredCourses);
$electiveCount = 0;
foreach ($electiveGroups as $group) {
    $electiveCount += count($group);
}
?>

<div class="stride-course-info-card">
    <?php if ($heroImage): ?>
        <div class="stride-course-info-image">
            <img src="<?php echo esc_url($heroImage); ?>" alt="<?php echo esc_attr($itemTitle); ?>">
        </div>
    <?php endif; ?>

    <div class="stride-course-info-header" style="background: linear-gradient(135deg, var(--stride-secondary) 0%, var(--stride-secondary-hover) 100%);">
        <h3 class="stride-course-info-title"><?php echo esc_html($itemTitle); ?></h3>
        <span class="uk-label"><?php esc_html_e('Traject', 'stride'); ?></span>
    </div>

    <div class="stride-course-info-body">
        <ul class="stride-course-info-list">
            <li class="stride-course-info-item">
                <span class="stride-course-info-icon" uk-icon="icon: git-branch; ratio: 0.9"></span>
                <span>
                    <?php printf(
                        esc_html(_n('%d cursus totaal', '%d cursussen totaal', $courseCount, 'stride')),
                        $courseCount
                    ); ?>
                </span>
            </li>

            <li class="stride-course-info-item">
                <span class="stride-course-info-icon" uk-icon="icon: check; ratio: 0.9"></span>
                <span>
                    <?php printf(
                        esc_html(_n('%d verplichte cursus', '%d verplichte cursussen', $requiredCount, 'stride')),
                        $requiredCount
                    ); ?>
                </span>
            </li>

            <?php if ($electiveCount > 0): ?>
                <li class="stride-course-info-item">
                    <span class="stride-course-info-icon" uk-icon="icon: plus-circle; ratio: 0.9"></span>
                    <span>
                        <?php printf(
                            esc_html(_n('%d keuzecursus', '%d keuzecursussen', $electiveCount, 'stride')),
                            $electiveCount
                        ); ?>
                    </span>
                </li>
            <?php endif; ?>
        </ul>

        <hr class="uk-margin-small">

        <!-- Price Summary -->
        <table class="uk-table uk-table-small uk-margin-remove-bottom">
            <tbody>
                <tr>
                    <td><?php esc_html_e('Trajectprijs', 'stride'); ?></td>
                    <td class="uk-text-right" id="line-item-price">
                        <?php echo '€ ' . number_format((float) $price, 2, ',', '.'); ?>
                    </td>
                </tr>
                <tr id="discount-row" style="display: none;">
                    <td class="uk-text-success"><?php esc_html_e('Korting', 'stride'); ?></td>
                    <td class="uk-text-right uk-text-success" id="discount-amount">- € 0,00</td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                    <td class="uk-text-right" id="subtotal">
                        <?php echo '€ ' . number_format((float) $price, 2, ',', '.'); ?>
                    </td>
                </tr>
                <tr>
                    <td class="uk-text-muted"><?php esc_html_e('BTW (21%)', 'stride'); ?></td>
                    <td class="uk-text-right uk-text-muted" id="tax-amount">
                        <?php echo '€ ' . number_format((float) $price * 0.21, 2, ',', '.'); ?>
                    </td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="uk-text-bold" style="font-size: 1.1em;">
                    <td><?php esc_html_e('Totaal', 'stride'); ?></td>
                    <td class="uk-text-right" id="total-amount">
                        <?php echo '€ ' . number_format((float) $price * 1.21, 2, ',', '.'); ?>
                    </td>
                </tr>
            </tfoot>
        </table>

        <?php if ($electiveCount > 0): ?>
            <div class="uk-alert uk-alert-primary uk-margin-top uk-margin-remove-bottom">
                <span uk-icon="icon: info; ratio: 0.8"></span>
                <span class="uk-text-small">
                    <?php esc_html_e('Na inschrijving kies je je keuzecursussen via je dashboard.', 'stride'); ?>
                </span>
            </div>
        <?php endif; ?>
    </div>
</div>
```

**Step 3: Update sidebar include in form.php**

Find the sidebar section (around line 324) and update to conditionally include the appropriate partial:

```php
<!-- Sidebar: Item Details & Price Summary -->
<div class="uk-width-1-3@m">
    <div uk-sticky="offset: 100; bottom: true; media: @m;">
        <?php if ($type === 'trajectory'): ?>
            <?php include get_theme_file_path('templates/enrollment/partials/trajectory-sidebar.php'); ?>
        <?php else: ?>
            <!-- Existing edition sidebar -->
            <?php include get_theme_file_path('templates/enrollment/partials/edition-sidebar.php'); ?>
        <?php endif; ?>

        <!-- Terms (desktop only) - shared -->
        <div class="uk-visible@m uk-margin-top">
            <div class="uk-margin-small-bottom">
                <label class="uk-text-small">
                    <input type="checkbox" form="stride-enrollment-form" name="terms_accepted" value="1" required class="uk-checkbox terms-checkbox">
                    <?php printf(
                        esc_html__('Ik ga akkoord met de %salgemene voorwaarden%s', 'stride'),
                        '<a href="' . esc_url(home_url('/algemene-voorwaarden/')) . '" target="_blank">',
                        '</a>'
                    ); ?> *
                </label>
            </div>

            <div class="uk-margin-bottom">
                <label class="uk-text-small">
                    <input type="checkbox" form="stride-enrollment-form" name="cancellation_accepted" value="1" required class="uk-checkbox cancellation-checkbox">
                    <?php esc_html_e('Ik begrijp dat annulering binnen 14 dagen voor aanvang niet mogelijk is', 'stride'); ?> *
                </label>
            </div>

            <button type="submit" form="stride-enrollment-form" class="uk-button uk-button-primary uk-button-large uk-width-1-1 submit-enrollment">
                <?php esc_html_e('Bevestig inschrijving', 'stride'); ?>
            </button>

            <p class="uk-text-small uk-text-muted uk-text-center uk-margin-small-top">
                <?php esc_html_e('Na inschrijving ontvang je een bevestigingsmail met je offerte.', 'stride'); ?>
            </p>
        </div>
    </div>
</div>
```

**Step 4: Update form hidden fields**

Update the form hidden fields section:

```php
<form id="stride-enrollment-form" method="post" class="stride-enrollment-form">
    <?php wp_nonce_field('stride_enrollment', 'nonce'); ?>
    <input type="hidden" name="item_id" value="<?php echo esc_attr($itemId); ?>">
    <input type="hidden" name="item_type" value="<?php echo esc_attr($type); ?>">
    <!-- Keep edition_id for backwards compatibility -->
    <?php if ($type === 'edition'): ?>
        <input type="hidden" name="edition_id" value="<?php echo esc_attr($itemId); ?>">
    <?php endif; ?>
```

**Step 5: Commit**

```bash
git add web/app/themes/stride/templates/enrollment/form.php
git add web/app/themes/stride/templates/enrollment/partials/trajectory-sidebar.php
git commit -m "feat(enrollment): unified form template with trajectory support"
```

---

## Task 5: Update Form JavaScript to Use ntdstAPI

**Files:**
- Modify: `web/app/themes/stride/templates/enrollment/form.php` (JavaScript section)

**Step 1: Replace JavaScript at bottom of form.php**

Replace the entire `<script>` section (starting around line 448):

```php
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('stride-enrollment-form');
    const submitBtns = document.querySelectorAll('.submit-enrollment');
    const voucherInput = document.getElementById('voucher_code');
    const applyVoucherBtn = document.getElementById('apply-voucher');
    const voucherResult = document.getElementById('voucher-result');

    const itemType = '<?php echo esc_js($type); ?>';
    const itemId = <?php echo (int) $itemId; ?>;

    // Sync checkboxes between mobile and desktop
    document.querySelectorAll('.terms-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            document.querySelectorAll('.terms-checkbox').forEach(other => {
                other.checked = this.checked;
            });
        });
    });

    document.querySelectorAll('.cancellation-checkbox').forEach(cb => {
        cb.addEventListener('change', function() {
            document.querySelectorAll('.cancellation-checkbox').forEach(other => {
                other.checked = this.checked;
            });
        });
    });

    // ─────────────────────────────────────────────────────────────
    // VOUCHER VALIDATION (ntdstAPI)
    // ─────────────────────────────────────────────────────────────

    if (applyVoucherBtn) {
        applyVoucherBtn.addEventListener('click', async function() {
            const code = voucherInput.value.trim();
            if (!code) return;

            applyVoucherBtn.disabled = true;
            applyVoucherBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

            try {
                const result = await ntdstAPI.call('stride_validate_voucher', {
                    code: code,
                    item_id: itemId,
                    item_type: itemType
                });

                voucherResult.style.display = 'block';
                voucherResult.innerHTML = '<div class="uk-alert uk-alert-success uk-margin-remove">' + result.message + '</div>';

                // Update prices
                if (result.discount) {
                    document.getElementById('discount-row').style.display = '';
                    document.getElementById('discount-amount').textContent = '- ' + result.discount_formatted;

                    // Calculate new totals
                    const originalPrice = <?php echo (float) (is_object($price) ? $price->inCents() / 100 : $price); ?>;
                    const discount = result.discount;
                    const subtotal = originalPrice - discount;
                    const tax = subtotal * 0.21;
                    const total = subtotal + tax;

                    document.getElementById('subtotal').textContent = '€ ' + subtotal.toFixed(2).replace('.', ',');
                    document.getElementById('tax-amount').textContent = '€ ' + tax.toFixed(2).replace('.', ',');
                    document.getElementById('total-amount').textContent = '€ ' + total.toFixed(2).replace('.', ',');
                }
            } catch (error) {
                voucherResult.style.display = 'block';
                voucherResult.innerHTML = '<div class="uk-alert uk-alert-danger uk-margin-remove">' + error.message + '</div>';
            } finally {
                applyVoucherBtn.disabled = false;
                applyVoucherBtn.textContent = '<?php esc_html_e('Toepassen', 'stride'); ?>';
            }
        });
    }

    // ─────────────────────────────────────────────────────────────
    // FORM SUBMISSION (ntdstAPI)
    // ─────────────────────────────────────────────────────────────

    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            // Validate checkboxes
            const termsChecked = document.querySelector('.terms-checkbox:checked');
            const cancellationChecked = document.querySelector('.cancellation-checkbox:checked');

            if (!termsChecked || !cancellationChecked) {
                UIkit.notification({
                    message: '<?php esc_html_e('Je moet akkoord gaan met de voorwaarden', 'stride'); ?>',
                    status: 'warning',
                    pos: 'top-center'
                });
                return;
            }

            // Disable buttons
            submitBtns.forEach(btn => {
                btn.disabled = true;
                btn.innerHTML = '<span uk-spinner="ratio: 0.6"></span> <?php esc_html_e('Bezig...', 'stride'); ?>';
            });

            try {
                // Collect form data
                const formData = new FormData(form);
                const params = Object.fromEntries(formData.entries());

                // Ensure item info is included
                params.item_id = itemId;
                params.item_type = itemType;
                params.terms_accepted = '1';

                const result = await ntdstAPI.call('stride_submit_enrollment', params);

                // Success - redirect
                if (result.redirect_url) {
                    window.location.href = result.redirect_url;
                } else {
                    window.location.href = '<?php echo esc_url($successUrl); ?>?enrolled=1';
                }

            } catch (error) {
                UIkit.notification({
                    message: error.message || '<?php esc_html_e('Er is een fout opgetreden', 'stride'); ?>',
                    status: 'danger',
                    pos: 'top-center'
                });

                // Re-enable buttons
                submitBtns.forEach(btn => {
                    btn.disabled = false;
                    btn.textContent = '<?php esc_html_e('Bevestig inschrijving', 'stride'); ?>';
                });
            }
        });
    }
});
</script>
```

**Step 2: Verify ntdstAPI is available**

Run in browser console on enrollment page:
```javascript
typeof ntdstAPI !== 'undefined'
```
Expected: `true`

**Step 3: Commit**

```bash
git add web/app/themes/stride/templates/enrollment/form.php
git commit -m "refactor(enrollment): use ntdstAPI instead of fetch for form submission"
```

---

## Task 6: Update single-vad_trajectory.php CTA Links

**Files:**
- Modify: `web/app/themes/stride/single-vad_trajectory.php`

**Step 1: Update enrollment button URLs**

Find line ~364 and ~408 and change from:
```php
add_query_arg('trajectory', $trajectoryId, home_url('/inschrijven-traject/'))
```

To:
```php
get_permalink($trajectoryId) . 'inschrijving/'
```

Full context for line ~364:
```php
<?php elseif (is_user_logged_in()): ?>
    <a href="<?php echo esc_url(get_permalink($trajectoryId) . 'inschrijving/'); ?>" class="stride-course-action-btn uk-button uk-button-primary">
        <?php esc_html_e('Start dit traject', 'stride'); ?>
    </a>
```

And line ~408 (mobile CTA):
```php
<?php else: ?>
    <a href="<?php echo esc_url(get_permalink($trajectoryId) . 'inschrijving/'); ?>" class="uk-button uk-button-primary uk-button-small">
        <?php esc_html_e('Start traject', 'stride'); ?>
    </a>
```

**Step 2: Test URL generation**

Run:
```bash
ddev exec wp eval "echo get_permalink(get_posts(['post_type' => 'vad_trajectory', 'numberposts' => 1])[0]->ID) . 'inschrijving/';"
```
Expected: URL like `https://stride.ddev.site/trajecten/test-trajectory/inschrijving/`

**Step 3: Commit**

```bash
git add web/app/themes/stride/single-vad_trajectory.php
git commit -m "fix(trajectory): update enrollment CTA to use clean URLs"
```

---

## Task 7: Add Seed Data for E2E Tests

**Files:**
- Modify: `scripts/seed.php`

**Step 1: Add trajectory enrollment test data**

Add to seed.php after existing user creation:

```php
// ─────────────────────────────────────────────────────────────
// TRAJECTORY TEST DATA
// ─────────────────────────────────────────────────────────────

echo "Creating trajectory test data...\n";

// Get or create test trajectory
$testTrajectory = get_page_by_path('test-trajectory', OBJECT, 'vad_trajectory');
if (!$testTrajectory) {
    $testTrajectoryId = wp_insert_post([
        'post_type' => 'vad_trajectory',
        'post_title' => 'Test Trajectory',
        'post_name' => 'test-trajectory',
        'post_status' => 'publish',
        'post_content' => 'A test trajectory for E2E testing.',
    ]);

    // Set trajectory meta
    $trajectoryModel = ntdst_data()->get('vad_trajectory');
    $trajectoryModel->updateMetaBatch($testTrajectoryId, [
        'mode' => 'cohort',
        'status' => 'open',
        'price' => 500,
        'enrollment_deadline' => date('Y-m-d', strtotime('+30 days')),
    ]);

    echo "  Created test-trajectory (ID: $testTrajectoryId)\n";
} else {
    $testTrajectoryId = $testTrajectory->ID;
    echo "  test-trajectory already exists (ID: $testTrajectoryId)\n";
}

// Create enrolled test user
$enrolledUser = get_user_by('email', 'seed_enrolled_user@seed.test');
if (!$enrolledUser) {
    $enrolledUserId = wp_create_user(
        'seed_enrolled_user',
        'seedpass123',
        'seed_enrolled_user@seed.test'
    );
    wp_update_user([
        'ID' => $enrolledUserId,
        'first_name' => 'Enrolled',
        'last_name' => 'User',
    ]);
    echo "  Created seed_enrolled_user@seed.test\n";
} else {
    $enrolledUserId = $enrolledUser->ID;
    echo "  seed_enrolled_user@seed.test already exists\n";
}

// Enroll user in trajectory
$trajectoryEnrollmentRepo = ntdst_get(\Stride\Modules\Trajectory\TrajectoryEnrollmentRepository::class);
$existingEnrollment = $trajectoryEnrollmentRepo->findByUserAndTrajectory($enrolledUserId, $testTrajectoryId);
if (!$existingEnrollment) {
    $trajectoryEnrollmentRepo->create([
        'user_id' => $enrolledUserId,
        'trajectory_id' => $testTrajectoryId,
        'status' => 'active',
    ]);
    echo "  Enrolled seed_enrolled_user in test-trajectory\n";
}

// Create completed test user
$completedUser = get_user_by('email', 'seed_completed_user@seed.test');
if (!$completedUser) {
    $completedUserId = wp_create_user(
        'seed_completed_user',
        'seedpass123',
        'seed_completed_user@seed.test'
    );
    wp_update_user([
        'ID' => $completedUserId,
        'first_name' => 'Completed',
        'last_name' => 'User',
    ]);
    echo "  Created seed_completed_user@seed.test\n";
}

echo "Trajectory test data complete.\n\n";
```

**Step 2: Run seed script**

```bash
ddev exec wp eval-file scripts/seed.php
```

**Step 3: Verify seed data**

```bash
ddev exec wp eval "echo get_page_by_path('test-trajectory', OBJECT, 'vad_trajectory') ? 'OK' : 'FAIL';"
```
Expected: `OK`

**Step 4: Commit**

```bash
git add scripts/seed.php
git commit -m "test(seed): add trajectory enrollment test data"
```

---

## Task 8: Create Playwright E2E Tests

**Files:**
- Create: `tests/frontend/enrollment/trajectory-enrollment.spec.ts`
- Create: `tests/frontend/enrollment/fixtures/test-data.ts`

**Step 1: Create test fixtures**

Create `tests/frontend/enrollment/fixtures/test-data.ts`:

```typescript
export const testUsers = {
    student: {
        email: 'seed_student1@seed.test',
        password: 'seedpass123',
    },
    enrolledUser: {
        email: 'seed_enrolled_user@seed.test',
        password: 'seedpass123',
    },
    completedUser: {
        email: 'seed_completed_user@seed.test',
        password: 'seedpass123',
    },
};

export const testTrajectories = {
    open: {
        slug: 'test-trajectory',
        title: 'Test Trajectory',
    },
};

export const testVouchers = {
    valid: 'SEEDVOUCHER10',
    invalid: 'INVALID123',
};

export async function login(page: any, user: { email: string; password: string }) {
    await page.goto('/wp-login.php');
    await page.fill('#user_login', user.email);
    await page.fill('#user_pass', user.password);
    await page.click('#wp-submit');
    await page.waitForURL('**/wp-admin/**');
}
```

**Step 2: Create main test file**

Create `tests/frontend/enrollment/trajectory-enrollment.spec.ts`:

```typescript
import { test, expect } from '@playwright/test';
import { testUsers, testTrajectories, testVouchers, login } from './fixtures/test-data';

test.describe('Trajectory Enrollment Flow', () => {

    // ─────────────────────────────────────────────────────────────
    // DISCOVERY & NAVIGATION
    // ─────────────────────────────────────────────────────────────

    test('user can browse trajectory catalog', async ({ page }) => {
        await page.goto('/trajecten/');

        await expect(page.locator('h1')).toBeVisible();
        await expect(page.locator('.stride-card, article')).toHaveCount({ min: 1 });
    });

    test('user can view trajectory detail page', async ({ page }) => {
        await page.goto(`/trajecten/${testTrajectories.open.slug}/`);

        await expect(page.locator('h1')).toContainText(testTrajectories.open.title);
        await expect(page.locator('.stride-modules-list, .stride-course-info-card')).toBeVisible();
    });

    test('enrollment button links to /inschrijving subpage', async ({ page }) => {
        await login(page, testUsers.student);
        await page.goto(`/trajecten/${testTrajectories.open.slug}/`);

        const enrollBtn = page.locator('a:has-text("Start"), a:has-text("traject")').first();
        const href = await enrollBtn.getAttribute('href');

        expect(href).toMatch(/\/inschrijving\/?$/);
    });

    // ─────────────────────────────────────────────────────────────
    // AUTHENTICATION GATE
    // ─────────────────────────────────────────────────────────────

    test('unauthenticated user is redirected to login', async ({ page }) => {
        await page.goto(`/trajecten/${testTrajectories.open.slug}/inschrijving/`);

        await expect(page).toHaveURL(/wp-login\.php/);
    });

    test('login redirects back to enrollment page', async ({ page }) => {
        await page.goto(`/trajecten/${testTrajectories.open.slug}/inschrijving/`);

        // Should be on login with redirect
        await expect(page).toHaveURL(/redirect_to=.*inschrijving/);

        // Login
        await page.fill('#user_login', testUsers.student.email);
        await page.fill('#user_pass', testUsers.student.password);
        await page.click('#wp-submit');

        // Should return to enrollment
        await expect(page).toHaveURL(/\/inschrijving\/?$/);
    });

    // ─────────────────────────────────────────────────────────────
    // ENROLLMENT FORM
    // ─────────────────────────────────────────────────────────────

    test.describe('authenticated user', () => {
        test.beforeEach(async ({ page }) => {
            await login(page, testUsers.student);
        });

        test('enrollment form displays trajectory details', async ({ page }) => {
            await page.goto(`/trajecten/${testTrajectories.open.slug}/inschrijving/`);

            await expect(page.locator('.stride-course-info-card, .stride-enrollment')).toBeVisible();
            await expect(page.locator('#stride-enrollment-form')).toBeVisible();
        });

        test('enrollment form pre-fills user email', async ({ page }) => {
            await page.goto(`/trajecten/${testTrajectories.open.slug}/inschrijving/`);

            const emailValue = await page.locator('#email').inputValue();
            expect(emailValue).toContain('seed_student1');
        });

        test('invalid voucher shows error', async ({ page }) => {
            await page.goto(`/trajecten/${testTrajectories.open.slug}/inschrijving/`);

            await page.fill('#voucher_code', testVouchers.invalid);
            await page.click('#apply-voucher');

            await expect(page.locator('#voucher-result')).toContainText(/ongeldig|verlopen|invalid/i);
        });

        test('form submission requires terms acceptance', async ({ page }) => {
            await page.goto(`/trajecten/${testTrajectories.open.slug}/inschrijving/`);

            await page.fill('#first_name', 'Test');
            await page.fill('#last_name', 'User');
            await page.click('.submit-enrollment');

            // Should show warning
            await expect(page.locator('.uk-notification')).toBeVisible();
        });
    });

    // ─────────────────────────────────────────────────────────────
    // ALREADY ENROLLED
    // ─────────────────────────────────────────────────────────────

    test('already enrolled user sees message instead of form', async ({ page }) => {
        await login(page, testUsers.enrolledUser);
        await page.goto(`/trajecten/${testTrajectories.open.slug}/inschrijving/`);

        await expect(page.locator('text=al ingeschreven')).toBeVisible();
        await expect(page.locator('#stride-enrollment-form')).not.toBeVisible();
    });
});
```

**Step 3: Run tests**

```bash
npx playwright test tests/frontend/enrollment/trajectory-enrollment.spec.ts --headed
```

**Step 4: Commit**

```bash
git add tests/frontend/enrollment/
git commit -m "test(e2e): add Playwright tests for trajectory enrollment flow"
```

---

## Verification Stages (MANDATORY)

> Run AFTER all implementation tasks. NOT done until all stages pass.
> If ANY stage fails: fix → re-run that stage → continue.

### Stage V1: Static Analysis

```bash
ddev exec vendor/bin/phpcs --standard=PSR12 \
    web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentRouterService.php \
    web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php

ddev exec vendor/bin/phpstan analyse \
    web/app/mu-plugins/stride-core/Modules/Enrollment/EnrollmentRouterService.php \
    web/app/mu-plugins/stride-core/Handlers/EnrollmentFormHandler.php \
    --level=5
```

Expected: No errors.

### Stage V2: Unit Tests

```bash
ddev exec vendor/bin/codecept run unit TrajectoryServiceTest
```

Expected: ALL tests pass.

### Stage V3: Acceptance Tests (Browser)

**Scenarios covered:**

```
VISITOR FLOW:
  SCENARIO: Unauthenticated enrollment redirect
    GIVEN: User not logged in
    WHEN: Visit /trajecten/test-trajectory/inschrijving/
    THEN: Redirected to wp-login.php with redirect_to param

USER FLOW:
  SCENARIO: Successful trajectory enrollment
    GIVEN: User logged in, not enrolled
    WHEN: Fill form, accept terms, submit
    THEN: Redirect to dashboard, enrollment created

ERROR FLOW:
  SCENARIO: Already enrolled
    GIVEN: User already enrolled in trajectory
    WHEN: Visit enrollment page
    THEN: See "already enrolled" message, no form

  SCENARIO: Invalid voucher
    GIVEN: User on enrollment form
    WHEN: Enter invalid voucher code
    THEN: Error message shown
```

```bash
npx playwright test tests/frontend/enrollment/trajectory-enrollment.spec.ts
```

Expected: ALL acceptance tests pass.

### Stage V4: Full Regression

```bash
ddev exec vendor/bin/codecept run
npx playwright test
```

Expected: Zero failures across all suites.

### Stage V5: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Visit: /trajecten/test-trajectory/
      Expected: Trajectory detail page loads, "Start dit traject" button visible

- [ ] Click: "Start dit traject" (not logged in)
      Expected: Redirect to login page

- [ ] Login and return to enrollment
      Expected: Form displays with trajectory details in sidebar

- [ ] Fill form, check terms, submit
      Expected: Success, redirect to /mijn-account/mijn-trajecten/

- [ ] Return to enrollment page
      Expected: "Already enrolled" message

- [ ] Database: `ddev exec wp db query "SELECT * FROM wp_vad_trajectory_enrollments ORDER BY id DESC LIMIT 1"`
      Expected: New enrollment record exists
```

---

## Summary

| Task | Description |
|------|-------------|
| 1 | Create EnrollmentRouterService with ntdst_router() |
| 2 | Add isUserEnrolled to TrajectoryService |
| 3 | Migrate EnrollmentFormHandler to NTDST API pattern |
| 4 | Update form template for unified edition/trajectory support |
| 5 | Update JavaScript to use ntdstAPI |
| 6 | Update trajectory detail page CTA links |
| 7 | Add seed data for E2E tests |
| 8 | Create Playwright E2E tests |
| V1-V5 | Verification stages |
