<?php
declare(strict_types=1);

namespace Stride\Tests\Unit\NtdstAssistant;

use NtdstAssistant\SystemPrompt;
use Stride\Tests\TestCase;

class SystemPromptTest extends TestCase
{
    private SystemPrompt $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prompt = new SystemPrompt();
    }

    public function testBuildReturnsBasePrompt(): void
    {
        $result = $this->prompt->build();

        $this->assertTrue(str_contains($result, 'AI assistant for WordPress administrators'));
        $this->assertTrue(str_contains($result, 'ALWAYS query before acting'));
    }

    public function testBuildAppliesFilter(): void
    {
        add_filter('ntdst_assistant/system_prompt', function (string $prompt, array $context): string {
            return $prompt . "\n\n## Custom Rules\nBe extra careful.";
        }, 10, 2);

        $result = $this->prompt->build();

        $this->assertTrue(str_contains($result, 'Custom Rules'));
        $this->assertTrue(str_contains($result, 'Be extra careful'));
    }

    public function testBuildPassesContextToFilter(): void
    {
        $capturedContext = null;

        add_filter('ntdst_assistant/system_prompt', function (string $prompt, array $context) use (&$capturedContext): string {
            $capturedContext = $context;
            return $prompt;
        }, 10, 2);

        $this->prompt->build();

        $this->assertIsArray($capturedContext);
        $this->assertArrayHasKey('user_id', $capturedContext);
        $this->assertArrayHasKey('locale', $capturedContext);
        $this->assertArrayHasKey('abilities', $capturedContext);
    }
}
