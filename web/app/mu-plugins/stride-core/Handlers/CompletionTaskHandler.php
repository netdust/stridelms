<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Enrollment\EnrollmentCompletion;
use Stride\Modules\Enrollment\RegistrationRepository;

/**
 * Handles completion task AJAX requests and auto-confirmation.
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
        add_action('wp_ajax_stride_complete_task', [$this, 'ajaxCompleteTask']);
        add_action('wp_ajax_stride_upload_completion_documents', [$this, 'ajaxUploadDocuments']);
        add_action('stride/enrollment/task_completed', [$this, 'onTaskCompleted']);
    }

    /**
     * AJAX: Complete a task (questionnaire, session_selection).
     */
    public function ajaxCompleteTask(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_completion')) {
            wp_send_json_error(['message' => __('Ongeldig token.', 'stride')]);
        }

        $registrationId = absint($_POST['registration_id'] ?? 0);
        $taskType = sanitize_text_field($_POST['task_type'] ?? '');
        $taskData = json_decode(stripslashes($_POST['task_data'] ?? '{}'), true) ?: [];

        if (!$registrationId || !$taskType) {
            wp_send_json_error(['message' => __('Ongeldige gegevens.', 'stride')]);
        }

        // Verify user owns this registration
        $repo = ntdst_get(RegistrationRepository::class);
        $reg = $repo->find($registrationId);

        if (!$reg || (int) $reg->user_id !== get_current_user_id()) {
            wp_send_json_error(['message' => __('Geen toegang.', 'stride')]);
        }

        $completion = ntdst_get(EnrollmentCompletion::class);
        $result = $completion->completeTask($registrationId, $taskType, $taskData);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['completed' => true]);
    }

    /**
     * AJAX: Upload documents and mark task complete.
     */
    public function ajaxUploadDocuments(): void
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'stride_completion')) {
            wp_send_json_error(['message' => __('Ongeldig token.', 'stride')]);
        }

        $registrationId = absint($_POST['registration_id'] ?? 0);

        if (!$registrationId) {
            wp_send_json_error(['message' => __('Ongeldige gegevens.', 'stride')]);
        }

        // Verify user owns this registration
        $repo = ntdst_get(RegistrationRepository::class);
        $reg = $repo->find($registrationId);

        if (!$reg || (int) $reg->user_id !== get_current_user_id()) {
            wp_send_json_error(['message' => __('Geen toegang.', 'stride')]);
        }

        if (empty($_FILES['documents'])) {
            wp_send_json_error(['message' => __('Geen bestanden geselecteerd.', 'stride')]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachmentIds = [];
        $files = $_FILES['documents'];

        $fileCount = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $fileCount; $i++) {
            $file = [
                'name'     => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                'type'     => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                'error'    => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                'size'     => is_array($files['size']) ? $files['size'][$i] : $files['size'],
            ];

            $_FILES['upload_file'] = $file;
            $attachmentId = media_handle_upload('upload_file', 0);

            if (!is_wp_error($attachmentId)) {
                $attachmentIds[] = $attachmentId;
            }
        }

        if (empty($attachmentIds)) {
            wp_send_json_error(['message' => __('Upload mislukt. Probeer opnieuw.', 'stride')]);
        }

        $completion = ntdst_get(EnrollmentCompletion::class);
        $result = $completion->completeTask($registrationId, 'documents', ['files' => $attachmentIds]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'completed' => true,
            'attachment_ids' => $attachmentIds,
        ]);
    }

    /**
     * Handle task completion — auto-confirm if all tasks done.
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

        // All tasks complete — auto-confirm
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
