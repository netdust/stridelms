<?php

declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\Admin\AdminController;
use Netdust\Mail\SmartCodeRegistry;
use Netdust\Mail\TriggerRegistry;
use Netdust\Mail\AttachmentHandler;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Netdust\Mail\Admin\AdminController
 */
class AdminControllerTest extends TestCase
{
    private SmartCodeRegistry $mockSmartCodeRegistry;
    private TriggerRegistry $mockTriggerRegistry;
    private AttachmentHandler $mockAttachmentHandler;

    protected function setUp(): void
    {
        parent::setUp();

        global $_test_actions, $wp_scripts, $wp_styles, $current_user_caps, $_test_options_pages;
        $_test_actions = [];
        $wp_scripts = [];
        $wp_styles = [];
        $current_user_caps = [];
        $_test_options_pages = [];

        if (!defined('NDMAIL_URL')) {
            define('NDMAIL_URL', 'https://example.com/wp-content/plugins/netdust-mail/');
        }
        if (!defined('NDMAIL_VERSION')) {
            define('NDMAIL_VERSION', '1.0.0');
        }
        if (!defined('NDMAIL_PATH')) {
            define('NDMAIL_PATH', '/tmp/netdust-mail-test/');
        }

        $this->mockSmartCodeRegistry = $this->createMock(SmartCodeRegistry::class);
        $this->mockTriggerRegistry = $this->createMock(TriggerRegistry::class);
        $this->mockAttachmentHandler = $this->createMock(AttachmentHandler::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        global $current_user_caps;
        $current_user_caps = [];
    }

    public function testConstructorRegistersHooks(): void
    {
        $controller = new AdminController(
            $this->mockSmartCodeRegistry,
            $this->mockTriggerRegistry,
            $this->mockAttachmentHandler
        );

        $this->assertTrue(has_action('admin_menu') !== false);
        $this->assertTrue(has_action('admin_enqueue_scripts') !== false);
        $this->assertTrue(has_action('admin_head') !== false);
        $this->assertTrue(has_action('admin_footer') !== false);
        $this->assertTrue(has_action('rest_api_init') !== false);
    }

    public function testRegisterMenuAddsOptionsPage(): void
    {
        global $_test_options_pages;

        $controller = new AdminController(
            $this->mockSmartCodeRegistry,
            $this->mockTriggerRegistry,
            $this->mockAttachmentHandler
        );

        $controller->registerMenu();

        $this->assertArrayHasKey('netdust-mail', $_test_options_pages);
        $this->assertEquals('Netdust Mail', $_test_options_pages['netdust-mail']['page_title']);
        $this->assertEquals('manage_options', $_test_options_pages['netdust-mail']['capability']);
    }

    public function testEnqueueAssetsDoesNothingForOtherPages(): void
    {
        global $wp_scripts;
        $wp_scripts = [];

        $controller = new AdminController(
            $this->mockSmartCodeRegistry,
            $this->mockTriggerRegistry,
            $this->mockAttachmentHandler
        );

        $controller->enqueueAssets('edit.php');

        $this->assertFalse(wp_script_is('alpinejs', 'enqueued'));
    }

    public function testEnqueueAssetsEnqueuesForMailSettingsPage(): void
    {
        global $wp_scripts;
        $wp_scripts = [];

        $this->mockSmartCodeRegistry->method('getAll')->willReturn([]);
        $this->mockSmartCodeRegistry->method('getAllFlat')->willReturn([]);
        $this->mockTriggerRegistry->method('getAll')->willReturn([]);
        $this->mockTriggerRegistry->method('getOptions')->willReturn([]);
        $this->mockAttachmentHandler->method('getAvailableGenerators')->willReturn([]);

        $controller = new AdminController(
            $this->mockSmartCodeRegistry,
            $this->mockTriggerRegistry,
            $this->mockAttachmentHandler
        );

        $controller->enqueueAssets('settings_page_netdust-mail');

        $this->assertTrue(wp_script_is('alpinejs', 'enqueued'));
    }

    public function testEnqueueAssetsLocalizesMailConfig(): void
    {
        global $wp_scripts;
        $wp_scripts = [];

        $smartcodes = [['code' => '{user.email}', 'category' => 'User', 'label' => 'Email']];
        $triggers = ['user_register' => ['label' => 'User Registration', 'context' => ['user_id']]];
        $generators = ['invoice' => ['label' => 'Invoice PDF', 'context_key' => 'invoice_id']];

        $this->mockSmartCodeRegistry->method('getAll')->willReturn($smartcodes);
        $this->mockSmartCodeRegistry->method('getAllFlat')->willReturn($smartcodes);
        $this->mockTriggerRegistry->method('getAll')->willReturn($triggers);
        $this->mockTriggerRegistry->method('getOptions')->willReturn([]);
        $this->mockAttachmentHandler->method('getAvailableGenerators')->willReturn($generators);

        $controller = new AdminController(
            $this->mockSmartCodeRegistry,
            $this->mockTriggerRegistry,
            $this->mockAttachmentHandler
        );

        $controller->enqueueAssets('settings_page_netdust-mail');

        $this->assertArrayHasKey('l10n', $wp_scripts['alpinejs']);
        $this->assertArrayHasKey('MailConfig', $wp_scripts['alpinejs']['l10n']);

        $config = $wp_scripts['alpinejs']['l10n']['MailConfig'];
        $this->assertEquals($smartcodes, $config['smartcodes']);
        $this->assertEquals($triggers, $config['triggers']);
        $this->assertEquals($generators, $config['pdfGenerators']);
    }

    public function testRegisterRestRoutesRegistersSettingsEndpoint(): void
    {
        global $_test_rest_routes;
        $_test_rest_routes = [];

        $controller = new AdminController(
            $this->mockSmartCodeRegistry,
            $this->mockTriggerRegistry,
            $this->mockAttachmentHandler
        );

        $controller->registerRestRoutes();

        $this->assertArrayHasKey('netdust-mail/v1/settings', $_test_rest_routes);
    }
}
