<?php

/**
 * Plugin Name: Stride Core Loader
 * Description: Loads the Stride Core plugin from subdirectory
 * Version: 1.0.0
 * Author: NTDST
 *
 * This is a simple loader for mu-plugins that live in subdirectories.
 * The actual plugin code is in stride-core/stride-core.php
 */

defined('ABSPATH') || exit;

// Fix Tin Canny module URLs for Bedrock
add_filter('tincanny_module_url', function($url, $item, $module) {
    $home = home_url();

    // Fix double domain (Tin Canny sometimes stores full URLs in database)
    // e.g. https://site.com/wp/https://site.com/app/uploads/... → https://site.com/app/uploads/...
    if (preg_match('#https?://[^/]+/wp/(https?://)#', $url)) {
        $url = preg_replace('#^https?://[^/]+/wp/(https?://)#', '$1', $url);
    }

    // Fix Bedrock path (get_site_url returns /wp/ but uploads are at /app/)
    // e.g. https://site.com/wp/app/uploads/... → https://site.com/app/uploads/...
    $url = str_replace('/wp/app/', '/app/', $url);

    return $url;
}, 10, 3);

// Load the actual plugin
require_once __DIR__ . '/stride-core/stride-core.php';
