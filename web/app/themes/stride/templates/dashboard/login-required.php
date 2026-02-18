<?php
/**
 * Login Required Template
 *
 * Shown when user tries to access dashboard without being logged in.
 *
 * @var string $login_url
 * @var string $register_url
 *
 * @package stride
 */

defined('ABSPATH') || exit;
?>

<div class="stride-login-required">
    <div class="uk-section uk-section-default">
        <div class="uk-container uk-container-small">
            <div class="stride-card uk-text-center">
                <div class="stride-empty-state-icon">
                    <span uk-icon="icon: lock; ratio: 3"></span>
                </div>

                <h2 class="uk-h3 uk-margin-small-top">
                    <?php esc_html_e('Log in om verder te gaan', 'stride'); ?>
                </h2>

                <p class="uk-text-muted uk-margin-small">
                    <?php esc_html_e('Je moet ingelogd zijn om je dashboard te bekijken.', 'stride'); ?>
                </p>

                <div class="uk-margin-medium-top">
                    <a href="<?php echo esc_url($login_url); ?>" class="uk-button uk-button-primary uk-button-large">
                        <span uk-icon="icon: sign-in"></span>
                        <?php esc_html_e('Inloggen', 'stride'); ?>
                    </a>
                </div>

                <?php if (get_option('users_can_register')): ?>
                    <p class="uk-margin-medium-top uk-text-small uk-text-muted">
                        <?php esc_html_e('Nog geen account?', 'stride'); ?>
                        <a href="<?php echo esc_url($register_url); ?>">
                            <?php esc_html_e('Registreer hier', 'stride'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
