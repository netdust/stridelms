<?php

declare(strict_types=1);

namespace Stride\Modules\Questionnaire;

use Stride\Infrastructure\AbstractService;
use Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage;

/**
 * Questionnaire module service.
 *
 * Bootstraps admin UI for the questionnaire builder.
 */
final class QuestionnaireService extends AbstractService
{
    public static function metadata(): array
    {
        return [
            'name' => 'Questionnaire Service',
            'description' => 'Configurable questions for registration stages',
            'priority' => 12,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'questionnaire';
    }

    protected function init(): void
    {
        if (is_admin()) {
            new QuestionnaireSettingsPage();
        }
    }
}
