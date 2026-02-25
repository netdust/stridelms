# netdust-mail Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a standalone email template management plugin that integrates with ntdst-core for SmartCode-powered, trigger-based email dispatch.

**Architecture:** Thin wrapper around `ntdst_mail()`. CPT stores templates, SmartCodeParser handles `{{category.field}}` replacement, TriggerRegistry maps WP actions to auto-send, AttachmentHandler resolves media + generated PDFs.

**Tech Stack:** PHP 8.1+, ntdst-core (DI, Mailer, Data Manager, Logger), WordPress CPT

---

## Phase 1: Plugin Bootstrap & Core Structure

### Task 1.1: Create Plugin Directory and Bootstrap

**Files:**
- Create: `web/app/plugins/netdust-mail/netdust-mail.php`
- Create: `web/app/plugins/netdust-mail/composer.json`

**Step 1: Create plugin directory**

```bash
mkdir -p web/app/plugins/netdust-mail/src
mkdir -p web/app/plugins/netdust-mail/templates/emails
mkdir -p web/app/plugins/netdust-mail/assets/css
mkdir -p web/app/plugins/netdust-mail/assets/js
```

**Step 2: Create composer.json**

```json
{
    "name": "netdust/netdust-mail",
    "description": "Email template management for NTDST WordPress projects",
    "type": "wordpress-plugin",
    "license": "GPL-2.0-or-later",
    "require": {
        "php": ">=8.1"
    },
    "autoload": {
        "psr-4": {
            "Netdust\\Mail\\": "src/"
        }
    }
}
```

**Step 3: Create bootstrap file**

```php
<?php
/**
 * Plugin Name: Netdust Mail
 * Description: Email template management with SmartCodes and action triggers
 * Version: 1.0.0
 * Requires PHP: 8.1
 * Author: Netdust
 * Text Domain: netdust-mail
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

// Require ntdst-core
if (!function_exists('ntdst_container')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Netdust Mail requires ntdst-core to be active.', 'netdust-mail');
        echo '</p></div>';
    });
    return;
}

define('NDMAIL_VERSION', '1.0.0');
define('NDMAIL_PATH', plugin_dir_path(__FILE__));
define('NDMAIL_URL', plugin_dir_url(__FILE__));

require_once NDMAIL_PATH . 'vendor/autoload.php';

// Register main service with ntdst container
add_action('ntdst/services_registered', function () {
    ntdst_set(\Netdust\Mail\MailService::class, function ($container) {
        return new \Netdust\Mail\MailService(
            $container->get(\Netdust\Mail\SmartCodeRegistry::class),
            $container->get(\Netdust\Mail\SmartCodeParser::class),
            $container->get(\Netdust\Mail\TriggerRegistry::class),
            $container->get(\Netdust\Mail\AttachmentHandler::class),
            $container->get(\Netdust\Mail\MailTemplateRepository::class)
        );
    });

    // Register sub-services
    ntdst_set(\Netdust\Mail\SmartCodeRegistry::class);
    ntdst_set(\Netdust\Mail\SmartCodeParser::class);
    ntdst_set(\Netdust\Mail\TriggerRegistry::class);
    ntdst_set(\Netdust\Mail\AttachmentHandler::class);
    ntdst_set(\Netdust\Mail\MailTemplateRepository::class);
    ntdst_set(\Netdust\Mail\Admin\AdminController::class);
});

// Boot after ntdst features are ready
add_action('ntdst/features_ready', function () {
    ntdst_get(\Netdust\Mail\MailService::class);
    ntdst_get(\Netdust\Mail\Admin\AdminController::class);
});

// Global helper functions
function ndmail_send(string $templateSlug, array $context, array $options = []): bool|\WP_Error
{
    return ntdst_get(\Netdust\Mail\MailService::class)->send($templateSlug, $context, $options);
}

function ndmail_template(string $templateSlug): \Netdust\Mail\MailBuilder
{
    return ntdst_get(\Netdust\Mail\MailService::class)->template($templateSlug);
}
```

**Step 4: Run composer dump-autoload**

