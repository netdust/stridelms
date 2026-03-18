<?php

declare(strict_types=1);

namespace Tests\Integration\NetdustMail;

use Netdust\Mail\MailService;
use Netdust\Mail\MailTemplateCPT;
use Netdust\Mail\MailTemplateRepository;
use Netdust\Mail\SmartCodeRegistry;

/**
 * Integration tests for MailService.
 *
 * Tests the end-to-end email flow with real WordPress loaded:
 * - Template creation and retrieval
 * - SmartCode parsing in subjects and bodies
 * - Email blocking for unparsed SmartCodes
 * - Draft template blocking
 * - Fluent builder API
 *
 * These tests use the real services from the container to ensure
 * all hooks and registrations work properly.
 *
 * @group integration
 * @group netdust-mail
 */
class MailServiceIntegrationTest extends \IntegrationTestCase
{
    private MailService $mailService;
    private SmartCodeRegistry $smartCodeRegistry;

    /**
     * Track filter hooks added during test for cleanup.
     */
    private array $addedFilters = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Use the real services from the container
        // This ensures CPT registration and all hooks are properly set up
        $this->mailService = ntdst_get(MailService::class);
        $this->smartCodeRegistry = ntdst_get(SmartCodeRegistry::class);

        // Refresh the SmartCode registry to ensure clean state
        $this->smartCodeRegistry->refresh();
    }

    protected function tearDown(): void
    {
        // Remove any pre_wp_mail filters added during tests
        foreach ($this->addedFilters as $filter) {
            remove_filter($filter['hook'], $filter['callback'], $filter['priority']);
        }
        $this->addedFilters = [];

        parent::tearDown();
    }

    /**
     * Helper to create a test email template.
     *
     * @param string $slug    Template slug (post_name).
     * @param string $subject Subject line with optional SmartCodes.
     * @param string $body    HTML body with optional SmartCodes.
     * @param string $status  Template status ('active' or 'draft').
     * @return int The created post ID.
     */
    private function createTestTemplate(string $slug, string $subject, string $body, string $status = 'active'): int
    {
        $templateId = wp_insert_post([
            'post_type' => MailTemplateCPT::POST_TYPE,
            'post_name' => $slug,
            'post_title' => ucfirst(str_replace('-', ' ', $slug)),
            'post_status' => 'publish',
            'post_content' => $body, // Body is now stored in post_content (WYSIWYG editor)
        ]);

        if (is_wp_error($templateId)) {
            throw new \RuntimeException('Failed to create test template: ' . $templateId->get_error_message());
        }

        update_post_meta($templateId, '_ndmail_subject', $subject);
        update_post_meta($templateId, '_ndmail_status', $status);

        self::$testPosts[] = $templateId;

        return $templateId;
    }

    /**
     * Helper to intercept wp_mail calls and capture sent email data.
     *
     * @param callable $callback Receives captured email array when send is attempted.
     * @return void
     */
    private function interceptMail(callable $callback): void
    {
        $filter = function ($null, $atts) use ($callback) {
            $callback($atts);
            return true; // Prevent actual sending
        };

        add_filter('pre_wp_mail', $filter, 10, 2);

        $this->addedFilters[] = [
            'hook' => 'pre_wp_mail',
            'callback' => $filter,
            'priority' => 10,
        ];
    }

    // =========================================================================
    // TEMPLATE NOT FOUND
    // =========================================================================

    /**
     * @test
     */
    public function sendReturnsErrorForMissingTemplate(): void
    {
        $result = $this->mailService->send('nonexistent-template-' . time(), [], ['to' => 'test@example.com']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_template_not_found', $result->get_error_code());
    }

    // =========================================================================
    // DRAFT TEMPLATE BLOCKING
    // =========================================================================

    /**
     * @test
     */
    public function sendBlocksDraftTemplates(): void
    {
        $slug = 'draft-template-' . time();
        $this->createTestTemplate(
            $slug,
            'Test Subject',
            '<p>Test body</p>',
            'draft'
        );

        $result = $this->mailService->send($slug, [], ['to' => 'test@example.com']);

        $this->assertInstanceOf(\WP_Error::class, $result, 'Should return WP_Error for draft template');
        $this->assertEquals('ndmail_template_inactive', $result->get_error_code());
    }

    // =========================================================================
    // UNPARSED SMARTCODE BLOCKING
    // =========================================================================

    /**
     * @test
     */
    public function sendBlocksEmailWithUnparsedSmartcodes(): void
    {
        $slug = 'broken-template-' . time();
        // Create template with unknown SmartCode (order.number is not registered)
        $this->createTestTemplate(
            $slug,
            'Order {{order.number}}',
            '<p>Order details for {{order.customer}}</p>'
        );

        $result = $this->mailService->send($slug, ['user_id' => 1], ['to' => 'test@example.com']);

        $this->assertInstanceOf(\WP_Error::class, $result, 'Should return WP_Error for unparsed SmartCodes');
        $this->assertEquals('ndmail_unparsed_smartcodes', $result->get_error_code());
        $this->assertStringContainsString('order.number', $result->get_error_message());
    }

    // =========================================================================
    // SUCCESSFUL SENDING WITH SMARTCODE PARSING
    // =========================================================================

    /**
     * @test
     */
    public function sendParsesSmartcodesAndSendsEmail(): void
    {
        // Set up test user with first name
        $userId = self::$testUserId;
        update_user_meta($userId, 'first_name', 'John');

        $slug = 'welcome-test-' . time();
        $this->createTestTemplate(
            $slug,
            'Hello {{user.first_name}}!',
            '<p>Welcome, {{user.display_name}}!</p>'
        );

        // Capture sent email
        $sentMail = null;
        $this->interceptMail(function ($atts) use (&$sentMail) {
            $sentMail = $atts;
        });

        $result = $this->mailService->send($slug, ['user_id' => $userId], ['to' => 'test@example.com']);

        // Verify
        $this->assertTrue($result, 'Email should send successfully');
        $this->assertNotNull($sentMail, 'Email should have been captured');
        $this->assertEquals('Hello John!', $sentMail['subject'], 'Subject should have SmartCode parsed');
        $this->assertStringContainsString('Welcome,', $sentMail['message'], 'Body should contain content');
    }

    // =========================================================================
    // SMARTCODE DEFAULT VALUES
    // =========================================================================

    /**
     * @test
     */
    public function smartcodeDefaultValuesWork(): void
    {
        $slug = 'default-test-' . time();
        // Create template with default value
        $this->createTestTemplate(
            $slug,
            'Hello {{user.first_name|Guest}}!',
            '<p>Welcome!</p>'
        );

        $sentMail = null;
        $this->interceptMail(function ($atts) use (&$sentMail) {
            $sentMail = $atts;
        });

        // Send without valid user context (first_name will be empty)
        // User ID 999999 doesn't exist, so callback returns empty string
        $result = $this->mailService->send($slug, ['user_id' => 999999], ['to' => 'test@example.com']);

        // Verify default value is used
        $this->assertTrue($result, 'Email should send successfully');
        $this->assertNotNull($sentMail, 'Email should have been captured');
        $this->assertEquals('Hello Guest!', $sentMail['subject'], 'Default value should be used');
    }

    /**
     * @test
     */
    public function smartcodeDefaultValueUsedWhenUserHasNoFirstName(): void
    {
        // Create a user without first_name set
        $username = 'nofirstname_' . time();
        $userId = wp_create_user($username, 'testpass', $username . '@test.local');
        $this->assertIsInt($userId, 'Should create test user');

        // Ensure first_name is empty
        delete_user_meta($userId, 'first_name');

        $slug = 'default-fallback-' . time();
        $this->createTestTemplate(
            $slug,
            'Hi {{user.first_name|Valued Customer}}',
            '<p>Welcome!</p>'
        );

        $sentMail = null;
        $this->interceptMail(function ($atts) use (&$sentMail) {
            $sentMail = $atts;
        });

        $result = $this->mailService->send($slug, ['user_id' => $userId], ['to' => 'test@example.com']);

        // Cleanup test user
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($userId);

        $this->assertTrue($result, 'Email should send successfully');
        $this->assertEquals('Hi Valued Customer', $sentMail['subject']);
    }

    // =========================================================================
    // FLUENT BUILDER API
    // =========================================================================

    /**
     * @test
     */
    public function builderFluentApiWorks(): void
    {
        $userId = self::$testUserId;
        update_user_meta($userId, 'first_name', 'Jane');

        $slug = 'builder-test-' . time();
        $this->createTestTemplate(
            $slug,
            'Welcome {{user.first_name}}',
            '<p>Hello!</p>'
        );

        $sentMail = null;
        $this->interceptMail(function ($atts) use (&$sentMail) {
            $sentMail = $atts;
        });

        $builder = $this->mailService->template($slug);
        $result = $builder
            ->context(['user_id' => $userId])
            ->to('jane@example.com')
            ->send();

        $this->assertTrue($result, 'Builder should send successfully');
        $this->assertNotNull($sentMail, 'Email should have been captured');
        $this->assertEquals('Welcome Jane', $sentMail['subject']);
        // wp_mail receives 'to' as an array
        $to = is_array($sentMail['to']) ? $sentMail['to'][0] : $sentMail['to'];
        $this->assertEquals('jane@example.com', $to);
    }

    /**
     * @test
     */
    public function builderCanSetCcAndBcc(): void
    {
        $slug = 'cc-bcc-test-' . time();
        $this->createTestTemplate(
            $slug,
            'Test Email',
            '<p>Body</p>'
        );

        $sentMail = null;
        $this->interceptMail(function ($atts) use (&$sentMail) {
            $sentMail = $atts;
        });

        $result = $this->mailService->template($slug)
            ->to('primary@example.com')
            ->cc('copy@example.com')
            ->bcc('blind@example.com')
            ->send();

        $this->assertTrue($result);
        $this->assertNotNull($sentMail);
        // wp_mail receives 'to' as an array
        $to = is_array($sentMail['to']) ? $sentMail['to'][0] : $sentMail['to'];
        $this->assertEquals('primary@example.com', $to);
        // CC and BCC are passed through to wp_mail via headers
        $this->assertArrayHasKey('headers', $sentMail);
    }

    // =========================================================================
    // NO RECIPIENT ERROR
    // =========================================================================

    /**
     * @test
     */
    public function sendReturnsErrorWhenNoRecipient(): void
    {
        $slug = 'no-recipient-' . time();
        $this->createTestTemplate(
            $slug,
            'Test Subject',
            '<p>Test body</p>'
        );

        // Send without 'to' and without valid user context
        $result = $this->mailService->send($slug, [], []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_no_recipient', $result->get_error_code());
    }

    /**
     * @test
     */
    public function sendResolvesRecipientFromUserContext(): void
    {
        $userId = self::$testUserId;
        $user = get_userdata($userId);
        $userEmail = $user->user_email;

        $slug = 'user-recipient-' . time();
        $this->createTestTemplate(
            $slug,
            'Test Subject',
            '<p>Test body</p>'
        );

        $sentMail = null;
        $this->interceptMail(function ($atts) use (&$sentMail) {
            $sentMail = $atts;
        });

        // Send without explicit 'to' but with user_id context
        $result = $this->mailService->send($slug, ['user_id' => $userId], []);

        $this->assertTrue($result, 'Should resolve recipient from user context');
        $this->assertNotNull($sentMail);
        // wp_mail receives 'to' as an array
        $to = is_array($sentMail['to']) ? $sentMail['to'][0] : $sentMail['to'];
        $this->assertEquals($userEmail, $to);
    }

    // =========================================================================
    // SITE SMARTCODES
    // =========================================================================

    /**
     * @test
     */
    public function siteSmartcodesWork(): void
    {
        $slug = 'site-codes-' . time();
        $this->createTestTemplate(
            $slug,
            'Welcome to {{site.name}}',
            '<p>Visit us at {{site.url}}</p>'
        );

        $sentMail = null;
        $this->interceptMail(function ($atts) use (&$sentMail) {
            $sentMail = $atts;
        });

        $result = $this->mailService->send($slug, [], ['to' => 'test@example.com']);

        $this->assertTrue($result);
        $this->assertNotNull($sentMail);
        $this->assertStringContainsString(get_bloginfo('name'), $sentMail['subject']);
        $this->assertStringContainsString(home_url(), $sentMail['message']);
    }

    // =========================================================================
    // TEMPLATE REPOSITORY INTEGRATION
    // =========================================================================

    /**
     * @test
     */
    public function templateRepositoryFindsTemplateBySlug(): void
    {
        $slug = 'repo-test-' . time();
        $this->createTestTemplate(
            $slug,
            'Repository Test',
            '<p>Testing repository lookup</p>'
        );

        $repository = new MailTemplateRepository();
        $template = $repository->findBySlug($slug);

        $this->assertNotNull($template, 'Repository should find template');
        $this->assertEquals($slug, $template->post_name);
        $this->assertArrayHasKey('subject', $template->fields);
        $this->assertEquals('Repository Test', $template->fields['subject']);
    }

    /**
     * @test
     */
    public function templateRepositoryReturnsNullForNonexistent(): void
    {
        $repository = new MailTemplateRepository();
        $template = $repository->findBySlug('nonexistent-slug-' . time());

        $this->assertNull($template);
    }
}
