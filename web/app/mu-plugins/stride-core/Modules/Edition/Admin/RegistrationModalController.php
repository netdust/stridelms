<?php

declare(strict_types=1);

namespace Stride\Modules\Edition\Admin;

use Stride\Modules\Edition\EditionRepository;
use Stride\Modules\Edition\EditionService;
use Stride\Modules\Edition\SessionSelection;
use Stride\Modules\Edition\SessionService;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Server-renders enrollment-data and completion-data modals
 * for the deelnemers panel on a vad_edition post.
 */
final class RegistrationModalController
{
    public const NONCE_AJAX = 'stride_edition_admin';
    public const AJAX_ACTION = 'stride_get_registration_modal';

    public function __construct(
        private readonly EditionService $editionService,
        private readonly EditionRepository $editionRepository,
        private readonly SessionService $sessionService,
        private readonly SessionSelection $sessionSelection,
        private readonly RegistrationRepository $registrations,
    ) {
        $this->init();
    }

    private function init(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajaxGetModal']);
    }

    public function ajaxGetModal(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Onvoldoende rechten.', 'stride')], 403);
            return;
        }

        $nonce = isset($_REQUEST['nonce']) ? sanitize_text_field((string) $_REQUEST['nonce']) : '';
        if (!wp_verify_nonce($nonce, self::NONCE_AJAX)) {
            wp_send_json_error(['message' => __('Ongeldige sessie. Herlaad de pagina.', 'stride')], 403);
            return;
        }

        $registrationId = isset($_REQUEST['registration_id']) ? (int) $_REQUEST['registration_id'] : 0;
        $type = isset($_REQUEST['type']) ? sanitize_key((string) $_REQUEST['type']) : '';

        if ($registrationId <= 0 || !in_array($type, ['enrollment', 'completion'], true)) {
            wp_send_json_error(['message' => __('Ongeldige aanvraag.', 'stride')], 400);
            return;
        }

        $payload = $this->buildPayload($registrationId, $type);

        if ($payload instanceof \WP_Error) {
            wp_send_json_error(
                ['message' => $payload->get_error_message()],
                404,
            );
            return;
        }

