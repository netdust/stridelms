<?php
/**
 * Enrollment Form Template
 *
 * Unified enrollment form for editions and trajectories.
 *
 * Two-column layout:
 * - Main panel: Form fields (billing, voucher, terms)
 * - Sidebar: Item details and price summary
 *
 * Supports:
 * - Router-passed data ($item, $type via ntdst_response())
 * - Query parameter fallback (?edition=ID or ?trajectory=ID)
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Admin\StrideSettingsService;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\EnrollmentService;
use Stride\Modules\Trajectory\TrajectoryService;
use Stride\Domain\SessionType;
use Stride\Domain\Money;

// Include header to load scripts (including ntdstAPI)
get_header();

// Get configurable URL slugs
$trajectorySlug = StrideSettingsService::getTrajectorySlug();
$editionSlug = StrideSettingsService::getEditionSlug();

// ============================================================================
// UNIFIED ITEM DETECTION
// ============================================================================

// Check for router-passed data (from EnrollmentRouterService)
$item = $item ?? null;
$type = $type ?? null;
$enrollmentOpen = $enrollment_open ?? null;

// Fallback to query parameters if not passed via router
if (!$item || !$type) {
    $trajectoryId = (int) ($_GET['trajectory'] ?? 0);
    $editionId = (int) ($_GET['edition'] ?? 0);

    if ($trajectoryId) {
        $type = 'trajectory';
        $item = get_post($trajectoryId);
    } elseif ($editionId) {
        $type = 'edition';
        $item = get_post($editionId);
    }
}

// Determine item ID
$itemId = $item instanceof WP_Post ? $item->ID : ($item['id'] ?? 0);

// User info
$user = wp_get_current_user();
$userId = $user->ID;

// ============================================================================
// VALIDATION: LOGIN CHECK
// ============================================================================

if (!is_user_logged_in()) {
    $loginReturnUrl = $type === 'trajectory'
        ? home_url('/' . $trajectorySlug . '/' . ($item->post_name ?? '') . '/inschrijving/')
        : home_url('/' . $editionSlug . '/' . ($item->post_name ?? $itemId) . '/inschrijving/');
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
            <a href="<?php echo esc_url(wp_login_url($loginReturnUrl)); ?>" class="uk-button uk-button-primary uk-button-large">
                <?php esc_html_e('Inloggen', 'stride'); ?>
            </a>
            <p class="uk-text-small uk-margin-top">
                <?php printf(
                    esc_html__('Nog geen account? %sRegistreer hier%s', 'stride'),
                    '<a href="' . esc_url(wp_registration_url()) . '">',
                    '</a>'
                ); ?>
            </p>
        </div>
    </div>
    <?php
    get_footer();
    return;
}

// ============================================================================
// VALIDATION: ITEM SELECTED
// ============================================================================

if (!$itemId || !$item) {
    $catalogUrl = $type === 'trajectory'
        ? home_url('/' . $trajectorySlug . '/')
        : home_url('/' . $editionSlug . '/');
    $itemLabel = $type === 'trajectory'
        ? __('traject', 'stride')
        : __('cursus', 'stride');
    ?>
    <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
        <div class="stride-card uk-text-center uk-padding-large">
            <div class="stride-empty-state__icon uk-margin-bottom">
                <span uk-icon="icon: warning; ratio: 2"></span>
            </div>
            <h2><?php printf(esc_html__('Geen %s geselecteerd', 'stride'), $itemLabel); ?></h2>
            <p class="uk-text-muted uk-margin-bottom">
                <?php printf(esc_html__('Selecteer eerst een %s om je in te schrijven.', 'stride'), $itemLabel); ?>
            </p>
            <a href="<?php echo esc_url($catalogUrl); ?>" class="uk-button uk-button-primary">
                <?php printf(esc_html__('Bekijk %sen', 'stride'), $itemLabel); ?>
            </a>
        </div>
    </div>
    <?php
    get_footer();
    return;
}

// ============================================================================
// TYPE-SPECIFIC DATA LOADING
// ============================================================================

if ($type === 'trajectory') {
    // Trajectory enrollment
    $trajectoryService = ntdst_get(TrajectoryService::class);
    $trajectoryData = $trajectoryService->getTrajectory($itemId);

    if (!$trajectoryData) {
        ?>
        <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
            <div class="uk-alert uk-alert-danger">
                <?php esc_html_e('Traject niet gevonden.', 'stride'); ?>
            </div>
        </div>
        <?php
        return;
    }

    // Check if already enrolled
    if ($trajectoryService->isUserEnrolled($userId, $itemId)) {
        ?>
        <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
            <div class="stride-card uk-text-center uk-padding-large">
                <div class="stride-empty-state__icon uk-margin-bottom">
                    <span uk-icon="icon: check; ratio: 2" class="uk-text-success"></span>
                </div>
                <h2><?php esc_html_e('Je bent al ingeschreven', 'stride'); ?></h2>
                <p class="uk-text-muted uk-margin-bottom">
                    <?php esc_html_e('Je bent al ingeschreven voor dit traject.', 'stride'); ?>
                </p>
                <a href="<?php echo esc_url(home_url('/mijn-account/mijn-trajecten/')); ?>" class="uk-button uk-button-primary">
                    <?php esc_html_e('Bekijk mijn trajecten', 'stride'); ?>
                </a>
            </div>
        </div>
        <?php
        get_footer();
        return;
    }

    // Check if enrollment is open
    if ($enrollmentOpen === null) {
        $enrollmentOpen = $trajectoryService->isEnrollmentOpen($itemId);
    }

    if (!$enrollmentOpen) {
        ?>
        <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
            <div class="stride-card uk-text-center uk-padding-large">
                <div class="stride-empty-state__icon uk-margin-bottom">
                    <span uk-icon="icon: ban; ratio: 2" class="uk-text-danger"></span>
                </div>
                <h2><?php esc_html_e('Inschrijving niet mogelijk', 'stride'); ?></h2>
                <p class="uk-text-muted uk-margin-bottom">
                    <?php esc_html_e('Inschrijving voor dit traject is momenteel niet mogelijk.', 'stride'); ?>
                </p>
                <a href="<?php echo esc_url(home_url('/' . $trajectorySlug . '/')); ?>" class="uk-button uk-button-default">
                    <?php esc_html_e('Bekijk andere trajecten', 'stride'); ?>
                </a>
            </div>
        </div>
        <?php
        get_footer();
        return;
    }

    // Set type-specific variables
    $itemTitle = $trajectoryData['title'];
    $heroImage = get_the_post_thumbnail_url($itemId, 'medium');
    $backUrl = get_permalink($itemId);
    $backLabel = __('Terug naar traject', 'stride');
    $catalogUrl = home_url('/' . $trajectorySlug . '/');
    $successUrl = home_url('/mijn-account/mijn-trajecten/');
    $price = Money::eur($trajectoryData['price']);
    $priceNonMember = Money::eur($trajectoryData['price_non_member']);

    // Trajectory-specific data for sidebar
    $courses = $trajectoryData['courses'] ?? [];
    $totalCourses = count($courses);
    $requiredCourses = count(array_filter($courses, fn($c) => ($c['required'] ?? false) === true));
    $electiveCourses = $totalCourses - $requiredCourses;
    $enrollmentDeadline = $trajectoryData['enrollment_deadline'] ?? '';

} else {
    // Edition enrollment (default)
    $editionService = ntdst_get(EditionService::class);
    $sessionService = ntdst_get(SessionService::class);
    $enrollmentService = ntdst_get(EnrollmentService::class);

    $edition = $editionService->getEdition($itemId);
    if (is_wp_error($edition)) {
        ?>
        <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
            <div class="uk-alert uk-alert-danger">
                <?php esc_html_e('Editie niet gevonden.', 'stride'); ?>
            </div>
        </div>
        <?php
        get_footer();
        return;
    }

    // Check if already enrolled
    if ($enrollmentService->isEnrolled($userId, $itemId)) {
        ?>
        <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
            <div class="stride-card uk-text-center uk-padding-large">
                <div class="stride-empty-state__icon uk-margin-bottom">
                    <span uk-icon="icon: check; ratio: 2" class="uk-text-success"></span>
                </div>
                <h2><?php esc_html_e('Je bent al ingeschreven', 'stride'); ?></h2>
                <p class="uk-text-muted uk-margin-bottom">
                    <?php esc_html_e('Je bent al ingeschreven voor deze cursus.', 'stride'); ?>
                </p>
                <a href="<?php echo esc_url(home_url('/mijn-account/mijn-cursussen/')); ?>" class="uk-button uk-button-primary">
                    <?php esc_html_e('Bekijk mijn cursussen', 'stride'); ?>
                </a>
            </div>
        </div>
        <?php
        get_footer();
        return;
    }

    // Check if can enroll
    if (!$editionService->canEnroll($itemId)) {
        ?>
        <div class="stride-enrollment uk-width-xlarge uk-margin-auto">
            <div class="stride-card uk-text-center uk-padding-large">
                <div class="stride-empty-state__icon uk-margin-bottom">
                    <span uk-icon="icon: ban; ratio: 2" class="uk-text-danger"></span>
                </div>
                <h2><?php esc_html_e('Inschrijving niet mogelijk', 'stride'); ?></h2>
                <p class="uk-text-muted uk-margin-bottom">
                    <?php esc_html_e('Inschrijving voor deze cursus is momenteel niet mogelijk. De cursus is volzet of de inschrijving is gesloten.', 'stride'); ?>
                </p>
                <a href="<?php echo esc_url(home_url('/' . $editionSlug . '/')); ?>" class="uk-button uk-button-default">
                    <?php esc_html_e('Bekijk andere cursussen', 'stride'); ?>
                </a>
            </div>
        </div>
        <?php
        get_footer();
        return;
    }

    // Get course and edition details
    $courseId = $editionService->getCourseId($itemId);
    $course = $courseId ? get_post($courseId) : null;
    $itemTitle = $course ? $course->post_title : get_the_title($itemId);
    $heroImage = $courseId ? get_the_post_thumbnail_url($courseId, 'medium') : null;
    $backUrl = get_permalink($itemId);
    $backLabel = __('Terug naar cursus', 'stride');
    $catalogUrl = home_url('/' . $editionSlug . '/');
    $successUrl = home_url('/mijn-account/mijn-cursussen/');

    // Pricing
    $price = $editionService->getPrice($itemId, true); // Member price
    $priceNonMember = $editionService->getPrice($itemId, false);

    // Sessions
    $sessions = $sessionService->getSessionsForEdition($itemId);
    $sessionCount = count($sessions);
    $dayCount = $sessionService->getDayCount($itemId);

    // Get start date from sessions
    $startDate = null;
    $endDate = null;
    if (!empty($sessions)) {
        $dates = array_filter(array_column($sessions, 'date'));
        if (!empty($dates)) {
            sort($dates);
            $startDate = strtotime(reset($dates));
            $endDate = strtotime(end($dates));
        }
    }

    // Venue
    $venue = get_post_meta($itemId, '_ntdst_venue', true);
    if (!$venue) {
        foreach ($sessions as $session) {
            if (!empty($session['location'])) {
                $venue = $session['location'];
                break;
            }
        }
    }
}

// User billing info (from meta)
$firstName = $user->first_name;
$lastName = $user->last_name;
$email = $user->user_email;
$company = get_user_meta($userId, 'organization', true);
$vatNumber = get_user_meta($userId, 'vat_number', true);
$address = get_user_meta($userId, 'billing_address', true);
$city = get_user_meta($userId, 'billing_city', true);
$postalCode = get_user_meta($userId, 'billing_postal', true);
?>

<div class="stride-enrollment">
    <!-- Header -->
    <header class="stride-page-header uk-margin-bottom">
        <a href="<?php echo esc_url($backUrl); ?>" class="stride-page-header__back">
            <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
            <?php echo esc_html($backLabel); ?>
        </a>
        <h1 class="stride-page-header__title"><?php esc_html_e('Inschrijven', 'stride'); ?></h1>
    </header>

    <div uk-grid class="uk-grid-large">
        <!-- Main Content: Form -->
        <div class="uk-width-2-3@m">
            <form id="stride-enrollment-form" method="post" class="stride-enrollment-form">
                <?php wp_nonce_field('stride_enrollment', 'nonce'); ?>
                <input type="hidden" name="action" value="stride_submit_enrollment">
                <input type="hidden" name="item_type" value="<?php echo esc_attr($type); ?>">
                <input type="hidden" name="item_id" value="<?php echo esc_attr($itemId); ?>">
                <?php if ($type === 'edition'): ?>
                    <!-- Keep edition_id for backward compatibility -->
                    <input type="hidden" name="edition_id" value="<?php echo esc_attr($itemId); ?>">
                <?php else: ?>
                    <input type="hidden" name="trajectory_id" value="<?php echo esc_attr($itemId); ?>">
                <?php endif; ?>

                <!-- Billing Information -->
                <div class="stride-card uk-margin-bottom">
                    <div class="stride-card-header">
                        <h2 class="stride-card-title">
                            <span uk-icon="icon: user"></span>
                            <?php esc_html_e('Facturatiegegevens', 'stride'); ?>
                        </h2>
                    </div>
                    <div class="uk-padding">
                        <div class="uk-grid uk-grid-small uk-child-width-1-2@s" uk-grid>
                            <div>
                                <label class="uk-form-label" for="first_name"><?php esc_html_e('Voornaam', 'stride'); ?> *</label>
                                <input type="text" id="first_name" name="first_name" class="uk-input"
                                       value="<?php echo esc_attr($firstName); ?>" required>
                            </div>

                            <div>
                                <label class="uk-form-label" for="last_name"><?php esc_html_e('Achternaam', 'stride'); ?> *</label>
                                <input type="text" id="last_name" name="last_name" class="uk-input"
                                       value="<?php echo esc_attr($lastName); ?>" required>
                            </div>

                            <div class="uk-width-1-1">
                                <label class="uk-form-label" for="email"><?php esc_html_e('E-mailadres', 'stride'); ?> *</label>
                                <input type="email" id="email" name="email" class="uk-input"
                                       value="<?php echo esc_attr($email); ?>" required>
                            </div>

                            <div class="uk-width-1-1">
                                <label class="uk-form-label" for="company"><?php esc_html_e('Organisatie', 'stride'); ?></label>
                                <input type="text" id="company" name="company" class="uk-input"
                                       value="<?php echo esc_attr($company); ?>">
                            </div>

                            <div>
                                <label class="uk-form-label" for="vat_number"><?php esc_html_e('BTW-nummer', 'stride'); ?></label>
                                <input type="text" id="vat_number" name="vat_number" class="uk-input"
                                       value="<?php echo esc_attr($vatNumber); ?>" placeholder="BE0123456789">
                            </div>

                            <div class="uk-width-1-1">
                                <label class="uk-form-label" for="address"><?php esc_html_e('Adres', 'stride'); ?></label>
                                <input type="text" id="address" name="address" class="uk-input"
                                       value="<?php echo esc_attr($address); ?>">
                            </div>

                            <div>
                                <label class="uk-form-label" for="postal_code"><?php esc_html_e('Postcode', 'stride'); ?></label>
                                <input type="text" id="postal_code" name="postal_code" class="uk-input"
                                       value="<?php echo esc_attr($postalCode); ?>">
                            </div>

                            <div>
                                <label class="uk-form-label" for="city"><?php esc_html_e('Plaats', 'stride'); ?></label>
                                <input type="text" id="city" name="city" class="uk-input"
                                       value="<?php echo esc_attr($city); ?>">
                            </div>
                        </div>

                        <div class="uk-margin-top">
                            <label>
                                <input type="checkbox" name="save_billing" value="1" checked class="uk-checkbox">
                                <?php esc_html_e('Bewaar deze gegevens voor toekomstige inschrijvingen', 'stride'); ?>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Voucher Code -->
                <div class="stride-card uk-margin-bottom">
                    <div class="stride-card-header">
                        <h2 class="stride-card-title">
                            <span uk-icon="icon: tag"></span>
                            <?php esc_html_e('Kortingscode', 'stride'); ?>
                        </h2>
                    </div>
                    <div class="uk-padding">
                        <div class="uk-grid uk-grid-small" uk-grid>
                            <div class="uk-width-expand">
                                <input type="text" id="voucher_code" name="voucher_code" class="uk-input"
                                       placeholder="<?php esc_attr_e('Voer je kortingscode in', 'stride'); ?>">
                            </div>
                            <div class="uk-width-auto">
                                <button type="button" id="apply-voucher" class="uk-button uk-button-default">
                                    <?php esc_html_e('Toepassen', 'stride'); ?>
                                </button>
                            </div>
                        </div>
                        <div id="voucher-result" class="uk-margin-small-top" style="display: none;"></div>
                    </div>
                </div>

                <!-- Terms & Submit (visible on mobile only, desktop uses sidebar) -->
                <div class="stride-card uk-hidden@m">
                    <div class="uk-padding">
                        <div class="uk-margin-bottom">
                            <label>
                                <input type="checkbox" name="terms_accepted_mobile" value="1" class="uk-checkbox terms-checkbox">
                                <?php printf(
                                    esc_html__('Ik ga akkoord met de %salgemene voorwaarden%s', 'stride'),
                                    '<a href="' . esc_url(home_url('/algemene-voorwaarden/')) . '" target="_blank">',
                                    '</a>'
                                ); ?> *
                            </label>
                        </div>

                        <div class="uk-margin-bottom">
                            <label>
                                <input type="checkbox" name="cancellation_accepted_mobile" value="1" class="uk-checkbox cancellation-checkbox">
                                <?php esc_html_e('Ik begrijp dat annulering binnen 14 dagen voor aanvang niet mogelijk is', 'stride'); ?> *
                            </label>
                        </div>

                        <button type="submit" class="uk-button uk-button-primary uk-button-large uk-width-1-1 submit-enrollment">
                            <?php esc_html_e('Bevestig inschrijving', 'stride'); ?>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Sidebar: Item Details & Price Summary -->
        <div class="uk-width-1-3@m">
            <div uk-sticky="offset: 100; bottom: true; media: @m;">
                <?php if ($type === 'trajectory'): ?>
                    <?php
                    // Include trajectory sidebar partial
                    include __DIR__ . '/partials/trajectory-sidebar.php';
                    ?>
                <?php else: ?>
                    <!-- Edition Sidebar -->
                    <div class="stride-course-info-card">
                        <?php if ($heroImage): ?>
                            <div class="stride-course-info-image">
                                <img src="<?php echo esc_url($heroImage); ?>" alt="<?php echo esc_attr($itemTitle); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="stride-course-info-header">
                            <h3 class="stride-course-info-title"><?php echo esc_html($itemTitle); ?></h3>
                        </div>

                        <div class="stride-course-info-body">
                            <ul class="stride-course-info-list">
                                <?php if (!empty($startDate)): ?>
                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: calendar; ratio: 0.9"></span>
                                        <span>
                                            <?php echo esc_html(date_i18n('j F Y', $startDate)); ?>
                                            <?php if (!empty($endDate) && $endDate !== $startDate): ?>
                                                - <?php echo esc_html(date_i18n('j F', $endDate)); ?>
                                            <?php endif; ?>
                                        </span>
                                    </li>
                                <?php endif; ?>

                                <?php if (!empty($venue)): ?>
                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: location; ratio: 0.9"></span>
                                        <span><?php echo esc_html($venue); ?></span>
                                    </li>
                                <?php endif; ?>

                                <?php if (!empty($sessionCount) && $sessionCount > 0): ?>
                                    <li class="stride-course-info-item">
                                        <span class="stride-course-info-icon" uk-icon="icon: clock; ratio: 0.9"></span>
                                        <span>
                                            <?php printf(
                                                esc_html(_n('%d sessie', '%d sessies', $sessionCount, 'stride')),
                                                $sessionCount
                                            ); ?>
                                            <?php if (!empty($dayCount) && $dayCount !== $sessionCount): ?>
                                                (<?php printf(esc_html(_n('%d dag', '%d dagen', $dayCount, 'stride')), $dayCount); ?>)
                                            <?php endif; ?>
                                        </span>
                                    </li>
                                <?php endif; ?>
                            </ul>

                            <hr class="uk-margin-small">

                            <!-- Price Summary -->
                            <table class="uk-table uk-table-small uk-margin-remove-bottom">
                                <tbody>
                                    <tr>
                                        <td><?php esc_html_e('Cursusprijs', 'stride'); ?></td>
                                        <td class="uk-text-right" id="line-item-price"><?php echo esc_html($price->format()); ?></td>
                                    </tr>
                                    <tr id="discount-row" style="display: none;">
                                        <td class="uk-text-success"><?php esc_html_e('Korting', 'stride'); ?></td>
                                        <td class="uk-text-right uk-text-success" id="discount-amount">- &euro; 0,00</td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e('Subtotaal', 'stride'); ?></td>
                                        <td class="uk-text-right" id="subtotal"><?php echo esc_html($price->format()); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="uk-text-muted"><?php esc_html_e('BTW (21%)', 'stride'); ?></td>
                                        <td class="uk-text-right uk-text-muted" id="tax-amount"><?php
                                            $taxAmount = $price->inCents() * 0.21;
                                            echo '&euro; ' . number_format($taxAmount / 100, 2, ',', '.');
                                        ?></td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr class="uk-text-bold" style="font-size: 1.1em;">
                                        <td><?php esc_html_e('Totaal', 'stride'); ?></td>
                                        <td class="uk-text-right" id="total-amount"><?php
                                            $totalAmount = $price->inCents() * 1.21;
                                            echo '&euro; ' . number_format($totalAmount / 100, 2, ',', '.');
                                        ?></td>
                                    </tr>
                                </tfoot>
                            </table>

                            <hr class="uk-margin-small">

                            <!-- Terms (desktop only) -->
                            <div class="uk-visible@m">
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
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('stride-enrollment-form');
    const submitBtns = document.querySelectorAll('.submit-enrollment');
    const voucherInput = document.getElementById('voucher_code');
    const applyVoucherBtn = document.getElementById('apply-voucher');
    const voucherResult = document.getElementById('voucher-result');

    // Item type and ID for AJAX calls
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

    // Get form nonce for AJAX calls
    const formNonce = form ? form.querySelector('input[name="nonce"]')?.value : '';

    // Apply voucher using ntdstAPI
    if (applyVoucherBtn) {
        applyVoucherBtn.addEventListener('click', async function() {
            const code = voucherInput.value.trim();
            if (!code) return;

            applyVoucherBtn.disabled = true;
            applyVoucherBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span>';

            try {
                const result = await ntdstAPI.call('stride_validate_voucher', {
                    code: code,
                    item_type: itemType,
                    item_id: itemId,
                    nonce: formNonce
                });

                voucherResult.style.display = 'block';
                voucherResult.innerHTML = '<div class="uk-alert uk-alert-success uk-margin-remove">' + result.message + '</div>';

                // Update prices if discount was applied
                if (result.discount) {
                    document.getElementById('discount-row').style.display = '';
                    document.getElementById('discount-amount').textContent = '- ' + result.discount_formatted;
                    document.getElementById('subtotal').textContent = result.subtotal_formatted;
                    document.getElementById('tax-amount').textContent = result.tax_formatted;
                    document.getElementById('total-amount').textContent = result.total_formatted;
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

    // Form submission using ntdstAPI
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

            // Disable submit buttons
            submitBtns.forEach(btn => {
                btn.disabled = true;
                btn.innerHTML = '<span uk-spinner="ratio: 0.6"></span> <?php esc_html_e('Bezig...', 'stride'); ?>';
            });

            try {
                // Collect form data as object
                const formData = new FormData(form);
                const params = Object.fromEntries(formData.entries());

                // Ensure item info is included
                params.item_id = itemId;
                params.item_type = itemType;
                params.terms_accepted = '1';
                params.cancellation_accepted = '1';

                const result = await ntdstAPI.call('stride_submit_enrollment', params);

                // Success - redirect
                if (result.redirect_url) {
                    window.location.href = result.redirect_url;
                } else {
                    // Type-specific success URL
                    const successUrl = itemType === 'trajectory'
                        ? '<?php echo esc_url(home_url('/mijn-account/mijn-trajecten/')); ?>?enrolled=1'
                        : '<?php echo esc_url(home_url('/mijn-account/mijn-cursussen/')); ?>?enrolled=1';
                    window.location.href = successUrl;
                }
            } catch (error) {
                UIkit.notification({
                    message: error.message || '<?php esc_html_e('Er is een fout opgetreden', 'stride'); ?>',
                    status: 'danger',
                    pos: 'top-center'
                });

                // Re-enable submit buttons
                submitBtns.forEach(btn => {
                    btn.disabled = false;
                    btn.textContent = '<?php esc_html_e('Bevestig inschrijving', 'stride'); ?>';
                });
            }
        });
    }
});
</script>

<?php get_footer(); ?>
