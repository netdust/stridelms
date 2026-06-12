<?php
/**
 * Activation Success Page Template
 *
 * Variables: $title, $message, $redirect
 */
defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php wp_head(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/css/uikit.min.css">
    <link rel="stylesheet" href="<?php echo esc_url(NTDST_AUTH_URL . 'assets/css/auth.css'); ?>">
    <?php if (!empty($redirect)): ?>
    <meta http-equiv="refresh" content="3;url=<?php echo esc_url($redirect); ?>">
    <?php endif; ?>
</head>
<body class="ntdst-auth-page">
    <div class="uk-flex uk-flex-center uk-flex-middle uk-height-viewport uk-padding">
        <div class="uk-card uk-card-default uk-card-body uk-width-medium uk-text-center">
            <span uk-icon="icon: check; ratio: 3" class="uk-text-success"></span>
            <h2 class="uk-card-title uk-margin-top"><?php echo esc_html($title); ?></h2>
            <p class="uk-text-muted"><?php echo esc_html($message); ?></p>
            <?php if (!empty($redirect)): ?>
            <p class="uk-text-small uk-text-muted">
                <?php esc_html_e('Redirecting...', 'ntdst-auth'); ?>
            </p>
            <a href="<?php echo esc_url($redirect); ?>" class="uk-button uk-button-primary">
                <?php esc_html_e('Continue', 'ntdst-auth'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/uikit@3.21.5/dist/js/uikit.min.js"></script>
    <?php wp_footer(); ?>
</body>
</html>
