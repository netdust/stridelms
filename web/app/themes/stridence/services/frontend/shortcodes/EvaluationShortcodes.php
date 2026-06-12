<?php

declare(strict_types=1);

namespace stridence\services\frontend\shortcodes;

final class EvaluationShortcodes
{
    public function register(): void
    {
        add_shortcode('stride_evaluation', [$this, 'renderEvaluation']);
    }

    public function renderEvaluation(array $atts = []): string
    {
        if (!is_user_logged_in()) {
            return '';
        }
        $edition_id = isset($_GET['editie']) ? absint($_GET['editie']) : 0;
        if (!$edition_id) {
            return '';
        }

        return stridence_template_html('templates/forms/evaluation', null, [
            'edition_id' => $edition_id,
        ]);
    }
}
