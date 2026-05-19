<?php
/**
 * Stage Form Template — Intake / Evaluation
 *
 * @var int    $edition_id Edition ID
 * @var string $stage      Stage name (intake / evaluation)
 * @var string $title      Form title
 * @var string $description Form description
 */
use Stride\Modules\Questionnaire\QuestionnaireRepository;
use Stride\Modules\Enrollment\RegistrationRepository;
use Stride\Domain\RegistrationStatus;

$edition_id = $args['edition_id'] ?? 0;
$stage = $args['stage'] ?? '';
$title = $args['title'] ?? '';
$description = $args['description'] ?? '';

if (!is_user_logged_in() || !$edition_id || !$stage) return;

$userId = get_current_user_id();
$registrations = ntdst_get(RegistrationRepository::class);
$registration = $registrations->findByUserAndEdition($userId, $edition_id);

if (!$registration) return;

// Check registration status matches expected state
$expectedStatus = match ($stage) {
    'intake' => RegistrationStatus::Confirmed->value,
    'evaluation' => RegistrationStatus::Completed->value,
    default => null,
};
if ($expectedStatus && $registration->status !== $expectedStatus) return;

// Check if already completed
$enrollmentData = json_decode($registration->enrollment_data ?? '{}', true) ?: [];
if (isset($enrollmentData[$stage])) {
    ?>
    <div class="card p-6 text-center">
        <p class="text-success font-medium"><?= esc_html__('Je hebt dit formulier al ingevuld. Bedankt!', 'stridence') ?></p>
    </div>
    <?php
    return;
}

$questionnaireRepo = ntdst_get(QuestionnaireRepository::class);
$field_groups = $questionnaireRepo->getGroupsForStage($edition_id, $stage);

if (empty($field_groups)) return;

$alpine_config = json_encode([
    'editionId' => $edition_id,
    'stage' => $stage,
    'fieldGroups' => $field_groups,
    'action' => 'stride_submit_' . $stage,
]);
?>

<div class="card p-6 lg:p-8" x-data="strideStageForm(<?= esc_attr($alpine_config) ?>)">
    <h3 class="text-lg font-bold mb-2"><?= esc_html($title) ?></h3>
    <?php if ($description) : ?>
        <p class="text-text-muted text-sm mb-6"><?= esc_html($description) ?></p>
    <?php endif; ?>

    <template x-if="submitted">
        <div class="text-center py-4">
            <p class="text-success font-medium"><?= esc_html__('Bedankt voor het invullen!', 'stridence') ?></p>
        </div>
    </template>

    <form x-show="!submitted" @submit.prevent="submit()">
        <div class="grid gap-4">
            <?php foreach ($field_groups as $group) : ?>
                <?php stridence_template_part('forms/fields/field-group', null, ['group' => $group]); ?>
            <?php endforeach; ?>
        </div>

        <p x-show="error" x-text="error" class="text-error text-sm mt-4"></p>

        <button type="submit" class="btn btn-primary w-full mt-6" :disabled="loading">
            <span x-show="!loading"><?= esc_html__('Versturen', 'stridence') ?></span>
            <span x-show="loading"><?= esc_html__('Bezig...', 'stridence') ?></span>
        </button>
    </form>
</div>

<script>
function strideStageForm(config) {
    return {
        form: { extra_fields: {} },
        loading: false,
        submitted: false,
        error: '',
        init() {
            (config.fieldGroups || []).forEach(group => {
                (group.fields || []).forEach(field => {
                    if (field.name) {
                        this.form.extra_fields[field.name] = field.type === 'checkbox' ? false : (field.type === 'scale' ? null : '');
                    }
                });
            });
        },
        async submit() {
            this.loading = true;
            this.error = '';
            try {
                await ntdstAPI.call(config.action, {
                    edition_id: config.editionId,
                    extra_fields: this.form.extra_fields,
                });
                this.submitted = true;
            } catch (e) {
                this.error = e.message || 'Er is een fout opgetreden.';
            } finally {
                this.loading = false;
            }
        }
    };
}
</script>
