<?php
/**
 * Interest Form Template — Anonymous
 *
 * Shows name, email, and any interest-stage field groups for the edition.
 * Does NOT require login.
 *
 * @var int   $edition_id Edition ID
 * @var array $field_groups Interest stage field groups
 */
use Stride\Modules\Questionnaire\QuestionnaireRepository;

$edition_id = $args['edition_id'] ?? 0;
$edition = get_post($edition_id);
$course_id = $edition ? (int) get_post_meta($edition_id, '_ntdst_course_id', true) : 0;
$course_title = $course_id ? get_the_title($course_id) : ($edition ? $edition->post_title : '');

$questionnaireRepo = ntdst_get(QuestionnaireRepository::class);
$field_groups = $questionnaireRepo->getGroupsForStage($edition_id, 'interest');

// Prefill from the logged-in user (form-identity rule 2 — all forms prefill;
// mirrors the waitlist form). The e-mail stays EDITABLE: submitting your own
// address binds the row to your account, editing it submits for someone else
// (rule 3/4 — the e-mail field IS the choice, no toggle). Anonymous visitors
// get an empty form.
$prefill_name = '';
$prefill_email = '';
if (is_user_logged_in()) {
    $current_user = wp_get_current_user();
    $prefill_name = trim(
        get_user_meta($current_user->ID, 'first_name', true) . ' ' . get_user_meta($current_user->ID, 'last_name', true)
    ) ?: $current_user->display_name;
    $prefill_email = $current_user->user_email;
}

$alpine_config = json_encode([
    'editionId' => $edition_id,
    'fieldGroups' => $field_groups,
    'prefillName' => $prefill_name,
    'prefillEmail' => $prefill_email,
]);
?>

<div class="container py-8 lg:py-12" x-data="strideInterestForm(<?= esc_attr($alpine_config) ?>)">
    <div class="bg-surface-card rounded-[16px] shadow-card p-6 lg:p-8 max-w-lg mx-auto">
        <h2 class="text-xl font-bold mb-2"><?= esc_html__('Interesse melden', 'stridence') ?></h2>
        <p class="text-text-muted text-sm mb-6">
            <?= sprintf(esc_html__('Meld je interesse aan voor %s. We nemen contact op zodra er data gepland zijn.', 'stridence'), '<strong>' . esc_html($course_title) . '</strong>') ?>
        </p>

        <!-- Success state -->
        <template x-if="submitted">
            <div class="text-center py-4">
                <p class="text-success font-medium"><?= esc_html__('Bedankt! Je interesse is geregistreerd.', 'stridence') ?></p>
            </div>
        </template>

        <!-- Form -->
        <form x-show="!submitted" @submit.prevent="submit()">
            <div class="grid gap-4">
                <div>
                    <label class="input-label" for="interest_name">
                        <?= esc_html__('Naam', 'stridence') ?> <span class="text-error">*</span>
                    </label>
                    <input type="text" id="interest_name" x-model="form.name" class="input-text" required>
                </div>
                <div>
                    <label class="input-label" for="interest_email">
                        <?= esc_html__('E-mailadres', 'stridence') ?> <span class="text-error">*</span>
                    </label>
                    <input type="email" id="interest_email" x-model="form.email" class="input-text" required>
                </div>

                <?php foreach ($field_groups as $group) : ?>
                    <?php stridence_template_part('forms/fields/field-group', null, ['group' => $group]); ?>
                <?php endforeach; ?>
            </div>

            <p x-show="error" x-text="error" class="text-error text-sm mt-4"></p>

            <button type="submit" class="btn-primary w-full mt-6" :disabled="loading">
                <span x-show="!loading"><?= esc_html__('Interesse melden', 'stridence') ?></span>
                <span x-show="loading"><?= esc_html__('Bezig...', 'stridence') ?></span>
            </button>
        </form>
    </div>
</div>

<script>
function strideInterestForm(config) {
    return {
        form: { name: '', email: '', extra_fields: {} },
        loading: false,
        submitted: false,
        error: '',
        init() {
            this.form.name = config.prefillName || '';
            this.form.email = config.prefillEmail || '';
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
                await ntdstAPI.call('stride_submit_interest', {
                    edition_id: config.editionId,
                    name: this.form.name,
                    email: this.form.email,
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
