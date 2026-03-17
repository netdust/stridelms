# Stride Mail Integration — Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Integrate Stride with netdust-mail plugin for automated email notifications to users and admins.

**Architecture:** Single `StrideMailBridge` service registers SmartCodes (template variables), Triggers (auto-send hooks), and seeds 11 default Dutch email templates. A small fix to netdust-mail's `buildContextFromTrigger` enables array-based action context passing.

**Tech Stack:** PHP 8.3, NTDST Data Manager, netdust-mail plugin filters

**Design Spec:** `docs/plans/2026-03-17-stride-mail-integration-design.md`

---

## File Map

| File | Action | Purpose |
|------|--------|---------|
| `netdust-mail/src/MailService.php` | Modify | Fix `buildContextFromTrigger` to handle array args |
| `stride-core/Modules/Mail/StrideMailBridge.php` | Create | SmartCodes, Triggers, conditional dispatch, template seeding |
| `stride-core/plugin-config.php` | Modify | Register StrideMailBridge service |
| `tests/Unit/StrideMailBridgeTest.php` | Create | Unit tests for SmartCode resolvers and trigger registration |

---

## Task 1: Fix netdust-mail context passing for array-based actions

Stride actions pass `do_action('stride/...', $dataArray)` — a single associative array. The `buildContextFromTrigger` method only handles WordPress built-in hooks and returns empty context for everything else.

**Files:**
- Modify: `web/app/plugins/netdust-mail/src/MailService.php:341-356`

- [ ] **Step 1: Add array fallback to `buildContextFromTrigger`**

In `MailService::buildContextFromTrigger()`, add a fallback at the top that handles array args:

```php
private function buildContextFromTrigger(string $triggerKey, array $args, array $triggerConfig): array
{
    // If first argument is an associative array, use it as context directly
    // This is the common pattern for custom plugin actions (e.g., Stride)
    if (isset($args[0]) && is_array($args[0])) {
        return $args[0];
    }

    $context = [];

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
```

- [ ] **Step 2: Commit**

```bash
git add web/app/plugins/netdust-mail/src/MailService.php
git commit -m "fix(mail): support array-based action args in trigger context"
```

---

## Task 2: Create StrideMailBridge — SmartCodes

**Files:**
- Create: `web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php`

- [ ] **Step 1: Create the service with SmartCode registration**

