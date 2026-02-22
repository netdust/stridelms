<?php
/**
 * Register Page Template
 *
 * Variables: $settings (array of all settings)
 */
defined('ABSPATH') || exit;

$loginUrl = home_url($settings['login_url'] ?? '/login');
$termsUrl = home_url($settings['terms_url'] ?? '/terms');
$privacyUrl = home_url($settings['privacy_url'] ?? '/privacy');
$fields = $settings['registration_fields'] ?? ['email', 'first_name', 'last_name'];
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e('Register', 'ntdst-auth'); ?> | <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/css/uikit.min.css">
    <link rel="stylesheet" href="<?php echo esc_url(NTDST_AUTH_URL . 'assets/css/auth.css'); ?>">
</head>
<body class="ntdst-auth-page">
    <div class="uk-flex uk-flex-center uk-flex-middle uk-height-viewport uk-padding" x-data="authRegister()">
        <div class="uk-card uk-card-default uk-card-body uk-width-medium">
            <!-- Logo slot -->
            <div class="uk-text-center uk-margin-medium-bottom">
                <h2 class="uk-card-title"><?php esc_html_e('Create Account', 'ntdst-auth'); ?></h2>
            </div>

            <!-- Success message -->
            <template x-if="success">
                <div class="uk-alert uk-alert-success" x-text="message"></div>
            </template>

            <!-- Error message -->
            <template x-if="error">
                <div class="uk-alert uk-alert-danger" x-text="message"></div>
            </template>

            <!-- Registration Form -->
            <form @submit.prevent="register" x-show="!success">
                <?php if (in_array('first_name', $fields)): ?>
                <div class="uk-margin">
                    <label class="uk-form-label" for="first_name"><?php esc_html_e('First Name', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="text" id="first_name" x-model="firstName" required>
                </div>
                <?php endif; ?>

                <?php if (in_array('last_name', $fields)): ?>
                <div class="uk-margin">
                    <label class="uk-form-label" for="last_name"><?php esc_html_e('Last Name', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="text" id="last_name" x-model="lastName" required>
                </div>
                <?php endif; ?>

                <div class="uk-margin">
                    <label class="uk-form-label" for="email"><?php esc_html_e('Email', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="email" id="email" x-model="email" required>
                </div>

                <div class="uk-margin">
                    <label>
                        <input class="uk-checkbox" type="checkbox" x-model="consentTerms" required>
                        <?php printf(
                            esc_html__('I accept the %1$sTerms of Service%2$s', 'ntdst-auth'),
                            '<a href="' . esc_url($termsUrl) . '" target="_blank">',
                            '</a>'
                        ); ?>
                    </label>
                </div>

                <div class="uk-margin">
                    <label>
                        <input class="uk-checkbox" type="checkbox" x-model="consentPrivacy" required>
                        <?php printf(
                            esc_html__('I accept the %1$sPrivacy Policy%2$s', 'ntdst-auth'),
                            '<a href="' . esc_url($privacyUrl) . '" target="_blank">',
                            '</a>'
                        ); ?>
                    </label>
                </div>

                <div class="uk-margin">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit" :disabled="loading">
                        <span x-show="!loading"><?php esc_html_e('Create Account', 'ntdst-auth'); ?></span>
                        <span x-show="loading" uk-spinner="ratio: 0.6"></span>
                    </button>
                </div>
            </form>

            <!-- Login link -->
            <div class="uk-text-center uk-margin-top">
                <span class="uk-text-muted"><?php esc_html_e('Already have an account?', 'ntdst-auth'); ?></span>
                <a href="<?php echo esc_url($loginUrl); ?>"><?php esc_html_e('Sign In', 'ntdst-auth'); ?></a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/js/uikit.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <script src="<?php echo esc_url(NTDST_AUTH_URL . 'assets/js/auth.js'); ?>"></script>
    <script>
        window.ntdstAuth = {
            ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            nonce: '<?php echo esc_js(wp_create_nonce('ntdst_auth_register')); ?>'
        };
    </script>
    <?php wp_footer(); ?>
</body>
</html>
