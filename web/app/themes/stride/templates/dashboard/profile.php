<?php
/**
 * Profile Template
 *
 * User profile page with editable tabbed forms for personal info,
 * billing info, and notification preferences.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

use Stride\Modules\Invoicing\QuoteService;
use Stride\Modules\Completion\CompletionService;
use Stride\Modules\Enrollment\EnrollmentService;

// Current user
$user = wp_get_current_user();
$userId = $user->ID;
$firstName = $user->first_name ?: '';
$lastName = $user->last_name ?: '';
$displayName = $user->display_name;
$email = $user->user_email;

// User meta - personal
$phone = get_user_meta($userId, 'phone', true) ?: get_user_meta($userId, 'billing_phone', true);

// User meta - billing (check both new and legacy field names)
$billingCompany = get_user_meta($userId, 'invoice_organization_name', true) ?: get_user_meta($userId, 'company', true);
$billingVat = get_user_meta($userId, 'vat_number', true);
$billingGln = get_user_meta($userId, 'gln_number', true);
$billingAddress = get_user_meta($userId, 'invoice_address', true) ?: get_user_meta($userId, 'address_line_1', true);
$billingPostalCode = get_user_meta($userId, 'invoice_postal_code', true) ?: get_user_meta($userId, 'postal_code', true);
$billingCity = get_user_meta($userId, 'invoice_city', true) ?: get_user_meta($userId, 'city', true);
$billingEmail = get_user_meta($userId, 'invoice_email', true) ?: $email;

// User meta - notification preferences
$notifyReminders = get_user_meta($userId, 'stride_notify_reminders', true);
$notifyNewCourses = get_user_meta($userId, 'stride_notify_new_courses', true);
$notifyNewsletter = get_user_meta($userId, 'stride_notify_newsletter', true);

// User meta - language preference
$communicationLanguage = get_user_meta($userId, 'stride_communication_language', true) ?: 'nl';

// Services for counts
$quoteService = ntdst_get(QuoteService::class);
$enrollmentService = ntdst_get(EnrollmentService::class);
$completionService = ntdst_get(CompletionService::class);

// Get quote count
$quotes = $quoteService->getUserQuotes($userId);
$quoteCount = count($quotes);

// Get certificate count (completed courses)
$enrollments = $enrollmentService->getUserEnrollments($userId);
$certificateCount = 0;

foreach ($enrollments as $enrollment) {
    $editionId = (int) $enrollment->edition_id;
    $progress = $completionService->getProgress($editionId, $userId);
    if ($progress['is_complete'] ?? false) {
        $certificateCount++;
    }
}

// Avatar - either Gravatar or initials
$avatarUrl = get_avatar_url($userId, ['size' => 160]);
$initials = '';
if ($firstName && $lastName) {
    $initials = mb_strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
} elseif ($displayName) {
    $parts = explode(' ', $displayName);
    $initials = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) {
        $initials .= mb_strtoupper(mb_substr(end($parts), 0, 1));
    }
}

// Member since
$memberSince = date_i18n('F Y', strtotime($user->user_registered));

// Nonce for all profile forms
$profileNonce = wp_create_nonce('stride_profile');
?>

<div class="stride-profile stride-dashboard-profile stride-dashboard-page">
    <!-- Page Header -->
    <header class="stride-page-header">
        <div class="stride-page-header__content">
            <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="stride-page-header__back">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Dashboard', 'stride'); ?>
            </a>
            <h1 class="stride-page-header__title"><?php esc_html_e('Mijn profiel', 'stride'); ?></h1>
            <p class="stride-page-header__subtitle">
                <?php esc_html_e('Bekijk en beheer je accountgegevens', 'stride'); ?>
            </p>
        </div>
    </header>

    <!-- Profile Card with Avatar -->
    <div class="uk-card uk-card-default stride-profile-card uk-margin-bottom">
        <div class="stride-profile-card__header">
            <div class="stride-profile-card__avatar">
                <?php if ($avatarUrl && !str_contains($avatarUrl, 'd=blank')) : ?>
                    <img src="<?php echo esc_url($avatarUrl); ?>" alt="<?php echo esc_attr($displayName); ?>">
                <?php else : ?>
                    <span class="stride-profile-card__initials"><?php echo esc_html($initials); ?></span>
                <?php endif; ?>
            </div>
            <div class="stride-profile-card__info">
                <h2 class="stride-profile-card__name"><?php echo esc_html($displayName); ?></h2>
                <p class="stride-profile-card__email"><?php echo esc_html($email); ?></p>
                <p class="stride-profile-card__meta">
                    <span uk-icon="icon: calendar; ratio: 0.8"></span>
                    <?php
                    printf(
                        /* translators: %s: date when user registered */
                        esc_html__('Lid sinds %s', 'stride'),
                        esc_html($memberSince)
                    );
                    ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Tabbed Profile Forms -->
    <div class="uk-card uk-card-default uk-margin-bottom">
        <ul class="uk-tab" uk-tab>
            <li class="uk-active"><a href="#"><?php esc_html_e('Persoonlijk', 'stride'); ?></a></li>
            <li><a href="#"><?php esc_html_e('Facturatie', 'stride'); ?></a></li>
            <li><a href="#"><?php esc_html_e('Voorkeuren', 'stride'); ?></a></li>
        </ul>

        <ul class="uk-switcher uk-padding">
            <!-- Personal Tab -->
            <li>
                <form id="profile-personal-form" class="uk-form-stacked">
                    <input type="hidden" name="action" value="stride_update_profile">
                    <input type="hidden" name="form_type" value="personal">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($profileNonce); ?>">

                    <div class="uk-grid-small uk-child-width-1-2@s" uk-grid>
                        <div>
                            <label class="uk-form-label" for="profile_first_name">
                                <?php esc_html_e('Voornaam', 'stride'); ?>
                            </label>
                            <div class="uk-form-controls">
                                <input type="text" id="profile_first_name" name="first_name" class="uk-input"
                                       value="<?php echo esc_attr($firstName); ?>">
                            </div>
                        </div>
                        <div>
                            <label class="uk-form-label" for="profile_last_name">
                                <?php esc_html_e('Achternaam', 'stride'); ?>
                            </label>
                            <div class="uk-form-controls">
                                <input type="text" id="profile_last_name" name="last_name" class="uk-input"
                                       value="<?php echo esc_attr($lastName); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="profile_email">
                            <?php esc_html_e('E-mailadres', 'stride'); ?>
                        </label>
                        <div class="uk-form-controls">
                            <input type="email" id="profile_email" class="uk-input uk-disabled"
                                   value="<?php echo esc_attr($email); ?>" disabled>
                            <p class="uk-text-meta uk-margin-small-top">
                                <?php esc_html_e('Je e-mailadres kan niet worden gewijzigd.', 'stride'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="profile_phone">
                            <?php esc_html_e('Telefoonnummer', 'stride'); ?>
                        </label>
                        <div class="uk-form-controls">
                            <input type="tel" id="profile_phone" name="phone" class="uk-input"
                                   value="<?php echo esc_attr($phone); ?>">
                        </div>
                    </div>

                    <div class="uk-margin-medium-top">
                        <button type="submit" class="uk-button uk-button-primary">
                            <?php esc_html_e('Opslaan', 'stride'); ?>
                        </button>
                    </div>
                </form>
            </li>

            <!-- Billing Tab -->
            <li>
                <form id="profile-billing-form" class="uk-form-stacked">
                    <input type="hidden" name="action" value="stride_update_profile">
                    <input type="hidden" name="form_type" value="billing">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($profileNonce); ?>">

                    <div class="uk-margin">
                        <label class="uk-form-label" for="billing_company">
                            <?php esc_html_e('Bedrijf/Organisatie', 'stride'); ?>
                        </label>
                        <div class="uk-form-controls">
                            <input type="text" id="billing_company" name="billing_company" class="uk-input"
                                   value="<?php echo esc_attr($billingCompany); ?>">
                        </div>
                    </div>

                    <div class="uk-grid-small uk-child-width-1-2@s" uk-grid>
                        <div>
                            <label class="uk-form-label" for="billing_vat">
                                <?php esc_html_e('BTW-nummer', 'stride'); ?>
                            </label>
                            <div class="uk-form-controls">
                                <input type="text" id="billing_vat" name="billing_vat" class="uk-input"
                                       value="<?php echo esc_attr($billingVat); ?>"
                                       placeholder="<?php esc_attr_e('BE0123456789', 'stride'); ?>">
                            </div>
                        </div>
                        <div>
                            <label class="uk-form-label" for="billing_gln">
                                <?php esc_html_e('GLN-nummer', 'stride'); ?>
                            </label>
                            <div class="uk-form-controls">
                                <input type="text" id="billing_gln" name="billing_gln" class="uk-input"
                                       value="<?php echo esc_attr($billingGln); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="billing_email">
                            <?php esc_html_e('Facturatie e-mailadres', 'stride'); ?>
                        </label>
                        <div class="uk-form-controls">
                            <input type="email" id="billing_email" name="billing_email" class="uk-input"
                                   value="<?php echo esc_attr($billingEmail); ?>">
                            <p class="uk-text-meta uk-margin-small-top">
                                <?php esc_html_e('Facturen worden naar dit adres verzonden.', 'stride'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="billing_address">
                            <?php esc_html_e('Adres', 'stride'); ?>
                        </label>
                        <div class="uk-form-controls">
                            <input type="text" id="billing_address" name="billing_address" class="uk-input"
                                   value="<?php echo esc_attr($billingAddress); ?>">
                        </div>
                    </div>

                    <div class="uk-grid-small uk-child-width-1-2@s" uk-grid>
                        <div>
                            <label class="uk-form-label" for="billing_postal_code">
                                <?php esc_html_e('Postcode', 'stride'); ?>
                            </label>
                            <div class="uk-form-controls">
                                <input type="text" id="billing_postal_code" name="billing_postal_code" class="uk-input"
                                       value="<?php echo esc_attr($billingPostalCode); ?>">
                            </div>
                        </div>
                        <div>
                            <label class="uk-form-label" for="billing_city">
                                <?php esc_html_e('Stad', 'stride'); ?>
                            </label>
                            <div class="uk-form-controls">
                                <input type="text" id="billing_city" name="billing_city" class="uk-input"
                                       value="<?php echo esc_attr($billingCity); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="uk-margin-medium-top">
                        <button type="submit" class="uk-button uk-button-primary">
                            <?php esc_html_e('Opslaan', 'stride'); ?>
                        </button>
                    </div>
                </form>
            </li>

            <!-- Preferences Tab -->
            <li>
                <form id="profile-preferences-form" class="uk-form-stacked">
                    <input type="hidden" name="action" value="stride_update_profile">
                    <input type="hidden" name="form_type" value="notifications">
                    <input type="hidden" name="nonce" value="<?php echo esc_attr($profileNonce); ?>">

                    <h3 class="uk-h4 uk-margin-bottom"><?php esc_html_e('E-mailmeldingen', 'stride'); ?></h3>

                    <div class="uk-margin">
                        <label class="uk-flex uk-flex-middle">
                            <input type="checkbox" name="notify_reminders" class="uk-checkbox uk-margin-small-right"
                                   <?php checked($notifyReminders, 'yes'); ?>>
                            <div>
                                <span class="uk-text-bold"><?php esc_html_e('Herinneringen', 'stride'); ?></span>
                                <p class="uk-text-meta uk-margin-remove">
                                    <?php esc_html_e('Ontvang herinneringen voor aankomende sessies en deadlines.', 'stride'); ?>
                                </p>
                            </div>
                        </label>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-flex uk-flex-middle">
                            <input type="checkbox" name="notify_new_courses" class="uk-checkbox uk-margin-small-right"
                                   <?php checked($notifyNewCourses, 'yes'); ?>>
                            <div>
                                <span class="uk-text-bold"><?php esc_html_e('Nieuwe cursussen', 'stride'); ?></span>
                                <p class="uk-text-meta uk-margin-remove">
                                    <?php esc_html_e('Blijf op de hoogte van nieuwe cursussen en opleidingen.', 'stride'); ?>
                                </p>
                            </div>
                        </label>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-flex uk-flex-middle">
                            <input type="checkbox" name="notify_newsletter" class="uk-checkbox uk-margin-small-right"
                                   <?php checked($notifyNewsletter, 'yes'); ?>>
                            <div>
                                <span class="uk-text-bold"><?php esc_html_e('Nieuwsbrief', 'stride'); ?></span>
                                <p class="uk-text-meta uk-margin-remove">
                                    <?php esc_html_e('Ontvang onze periodieke nieuwsbrief met tips en updates.', 'stride'); ?>
                                </p>
                            </div>
                        </label>
                    </div>

                    <hr class="uk-margin-medium">

                    <h3 class="uk-h4 uk-margin-bottom"><?php esc_html_e('Communicatievoorkeuren', 'stride'); ?></h3>

                    <div class="uk-margin">
                        <label class="uk-form-label" for="communication_language">
                            <?php esc_html_e('Voorkeurstaal', 'stride'); ?>
                        </label>
                        <div class="uk-form-controls">
                            <select id="communication_language" name="communication_language" class="uk-select">
                                <option value="nl" <?php selected($communicationLanguage, 'nl'); ?>>
                                    <?php esc_html_e('Nederlands', 'stride'); ?>
                                </option>
                                <option value="fr" <?php selected($communicationLanguage, 'fr'); ?>>
                                    <?php esc_html_e('Français', 'stride'); ?>
                                </option>
                                <option value="en" <?php selected($communicationLanguage, 'en'); ?>>
                                    <?php esc_html_e('English', 'stride'); ?>
                                </option>
                            </select>
                            <p class="uk-text-meta uk-margin-small-top">
                                <?php esc_html_e('Selecteer de taal voor e-mails en communicatie.', 'stride'); ?>
                            </p>
                        </div>
                    </div>

                    <div class="uk-margin-medium-top">
                        <button type="submit" class="uk-button uk-button-primary">
                            <?php esc_html_e('Opslaan', 'stride'); ?>
                        </button>
                    </div>
                </form>
            </li>
        </ul>
    </div>

    <!-- Quick Links -->
    <section class="stride-profile-links uk-margin-medium-top">
        <h3 class="stride-section-title"><?php esc_html_e('Snelkoppelingen', 'stride'); ?></h3>

        <div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-2@s" uk-grid>
            <!-- My Quotes -->
            <div>
                <a href="<?php echo esc_url(home_url('/mijn-account/mijn-offertes/')); ?>" class="stride-link-card uk-card uk-card-default">
                    <div class="stride-link-card__icon stride-link-card__icon--warning">
                        <span uk-icon="icon: file-text; ratio: 1.2"></span>
                    </div>
                    <div class="stride-link-card__content">
                        <h4 class="stride-link-card__title"><?php esc_html_e('Mijn offertes', 'stride'); ?></h4>
                        <p class="stride-link-card__meta">
                            <?php
                            printf(
                                esc_html(_n(
                                    '%d offerte',
                                    '%d offertes',
                                    $quoteCount,
                                    'stride'
                                )),
                                $quoteCount
                            );
                            ?>
                        </p>
                    </div>
                    <span class="stride-link-card__arrow" uk-icon="icon: chevron-right"></span>
                </a>
            </div>

            <!-- Certificates -->
            <div>
                <a href="<?php echo esc_url(home_url('/mijn-account/mijn-cursussen/')); ?>" class="stride-link-card uk-card uk-card-default">
                    <div class="stride-link-card__icon stride-link-card__icon--success">
                        <span uk-icon="icon: certificate; ratio: 1.2"></span>
                    </div>
                    <div class="stride-link-card__content">
                        <h4 class="stride-link-card__title"><?php esc_html_e('Certificaten', 'stride'); ?></h4>
                        <p class="stride-link-card__meta">
                            <?php
                            printf(
                                esc_html(_n(
                                    '%d certificaat',
                                    '%d certificaten',
                                    $certificateCount,
                                    'stride'
                                )),
                                $certificateCount
                            );
                            ?>
                        </p>
                    </div>
                    <span class="stride-link-card__arrow" uk-icon="icon: chevron-right"></span>
                </a>
            </div>

            <!-- My Courses -->
            <div>
                <a href="<?php echo esc_url(home_url('/mijn-account/mijn-cursussen/')); ?>" class="stride-link-card uk-card uk-card-default">
                    <div class="stride-link-card__icon stride-link-card__icon--primary">
                        <span uk-icon="icon: book; ratio: 1.2"></span>
                    </div>
                    <div class="stride-link-card__content">
                        <h4 class="stride-link-card__title"><?php esc_html_e('Mijn cursussen', 'stride'); ?></h4>
                        <p class="stride-link-card__meta">
                            <?php esc_html_e('Bekijk je inschrijvingen', 'stride'); ?>
                        </p>
                    </div>
                    <span class="stride-link-card__arrow" uk-icon="icon: chevron-right"></span>
                </a>
            </div>

            <!-- Calendar -->
            <div>
                <a href="<?php echo esc_url(home_url('/mijn-account/kalender/')); ?>" class="stride-link-card uk-card uk-card-default">
                    <div class="stride-link-card__icon stride-link-card__icon--secondary">
                        <span uk-icon="icon: calendar; ratio: 1.2"></span>
                    </div>
                    <div class="stride-link-card__content">
                        <h4 class="stride-link-card__title"><?php esc_html_e('Agenda', 'stride'); ?></h4>
                        <p class="stride-link-card__meta">
                            <?php esc_html_e('Bekijk je planning', 'stride'); ?>
                        </p>
                    </div>
                    <span class="stride-link-card__arrow" uk-icon="icon: chevron-right"></span>
                </a>
            </div>
        </div>
    </section>

    <!-- Actions -->
    <section class="stride-profile-actions uk-margin-large-top">
        <div class="uk-grid uk-grid-small uk-child-width-auto" uk-grid>
            <div>
                <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="uk-button uk-button-default">
                    <span uk-icon="icon: home; ratio: 0.9"></span>
                    <?php esc_html_e('Naar dashboard', 'stride'); ?>
                </a>
            </div>
            <div>
                <a href="<?php echo esc_url(wp_logout_url(home_url('/'))); ?>" class="uk-button uk-button-default stride-button-logout">
                    <span uk-icon="icon: sign-out; ratio: 0.9"></span>
                    <?php esc_html_e('Uitloggen', 'stride'); ?>
                </a>
            </div>
        </div>
    </section>

    <!-- Navigation (Desktop nav panel + Mobile bottom navbar) -->
    <?php include locate_template('templates/dashboard/partials/nav-panel.php'); ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const forms = ['profile-personal-form', 'profile-billing-form', 'profile-preferences-form'];

    forms.forEach(function(formId) {
        const form = document.getElementById(formId);
        if (!form) return;

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;

            submitBtn.innerHTML = '<span uk-spinner="ratio: 0.5"></span> <?php echo esc_js(__('Opslaan...', 'stride')); ?>';
            submitBtn.disabled = true;

            const formData = new FormData(form);

            fetch(strideConfig.ajaxUrl, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;

                if (data.success) {
                    UIkit.notification({
                        message: data.data.message || '<?php echo esc_js(__('Gegevens opgeslagen', 'stride')); ?>',
                        status: 'success',
                        pos: 'top-center'
                    });

                    // Update displayed name if personal form
                    if (formId === 'profile-personal-form') {
                        const firstName = form.querySelector('[name="first_name"]').value;
                        const lastName = form.querySelector('[name="last_name"]').value;
                        const nameEl = document.querySelector('.stride-profile-card__name');
                        if (nameEl && (firstName || lastName)) {
                            nameEl.textContent = (firstName + ' ' + lastName).trim();
                        }
                    }
                } else {
                    UIkit.notification({
                        message: data.data.message || '<?php echo esc_js(__('Er is een fout opgetreden', 'stride')); ?>',
                        status: 'danger',
                        pos: 'top-center'
                    });
                }
            })
            .catch(error => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;

                UIkit.notification({
                    message: '<?php echo esc_js(__('Er is een fout opgetreden', 'stride')); ?>',
                    status: 'danger',
                    pos: 'top-center'
                });
            });
        });
    });
});
</script>
