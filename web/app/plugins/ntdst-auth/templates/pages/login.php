<?php
/**
 * Login Page Template
 *
 * Variables: $settings (array of all settings)
 */
defined('ABSPATH') || exit;

$enableMagicLink = $settings['enable_magic_link'] ?? true;
$enablePassword = $settings['enable_password'] ?? false;
$registerUrl = home_url($settings['register_url'] ?? '/register');
$enableRegistration = $settings['enable_registration'] ?? true;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/css/uikit.min.css">
    <link rel="stylesheet" href="<?php echo esc_url(NTDST_AUTH_URL . 'assets/css/auth.css'); ?>">
</head>
<body class="ntdst-auth-page">
    <?php
    // WordPress overwrites $_GET on custom routes — parse from QUERY_STRING instead
    $queryParams = [];
    if (!empty($_SERVER['QUERY_STRING'])) {
        parse_str($_SERVER['QUERY_STRING'], $queryParams);
    }
    $serverError = isset($queryParams['error']) ? sanitize_text_field($queryParams['error']) : '';
    ?>
    <div class="uk-flex uk-flex-center uk-flex-middle uk-height-viewport uk-padding" x-data="authLogin()" x-init="<?php if ($serverError): ?>error = true; message = <?php echo esc_attr(wp_json_encode($serverError)); ?><?php endif; ?>">
        <div class="uk-card uk-card-default uk-card-body uk-width-medium">
            <!-- Logo slot -->
            <div class="uk-text-center uk-margin-medium-bottom">
                <h2 class="uk-card-title"><?php bloginfo('name'); ?></h2>
            </div>

            <!-- Success message -->
            <template x-if="success">
                <div class="uk-alert uk-alert-success" x-text="message"></div>
            </template>

            <!-- Error message -->
            <template x-if="error">
                <div class="uk-alert uk-alert-danger" x-text="message"></div>
            </template>

            <!-- Magic Link Form -->
            <?php if ($enableMagicLink && !$enablePassword): ?>
            <form @submit.prevent="requestMagicLink" x-show="!success">
                <div class="uk-margin">
                    <label class="uk-form-label" for="email"><?php esc_html_e('Email', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="email" id="email" x-model="email" required autofocus>
                </div>

                <div class="uk-margin">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit" :disabled="loading">
                        <span x-show="!loading"><?php esc_html_e('Send Login Link', 'ntdst-auth'); ?></span>
                        <span x-show="loading" uk-spinner="ratio: 0.6"></span>
                    </button>
                </div>
            </form>
            <?php endif; ?>

            <!-- Password Login Form (with optional magic link toggle) -->
            <?php if ($enablePassword): ?>
            <form @submit.prevent="loginPassword" x-show="!success && mode === 'password'">
                <div class="uk-margin">
                    <label class="uk-form-label" for="email"><?php esc_html_e('Email', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="email" id="email" x-model="email" required autofocus>
                </div>

                <div class="uk-margin">
                    <label class="uk-form-label" for="password"><?php esc_html_e('Password', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="password" id="password" x-model="password" required>
                </div>

                <div class="uk-margin">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit" :disabled="loading">
                        <span x-show="!loading"><?php esc_html_e('Sign In', 'ntdst-auth'); ?></span>
                        <span x-show="loading" uk-spinner="ratio: 0.6"></span>
                    </button>
                </div>

                <div class="uk-text-center uk-margin-small-top">
                    <a href="<?php echo esc_url(wp_lostpassword_url()); ?>" class="uk-link-muted uk-text-small">
                        <?php esc_html_e('Forgot your password?', 'ntdst-auth'); ?>
                    </a>
                </div>

                <?php if ($enableMagicLink): ?>
                <div class="uk-text-center uk-margin-small-top">
                    <a href="#" @click.prevent="mode = 'magic'" class="uk-link-muted uk-text-small">
                        <?php esc_html_e('Sign in with email link instead', 'ntdst-auth'); ?>
                    </a>
                </div>
                <?php endif; ?>
            </form>

            <?php if ($enableMagicLink): ?>
            <form @submit.prevent="requestMagicLink" x-show="!success && mode === 'magic'">
                <div class="uk-margin">
                    <label class="uk-form-label" for="email-magic"><?php esc_html_e('Email', 'ntdst-auth'); ?></label>
                    <input class="uk-input" type="email" id="email-magic" x-model="email" required>
                </div>

                <div class="uk-margin">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit" :disabled="loading">
                        <span x-show="!loading"><?php esc_html_e('Send Login Link', 'ntdst-auth'); ?></span>
                        <span x-show="loading" uk-spinner="ratio: 0.6"></span>
                    </button>
                </div>

                <div class="uk-text-center uk-margin-small-top">
                    <a href="#" @click.prevent="mode = 'password'" class="uk-link-muted uk-text-small">
                        <?php esc_html_e('Sign in with password instead', 'ntdst-auth'); ?>
                    </a>
                </div>
            </form>
            <?php endif; ?>
            <?php endif; ?>

            <!-- Register link -->
            <?php if ($enableRegistration): ?>
            <div class="uk-text-center uk-margin-top">
                <span class="uk-text-muted"><?php esc_html_e("Don't have an account?", 'ntdst-auth'); ?></span>
                <a href="<?php echo esc_url($registerUrl); ?>"><?php esc_html_e('Register', 'ntdst-auth'); ?></a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/js/uikit.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
    <script src="<?php echo esc_url(NTDST_AUTH_URL . 'assets/js/auth.js'); ?>"></script>
    <script>
        window.ntdstAuth = {
            ajaxUrl: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
            nonce: '<?php echo esc_js(wp_create_nonce('ntdst_auth_login')); ?>',
            enablePassword: <?php echo $enablePassword ? 'true' : 'false'; ?>
        };
    </script>
    <?php wp_footer(); ?>
</body>
</html>