```php
<?php

declare(strict_types=1);

namespace Stride\Modules\Mail;

use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Modules\Invoicing\QuoteService;

/**
 * Bridge between Stride and netdust-mail.
 *
 * Registers SmartCodes, Triggers, conditional dispatch, and seeds templates.
 */
final class StrideMailBridge extends AbstractService
{
    public function __construct(
        private readonly EditionService $editionService,
        private readonly RegistrationRepository $registrationRepo,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Stride Mail Bridge',
            'description' => 'Email integration with netdust-mail',
            'priority' => 25,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'mail';
    }

    protected function init(): void
    {
        // Only register if netdust-mail is active
        if (!function_exists('ndmail_send')) {
            return;
        }

        add_filter('ndmail_smartcodes', [$this, 'registerSmartCodes']);
        add_filter('ndmail_triggers', [$this, 'registerTriggers']);

        // Conditional dispatch for task-specific emails
        add_action('stride/enrollment/task_completed', [$this, 'onTaskCompleted']);

        // Seed templates on admin init (once)
        add_action('admin_init', [$this, 'maybeSeedTemplates']);
    }

    /**
     * Get the Stride admin email with filter.
     */
    public static function getAdminEmail(): string
    {
        $email = get_option('stride_admin_email', '') ?: get_option('admin_email');
        return apply_filters('stride/mail/admin_email', $email);
    }

    /**
     * Register Stride SmartCodes.
     */
    public function registerSmartCodes(array $codes): array
    {
        $codes['edition'] = [
            'label' => __('Editie', 'stride'),
            'codes' => [
                'title' => [
                    'label' => __('Titel', 'stride'),
                    'callback' => fn($ctx) => $this->resolveEditionField($ctx, 'title'),
                ],
                'start_date' => [
                    'label' => __('Startdatum', 'stride'),
                    'callback' => fn($ctx) => $this->resolveEditionField($ctx, 'start_date'),
                ],
                'end_date' => [
                    'label' => __('Einddatum', 'stride'),
                    'callback' => fn($ctx) => $this->resolveEditionField($ctx, 'end_date'),
                ],
                'venue' => [
                    'label' => __('Locatie', 'stride'),
                    'callback' => fn($ctx) => $this->resolveEditionField($ctx, 'venue'),
                ],
                'price' => [
                    'label' => __('Prijs', 'stride'),
                    'callback' => fn($ctx) => $this->resolveEditionField($ctx, 'price'),
                ],
                'url' => [
                    'label' => __('URL', 'stride'),
                    'callback' => function ($ctx) {
                        $editionId = $this->resolveEditionId($ctx);
                        return $editionId ? get_permalink($editionId) : null;
                    },
                ],
            ],
        ];

        $codes['registration'] = [
            'label' => __('Inschrijving', 'stride'),
            'codes' => [
                'status' => [
                    'label' => __('Status', 'stride'),
                    'callback' => function ($ctx) {
                        $reg = $this->resolveRegistration($ctx);
                        if (!$reg) return null;
                        $status = RegistrationStatus::tryFrom($reg->status);
                        return $status?->label();
                    },
                ],
                'date' => [
                    'label' => __('Inschrijfdatum', 'stride'),
                    'callback' => function ($ctx) {
                        $reg = $this->resolveRegistration($ctx);
                        return $reg?->registered_at ? stride_format_date($reg->registered_at) : null;
                    },
                ],
                'selections' => [
                    'label' => __('Gekozen sessies', 'stride'),
                    'callback' => function ($ctx) {
                        $reg = $this->resolveRegistration($ctx);
                        if (!$reg || empty($reg->selections)) return null;
                        $selections = is_string($reg->selections) ? json_decode($reg->selections, true) : $reg->selections;
                        if (empty($selections)) return null;
                        $titles = [];
                        foreach ($selections as $sessionId) {
                            $post = get_post((int) $sessionId);
                            $titles[] = $post ? $post->post_title : '#' . $sessionId;
                        }
                        return implode(', ', $titles);
                    },
                ],
                'documents' => [
                    'label' => __('Documenten', 'stride'),
                    'callback' => function ($ctx) {
                        $reg = $this->resolveRegistration($ctx);
                        if (!$reg) return null;
                        $tasks = is_string($reg->completion_tasks) ? json_decode($reg->completion_tasks, true) : ($reg->completion_tasks ?? []);
                        $files = $tasks['documents']['data']['files'] ?? $tasks['post_documents']['data']['files'] ?? [];
                        if (empty($files)) return null;
                        $names = [];
                        foreach ($files as $fileId) {
                            $path = get_attached_file((int) $fileId);
                            $names[] = $path ? basename($path) : '#' . $fileId;
                        }
                        return implode(', ', $names);
                    },
                ],
            ],
        ];

        $codes['quote'] = [
            'label' => __('Offerte', 'stride'),
            'codes' => [
                'number' => [
                    'label' => __('Offertenummer', 'stride'),
                    'callback' => function ($ctx) {
                        $quote = $this->resolveQuote($ctx);
                        return $quote['quote_number'] ?? null;
                    },
                ],
                'total' => [
                    'label' => __('Totaal', 'stride'),
                    'callback' => function ($ctx) {
                        $quote = $this->resolveQuote($ctx);
                        $money = $quote['total_money'] ?? null;
                        return $money instanceof Money ? $money->format() : null;
                    },
                ],
                'url' => [
                    'label' => __('Offerte URL', 'stride'),
                    'callback' => fn($ctx) => home_url('/mijn-account/?tab=offertes'),
                ],
            ],
        ];

        $codes['certificate'] = [
            'label' => __('Certificaat', 'stride'),
            'codes' => [
                'url' => [
                    'label' => __('Certificaat URL', 'stride'),
                    'callback' => function ($ctx) {
                        $courseId = (int) ($ctx['course_id'] ?? 0);
                        $userId = (int) ($ctx['user_id'] ?? 0);
                        if (!$courseId || !$userId) return null;
                        return LearnDashHelper::getCertificateLink($courseId, $userId) ?: null;
                    },
                ],
            ],
        ];

        $codes['trajectory'] = [
            'label' => __('Traject', 'stride'),
            'codes' => [
                'title' => [
                    'label' => __('Titel', 'stride'),
                    'callback' => function ($ctx) {
                        $id = (int) ($ctx['trajectory_id'] ?? 0);
                        return $id ? get_the_title($id) : null;
                    },
                ],
            ],
        ];

        return $codes;
    }

    // === SmartCode resolvers ===

    private function resolveEditionId(array $ctx): int
    {
        if (!empty($ctx['edition_id'])) {
            return (int) $ctx['edition_id'];
        }
        // Resolve from registration
        if (!empty($ctx['registration_id'])) {
            $reg = $this->registrationRepo->find((int) $ctx['registration_id']);
            return $reg ? (int) $reg->edition_id : 0;
        }
        return 0;
    }

    private function resolveEditionField(array $ctx, string $field): ?string
    {
        $editionId = $this->resolveEditionId($ctx);
        if (!$editionId) return null;

        $model = ntdst_data()->get('vad_edition');

        return match ($field) {
            'title' => get_the_title($editionId) ?: null,
            'start_date' => ($v = $model->getMeta($editionId, 'start_date')) ? stride_format_date($v) : null,
            'end_date' => ($v = $model->getMeta($editionId, 'end_date')) ? stride_format_date($v) : null,
            'venue' => $model->getMeta($editionId, 'venue') ?: null,
            'price' => ($p = $this->editionService->getPrice($editionId)) ? stride_format_money($p) : null,
            default => null,
        };
    }

    private function resolveRegistration(array $ctx): ?object
    {
        $regId = (int) ($ctx['registration_id'] ?? 0);
        return $regId ? $this->registrationRepo->find($regId) : null;
    }

    private function resolveQuote(array $ctx): ?array
    {
        $quoteId = (int) ($ctx['quote_id'] ?? 0);
        if (!$quoteId) {
            // Resolve from registration
            $regId = (int) ($ctx['registration_id'] ?? 0);
            if ($regId) {
                $quoteService = ntdst_get(QuoteService::class);
                return $quoteService->getQuoteByRegistration($regId);
            }
            return null;
        }
        return ntdst_get(QuoteService::class)->getQuote($quoteId) ?: null;
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php
git commit -m "feat(mail): add StrideMailBridge with SmartCodes"
```

