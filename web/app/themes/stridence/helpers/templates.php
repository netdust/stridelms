<?php
/**
 * Template helpers — thin wrappers around NTDST_Response.
 *
 * Why wrappers and not direct ntdst_response() calls everywhere?
 * - ~95 existing callers use stridence_template_part(); rewriting them
 *   has high blast radius for zero functional gain. The wrapper gives
 *   them NTDST's path + locate cache for free.
 * - Client mu-plugins override templates by registering their own
 *   directories via NTDST_Template_Loader::addPath(), no filter needed.
 *
 * @package stridence
 */

declare(strict_types=1);

/**
 * Echo a template part with NTDST's cached lookup.
 *
 * Resolution order (highest priority first):
 *   1. Paths registered via NTDST_Template_Loader::addPath()      (client plugins)
 *   2. <stylesheet>/templates                                      (NTDST default)
 *   3. <template>/templates                                        (NTDST default)
 *   4. <stylesheet>                                                (theme root — added per call)
 *
 * Slug semantics: relative to the theme root, e.g. 'partials/card-course'
 * or 'templates/course/header'. No leading slash, no .php extension required.
 *
 * Template-side contract:
 *   Templates receive the data dictionary as `$args` (compatible with WP's
 *   native get_template_part() since 5.5). Every key is also extracted as a
 *   loose variable, which is what callers of ntdst_response()->html() expect.
 *   Both contracts work simultaneously.
 *
 * @param string      $slug Template slug (e.g., 'partials/card-course')
 * @param string|null $name Optional name variant — appended as '-{name}'
 * @param array       $args Variables exposed to the template as $args + extracted
 */
function stridence_template_part(string $slug, ?string $name = null, array $args = []): void
{
    echo stridence_template_html($slug, $name, $args);
}

/**
 * Render a template part and return its output as a string.
 *
 * Same resolution and $args contract as stridence_template_part(), but
 * returns instead of echoing — for shortcodes and any caller that needs
 * the rendered HTML as a value.
 *
 * @param string      $slug Template slug (e.g., 'partials/card-course')
 * @param string|null $name Optional name variant — appended as '-{name}'
 * @param array       $args Variables exposed to the template as $args + extracted
 */
function stridence_template_html(string $slug, ?string $name = null, array $args = []): string
{
    $template = $name ? "{$slug}-{$name}" : $slug;

    return ntdst_response()
        ->addPath(get_stylesheet_directory())
        ->withData(['args' => $args] + $args)
        ->html($template);
}

/**
 * Render a centered error card with icon, title, message and action link.
 *
 * Used by the form shortcodes (enrollment, interest, intake, evaluation)
 * when their target edition is missing or invalid.
 */
function stridence_render_error_state(string $icon, string $title, string $message, string $action_label, string $action_url): string
{
    return stridence_template_html('partials/error-state', null, [
        'icon'         => $icon,
        'title'        => $title,
        'message'      => $message,
        'action_label' => $action_label,
        'action_url'   => $action_url,
    ]);
}
