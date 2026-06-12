<?php

declare(strict_types=1);

namespace Netdust\Mail;

defined('ABSPATH') || exit;

/**
 * Main email service orchestrator.
 *
 * Coordinates all email functionality:
 * - Template management via CPT
 * - SmartCode parsing in subjects and bodies
 * - Trigger-based automatic sending
 * - Attachment handling
 *
 * CRITICAL: Emails with unparsed SmartCodes are BLOCKED to prevent
 * sending emails with visible {{placeholders}} to users.
 */
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

    /**
     * Register the email template CPT.
     */
    public function registerCpt(): void
    {
        MailTemplateCPT::register();
    }

    /**
     * Register built-in SmartCodes for site, user, and date.
     *
     * @param array $codes Existing codes from filter.
     * @return array Extended codes array.
     */
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

    /**
     * Register built-in WordPress triggers.
     *
     * @param array $triggers Existing triggers from filter.
     * @return array Extended triggers array.
     */
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

    /**
     * Activate triggers for all templates that have them configured.
     */
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

    /**
     * Send an email using a template.
     *
     * @param string $templateSlug The template slug to use.
     * @param array  $context      SmartCode context data.
     * @param array  $options      Additional options (to, cc, bcc, attachments).
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
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
        // Body comes from post_content (WordPress editor), subject from meta
        $subject = $this->smartCodeParser->parse($template->fields['subject'] ?? '', $context);
        $body = $this->smartCodeParser->parse($template->post_content ?? '', $context);

        // CRITICAL: Block emails with unparsed SmartCodes
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
            ->message($body, true);

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

    /**
     * Get a fluent mail builder for a template.
     *
     * @param string $templateSlug The template slug.
     * @return MailBuilder
     */
    public function template(string $templateSlug): MailBuilder
    {
        return new MailBuilder($this, $templateSlug);
    }

    /**
     * Parse SmartCodes in content.
     *
     * This is the filter callback for 'ndmail_before_send'.
     *
     * @param string $content      The content to parse.
     * @param array  $context      Context data.
     * @param string $templateSlug The template slug (for logging).
     * @return string Parsed content.
     */
    public function parseSmartCodes(string $content, array $context, string $templateSlug): string
    {
        return $this->smartCodeParser->parse($content, $context);
    }

    /**
     * Resolve a WP_User from context.
     *
     * @param array $context Context data.
     * @return \WP_User|null
     */
    private function resolveUser(array $context): ?\WP_User
    {
        $userId = $context['user_id'] ?? null;
        if ($userId) {
            $user = get_userdata($userId);
            return $user ?: null;
        }
        return null;
    }

    /**
     * Build context array from trigger action arguments.
     *
     * @param string $triggerKey    The trigger key (action name).
     * @param array  $args          Arguments passed to the action.
     * @param array  $triggerConfig Trigger configuration.
     * @return array Context for SmartCode resolution.
     */
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
}
