<?php
declare(strict_types=1);

namespace stridence\services\frontend\shortcodes;

final class IntakeShortcodes
{
    public function register(): void
    {
        add_shortcode('stride_intake', [$this, 'renderIntake']);
    }

    public function renderIntake(array $atts = []): string
    {
        if (!is_user_logged_in()) return '';
        $edition_id = isset($_GET['editie']) ? absint($_GET['editie']) : 0;
        if (!$edition_id) return '';

        return stridence_template_html('templates/forms/intake', null, [
            'edition_id' => $edition_id,
        ]);
    }
}