```bash
cd web/app/plugins/netdust-mail && composer dump-autoload
```

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/
git commit -m "feat(netdust-mail): bootstrap plugin structure"
```

**Unit test:** Verify plugin activates without error when ntdst-core is present, shows admin notice when absent.

---

### Task 1.2: Create MailService Main Orchestrator

**Files:**
- Create: `web/app/plugins/netdust-mail/src/MailService.php`

**Step 1: Write the failing test**

Create `tests/Unit/NetdustMail/MailServiceTest.php`:

```php
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
    public function test_metadata_returns_required_fields(): void
    {
        $meta = MailService::metadata();

        $this->assertArrayHasKey('name', $meta);
        $this->assertArrayHasKey('description', $meta);
        $this->assertArrayHasKey('priority', $meta);
        $this->assertEquals('Mail Service', $meta['name']);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit --filter=MailServiceTest
```

Expected: FAIL with "Class not found"

**Step 3: Write MailService**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

class MailService implements \NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return [
            'name' => 'Mail Service',
            'description' => 'Email template management with SmartCodes and triggers',
            'priority' => 15,
        ];
    }

    public function __construct(
        private readonly SmartCodeRegistry $smartCodeRegistry,
        private readonly SmartCodeParser $smartCodeParser,
        private readonly TriggerRegistry $triggerRegistry,
        private readonly AttachmentHandler $attachmentHandler,
        private readonly MailTemplateRepository $templateRepository,
    ) {
        $this->init();
    }

    private function init(): void
    {
        // Register CPT
        add_action('init', [$this, 'registerCpt']);

        // Hook into mail pipeline for SmartCode parsing
        add_filter('ndmail_before_send', [$this, 'parseSmartCodes'], 10, 3);

        // Register built-in SmartCodes
        add_filter('ndmail_smartcodes', [$this, 'registerBuiltinSmartCodes']);

        // Register built-in triggers
        add_filter('ndmail_triggers', [$this, 'registerBuiltinTriggers']);

        // Activate template triggers
        add_action('init', [$this, 'activateTriggers'], 20);
    }

    public function registerCpt(): void
    {
        MailTemplateCPT::register();
    }

    public function registerBuiltinSmartCodes(array $codes): array
    {
        $codes['site'] = [
            'label' => __('Site', 'netdust-mail'),
            'codes' => [
                'name' => [
                    'label' => __('Site Name', 'netdust-mail'),
                    'callback' => fn($ctx) => get_bloginfo('name'),
                ],
                'url' => [
                    'label' => __('Site URL', 'netdust-mail'),
                    'callback' => fn($ctx) => home_url(),
                ],
                'admin_email' => [
                    'label' => __('Admin Email', 'netdust-mail'),
                    'callback' => fn($ctx) => get_option('admin_email'),
                ],
            ],
        ];

        $codes['user'] = [
            'label' => __('User', 'netdust-mail'),
            'codes' => [
                'email' => [
                    'label' => __('Email', 'netdust-mail'),
                    'callback' => function ($ctx) {
                        $user = $this->resolveUser($ctx);
                        return $user?->user_email;
                    },
                ],
                'first_name' => [
                    'label' => __('First Name', 'netdust-mail'),
                    'callback' => function ($ctx) {
                        $user = $this->resolveUser($ctx);
                        return $user?->first_name;
                    },
                ],
                'last_name' => [
                    'label' => __('Last Name', 'netdust-mail'),
                    'callback' => function ($ctx) {
                        $user = $this->resolveUser($ctx);
                        return $user?->last_name;
                    },
                ],
                'display_name' => [
                    'label' => __('Display Name', 'netdust-mail'),
                    'callback' => function ($ctx) {
                        $user = $this->resolveUser($ctx);
                        return $user?->display_name;
                    },
                ],
            ],
        ];

        $codes['date'] = [
            'label' => __('Date', 'netdust-mail'),
            'codes' => [
                'today' => [
                    'label' => __('Today', 'netdust-mail'),
                    'callback' => fn($ctx) => wp_date(get_option('date_format')),
                ],
                'year' => [
                    'label' => __('Year', 'netdust-mail'),
                    'callback' => fn($ctx) => wp_date('Y'),
                ],
                'month' => [
                    'label' => __('Month', 'netdust-mail'),
                    'callback' => fn($ctx) => wp_date('F'),
                ],
            ],
        ];

        return $codes;
    }

    public function registerBuiltinTriggers(array $triggers): array
    {
        $triggers['user_register'] = [
            'label' => __('User Registration', 'netdust-mail'),
            'context' => ['user_id'],
        ];
        $triggers['retrieve_password'] = [
            'label' => __('Password Reset Request', 'netdust-mail'),
            'context' => ['user_id'],
        ];
        $triggers['wp_login'] = [
            'label' => __('User Login', 'netdust-mail'),
            'context' => ['user_id'],
        ];

        return $triggers;
    }

    public function activateTriggers(): void
    {
        $templates = $this->templateRepository->findWithTriggers();
        $registeredTriggers = $this->triggerRegistry->getAll();

        foreach ($templates as $template) {
            $triggerKey = $template->fields['trigger'] ?? null;
            if (!$triggerKey || !isset($registeredTriggers[$triggerKey])) {
                continue;
            }

            add_action($triggerKey, function (...$args) use ($template, $triggerKey, $registeredTriggers) {
                $context = $this->buildContextFromTrigger($triggerKey, $args, $registeredTriggers[$triggerKey]);
                $this->send($template->post_name, $context);
            }, 10, 10);
        }
    }

    public function send(string $templateSlug, array $context, array $options = []): bool|\WP_Error
    {
        $template = $this->templateRepository->findBySlug($templateSlug);
        if (!$template) {
            return new \WP_Error('ndmail_template_not_found', sprintf('Template "%s" not found', $templateSlug));
        }

        if (($template->fields['status'] ?? 'draft') !== 'active') {
            return new \WP_Error('ndmail_template_inactive', sprintf('Template "%s" is not active', $templateSlug));
        }

        // Parse SmartCodes in subject and body
        $subject = $this->smartCodeParser->parse($template->fields['subject'] ?? '', $context);
        $body = $this->smartCodeParser->parse($template->fields['body'] ?? '', $context);

        // Check for unparsed SmartCodes
        $unparsedSubject = $this->smartCodeParser->findUnparsed($subject);
        $unparsedBody = $this->smartCodeParser->findUnparsed($body);
        $allUnparsed = array_merge($unparsedSubject, $unparsedBody);

        if (!empty($allUnparsed)) {
            ntdst_log('mail')->error('Email blocked: unparsed smartcodes', [
                'template' => $templateSlug,
                'unparsed' => $allUnparsed,
                'context_keys' => array_keys($context),
            ]);
            return new \WP_Error(
                'ndmail_unparsed_smartcodes',
                sprintf('Cannot send "%s": missing context for %s', $templateSlug, implode(', ', $allUnparsed))
            );
        }

        // Resolve recipient
        $to = $options['to'] ?? null;
        if (!$to) {
            $user = $this->resolveUser($context);
            $to = $user?->user_email;
        }
        if (!$to) {
            return new \WP_Error('ndmail_no_recipient', 'No recipient email address');
        }

        // Resolve attachments
        $attachments = $this->attachmentHandler->resolve($template->fields['attachments'] ?? [], $context);
        if (is_wp_error($attachments)) {
            ntdst_log('mail')->error('Email blocked: attachment error', [
                'template' => $templateSlug,
                'error' => $attachments->get_error_message(),
            ]);
            return $attachments;
        }

        // Build and send email
        $mail = ntdst_mail()
            ->to($to)
            ->subject($subject)
            ->html($body);

        if (!empty($options['cc'])) {
            $mail->cc($options['cc']);
        }
        if (!empty($options['bcc'])) {
            $mail->bcc($options['bcc']);
        }
        foreach ($attachments as $attachment) {
            $mail->attach($attachment);
        }

        $result = $mail->send();

        if (is_wp_error($result)) {
            ntdst_log('mail')->error('Email send failed', [
                'template' => $templateSlug,
                'to' => $to,
                'error' => $result->get_error_message(),
            ]);
        } else {
            ntdst_log('mail')->info('Email sent', [
                'template' => $templateSlug,
                'to' => $to,
                'subject' => $subject,
            ]);
            do_action('ndmail_after_send', $templateSlug, $context, $to);
        }

        return $result;
    }

    public function template(string $templateSlug): MailBuilder
    {
        return new MailBuilder($this, $templateSlug);
    }

    public function parseSmartCodes(string $content, array $context, string $templateSlug): string
    {
        return $this->smartCodeParser->parse($content, $context);
    }

    private function resolveUser(array $context): ?\WP_User
    {
        $userId = $context['user_id'] ?? null;
        if ($userId) {
            $user = get_userdata($userId);
            return $user ?: null;
        }
        return null;
    }

    private function buildContextFromTrigger(string $triggerKey, array $args, array $triggerConfig): array
    {
        $context = [];
        $contextKeys = $triggerConfig['context'] ?? [];

        // Map common WordPress hook args
        if ($triggerKey === 'user_register' && isset($args[0])) {
            $context['user_id'] = $args[0];
        } elseif ($triggerKey === 'retrieve_password' && isset($args[0])) {
            $user = get_user_by('login', $args[0]);
            $context['user_id'] = $user ? $user->ID : null;
        } elseif ($triggerKey === 'wp_login' && isset($args[1])) {
            $context['user_id'] = $args[1]->ID;
        }

        return $context;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit --filter=MailServiceTest
```

Expected: PASS

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/src/MailService.php tests/Unit/NetdustMail/
git commit -m "feat(netdust-mail): add MailService orchestrator"
```

---

### Task 1.3: Create MailTemplateCPT

**Files:**
- Create: `web/app/plugins/netdust-mail/src/MailTemplateCPT.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/NetdustMail/MailTemplateCPTTest.php
declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\MailTemplateCPT;
use PHPUnit\Framework\TestCase;

class MailTemplateCPTTest extends TestCase
{
    public function test_post_type_constant_is_defined(): void
    {
        $this->assertEquals('ndmail_template', MailTemplateCPT::POST_TYPE);
    }

    public function test_get_fields_returns_expected_structure(): void
    {
        $fields = MailTemplateCPT::getFields();

        $this->assertArrayHasKey('subject', $fields);
        $this->assertArrayHasKey('body', $fields);
        $this->assertArrayHasKey('category', $fields);
        $this->assertArrayHasKey('status', $fields);
        $this->assertArrayHasKey('trigger', $fields);
        $this->assertArrayHasKey('attachments', $fields);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit --filter=MailTemplateCPTTest
```

**Step 3: Write MailTemplateCPT**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

class MailTemplateCPT
{
    public const POST_TYPE = 'ndmail_template';

    public static function register(): void
    {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }

        ntdst_data()->register(self::POST_TYPE, [
            'meta_prefix' => '_ndmail_',
            'label' => __('Email Templates', 'netdust-mail'),
            'labels' => [
                'singular_name' => __('Email Template', 'netdust-mail'),
                'add_new' => __('New Template', 'netdust-mail'),
                'add_new_item' => __('Add New Email Template', 'netdust-mail'),
                'edit_item' => __('Edit Email Template', 'netdust-mail'),
                'view_item' => __('View Email Template', 'netdust-mail'),
                'search_items' => __('Search Templates', 'netdust-mail'),
                'not_found' => __('No templates found', 'netdust-mail'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false, // We add our own menu under Tools
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'fields' => self::getFields(),
            'auto_metabox' => true,
            'use_tabs' => true,
            'field_groups' => [
                'content' => [
                    'title' => __('Email Content', 'netdust-mail'),
                    'fields' => ['subject', 'body'],
                ],
                'settings' => [
                    'title' => __('Settings', 'netdust-mail'),
                    'fields' => ['category', 'status', 'trigger'],
                ],
                'attachments' => [
                    'title' => __('Attachments', 'netdust-mail'),
                    'fields' => ['attachments'],
                ],
            ],
        ]);
    }

    public static function getFields(): array
    {
        return [
            'subject' => [
                'type' => 'text',
                'label' => __('Subject Line', 'netdust-mail'),
                'description' => __('Supports SmartCodes like {{user.first_name}}', 'netdust-mail'),
                'required' => true,
            ],
            'body' => [
                'type' => 'html',
                'label' => __('Email Body', 'netdust-mail'),
                'description' => __('HTML content with SmartCode support', 'netdust-mail'),
                'required' => true,
            ],
            'category' => [
                'type' => 'select',
                'label' => __('Category', 'netdust-mail'),
                'options' => [
                    '' => __('— Select —', 'netdust-mail'),
                    'auth' => __('Authentication', 'netdust-mail'),
                    'notification' => __('Notification', 'netdust-mail'),
                    'transactional' => __('Transactional', 'netdust-mail'),
                    'marketing' => __('Marketing', 'netdust-mail'),
                ],
            ],
            'status' => [
                'type' => 'select',
                'label' => __('Status', 'netdust-mail'),
                'options' => [
                    'draft' => __('Draft', 'netdust-mail'),
                    'active' => __('Active', 'netdust-mail'),
                ],
                'default' => 'draft',
            ],
            'trigger' => [
                'type' => 'select',
                'label' => __('Auto-send Trigger', 'netdust-mail'),
                'description' => __('Automatically send when this WordPress action fires', 'netdust-mail'),
                'options' => self::getTriggerOptions(),
            ],
            'attachments' => [
                'type' => 'json',
                'label' => __('Attachments', 'netdust-mail'),
                'description' => __('JSON array of attachment definitions', 'netdust-mail'),
            ],
        ];
    }

    private static function getTriggerOptions(): array
    {
        $options = ['' => __('— None (manual only) —', 'netdust-mail')];

        $triggers = apply_filters('ndmail_triggers', []);
        foreach ($triggers as $key => $config) {
            $options[$key] = $config['label'] ?? $key;
        }

        return $options;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit --filter=MailTemplateCPTTest
```

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/src/MailTemplateCPT.php tests/Unit/NetdustMail/MailTemplateCPTTest.php
git commit -m "feat(netdust-mail): add MailTemplateCPT with Data Manager"
```

---

### Task 1.4: Create MailTemplateRepository

**Files:**
- Create: `web/app/plugins/netdust-mail/src/MailTemplateRepository.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/NetdustMail/MailTemplateRepositoryTest.php
declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\MailTemplateRepository;
use PHPUnit\Framework\TestCase;

class MailTemplateRepositoryTest extends TestCase
{
    public function test_get_post_type_returns_correct_value(): void
    {
        $repo = new MailTemplateRepository();
        $this->assertEquals('ndmail_template', $repo->getPostType());
    }
}
```

**Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit --filter=MailTemplateRepositoryTest
```

**Step 3: Write MailTemplateRepository**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

class MailTemplateRepository
{
    private const POST_TYPE = MailTemplateCPT::POST_TYPE;

    public function getPostType(): string
    {
        return self::POST_TYPE;
    }

    public function findBySlug(string $slug): ?\WP_Post
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $result = $model->where('post_name', $slug)
            ->where('post_status', 'publish')
            ->withMeta()
            ->first();

        if (!$result) {
            return null;
        }

        // Convert to WP_Post with fields
        $post = get_post($result->ID);
        if ($post) {
            $post->fields = (array) ($result->fields ?? []);
            $post->meta = (array) ($result->meta ?? []);
        }

        return $post;
    }

    public function findById(int $id): ?\WP_Post
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $result = $model->find($id);

        if (is_wp_error($result)) {
            return null;
        }

        return $result;
    }

    public function findWithTriggers(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $results = $model->where('post_status', 'publish')
            ->whereNot('trigger', '')
            ->withMeta()
            ->get();

        $posts = [];
        foreach ($results as $result) {
            $post = get_post($result['ID']);
            if ($post) {
                $post->fields = $result['fields'] ?? [];
                $posts[] = $post;
            }
        }

        return $posts;
    }

    public function findByCategory(string $category): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $results = $model->where('post_status', 'publish')
            ->where('category', $category)
            ->withMeta()
            ->orderBy('post_title', 'ASC')
            ->get();

        $posts = [];
        foreach ($results as $result) {
            $post = get_post($result['ID']);
            if ($post) {
                $post->fields = $result['fields'] ?? [];
                $posts[] = $post;
            }
        }

        return $posts;
    }

    public function findAll(): array
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        $results = $model->withMeta()
            ->orderBy('post_title', 'ASC')
            ->get();

        $posts = [];
        foreach ($results as $result) {
            $post = get_post($result['ID']);
            if ($post) {
                $post->fields = $result['fields'] ?? [];
                $posts[] = $post;
            }
        }

        return $posts;
    }

    public function create(array $data): \WP_Post|\WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->create(array_merge([
            'post_status' => 'publish',
        ], $data));
    }

    public function update(int $id, array $data): \WP_Post|\WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->update($id, $data);
    }

    public function delete(int $id, bool $force = false): bool|\WP_Error
    {
        $model = ntdst_data()->get(self::POST_TYPE);
        return $model->delete($id, $force);
    }
}
```

**Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit --filter=MailTemplateRepositoryTest
```

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/src/MailTemplateRepository.php tests/Unit/NetdustMail/MailTemplateRepositoryTest.php
git commit -m "feat(netdust-mail): add MailTemplateRepository"
```

---

**Phase 1 Integration Gate:** Verify plugin activates, CPT registers, MailService instantiates without error. Run `ddev exec wp plugin activate netdust-mail` and check for PHP errors in debug.log.

---

## Phase 2: SmartCode System

### Task 2.1: Create SmartCodeRegistry

**Files:**
- Create: `web/app/plugins/netdust-mail/src/SmartCodeRegistry.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/NetdustMail/SmartCodeRegistryTest.php
declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\SmartCodeRegistry;
use PHPUnit\Framework\TestCase;

