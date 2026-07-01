<?php

declare(strict_types=1);

namespace Stride\Modules\Reminder;

use Stride\Admin\ActionQueueService;
use Stride\Admin\StrideSettingsService;
use Stride\Infrastructure\AbstractService;
use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Daily cron: sends gate reminder / deadline-tomorrow emails for
 * registrations with an active enroll-phase or post-phase gate deadline.
 *
 * Wires together:
 *  - RegistrationRepository::findWithActiveDeadline() (Task 2.3) — enumeration
 *  - RegistrationRepository::getReminderState()/setReminderState() (Task 2.2) — ledger
 *  - GateReminderDueCalculator::dueMailFor() (Task 4.2) — pure date-math decision
 *  - EnrollmentCompletion::getTaskAvailability() — "is this phase still incomplete?"
 *  - ndmail_send() (netdust-mail) — actual delivery
 *
 * Concurrency (P2-gate requirement): the read-state -> decide -> send ->
 * mark-state sequence for a single registration+phase runs entirely inside
 * RegistrationRepository's per-registration advisory lock
 * (acquireSelectionLock/releaseSelectionLock — the same MySQL GET_LOCK idiom
 * used for selection writes), so two overlapping cron ticks can never both
 * send for the same registration.
 *
 * INV-4: WP_Error from setReminderState()/ndmail_send() is logged and the
 * ledger is NOT marked, so the mail is retried on the next cron tick.
 *
 * Forward-dep (Phase 5 not built yet): 'gate_reminder_days' is not in
 * ActionQueueService::DEFAULTS yet, so getReminderDays() falls back to 7 when
 * the key is absent. Once Phase 5 adds it, this reads live with no change here.
 */
final class GateReminderService extends AbstractService
{
    private const PHASES = [
        'enroll' => [
            'deadline_field' => 'gate_deadline',
            'immediate_tasks' => ['questionnaire', 'documents'],
        ],
        'post' => [
            'deadline_field' => 'post_gate_deadline',
            'immediate_tasks' => ['post_evaluation', 'post_documents'],
        ],
    ];

    private const MAIL_SLUGS = [
        'reminder' => 'stride-gate-reminder',
        'deadline' => 'stride-gate-deadline-tomorrow',
    ];

    private const CHUNK_SIZE = 500;

    public function __construct(
        private readonly RegistrationRepository $registrations,
        private readonly EditionRepository $editions,
        private readonly EnrollmentCompletion $completion,
        private readonly GateReminderDueCalculator $calculator,
    ) {
        parent::__construct();
    }

    public static function metadata(): array
    {
        return [
            'name' => 'Gate Reminder Service',
            'description' => 'Daily cron sending gate reminder/deadline emails for incomplete enrollment/post-course tasks',
            'priority' => 20,
        ];
    }

    protected function getConfigSlug(): string
    {
        return 'gate_reminder';
    }

    protected function init(): void
    {
        ntdst_schedule_recurring('stride_gate_reminders', 'daily', [$this, 'run']);
    }

    /**
     * Cron entry point. No params, reads no request superglobals
     * (mitigation 7) — WP-Cron invokes this outside any HTTP request context.
     */
    public function run(): void
    {
        $today = current_time('Y-m-d');
        $reminderDays = $this->getReminderDays();

        $offset = 0;

        do {
            $rows = $this->registrations->findWithActiveDeadline(self::CHUNK_SIZE, $offset);

            foreach ($rows as $row) {
                $this->processRegistration($row, $reminderDays, $today);
            }

            $offset += self::CHUNK_SIZE;
        } while (count($rows) === self::CHUNK_SIZE);
    }

    /**
     * Resolve gate_reminder_days from settings, falling back to 7 while
     * Phase 5 hasn't added the key to ActionQueueService::DEFAULTS yet.
     * Clamped defensively to [1, 365].
     */
    private function getReminderDays(): int
    {
        $rules = StrideSettingsService::getNotificationRules();
        $days = $rules['gate_reminder_days']['value'] ?? 7;

        return max(1, min((int) $days, 365));
    }

    private function processRegistration(object $row, int $reminderDays, string $today): void
    {
        $regId = (int) $row->id;
        $editionId = (int) $row->edition_id;

        if (!$editionId) {
            return;
        }

        $tasks = is_string($row->completion_tasks ?? null)
            ? (json_decode($row->completion_tasks, true) ?: [])
            : (array) ($row->completion_tasks ?? []);

        foreach (self::PHASES as $phase => $config) {
            $this->processPhase($regId, $editionId, $phase, $config, $tasks, $reminderDays, $today);
        }
    }

