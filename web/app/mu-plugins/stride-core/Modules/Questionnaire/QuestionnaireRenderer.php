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

        $html = '';
        foreach ($groups as $group) {
            $args = ['group' => $group, 'model_prefix' => $modelPrefix];
            $html .= ntdst_response()
                ->withData(['args' => $args] + $args)
                ->html('forms/fields/field-group');
        }
        return $html;
    }
}