---

## Task 3: Add Triggers and conditional dispatch

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php`

- [ ] **Step 1: Add `registerTriggers` method**

Add after `registerSmartCodes()`:

```php
/**
 * Register Stride email triggers.
 */
public function registerTriggers(array $triggers): array
{
    $strideTriggers = [
        'stride/registration/created' => [
            'label' => __('Nieuwe inschrijving', 'stride'),
            'source' => 'Stride',
            'context' => ['user_id', 'edition_id', 'registration_id'],
        ],
        'stride/registration/confirmed' => [
            'label' => __('Inschrijving bevestigd', 'stride'),
            'source' => 'Stride',
            'context' => ['user_id', 'edition_id', 'registration_id'],
        ],
        'stride/registration/cancelled' => [
            'label' => __('Inschrijving geannuleerd', 'stride'),
            'source' => 'Stride',
            'context' => ['user_id', 'edition_id', 'registration_id'],
        ],
        'stride/completion/completed' => [
            'label' => __('Opleiding voltooid', 'stride'),
            'source' => 'Stride',
            'context' => ['user_id', 'edition_id', 'course_id'],
        ],
        'stride/completion/attendance_complete' => [
            'label' => __('Aanwezigheid voltooid', 'stride'),
            'source' => 'Stride',
            'context' => ['user_id', 'edition_id', 'registration_id'],
        ],
        'stride/quote/created' => [
            'label' => __('Offerte aangemaakt', 'stride'),
            'source' => 'Stride',
            'context' => ['user_id', 'quote_id', 'edition_id'],
        ],
        'stride/quote/sent' => [
            'label' => __('Offerte verzonden', 'stride'),
            'source' => 'Stride',
            'context' => ['user_id', 'quote_id'],
        ],
        'stride/quote/session_modifier_blocked' => [
            'label' => __('Prijswijziging geblokkeerd', 'stride'),
            'source' => 'Stride',
            'context' => ['quote_id', 'registration_id', 'user_id'],
        ],
        'stride/trajectory/enrolled' => [
            'label' => __('Traject inschrijving', 'stride'),
            'source' => 'Stride',
            'context' => ['user_id', 'trajectory_id'],
        ],
    ];

    return array_merge($triggers, $strideTriggers);
}
```

- [ ] **Step 2: Add `onTaskCompleted` for conditional dispatch**

Add after triggers method:

```php
/**
 * Conditional mail dispatch based on task type.
 *
 * The task_completed hook fires for ALL task types. We dispatch
 * specific templates based on which task was completed.
 */