    /**
     * @param array{deadline_field: string, immediate_tasks: array<int, string>} $config
     */
    private function processPhase(
        int $regId,
        int $editionId,
        string $phase,
        array $config,
        array $tasks,
        int $reminderDays,
        string $today,
    ): void {
        $deadline = $this->editions->getField($editionId, $config['deadline_field']);

        if (!$deadline) {
            return;
        }

        if ($this->isPhaseComplete($tasks, $editionId, $config['immediate_tasks'])) {
            return;
        }

        if (!$this->registrations->acquireSelectionLock($regId)) {
            // Another run holds the lock for this registration — skip this tick.
            return;
        }

        try {
            $this->decideAndSend($regId, $editionId, $phase, $deadline, $reminderDays, $today);
        } finally {
            $this->registrations->releaseSelectionLock($regId);
        }
    }

    /**
     * @param array<int, string> $immediateTasks
     */
    private function isPhaseComplete(array $tasks, int $editionId, array $immediateTasks): bool
    {
        $relevant = array_intersect_key($tasks, array_flip($immediateTasks));

        if (empty($relevant)) {
            // No gate tasks configured for this phase on this edition — nothing to remind about.
            return true;
        }

        $availability = $this->completion->getTaskAvailability($tasks, $editionId);

        foreach ($immediateTasks as $type) {
            if (!isset($tasks[$type])) {
                continue;
            }

            if (($availability[$type]['state'] ?? '') !== 'completed') {
                return false;
            }
        }

        return true;
    }

    /**
     * Must run inside the advisory lock: re-reads state, decides, sends, marks.
     */
    private function decideAndSend(
        int $regId,
        int $editionId,
        string $phase,
        string $deadline,
        int $reminderDays,
        string $today,
    ): void {
        $state = $this->registrations->getReminderState($regId);
        $phaseState = $state[$phase] ?? ['reminder' => null, 'deadline' => null];

        $row = $this->registrations->find($regId);
        $registeredAt = $row->registered_at ?? null;

        if (!$registeredAt) {
            return;
        }

        $mailType = $this->calculator->dueMailFor($registeredAt, $deadline, $reminderDays, $phaseState, $today);

        if ($mailType === null) {
            return;
        }

        $userId = (int) ($row->user_id ?? 0);
        $email = $this->resolveEmail($userId);

        if ($email === null) {
            ntdst_log('enrollment')->warning('Gate reminder: invalid or missing recipient email, mail not sent', [
                'registration_id' => $regId,
                'user_id' => $userId,
                'phase' => $phase,
                'mail_type' => $mailType,
            ]);

            return;
        }

        $slug = self::MAIL_SLUGS[$mailType];

        if (!function_exists('ndmail_send')) {
            ntdst_log('enrollment')->error('Gate reminder: ndmail_send unavailable, mail not sent', [
                'registration_id' => $regId,
                'phase' => $phase,
                'mail_type' => $mailType,
            ]);

            return;
        }

        $result = ndmail_send($slug, [
            'user_id' => $userId,
            'edition_id' => $editionId,
            'registration_id' => $regId,
        ], ['to' => $email]);

        if ($result instanceof WP_Error) {
            ntdst_log('enrollment')->error('Gate reminder: ndmail_send failed', [
                'registration_id' => $regId,
                'phase' => $phase,
                'mail_type' => $mailType,
                'error' => $result->get_error_message(),
            ]);

            return;
        }

        $phaseState[$mailType] = $today;
        $state[$phase] = $phaseState;

        $marked = $this->registrations->setReminderState($regId, $state);

        if ($marked instanceof WP_Error) {
            ntdst_log('enrollment')->error('Gate reminder: setReminderState failed after send', [
                'registration_id' => $regId,
                'phase' => $phase,
                'mail_type' => $mailType,
                'error' => $marked->get_error_message(),
            ]);
        }
    }

    private function resolveEmail(int $userId): ?string
    {
        if (!$userId) {
            return null;
        }

        $user = get_userdata($userId);
        $email = $user ? $user->user_email : '';

        return ($email && is_email($email)) ? $email : null;
    }
}
