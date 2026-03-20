<?php

declare(strict_types=1);

namespace Stride\Tests\Unit;

use Stride\Modules\Assistant\AbilityRegistrar;
use Stride\Tests\TestCase;

/**
 * Unit tests for AbilityRegistrar
 *
 * Verifies that Stride domain abilities are registered correctly
 * with the WP Abilities API for AI assistant integration.
 */
class AbilityRegistrarTest extends TestCase
{
    private AbilityRegistrar $registrar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrar = new AbilityRegistrar();
    }

    // =========================================================================
    // Category registration
    // =========================================================================

    public function testRegistersStrideCategory(): void
    {
        $this->registrar->registerCategories();

        $this->assertTrue(wp_has_ability_category('stride'));
    }

    public function testStrideCategoryHasLabel(): void
    {
        $this->registrar->registerCategories();

        $category = wp_get_ability_category('stride');
        $this->assertNotNull($category);
        $this->assertSame('Stride LMS', $category->get_label());
    }

    // =========================================================================
    // Read abilities
    // =========================================================================

    public function testRegistersReadAbilities(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $this->assertTrue(wp_has_ability('stride/search-users'));
        $this->assertTrue(wp_has_ability('stride/get-edition'));
        $this->assertTrue(wp_has_ability('stride/get-editions'));
        $this->assertTrue(wp_has_ability('stride/get-enrollments'));
    }

    public function testReadAbilitiesAreReadonly(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $readAbilities = [
            'stride/search-users',
            'stride/get-edition',
            'stride/get-editions',
            'stride/get-enrollments',
        ];

        foreach ($readAbilities as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, "Ability '{$name}' should exist");

            $annotations = $ability->get_meta_item('annotations', []);
            $this->assertTrue($annotations['readonly'] ?? false, "'{$name}' should have readonly annotation");

            $readonly = $ability->get_meta_item('readonly', false);
            $this->assertTrue($readonly, "'{$name}' should have readonly meta");
        }
    }

    public function testReadAbilitiesAreVisibleInRest(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $readAbilities = [
            'stride/search-users',
            'stride/get-edition',
            'stride/get-editions',
            'stride/get-enrollments',
        ];

        foreach ($readAbilities as $name) {
            $ability = wp_get_ability($name);
            $this->assertTrue(
                $ability->get_meta_item('show_in_rest', false),
                "'{$name}' should have show_in_rest = true"
            );
        }
    }

    public function testReadAbilitiesBelongToStrideCategory(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $ability = wp_get_ability('stride/get-editions');
        $this->assertSame('stride', $ability->get_category());
    }

    // =========================================================================
    // Write abilities
    // =========================================================================

    public function testRegistersWriteAbilities(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $this->assertTrue(wp_has_ability('stride/enroll-user'));
        $this->assertTrue(wp_has_ability('stride/unenroll-user'));
    }

    public function testWriteAbilitiesAreNotReadonly(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $writeAbilities = ['stride/enroll-user', 'stride/unenroll-user'];

        foreach ($writeAbilities as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, "Ability '{$name}' should exist");

            $readonly = $ability->get_meta_item('readonly', false);
            $this->assertFalse($readonly, "'{$name}' should NOT be readonly");
        }
    }

    public function testWriteAbilitiesHaveDescribeInput(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $writeAbilities = ['stride/enroll-user', 'stride/unenroll-user'];

        foreach ($writeAbilities as $name) {
            $ability = wp_get_ability($name);
            $describer = $ability->get_meta_item('describe_input');

            $this->assertTrue(is_callable($describer), "'{$name}' should have callable describe_input");
        }
    }

    public function testWriteAbilitiesAreVisibleInRest(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $writeAbilities = ['stride/enroll-user', 'stride/unenroll-user'];

        foreach ($writeAbilities as $name) {
            $ability = wp_get_ability($name);
            $this->assertTrue(
                $ability->get_meta_item('show_in_rest', false),
                "'{$name}' should have show_in_rest = true"
            );
        }
    }

    // =========================================================================
    // Input schemas
    // =========================================================================

    public function testSearchUsersHasRequiredQueryParam(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $ability = wp_get_ability('stride/search-users');
        $schema = $ability->get_input_schema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('query', $schema['properties']);
        $this->assertContains('query', $schema['required']);
    }

    public function testEnrollUserRequiresUserAndEditionIds(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $ability = wp_get_ability('stride/enroll-user');
        $schema = $ability->get_input_schema();

        $this->assertContains('user_id', $schema['required']);
        $this->assertContains('edition_id', $schema['required']);
    }

    // =========================================================================
    // System prompt injection
    // =========================================================================

    public function testInjectsSystemPrompt(): void
    {
        $result = $this->registrar->injectDomainPrompts('base prompt', []);

        $this->assertStringContainsString('Edition', $result);
        $this->assertStringContainsString('Dutch', $result);
        $this->assertStringContainsString('base prompt', $result);
    }

    public function testSystemPromptContainsDomainModel(): void
    {
        $result = $this->registrar->injectDomainPrompts('', []);

        $this->assertStringContainsString('Domain Model', $result);
        $this->assertStringContainsString('vad_edition', $result);
        $this->assertStringContainsString('Registration', $result);
    }

    public function testSystemPromptContainsFormattingRules(): void
    {
        $result = $this->registrar->injectDomainPrompts('', []);

        $this->assertStringContainsString('Formatting', $result);
        $this->assertStringContainsString('nl_BE', $result);
    }

    // =========================================================================
    // Describe input callbacks
    // =========================================================================

    public function testDescribeEnrollInputResolvesNames(): void
    {
        // Without real user/post data, falls back to ID-based labels
        $description = $this->registrar->describeEnrollInput([
            'user_id' => 999,
            'edition_id' => 888,
        ]);

        $this->assertStringContainsString('inschrijven', $description);
        $this->assertStringContainsString('999', $description);
        $this->assertStringContainsString('888', $description);
    }

    public function testDescribeUnenrollInputMentionsLearnDash(): void
    {
        $description = $this->registrar->describeUnenrollInput([
            'user_id' => 1,
            'edition_id' => 2,
        ]);

        $this->assertStringContainsString('uitschrijven', $description);
        $this->assertStringContainsString('LearnDash', $description);
    }

    public function testDescribeEnrollInputHandlesMissingIds(): void
    {
        $description = $this->registrar->describeEnrollInput([]);

        $this->assertStringContainsString('onbekende gebruiker', $description);
        $this->assertStringContainsString('onbekende editie', $description);
    }

    // =========================================================================
    // Stub execute callbacks
    // =========================================================================

    public function testGetEditionsReturnsStubMessage(): void
    {
        $result = $this->registrar->getEditions([]);

        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('not yet wired', $result['message']);
    }

    public function testGetEnrollmentsReturnsStubMessage(): void
    {
        $result = $this->registrar->getEnrollments([]);

        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('not yet wired', $result['message']);
    }

    public function testEnrollUserReturnsStubMessage(): void
    {
        $result = $this->registrar->enrollUser(['user_id' => 1, 'edition_id' => 2]);

        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('not yet wired', $result['message']);
    }

    public function testUnenrollUserReturnsStubMessage(): void
    {
        $result = $this->registrar->unenrollUser(['user_id' => 1, 'edition_id' => 2]);

        $this->assertArrayHasKey('message', $result);
        $this->assertStringContainsString('not yet wired', $result['message']);
    }

    // =========================================================================
    // Search users (fully implemented)
    // =========================================================================

    public function testSearchUsersReturnsEmptyForBlankQuery(): void
    {
        $result = $this->registrar->searchUsers(['query' => '']);

        $this->assertSame(['users' => []], $result);
    }

    public function testSearchUsersReturnsEmptyForMissingQuery(): void
    {
        $result = $this->registrar->searchUsers([]);

        $this->assertSame(['users' => []], $result);
    }

    public function testSearchUsersCapsPerPage(): void
    {
        // With no matching users, just verify it doesn't error with large per_page
        $result = $this->registrar->searchUsers(['query' => 'test', 'per_page' => 999]);

        $this->assertArrayHasKey('users', $result);
    }

    // =========================================================================
    // Total ability count
    // =========================================================================

    public function testRegistersSixAbilitiesTotal(): void
    {
        $this->registrar->registerCategories();
        $this->registrar->registerAbilities();

        $all = wp_get_abilities();
        $strideAbilities = array_filter(
            $all,
            fn(\WP_Ability $a) => $a->get_category() === 'stride'
        );

        $this->assertCount(6, $strideAbilities);
    }
}