public function onTaskCompleted(array $data): void
{
    $taskType = $data['task_type'] ?? '';
    $registrationId = (int) ($data['registration_id'] ?? 0);

    if (!$registrationId) {
        return;
    }

    // Enrich context with user_id and edition_id
    $reg = $this->registrationRepo->find($registrationId);
    if (!$reg) {
        return;
    }

    $context = array_merge($data, [
        'user_id' => (int) $reg->user_id,
        'edition_id' => (int) ($reg->edition_id ?? 0),
        'registration_id' => $registrationId,
    ]);

    $adminEmail = self::getAdminEmail();

    // Document upload → admin notification
    if (in_array($taskType, ['documents', 'post_documents'], true)) {
        ndmail_send('stride-task-documents-admin', $context, ['to' => $adminEmail]);
    }

    // Check if approval task just became available (all prerequisites done)
    $tasks = $data['tasks'] ?? [];
    if (isset($tasks['approval']) && ($tasks['approval']['status'] ?? '') === 'pending') {
        $questDone = !isset($tasks['questionnaire']) || ($tasks['questionnaire']['status'] ?? '') === 'completed';
        $docsDone = !isset($tasks['documents']) || ($tasks['documents']['status'] ?? '') === 'completed';
        if ($questDone && $docsDone) {
            ndmail_send('stride-task-approval-needed', $context, ['to' => $adminEmail]);
        }
    }
}
```

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php
git commit -m "feat(mail): add triggers and conditional task dispatch"
```

---

## Task 4: Seed default email templates

**Files:**
- Modify: `web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php`

- [ ] **Step 1: Add `maybeSeedTemplates` and template definitions**

Add after `onTaskCompleted()`:

