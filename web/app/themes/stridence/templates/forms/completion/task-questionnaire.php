<?php
/**
 * Completion task: Questionnaire.
 *
 * Renders field groups assigned to this edition/trajectory's course.
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

use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Modules\Edition\EditionService;

$registration = $args['registration'] ?? null;
$post         = $args['post'] ?? null;
$taskType     = $args['task_type'] ?? 'questionnaire';

if (!$registration || !$post) {
    return;
}

// Map task type to questionnaire stage:
// - post_evaluation → 'evaluation' (post-course evaluation form)
// - questionnaire   → 'intake' (pre-course intake questionnaire)
$stage = ($taskType === 'post_evaluation') ? 'evaluation' : 'intake';

$repo = ntdst_get(QuestionnaireRepository::class);

if ($post->post_type === 'vad_edition') {
    $editionService = ntdst_get(EditionService::class);
    $courseId = $editionService->getCourseId($post->ID);

    // Check both edition and linked course for field group assignments
    $fieldGroups = $repo->getGroupsForStage($post->ID, $stage, 'vad_edition');
    if (empty($fieldGroups) && $courseId) {
        $fieldGroups = $repo->getGroupsForStage($courseId, $stage);
    }
} else {
    $fieldGroups = $repo->getGroupsForStage($post->ID, $stage, $post->post_type);
}

if (empty($fieldGroups)) {
    ?>
    <p class="text-sm text-text-muted italic">
        <?= esc_html__('Geen vragenlijst geconfigureerd.', 'stridence') ?>
    </p>
    <?php
    return;
}
?>

<form x-data="{ form: { extra_fields: {} } }"
      @submit.prevent="completeTask('<?= esc_js($taskType) ?>', { answers: form.extra_fields })"
      class="space-y-6">
    <?php foreach ($fieldGroups as $group): ?>
        <fieldset class="space-y-4">
            <?php if (!empty($group['label'])): ?>
                <legend class="text-sm font-medium text-text uppercase tracking-wider">
                    <?= esc_html($group['label']) ?>
                </legend>
            <?php endif; ?>

            <?php foreach ($group['fields'] ?? [] as $field): ?>
                <?php
                stridence_template_part('templates/forms/fields/dynamic-field', null, [
                    'field'  => $field,
                    'prefix' => 'questionnaire',
                    'value'  => '',
                ]);
                ?>
            <?php endforeach; ?>
        </fieldset>
    <?php endforeach; ?>

    <div class="flex items-center gap-3">
        <button type="submit"
                class="btn-primary text-sm"
                :disabled="loading">
            <span x-show="!loading"><?= esc_html__('Opslaan', 'stridence') ?></span>
            <span x-show="loading"><?= esc_html__('Opslaan...', 'stridence') ?></span>
        </button>
        <span x-show="error" class="text-sm text-status-error" x-text="error"></span>
    </div>
</form>
