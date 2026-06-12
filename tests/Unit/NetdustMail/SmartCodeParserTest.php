<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\SmartCodeParser;
use Netdust\Mail\SmartCodeRegistry;
use PHPUnit\Framework\TestCase;

class SmartCodeParserTest extends TestCase
{
    private SmartCodeParser $parser;
    private SmartCodeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = $this->createMock(SmartCodeRegistry::class);
        $this->parser = new SmartCodeParser($this->registry);
    }

    // =========================================================================
    // parse() tests
    // =========================================================================

    public function test_parse_replaces_known_smartcode_with_callback_value(): void
    {
        $this->registry
            ->method('getCallback')
            ->with('user', 'email')
            ->willReturn(fn(array $context) => 'john@example.com');

        $content = 'Hello {{user.email}}!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello john@example.com!', $result);
    }

    public function test_parse_leaves_unknown_smartcode_intact(): void
    {
        $this->registry
            ->method('getCallback')
            ->with('unknown', 'field')
            ->willReturn(null);

        $content = 'Hello {{unknown.field}}!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello {{unknown.field}}!', $result);
    }

    public function test_parse_uses_default_value_when_callback_returns_null(): void
    {
        $this->registry
            ->method('getCallback')
            ->with('user', 'nickname')
            ->willReturn(fn(array $context) => null);

        $content = 'Hello {{user.nickname|Friend}}!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello Friend!', $result);
    }

    public function test_parse_uses_default_value_when_callback_returns_empty_string(): void
    {
        $this->registry
            ->method('getCallback')
            ->with('user', 'nickname')
            ->willReturn(fn(array $context) => '');

        $content = 'Hello {{user.nickname|Guest}}!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello Guest!', $result);
    }

    public function test_parse_handles_multiple_smartcodes(): void
    {
        $this->registry
            ->method('getCallback')
            ->willReturnMap([
                ['user', 'first_name', fn(array $context) => 'John'],
                ['user', 'last_name', fn(array $context) => 'Doe'],
                ['site', 'name', fn(array $context) => 'My Site'],
            ]);

        $content = 'Hello {{user.first_name}} {{user.last_name}}, welcome to {{site.name}}!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello John Doe, welcome to My Site!', $result);
    }

    public function test_parse_passes_context_to_callback(): void
    {
        $receivedContext = null;

        $this->registry
            ->method('getCallback')
            ->with('user', 'email')
            ->willReturn(function (array $context) use (&$receivedContext) {
                $receivedContext = $context;
                return 'test@example.com';
            });

        $context = ['user_id' => 123, 'post_id' => 456];
        $this->parser->parse('{{user.email}}', $context);

        $this->assertEquals($context, $receivedContext);
    }

    public function test_parse_handles_content_without_smartcodes(): void
    {
        $content = 'Hello World!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello World!', $result);
    }

    public function test_parse_handles_empty_default_value(): void
    {
        $this->registry
            ->method('getCallback')
            ->with('user', 'name')
            ->willReturn(fn(array $context) => null);

        $content = 'Hello {{user.name|}}!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello !', $result);
    }

    public function test_parse_converts_non_string_return_value_to_string(): void
    {
        $this->registry
            ->method('getCallback')
            ->with('user', 'id')
            ->willReturn(fn(array $context) => 42);

        $content = 'User ID: {{user.id}}';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('User ID: 42', $result);
    }

    public function test_parse_handles_smartcode_with_underscores(): void
    {
        $this->registry
            ->method('getCallback')
            ->with('user', 'first_name')
            ->willReturn(fn(array $context) => 'John');

        $content = 'Hello {{user.first_name}}!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello John!', $result);
    }

    public function test_parse_is_case_insensitive_for_smartcode_names(): void
    {
        $this->registry
            ->method('getCallback')
            ->with('User', 'Email')
            ->willReturn(fn(array $context) => 'john@example.com');

        $content = 'Hello {{User.Email}}!';
        $result = $this->parser->parse($content, []);

        $this->assertEquals('Hello john@example.com!', $result);
    }

    // =========================================================================
    // findUnparsed() tests
    // =========================================================================

    public function test_find_unparsed_returns_remaining_smartcodes(): void
    {
        $content = 'Hello {{user.name}}, your email is {{user.email}}';
        $result = $this->parser->findUnparsed($content);

        $this->assertCount(2, $result);
        $this->assertContains('user.name', $result);
        $this->assertContains('user.email', $result);
    }

    public function test_find_unparsed_returns_empty_array_for_clean_text(): void
    {
        $content = 'Hello World, no smartcodes here!';
        $result = $this->parser->findUnparsed($content);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_find_unparsed_returns_unique_codes(): void
    {
        $content = '{{user.email}} and again {{user.email}}';
        $result = $this->parser->findUnparsed($content);

        $this->assertCount(1, $result);
        $this->assertContains('user.email', $result);
    }

    public function test_find_unparsed_handles_codes_with_defaults(): void
    {
        $content = 'Hello {{user.name|Guest}}!';
        $result = $this->parser->findUnparsed($content);

        $this->assertCount(1, $result);
        $this->assertContains('user.name', $result);
    }

    public function test_find_unparsed_handles_multiple_categories(): void
    {
        $content = '{{user.email}} {{site.name}} {{date.today}}';
        $result = $this->parser->findUnparsed($content);

        $this->assertCount(3, $result);
        $this->assertContains('user.email', $result);
        $this->assertContains('site.name', $result);
        $this->assertContains('date.today', $result);
    }

    // =========================================================================
    // extractCodes() tests
    // =========================================================================

    public function test_extract_codes_returns_full_code_info(): void
    {
        $content = 'Hello {{user.name}}!';
        $result = $this->parser->extractCodes($content);

        $this->assertCount(1, $result);
        $this->assertEquals('{{user.name}}', $result[0]['full']);
        $this->assertEquals('user', $result[0]['category']);
        $this->assertEquals('name', $result[0]['field']);
        $this->assertNull($result[0]['default']);
    }

    public function test_extract_codes_includes_default_value(): void
    {
        $content = 'Hello {{user.name|Guest}}!';
        $result = $this->parser->extractCodes($content);

        $this->assertCount(1, $result);
        $this->assertEquals('{{user.name|Guest}}', $result[0]['full']);
        $this->assertEquals('user', $result[0]['category']);
        $this->assertEquals('name', $result[0]['field']);
        $this->assertEquals('Guest', $result[0]['default']);
    }

    public function test_extract_codes_returns_all_codes_including_duplicates(): void
    {
        $content = '{{user.email}} and {{user.email}}';
        $result = $this->parser->extractCodes($content);

        $this->assertCount(2, $result);
        $this->assertEquals('user.email', $result[0]['category'] . '.' . $result[0]['field']);
        $this->assertEquals('user.email', $result[1]['category'] . '.' . $result[1]['field']);
    }

    public function test_extract_codes_returns_empty_array_when_no_codes(): void
    {
        $content = 'No smartcodes here';
        $result = $this->parser->extractCodes($content);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_extract_codes_handles_mixed_codes_with_and_without_defaults(): void
    {
        $content = 'Hello {{user.name|Guest}}, email: {{user.email}}';
        $result = $this->parser->extractCodes($content);

        $this->assertCount(2, $result);

        // First code with default
        $this->assertEquals('{{user.name|Guest}}', $result[0]['full']);
        $this->assertEquals('Guest', $result[0]['default']);

        // Second code without default
        $this->assertEquals('{{user.email}}', $result[1]['full']);
        $this->assertNull($result[1]['default']);
    }

    public function test_extract_codes_handles_empty_default(): void
    {
        $content = 'Hello {{user.name|}}!';
        $result = $this->parser->extractCodes($content);

        $this->assertCount(1, $result);
        $this->assertEquals('{{user.name|}}', $result[0]['full']);
        $this->assertEquals('', $result[0]['default']);
    }

    // =========================================================================
    // Method signature and structure tests
    // =========================================================================

    public function test_class_has_correct_method_signatures(): void
    {
        $reflection = new \ReflectionClass(SmartCodeParser::class);

        // parse method
        $method = $reflection->getMethod('parse');
        $this->assertEquals('string', $method->getReturnType()->getName());
        $this->assertCount(2, $method->getParameters());
        $this->assertEquals('string', $method->getParameters()[0]->getType()->getName());
        $this->assertEquals('array', $method->getParameters()[1]->getType()->getName());

        // findUnparsed method
        $method = $reflection->getMethod('findUnparsed');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(1, $method->getParameters());
        $this->assertEquals('string', $method->getParameters()[0]->getType()->getName());

        // extractCodes method
        $method = $reflection->getMethod('extractCodes');
        $this->assertEquals('array', $method->getReturnType()->getName());
        $this->assertCount(1, $method->getParameters());
        $this->assertEquals('string', $method->getParameters()[0]->getType()->getName());
    }

    public function test_class_has_pattern_constant(): void
    {
        $reflection = new \ReflectionClass(SmartCodeParser::class);

        $this->assertTrue($reflection->hasConstant('PATTERN'));

        $constant = $reflection->getReflectionConstant('PATTERN');
        $this->assertTrue($constant->isPrivate());
    }

    public function test_constructor_requires_registry(): void
    {
        $reflection = new \ReflectionClass(SmartCodeParser::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertCount(1, $constructor->getParameters());

        $param = $constructor->getParameters()[0];
        $this->assertEquals('registry', $param->getName());
        $this->assertEquals(SmartCodeRegistry::class, $param->getType()->getName());
    }
}
