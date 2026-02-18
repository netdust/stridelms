<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="profile" href="https://gmpg.org/xfn/11">
    <?php wp_head(); ?>
</head>

<body <?php body_class(is_user_logged_in() ? 'stride-user-logged-in' : ''); ?>>
<?php wp_body_open(); ?>

<div id="page" class="stride-site">
    <?php
    // Mobile header (hidden on desktop via CSS)
    get_template_part('templates/shell/mobile-header');

    // Desktop navigation (hidden on mobile via CSS)
    get_template_part('templates/shell/desktop-nav');
    ?>

    <main id="content" class="stride-content">
        <div class="uk-container uk-margin-top uk-margin-bottom">
