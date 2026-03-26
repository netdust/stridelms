<?php
/**
 * Dashboard Header
 *
 * Minimal header for the dashboard — HTML head + body open only.
 * No site navigation, no header bar. The sidebar IS the navigation.
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <?php
    $stridence_font_url = apply_filters('stridence_font_url', 'https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,300..800;1,6..72,300..800&family=Plus+Jakarta+Sans:wght@300..800&family=Manrope:wght@400;500;600;700&display=swap');
    if ($stridence_font_url) :
    ?>
    <link href="<?php echo esc_url($stridence_font_url); ?>" rel="stylesheet">
    <?php endif; ?>
    <?php wp_head(); ?>
</head>
<body <?php body_class('bg-surface text-text'); ?>>
<?php wp_body_open(); ?>
