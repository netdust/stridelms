<?php

declare(strict_types=1);

namespace Stride\Modules\Mail;

use Stride\Domain\Money;
use Stride\Domain\QuoteStatus;
use Stride\Domain\RegistrationStatus;
use Stride\Infrastructure\AbstractService;
use Stride\Integrations\LearnDash\LearnDashHelper;
use Stride\Modules\Edition\EditionRepository;
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
        private readonly EditionRepository $editionRepo,
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

        // Admin notification for new enrollments — scheduled out of the user's
        // request thread so SMTP latency / timeouts can't stall enrollment.
        add_action('stride/registration/created', [$this, 'scheduleAdminNotify']);
        add_action('stride/mail/admin_notify_async', [$this, 'onRegistrationCreatedAdminNotify']);

        // User confirmations for logged-in interest / waitlist submissions.
        // Anonymous submissions send their confirmation inline from QuestionnaireHandler.
        add_action('stride/registration/interest_registered', [$this, 'onInterestRegisteredUserMail']);
        add_action('stride/registration/waitlisted', [$this, 'onWaitlistRegisteredUserMail']);

        // Quote send email (triggered from admin)
        add_action('stride/quote/send_email', [$this, 'onQuoteSendEmail'], 10, 3);

        // Seed templates on admin init (once)
        add_action('admin_init', [$this, 'maybeSeedTemplates']);
    }

    /**
     * Schedule the admin notification to run on the next wp-cron tick.
     *
     * Synchronous PHPMailer sends inside `enroll()` add 200-1500ms per
     * recipient and fail the user's request on SMTP timeout. The cron-backed
     * single event hands the send off to a background pass.
     */
    public function scheduleAdminNotify(array $data): void
    {
        if (!wp_next_scheduled('stride/mail/admin_notify_async', [$data])) {
            wp_schedule_single_event(time() + 1, 'stride/mail/admin_notify_async', [$data]);
        }
    }

    /**
     * Send admin notification for new enrollments with explicit admin recipient.
     */
    public function onRegistrationCreatedAdminNotify(array $data): void
    {
        $adminEmail = self::getAdminEmail();
        if (!$adminEmail) {
            return;
        }

        ndmail_send('stride-enrollment-created-admin', $data, ['to' => $adminEmail]);
    }

    /**
     * Send user confirmation when a logged-in user registers interest.
     *
     * @param array{registration_id: int, user_id: int, edition_id?: int|null} $data
     */
    public function onInterestRegisteredUserMail(array $data): void
    {
        $this->sendUserStageMail('stride-interest-registered-user', $data);
    }

    /**
     * Send user confirmation when a logged-in user joins the waitlist.
     *
     * @param array{registration_id: int, user_id: int, edition_id?: int|null} $data
     */
    public function onWaitlistRegisteredUserMail(array $data): void
    {
        $this->sendUserStageMail('stride-waitlist-registered-user', $data);
    }

    /**
     * Shared helper for the two user-confirmation mails above.
     *
     * @param array{user_id: int, edition_id?: int|null} $data
     */
    private function sendUserStageMail(string $template, array $data): void
    {
        $userId = (int) ($data['user_id'] ?? 0);
        if (!$userId) {
            return;
        }
        $user = get_userdata($userId);
        if (!$user || !$user->user_email) {
            return;
        }

        $editionId = (int) ($data['edition_id'] ?? 0);
        $edition = $editionId ? get_post($editionId) : null;

        ndmail_send($template, [
            'registration' => [
                'name' => trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name,
                'email' => $user->user_email,
            ],
            'edition_id' => $editionId,
            'edition' => ['title' => $edition ? $edition->post_title : ''],
        ], ['to' => $user->user_email]);
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
                'name' => [
                    'label' => __('Naam', 'stride'),
                    'callback' => fn($ctx) => $ctx['name'] ?? null,
                ],
                'email' => [
                    'label' => __('E-mail', 'stride'),
                    'callback' => fn($ctx) => $ctx['email'] ?? null,
                ],
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

        $codes['completion'] = [
            'label' => __('Taken', 'stride'),
            'codes' => [
                'url' => [
                    'label' => __('Voltooien URL', 'stride'),
                    'callback' => function ($ctx) {
                        $editionId = $this->resolveEditionId($ctx);
                        if (!$editionId) return null;
                        $slug = get_post_field('post_name', $editionId);
                        return $slug ? home_url('/vormingen/' . $slug . '/voltooien/') : null;
                    },
                ],
                'summary' => [
                    'label' => __('Taken overzicht', 'stride'),
                    'callback' => function ($ctx) {
                        $regId = (int) ($ctx['registration_id'] ?? 0);
                        if (!$regId) return null;
                        $reg = $this->registrationRepo->find($regId);
                        if (!$reg || empty($reg->completion_tasks)) return null;
                        $tasks = is_string($reg->completion_tasks) ? json_decode($reg->completion_tasks, true) : $reg->completion_tasks;
                        if (empty($tasks)) return null;
                        $labels = [
                            'questionnaire' => 'Vragenlijst invullen',
                            'documents' => 'Documenten uploaden',
                            'session_selection' => 'Sessiekeuze maken',
                            'approval' => 'Goedkeuring beheerder',
                        ];
                        $lines = [];
                        foreach ($tasks as $type => $task) {
                            if (($task['phase'] ?? 'enrollment') !== 'enrollment') continue;
                            $label = $labels[$type] ?? $type;
                            $status = ($task['status'] ?? 'pending') === 'completed' ? '✓' : '○';
                            $lines[] = $status . ' ' . $label;
                        }
                        return implode("\n", $lines);
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

        // Document upload -> admin notification
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

    /**
     * Send quote email (triggered from admin "Send Quote" action).
     */
    public function onQuoteSendEmail(int $quoteId, string $sendTo, string $sendCc = ''): void
    {
        $quoteService = ntdst_get(QuoteService::class);
        $quote = $quoteService->getQuote($quoteId);
        if (!$quote || is_wp_error($quote)) {
            return;
        }

        $context = [
            'quote_id'        => $quoteId,
            'user_id'         => (int) ($quote['user_id'] ?? 0),
            'edition_id'      => (int) ($quote['edition_id'] ?? 0),
            'registration_id' => (int) ($quote['registration_id'] ?? 0),
        ];

        $options = ['to' => $sendTo];
        if ($sendCc) {
            $options['cc'] = $sendCc;
        }

        ndmail_send('stride-quote-sent', $context, $options);
    }

    /**
     * Seed default templates if not already present.
     */
    public function maybeSeedTemplates(): void
    {
        $currentVersion = '3';
        if (get_option('stride_mail_templates_seeded') === $currentVersion) {
            return;
        }

        $this->seedTemplates();
        update_option('stride_mail_templates_seeded', $currentVersion);
    }

    /**
     * Create default email templates.
     */
    public function seedTemplates(): void
    {
        $adminEmail = self::getAdminEmail();

        $templates = $this->getTemplateDefinitions();

        $model = ntdst_data()->get('ndmail_template');

        foreach ($templates as $slug => $tpl) {
            // Skip if template already exists
            $existing = get_page_by_path($slug, OBJECT, 'ndmail_template');
            if ($existing) {
                continue;
            }

            // Use Data API friendly vocabulary (title/content, not post_title/post_content)
            // so fields validate, cache invalidates, and we don't leave orphan
            // _ndmail_post_* meta rows if the model later registers those keys.
            $created = $model->create([
                'title' => $tpl['title'],
                'content' => $tpl['body'],
                'post_name' => $slug,
                'post_status' => 'publish',
                'subject' => $tpl['subject'],
                'category' => $tpl['category'] ?? 'notification',
                'status' => 'active',
                'trigger' => $tpl['trigger'] ?? '',
            ]);

            if (is_wp_error($created)) {
                ntdst_log('mail')->warning('Failed to seed Stride mail template', [
                    'slug' => $slug,
                    'error' => $created->get_error_message(),
                ]);
            }
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
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>Je inschrijving voor <strong>{{edition.title}}</strong> is ontvangen.</p>'
                    . '<p><strong>Startdatum:</strong> {{edition.start_date}}<br>'
                    . '<strong>Locatie:</strong> {{edition.venue}}</p>'
                    . '<p>Om je inschrijving te voltooien, dien je nog enkele stappen af te ronden:</p>'
                    . '<p><a href="{{completion.url}}" class="button">Inschrijving voltooien</a></p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            'stride-enrollment-created-admin' => [
                'title' => 'Nieuwe inschrijving (admin)',
                'subject' => 'Nieuwe inschrijving: {{user.display_name}} voor {{edition.title}}',
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
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>Je inschrijving voor <strong>{{edition.title}}</strong> is bevestigd!</p>'
                    . '<p><strong>Startdatum:</strong> {{edition.start_date}}<br>'
                    . '<strong>Locatie:</strong> {{edition.venue}}</p>'
                    . '<p>Bekijk je inschrijving en eventuele volgende stappen (zoals sessiekeuze) in je dashboard:</p>'
                    . '<p><a href="{{completion.url}}" class="button">Naar mijn inschrijving</a></p>'
                    . '<p>We kijken ernaar uit je te verwelkomen.</p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            'stride-enrollment-cancelled' => [
                'title' => 'Inschrijving geannuleerd',
                'subject' => 'Inschrijving geannuleerd: {{edition.title}}',
                'trigger' => 'stride/registration/cancelled',
                'category' => 'transactional',
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>Je inschrijving voor <strong>{{edition.title}}</strong> is geannuleerd.</p>'
                    . '<p>Heb je vragen? Neem gerust contact met ons op.</p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            'stride-task-documents-admin' => [
                'title' => 'Documenten ontvangen (admin)',
                'subject' => 'Documenten ontvangen: {{user.display_name}}',
                'trigger' => '',
                'category' => 'notification',
                'body' => '<p>{{user.display_name}} heeft documenten ge&uuml;pload voor <strong>{{edition.title}}</strong>.</p>'
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
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>Gefeliciteerd! Je hebt de opleiding <strong>{{edition.title}}</strong> succesvol afgerond.</p>'
                    . '<p>Je certificaat is beschikbaar via je dashboard.</p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            // Fires after the classroom/attendance phase is done but post-course
            // tasks still need to be completed (post_evaluation, post_documents,
            // post_approval). LD completion + certificate come later, when those
            // are finished — that's when `stride-completion-user` fires.
            'stride-attendance-complete' => [
                'title' => 'Aanwezigheid voltooid',
                'subject' => 'Aanwezigheid afgerond: {{edition.title}}',
                'trigger' => 'stride/completion/attendance_complete',
                'category' => 'transactional',
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>Je aanwezigheid bij <strong>{{edition.title}}</strong> is bevestigd. Er volgen nog enkele afsluitende taken voor je je certificaat ontvangt.</p>'
                    . '<p><a href="{{completion.url}}" class="button">Opleiding afronden</a></p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            'stride-quote-created' => [
                'title' => 'Offerte aangemaakt',
                'subject' => 'Je offerte {{quote.number}} is aangemaakt',
                'trigger' => 'stride/quote/created',
                'category' => 'transactional',
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
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
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
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
            'stride-interest-registered-user' => [
                'title' => 'Bevestiging interesse (gebruiker)',
                'subject' => 'Bevestiging interesse: {{edition.title}}',
                'trigger' => '',
                'category' => 'transactional',
                'body' => '<p>Beste {{registration.name}},</p>'
                    . '<p>Je interesse voor <strong>{{edition.title}}</strong> is goed ontvangen.</p>'
                    . '<p>We nemen contact met je op zodra de inschrijvingen openen.</p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            'stride-waitlist-registered-user' => [
                'title' => 'Bevestiging wachtlijst (gebruiker)',
                'subject' => 'Bevestiging wachtlijst: {{edition.title}}',
                'trigger' => '',
                'category' => 'transactional',
                'body' => '<p>Beste {{registration.name}},</p>'
                    . '<p>Je staat op de wachtlijst voor <strong>{{edition.title}}</strong>.</p>'
                    . '<p>We nemen contact met je op zodra er een plaats vrijkomt of als er een nieuwe editie wordt ingepland.</p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            'stride-trajectory-enrolled' => [
                'title' => 'Traject inschrijving',
                'subject' => 'Inschrijving traject: {{trajectory.title}}',
                'trigger' => 'stride/trajectory/enrolled',
                'category' => 'transactional',
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>Je inschrijving voor het traject <strong>{{trajectory.title}}</strong> is ontvangen.</p>'
                    . '<p>Je kunt je voortgang volgen in je dashboard.</p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
        ];
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

        return match ($field) {
            'title' => get_the_title($editionId) ?: null,
            'start_date' => ($v = $this->editionRepo->getField($editionId, 'start_date')) ? stride_format_date($v) : null,
            'end_date' => ($v = $this->editionRepo->getField($editionId, 'end_date')) ? stride_format_date($v) : null,
            'venue' => $this->editionRepo->getField($editionId, 'venue') ?: null,
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
