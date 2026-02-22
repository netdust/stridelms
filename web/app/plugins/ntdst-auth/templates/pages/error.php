<?php
/**
 * Error Page Template
 *
 * Variables: $title, $message, $show_request_new (optional)
 */
defined('ABSPATH') || exit;

$settings = ntdst_get(\NTDST\Auth\SettingsService::class)->getSettings();
$loginUrl = home_url($settings['login_url'] ?? '/login');
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($title); ?> | <?php bloginfo('name'); ?></title>
    <?php wp_head(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/css/uikit.min.css">
    <link rel="stylesheet" href="<?php echo esc_url(NTDST_AUTH_URL . 'assets/css/auth.css'); ?>">
</head>
<body class="ntdst-auth-page">
    <div class="uk-flex uk-flex-center uk-flex-middle uk-height-viewport uk-padding">
        <div class="uk-card uk-card-default uk-card-body uk-width-medium uk-text-center">
            <span uk-icon="icon: warning; ratio: 3" class="uk-text-warning"></span>
            <h2 class="uk-card-title uk-margin-top"><?php echo esc_html($title); ?></h2>
            <p class="uk-text-muted"><?php echo esc_html($message); ?></p>
            <?php if (!empty($show_request_new)): ?>
            <a href="<?php echo esc_url($loginUrl); ?>" class="uk-button uk-button-primary">
                <?php esc_html_e('Request New Link', 'ntdst-auth'); ?>
            </a>
            <?php else: ?>
            <a href="<?php echo esc_url($loginUrl); ?>" class="uk-button uk-button-default">
                <?php esc_html_e('Back to Login', 'ntdst-auth'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/js/uikit.min.js"></script>
    <?php wp_footer(); ?>
</body>
</html>
