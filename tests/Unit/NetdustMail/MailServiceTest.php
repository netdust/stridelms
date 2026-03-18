<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\MailService;
use Netdust\Mail\SmartCodeRegistry;
use Netdust\Mail\SmartCodeParser;
use Netdust\Mail\TriggerRegistry;
use Netdust\Mail\AttachmentHandler;
use Netdust\Mail\MailTemplateRepository;
use PHPUnit\Framework\TestCase;

class MailServiceTest extends TestCase
{
    private SmartCodeRegistry $smartCodeRegistry;
    private SmartCodeParser $smartCodeParser;
    private TriggerRegistry $triggerRegistry;
    private AttachmentHandler $attachmentHandler;
    private MailTemplateRepository $templateRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset WordPress globals
        global $_test_actions, $_test_filters, $_test_action_calls;
        $_test_actions = [];
        $_test_filters = [];
        $_test_action_calls = [];

        // Create mock dependencies
        $this->smartCodeRegistry = $this->createMock(SmartCodeRegistry::class);
        $this->smartCodeParser = $this->createMock(SmartCodeParser::class);
        $this->triggerRegistry = $this->createMock(TriggerRegistry::class);
        $this->attachmentHandler = $this->createMock(AttachmentHandler::class);
        $this->templateRepository = $this->createMock(MailTemplateRepository::class);
    }

    public function test_metadata_returns_required_fields(): void
    {
        $meta = MailService::metadata();

        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('description', $meta);
        $this->assertArrayHasKey('priority', $meta);
        $this->assertEquals('Mail Service', $meta['name']);
    }

    public function test_constructor_registers_hooks(): void
    {
        global $_test_actions, $_test_filters;

        // TriggerRegistry returns empty for activateTriggers
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        // Verify init action is registered for CPT
        $this->assertArrayHasKey('init', $_test_actions);

        // Verify ndmail_before_send filter is registered
        $this->assertArrayHasKey('ndmail_before_send', $_test_filters);

        // Verify built-in SmartCodes filter is registered
        $this->assertArrayHasKey('ndmail_smartcodes', $_test_filters);

        // Verify built-in triggers filter is registered
        $this->assertArrayHasKey('ndmail_triggers', $_test_filters);
    }

    public function test_register_builtin_smartcodes_adds_site_codes(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $codes = $service->registerBuiltinSmartCodes([]);

        $this->assertArrayHasKey('site', $codes);
        $this->assertArrayHasKey('codes', $codes['site']);
        $this->assertArrayHasKey('name', $codes['site']['codes']);
        $this->assertArrayHasKey('url', $codes['site']['codes']);
        $this->assertArrayHasKey('admin_email', $codes['site']['codes']);
    }

    public function test_register_builtin_smartcodes_adds_user_codes(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $codes = $service->registerBuiltinSmartCodes([]);

        $this->assertArrayHasKey('user', $codes);
        $this->assertArrayHasKey('codes', $codes['user']);
        $this->assertArrayHasKey('email', $codes['user']['codes']);
        $this->assertArrayHasKey('first_name', $codes['user']['codes']);
        $this->assertArrayHasKey('last_name', $codes['user']['codes']);
        $this->assertArrayHasKey('display_name', $codes['user']['codes']);
    }

    public function test_register_builtin_smartcodes_adds_date_codes(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $codes = $service->registerBuiltinSmartCodes([]);

        $this->assertArrayHasKey('date', $codes);
        $this->assertArrayHasKey('codes', $codes['date']);
        $this->assertArrayHasKey('today', $codes['date']['codes']);
        $this->assertArrayHasKey('year', $codes['date']['codes']);
        $this->assertArrayHasKey('month', $codes['date']['codes']);
    }

    public function test_register_builtin_triggers_adds_standard_triggers(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $triggers = $service->registerBuiltinTriggers([]);

        $this->assertArrayHasKey('user_register', $triggers);
        $this->assertArrayHasKey('retrieve_password', $triggers);
        $this->assertArrayHasKey('wp_login', $triggers);
    }

    public function test_send_returns_error_when_template_not_found(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);
        $this->templateRepository->method('findBySlug')
            ->with('nonexistent')
            ->willReturn(null);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $result = $service->send('nonexistent', []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_template_not_found', $result->get_error_code());
    }

    public function test_send_returns_error_when_template_inactive(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $template = new \WP_Post([
            'ID' => 1,
            'post_name' => 'inactive-template',
            'post_content' => 'Test body',
        ]);
        $template->fields = [
            'status' => 'draft',
            'subject' => 'Test',
        ];
        $this->templateRepository->method('findBySlug')
            ->with('inactive-template')
            ->willReturn($template);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $result = $service->send('inactive-template', []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_template_inactive', $result->get_error_code());
    }

    public function test_send_blocks_email_with_unparsed_smartcodes(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $template = new \WP_Post([
            'ID' => 2,
            'post_name' => 'test-template',
            'post_content' => 'Welcome {{user.first_name}}!',
        ]);
        $template->fields = [
            'status' => 'active',
            'subject' => 'Hello {{user.first_name}}',
        ];
        $this->templateRepository->method('findBySlug')
            ->with('test-template')
            ->willReturn($template);

        // Parser returns content unchanged (unparsed)
        $this->smartCodeParser->method('parse')
            ->willReturnCallback(fn($content) => $content);

        // Parser finds unparsed codes
        $this->smartCodeParser->method('findUnparsed')
            ->willReturn(['{{user.first_name}}']);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $result = $service->send('test-template', []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_unparsed_smartcodes', $result->get_error_code());
    }

    public function test_send_returns_error_when_no_recipient(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $template = new \WP_Post([
            'ID' => 3,
            'post_name' => 'test-template',
        ]);
        $template->fields = [
            'status' => 'active',
            'subject' => 'Test Subject',
                    ];
        $this->templateRepository->method('findBySlug')
            ->with('test-template')
            ->willReturn($template);

        // Parser returns parsed content (no SmartCodes)
        $this->smartCodeParser->method('parse')
            ->willReturnCallback(fn($content) => $content);
        $this->smartCodeParser->method('findUnparsed')
            ->willReturn([]);

        // No attachments
        $this->attachmentHandler->method('resolve')
            ->willReturn([]);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        // No user_id in context and no 'to' in options
        $result = $service->send('test-template', []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_no_recipient', $result->get_error_code());
    }

    public function test_parse_smartcodes_delegates_to_parser(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $this->smartCodeParser->expects($this->once())
            ->method('parse')
            ->with('Hello {{user.name}}', ['user_id' => 1])
            ->willReturn('Hello John');

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $result = $service->parseSmartCodes('Hello {{user.name}}', ['user_id' => 1], 'test-template');

        $this->assertEquals('Hello John', $result);
    }

    public function test_template_returns_mail_builder(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $builder = $service->template('welcome');

        $this->assertInstanceOf(\Netdust\Mail\MailBuilder::class, $builder);
    }

    public function test_send_returns_error_when_attachment_handler_fails(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $template = new \WP_Post([
            'ID' => 4,
            'post_name' => 'test-template',
        ]);
        $template->fields = [
            'status' => 'active',
            'subject' => 'Test Subject',
                        'attachments' => ['some-attachment'],
        ];
        $this->templateRepository->method('findBySlug')
            ->with('test-template')
            ->willReturn($template);

        $this->smartCodeParser->method('parse')
            ->willReturnCallback(fn($content) => $content);
        $this->smartCodeParser->method('findUnparsed')
            ->willReturn([]);

        // Attachment handler returns error
        $this->attachmentHandler->method('resolve')
            ->willReturn(new \WP_Error('ndmail_attachment_error', 'Attachment not found'));

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $result = $service->send('test-template', [], ['to' => 'user@example.com']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_attachment_error', $result->get_error_code());
    }

    public function test_builtin_smartcode_callbacks_are_callable(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $codes = $service->registerBuiltinSmartCodes([]);

        // Verify all callbacks are callable
        foreach ($codes as $category => $categoryConfig) {
            foreach ($categoryConfig['codes'] as $codeKey => $codeConfig) {
                $this->assertArrayHasKey('callback', $codeConfig, "Missing callback for {$category}.{$codeKey}");
                $this->assertIsCallable($codeConfig['callback'], "Callback not callable for {$category}.{$codeKey}");
            }
        }
    }

    public function test_builtin_triggers_have_required_fields(): void
    {
        $this->triggerRegistry->method('getAll')->willReturn([]);
        $this->templateRepository->method('findWithTriggers')->willReturn([]);

        $service = new MailService(
            $this->smartCodeRegistry,
            $this->smartCodeParser,
            $this->triggerRegistry,
            $this->attachmentHandler,
            $this->templateRepository
        );

        $triggers = $service->registerBuiltinTriggers([]);

        foreach ($triggers as $key => $config) {
            $this->assertArrayHasKey('label', $config, "Missing label for trigger: {$key}");
            $this->assertArrayHasKey('context', $config, "Missing context for trigger: {$key}");
            $this->assertIsArray($config['context'], "Context must be array for trigger: {$key}");
        }
    }
}
