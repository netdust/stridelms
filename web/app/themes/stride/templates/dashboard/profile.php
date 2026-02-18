<?php
/**
 * Profile Template
 *
 * User profile page with personal info and quick links.
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

// User meta
$phone = get_user_meta($userId, 'phone', true) ?: get_user_meta($userId, 'billing_phone', true);
$company = get_user_meta($userId, 'company', true) ?: get_user_meta($userId, 'billing_company', true);

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
?>

<div class="stride-profile">
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

    <!-- Profile Card -->
    <div class="uk-card uk-card-default stride-profile-card">
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

        <!-- User Details -->
        <div class="stride-profile-card__body">
            <h3 class="stride-profile-card__section-title"><?php esc_html_e('Persoonlijke gegevens', 'stride'); ?></h3>

            <dl class="stride-profile-details">
                <?php if ($firstName || $lastName) : ?>
                    <div class="stride-profile-details__item">
                        <dt><?php esc_html_e('Naam', 'stride'); ?></dt>
                        <dd><?php echo esc_html(trim($firstName . ' ' . $lastName)); ?></dd>
                    </div>
                <?php endif; ?>

                <div class="stride-profile-details__item">
                    <dt><?php esc_html_e('E-mailadres', 'stride'); ?></dt>
                    <dd><?php echo esc_html($email); ?></dd>
                </div>

                <?php if ($phone) : ?>
                    <div class="stride-profile-details__item">
                        <dt><?php esc_html_e('Telefoonnummer', 'stride'); ?></dt>
                        <dd><?php echo esc_html($phone); ?></dd>
                    </div>
                <?php endif; ?>

                <?php if ($company) : ?>
                    <div class="stride-profile-details__item">
                        <dt><?php esc_html_e('Bedrijf', 'stride'); ?></dt>
                        <dd><?php echo esc_html($company); ?></dd>
                    </div>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <!-- Quick Links -->
    <section class="stride-profile-links uk-margin-medium-top">
        <h3 class="stride-section-title"><?php esc_html_e('Snelkoppelingen', 'stride'); ?></h3>

        <div class="uk-grid uk-grid-small uk-child-width-1-1 uk-child-width-1-2@s" uk-grid>
            <!-- My Quotes -->
            <div>
                <a href="<?php echo esc_url(home_url('/mijn-account/offertes/')); ?>" class="stride-link-card uk-card uk-card-default">
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
                <a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>" class="stride-link-card uk-card uk-card-default">
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
                <a href="<?php echo esc_url(home_url('/mijn-account/cursussen/')); ?>" class="stride-link-card uk-card uk-card-default">
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
</div>
