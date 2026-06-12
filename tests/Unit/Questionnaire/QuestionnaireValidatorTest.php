<?php

declare(strict_types=1);

namespace Stride\Tests\Unit\Questionnaire;

use PHPUnit\Framework\TestCase;
use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Modules\Questionnaire\QuestionnaireValidator;
use WP_Error;

/**
 * Unit tests for QuestionnaireValidator
 *
 * Covers required-field enforcement, scale range validation,
 * description field skipping, and optional-field leniency.
 */
class QuestionnaireValidatorTest extends TestCase
{
    private QuestionnaireRepository $repository;
    private QuestionnaireValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset the global options store used by the stub implementation.
        global $_test_options;
        $_test_options = [];

        $this->repository = new QuestionnaireRepository();
        $this->validator  = new QuestionnaireValidator($this->repository);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Saves a single field group assigned to all editions for the 'evaluation'
     * stage and returns the edition post ID used throughout the tests.
     *
     * @param array<int, array<string, mixed>> $fields
     */
    private function seedGroup(array $fields, int $postId = 100): void
    {
        $this->repository->saveGroups([
            [
                'id'          => 'fg_test',
                'label'       => 'Test Group',
                'stage'       => 'evaluation',
                'assignments' => ['_all_editions'],
                'fields'      => $fields,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    /** @test */
    public function testValidDataPasses(): void
    {
        $this->seedGroup([
            ['type' => 'scale',    'label' => 'Kennis',      'name' => 'kennis',   'min' => 1, 'max' => 5, 'required' => true],
            ['type' => 'textarea', 'label' => 'Opmerkingen', 'name' => 'comments', 'required' => false],
        ]);

        $result = $this->validator->validate(
            ['kennis' => 4, 'comments' => 'Prima cursus'],
            100,
            'evaluation',
        );

        $this->assertTrue($result);
    }

    /** @test */
    public function testMissingRequiredFieldFails(): void
    {
        $this->seedGroup([
            ['type' => 'scale', 'label' => 'Kennis', 'name' => 'kennis', 'min' => 1, 'max' => 5, 'required' => true],
        ]);

        $result = $this->validator->validate(
            [],   // kennis not submitted
            100,
            'evaluation',
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $messages = $result->get_error_messages('validation_error');
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('Kennis', $messages[0]);
        $this->assertStringContainsString('verplicht', $messages[0]);
    }

    /** @test */
    public function testScaleOutOfRangeFails(): void
    {
        $this->seedGroup([
            ['type' => 'scale', 'label' => 'Kennis', 'name' => 'kennis', 'min' => 1, 'max' => 5, 'required' => false],
        ]);

        $result = $this->validator->validate(
            ['kennis' => 7],
            100,
            'evaluation',
        );

        $this->assertInstanceOf(WP_Error::class, $result);
        $messages = $result->get_error_messages('validation_error');
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('1', $messages[0]);
        $this->assertStringContainsString('5', $messages[0]);
    }

    /** @test */
    public function testScaleInRangePasses(): void
    {
        $this->seedGroup([
            ['type' => 'scale', 'label' => 'Kennis', 'name' => 'kennis', 'min' => 1, 'max' => 5, 'required' => false],
        ]);

        $result = $this->validator->validate(
            ['kennis' => 3],
            100,
            'evaluation',
        );

        $this->assertTrue($result);
    }

    /** @test */
    public function testDescriptionFieldIsSkipped(): void
    {
        $this->seedGroup([
            // Description field has no 'name' and should never cause an error.
            ['type' => 'description', 'label' => 'Sectie intro'],
            ['type' => 'scale', 'label' => 'Kennis', 'name' => 'kennis', 'min' => 1, 'max' => 5, 'required' => true],
        ]);

        // Provide the required scale value but nothing for the description.
        $result = $this->validator->validate(
            ['kennis' => 3],
            100,
            'evaluation',
        );

        $this->assertTrue($result);
    }

    /** @test */
    public function testOptionalFieldCanBeEmpty(): void
    {
        $this->seedGroup([
            ['type' => 'textarea', 'label' => 'Opmerkingen', 'name' => 'comments', 'required' => false],
        ]);

        $result = $this->validator->validate(
            [],   // comments omitted entirely
            100,
            'evaluation',
        );

        $this->assertTrue($result);
    }
}