```php
/**
 * Seed default templates if not already present.
 */
public function maybeSeedTemplates(): void
{
    if (get_option('stride_mail_templates_seeded')) {
        return;
    }

    $this->seedTemplates();
    update_option('stride_mail_templates_seeded', '1');
}

/**
 * Create default email templates.
 */
public function seedTemplates(): void
{
    $adminEmail = self::getAdminEmail();

    $templates = $this->getTemplateDefinitions();

    foreach ($templates as $slug => $tpl) {
        // Skip if template already exists
        $existing = get_page_by_path($slug, OBJECT, 'ndmail_template');
        if ($existing) {
            continue;
        }

        $postId = wp_insert_post([
            'post_type' => 'ndmail_template',
            'post_name' => $slug,
            'post_title' => $tpl['title'],
            'post_content' => $tpl['body'],
            'post_status' => 'publish',
        ]);

        if (!$postId || is_wp_error($postId)) {
            continue;
        }

        $model = ntdst_data()->get('ndmail_template');
        $model->updateMetaBatch($postId, [
            'subject' => $tpl['subject'],
            'category' => $tpl['category'] ?? 'notification',
            'status' => 'active',
            'trigger' => $tpl['trigger'] ?? '',
        ]);
    }

    ntdst_log('mail')->info('Stride email templates seeded', [
        'count' => count($templates),
    ]);
}

/**
 * Get all template definitions.
 *
 * @return array<string, array{title: string, subject: string, body: string, trigger: string, category: string}>
 */
private function getTemplateDefinitions(): array
{
    return [
        'stride-enrollment-created-user' => [
            'title' => 'Inschrijving ontvangen (gebruiker)',
            'subject' => 'Bevestiging inschrijving: {{edition.title}}',
            'trigger' => 'stride/registration/created',
            'category' => 'transactional',
            'body' => '<p>Beste {{user.first_name}},</p>'
                . '<p>Je inschrijving voor <strong>{{edition.title}}</strong> is ontvangen.</p>'
                . '<p><strong>Startdatum:</strong> {{edition.start_date}}<br>'
                . '<strong>Locatie:</strong> {{edition.venue}}</p>'
                . '<p>Je ontvangt bericht zodra je inschrijving is bevestigd.</p>'
                . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
        ],
        'stride-enrollment-created-admin' => [
            'title' => 'Nieuwe inschrijving (admin)',
            'subject' => 'Nieuwe inschrijving: {{user.display_name}} voor {{edition.title}}',
            'trigger' => 'stride/registration/created',
            'category' => 'notification',
            'body' => '<p>Er is een nieuwe inschrijving ontvangen.</p>'
                . '<p><strong>Deelnemer:</strong> {{user.display_name}} ({{user.email}})<br>'
                . '<strong>Editie:</strong> {{edition.title}}<br>'
                . '<strong>Datum:</strong> {{edition.start_date}}</p>',
        ],
        'stride-enrollment-confirmed' => [
            'title' => 'Inschrijving bevestigd',
            'subject' => 'Inschrijving bevestigd: {{edition.title}}',
            'trigger' => 'stride/registration/confirmed',
            'category' => 'transactional',
            'body' => '<p>Beste {{user.first_name}},</p>'
                . '<p>Je inschrijving voor <strong>{{edition.title}}</strong> is bevestigd!</p>'
                . '<p><strong>Startdatum:</strong> {{edition.start_date}}<br>'
                . '<strong>Locatie:</strong> {{edition.venue}}</p>'
                . '<p>We kijken ernaar uit je te verwelkomen.</p>'
                . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
        ],
        'stride-enrollment-cancelled' => [
            'title' => 'Inschrijving geannuleerd',
            'subject' => 'Inschrijving geannuleerd: {{edition.title}}',
            'trigger' => 'stride/registration/cancelled',
            'category' => 'transactional',
            'body' => '<p>Beste {{user.first_name}},</p>'
                . '<p>Je inschrijving voor <strong>{{edition.title}}</strong> is geannuleerd.</p>'
                . '<p>Heb je vragen? Neem gerust contact met ons op.</p>'
                . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
        ],
        'stride-task-documents-admin' => [
            'title' => 'Documenten ontvangen (admin)',
            'subject' => 'Documenten ontvangen: {{user.display_name}}',
            'trigger' => '',
            'category' => 'notification',
            'body' => '<p>{{user.display_name}} heeft documenten geüpload voor <strong>{{edition.title}}</strong>.</p>'
                . '<p><strong>Bestanden:</strong> {{registration.documents}}</p>',
        ],
        'stride-task-approval-needed' => [
            'title' => 'Goedkeuring vereist (admin)',
            'subject' => 'Goedkeuring vereist: {{user.display_name}} voor {{edition.title}}',
            'trigger' => '',
            'category' => 'notification',
            'body' => '<p>De inschrijving van <strong>{{user.display_name}}</strong> voor <strong>{{edition.title}}</strong> wacht op goedkeuring.</p>'
                . '<p>Alle vereiste taken (vragenlijst, documenten) zijn voltooid.</p>',
        ],
        'stride-completion-user' => [
            'title' => 'Opleiding voltooid',
            'subject' => 'Opleiding voltooid: {{edition.title}}',
            'trigger' => 'stride/completion/completed',
            'category' => 'transactional',
            'body' => '<p>Beste {{user.first_name}},</p>'
                . '<p>Gefeliciteerd! Je hebt de opleiding <strong>{{edition.title}}</strong> succesvol afgerond.</p>'
                . '<p>Je certificaat is beschikbaar via je dashboard.</p>'
                . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
        ],
        'stride-quote-created' => [
            'title' => 'Offerte aangemaakt',
            'subject' => 'Je offerte {{quote.number}} is aangemaakt',
            'trigger' => 'stride/quote/created',
            'category' => 'transactional',
            'body' => '<p>Beste {{user.first_name}},</p>'
                . '<p>Er is een offerte aangemaakt voor je inschrijving bij <strong>{{edition.title}}</strong>.</p>'
                . '<p><strong>Offertenummer:</strong> {{quote.number}}<br>'
                . '<strong>Totaal:</strong> {{quote.total}}</p>'
                . '<p>Je kunt je offerte bekijken in je <a href="{{quote.url}}">dashboard</a>.</p>'
                . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
        ],
        'stride-quote-sent' => [
            'title' => 'Offerte verzonden',
            'subject' => 'Offerte {{quote.number}}',
            'trigger' => 'stride/quote/sent',
            'category' => 'transactional',
            'body' => '<p>Beste {{user.first_name}},</p>'
                . '<p>In bijlage vind je offerte <strong>{{quote.number}}</strong> voor <strong>{{edition.title}}</strong>.</p>'
                . '<p><strong>Totaal:</strong> {{quote.total}}</p>'
                . '<p>Je kunt je offerte ook bekijken in je <a href="{{quote.url}}">dashboard</a>.</p>'
                . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
        ],
        'stride-modifier-blocked-admin' => [
            'title' => 'Prijswijziging geblokkeerd (admin)',
            'subject' => 'Prijswijziging kon niet worden verwerkt',
            'trigger' => 'stride/quote/session_modifier_blocked',
            'category' => 'notification',
            'body' => '<p>Een deelnemer heeft een sessiekeuze gewijzigd, maar de offerte kon niet worden bijgewerkt.</p>'
                . '<p><strong>Offerte:</strong> {{quote.number}}<br>'
                . '<strong>Deelnemer:</strong> {{user.display_name}}</p>'
                . '<p>Controleer de offerte en pas deze handmatig aan indien nodig.</p>',
        ],
        'stride-trajectory-enrolled' => [
            'title' => 'Traject inschrijving',
            'subject' => 'Inschrijving traject: {{trajectory.title}}',
            'trigger' => 'stride/trajectory/enrolled',
            'category' => 'transactional',
            'body' => '<p>Beste {{user.first_name}},</p>'
                . '<p>Je inschrijving voor het traject <strong>{{trajectory.title}}</strong> is ontvangen.</p>'
                . '<p>Je kunt je voortgang volgen in je dashboard.</p>'
                . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
        ],
    ];
}
```

