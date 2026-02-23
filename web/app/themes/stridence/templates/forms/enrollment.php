<?php
/**
 * Enrollment Form Template
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

// Get edition/course from URL
$editionId = isset($_GET['edition']) ? absint($_GET['edition']) : 0;
$trajectoryId = isset($_GET['trajectory']) ? absint($_GET['trajectory']) : 0;

$edition = $editionId ? get_post($editionId) : null;
$trajectory = $trajectoryId ? get_post($trajectoryId) : null;

// Redirect if no valid item
if (!$edition && !$trajectory) {
    wp_redirect(home_url('/cursussen/'));
    exit;
}

$item = $edition ?: $trajectory;
$itemType = $edition ? 'edition' : 'trajectory';
$price = get_post_meta($item->ID, '_price', true) ?: 0;

// Pre-fill user data if logged in
$user = wp_get_current_user();
$firstName = is_user_logged_in() ? get_user_meta($user->ID, 'first_name', true) : '';
$lastName = is_user_logged_in() ? get_user_meta($user->ID, 'last_name', true) : '';
$email = is_user_logged_in() ? $user->user_email : '';
$phone = is_user_logged_in() ? get_user_meta($user->ID, 'billing_phone', true) : '';
$company = is_user_logged_in() ? get_user_meta($user->ID, 'billing_company', true) : '';
?>

<main class="str-main str-bg-light">
    <div class="str-container">
        <div class="str-enrollment">
            <div class="str-enrollment__grid">
                <!-- Form -->
                <div class="str-enrollment__form">
                    <header class="str-enrollment__header">
                        <h1><?php esc_html_e('Inschrijven', 'stridence'); ?></h1>
                        <p class="str-text-muted">
                            <?php esc_html_e('Vul onderstaand formulier in om je in te schrijven.', 'stridence'); ?>
                        </p>
                    </header>

                    <form method="post" id="stridence-enrollment-form" class="str-enrollment-form">
                        <?php wp_nonce_field('stridence_enrollment', 'stridence_enrollment_nonce'); ?>
                        <input type="hidden" name="item_id" value="<?php echo esc_attr($item->ID); ?>">
                        <input type="hidden" name="item_type" value="<?php echo esc_attr($itemType); ?>">

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
                                           value="<?php echo esc_attr($email); ?>" required>
                                </div>

                                <div class="str-form-group">
                                    <label class="str-label str-label--required" for="phone">
                                        <?php esc_html_e('Telefoonnummer', 'stridence'); ?>
                                    </label>
                                    <input type="tel" id="phone" name="phone" class="str-input"
                                           value="<?php echo esc_attr($phone); ?>" required>
                                </div>
                            </div>
                        </section>

                        <section class="str-form-section">
                            <h2 class="str-form-section__title"><?php esc_html_e('Facturatiegegevens', 'stridence'); ?></h2>

                            <div class="str-form-group">
                                <label class="str-label" for="company">
                                    <?php esc_html_e('Bedrijfsnaam', 'stridence'); ?>
                                </label>
                                <input type="text" id="company" name="company" class="str-input"
                                       value="<?php echo esc_attr($company); ?>">
                            </div>

                            <div class="str-form-group">
                                <label class="str-label" for="vat_number">
                                    <?php esc_html_e('BTW-nummer', 'stridence'); ?>
                                </label>
                                <input type="text" id="vat_number" name="vat_number" class="str-input"
                                       placeholder="BE0123456789">
                            </div>

                            <div class="str-form-group">
                                <label class="str-label" for="remarks">
                                    <?php esc_html_e('Opmerkingen', 'stridence'); ?>
                                </label>
                                <textarea id="remarks" name="remarks" class="str-textarea" rows="3"></textarea>
                            </div>
                        </section>

                        <section class="str-form-section">
                            <h2 class="str-form-section__title"><?php esc_html_e('Voorwaarden', 'stridence'); ?></h2>

                            <div class="str-form-group">
                                <label class="str-checkbox">
                                    <input type="checkbox" name="terms" required>
                                    <span class="str-checkbox__label">
                                        <?php printf(
                                            esc_html__('Ik ga akkoord met de %salgmene voorwaarden%s', 'stridence'),
                                            '<a href="' . esc_url(home_url('/voorwaarden/')) . '" target="_blank">',
                                            '</a>'
                                        ); ?>
                                    </span>
                                </label>
                            </div>

                            <div class="str-form-group">
                                <label class="str-checkbox">
                                    <input type="checkbox" name="privacy" required>
                                    <span class="str-checkbox__label">
                                        <?php printf(
                                            esc_html__('Ik ga akkoord met het %sprivacybeleid%s', 'stridence'),
                                            '<a href="' . esc_url(home_url('/privacy/')) . '" target="_blank">',
                                            '</a>'
                                        ); ?>
                                    </span>
                                </label>
                            </div>
                        </section>

                        <div class="str-form-actions">
                            <button type="submit" class="str-btn str-btn--primary str-btn--lg str-btn--block">
                                <?php esc_html_e('Inschrijving bevestigen', 'stridence'); ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Order Summary -->
                <aside class="str-enrollment__summary">
                    <div class="str-order-summary">
                        <h2 class="str-order-summary__title"><?php esc_html_e('Samenvatting', 'stridence'); ?></h2>

                        <div class="str-order-summary__item">
                            <div class="str-order-summary__item-image">
                                <?php if (has_post_thumbnail($item->ID)): ?>
                                    <?php echo get_the_post_thumbnail($item->ID, 'thumbnail'); ?>
                                <?php else: ?>
                                    <?php stridence_icon($edition ? 'users' : 'gift', '', 32); ?>
                                <?php endif; ?>
                            </div>
                            <div class="str-order-summary__item-content">
                                <h3><?php echo esc_html($item->post_title); ?></h3>
                                <?php if ($edition): ?>
                                    <?php
                                    $startDate = get_post_meta($edition->ID, '_start_date', true);
                                    $location = get_post_meta($edition->ID, '_location', true);
                                    ?>
                                    <?php if ($startDate): ?>
                                        <p><?php echo esc_html(date_i18n('j F Y', strtotime($startDate))); ?></p>
                                    <?php endif; ?>
                                    <?php if ($location): ?>
                                        <p><?php echo esc_html($location); ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="str-order-summary__totals">
                            <div class="str-order-summary__row">
                                <span><?php esc_html_e('Subtotaal', 'stridence'); ?></span>
                                <span>€<?php echo esc_html(number_format((float)$price, 2, ',', '.')); ?></span>
                            </div>
                            <div class="str-order-summary__row">
                                <span><?php esc_html_e('BTW (21%)', 'stridence'); ?></span>
                                <span>€<?php echo esc_html(number_format((float)$price * 0.21, 2, ',', '.')); ?></span>
                            </div>
                            <div class="str-order-summary__row str-order-summary__row--total">
                                <span><?php esc_html_e('Totaal', 'stridence'); ?></span>
                                <span>€<?php echo esc_html(number_format((float)$price * 1.21, 2, ',', '.')); ?></span>
                            </div>
                        </div>

                        <p class="str-order-summary__note">
                            <?php esc_html_e('Na inschrijving ontvang je een bevestiging en offerte per e-mail.', 'stridence'); ?>
                        </p>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</main>

<?php get_footer(); ?>
