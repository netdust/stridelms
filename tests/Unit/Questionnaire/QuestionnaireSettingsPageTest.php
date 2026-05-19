<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Questionnaire;

use Stride\Modules\Questionnaire\Admin\QuestionnaireSettingsPage;
use Stride\Modules\Questionnaire\QuestionnaireRepository;
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

    public function testGetStateJsonHasGroupsFieldTypesStagesAndAssignmentsKeys(): void
    {
        // getStateJson() resolves the repository via ntdst_get(); register a
        // real (plain-class) instance in the test container so the call works.
        $this->registerService(QuestionnaireRepository::class, new QuestionnaireRepository());

        $page = new QuestionnaireSettingsPage();

        $reflection = new \ReflectionMethod($page, 'getStateJson');
        $reflection->setAccessible(true);
        $state = $reflection->invoke($page);

        $this->assertIsArray($state);
        $this->assertArrayHasKey('groups', $state);
        $this->assertArrayHasKey('fieldTypes', $state);
        $this->assertArrayHasKey('stages', $state);
        $this->assertArrayHasKey('assignments', $state);

        $this->assertIsArray($state['groups']);
        $this->assertIsArray($state['fieldTypes']);
        $this->assertIsArray($state['stages']);
        $this->assertIsArray($state['assignments']);
    }
}
