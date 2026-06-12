<?php

declare(strict_types=1);

namespace Stride\Handlers;

use Stride\Modules\Enrollment\CompletionProofStorage;
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
    // .doc / .docx intentionally excluded: macro-bearing Word documents can
    // execute on the admin's machine when reviewed. Users uploading completion
    // proof should export to PDF.
    private const ALLOWED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
    ];
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
    private const MAX_FILE_COUNT = 5;

    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        add_filter('ntdst/api_data/stride_complete_task', [$this, 'handleCompleteTask'], 10, 2);
        add_filter('ntdst/api_data/stride_upload_completion_documents', [$this, 'handleUploadDocuments'], 10, 2);
        add_filter('ntdst/api_data/stride_download_proof', [$this, 'handleDownloadProof'], 10, 2);
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
        $taskData = is_array($params['task_data'] ?? null) ? $this->sanitizeTaskData($params['task_data']) : [];

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

        // Persist session selections to the registration's selections column
        if ($taskType === 'session_selection' && !empty($taskData['session_ids'])) {
            $sessionIds = array_map('intval', $taskData['session_ids']);
            $repo->setSelections($registrationId, $sessionIds);
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

        $fileCount = is_array($files['name']) ? count($files['name']) : 1;

        if ($fileCount > self::MAX_FILE_COUNT) {
            return new WP_Error('too_many_files', sprintf(
                __('Maximaal %d bestanden per upload.', 'stride'),
                self::MAX_FILE_COUNT,
            ));
        }

        // Validate each file before uploading
        for ($i = 0; $i < $fileCount; $i++) {
            $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
            $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];

            if ($size > self::MAX_FILE_SIZE) {
                return new WP_Error('file_too_large', sprintf(
                    __('%s is te groot. Maximum is 10 MB.', 'stride'),
                    $name,
                ));
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $mimeType = $finfo->file($tmpName);

            if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
                return new WP_Error('invalid_file_type', sprintf(
                    __('%s: ongeldig bestandstype. Toegestaan: PDF, JPG, PNG.', 'stride'),
                    $name,
                ));
            }
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // M1: proofs may contain certificates bearing national IDs — store
        // them in the deny-ruled protected dir, never the public library.
        $dirReady = CompletionProofStorage::ensureProtectedDir();
        if (is_wp_error($dirReady)) {
            ntdst_log('enrollment')->error('proof upload: protected dir unavailable', [
                'registration_id' => $registrationId,
                'error' => $dirReady->get_error_message(),
            ]);

            return $dirReady;
        }

        $attachmentIds = [];

        $errors = [];

        // Proofs need no derivative image sizes — thumbnails would duplicate
        // sensitive content and nothing renders them.
        add_filter('upload_dir', [CompletionProofStorage::class, 'uploadDirFilter']);
        add_filter('intermediate_image_sizes_advanced', '__return_empty_array');

        try {
            for ($i = 0; $i < $fileCount; $i++) {
                $_FILES['upload_file'] = [
                    'name'     => is_array($files['name']) ? $files['name'][$i] : $files['name'],
                    'type'     => is_array($files['type']) ? $files['type'][$i] : $files['type'],
                    'tmp_name' => is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'],
                    'error'    => is_array($files['error']) ? $files['error'][$i] : $files['error'],
                    'size'     => is_array($files['size']) ? $files['size'][$i] : $files['size'],
                ];

                $attachmentId = media_handle_upload('upload_file', 0, [], $this->uploadOverrides());

                if (is_wp_error($attachmentId)) {
                    $fileName = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                    $errors[] = sprintf('%s: %s', $fileName, $attachmentId->get_error_message());
                } else {
                    $attachmentIds[] = $attachmentId;
                }
            }
        } finally {
            remove_filter('upload_dir', [CompletionProofStorage::class, 'uploadDirFilter']);
            remove_filter('intermediate_image_sizes_advanced', '__return_empty_array');
        }

        // M3 anchor: link each proof to its registration server-side so the
        // download handler authorizes against the stored link, never a
        // caller-supplied registration id.
        foreach ($attachmentIds as $attachmentId) {
            CompletionProofStorage::markProtected((int) $attachmentId, $registrationId);
        }

        if (empty($attachmentIds)) {
            $message = !empty($errors)
                ? implode('; ', $errors)
                : __('Upload mislukt. Probeer opnieuw.', 'stride');
            return new WP_Error('upload_failed', $message);
        }

        $taskType = in_array($params['task_type'] ?? '', ['documents', 'post_documents'], true)
            ? $params['task_type']
            : 'documents';

        $completion = ntdst_get(EnrollmentCompletion::class);
        $result = $completion->completeTask($registrationId, $taskType, ['files' => $attachmentIds]);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'completed' => true,
            'attachment_ids' => $attachmentIds,
        ];
    }

    /**
     * Download a completion proof (threat-model M2–M4, M9).
     *
     * The framework nonce is already verified by NTDST_Endpoints before this
     * filter runs (INV-2 — MUST NOT re-verify here). Authorization happens
     * inside this handler (INV-1): registration owner or stride_manage.
     *
     * Uses Response->download() which outputs the file and exits,
     * bypassing the normal JSON response (ICalHandler convergence point).
     *
     * @param mixed $data Existing data (unused)
     * @param array<string, mixed> $params Request parameters
     * @return never|WP_Error
     */
    public function handleDownloadProof(mixed $data, array $params): WP_Error
    {
        $resolved = $this->resolveProofDownload(absint($params['attachment_id'] ?? 0));

        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $contents = file_get_contents($resolved['path']);
        if ($contents === false) {
            ntdst_log('enrollment')->error('proof download: file unreadable', [
                'path' => $resolved['path'],
            ]);

            return new WP_Error('proof_unavailable', __('Bestand niet beschikbaar.', 'stride'));
        }

        // This exits - never returns. Sets Content-Type from the stored
        // validated MIME + Content-Disposition: attachment + nosniff (M4).
        ntdst_response()->download($contents, $resolved['filename'], $resolved['mime']);
    }

    /**
     * Resolve + authorize a proof download (separated from the byte-serving
     * exit so the contract is integration-testable).
     *
     * M3: accepts an attachment ID only. The registration link was stamped
     * server-side at upload/migration time; no user string ever reaches a
     * path, and the resolved file must live inside the protected dir.
     *
     * @return array{path: string, filename: string, mime: string}|WP_Error
     */
    public function resolveProofDownload(int $attachmentId): array|WP_Error
    {
        $userId = get_current_user_id();
        if (!$userId) {
            return new WP_Error('not_logged_in', __('Je moet ingelogd zijn.', 'stride'));
        }

        if (!$attachmentId) {
            return new WP_Error('invalid_input', __('Ongeldige gegevens.', 'stride'));
        }

        $registrationId = (int) get_post_meta($attachmentId, CompletionProofStorage::META_REGISTRATION, true);
        if ($registrationId <= 0) {
            // Not a completion proof — deny without detail (id iteration probe).
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        $repo = ntdst_get(RegistrationRepository::class);
        $reg = $repo->find($registrationId);

        if (!$reg) {
            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        // M2: registration owner or stride_manage, decided here (INV-1).
        if ((int) $reg->user_id !== $userId && !current_user_can('stride_manage')) {
            ntdst_log('enrollment')->warning('proof download denied: not owner', [
                'attachment_id' => $attachmentId,
                'registration_id' => $registrationId,
                'requested_by' => $userId,
            ]);

            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        $path = (string) get_attached_file($attachmentId);
        if ($path === '' || !file_exists($path)) {
            ntdst_log('enrollment')->error('proof download: file missing on disk', [
                'attachment_id' => $attachmentId,
                'registration_id' => $registrationId,
                'path' => $path,
            ]);

            return new WP_Error('proof_unavailable', __('Bestand niet beschikbaar.', 'stride'));
        }

        // M3: only files physically inside the protected dir are servable.
        if (!CompletionProofStorage::isProtectedPath($path)) {
            ntdst_log('enrollment')->warning('proof download denied: path outside protected dir', [
                'attachment_id' => $attachmentId,
                'registration_id' => $registrationId,
                'path' => $path,
            ]);

            return new WP_Error('forbidden', __('Geen toegang.', 'stride'));
        }

        $mime = (string) get_post_mime_type($attachmentId);

        return [
            'path' => $path,
            'filename' => basename($path),
            'mime' => $mime !== '' ? $mime : 'application/octet-stream',
        ];
    }

    /**
     * Overrides for media_handle_upload().
     *
     * PHPUnit cannot fabricate a genuine PHP upload: is_uploaded_file() only
     * passes for files received by the SAPI in this request's POST. The
     * integration bootstrap defines STRIDE_INTEGRATION_TESTING; production
     * never does, so real requests keep the strict
     * is_uploaded_file()/move_uploaded_file() path.
     *
     * @return array<string, mixed>
     */
    private function uploadOverrides(): array
    {
        $overrides = ['test_form' => false];

        if (defined('STRIDE_INTEGRATION_TESTING') && STRIDE_INTEGRATION_TESTING) {
            $overrides['action'] = 'wp_handle_sideload';
        }

        return $overrides;
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
            // All tasks done including post-course — mark LD complete + status completed.
            // processCompletionFinal returns WP_Error('no_course') for in-person-only
            // editions (no LearnDash course linked). That's expected, not a failure —
            // the registration still completes on the task-completion criterion.
            $repo = ntdst_get(RegistrationRepository::class);
            $reg = $repo->find($registrationId);
            if ($reg) {
                $editionCompletion = ntdst_get(\Stride\Modules\Edition\EditionCompletion::class);
                $result = $editionCompletion->processCompletionFinal((int) $reg->edition_id, (int) $reg->user_id);
                if (is_wp_error($result) && $result->get_error_code() !== 'no_course') {
                    ntdst_log('enrollment')->error('Final completion failed after post-course tasks', [
                        'registration_id' => $registrationId,
                        'edition_id'      => (int) $reg->edition_id,
                        'user_id'         => (int) $reg->user_id,
                        'error'           => $result->get_error_code() . ': ' . $result->get_error_message(),
                    ]);
                }
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

    /**
     * Recursively sanitize task data from user input.
     */
    private function sanitizeTaskData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $safeKey = sanitize_key($key);
            if (is_array($value)) {
                $sanitized[$safeKey] = $this->sanitizeTaskData($value);
            } elseif (is_int($value) || ctype_digit((string) $value)) {
                $sanitized[$safeKey] = (int) $value;
            } else {
                $sanitized[$safeKey] = sanitize_text_field((string) $value);
            }
        }
        return $sanitized;
    }
}
