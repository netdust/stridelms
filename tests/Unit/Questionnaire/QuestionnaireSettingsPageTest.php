<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Questionnaire;

use Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage;
use Stride\Tests\TestCase;

final class QuestionnaireSettingsPageTest extends TestCase
{
    public function testGetFieldTypesReturnsPlainArrayWithoutColorKey(): void
    {
        $page = new QuestionnaireSettingsPage();

        $reflection = new \ReflectionMethod($page, 'getFieldTypes');
        $reflection->setAccessible(true);
        $types = $reflection->invoke($page);

        $this->assertIsArray($types);
        $this->assertArrayHasKey('text', $types);
        $this->assertArrayHasKey('label', $types['text']);
        $this->assertArrayNotHasKey(
            'color',
            $types['text'],
            'Field types should not carry per-type colors anymore — colors were removed to drop the rainbow chip palette.'
        );
    }
}
