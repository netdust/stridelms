<?php

declare(strict_types=1);

namespace Stride\Modules\Mail;

use Stride\Domain\Money;
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

        // Mail #1 ("je moet nog...") — fires ONLY when the phase's edition
        // carries a gate deadline (no deadline -> no cadence -> no mail #1
        // either, consistent with GateReminderService's cron).
        add_action('stride/registration/created', [$this, 'onRegistrationCreatedGateTodoMail']);
        add_action('stride/completion/completed', [$this, 'onCompletionCompletedGateTodoMail']);

        // User confirmations for logged-in interest / waitlist submissions.
        // Anonymous submissions send their confirmation inline from QuestionnaireHandler.
        add_action('stride/registration/interest_registered', [$this, 'onInterestRegisteredUserMail']);
        add_action('stride/registration/waitlisted', [$this, 'onWaitlistRegisteredUserMail']);

        // M-NEW-USER-MAIL-ONLY / attack 6: the stride/registration/confirmed event
        // is bound to the seeded, active `stride-enrollment-confirmed` template,
        // which netdust-mail auto-sends (priority 10) to the row's user. For an
        // anonymous-waitlist-promote COLLISION (an anon lead whose email matched a
        // PRE-EXISTING account), that mail would be an unsolicited confirmation to
        // a stranger. We arm a per-dispatch suppression BEFORE the netdust-mail
        // trigger (priority 5) and disarm it AFTER (priority 15), so the suppression
        // is scoped to exactly this dispatch and never disables the template
        // globally or affects normal (logged-in / new-account) confirms.
        add_action('stride/registration/confirmed', [$this, 'armConfirmMailSuppression'], 5);
        add_action('stride/registration/confirmed', [$this, 'disarmConfirmMailSuppression'], 15);

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
     * Handles both dispatch shapes of the interest/waitlist events:
     * logged-in (EnrollmentService: user_id set) and anonymous
     * (QuestionnaireHandler: user_id null, name/email in the payload).
     *
     * @param array{user_id?: int|null, edition_id?: int|null, name?: string, email?: string} $data
     */
    private function sendUserStageMail(string $template, array $data): void
    {
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId) {
            $user = get_userdata($userId);
            if (!$user || !$user->user_email) {
                return;
            }
            $name  = trim($user->first_name . ' ' . $user->last_name) ?: $user->display_name;
            $email = $user->user_email;
        } else {
            $name  = (string) ($data['name'] ?? '');
            $email = sanitize_email((string) ($data['email'] ?? ''));
            if (!$email) {
                return;
            }
        }

        $editionId = (int) ($data['edition_id'] ?? 0);
        $edition = $editionId ? get_post($editionId) : null;

        ndmail_send($template, [
            'registration' => [
                'name' => $name,
                'email' => $email,
            ],
            'edition_id' => $editionId,
            'edition' => ['title' => $edition ? $edition->post_title : ''],
        ], ['to' => $email]);
    }

    /**
     * Mail #1 ("je moet nog...") — enroll phase.
     *
     * Fires ONLY when the edition carries a `gate_deadline` (enroll-phase
     * gate). No deadline means no reminder cadence (GateReminderService
     * never enumerates the registration either), so mail #1 stays silent
     * too — the guard keeps enrollment mail behavior consistent with the
     * cron's own eligibility check.
     *
     * @param array{registration_id: int, user_id: int, edition_id: int} $data
     */
    public function onRegistrationCreatedGateTodoMail(array $data): void
    {
        if (!function_exists('ndmail_send')) {
            return;
        }

        $editionId = (int) ($data['edition_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);
        $registrationId = (int) ($data['registration_id'] ?? 0);

        if (!$editionId || !$userId) {
            return;
        }

        $deadline = $this->editionRepo->getField($editionId, 'gate_deadline');
        if (empty($deadline)) {
            return;
        }

        $email = self::resolveUserEmail($userId);
        if (!$email) {
            return;
        }

        ndmail_send('stride-gate-todo', [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'registration_id' => $registrationId,
        ], ['to' => $email]);
    }

    /**
     * Mail #1 ("je moet nog...") — post/completion phase.
     *
     * Fires ONLY when the edition carries a `post_gate_deadline`. Mirrors
     * the enroll-phase guard above: no deadline, no mail #1.
     *
     * @param array{edition_id: int, user_id: int, course_id?: int} $data
     */
    public function onCompletionCompletedGateTodoMail(array $data): void
    {
        if (!function_exists('ndmail_send')) {
            return;
        }

        $editionId = (int) ($data['edition_id'] ?? 0);
        $userId = (int) ($data['user_id'] ?? 0);

        if (!$editionId || !$userId) {
            return;
        }

        $deadline = $this->editionRepo->getField($editionId, 'post_gate_deadline');
        if (empty($deadline)) {
            return;
        }

        $email = self::resolveUserEmail($userId);
        if (!$email) {
            return;
        }

        ndmail_send('stride-gate-todo', [
            'user_id' => $userId,
            'edition_id' => $editionId,
        ], ['to' => $email]);
    }

    /**
     * Resolve a valid recipient email for a user id (mirrors
     * GateReminderService::resolveEmail — same invalid/missing-email guard).
     */
    private static function resolveUserEmail(int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $user = get_userdata($userId);
        $email = $user ? $user->user_email : '';

        return ($email && is_email($email)) ? $email : null;
    }

    /**
     * The per-dispatch pre_wp_mail guard currently armed (null when disarmed).
     *
     * @var callable|null
     */
    private $confirmMailGuard = null;

    /**
     * Memoized static subject prefix of the confirmation template (null until
     * first armed dispatch resolves it). Avoids a per-dispatch template lookup
     * in a collision-heavy bulk batch.
     */
    private ?string $confirmSubjectPrefix = null;

    /**
     * Arm a per-dispatch suppression of the seeded confirmation mail for the
     * anonymous-promote COLLISION case (M-NEW-USER-MAIL-ONLY / attack 6).
     *
     * Runs at priority 5, BEFORE netdust-mail's confirmed-trigger send (priority
     * 10). When the dispatch carries `suppress_confirm_mail === true`, we install
     * a narrowly-scoped pre_wp_mail short-circuit that suppresses ONLY the
     * confirmation mail to the resolved (pre-existing) account: it matches both
     * the recipient email AND the confirmation template's subject prefix, so the
     * quote/created mail (different subject) and any mail to a different recipient
     * are untouched. The guard self-removes after one match and is unconditionally
     * disarmed at priority 15.
     *
     * Why this seam: netdust-mail exposes no per-send short-circuit filter
     * (`ndmail_before_send` is registered but never applied; there is no
     * recipient/pre_send filter), and gating the whole `confirmed` dispatch would
     * also silence the audit, quote, cache and trajectory listeners for the
     * collision row — which IS a real confirmed enrollment. Suppressing at
     * pre_wp_mail keeps the grant + those four listeners intact and removes only
     * the unsolicited mail.
     *
     * @param array{user_id?: int|null, suppress_confirm_mail?: bool} $data
     */
    public function armConfirmMailSuppression(array $data): void
    {
        if (empty($data['suppress_confirm_mail'])) {
            return;
        }

        $userId = (int) ($data['user_id'] ?? 0);
        if (!$userId) {
            return;
        }
        $user = get_userdata($userId);
        if (!$user || !$user->user_email) {
            return;
        }
        $target = strtolower(trim((string) $user->user_email));

        // Static prefix of the confirmation template's subject (before the first
        // SmartCode), so the match is template-specific without hardcoding the
        // rendered edition title. e.g. "Inschrijving bevestigd: {{edition.title}}"
        // → "Inschrijving bevestigd:". Memoized per-request — the template subject
        // does not change within a single bulk-promote batch.
        if ($this->confirmSubjectPrefix === null) {
            $template = get_page_by_path('stride-enrollment-confirmed', OBJECT, 'ndmail_template');
            $rawSubject = $template ? (string) get_post_meta($template->ID, '_ndmail_subject', true) : '';
            $this->confirmSubjectPrefix = trim((string) strstr($rawSubject, '{{', true)) ?: $rawSubject;
        }
        $subjectPrefix = $this->confirmSubjectPrefix;

        $this->confirmMailGuard = function ($short, $atts) use ($target, $subjectPrefix) {
            $to = $atts['to'] ?? [];
            $recipients = is_array($to) ? $to : [$to];
            $matchesRecipient = false;
            foreach ($recipients as $r) {
                if (strtolower(trim((string) $r)) === $target) {
                    $matchesRecipient = true;
                    break;
                }
            }

            $subject = (string) ($atts['subject'] ?? '');
            // INTENTIONAL FAIL-SAFE: an empty/underivable prefix (template missing
            // or edited to a prefix-less subject) means recipient-only matching —
            // i.e. MORE suppression, never less. Do NOT "fix" this into a
            // fail-open `false`: within the prio-5→15 arm window the confirm mail
            // is the only send to this recipient, and biasing toward suppression
            // is what keeps attack-6 (M-NEW-USER-MAIL-ONLY) leak-proof.
            $matchesSubject = $subjectPrefix === '' || str_starts_with($subject, $subjectPrefix);

            if ($matchesRecipient && $matchesSubject) {
                // Suppress this one send (return non-null short-circuits wp_mail).
                $this->disarmConfirmMailSuppression([]);
                return true;
            }

            return $short;
        };

        add_filter('pre_wp_mail', $this->confirmMailGuard, 10, 2);
    }

    /**
     * Disarm the per-dispatch confirmation-mail suppression (priority 15, after
     * the netdust-mail trigger). Idempotent — also called from inside the guard
     * after a single match so a later, unrelated send in the same request is
     * never suppressed.
     */
    public function disarmConfirmMailSuppression(array $data = []): void
    {
        if ($this->confirmMailGuard !== null) {
            remove_filter('pre_wp_mail', $this->confirmMailGuard, 10);
            $this->confirmMailGuard = null;
        }
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
                        if (!$reg) {
                            return null;
                        }
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
                        if (!$reg || empty($reg->selections)) {
                            return null;
                        }
                        $selections = is_string($reg->selections) ? json_decode($reg->selections, true) : $reg->selections;
                        if (empty($selections)) {
                            return null;
                        }
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
                        if (!$reg) {
                            return null;
                        }
                        $tasks = is_string($reg->completion_tasks) ? json_decode($reg->completion_tasks, true) : ($reg->completion_tasks ?? []);
                        $files = $tasks['documents']['data']['files'] ?? $tasks['post_documents']['data']['files'] ?? [];
                        if (empty($files)) {
                            return null;
                        }
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
                        if (!$editionId) {
                            return null;
                        }
                        $slug = get_post_field('post_name', $editionId);
                        return $slug ? home_url('/edities/' . $slug . '/voltooien/') : null;
                    },
                ],
                'summary' => [
                    'label' => __('Taken overzicht', 'stride'),
                    'callback' => function ($ctx) {
                        $regId = (int) ($ctx['registration_id'] ?? 0);
                        if (!$regId) {
                            return null;
                        }
                        $reg = $this->registrationRepo->find($regId);
                        if (!$reg || empty($reg->completion_tasks)) {
                            return null;
                        }
                        $tasks = is_string($reg->completion_tasks) ? json_decode($reg->completion_tasks, true) : $reg->completion_tasks;
                        if (empty($tasks)) {
                            return null;
                        }
                        $labels = [
                            'questionnaire' => 'Vragenlijst invullen',
                            'documents' => 'Documenten uploaden',
                            'session_selection' => 'Sessiekeuze maken',
                            'approval' => 'Goedkeuring beheerder',
                        ];
                        $lines = [];
                        foreach ($tasks as $type => $task) {
                            if (($task['phase'] ?? 'enrollment') !== 'enrollment') {
                                continue;
                            }
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
                        if (!$courseId || !$userId) {
                            return null;
                        }
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

        $codes['gdpr'] = [
            'label' => __('GDPR', 'stride'),
            'codes' => [
                'reason' => [
                    'label' => __('Toelichting gebruiker', 'stride'),
                    'callback' => fn($ctx) => $ctx['reason'] ?? null,
                ],
                'edit_user_url' => [
                    'label' => __('Bewerk-gebruiker URL', 'stride'),
                    'callback' => function ($ctx) {
                        $userId = (int) ($ctx['user_id'] ?? 0);
                        return $userId ? get_edit_user_link($userId) : null;
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
        $currentVersion = '5';
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

        // Lazy lookup: MailTemplateRepository lives in netdust-mail. Resolving
        // via ntdst_get() at seed time (not in the constructor) keeps Stride's
        // bootstrap independent of whether netdust-mail is loaded — seedTemplates()
        // is only reached after the ndmail_send() guard in init() passes.
        $templateRepo = ntdst_get(\Netdust\Mail\MailTemplateRepository::class);

        foreach ($templates as $slug => $tpl) {
            // Skip if template already exists
            $existing = get_page_by_path($slug, OBJECT, 'ndmail_template');
            if ($existing) {
                continue;
            }

            // Use Data API friendly vocabulary (title/content, not post_title/post_content)
            // so fields validate, cache invalidates, and we don't leave orphan
            // _ndmail_post_* meta rows if the model later registers those keys.
            $created = $templateRepo->create([
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
            'stride-gdpr-erasure-admin' => [
                'title' => 'GDPR — account-verwijdering aangevraagd (beheerder)',
                'subject' => '[Stride] Account-verwijdering aangevraagd door {{user.display_name}}',
                'trigger' => 'stride/gdpr/erasure_requested',
                'category' => 'notification',
                'body' => '<p>Gebruiker <strong>{{user.display_name}}</strong> ({{user.email}}) heeft via <code>/mijn-account/?tab=profiel</code> om verwijdering van het account verzocht.</p>'
                    . '<p><strong>Toelichting van de gebruiker:</strong><br>{{gdpr.reason|geen toelichting opgegeven}}</p>'
                    . '<p><strong>Volgende stappen voor de beheerder:</strong></p>'
                    . '<ol>'
                    . '<li>Neem contact op met de gebruiker om de aanvraag te bevestigen.</li>'
                    . '<li>Anonimiseer het account via <a href="{{gdpr.edit_user_url}}">Gebruikers → bewerken</a> (of kies hard delete als geen historie bewaard moet blijven).</li>'
                    . '</ol>'
                    . '<p>Deze aanvraag is automatisch gelogd in de audit trail.</p>',
            ],
            'stride-gate-todo' => [
                'title' => 'Je moet nog taken afronden',
                'subject' => 'Nog te doen: {{edition.title}}',
                // No auto-trigger: this template is dispatched manually by the
                // deadline-gated handlers onRegistrationCreatedGateTodoMail /
                // onCompletionCompletedGateTodoMail (see init(), above). A
                // non-empty trigger here would ALSO get auto-bound by
                // netdust-mail's MailService::activateTriggers() with no
                // deadline guard, causing a double-send on gated editions and
                // an unconditional send on editions with no gate_deadline at
                // all. Keep this '' like the sibling stride-gate-reminder /
                // stride-gate-deadline-tomorrow templates (both cron-sent).
                'trigger' => '',
                'category' => 'transactional',
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>Voor <strong>{{edition.title}}</strong> moet je nog enkele taken afronden (zoals de vragenlijst of het opladen van documenten) voordat de deadline verstrijkt.</p>'
                    . '<p><a href="{{completion.url}}" class="button">Taken afronden</a></p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            'stride-gate-reminder' => [
                'title' => 'Herinnering: taken nog niet afgerond',
                'subject' => 'Herinnering: nog te doen voor {{edition.title}}',
                'trigger' => '',
                'category' => 'transactional',
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>Even een herinnering: je hebt nog openstaande taken voor <strong>{{edition.title}}</strong>. Rond deze tijdig af, zodat je niets mist.</p>'
                    . '<p><a href="{{completion.url}}" class="button">Taken afronden</a></p>'
                    . '<p>Met vriendelijke groet,<br>{{site.name}}</p>',
            ],
            'stride-gate-deadline-tomorrow' => [
                'title' => 'Deadline morgen: taken nog niet afgerond',
                'subject' => 'Deadline morgen: {{edition.title}}',
                'trigger' => '',
                'category' => 'transactional',
                'body' => '<p>Beste {{user.first_name|klant}},</p>'
                    . '<p>De deadline voor je openstaande taken bij <strong>{{edition.title}}</strong> is <strong>morgen</strong>. Rond ze vandaag nog af.</p>'
                    . '<p><a href="{{completion.url}}" class="button">Taken afronden</a></p>'
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
        if (!$editionId) {
            return null;
        }

        return match ($field) {
            'title' => get_the_title($editionId) ?: null,
            'start_date' => ($v = $this->editionRepo->getField($editionId, 'start_date')) ? stride_format_date($v) : null,
            'end_date' => ($v = $this->editionRepo->getField($editionId, 'end_date')) ? stride_format_date($v) : null,
            'venue' => $this->editionRepo->getField($editionId, 'venue') ?: null,
            'price' => ($p = $this->editionService->getPrice($editionId))->inCents() > 0 ? $p->format() : null,
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
