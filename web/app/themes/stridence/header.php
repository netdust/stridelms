<?php
/**
 * Stridence Header
 *
 * Minimal header that skips Kadence's inner-wrap for custom templates.
 *
 * @package stridence
 */

defined('ABSPATH') || exit;
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<div id="page" class="site">
    <?php
    /**
     * Hook for header output
     * Kadence uses this to output its header
     */
    do_action('kadence_header');
    ?>

    <div id="content" class="site-content">
