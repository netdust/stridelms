<?php
/**
 * My Profile Template
 *
 * User profile edit form with AJAX save.
 *
 * @var int $user_id
 * @var array $profile
 * @var string $change_password_url
 * @var DashboardService $dashboard_service
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<div class="stride-dashboard">
    <div class="uk-container uk-container-small">
        <!-- Page Header -->
        <div class="stride-dashboard-header uk-margin-medium-bottom">
            <h1 class="uk-h2 uk-margin-remove-bottom">
                <?php esc_html_e('Mijn Profiel', 'stride'); ?>
            </h1>
            <p class="uk-text-muted uk-margin-small-top">
                <?php esc_html_e('Beheer je persoonlijke gegevens.', 'stride'); ?>
            </p>
        </div>

        <!-- Profile Form -->
        <div class="stride-card">
            <div class="stride-card-header">
                <h2 class="stride-card-title">
                    <span uk-icon="icon: user"></span>
                    <?php esc_html_e('Persoonlijke gegevens', 'stride'); ?>
                </h2>
            </div>

            <form class="stride-profile-form" method="post">
                <div uk-grid class="uk-grid-small">
                    <!-- First Name -->
                    <div class="uk-width-1-2@s">
                        <div class="stride-form-group">
                            <label class="stride-form-label" for="first_name">
                                <?php esc_html_e('Voornaam', 'stride'); ?>
                            </label>
                            <input type="text" name="first_name" id="first_name"
                                   class="stride-form-input"
                                   value="<?php echo esc_attr($profile['first_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Last Name -->
                    <div class="uk-width-1-2@s">
                        <div class="stride-form-group">
                            <label class="stride-form-label" for="last_name">
                                <?php esc_html_e('Achternaam', 'stride'); ?>
                            </label>
                            <input type="text" name="last_name" id="last_name"
                                   class="stride-form-input"
                                   value="<?php echo esc_attr($profile['last_name'] ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Email (read-only) -->
                    <div class="uk-width-1-1">
                        <div class="stride-form-group">
                            <label class="stride-form-label" for="email">
                                <?php esc_html_e('E-mailadres', 'stride'); ?>
                            </label>
                            <input type="email" name="email" id="email"
                                   class="stride-form-input"
                                   value="<?php echo esc_attr($profile['email'] ?? ''); ?>"
                                   readonly disabled>
                            <p class="stride-form-hint">
                                <?php esc_html_e('Neem contact met ons op om je e-mailadres te wijzigen.', 'stride'); ?>
                            </p>
                        </div>
                    </div>

                    <!-- Phone -->
                    <div class="uk-width-1-1">
                        <div class="stride-form-group">
                            <label class="stride-form-label" for="phone">
                                <?php esc_html_e('Telefoonnummer', 'stride'); ?>
                            </label>
                            <input type="tel" name="phone" id="phone"
                                   class="stride-form-input"
                                   value="<?php echo esc_attr($profile['phone'] ?? ''); ?>"
                                   placeholder="+32 ...">
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="uk-margin-medium-top">
                    <button type="submit" class="uk-button uk-button-primary">
                        <span uk-icon="icon: check"></span>
                        <?php esc_html_e('Opslaan', 'stride'); ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Organization Info (read-only if linked) -->
        <?php if (!empty($profile['organization_name'])): ?>
            <div class="stride-card uk-margin-top">
                <div class="stride-card-header">
                    <h2 class="stride-card-title">
                        <span uk-icon="icon: home"></span>
                        <?php esc_html_e('Organisatie', 'stride'); ?>
                    </h2>
                </div>

                <dl class="uk-description-list uk-description-list-divider">
                    <dt><?php esc_html_e('Organisatie', 'stride'); ?></dt>
                    <dd><?php echo esc_html($profile['organization_name']); ?></dd>
                </dl>

                <p class="uk-text-small uk-text-muted">
                    <?php esc_html_e('Je account is gekoppeld aan bovenstaande organisatie. Neem contact op met je organisatiebeheerder voor wijzigingen.', 'stride'); ?>
                </p>
            </div>
        <?php endif; ?>

        <!-- Password Change -->
        <div class="stride-card uk-margin-top">
            <div class="stride-card-header">
                <h2 class="stride-card-title">
                    <span uk-icon="icon: lock"></span>
                    <?php esc_html_e('Wachtwoord', 'stride'); ?>
                </h2>
            </div>

            <p class="uk-text-muted uk-margin-small-bottom">
                <?php esc_html_e('Wijzig je wachtwoord via de WordPress wachtwoord reset functie.', 'stride'); ?>
            </p>

            <a href="<?php echo esc_url($change_password_url); ?>" class="uk-button uk-button-default">
                <span uk-icon="icon: lock"></span>
                <?php esc_html_e('Wachtwoord wijzigen', 'stride'); ?>
            </a>
        </div>

        <!-- Account Actions -->
        <div class="stride-card uk-margin-top">
            <div class="stride-card-header">
                <h2 class="stride-card-title">
                    <span uk-icon="icon: cog"></span>
                    <?php esc_html_e('Account', 'stride'); ?>
                </h2>
            </div>

            <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap" uk-grid>
                <div>
                    <p class="uk-text-muted uk-margin-remove">
                        <?php esc_html_e('Ingelogd als:', 'stride'); ?>
                        <strong><?php echo esc_html($profile['email']); ?></strong>
                    </p>
                </div>
                <div>
                    <a href="<?php echo esc_url(wp_logout_url(home_url())); ?>"
                       class="uk-button uk-button-default">
                        <span uk-icon="icon: sign-out"></span>
                        <?php esc_html_e('Uitloggen', 'stride'); ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- Back to Dashboard -->
        <div class="uk-margin-medium-top">
            <a href="<?php echo esc_url(home_url('/mijn-account/')); ?>" class="uk-link-muted">
                <span uk-icon="icon: arrow-left; ratio: 0.8"></span>
                <?php esc_html_e('Terug naar dashboard', 'stride'); ?>
            </a>
        </div>
    </div>
</div>
