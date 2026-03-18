<?php
/**
 * Template loading with override support.
 *
 * Wraps get_template_part() with a filter that allows plugins
 * to override any template by providing an alternative file path.
 *
 * @package stridence
 */

declare(strict_types=1);

/**
 * Load a template part with plugin override support.
 *
 * Works identically to get_template_part() but fires a filter
 * that client plugins can hook into to provide an override path.
 *
 * Filter: 'stridence_template_path'
 *   @param string      $override  Override file path (empty = use default)
 *   @param string      $slug      Template slug
 *   @param string|null $name      Template name variant
 *   @param array       $args      Arguments passed to the template
 *
 * @param string      $slug Template slug (e.g., 'partials/card-course')
 * @param string|null $name Optional template name variant
 * @param array       $args Arguments passed to the template
 */
function stridence_template_part(string $slug, ?string $name = null, array $args = []): void
{
    $override = apply_filters('stridence_template_path', '', $slug, $name, $args);

    if ($override && file_exists($override)) {
        load_template($override, false, $args);
        return;
    }

    get_template_part($slug, $name, $args);
}
