<?php
declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

/**
 * Renders questionnaire field groups to HTML.
 *
 * Uses theme template partials. Shared by all stage shortcodes.
 */
final class QuestionnaireRenderer
{
    /**
     * Render field groups as HTML.
     *
     * @param array $groups Field group definitions
     * @param string $modelPrefix Alpine model prefix (default: form.extra_fields)
     * @return string HTML output
     */
    public function render(array $groups, string $modelPrefix = 'form.extra_fields'): string
    {
        if (empty($groups)) {
            return '';
        }

        ob_start();
        foreach ($groups as $group) {
            stridence_template_part('templates/forms/fields/field-group', null, [
                'group' => $group,
                'model_prefix' => $modelPrefix,
            ]);
        }
        return ob_get_clean() ?: '';
    }
}
