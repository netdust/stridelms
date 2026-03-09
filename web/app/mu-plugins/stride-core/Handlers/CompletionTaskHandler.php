<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\RegistrationRepository;
use WP_Error;

/**
 * Handles completion task API requests and auto-confirmation.
 *
 * Thin handler — validates input, delegates to EnrollmentCompletion.
 */
final class CompletionTaskHandler
{
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_complete_task', [$this, 'handleCompleteTask'], 10, 2);
        add_filter('ntdst/api_data/stride_upload_completion_documents', [$this, 'handleUploadDocuments'], 10, 2);
        add_action('stride/enrollment/task_completed', [$this, 'onTaskCompleted']);
    }

    /**
     * Complete a task (questionnaire, session_selection).
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return array<string, mixed>|WP_Error
     */
    public function handleCompleteTask(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $registrationId = absint($params['registration_id'] ?? 0);
        $taskType = sanitize_text_field($params['task_type'] ?? '');
        $taskData = is_array($params['task_data'] ?? null) ? $params['task_data'] : [];

        if (!$registrationId || !$taskType) {
            return new WP_Error('invalid_input', __('Ongeldige gegevens.', 'stride'));
        }

        $repo = ntdst_get(RegistrationRepository::class);
        $reg = $repo->find($registrationId);

        if (!$reg || (int) $reg->user_id !== $userId) {
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        $completion = ntdst_get(EnrollmentCompletion::class);
        $result = $completion->completeTask($registrationId, $taskType, $taskData);

        if (is_wp_error($result)) {
            return $result;
        }

        return ['completed' => true];
    }

    /**
     * Upload documents and mark task complete.
     *
     * Files arrive via multipart/form-data and are available in $params['_files'].
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters (includes '_files' key)
     * @return array<string, mixed>|WP_Error
     */
    public function handleUploadDocuments(mixed $data, array $params): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        $registrationId = absint($params['registration_id'] ?? 0);
        if (!$registrationId) {
            return new WP_Error('invalid_input', __('Ongeldige gegevens.', 'stride'));
        }

        $repo = ntdst_get(RegistrationRepository::class);
        $reg = $repo->find($registrationId);

        if (!$reg || (int) $reg->user_id !== $userId) {
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        $files = $params['_files']['documents'] ?? [];
        if (empty($files)) {
            return new WP_Error('no_files', __('Geen bestanden geselecteerd.', 'stride'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachmentIds = [];
        $fileCount = is_array($files['name']) ? count($files['name']) : 1;

        $errors = [];

        for ($i = 0; $i < $fileCount; $i++) {
            $_FILES['upload_file'] = [
                'name'     => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                'type'     => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error'    => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                'size'     => is_array($files['size']) ? $files['size'][$i] : $files['size'],
            ];

            $attachmentId = media_handle_upload('upload_file', 0);

            if (is_wp_error($attachmentId)) {
                $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $errors[] = sprintf('%s: %s', $fileName, $attachmentId->get_error_message());
            } else {
                $attachmentIds[] = $attachmentId;
            }
        }

        if (empty($attachmentIds)) {
            $message = !empty($errors)
                ? implode('; ', $errors)
                : __('Upload mislukt. Probeer opnieuw.', 'stride');
            return new WP_Error('upload_failed', $message);
        }

        $completion = ntdst_get(EnrollmentCompletion::class);
        $result = $completion->completeTask($registrationId, 'documents', ['files' => $attachmentIds]);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'completed' => true,
            'attachment_ids' => $attachmentIds,
        ];
    }

    /**
     * Handle task completion — auto-confirm or finalize depending on phase.
     *
     * If post-course tasks exist and all tasks are done, triggers LD completion
     * and marks registration as completed. Otherwise, auto-confirms (existing behavior).
     *
     * @param array<string, mixed> $data
     */
    public function onTaskCompleted(array $data): void
    {
        $registrationId = $data['registration_id'] ?? 0;
        $tasks = $data['tasks'] ?? [];

        if (!$registrationId || empty($tasks)) {
            return;
        }

        $completion = ntdst_get(EnrollmentCompletion::class);

        if (!$completion->isFullyComplete($tasks)) {
            return;
        }

        // Check if we have post-course tasks
        $hasPostCourse = !empty(array_filter($tasks, fn($t) => ($t['phase'] ?? 'enrollment') === 'post_course'));

        if ($hasPostCourse) {
            // All tasks done including post-course — mark LD complete + status completed
            $repo = ntdst_get(RegistrationRepository::class);
            $reg = $repo->find($registrationId);
            if ($reg) {
                $editionCompletion = ntdst_get(\Stride\Modules\Edition\EditionCompletion::class);
                $editionCompletion->processCompletionFinal((int) $reg->edition_id, (int) $reg->user_id);
                $repo->updateStatus($registrationId, \Stride\Domain\RegistrationStatus::Completed);
                ntdst_log('enrollment')->info('Registration completed after post-course tasks', [
                    'registration_id' => $registrationId,
                ]);
            }
        } else {
            // All enrollment tasks done — auto-confirm (existing behavior)
            $enrollmentService = ntdst_get(\Stride\Modules\Enrollment\EnrollmentService::class);
            $result = $enrollmentService->confirmRegistration($registrationId);

            if (is_wp_error($result)) {
                ntdst_log('enrollment')->error('Auto-confirm failed', [
                    'registration_id' => $registrationId,
                    'error' => $result->get_error_message(),
                ]);
            } else {
                ntdst_log('enrollment')->info('Registration auto-confirmed after task completion', [
                    'registration_id' => $registrationId,
                ]);
            }
        }
    }
}
