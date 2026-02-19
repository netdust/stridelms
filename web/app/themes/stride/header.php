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

    <?php
    // Full-width templates handle their own layout
    // Check for: front page, edition/trajectory singles, and dashboard page templates
    $page_template = is_page() ? get_page_template_slug() : '';
    $custom_layout_templates = [
        'page-mijn-account.php',
        'page-mijn-cursussen.php',
        'page-offertes.php',
        'page-profiel.php',
        'page-inschrijven.php',
        'page-offerte.php',
    ];

    $full_width_templates = is_front_page()
        || is_singular(['vad_edition', 'vad_trajectory'])
        || in_array($page_template, $custom_layout_templates, true);

    if (!$full_width_templates):
    ?>
    <main id="content" class="stride-content">
        <div class="uk-container uk-margin-top uk-margin-bottom">
    <?php endif; ?>