class SmartCodeRegistryTest extends TestCase
{
    public function test_get_all_returns_filtered_codes(): void
    {
        $registry = new SmartCodeRegistry();
        $codes = $registry->getAll();

        $this->assertIsArray($codes);
    }

    public function test_get_callback_returns_callable_for_registered_code(): void
    {
        $registry = new SmartCodeRegistry();

        // Mock the filter
        add_filter('ndmail_smartcodes', function ($codes) {
            $codes['test'] = [
                'label' => 'Test',
                'codes' => [
                    'value' => [
                        'label' => 'Test Value',
                        'callback' => fn($ctx) => 'test_result',
                    ],
                ],
            ];
            return $codes;
        });

        $callback = $registry->getCallback('test', 'value');
        $this->assertIsCallable($callback);
        $this->assertEquals('test_result', $callback([]));
    }

    public function test_get_callback_returns_null_for_unknown_code(): void
    {
        $registry = new SmartCodeRegistry();
        $callback = $registry->getCallback('unknown', 'field');

        $this->assertNull($callback);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit --filter=SmartCodeRegistryTest
```

**Step 3: Write SmartCodeRegistry**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

class SmartCodeRegistry
{
    private ?array $codes = null;

    public function getAll(): array
    {
        if ($this->codes === null) {
            $this->codes = apply_filters('ndmail_smartcodes', []);
        }
        return $this->codes;
    }

    public function getCallback(string $category, string $field): ?callable
    {
        $codes = $this->getAll();

        if (!isset($codes[$category]['codes'][$field]['callback'])) {
            return null;
        }

        $callback = $codes[$category]['codes'][$field]['callback'];

        return is_callable($callback) ? $callback : null;
    }

    public function getCategories(): array
    {
        $codes = $this->getAll();
        $categories = [];

        foreach ($codes as $key => $config) {
            $categories[$key] = $config['label'] ?? $key;
        }

        return $categories;
    }

    public function getCodesForCategory(string $category): array
    {
        $codes = $this->getAll();

        if (!isset($codes[$category]['codes'])) {
            return [];
        }

        $result = [];
        foreach ($codes[$category]['codes'] as $field => $config) {
            $result[$field] = $config['label'] ?? $field;
        }

        return $result;
    }

    public function getAllFlat(): array
    {
        $codes = $this->getAll();
        $flat = [];

        foreach ($codes as $category => $categoryConfig) {
            foreach (($categoryConfig['codes'] ?? []) as $field => $fieldConfig) {
                $flat[] = [
                    'code' => "{{$category}.{$field}}",
                    'category' => $categoryConfig['label'] ?? $category,
                    'label' => $fieldConfig['label'] ?? $field,
                ];
            }
        }

        return $flat;
    }

    public function refresh(): void
    {
        $this->codes = null;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit --filter=SmartCodeRegistryTest
```

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/src/SmartCodeRegistry.php tests/Unit/NetdustMail/SmartCodeRegistryTest.php
git commit -m "feat(netdust-mail): add SmartCodeRegistry"
```

---

### Task 2.2: Create SmartCodeParser

**Files:**
- Create: `web/app/plugins/netdust-mail/src/SmartCodeParser.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/NetdustMail/SmartCodeParserTest.php
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
        $this->registry = $this->createMock(SmartCodeRegistry::class);
        $this->parser = new SmartCodeParser($this->registry);
    }

