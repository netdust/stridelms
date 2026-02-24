<?php
/**
 * My Profile Dashboard Page
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

$current_page = 'profile';
$user = wp_get_current_user();

// Get user meta
$firstName = get_user_meta($user->ID, 'first_name', true);
$lastName = get_user_meta($user->ID, 'last_name', true);
$phone = get_user_meta($user->ID, 'billing_phone', true);
$company = get_user_meta($user->ID, 'billing_company', true);

include get_stylesheet_directory() . '/templates/partials/dashboard-layout.php';
?>

<header class="str-dashboard__header">
    <h1 class="str-dashboard__title"><?php esc_html_e('Mijn profiel', 'stridence'); ?></h1>
    <p class="str-dashboard__subtitle">
        <?php esc_html_e('Beheer je persoonlijke gegevens', 'stridence'); ?>
    </p>
</header>

<form method="post" class="str-profile-form" id="stridence-profile-form">
    <?php wp_nonce_field('stridence_profile', 'stridence_profile_nonce'); ?>

    <section class="str-form-section">
        <h2 class="str-form-section__title"><?php esc_html_e('Persoonlijke gegevens', 'stridence'); ?></h2>

        <div class="str-form-row">
            <div class="str-form-group">
                <label class="str-label str-label--required" for="first_name">
                    <?php esc_html_e('Voornaam', 'stridence'); ?>
                </label>
                <input type="text" id="first_name" name="first_name" class="str-input"
                       value="<?php echo esc_attr($firstName); ?>" required>
            </div>

            <div class="str-form-group">
                <label class="str-label str-label--required" for="last_name">
                    <?php esc_html_e('Achternaam', 'stridence'); ?>
                </label>
                <input type="text" id="last_name" name="last_name" class="str-input"
                       value="<?php echo esc_attr($lastName); ?>" required>
            </div>
        </div>

        <div class="str-form-row">
            <div class="str-form-group">
                <label class="str-label str-label--required" for="email">
                    <?php esc_html_e('E-mailadres', 'stridence'); ?>
                </label>
                <input type="email" id="email" name="email" class="str-input"
                       value="<?php echo esc_attr($user->user_email); ?>" required>
            </div>

            <div class="str-form-group">
                <label class="str-label" for="phone">
                    <?php esc_html_e('Telefoonnummer', 'stridence'); ?>
                </label>
                <input type="tel" id="phone" name="phone" class="str-input"
                       value="<?php echo esc_attr($phone); ?>">
            </div>
        </div>
    </section>

    <section class="str-form-section">
        <h2 class="str-form-section__title"><?php esc_html_e('Bedrijfsgegevens', 'stridence'); ?></h2>

        <div class="str-form-group">
            <label class="str-label" for="company">
                <?php esc_html_e('Bedrijfsnaam', 'stridence'); ?>
            </label>
            <input type="text" id="company" name="company" class="str-input"
                   value="<?php echo esc_attr($company); ?>">
        </div>
    </section>

    <section class="str-form-section">
        <h2 class="str-form-section__title"><?php esc_html_e('Wachtwoord wijzigen', 'stridence'); ?></h2>
        <p class="str-text-muted" style="margin-bottom: var(--str-space-lg);">
            <?php esc_html_e('Laat leeg om je huidige wachtwoord te behouden.', 'stridence'); ?>
        </p>

        <div class="str-form-row">
            <div class="str-form-group">
                <label class="str-label" for="new_password">
                    <?php esc_html_e('Nieuw wachtwoord', 'stridence'); ?>
                </label>
                <input type="password" id="new_password" name="new_password" class="str-input"
                       autocomplete="new-password">
            </div>

            <div class="str-form-group">
                <label class="str-label" for="confirm_password">
                    <?php esc_html_e('Wachtwoord bevestigen', 'stridence'); ?>
                </label>
                <input type="password" id="confirm_password" name="confirm_password" class="str-input"
                       autocomplete="new-password">
            </div>
        </div>
    </section>

    <div class="str-form-actions">
        <button type="submit" class="str-btn str-btn--primary str-btn--lg">
            <?php esc_html_e('Opslaan', 'stridence'); ?>
        </button>
    </div>
</form>

<?php
include get_stylesheet_directory() . '/templates/partials/dashboard-layout-close.php';
get_footer();
?>
