<?php
/**
 * Interest Form Template
 *
 * Used when a course/edition is full or user wants to express interest.
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

get_header();

// Get course from URL
$courseId = isset($_GET['course']) ? absint($_GET['course']) : 0;
$course = $courseId ? get_post($courseId) : null;

// Pre-fill user data if logged in
$user = wp_get_current_user();
$firstName = is_user_logged_in() ? get_user_meta($user->ID, 'first_name', true) : '';
$lastName = is_user_logged_in() ? get_user_meta($user->ID, 'last_name', true) : '';
$email = is_user_logged_in() ? $user->user_email : '';
?>

<main class="str-main str-bg-light">
    <div class="str-container">
        <div class="str-interest-form-page">
            <div class="str-interest-form-card">
                <header class="str-interest-form__header">
                    <div class="str-interest-form__icon">
                        <?php stridence_icon('gift', '', 32); ?>
                    </div>
                    <h1><?php esc_html_e('Interesse melden', 'stridence'); ?></h1>
                    <p class="str-text-muted">
                        <?php if ($course): ?>
                            <?php printf(
                                esc_html__('Meld je interesse voor "%s" en we houden je op de hoogte.', 'stridence'),
                                esc_html($course->post_title)
                            ); ?>
                        <?php else: ?>
                            <?php esc_html_e('Laat je gegevens achter en we nemen contact met je op.', 'stridence'); ?>
                        <?php endif; ?>
                    </p>
                </header>

                <form method="post" id="stridence-interest-form">
                    <?php wp_nonce_field('stridence_interest', 'stridence_interest_nonce'); ?>
                    <?php if ($course): ?>
                        <input type="hidden" name="course_id" value="<?php echo esc_attr($course->ID); ?>">
                    <?php endif; ?>

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

                    <div class="str-form-group">
                        <label class="str-label str-label--required" for="email">
                            <?php esc_html_e('E-mailadres', 'stridence'); ?>
                        </label>
                        <input type="email" id="email" name="email" class="str-input"
                               value="<?php echo esc_attr($email); ?>" required>
                    </div>

                    <div class="str-form-group">
                        <label class="str-label" for="message">
                            <?php esc_html_e('Bericht (optioneel)', 'stridence'); ?>
                        </label>
                        <textarea id="message" name="message" class="str-textarea" rows="3"
                                  placeholder="<?php esc_attr_e('Vertel ons meer over je interesse...', 'stridence'); ?>"></textarea>
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

                    <button type="submit" class="str-btn str-btn--primary str-btn--lg str-btn--block">
                        <?php esc_html_e('Versturen', 'stridence'); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</main>

<?php get_footer(); ?>