        wp_send_json_success($payload);
    }

    /**
     * Build the payload (title + html) for a modal, or a WP_Error.
     *
     * @return array{title: string, html: string}|\WP_Error
     */
    public function buildPayload(int $registrationId, string $type): array|\WP_Error
    {
        $registration = $this->registrations->find($registrationId);
        if (!$registration) {
            return new \WP_Error(
                'registration_not_found',
                __('Inschrijving niet gevonden.', 'stride'),
            );
        }

        $userId = (int) $registration->user_id;
        $anonymisedAt = (int) get_user_meta($userId, '_stride_anonymised_at', true);
        if ($anonymisedAt > 0) {
            return new \WP_Error(
                'user_unavailable',
                __('Gegevens van deze gebruiker zijn niet meer beschikbaar.', 'stride'),
            );
        }

        $user = get_userdata($userId);
        if (!$user) {
            return new \WP_Error(
                'user_unavailable',
                __('Gegevens van deze gebruiker zijn niet meer beschikbaar.', 'stride'),
            );
        }

        $editionId = (int) $registration->edition_id;
        $edition = $this->editionRepository->find($editionId);
        if (!$edition instanceof \WP_Post) {
            return new \WP_Error(
                'edition_not_found',
                __('De editie van deze inschrijving bestaat niet meer.', 'stride'),
            );
        }

        return [
            'title' => $this->buildTitle($type, $user->display_name, $edition->post_title),
            'html'  => $this->renderHtml($type, $registration),
        ];
    }

    private function renderHtml(string $type, object $registration): string
    {
        if ($type === 'completion') {
            return $this->renderCompletion($registration);
        }
        return $this->renderEnrollment($registration);
    }

    private function renderEnrollment(object $registration): string
    {
        $enrollmentData = $this->decodeJson($registration->enrollment_data ?? '');
        $sessionSelections = $this->buildSessionSelections(
            (int) $registration->id,
            (int) $registration->edition_id,
        );
        $tasks = $this->decodeJson($registration->completion_tasks ?? '');
        $questionnaireAnswers = is_array($tasks['questionnaire']['data']['answers'] ?? null)
            ? $tasks['questionnaire']['data']['answers']
            : [];
        $documents = $this->buildDocuments($tasks);
        $initialSelection = $this->buildInitialSelection($enrollmentData['initial_selection'] ?? null);
        $stages = $this->buildStagesForDisplay($enrollmentData);

        ob_start();
        $partialPath = dirname(__DIR__, 3) . '/templates/admin/partials/registration-modal-enrollment.php';
        include $partialPath;
        return (string) ob_get_clean();
    }

    /**
     * @return array<int, array{slot_label: ?string, session: ?array}>
     */
    private function buildSessionSelections(int $registrationId, int $editionId): array
    {
        $selectedIds = $this->registrations->getSelections($registrationId);
        if (empty($selectedIds)) {
            return [];
        }

        $slotConfig = $this->sessionSelection->getSlotConfig($editionId);
        $slotLabelByKey = [];
        foreach ($slotConfig as $slot) {
            $key = (string) ($slot['slot'] ?? '');
            $slotLabelByKey[$key] = (string) ($slot['label'] ?? $key);
        }

        $rows = [];
        foreach ($selectedIds as $sessionId) {
            $session = $this->sessionService->getSession((int) $sessionId);
            if (!$session) {
                continue;
            }
            $slotKey = (string) ($session['slot'] ?? '');
            $rows[] = [
                'slot_label' => $slotKey !== '' ? ($slotLabelByKey[$slotKey] ?? __('Verplichte sessie', 'stride')) : null,
                'session' => $session,
            ];
        }

        return $rows;
    }

    /**
     * @param mixed $initial  Raw initial_selection value from enrollment_data.
     * @return array<int, array{
     *   phase_label: string,
     *   captured_at_display: string,
     *   captured_by_display: string,
     *   items: array<int, array{label: string, deleted: bool}>
     * }>
     */
    private function buildInitialSelection(mixed $initial): array
    {
        if (!is_array($initial) || empty($initial['phases'])) {
            return [];
        }
        $out = [];
        foreach ($initial['phases'] as $phase) {
            $ids = $phase['session_ids'] ?? $phase['edition_ids'] ?? [];
            if (!is_array($ids)) {
                continue;
            }
            $items = [];
            foreach ($ids as $id) {
                $post = get_post((int) $id);
                if (!$post) {
                    $items[] = ['label' => '#' . (int) $id, 'deleted' => true];
                    continue;
                }
                $label = $post->post_title;
                if ($post->post_type === 'vad_session') {
                    // Sessions store their date under _ntdst_date (SessionCPT
                    // field 'date' + the layer prefix) — the old bare
                    // 'session_date' key never existed, so the date suffix
                    // silently never rendered here.
                    $date = get_post_meta($post->ID, '_ntdst_date', true);
                    if ($date) {
                        $label .= ' — ' . date_i18n('d/m/Y', strtotime((string) $date));
                    }
                }
                $items[] = ['label' => $label, 'deleted' => false];
            }

            $capturedBy = $phase['captured_by'] ?? null;
            $byDisplay = __('(systeem)', 'stride');
            if ($capturedBy) {
                $user = get_userdata((int) $capturedBy);
                $byDisplay = $user ? $user->display_name : ('#' . (int) $capturedBy);
            }
            $capturedAt = $phase['captured_at'] ?? '';
            $atDisplay = $capturedAt ? date_i18n('d/m/Y H:i', strtotime((string) $capturedAt)) : '';

            $phaseLabel = match ($phase['phase'] ?? 'enrollment') {
                'enrollment' => __('Inschrijving', 'stride'),
                default => ucfirst(str_replace('_', ' ', (string) ($phase['phase'] ?? ''))),
            };

            $out[] = [
                'phase_label'          => $phaseLabel,
                'captured_at_display'  => $atDisplay,
                'captured_by_display'  => $byDisplay,
                'items'                => $items,
            ];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $enrollmentData
     * @return array<string, array{
     *   label: string,
     *   submitted_at_display: string,
     *   submitted_by_display: string,
     *   data: array<string, mixed>
     * }>
     */
    private function buildStagesForDisplay(array $enrollmentData): array
    {
        $labels = [
            'interest'            => __('Interesse', 'stride'),
            'waitlist'            => __('Wachtlijst', 'stride'),
            'enrollment_personal' => __('Inschrijving — Persoonlijk', 'stride'),
            'enrollment_billing'  => __('Inschrijving — Facturatie', 'stride'),
            'intake'              => __('Intake', 'stride'),
            'evaluation'          => __('Evaluatie', 'stride'),
        ];
        $out = [];
        foreach ($labels as $key => $label) {
            $envelope = $enrollmentData[$key] ?? null;
            if (!is_array($envelope)) {
                continue;
            }
            $data = is_array($envelope['data'] ?? null) ? $envelope['data'] : [];
            if (empty($data)) {
                continue;
            }
            $submittedBy = $envelope['submitted_by'] ?? null;
            $byDisplay = __('(anoniem)', 'stride');
            if ($submittedBy) {
                $user = get_userdata((int) $submittedBy);
                $byDisplay = $user ? $user->display_name : ('#' . (int) $submittedBy);
            }
            $submittedAt = $envelope['submitted_at'] ?? '';
            $atDisplay = $submittedAt ? date_i18n('d/m/Y H:i', strtotime((string) $submittedAt)) : '';

            $out[$key] = [
                'label'                 => $label,
                'submitted_at_display'  => $atDisplay,
                'submitted_by_display'  => $byDisplay,
                'data'                  => $data,
            ];
        }
        return $out;
    }

    private function renderCompletion(object $registration): string
    {
        $taskRows = $this->buildTaskRows(
            $this->decodeJson($registration->completion_tasks ?? ''),
        );

        $editionId = (int) $registration->edition_id;
        $userId = (int) $registration->user_id;
        $courseId = (int) ($this->editionService->getCourseId($editionId) ?? 0);

        $ldProgress = 0;
        $ldCompletionDate = null;
        $certificateUrl = '';

        if ($courseId > 0 && class_exists(\Stride\Integrations\LearnDash\LearnDashHelper::class)) {
            $ldProgress = \Stride\Integrations\LearnDash\LearnDashHelper::getProgress($courseId, $userId);
            $completionTs = \Stride\Integrations\LearnDash\LearnDashHelper::getCompletionDate($courseId, $userId);
            $ldCompletionDate = $completionTs ? date('Y-m-d H:i:s', $completionTs) : null;
            if (\Stride\Integrations\LearnDash\LearnDashHelper::isComplete($courseId, $userId)) {
                $certificateUrl = \Stride\Integrations\LearnDash\LearnDashHelper::getCertificateLink($courseId, $userId);
            }
        }

        // Aanwezigheid is only meaningful when we can compute attended hours.
        // Until SessionService::getHoursAttended lands, hide the section
        // rather than render misleading "0,0 / X uur".
        $showAttendance = method_exists($this->sessionService, 'getHoursAttended');
        $hoursAttended = 0.0;
        $hoursTotal = 0.0;
        if ($showAttendance) {
            $hoursAttended = $this->sessionService->getHoursAttended($userId, $editionId);
            $hoursTotal = method_exists($this->sessionService, 'getTotalHours')
                ? $this->sessionService->getTotalHours($editionId)
                : 0.0;
        }

        ob_start();
        $partialPath = dirname(__DIR__, 3) . '/templates/admin/partials/registration-modal-completion.php';
        include $partialPath;
        return (string) ob_get_clean();
    }

    /**
     * @return array<int, array{status:string,label:string,completed_at:?string,completed_by:?string}>
     */
    private function buildTaskRows(array $tasks): array
    {
        $labels = [
            'questionnaire'     => __('Vragenlijst', 'stride'),
            'documents'         => __('Documenten', 'stride'),
            'approval'          => __('Goedkeuring', 'stride'),
            'session_selection' => __('Sessiekeuze', 'stride'),
            'post_evaluation'   => __('Evaluatie', 'stride'),
            'post_documents'    => __('Documenten (na afloop)', 'stride'),
            'post_approval'     => __('Goedkeuring (na afloop)', 'stride'),
        ];

        $rows = [];
        foreach ($tasks as $taskKey => $task) {
            if (!is_array($task)) {
                continue;
            }
            $rows[] = [
                'status'       => (string) ($task['status'] ?? 'pending'),
                'label'        => $labels[$taskKey] ?? ucfirst(str_replace('_', ' ', (string) $taskKey)),
                'completed_at' => isset($task['completed_at']) ? (string) $task['completed_at'] : null,
                'completed_by' => isset($task['completed_by']) ? (string) $task['completed_by'] : null,
            ];
        }
        return $rows;
    }

    /**
     * Proofs live in protected storage (CompletionProofStorage) — their raw
     * attachment URLs are deny-ruled, so the admin affordance downloads
     * through the authenticated stride_download_proof handler instead.
     *
     * @return array<int, array{id:int, filename:string, uploaded_at:?string}>
     */
    private function buildDocuments(array $tasks): array
    {
        $docs = [];
        foreach (['documents', 'post_documents'] as $taskKey) {
            $files = $tasks[$taskKey]['data']['files'] ?? null;
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $fileId) {
                $id = (int) $fileId;
                if ($id <= 0) {
                    continue;
                }
                $path = get_attached_file($id);
                $docs[] = [
                    'id' => $id,
                    'filename' => $path ? basename((string) $path) : sprintf(__('Bestand #%d', 'stride'), $id),
                    'uploaded_at' => get_post_field('post_date', $id) ?: null,
                ];
            }
        }
        return $docs;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function buildTitle(string $type, string $userName, string $editionTitle): string
    {
        if ($type === 'completion') {
            return sprintf(
                /* translators: %s: user display name */
                __('Voltooiing — %s', 'stride'),
                $userName,
            );
        }

        return sprintf(
            /* translators: 1: user display name, 2: edition title */
            __('Inschrijving — %1$s — %2$s', 'stride'),
            $userName,
            $editionTitle,
        );
    }
}
