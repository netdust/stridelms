<?php

/**
 * Icon Helper Functions
 *
 * @package stridence
 */

defined('ABSPATH') || exit;

/**
 * Render inline SVG icon
 *
 * @param string $name  Icon name (without .svg extension)
 * @param string $class Optional CSS classes
 * @return string       SVG markup
 */
function stridence_icon(string $name, string $class = ''): string
{
    static $cache = [];
    $key = $name . '|' . $class;

    if (!isset($cache[$key])) {
        $path = get_theme_file_path("icons/{$name}.svg");

        if (!file_exists($path)) {
            $cache[$key] = '';
            return '';
        }

        $svg = file_get_contents($path);

        if ($class) {
            // Add classes to SVG element
            $svg = preg_replace(
                '/<svg/',
                '<svg class="' . esc_attr($class) . '"',
                $svg,
                1,
            );
        }

        $cache[$key] = $svg;
    }

    return $cache[$key];
}
