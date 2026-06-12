<?php

declare(strict_types=1);

namespace stridence\services\frontend\shortcodes;

/**
 * Interest form shortcode.
 *
 * Renders anonymous interest form for editions without sessions.
 */
final class InterestShortcodes
{
    public function register(): void
    {
        add_shortcode('stride_interest', [$this, 'renderInterest']);
    }

    public function renderInterest(array $atts = []): string
    {
        $edition_id = isset($_GET['editie']) ? absint($_GET['editie']) : 0;

        if (!$edition_id) {
            return stridence_render_error_state(
                'alert-circle',
                __('Geen editie geselecteerd', 'stridence'),
                __('Selecteer eerst een editie via de cursuspagina.', 'stridence'),
                __('Naar cursussen', 'stridence'),
                get_post_type_archive_link('sfwd-courses'),
            );
        }

        $edition = get_post($edition_id);
        if (!$edition || $edition->post_type !== 'vad_edition') {
            return stridence_render_error_state(
                'alert-circle',
                __('Editie niet gevonden', 'stridence'),
                __('Deze editie bestaat niet of is verwijderd.', 'stridence'),
                __('Naar cursussen', 'stridence'),
                get_post_type_archive_link('sfwd-courses'),
            );
        }

        return stridence_template_html('templates/forms/interest', null, [
            'edition_id' => $edition_id,
        ]);
    }
}