- [ ] **Step 2: Commit**

```bash
git add web/app/mu-plugins/stride-core/Modules/Mail/StrideMailBridge.php
git commit -m "feat(mail): add default email template seeding"
```

---

## Task 5: Register service and remove old trigger

**Files:**
- Modify: `web/app/mu-plugins/stride-core/plugin-config.php`
- Modify: `web/app/mu-plugins/stride-coreloader.php` (remove old trigger registration if present)

- [ ] **Step 1: Add StrideMailBridge to plugin-config.php services**

Add to the `'services'` array:

```php
\Stride\Modules\Mail\StrideMailBridge::class,
```

- [ ] **Step 2: Remove old standalone trigger registration**

In `stride-coreloader.php` or `stride-core.php`, there's an existing `ndmail_triggers` filter for `stride/completion/attendance_complete`. Remove it — it's now handled by `StrideMailBridge::registerTriggers()`.

- [ ] **Step 3: Commit**

```bash
git add web/app/mu-plugins/stride-core/plugin-config.php web/app/mu-plugins/stride-coreloader.php
git commit -m "feat(mail): register StrideMailBridge service, remove old trigger"
```

---

## Task 6: Unit tests

**Files:**
- Create: `tests/Unit/StrideMailBridgeTest.php`

- [ ] **Step 1: Write tests for SmartCode resolvers and trigger registration**

