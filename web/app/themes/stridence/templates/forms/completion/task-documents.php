<?php
/**
 * Completion task: Document Upload.
 *
 * File upload area for required documents.
 * Parent Alpine component `completionPage` provides `completeTask()`.
 *
 * @var array $args {
 *     @type object  $registration  Registration row
 *     @type array   $task          Task status data
 *     @type WP_Post $post          Edition or trajectory post
 * }
 * @package stridence
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$taskType = $args['task_type'] ?? 'documents';
?>

<div x-data="{
    files: [],
    uploading: false,
    uploadError: '',

    handleFiles(event) {
        this.uploadError = '';
        this.files = [...this.files, ...Array.from(event.target.files)];
    },

    removeFile(index) {
        this.files.splice(index, 1);
    },

    async submitDocuments() {
        if (this.files.length === 0) return;

        this.uploading = true;
        this.uploadError = '';
        const formData = new FormData();
        formData.append('registration_id', $data.registrationId);
        formData.append('task_type', '<?= esc_js($taskType) ?>');

        this.files.forEach(file => {
            formData.append('documents[]', file);
        });

        try {
            const result = await ntdstAPI.upload('stride_upload_completion_documents', formData);
            this.files = [];
            $data.tasks['<?= esc_js($taskType) ?>'] = { status: 'completed', completed_at: new Date().toISOString() };

            if ($data.completedCount === $data.totalCount) {
                window.location.reload();
            }
        } catch (e) {
            this.uploadError = e.message || 'Upload mislukt. Probeer opnieuw.';
        } finally {
            this.uploading = false;
        }
    }
}">
    <!-- Drop zone -->
    <label class="block border-2 border-dashed border-border rounded-lg p-6 text-center cursor-pointer hover:border-primary transition-colors">
        <input type="file" multiple class="sr-only" @change="handleFiles">
        <?= stridence_icon('file-text', 'w-8 h-8 mx-auto text-text-muted mb-2') ?>
        <p class="text-sm text-text-muted">
            <?= esc_html__('Klik om bestanden te selecteren', 'stridence') ?>
        </p>
        <p class="text-xs text-text-muted mt-1">
            <?= esc_html__('PDF, Word, afbeeldingen (max. 10 MB)', 'stridence') ?>
        </p>
    </label>

    <!-- File list -->
    <template x-if="files.length > 0">
        <div class="mt-3 space-y-2">
            <template x-for="(file, index) in files" :key="index">
                <div class="flex items-center gap-2 text-sm p-2 bg-surface-alt rounded-sm">
                    <?= stridence_icon('file-text', 'w-4 h-4 text-text-muted shrink-0') ?>
                    <span class="flex-1 truncate" x-text="file.name"></span>
                    <span class="text-xs text-text-muted" x-text="(file.size / 1024 / 1024).toFixed(1) + ' MB'"></span>
                    <button type="button" @click="removeFile(index)"
                            class="text-error hover:bg-error/10 text-xs">
                        &times;
                    </button>
                </div>
            </template>
        </div>
    </template>

    <!-- Error message -->
    <template x-if="uploadError">
        <div class="mt-3 p-3 bg-status-error-subtle border border-status-error rounded-[12px] text-sm text-status-error" x-text="uploadError"></div>
    </template>

    <!-- Submit -->
    <div class="mt-4 flex items-center gap-3">
        <button type="button"
                @click="submitDocuments()"
                class="btn-primary text-sm"
                :disabled="files.length === 0 || uploading">
            <span x-show="!uploading"><?= esc_html__('Uploaden', 'stridence') ?></span>
            <span x-show="uploading"><?= esc_html__('Uploaden...', 'stridence') ?></span>
        </button>
        <span class="text-xs text-text-muted" x-show="files.length > 0"
              x-text="files.length + ' bestand(en)'"></span>
    </div>
</div>
