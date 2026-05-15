<?php
declare(strict_types=1);

namespace stridence\services\frontend\shortcodes;

/**
 * Enrollment form shortcode.
 *
 * Usage: [stride_enrollment]
 * Reads the target edition from the `?editie=<id>` query parameter.
 */
final class EnrollmentShortcode
{
    public function register(): void
    {
        add_shortcode('stride_enrollment', [$this, 'renderEnrollment']);
    }

    public function renderEnrollment(array $atts = []): string
    {
        $edition_id = isset($_GET['editie']) ? absint($_GET['editie']) : 0;

        if (!$edition_id) {
            return stridence_render_error_state(
                'alert-circle',
                __('Geen editie geselecteerd', 'stridence'),
                __('Selecteer eerst een editie via de cursuspagina.', 'stridence'),
                __('Naar cursussen', 'stridence'),
                get_post_type_archive_link('sfwd-courses')
            );
        }

        $edition = get_post($edition_id);
        if (!$edition || $edition->post_type !== 'vad_edition') {
            return stridence_render_error_state(
                'alert-circle',
                __('Editie niet gevonden', 'stridence'),
                __('Deze editie bestaat niet of is verwijderd.', 'stridence'),
                __('Naar cursussen', 'stridence'),
                get_post_type_archive_link('sfwd-courses')
            );
        }

        // Pre-fetch edition data for the Alpine component on the template.
        $item_data = [
            'id'    => $edition_id,
            'title' => $edition->post_title,
        ];

        return stridence_template_html('templates/forms/enrollment', null, [
            'item_id'   => $edition_id,
            'item_type' => 'edition',
            'item_data' => $item_data,
        ]);
    }
}