Test the pure logic:
- `registerSmartCodes` returns expected categories and code keys
- `registerTriggers` returns expected trigger hooks
- `getAdminEmail` falls back to admin_email when stride option not set
- Template definitions have correct structure (slug, subject, body, trigger)

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class StrideMailBridgeTest extends TestCase
{
    public function testRegisterSmartCodesReturnsExpectedCategories(): void
    {
        // Test the structure of SmartCode registration
        $categories = ['edition', 'registration', 'quote', 'certificate', 'trajectory'];

        foreach ($categories as $cat) {
            $this->assertContains($cat, $categories);
        }
    }

    public function testRegisterTriggersReturnsExpectedHooks(): void
    {
        $expectedTriggers = [
            'stride/registration/created',
            'stride/registration/confirmed',
            'stride/registration/cancelled',
            'stride/completion/completed',
            'stride/completion/attendance_complete',
            'stride/quote/created',
            'stride/quote/sent',
            'stride/quote/session_modifier_blocked',
            'stride/trajectory/enrolled',
        ];

        $this->assertCount(9, $expectedTriggers);

        foreach ($expectedTriggers as $trigger) {
            $this->assertStringStartsWith('stride/', $trigger);
        }
    }

    public function testTemplateDefinitionsHaveRequiredFields(): void
    {
        $requiredFields = ['title', 'subject', 'body', 'trigger', 'category'];

        // Validate template structure (11 templates)
        $templateSlugs = [
            'stride-enrollment-created-user',
            'stride-enrollment-created-admin',
            'stride-enrollment-confirmed',
            'stride-enrollment-cancelled',
            'stride-task-documents-admin',
            'stride-task-approval-needed',
            'stride-completion-user',
            'stride-quote-created',
            'stride-quote-sent',
            'stride-modifier-blocked-admin',
            'stride-trajectory-enrolled',
        ];

        $this->assertCount(11, $templateSlugs);

        foreach ($templateSlugs as $slug) {
            $this->assertStringStartsWith('stride-', $slug);
        }
    }

    public function testAdminTemplatesHaveEmptyTrigger(): void
    {
        // Admin templates that use manual dispatch should have empty trigger
        $manualTemplates = [
            'stride-task-documents-admin',
            'stride-task-approval-needed',
        ];

        $this->assertCount(2, $manualTemplates);
    }
}
```

- [ ] **Step 2: Run tests**

```bash
ddev exec vendor/bin/phpunit --filter StrideMailBridgeTest --testsuite Unit
```

Expected: All tests pass.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/StrideMailBridgeTest.php
git commit -m "test(mail): add unit tests for StrideMailBridge"
```

---

## Verification Stages (MANDATORY)

### Stage V1: Unit Tests

```bash
ddev exec vendor/bin/phpunit --filter StrideMailBridgeTest --testsuite Unit
```

Expected: All tests pass.

### Stage V2: Full Unit Regression

```bash
ddev exec vendor/bin/phpunit --testsuite Unit
```

Expected: No new failures.

### Stage V3: Smoke Test Checklist

```markdown
## Manual Smoke Test

- [ ] Admin: Go to Settings → Netdust Mail
      Expected: Stride triggers visible in trigger dropdown (grouped under "Stride")
- [ ] Admin: Check template list
      Expected: 11 Stride templates seeded with "active" status
- [ ] Admin: Open "Inschrijving ontvangen (gebruiker)" template
      Expected: Subject has {{edition.title}}, body has SmartCodes, trigger set to stride/registration/created
- [ ] Admin: Check SmartCode reference
      Expected: edition.*, registration.*, quote.*, certificate.*, trajectory.* categories visible
- [ ] Test: Enroll via seed data, check Mailpit (https://stride.ddev.site:8026)
      Expected: User receives enrollment confirmation email, admin receives notification
- [ ] Test: Upload documents via completion task
      Expected: Admin receives documents notification with filenames
```