    public function test_parse_replaces_known_smartcode(): void
    {
        $this->registry->method('getCallback')
            ->with('user', 'first_name')
            ->willReturn(fn($ctx) => 'John');

        $result = $this->parser->parse('Hello {{user.first_name}}!', ['user_id' => 1]);

        $this->assertEquals('Hello John!', $result);
    }

    public function test_parse_leaves_unknown_smartcode_intact(): void
    {
        $this->registry->method('getCallback')
            ->willReturn(null);

        $result = $this->parser->parse('Hello {{unknown.field}}!', []);

        $this->assertEquals('Hello {{unknown.field}}!', $result);
    }

    public function test_parse_uses_default_value_when_callback_returns_null(): void
    {
        $this->registry->method('getCallback')
            ->willReturn(fn($ctx) => null);

        $result = $this->parser->parse('Hello {{user.name|Guest}}!', []);

        $this->assertEquals('Hello Guest!', $result);
    }

    public function test_find_unparsed_returns_remaining_codes(): void
    {
        $result = $this->parser->findUnparsed('Hello {{user.name}} and {{unknown.field}}!');

        $this->assertCount(2, $result);
        $this->assertContains('user.name', $result);
        $this->assertContains('unknown.field', $result);
    }

    public function test_find_unparsed_returns_empty_for_clean_text(): void
    {
        $result = $this->parser->findUnparsed('Hello John!');

        $this->assertEmpty($result);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit --filter=SmartCodeParserTest
```

**Step 3: Write SmartCodeParser**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

class SmartCodeParser
{
    private const PATTERN = '/\{\{([a-z_]+)\.([a-z_]+)(?:\|([^}]*))?\}\}/i';

    public function __construct(
        private readonly SmartCodeRegistry $registry,
    ) {}

    public function parse(string $content, array $context): string
    {
        return preg_replace_callback(
            self::PATTERN,
            function ($matches) use ($context) {
                $category = $matches[1];
                $field = $matches[2];
                $default = $matches[3] ?? '';

                $callback = $this->registry->getCallback($category, $field);

                if ($callback === null) {
                    // Unknown code - leave intact for validation
                    return $matches[0];
                }

                $value = $callback($context);

                if ($value === null || $value === '') {
                    return $default;
                }

                return (string) $value;
            },
            $content
        );
    }

    public function findUnparsed(string $content): array
    {
        preg_match_all(self::PATTERN, $content, $matches);

        $codes = [];
        foreach ($matches[0] as $i => $match) {
            $codes[] = $matches[1][$i] . '.' . $matches[2][$i];
        }

        return array_unique($codes);
    }

    public function extractCodes(string $content): array
    {
        preg_match_all(self::PATTERN, $content, $matches, PREG_SET_ORDER);

        $codes = [];
        foreach ($matches as $match) {
            $codes[] = [
                'full' => $match[0],
                'category' => $match[1],
                'field' => $match[2],
                'default' => $match[3] ?? null,
            ];
        }

        return $codes;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit --filter=SmartCodeParserTest
```

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/src/SmartCodeParser.php tests/Unit/NetdustMail/SmartCodeParserTest.php
git commit -m "feat(netdust-mail): add SmartCodeParser with validation"
```

---

**Phase 2 Integration Gate:** Test SmartCode parsing end-to-end. Create a template with `{{site.name}}` and `{{user.first_name}}`, call `ndmail_send()` with valid context, verify parsed output in mail log.

---

## Phase 3: Trigger System

### Task 3.1: Create TriggerRegistry

**Files:**
- Create: `web/app/plugins/netdust-mail/src/TriggerRegistry.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/NetdustMail/TriggerRegistryTest.php
declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\TriggerRegistry;
use PHPUnit\Framework\TestCase;

class TriggerRegistryTest extends TestCase
{
    public function test_get_all_returns_filtered_triggers(): void
    {
        $registry = new TriggerRegistry();
        $triggers = $registry->getAll();

        $this->assertIsArray($triggers);
    }

    public function test_get_returns_specific_trigger(): void
    {
        add_filter('ndmail_triggers', function ($triggers) {
            $triggers['test_action'] = [
                'label' => 'Test Action',
                'context' => ['user_id'],
            ];
            return $triggers;
        });

        $registry = new TriggerRegistry();
        $trigger = $registry->get('test_action');

        $this->assertNotNull($trigger);
        $this->assertEquals('Test Action', $trigger['label']);
    }

    public function test_get_context_keys_returns_expected_keys(): void
    {
        add_filter('ndmail_triggers', function ($triggers) {
            $triggers['test_action'] = [
                'label' => 'Test Action',
                'context' => ['user_id', 'order_id'],
            ];
            return $triggers;
        });

        $registry = new TriggerRegistry();
        $keys = $registry->getContextKeys('test_action');

        $this->assertEquals(['user_id', 'order_id'], $keys);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit --filter=TriggerRegistryTest
```

**Step 3: Write TriggerRegistry**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

class TriggerRegistry
{
    private ?array $triggers = null;

    public function getAll(): array
    {
        if ($this->triggers === null) {
            $this->triggers = apply_filters('ndmail_triggers', []);
        }
        return $this->triggers;
    }

    public function get(string $key): ?array
    {
        $triggers = $this->getAll();
        return $triggers[$key] ?? null;
    }

    public function getContextKeys(string $key): array
    {
        $trigger = $this->get($key);
        return $trigger['context'] ?? [];
    }

    public function getOptions(): array
    {
        $triggers = $this->getAll();
        $options = ['' => __('— None (manual only) —', 'netdust-mail')];

        foreach ($triggers as $key => $config) {
            $options[$key] = $config['label'] ?? $key;
        }

        return $options;
    }

    public function getGroupedOptions(): array
    {
        $triggers = $this->getAll();
        $grouped = [];

        foreach ($triggers as $key => $config) {
            $source = $config['source'] ?? __('Core', 'netdust-mail');
            if (!isset($grouped[$source])) {
                $grouped[$source] = [];
            }
            $grouped[$source][$key] = $config['label'] ?? $key;
        }

        return $grouped;
    }

    public function refresh(): void
    {
        $this->triggers = null;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit --filter=TriggerRegistryTest
```

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/src/TriggerRegistry.php tests/Unit/NetdustMail/TriggerRegistryTest.php
git commit -m "feat(netdust-mail): add TriggerRegistry"
```

---

**Phase 3 Integration Gate:** Create a template with `user_register` trigger, register a test user via `wp_create_user()`, verify the email was dispatched.

---

## Phase 4: Attachment System

### Task 4.1: Create AttachmentHandler

**Files:**
- Create: `web/app/plugins/netdust-mail/src/AttachmentHandler.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/NetdustMail/AttachmentHandlerTest.php
declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\AttachmentHandler;
use PHPUnit\Framework\TestCase;

class AttachmentHandlerTest extends TestCase
{
    public function test_resolve_returns_empty_array_for_empty_input(): void
    {
        $handler = new AttachmentHandler();
        $result = $handler->resolve([], []);

        $this->assertEquals([], $result);
    }

    public function test_resolve_returns_error_for_missing_media_file(): void
    {
        $handler = new AttachmentHandler();
        $attachments = [['type' => 'media', 'id' => 999999]];

        $result = $handler->resolve($attachments, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_resolve_returns_error_for_missing_pdf_context(): void
    {
        add_filter('ndmail_pdf_generators', function ($generators) {
            $generators['test_pdf'] = [
                'label' => 'Test PDF',
                'callback' => fn($id) => '/tmp/test.pdf',
                'context_key' => 'test_id',
            ];
            return $generators;
        });

        $handler = new AttachmentHandler();
        $attachments = [['type' => 'pdf', 'generator' => 'test_pdf']];

        $result = $handler->resolve($attachments, []); // Missing test_id

        $this->assertInstanceOf(\WP_Error::class, $result);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit --filter=AttachmentHandlerTest
```

**Step 3: Write AttachmentHandler**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

class AttachmentHandler
{
    private ?array $pdfGenerators = null;

    public function resolve(array $attachments, array $context): array|\WP_Error
    {
        if (empty($attachments)) {
            return [];
        }

        // Handle JSON string
        if (is_string($attachments)) {
            $attachments = json_decode($attachments, true) ?: [];
        }

        $files = [];

        foreach ($attachments as $attachment) {
            $type = $attachment['type'] ?? null;

            if ($type === 'media') {
                $result = $this->resolveMedia($attachment);
            } elseif ($type === 'pdf') {
                $result = $this->resolvePdf($attachment, $context);
            } else {
                continue; // Skip unknown types
            }

            if (is_wp_error($result)) {
                return $result;
            }

            if ($result) {
                $files[] = $result;
            }
        }

        return $files;
    }

    private function resolveMedia(array $attachment): string|\WP_Error
    {
        $id = $attachment['id'] ?? null;
        if (!$id) {
            return new \WP_Error('ndmail_invalid_attachment', 'Media attachment missing ID');
        }

        $path = get_attached_file($id);
        if (!$path || !file_exists($path)) {
            return new \WP_Error(
                'ndmail_media_not_found',
                sprintf('Media file not found for attachment ID %d', $id)
            );
        }

        return $path;
    }

    private function resolvePdf(array $attachment, array $context): string|\WP_Error
    {
        $generatorKey = $attachment['generator'] ?? null;
        if (!$generatorKey) {
            return new \WP_Error('ndmail_invalid_attachment', 'PDF attachment missing generator');
        }

        $generators = $this->getPdfGenerators();
        if (!isset($generators[$generatorKey])) {
            return new \WP_Error(
                'ndmail_unknown_generator',
                sprintf('Unknown PDF generator: %s', $generatorKey)
            );
        }

        $generator = $generators[$generatorKey];
        $contextKey = $generator['context_key'] ?? null;

        if ($contextKey && !isset($context[$contextKey])) {
            return new \WP_Error(
                'ndmail_missing_pdf_context',
                sprintf('PDF generator "%s" requires context key "%s"', $generatorKey, $contextKey)
            );
        }

        $contextId = $contextKey ? $context[$contextKey] : null;
        $callback = $generator['callback'] ?? null;

        if (!is_callable($callback)) {
            return new \WP_Error(
                'ndmail_invalid_generator',
                sprintf('PDF generator "%s" has invalid callback', $generatorKey)
            );
        }

        try {
            $path = $callback($contextId);

            if (!$path || !file_exists($path)) {
                return new \WP_Error(
                    'ndmail_pdf_generation_failed',
                    sprintf('PDF generator "%s" did not produce a valid file', $generatorKey)
                );
            }

            return $path;
        } catch (\Throwable $e) {
            return new \WP_Error(
                'ndmail_pdf_generation_error',
                sprintf('PDF generation failed: %s', $e->getMessage())
            );
        }
    }

    private function getPdfGenerators(): array
    {
        if ($this->pdfGenerators === null) {
            $this->pdfGenerators = apply_filters('ndmail_pdf_generators', []);
        }
        return $this->pdfGenerators;
    }

    public function getAvailableGenerators(): array
    {
        $generators = $this->getPdfGenerators();
        $options = [];

        foreach ($generators as $key => $config) {
            $options[$key] = [
                'label' => $config['label'] ?? $key,
                'context_key' => $config['context_key'] ?? null,
            ];
        }

        return $options;
    }
}
```

**Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit --filter=AttachmentHandlerTest
```

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/src/AttachmentHandler.php tests/Unit/NetdustMail/AttachmentHandlerTest.php
git commit -m "feat(netdust-mail): add AttachmentHandler for media and PDF"
```

---

**Phase 4 Integration Gate:** Register a mock PDF generator, create a template with that attachment, send with valid context, verify attachment is included in email.

---

## Phase 5: Fluent Builder API

### Task 5.1: Create MailBuilder

**Files:**
- Create: `web/app/plugins/netdust-mail/src/MailBuilder.php`

**Step 1: Write the failing test**

```php
<?php
// tests/Unit/NetdustMail/MailBuilderTest.php
declare(strict_types=1);

namespace Tests\Unit\NetdustMail;

use Netdust\Mail\MailBuilder;
use Netdust\Mail\MailService;
use PHPUnit\Framework\TestCase;

class MailBuilderTest extends TestCase
{
    public function test_fluent_interface_returns_self(): void
    {
        $service = $this->createMock(MailService::class);
        $builder = new MailBuilder($service, 'test-template');

        $this->assertSame($builder, $builder->context(['user_id' => 1]));
        $this->assertSame($builder, $builder->to('test@example.com'));
        $this->assertSame($builder, $builder->cc('cc@example.com'));
        $this->assertSame($builder, $builder->bcc('bcc@example.com'));
        $this->assertSame($builder, $builder->attach('/path/to/file'));
    }

    public function test_send_calls_service_with_collected_options(): void
    {
        $service = $this->createMock(MailService::class);
        $service->expects($this->once())
            ->method('send')
            ->with(
                'test-template',
                ['user_id' => 1],
                $this->callback(function ($options) {
                    return $options['to'] === 'test@example.com'
                        && $options['cc'] === 'cc@example.com';
                })
            )
            ->willReturn(true);

        $builder = new MailBuilder($service, 'test-template');
        $result = $builder
            ->context(['user_id' => 1])
            ->to('test@example.com')
            ->cc('cc@example.com')
            ->send();

        $this->assertTrue($result);
    }
}
```

**Step 2: Run test to verify it fails**

```bash
ddev exec vendor/bin/phpunit --filter=MailBuilderTest
```

**Step 3: Write MailBuilder**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

class MailBuilder
{
    private array $context = [];
    private array $options = [];
    private array $extraAttachments = [];

    public function __construct(
        private readonly MailService $service,
        private readonly string $templateSlug,
    ) {}

    public function context(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    public function to(string $email): self
    {
        $this->options['to'] = $email;
        return $this;
    }

    public function cc(string $email): self
    {
        $this->options['cc'] = $email;
        return $this;
    }

    public function bcc(string $email): self
    {
        $this->options['bcc'] = $email;
        return $this;
    }

    public function attach(string $filePath): self
    {
        $this->extraAttachments[] = $filePath;
        return $this;
    }

    public function send(): bool|\WP_Error
    {
        if (!empty($this->extraAttachments)) {
            $this->options['extra_attachments'] = $this->extraAttachments;
        }

        return $this->service->send($this->templateSlug, $this->context, $this->options);
    }
}
```

**Step 4: Run test to verify it passes**

```bash
ddev exec vendor/bin/phpunit --filter=MailBuilderTest
```

**Step 5: Commit**

```bash
git add web/app/plugins/netdust-mail/src/MailBuilder.php tests/Unit/NetdustMail/MailBuilderTest.php
git commit -m "feat(netdust-mail): add fluent MailBuilder API"
```

---

**Phase 5 Integration Gate:** Test `ndmail_template('slug')->context([...])->to('email')->send()` flow works end-to-end.

---

## Phase 6: Admin UI

### Task 6.1: Create AdminController

**Files:**
- Create: `web/app/plugins/netdust-mail/src/Admin/AdminController.php`

**Step 1: Write AdminController**

```php
<?php
declare(strict_types=1);

namespace Netdust\Mail\Admin;

use Netdust\Mail\MailTemplateCPT;
use Netdust\Mail\SmartCodeRegistry;
use Netdust\Mail\TriggerRegistry;
use Netdust\Mail\AttachmentHandler;

defined('ABSPATH') || exit;

class AdminController
{
    public function __construct(
        private readonly SmartCodeRegistry $smartCodeRegistry,
        private readonly TriggerRegistry $triggerRegistry,
        private readonly AttachmentHandler $attachmentHandler,
    ) {
        $this->init();
    }

    private function init(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('add_meta_boxes', [$this, 'addSmartCodeMetaBox']);
    }

    public function registerMenu(): void
    {
        // Add submenu under Tools
        add_submenu_page(
            'tools.php',
            __('Email Templates', 'netdust-mail'),
            __('Email Templates', 'netdust-mail'),
            'manage_options',
            'edit.php?post_type=' . MailTemplateCPT::POST_TYPE
        );

        // Add Settings page
        add_submenu_page(
            'tools.php',
            __('Email Settings', 'netdust-mail'),
            __('Email Settings', 'netdust-mail'),
            'manage_options',
            'ndmail-settings',
            [$this, 'renderSettingsPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        global $post_type;

        if ($post_type !== MailTemplateCPT::POST_TYPE) {
            return;
        }

        wp_enqueue_style(
            'ndmail-admin',
            NDMAIL_URL . 'assets/css/admin.css',
            [],
            NDMAIL_VERSION
        );

        wp_enqueue_script(
            'ndmail-admin',
            NDMAIL_URL . 'assets/js/admin.js',
            ['jquery'],
            NDMAIL_VERSION,
            true
        );

        wp_localize_script('ndmail-admin', 'ndmailAdmin', [
            'smartcodes' => $this->smartCodeRegistry->getAllFlat(),
            'triggers' => $this->triggerRegistry->getAll(),
            'pdfGenerators' => $this->attachmentHandler->getAvailableGenerators(),
            'i18n' => [
                'insertSmartCode' => __('Insert SmartCode', 'netdust-mail'),
                'selectCategory' => __('Select category...', 'netdust-mail'),
            ],
        ]);
    }

    public function addSmartCodeMetaBox(): void
    {
        add_meta_box(
            'ndmail-smartcode-reference',
            __('SmartCode Reference', 'netdust-mail'),
            [$this, 'renderSmartCodeMetaBox'],
            MailTemplateCPT::POST_TYPE,
            'side',
            'default'
        );
    }

    public function renderSmartCodeMetaBox(): void
    {
        $codes = $this->smartCodeRegistry->getAll();

        echo '<div class="ndmail-smartcode-list">';

        foreach ($codes as $category => $config) {
            echo '<details>';
            echo '<summary><strong>' . esc_html($config['label'] ?? $category) . '</strong></summary>';
            echo '<ul>';

            foreach (($config['codes'] ?? []) as $field => $fieldConfig) {
                $code = "{{$category}.{$field}}";
                echo '<li>';
                echo '<code class="ndmail-insertable" data-code="' . esc_attr($code) . '">';
                echo esc_html($code);
                echo '</code>';
                echo ' <small>' . esc_html($fieldConfig['label'] ?? '') . '</small>';
                echo '</li>';
            }

            echo '</ul>';
            echo '</details>';
        }

        echo '</div>';

        echo '<p class="description">';
        echo esc_html__('Click a code to copy. Use |default for fallback values.', 'netdust-mail');
        echo '</p>';
    }

    public function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle form submission
        if (isset($_POST['ndmail_settings_nonce']) && wp_verify_nonce($_POST['ndmail_settings_nonce'], 'ndmail_settings')) {
            update_option('ndmail_from_name', sanitize_text_field($_POST['ndmail_from_name'] ?? ''));
            update_option('ndmail_from_email', sanitize_email($_POST['ndmail_from_email'] ?? ''));

            echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'netdust-mail') . '</p></div>';
        }

        $fromName = get_option('ndmail_from_name', '');
        $fromEmail = get_option('ndmail_from_email', '');

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Email Settings', 'netdust-mail'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('ndmail_settings', 'ndmail_settings_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="ndmail_from_name"><?php echo esc_html__('Default From Name', 'netdust-mail'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="ndmail_from_name" name="ndmail_from_name"
                                   value="<?php echo esc_attr($fromName); ?>" class="regular-text">
                            <p class="description"><?php echo esc_html__('Leave empty to use WordPress default.', 'netdust-mail'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="ndmail_from_email"><?php echo esc_html__('Default From Email', 'netdust-mail'); ?></label>
                        </th>
                        <td>
                            <input type="email" id="ndmail_from_email" name="ndmail_from_email"
                                   value="<?php echo esc_attr($fromEmail); ?>" class="regular-text">
                            <p class="description"><?php echo esc_html__('Leave empty to use WordPress default.', 'netdust-mail'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php echo esc_html__('Registered Triggers', 'netdust-mail'); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Action', 'netdust-mail'); ?></th>
                        <th><?php echo esc_html__('Label', 'netdust-mail'); ?></th>
                        <th><?php echo esc_html__('Context', 'netdust-mail'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->triggerRegistry->getAll() as $key => $config): ?>
                    <tr>
                        <td><code><?php echo esc_html($key); ?></code></td>
                        <td><?php echo esc_html($config['label'] ?? $key); ?></td>
                        <td><?php echo esc_html(implode(', ', $config['context'] ?? [])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <hr>

            <h2><?php echo esc_html__('Registered PDF Generators', 'netdust-mail'); ?></h2>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Key', 'netdust-mail'); ?></th>
                        <th><?php echo esc_html__('Label', 'netdust-mail'); ?></th>
                        <th><?php echo esc_html__('Context Key', 'netdust-mail'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($this->attachmentHandler->getAvailableGenerators() as $key => $config): ?>
                    <tr>
                        <td><code><?php echo esc_html($key); ?></code></td>
                        <td><?php echo esc_html($config['label']); ?></td>
                        <td><?php echo esc_html($config['context_key'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
```

**Step 2: Register AdminController in bootstrap**

Update `netdust-mail.php` to register AdminController:

```php
ntdst_set(\Netdust\Mail\Admin\AdminController::class, function ($container) {
    return new \Netdust\Mail\Admin\AdminController(
        $container->get(\Netdust\Mail\SmartCodeRegistry::class),
        $container->get(\Netdust\Mail\TriggerRegistry::class),
        $container->get(\Netdust\Mail\AttachmentHandler::class)
    );
});
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-mail/src/Admin/
git commit -m "feat(netdust-mail): add AdminController with menu and settings"
```

**Unit test:** Verify admin menu appears under Tools when plugin is active.

---

### Task 6.2: Create Admin CSS and JS

**Files:**
- Create: `web/app/plugins/netdust-mail/assets/css/admin.css`
- Create: `web/app/plugins/netdust-mail/assets/js/admin.js`

**Step 1: Create admin.css**

```css
/* Netdust Mail Admin Styles */

.ndmail-smartcode-list {
    max-height: 300px;
    overflow-y: auto;
}

.ndmail-smartcode-list details {
    margin-bottom: 8px;
}

.ndmail-smartcode-list summary {
    cursor: pointer;
    padding: 4px 0;
}

.ndmail-smartcode-list ul {
    margin: 8px 0 0 16px;
    padding: 0;
    list-style: none;
}

.ndmail-smartcode-list li {
    margin-bottom: 4px;
}

.ndmail-insertable {
    cursor: pointer;
    background: #f0f0f1;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    transition: background-color 0.2s;
}

.ndmail-insertable:hover {
    background: #dcdcde;
}

.ndmail-insertable.copied {
    background: #00a32a;
    color: white;
}

/* Attachment builder */
.ndmail-attachment-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: #f6f7f7;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    margin-bottom: 8px;
}

.ndmail-attachment-item .type-badge {
    background: #2271b1;
    color: white;
    padding: 2px 6px;
    border-radius: 3px;
    font-size: 11px;
    text-transform: uppercase;
}

.ndmail-attachment-item .remove-attachment {
    margin-left: auto;
    color: #b32d2e;
    cursor: pointer;
}
```

**Step 2: Create admin.js**

```javascript
/* Netdust Mail Admin Scripts */
(function($) {
    'use strict';

    // SmartCode click-to-copy
    $(document).on('click', '.ndmail-insertable', function() {
        const code = $(this).data('code');
        const $el = $(this);

        // Copy to clipboard
        navigator.clipboard.writeText(code).then(function() {
            $el.addClass('copied');
            setTimeout(function() {
                $el.removeClass('copied');
            }, 1000);
        });
    });

    // SmartCode inserter for TinyMCE
    if (typeof tinymce !== 'undefined') {
        tinymce.PluginManager.add('ndmail_smartcode', function(editor) {
            editor.addButton('ndmail_smartcode', {
                text: ndmailAdmin.i18n.insertSmartCode,
                icon: false,
                type: 'menubutton',
                menu: buildSmartCodeMenu(editor)
            });
        });
    }

    function buildSmartCodeMenu(editor) {
        const menu = [];
        const codesByCategory = {};

        // Group codes by category
        ndmailAdmin.smartcodes.forEach(function(item) {
            if (!codesByCategory[item.category]) {
                codesByCategory[item.category] = [];
            }
            codesByCategory[item.category].push(item);
        });

        // Build menu structure
        Object.keys(codesByCategory).forEach(function(category) {
            menu.push({
                text: category,
                menu: codesByCategory[category].map(function(item) {
                    return {
                        text: item.label,
                        onclick: function() {
                            editor.insertContent(item.code);
                        }
                    };
                })
            });
        });

        return menu;
    }

    // Add TinyMCE button on editor init
    $(document).on('tinymce-editor-setup', function(event, editor) {
        if ($('body').hasClass('post-type-ndmail_template')) {
            editor.settings.toolbar1 += ',ndmail_smartcode';
        }
    });

})(jQuery);
```

**Step 3: Commit**

```bash
git add web/app/plugins/netdust-mail/assets/
git commit -m "feat(netdust-mail): add admin CSS and JS for SmartCode inserter"
```

---

### Task 6.3: Create Default Email Layout

**Files:**
- Create: `web/app/plugins/netdust-mail/templates/emails/layout.php`

**Step 1: Create layout.php**

```php
<?php
/**
 * Default Email Layout
 *
 * Variables available:
 * - $content - The parsed email body
 * - $subject - The email subject
 */
defined('ABSPATH') || exit;

$site_name = get_bloginfo('name');
$site_url = home_url();
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_locale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html($subject); ?></title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #f4f4f4;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            color: #333333;
        }
        .wrapper {
            width: 100%;
            table-layout: fixed;
            background-color: #f4f4f4;
            padding: 40px 0;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .header {
            background-color: #2271b1;
            color: #ffffff;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: 600;
        }
        .content {
            padding: 40px 30px;
        }
        .content h1, .content h2, .content h3 {
            color: #1d2327;
            margin-top: 0;
        }
        .content a {
            color: #2271b1;
        }
        .content .button {
            display: inline-block;
            background-color: #2271b1;
            color: #ffffff !important;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: 500;
            margin: 16px 0;
        }
        .content .button:hover {
            background-color: #135e96;
        }
        .footer {
            background-color: #f0f0f1;
            padding: 20px 30px;
            text-align: center;
            font-size: 13px;
            color: #646970;
        }
        .footer a {
            color: #646970;
        }
        @media only screen and (max-width: 600px) {
            .content {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <h1><?php echo esc_html($site_name); ?></h1>
            </div>
            <div class="content">
                <?php echo $content; // Already sanitized by wp_kses_post in body field ?>
            </div>
            <div class="footer">
                <p>
                    &copy; <?php echo esc_html($year); ?> <?php echo esc_html($site_name); ?><br>
                    <a href="<?php echo esc_url($site_url); ?>"><?php echo esc_html($site_url); ?></a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
```

**Step 2: Commit**

```bash
git add web/app/plugins/netdust-mail/templates/
git commit -m "feat(netdust-mail): add default email layout template"
```

---

**Phase 6 Integration Gate:**
- Verify Tools → Email Templates menu appears
- Create a new template via admin UI
- Verify SmartCode reference sidebar shows available codes
- Verify Settings page shows registered triggers and generators

---

## Phase 7: Final Integration & Testing

### Task 7.1: Integration Tests

**Files:**
- Create: `tests/Integration/NetdustMail/MailServiceIntegrationTest.php`

**Step 1: Write integration test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration\NetdustMail;

use Netdust\Mail\MailService;
use Netdust\Mail\MailTemplateCPT;

class MailServiceIntegrationTest extends \WP_UnitTestCase
{
    public function test_send_parses_smartcodes_and_sends_email(): void
    {
        // Create test user
        $userId = $this->factory->user->create([
            'user_email' => 'test@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        // Create template
        $templateId = wp_insert_post([
            'post_type' => MailTemplateCPT::POST_TYPE,
            'post_name' => 'test-template',
            'post_title' => 'Test Template',
            'post_status' => 'publish',
        ]);

        update_post_meta($templateId, '_ndmail_subject', 'Hello {{user.first_name}}!');
        update_post_meta($templateId, '_ndmail_body', '<p>Welcome, {{user.display_name}}!</p>');
        update_post_meta($templateId, '_ndmail_status', 'active');

        // Mock wp_mail
        $sentMail = null;
        add_filter('pre_wp_mail', function ($null, $atts) use (&$sentMail) {
            $sentMail = $atts;
            return true; // Prevent actual sending
        }, 10, 2);

        // Send
        $result = ndmail_send('test-template', ['user_id' => $userId]);

        $this->assertTrue($result);
        $this->assertNotNull($sentMail);
        $this->assertEquals('Hello John!', $sentMail['subject']);
        $this->assertStringContainsString('Welcome,', $sentMail['message']);
    }

    public function test_send_blocks_email_with_unparsed_smartcodes(): void
    {
        // Create template with unknown smartcode
        $templateId = wp_insert_post([
            'post_type' => MailTemplateCPT::POST_TYPE,
            'post_name' => 'broken-template',
            'post_title' => 'Broken Template',
            'post_status' => 'publish',
        ]);

        update_post_meta($templateId, '_ndmail_subject', 'Order {{order.number}}');
        update_post_meta($templateId, '_ndmail_body', '<p>Details</p>');
        update_post_meta($templateId, '_ndmail_status', 'active');

        // Send without order context
        $result = ndmail_send('broken-template', ['user_id' => 1]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('ndmail_unparsed_smartcodes', $result->get_error_code());
    }
}
```

**Step 2: Run integration tests**

```bash
ddev exec vendor/bin/phpunit --testsuite=Integration --filter=MailServiceIntegrationTest
```

**Step 3: Commit**

```bash
git add tests/Integration/NetdustMail/
git commit -m "test(netdust-mail): add integration tests"
```

---

### Task 7.2: Final Plugin Activation Test

**Step 1: Activate plugin and verify**

```bash
ddev exec wp plugin activate netdust-mail
ddev exec wp eval "echo class_exists('\Netdust\Mail\MailService') ? 'OK' : 'FAIL';"
```

Expected: `OK`

**Step 2: Create smoke test template**

```bash
ddev exec wp eval "
\$id = wp_insert_post([
    'post_type' => 'ndmail_template',
    'post_name' => 'smoke-test',
    'post_title' => 'Smoke Test',
    'post_status' => 'publish',
]);
update_post_meta(\$id, '_ndmail_subject', 'Test from {{site.name}}');
update_post_meta(\$id, '_ndmail_body', '<p>Hello {{user.first_name|there}}!</p>');
update_post_meta(\$id, '_ndmail_status', 'active');
echo 'Template ID: ' . \$id;
"
```

**Step 3: Commit final state**

```bash
git add -A
git commit -m "feat(netdust-mail): complete plugin implementation"
```

---

## Smoke Test Checklist

After all automated tests pass:

- [ ] Visit: `/wp-admin/tools.php?post_type=ndmail_template`
      Expected: Email Templates list renders, no PHP errors

- [ ] Create new template via admin
      Expected: Editor loads with SmartCode reference in sidebar

- [ ] Add SmartCodes to subject and body, save
      Expected: Template saves, SmartCodes visible in editor

- [ ] Visit: `/wp-admin/tools.php?page=ndmail-settings`
      Expected: Settings page renders with triggers and generators tables

- [ ] Send test email via WP-CLI:
      ```bash
      ddev exec wp eval "var_dump(ndmail_send('smoke-test', ['user_id' => 1]));"
      ```
      Expected: Returns `true`, check Mailpit for received email

- [ ] Console: DevTools > Console
      Expected: No JavaScript errors on template editor page
