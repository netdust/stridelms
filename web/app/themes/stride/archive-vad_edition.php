<?php
/**
 * Edition Archive Template
 *
 * Displays the course catalog for scheduled editions.
 *
 * @package stride
 */

defined('ABSPATH') || exit;

get_header();

// Use the catalog template for edition archives
get_template_part('templates/course/catalog');

get_footer();
